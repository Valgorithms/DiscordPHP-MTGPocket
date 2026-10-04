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

use MTGPocket\Game\Game;
use MTGPocket\Game\GameRecord;

/**
 * A match between two players in one game mode: a challenge until the
 * opponent accepts (or a ranked game matchmaking paired), then the game
 * itself, and what each player has picked so far in their action panel
 * (the spell they are casting and its targets, their blocks).
 *
 * @since 0.3.0
 */
final class MatchRecord
{
    public const string PENDING = 'pending';
    public const string PLAYING = 'playing';
    public const string OVER = 'over';
    public const string DECLINED = 'declined';
    public const string CANCELLED = 'cancelled';

    /**
     * @param string                $id
     * @param string                $status
     * @param array<int, array{id: string, name: string, deckId: ?string, deckName: ?string}> $players The challenger first.
     * @param Game|null             $game
     * @param array<string, array>  $choices Player id => what they have picked in their panel.
     * @param int                   $createdAt
     * @param int                   $updatedAt
     * @param string                $mode      A {@see \MTGPocket\Modes\GameMode} id.
     * @param bool                  $ranked    Paired by matchmaking; the result moves ratings.
     * @param list<array{id: string, points: int, quests: list<array{label: string, points: int}>}> $rewards Points the finished game paid, and the quests it completed.
     */
    public function __construct(
        public readonly string $id,
        public string $status,
        public array $players,
        public ?Game $game = null,
        public array $choices = [],
        public int $createdAt = 0,
        public int $updatedAt = 0,
        public string $mode = 'casual',
        public bool $ranked = false,
        public array $rewards = [],
    ) {
    }

    /**
     * Whether it still needs its players: a challenge or a game in play.
     *
     * @return bool
     */
    public function isLive(): bool
    {
        return $this->status === self::PENDING || $this->status === self::PLAYING;
    }

    public function challenger(): array
    {
        return $this->players[0];
    }

    public function opponent(): array
    {
        return $this->players[1];
    }

    /**
     * @param string $playerId
     *
     * @return bool
     */
    public function has(string $playerId): bool
    {
        return in_array($playerId, array_column($this->players, 'id'), true);
    }

    /**
     * A player's picks in their action panel.
     *
     * @param string $playerId
     *
     * @return array
     */
    public function choice(string $playerId): array
    {
        return $this->choices[$playerId] ?? [];
    }

    /**
     * The game's record as text, for review: the match's own tags (id,
     * mode, date, decks), then the {@see GameRecord}.
     *
     * @return string|null Null before the game has started.
     */
    public function transcript(): ?string
    {
        if ($this->game === null) {
            return null;
        }
        $tags = [
            'Match' => $this->id,
            'Mode' => ucfirst($this->mode).($this->ranked ? ' (ranked)' : ' (friendly)'),
        ];
        if ($this->createdAt > 0) {
            $tags['Date'] = gmdate('Y.m.d H:i', $this->createdAt).' UTC';
        }
        foreach ($this->players as $seat => $player) {
            $tags['Seat '.($seat + 1)] = $player['name'];
            if (($player['deckName'] ?? null) !== null) {
                $tags['Seat '.($seat + 1).' deck'] = (string) $player['deckName'];
            }
        }

        return GameRecord::render($this->game, $tags);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'players' => $this->players,
            'game' => $this->game?->toArray(),
            'choices' => (object) $this->choices,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'mode' => $this->mode,
            'ranked' => $this->ranked,
            'rewards' => $this->rewards,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['status'],
            array_values(array_map(fn ($player) => (array) $player, (array) $data['players'])),
            isset($data['game']) ? Game::fromArray((array) $data['game']) : null,
            array_map(fn ($choice) => (array) $choice, (array) ($data['choices'] ?? [])),
            (int) ($data['createdAt'] ?? 0),
            (int) ($data['updatedAt'] ?? 0),
            // Matches from before game modes were played like Casual.
            (string) ($data['mode'] ?? 'casual'),
            (bool) ($data['ranked'] ?? false),
            array_values(array_map(fn ($reward) => [
                'id' => (string) $reward['id'],
                'points' => (int) $reward['points'],
                'quests' => array_values(array_map(fn ($quest) => ['label' => (string) $quest['label'], 'points' => (int) $quest['points']], (array) ($reward['quests'] ?? []))),
            ], (array) ($data['rewards'] ?? []))),
        );
    }
}
