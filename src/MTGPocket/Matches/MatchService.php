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
use MTGPocket\Modes\GameMode;
use MTGPocket\Modes\GameModes;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Models\Player;
use MTGPocket\Rentals\RentalDeck;
use MTGPocket\Quests\Quests;
use MTGPocket\Rentals\Rentals;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\MatchRepository;
use MTGPocket\Repository\PlayerRepository;

/**
 * Matches: direct challenges, the matchmaking queue and the games they
 * start. Each player is in at most one live match (a challenge or a game)
 * and at most one queue at a time.
 *
 * Every match is played in a game mode, with the main deck of each
 * player's active deck, rental deck, or the one they name: the deck has
 * to follow the mode's rules and library, and hold only cards the player
 * still owns (a rental needs a rental game left for the day instead).
 * Matchmaking pairs players waiting in the same mode whose ratings are
 * close (a range that widens the longer someone waits); those games are
 * ranked, move both players on the mode's {@see Ladder} and pay points
 * (see {@see \MTGPocket\Economy\MatchRewards}).
 *
 * @since 0.3.0
 */
final class MatchService
{
    /**
     * How long a player waits in a queue before their spot lapses, in seconds.
     */
    public const int QUEUE_WAIT = 1800;

    /**
     * How far apart two ratings may be for a pairing, and how much further
     * for each minute the waiting player has waited.
     */
    public const int RATING_RANGE = 200;
    public const int RANGE_PER_MINUTE = 50;

    /** @var \Closure(): string */
    private \Closure $random;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param MatchRepository           $matches
     * @param DeckBuilder               $decks
     * @param InventoryRepository       $inventories
     * @param GameModes                 $modes
     * @param CardPoolRepository        $pools       For the release dates of sets.
     * @param Ladder                    $ladder
     * @param Rentals                   $rentals
     * @param PlayerRepository          $players     For points and daily counts.
     * @param Quests                    $quests      Which ranked games count towards.
     * @param (\Closure(): string)|null $random      Random hex for match ids and game seeds; defaults to {@see random_bytes()}.
     * @param (\Closure(): int)|null    $clock       The current Unix time; defaults to {@see time()}.
     */
    public function __construct(
        private readonly MatchRepository $matches,
        private readonly DeckBuilder $decks,
        private readonly InventoryRepository $inventories,
        public readonly GameModes $modes,
        private readonly CardPoolRepository $pools,
        public readonly Ladder $ladder,
        public readonly Rentals $rentals,
        private readonly PlayerRepository $players,
        public readonly Quests $quests,
        ?\Closure $random = null,
        ?\Closure $clock = null,
    ) {
        $this->random = $random ?? fn () => bin2hex(random_bytes(16));
        $this->clock = $clock ?? fn () => time();
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
     * @param string|null $mode           Defaults to the deck's format.
     *
     * @return MatchRecord
     */
    public function challenge(string $challengerId, string $challengerName, string $opponentId, string $opponentName, ?string $deck = null, ?string $mode = null): MatchRecord
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
        $chosen = $this->chooseDeck($challengerId, $deck);
        $gameMode = $this->playableMode($mode ?? $chosen->format);
        $this->deckCards($challengerId, $chosen->id, $gameMode);

        $match = new MatchRecord(
            substr(($this->random)(), 0, 12),
            MatchRecord::PENDING,
            [
                ['id' => $challengerId, 'name' => $challengerName, 'deckId' => $chosen->id, 'deckName' => $chosen->name],
                ['id' => $opponentId, 'name' => $opponentName, 'deckId' => null, 'deckName' => null],
            ],
            createdAt: ($this->clock)(),
            updatedAt: ($this->clock)(),
            mode: $gameMode->id,
        );
        $this->matches->save($match);
        $this->matches->setLive($challengerId, $match->id);
        $this->unqueue($challengerId);

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
        $pending = $this->matches->find($matchId) ?? throw new \OutOfBoundsException('That match no longer exists.');
        $gameMode = $this->modes->get($pending->mode);
        [$chosen, $cards] = $this->deckCards($playerId, $deck, $gameMode);

        $match = $this->matches->modify($matchId, function (MatchRecord $match) use ($playerId, $playerName, $chosen, $cards, $gameMode): void {
            if ($match->status !== MatchRecord::PENDING) {
                throw new \InvalidArgumentException('This challenge is no longer open.');
            }
            if ($match->opponent()['id'] !== $playerId) {
                throw new \InvalidArgumentException('Only **'.$match->opponent()['name'].'** can accept this challenge.');
            }
            [, $challengerCards] = $this->deckCards($match->challenger()['id'], (string) $match->challenger()['deckId'], $gameMode);

            $match->players[1] = ['id' => $playerId, 'name' => $playerName, 'deckId' => $chosen->id, 'deckName' => $chosen->name];
            $this->begin($match, [$challengerCards, $cards], $gameMode);
        });
        $this->matches->setLive($playerId, $match->id);
        $this->unqueue($playerId);

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

    // ----------------------------------------------------------------------
    // Matchmaking
    // ----------------------------------------------------------------------

    /**
     * Joins a mode's queue with a deck. When someone waiting there has a
     * rating in range, the two are paired at once (the closest rating
     * first, then whoever has waited longest) and a ranked game starts.
     *
     * @param string      $playerId
     * @param string      $playerName
     * @param string|null $mode       Defaults to the deck's format.
     * @param string|null $deck       Id or name; defaults to the active deck.
     *
     * @return array{match: MatchRecord|null, mode: GameMode, deck: Deck} The game, when one started.
     */
    public function queue(string $playerId, string $playerName, ?string $mode = null, ?string $deck = null): array
    {
        if ($this->current($playerId) !== null) {
            throw new \InvalidArgumentException('You are already in a match. Finish it, or leave it with `/match leave`.');
        }
        $chosen = $this->chooseDeck($playerId, $deck);
        $gameMode = $this->playableMode($mode ?? $chosen->format);
        [, $cards] = $this->deckCards($playerId, $chosen->id, $gameMode);
        $this->unqueue($playerId);

        $me = ['id' => $playerId, 'name' => $playerName, 'deckId' => $chosen->id, 'deckName' => $chosen->name, 'rating' => $this->ladder->entry($gameMode->id, $playerId)['rating'], 'since' => ($this->clock)()];
        // An opponent whose deck stopped being playable, or who got into
        // another match, loses their spot; try the next one.
        for ($tries = 0; $tries < 10; $tries++) {
            $opponent = null;
            $this->matches->updateQueue($gameMode->id, function (array $entries) use ($me, &$opponent): array {
                $entries = $this->waiting($entries, $me['id']);
                $inRange = array_filter($entries, fn (array $entry) => abs($entry['rating'] - $me['rating']) <= self::RATING_RANGE + self::RANGE_PER_MINUTE * intdiv($me['since'] - $entry['since'], 60));
                usort($inRange, fn (array $a, array $b) => [abs($a['rating'] - $me['rating']), $a['since']] <=> [abs($b['rating'] - $me['rating']), $b['since']]);
                $opponent = $inRange[0] ?? null;
                if ($opponent === null) {
                    return [...$entries, $me];
                }

                return array_values(array_filter($entries, fn (array $entry) => $entry['id'] !== $opponent['id']));
            });
            if ($opponent === null) {
                return ['match' => null, 'mode' => $gameMode, 'deck' => $chosen];
            }

            try {
                if ($this->current($opponent['id']) !== null) {
                    continue;
                }
                [, $opponentCards] = $this->deckCards($opponent['id'], (string) $opponent['deckId'], $gameMode);
            } catch (\InvalidArgumentException|\OutOfBoundsException) {
                continue;
            }

            $now = ($this->clock)();
            $match = new MatchRecord(
                substr(($this->random)(), 0, 12),
                MatchRecord::PENDING,
                [
                    ['id' => $opponent['id'], 'name' => $opponent['name'], 'deckId' => $opponent['deckId'], 'deckName' => $opponent['deckName']],
                    ['id' => $playerId, 'name' => $playerName, 'deckId' => $chosen->id, 'deckName' => $chosen->name],
                ],
                createdAt: $now,
                updatedAt: $now,
                mode: $gameMode->id,
                ranked: true,
            );
            $this->begin($match, [$opponentCards, $cards], $gameMode);
            $this->matches->save($match);
            foreach ($match->players as $player) {
                $this->matches->setLive($player['id'], $match->id);
            }

            return ['match' => $match, 'mode' => $gameMode, 'deck' => $chosen];
        }

        throw new \InvalidArgumentException('Matchmaking is busy; try again.');
    }

    /**
     * The queue a player is waiting in.
     *
     * @param string $playerId
     *
     * @return array{mode: GameMode, deckName: string, since: int, rating: int}|null
     */
    public function queued(string $playerId): ?array
    {
        foreach ($this->modes->all() as $mode) {
            foreach ($this->waiting($this->matches->queue($mode->id)) as $entry) {
                if ($entry['id'] === $playerId) {
                    return ['mode' => $mode, 'deckName' => $entry['deckName'], 'since' => $entry['since'], 'rating' => $entry['rating']];
                }
            }
        }

        return null;
    }

    /**
     * How many players are waiting in each mode's queue.
     *
     * @return array<string, int>
     */
    public function queueSizes(): array
    {
        $sizes = [];
        foreach ($this->modes->all() as $mode) {
            $sizes[$mode->id] = count($this->waiting($this->matches->queue($mode->id)));
        }

        return $sizes;
    }

    /**
     * Takes a player out of every queue.
     *
     * @param string $playerId
     *
     * @return GameMode|null The mode they were waiting for.
     */
    public function unqueue(string $playerId): ?GameMode
    {
        $left = null;
        foreach ($this->modes->all() as $mode) {
            if (! in_array($playerId, array_column($this->matches->queue($mode->id), 'id'), true)) {
                continue;
            }
            $this->matches->updateQueue($mode->id, function (array $entries) use ($playerId, $mode, &$left): array {
                $kept = array_values(array_filter($entries, fn (array $entry) => $entry['id'] !== $playerId));
                if (count($kept) !== count($entries)) {
                    $left = $mode;
                }

                return $this->waiting($kept);
            });
        }

        return $left;
    }

    /**
     * Queue entries whose spot has not lapsed, without one player's.
     *
     * @param array[]     $entries
     * @param string|null $except
     *
     * @return array[]
     */
    private function waiting(array $entries, ?string $except = null): array
    {
        $since = ($this->clock)() - self::QUEUE_WAIT;

        return array_values(array_filter($entries, fn (array $entry) => $entry['since'] >= $since && $entry['id'] !== $except));
    }

    // ----------------------------------------------------------------------
    // Playing
    // ----------------------------------------------------------------------

    /**
     * Does something in a game as one of its players, under the match's lock.
     * When the action throws (a {@see \MTGPocket\Game\GameException} for a
     * move the rules do not allow), nothing is saved. A ranked game that
     * ends is recorded on the ladder.
     *
     * @param string                                      $matchId
     * @param string                                      $playerId
     * @param callable(Game, int, MatchRecord): void       $action   Gets the game, the player's seat and the match.
     *
     * @return MatchRecord As saved.
     */
    public function act(string $matchId, string $playerId, callable $action): MatchRecord
    {
        $ended = false;
        $match = $this->matches->modify($matchId, function (MatchRecord $match) use ($playerId, $action, &$ended): void {
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
                $ended = true;
            }
        });
        if ($ended && $match->ranked) {
            $this->ladder->record($match->mode, $match->players, $match->game->winner);
            $rewards = $this->reward($match);
            if ($rewards !== []) {
                $match = $this->matches->modify($match->id, fn (MatchRecord $saved) => $saved->rewards = $rewards);
            }
        }
        if (! $match->isLive()) {
            $this->release($match);
        }

        return $match;
    }

    /**
     * Pays the players of a finished ranked game: points for winning, fewer
     * for playing it out, if it lasted long enough and they have not hit
     * the day's limit; and counts it towards their quests.
     *
     * @param MatchRecord $match
     *
     * @return list<array{id: string, points: int, quests: list<array{label: string, points: int}>}>
     */
    private function reward(MatchRecord $match): array
    {
        $rules = $this->rentals->rules;
        if ($match->game->turn < $rules->minTurns) {
            return [];
        }
        $rewards = [];
        foreach ($match->players as $seat => $entry) {
            $won = $match->game->winner === $seat;
            $points = $won ? $rules->winPoints : $rules->playPoints;
            $paid = 0;
            $this->players->findOrCreate($entry['id'], $entry['name']);
            if ($points > 0) {
                $this->players->modify($entry['id'], function (Player $player) use ($points, $rules, &$paid): void {
                    $player->onDay($this->rentals->today());
                    if ($player->rewardedGames < $rules->rewardedPerDay) {
                        $player->rewardedGames++;
                        $player->points += $points;
                        $paid = $points;
                    }
                });
            }
            $quests = $this->quests->record($entry['id'], 'ranked');
            if ($won) {
                array_push($quests, ...$this->quests->record($entry['id'], 'win'));
            }
            if (RentalDeck::isRental((string) ($entry['deckId'] ?? ''))) {
                array_push($quests, ...$this->quests->record($entry['id'], 'rental'));
            }
            if ($paid > 0 || $quests !== []) {
                $rewards[] = ['id' => $entry['id'], 'points' => $paid, 'quests' => $quests];
            }
        }

        return $rewards;
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
     * What keeps a deck from being played in a mode: the mode's rules and
     * library, cards the player no longer owns, and cards whose rules data
     * is missing.
     *
     * @param Deck          $deck
     * @param GameMode|null $mode Defaults to the deck's format.
     *
     * @return string[] Empty when it can be played.
     */
    public function problems(Deck $deck, ?GameMode $mode = null): array
    {
        $mode ??= $this->modes->get($deck->format);
        if (RentalDeck::isRental($deck->id)) {
            $rental = $this->rentals->find($deck->id);

            return $mode->problems($deck, $rental->card(...), fn () => $rental->releaseDate, ($this->clock)());
        }
        $dates = [];
        $problems = $mode->problems(
            $deck,
            $this->decks->cardData(...),
            function (string $setCode) use (&$dates): ?string {
                return array_key_exists($setCode, $dates) ? $dates[$setCode] : ($dates[$setCode] = $this->pools->find($setCode)?->releaseDate);
            },
            ($this->clock)(),
        );

        $owned = $this->inventories->get($deck->playerId)->cards;
        $played = $deck->main->toArray();
        if ($deck->commander !== null) {
            $played[$deck->commander] = ($played[$deck->commander] ?? 0) + 1;
        }
        foreach ($played as $key => $count) {
            $key = (string) $key;
            if (BasicLands::isBasic($key)) {
                continue;
            }
            $card = $this->decks->cardData($key);
            if ($owned->get($key) < $count) {
                $problems[] = "You no longer own {$count} **{$card['name']}**.";
            } elseif (! array_key_exists('manaCost', $card)) {
                $problems[] = "The rules data for **{$card['name']}** has not been imported yet. Ask the bot's host to run `composer import-cards` again.";
            }
        }

        return array_slice($problems, 0, GameMode::MAX_PROBLEMS);
    }

    /**
     * A player's deck for a match in a mode and its main deck as card
     * data, one entry per copy.
     *
     * @param string      $playerId
     * @param string|null $deck     Id or name; defaults to the active deck.
     * @param GameMode    $mode
     *
     * @throws \InvalidArgumentException When the deck cannot be played in the mode.
     *
     * @return array{0: Deck, 1: array[]}
     */
    public function deckCards(string $playerId, ?string $deck, GameMode $mode): array
    {
        $chosen = $this->chooseDeck($playerId, $deck);
        $problems = $this->problems($chosen, $mode);
        if ($problems !== []) {
            throw new \InvalidArgumentException("**{$chosen->name}** cannot be played in {$mode->label}:\n- ".implode("\n- ", $problems));
        }
        if (RentalDeck::isRental($chosen->id)) {
            if ($this->rentals->gamesLeft($playerId) <= 0) {
                throw new \InvalidArgumentException("You have played your {$this->rentals->rules->rentalGamesPerDay} rental games for today; more at midnight UTC. Your own decks can still play (`/decks use`).");
            }

            return [$chosen, $this->rentals->find($chosen->id)->mainCards()];
        }

        $cards = [];
        foreach ($chosen->main as $key => $count) {
            array_push($cards, ...array_fill(0, $count, $this->decks->cardData((string) $key)));
        }

        return [$chosen, $cards];
    }

    /**
     * @param string      $playerId
     * @param string|null $deck     Id or name; defaults to the active deck.
     *
     * @return Deck
     */
    private function chooseDeck(string $playerId, ?string $deck): Deck
    {
        $deck = $deck === null || $deck === '' ? ($this->rentals->activeRental($playerId) ?? $this->decks->activeDeckId($playerId)) : $deck;
        if ($deck === null) {
            throw new \InvalidArgumentException('You have no deck to play with. Build one with `/decks create`, or borrow one with `/decks rent`.');
        }
        $rentalFormat = $this->rentals->mode()->id;
        if (RentalDeck::isRental($deck)) {
            return $this->rentals->find($deck)->deck($playerId, $rentalFormat);
        }

        try {
            return $this->decks->find($playerId, $deck);
        } catch (\OutOfBoundsException $e) {
            try {
                return $this->rentals->find($deck)->deck($playerId, $rentalFormat);
            } catch (\OutOfBoundsException) {
                throw $e;
            }
        }
    }

    private function playableMode(string $mode): GameMode
    {
        $gameMode = $this->modes->get($mode);
        if (! $gameMode->playable) {
            throw new \InvalidArgumentException("{$gameMode->label} games cannot be played yet; its decks can already be built and checked with `/decks show`.");
        }

        return $gameMode;
    }

    /**
     * Starts a match's game in its mode.
     *
     * @param MatchRecord          $match
     * @param array{0: array[], 1: array[]} $cards Each player's main deck as card data.
     * @param GameMode             $mode
     *
     * @return void
     */
    private function begin(MatchRecord $match, array $cards, GameMode $mode): void
    {
        $match->status = MatchRecord::PLAYING;
        $match->game = Game::start($match->id, [
            ['id' => $match->players[0]['id'], 'name' => $match->players[0]['name'], 'cards' => $cards[0], 'commander' => $this->commanderCard($match->players[0], $mode)],
            ['id' => $match->players[1]['id'], 'name' => $match->players[1]['name'], 'cards' => $cards[1], 'commander' => $this->commanderCard($match->players[1], $mode)],
        ], ($this->random)(), $mode->life);
        foreach ($match->players as $player) {
            if (RentalDeck::isRental((string) $player['deckId'])) {
                $this->rentals->countGame($player['id']);
            }
        }
    }

    /**
     * The card data of a player's commander, in a Commander game.
     *
     * @param array{id: string, deckId?: string|null} $player
     * @param GameMode                                $mode
     *
     * @return array|null
     */
    private function commanderCard(array $player, GameMode $mode): ?array
    {
        $deckId = (string) ($player['deckId'] ?? '');
        if (! $mode->commander || $deckId === '' || RentalDeck::isRental($deckId)) {
            return null;
        }
        $commander = $this->decks->find($player['id'], $deckId)->commander;

        return $commander === null ? null : $this->decks->cardData($commander);
    }

    /**
     * Frees the players of a match that is no longer live, and adds a game
     * that was played to their histories.
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
            if ($match->game !== null) {
                $this->matches->addToHistory($player['id'], $match->id);
            }
        }
    }

    /**
     * The games a player has finished, newest first.
     *
     * @param string $playerId
     *
     * @return MatchRecord[]
     */
    public function history(string $playerId): array
    {
        return array_values(array_filter(array_map(fn (string $id) => $this->matches->find($id), $this->matches->history($playerId))));
    }

    /**
     * The match whose record a player wants to review: one by id, or else
     * their live game, or else the last game they finished.
     *
     * @param string      $playerId
     * @param string|null $matchId
     *
     * @throws \InvalidArgumentException When there is no such game.
     *
     * @return MatchRecord
     */
    public function forReview(string $playerId, ?string $matchId = null): MatchRecord
    {
        $match = $matchId !== null && $matchId !== ''
            ? $this->matches->find(strtolower(trim($matchId)))
            : (($live = $this->current($playerId)) !== null && $live->game !== null ? $live : ($this->history($playerId)[0] ?? null));
        if ($match === null || $match->game === null) {
            throw new \InvalidArgumentException($matchId ? 'There is no game with that id.' : 'You have not played a game yet.');
        }

        return $match;
    }
}
