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

/**
 * A match between two players: a challenge until the opponent accepts,
 * then the game itself, and what each player has picked so far in their
 * action panel (the spell they are casting and its targets, their blocks).
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
     */
    public function __construct(
        public readonly string $id,
        public string $status,
        public array $players,
        public ?Game $game = null,
        public array $choices = [],
        public int $createdAt = 0,
        public int $updatedAt = 0,
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
        );
    }
}
