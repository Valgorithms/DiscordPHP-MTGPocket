<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-MTGPocket project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace MTGPocket\Matches;

use MTGPocket\Cards\BasicLands;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Game\Game;
use MTGPocket\Models\Deck;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\MatchRepository;

/**
 * Direct challenges and the games they start. Each player is in at most
 * one live match (a challenge or a game) at a time.
 *
 * A match is played with the main deck of each player's active deck (or
 * the one they name), which must hold at least {@see MIN_DECK} cards they
 * still own. Format rules (Standard, Commander, Limited) and matchmaking
 * come with the game modes.
 *
 * @since 0.3.0
 */
final class MatchService
{
    public const int MIN_DECK = 40;

    /** @var \Closure(): string */
    private \Closure $random;

    /**
     * @param MatchRepository           $matches
     * @param DeckBuilder               $decks
     * @param InventoryRepository       $inventories
     * @param (\Closure(): string)|null $random Random hex for match ids and game seeds; defaults to {@see random_bytes()}.
     */
    public function __construct(
        private readonly MatchRepository $matches,
        private readonly DeckBuilder $decks,
        private readonly InventoryRepository $inventories,
        ?\Closure $random = null,
    ) {
        $this->random = $random ?? fn () => bin2hex(random_bytes(16));
    }

    /**
     * The live match a player is in.
     *
     * @param string $playerId
     *
     * @return MatchRecord|null
     */
    public function current(string $playerId): ?MatchRecord
    {
        $id = $this->matches->liveMatchId($playerId);
        $match = $id === null ? null : $this->matches->find($id);
        if ($id !== null && ($match === null || ! $match->isLive() || ! $match->has($playerId))) {
            $this->matches->setLive($playerId, null);

            return null;
        }

        return $match;
    }

    public function find(string $matchId): ?MatchRecord
    {
        return $this->matches->find($matchId);
    }

    /**
     * Challenges another player with a deck.
     *
     * @param string      $challengerId
     * @param string      $challengerName
     * @param string      $opponentId
     * @param string      $opponentName
     * @param string|null $deck           Id or name; defaults to the active deck.
     *
     * @return MatchRecord
     */
    public function challenge(string $challengerId, string $challengerName, string $opponentId, string $opponentName, ?string $deck = null): MatchRecord
    {
        if ($challengerId === $opponentId) {
            throw new \InvalidArgumentException('You cannot challenge yourself.');
        }
        if ($this->current($challengerId) !== null) {
            throw new \InvalidArgumentException('You are already in a match. Finish it, or leave it with `/match leave`.');
        }
        if ($this->current($opponentId) !== null) {
            throw new \InvalidArgumentException("**{$opponentName}** is already in a match.");
        }
        [$chosen] = $this->deckCards($challengerId, $deck);

        $match = new MatchRecord(
            substr(($this->random)(), 0, 12),
            MatchRecord::PENDING,
            [
                ['id' => $challengerId, 'name' => $challengerName, 'deckId' => $chosen->id, 'deckName' => $chosen->name],
                ['id' => $opponentId, 'name' => $opponentName, 'deckId' => null, 'deckName' => null],
            ],
            createdAt: time(),
            updatedAt: time(),
        );
        $this->matches->save($match);
        $this->matches->setLive($challengerId, $match->id);

        return $match;
    }

    /**
     * Accepts a challenge and starts the game.
     *
     * @param string      $matchId
     * @param string      $playerId
     * @param string      $playerName
     * @param string|null $deck       Id or name; defaults to the active deck.
     *
     * @return MatchRecord
     */
    public function accept(string $matchId, string $playerId, string $playerName, ?string $deck = null): MatchRecord
    {
        $current = $this->current($playerId);
        if ($current !== null && $current->id !== $matchId) {
            throw new \InvalidArgumentException('You are already in another match. Leave it first with `/match leave`.');
        }
        [$chosen, $cards] = $this->deckCards($playerId, $deck);

        $match = $this->matches->modify($matchId, function (MatchRecord $match) use ($playerId, $playerName, $chosen, $cards): void {
            if ($match->status !== MatchRecord::PENDING) {
                throw new \InvalidArgumentException('This challenge is no longer open.');
            }
            if ($match->opponent()['id'] !== $playerId) {
                throw new \InvalidArgumentException('Only **'.$match->opponent()['name'].'** can accept this challenge.');
            }
            [, $challengerCards] = $this->deckCards($match->challenger()['id'], (string) $match->challenger()['deckId']);

            $match->players[1] = ['id' => $playerId, 'name' => $playerName, 'deckId' => $chosen->id, 'deckName' => $chosen->name];
            $match->status = MatchRecord::PLAYING;
            $match->game = Game::start($match->id, [
                ['id' => $match->players[0]['id'], 'name' => $match->players[0]['name'], 'cards' => $challengerCards],
                ['id' => $playerId, 'name' => $playerName, 'cards' => $cards],
            ], ($this->random)());
        });
        $this->matches->setLive($playerId, $match->id);

        return $match;
    }

    /**
     * Declines a challenge (the opponent) or calls it off (the challenger).
     *
     * @param string $matchId
     * @param string $playerId
     *
     * @return MatchRecord
     */
    public function decline(string $matchId, string $playerId): MatchRecord
    {
        $match = $this->matches->modify($matchId, function (MatchRecord $match) use ($playerId): void {
            if ($match->status !== MatchRecord::PENDING) {
                throw new \InvalidArgumentException('This challenge is no longer open.');
            }
            if (! $match->has($playerId)) {
                throw new \InvalidArgumentException('This challenge is not yours.');
            }
            $match->status = $match->challenger()['id'] === $playerId ? MatchRecord::CANCELLED : MatchRecord::DECLINED;
        });
        $this->release($match);

        return $match;
    }

    /**
     * Leaves the player's live match: calls off a challenge, or concedes a game.
     *
     * @param string $playerId
     *
     * @return MatchRecord
     */
    public function leave(string $playerId): MatchRecord
    {
        $match = $this->current($playerId) ?? throw new \InvalidArgumentException('You are not in a match.');
        if ($match->status === MatchRecord::PENDING) {
            return $this->decline($match->id, $playerId);
        }

        return $this->act($match->id, $playerId, fn (Game $game, int $seat) => $game->concede($seat));
    }

    /**
     * Does something in a game as one of its players, under the match's lock.
     * When the action throws (a {@see \MTGPocket\Game\GameException} for a
     * move the rules do not allow), nothing is saved.
     *
     * @param string                                      $matchId
     * @param string                                      $playerId
     * @param callable(Game, int, MatchRecord): void       $action   Gets the game, the player's seat and the match.
     *
     * @return MatchRecord As saved.
     */
    public function act(string $matchId, string $playerId, callable $action): MatchRecord
    {
        $match = $this->matches->modify($matchId, function (MatchRecord $match) use ($playerId, $action): void {
            if ($match->status !== MatchRecord::PLAYING || $match->game === null) {
                throw new \InvalidArgumentException($match->status === MatchRecord::OVER ? 'This game is over.' : 'This game has not started.');
            }
            $seat = $match->game->seatOf($playerId);
            if ($seat === null) {
                throw new \InvalidArgumentException('You are not playing in this game.');
            }
            $action($match->game, $seat, $match);
            if ($match->game->stage === Game::OVER) {
                $match->status = MatchRecord::OVER;
                $match->choices = [];
            }
        });
        if (! $match->isLive()) {
            $this->release($match);
        }

        return $match;
    }

    /**
     * Saves what a player has picked in their action panel, without
     * changing the game.
     *
     * @param string                $matchId
     * @param string                $playerId
     * @param callable(array): array $change Gets their picks and returns the new ones.
     *
     * @return MatchRecord
     */
    public function choose(string $matchId, string $playerId, callable $change): MatchRecord
    {
        return $this->matches->modify($matchId, function (MatchRecord $match) use ($playerId, $change): void {
            if (! $match->has($playerId)) {
                throw new \InvalidArgumentException('You are not playing in this game.');
            }
            $match->choices[$playerId] = $change($match->choice($playerId));
            if ($match->choices[$playerId] === []) {
                unset($match->choices[$playerId]);
            }
        });
    }

    /**
     * A player's deck for a match and its main deck as card data, one
     * entry per copy.
     *
     * @param string      $playerId
     * @param string|null $deck     Id or name; defaults to the active deck.
     *
     * @throws \InvalidArgumentException When the deck cannot be played.
     *
     * @return array{0: Deck, 1: array[]}
     */
    public function deckCards(string $playerId, ?string $deck = null): array
    {
        $deck = $deck === null || $deck === '' ? $this->decks->activeDeckId($playerId) : $deck;
        if ($deck === null) {
            throw new \InvalidArgumentException('You have no deck to play with. Build one with `/decks create`.');
        }
        $chosen = $this->decks->find($playerId, $deck);
        if ($chosen->main->total() < self::MIN_DECK) {
            throw new \InvalidArgumentException("**{$chosen->name}** has {$chosen->main->total()} cards; a match needs at least ".self::MIN_DECK.' in the main deck.');
        }

        $owned = $this->inventories->get($playerId)->cards;
        $cards = [];
        foreach ($chosen->main as $key => $count) {
            $key = (string) $key;
            $card = $this->decks->cardData($key);
            if (! BasicLands::isBasic($key)) {
                if ($owned->get($key) < $count) {
                    throw new \InvalidArgumentException("You no longer own {$count} **{$card['name']}** for **{$chosen->name}**.");
                }
                if (! array_key_exists('manaCost', $card)) {
                    throw new \InvalidArgumentException("The rules data for **{$card['name']}** has not been imported yet. Ask the bot's host to run `composer import-cards` again.");
                }
            }
            array_push($cards, ...array_fill(0, $count, $card));
        }

        return [$chosen, $cards];
    }

    /**
     * Frees the players of a match that is no longer live.
     *
     * @param MatchRecord $match
     *
     * @return void
     */
    private function release(MatchRecord $match): void
    {
        foreach ($match->players as $player) {
            if ($this->matches->liveMatchId($player['id']) === $match->id) {
                $this->matches->setLive($player['id'], null);
            }
        }
    }
}
