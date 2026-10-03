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

namespace MTGPocket\Tests;

use MTGPocket\Cards\CardPool;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Pocket;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * A game with imported card pools, a seeded pack generator and a clock the
 * test sets.
 */
abstract class PocketTestCase extends StorageTestCase
{
    protected Pocket $pocket;

    protected int $now = 1_790_000_000; // 2026-09-21 14:13:20 UTC

    protected function setUp(): void
    {
        parent::setUp();
        $this->pocket = new Pocket(
            $this->directory,
            new PackGenerator(new Randomizer(new Xoshiro256StarStar(42))),
            fn () => $this->now,
        );
    }

    /**
     * Imports a pool with a given number of cards of each color and rarity;
     * uuids are `{SET}-{color}-{rarity}-{n}` and names `{SET} {color} {rarity} {n}`.
     *
     * @param string                            $setCode
     * @param array<string, array<string, int>> $sizes   Color => rarity => cards.
     * @param string                            $releaseDate
     *
     * @return CardPool
     */
    protected function importPool(string $setCode, array $sizes, string $releaseDate = '2020-01-01'): CardPool
    {
        $pool = new CardPool($setCode, "Set {$setCode}", $releaseDate);
        foreach ($sizes as $color => $rarities) {
            foreach ($rarities as $rarity => $size) {
                for ($n = 1; $n <= $size; $n++) {
                    $pool->add([
                        'uuid' => "{$setCode}-{$color}-{$rarity}-{$n}",
                        'name' => "{$setCode} {$color} {$rarity} {$n}",
                        'number' => (string) $n,
                        'rarity' => $rarity,
                        'colors' => match ($color) {
                            CardPool::COLORLESS => [],
                            CardPool::MULTICOLOR => ['W', 'U'],
                            default => [$color],
                        },
                        'manaValue' => 2.0,
                        'type' => $rarity === 'common' ? 'Creature — Bear' : 'Instant',
                        'scryfallId' => '0123456789abcdef',
                    ]);
                }
            }
        }
        $this->pocket->pools->save($pool);

        return $pool;
    }

    /**
     * A full-sized color: 20 commons, 12 uncommons, 8 rares, 2 mythics.
     *
     * @return array<string, int>
     */
    protected static function fullColor(): array
    {
        return ['common' => 20, 'uncommon' => 12, 'rare' => 8, 'mythic' => 2];
    }
}
