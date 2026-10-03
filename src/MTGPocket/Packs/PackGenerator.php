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

use MTGPocket\Cards\CardPool;
use Random\Randomizer;

/**
 * Builds packs of one color of one set.
 *
 * A pack has {@see SIZE} cards in four kinds of slot, like a real draft
 * booster without its basic land:
 *
 * - {@see COMMONS} commons and {@see UNCOMMONS} uncommons;
 * - one wildcard slot, usually a common or uncommon but sometimes rarer;
 * - one rare slot, which is always a rare or a mythic rare, so every pack
 *   holds at least one. A mythic comes up as often as it would on a real
 *   print sheet, where each rare is printed twice and each mythic once.
 *
 * A pack holds no card twice while the color has enough cards. When a
 * color runs short of a rarity, the slot takes the nearest rarity that has
 * cards left; the rare slot never falls below rare, and is drawn first so
 * the wildcard cannot take its card.
 *
 * @since 0.2.0
 */
class PackGenerator
{
    public const int SIZE = 15;
    public const int COMMONS = 10;
    public const int UNCOMMONS = 3;

    /**
     * How likely the wildcard slot is to be each rarity, out of 100.
     *
     * @var array<string, int>
     */
    public const array WILDCARD_ODDS = ['common' => 70, 'uncommon' => 20, 'rare' => 8, 'mythic' => 2];

    /**
     * Rarities a slot falls back to, nearest first, when its own has run out.
     *
     * @var array<string, string[]>
     */
    protected const array FALLBACK = [
        'common' => ['common', 'uncommon', 'rare', 'mythic'],
        'uncommon' => ['uncommon', 'common', 'rare', 'mythic'],
        'rare' => ['rare', 'mythic'],
        'mythic' => ['mythic', 'rare'],
    ];

    /**
     * Fallbacks for the wildcard slot, which, unlike the rare slot, may go
     * below rare rather than repeat a card.
     *
     * @var array<string, string[]>
     */
    protected const array WILDCARD_FALLBACK = [
        'common' => ['common', 'uncommon', 'rare', 'mythic'],
        'uncommon' => ['uncommon', 'common', 'rare', 'mythic'],
        'rare' => ['rare', 'mythic', 'uncommon', 'common'],
        'mythic' => ['mythic', 'rare', 'uncommon', 'common'],
    ];

    protected Randomizer $random;

    /**
     * @param Randomizer|null $random A seeded one makes packs repeatable, for tests.
     */
    public function __construct(?Randomizer $random = null)
    {
        $this->random = $random ?? new Randomizer();
    }

    /**
     * Opens a pack.
     *
     * @param CardPool $pool
     * @param string   $color One of {@see CardPool::COLORS}.
     *
     * @throws \InvalidArgumentException When the color has no rare or mythic rare to fill the rare slot.
     *
     * @return Pack
     */
    public function generate(CardPool $pool, string $color): Pack
    {
        if (! $pool->hasRareSlot($color)) {
            throw new \InvalidArgumentException("{$pool->setName} has no rare or mythic rare {$color} cards, so it has no {$color} packs.");
        }

        $taken = [];
        $uuids = [];
        foreach (array_fill(0, self::COMMONS, 'common') as $rarity) {
            $uuids[] = $this->draw($pool, $color, $rarity, $taken);
        }
        foreach (array_fill(0, self::UNCOMMONS, 'uncommon') as $rarity) {
            $uuids[] = $this->draw($pool, $color, $rarity, $taken);
        }
        // The rare slot draws before the wildcard, so a rare wildcard can
        // never take the only rare and leave the rare slot a repeat.
        $rare = $this->draw($pool, $color, $this->rareSlotRarity($pool, $color), $taken);
        $uuids[] = $this->draw($pool, $color, $this->wildcardRarity($pool, $color), $taken, true);
        $uuids[] = $rare;

        return new Pack($pool->setCode, $pool->setName, $color, array_map(fn (string $uuid) => $pool->card($uuid), $uuids));
    }

    /**
     * The rare slot's rarity: mythic one time in (2 × rares + mythics) per
     * mythic, rare otherwise.
     *
     * @param CardPool $pool
     * @param string   $color
     *
     * @return string
     */
    protected function rareSlotRarity(CardPool $pool, string $color): string
    {
        $rares = count($pool->uuids($color, 'rare'));
        $mythics = count($pool->uuids($color, 'mythic'));

        return $this->random->getInt(1, 2 * $rares + $mythics) <= $mythics ? 'mythic' : 'rare';
    }

    /**
     * The wildcard slot's rarity, from {@see WILDCARD_ODDS} over the
     * rarities the color has.
     *
     * @param CardPool $pool
     * @param string   $color
     *
     * @return string
     */
    protected function wildcardRarity(CardPool $pool, string $color): string
    {
        $odds = array_filter(self::WILDCARD_ODDS, fn (string $rarity) => $pool->uuids($color, $rarity) !== [], ARRAY_FILTER_USE_KEY);
        $roll = $this->random->getInt(1, array_sum($odds));
        foreach ($odds as $rarity => $weight) {
            if (($roll -= $weight) <= 0) {
                return $rarity;
            }
        }

        return array_key_last($odds);
    }

    /**
     * Draws one card for a slot: one not yet in the pack, of the slot's
     * rarity or the nearest that has one left; a repeat only when the color
     * has nothing left to give.
     *
     * @param CardPool            $pool
     * @param string              $color
     * @param string              $rarity
     * @param array<string, bool> $taken    Uuids already in the pack.
     * @param bool                $wildcard Use {@see WILDCARD_FALLBACK}.
     *
     * @return string
     */
    protected function draw(CardPool $pool, string $color, string $rarity, array &$taken, bool $wildcard = false): string
    {
        $fallback = ($wildcard ? self::WILDCARD_FALLBACK : self::FALLBACK)[$rarity];
        foreach ($fallback as $candidate) {
            $left = array_values(array_filter($pool->uuids($color, $candidate), fn (string $uuid) => ! isset($taken[$uuid])));
            if ($left !== []) {
                $uuid = $left[$this->random->getInt(0, count($left) - 1)];
                $taken[$uuid] = true;

                return $uuid;
            }
        }

        // Fewer distinct cards than slots: allow a repeat.
        foreach ($fallback as $candidate) {
            if ($all = $pool->uuids($color, $candidate)) {
                return $all[$this->random->getInt(0, count($all) - 1)];
            }
        }

        throw new \InvalidArgumentException("{$pool->setName} has no {$color} cards.");
    }
}
