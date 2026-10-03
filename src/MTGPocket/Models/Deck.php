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
 * A player's deck: a main deck and a side deck, built for one format.
 * Which cards a format allows, and how many, is checked by the game modes
 * later; a deck only records what the player put in it.
 *
 * @since 0.1.0
 */
class Deck implements \JsonSerializable
{
    /**
     * @param string     $id        Unique among the player's decks.
     * @param string     $playerId  The owner's Discord user id.
     * @param string     $name      The player's name for it.
     * @param string     $format    The game mode it is built for, e.g. `standard`.
     * @param CardCounts $main      The main deck.
     * @param CardCounts $side      The side deck.
     * @param int        $createdAt Unix time.
     * @param int        $updatedAt Unix time.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $playerId,
        public string $name,
        public string $format = 'standard',
        public readonly CardCounts $main = new CardCounts(),
        public readonly CardCounts $side = new CardCounts(),
        public int $createdAt = 0,
        public int $updatedAt = 0,
    ) {
        if ($this->createdAt === 0) {
            $this->createdAt = time();
        }
        if ($this->updatedAt === 0) {
            $this->updatedAt = $this->createdAt;
        }
    }

    /**
     * Every card the deck uses, main and side together, for checking it
     * against the owner's inventory.
     *
     * @return CardCounts
     */
    public function allCards(): CardCounts
    {
        return (new CardCounts($this->main->toArray()))->merge($this->side);
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
            (string) $data['playerId'],
            (string) ($data['name'] ?? $data['id']),
            (string) ($data['format'] ?? 'standard'),
            new CardCounts((array) ($data['main'] ?? [])),
            new CardCounts((array) ($data['side'] ?? [])),
            (int) ($data['createdAt'] ?? 0),
            (int) ($data['updatedAt'] ?? 0),
        );
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'playerId' => $this->playerId,
            'name' => $this->name,
            'format' => $this->format,
            'main' => $this->main,
            'side' => $this->side,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
