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

namespace MTGPocket\Models;

/**
 * A Discord user who plays: their profile and when they last opened their
 * free daily pack. Their cards are an {@see Inventory} and their decks are
 * {@see Deck}s, each stored on its own.
 *
 * @since 0.1.0
 */
class Player implements \JsonSerializable
{
    /**
     * @param string      $id              The Discord user id.
     * @param string      $name            Display name when last seen.
     * @param int         $createdAt       Unix time the player first played.
     * @param int|null    $lastDailyPackAt Unix time of the last free daily pack, or null if never.
     * @param string|null $activeDeckId    The deck the player queues with.
     */
    public function __construct(
        public readonly string $id,
        public string $name = '',
        public int $createdAt = 0,
        public ?int $lastDailyPackAt = null,
        public ?string $activeDeckId = null,
    ) {
        if ($this->createdAt === 0) {
            $this->createdAt = time();
        }
    }

    /**
     * @param array $data As stored.
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data['id'],
            (string) ($data['name'] ?? ''),
            (int) ($data['createdAt'] ?? 0),
            isset($data['lastDailyPackAt']) ? (int) $data['lastDailyPackAt'] : null,
            isset($data['activeDeckId']) ? (string) $data['activeDeckId'] : null,
        );
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'createdAt' => $this->createdAt,
            'lastDailyPackAt' => $this->lastDailyPackAt,
            'activeDeckId' => $this->activeDeckId,
        ];
    }
}
