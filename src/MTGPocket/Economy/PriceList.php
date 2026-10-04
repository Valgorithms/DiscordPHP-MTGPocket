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

use MTGPocket\Cards\CardPool;

/**
 * What cards and packs cost in points, and what selling a card pays, from
 * the table in `config/economy.php`.
 *
 * A card's price is its rarity's price times its set's age multiplier, so
 * rarer cards and newer sets cost more. Selling pays a fixed share of the
 * buy price, under 1, so points cannot be made by selling and buying back.
 *
 * @since 0.4.0
 */
class PriceList
{
    /**
     * The table that comes with the game.
     */
    public const string DEFAULT_FILE = __DIR__.'/../../../config/economy.php';

    /**
     * @param array<string, int>                                 $buy      Rarity => points.
     * @param int                                                $pack     Points for a pack, before age.
     * @param float                                              $sellRate Share of the buy price a sale pays, in (0, 1).
     * @param list<array{days: int|null, multiplier: float}>     $age      Youngest tier first.
     *
     * @throws \InvalidArgumentException When the table would let points be farmed or a price is missing.
     */
    public function __construct(
        public readonly array $buy,
        public readonly int $pack,
        public readonly float $sellRate,
        public readonly array $age,
    ) {
        foreach (CardPool::RARITIES as $rarity) {
            if (! isset($buy[$rarity]) || $buy[$rarity] < 1) {
                throw new \InvalidArgumentException("The price list needs a buy price of at least 1 for {$rarity} cards.");
            }
        }
        if ($pack < 1) {
            throw new \InvalidArgumentException('The price list needs a pack price of at least 1.');
        }
        if ($sellRate <= 0 || $sellRate >= 1) {
            throw new \InvalidArgumentException('The sell rate must be more than 0 and less than 1, so selling never pays what buying costs.');
        }
        if ($age === []) {
            throw new \InvalidArgumentException('The price list needs at least one age tier.');
        }
        foreach ($age as $tier) {
            if (($tier['multiplier'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('Every age tier needs a multiplier above 0.');
            }
        }
    }

    /**
     * Reads a table like `config/economy.php`.
     *
     * @param string $file
     *
     * @throws \InvalidArgumentException
     *
     * @return static
     */
    public static function fromFile(string $file = self::DEFAULT_FILE): static
    {
        if (! is_file($file)) {
            throw new \InvalidArgumentException("There is no price list at {$file}.");
        }

        return static::fromArray((array) require $file);
    }

    /**
     * @param array $config As `config/economy.php` returns it.
     *
     * @return static
     */
    public static function fromArray(array $config): static
    {
        $age = [];
        foreach ((array) ($config['age'] ?? []) as $tier) {
            $age[] = [
                'days' => isset($tier['days']) ? (int) $tier['days'] : null,
                'multiplier' => (float) ($tier['multiplier'] ?? 0),
            ];
        }
        // Youngest first, with "any age" last.
        usort($age, fn (array $a, array $b) => ($a['days'] ?? PHP_INT_MAX) <=> ($b['days'] ?? PHP_INT_MAX));

        return new static(
            array_map('intval', (array) ($config['buy'] ?? [])),
            (int) ($config['pack'] ?? 0),
            (float) ($config['sell_rate'] ?? 0),
            $age,
        );
    }

    /**
     * The age multiplier of a set released on a date.
     *
     * @param string|null $releaseDate `YYYY-MM-DD`; null counts as the oldest.
     * @param int         $now         Unix time.
     *
     * @return float
     */
    public function ageMultiplier(?string $releaseDate, int $now): float
    {
        $released = $releaseDate === null ? false : strtotime($releaseDate.' 00:00:00 UTC');
        if ($released === false) {
            return $this->age[array_key_last($this->age)]['multiplier'];
        }

        $days = max(0, intdiv($now - $released, 86400));
        foreach ($this->age as $tier) {
            if ($tier['days'] === null || $days <= $tier['days']) {
                return $tier['multiplier'];
            }
        }

        return $this->age[array_key_last($this->age)]['multiplier'];
    }

    /**
     * What a card costs to buy.
     *
     * @param string      $rarity      One of {@see CardPool::RARITIES}.
     * @param string|null $releaseDate Its set's release date.
     * @param int         $now
     *
     * @return int
     */
    public function buyPrice(string $rarity, ?string $releaseDate, int $now): int
    {
        return max(1, (int) round(($this->buy[$rarity] ?? $this->buy['common']) * $this->ageMultiplier($releaseDate, $now)));
    }

    /**
     * What selling a card pays: always less than buying it, and at least 1.
     *
     * @param string      $rarity
     * @param string|null $releaseDate
     * @param int         $now
     *
     * @return int
     */
    public function sellPrice(string $rarity, ?string $releaseDate, int $now): int
    {
        $buy = $this->buyPrice($rarity, $releaseDate, $now);

        return max(1, min($buy - 1, (int) floor($buy * $this->sellRate)));
    }

    /**
     * What a pack of a set costs.
     *
     * @param string|null $releaseDate
     * @param int         $now
     *
     * @return int
     */
    public function packPrice(?string $releaseDate, int $now): int
    {
        return max(1, (int) round($this->pack * $this->ageMultiplier($releaseDate, $now)));
    }
}
