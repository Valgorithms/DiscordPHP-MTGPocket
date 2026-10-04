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

namespace MTGPocket\Economy;

/**
 * What a purchase or a sale did: the cards, the points each line came to,
 * and the player's points afterwards.
 *
 * @since 0.4.0
 */
final class Receipt
{
    /**
     * @param list<array{card: array, count: int, points: int}> $lines  Points are for the whole line.
     * @param int                                               $total  Points paid or earned.
     * @param int                                               $balance Points left.
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $total,
        public readonly int $balance,
    ) {
    }

    /**
     * The number of cards, counting copies.
     *
     * @return int
     */
    public function cards(): int
    {
        return array_sum(array_column($this->lines, 'count'));
    }
}
