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

namespace MTGPocket\Packs;

/**
 * An opened pack: the cards of one color of one set, in the order they are
 * revealed (commons first, the rare slot last).
 *
 * @since 0.2.0
 */
final class Pack implements \JsonSerializable
{
    /**
     * @param string  $setCode
     * @param string  $setName
     * @param string  $color   One of {@see \MTGPocket\Cards\CardPool::COLORS}.
     * @param array[] $cards   Pool card data, in reveal order.
     */
    public function __construct(
        public readonly string $setCode,
        public readonly string $setName,
        public readonly string $color,
        public readonly array $cards,
    ) {
    }

    /**
     * Copies of each card: uuid => count.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->cards as $card) {
            $counts[$card['uuid']] = ($counts[$card['uuid']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The cards of some rarities.
     *
     * @param string ...$rarities
     *
     * @return array[]
     */
    public function ofRarity(string ...$rarities): array
    {
        return array_values(array_filter($this->cards, fn (array $card) => in_array($card['rarity'], $rarities, true)));
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'setCode' => $this->setCode,
            'setName' => $this->setName,
            'color' => $this->color,
            'cards' => array_column($this->cards, 'uuid'),
        ];
    }
}
