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
 * Every card a player owns, by MTGJSON printing uuid.
 *
 * @since 0.1.0
 */
class Inventory implements \JsonSerializable
{
    /**
     * @param string     $playerId The Discord user id.
     * @param CardCounts $cards    Printing uuid => copies owned.
     */
    public function __construct(
        public readonly string $playerId,
        public readonly CardCounts $cards = new CardCounts(),
    ) {
    }

    /**
     * @param array $data As stored.
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static((string) $data['playerId'], new CardCounts((array) ($data['cards'] ?? [])));
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'playerId' => $this->playerId,
            'cards' => $this->cards,
        ];
    }
}
