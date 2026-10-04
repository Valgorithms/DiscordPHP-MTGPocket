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

namespace MTGPocket\Game\Mana;

/**
 * Pays a cost for the player, the way MTG Arena does: from floating mana
 * first, then by activating mana abilities of untapped permanents
 * (rule 601.2g, which lets a player activate mana abilities while paying).
 *
 * Colored and colorless symbols are matched to mana first (a bipartite
 * matching, so a dual land is saved for the color only it can make), then
 * generic mana takes what is left. Floating mana and the sources that can
 * make the fewest colors are spent first.
 *
 * @since 0.3.0
 */
final class ManaPayer
{
    /**
     * Finds how to pay.
     *
     * @param array{mana: array<string, int>, life: int}               $payment One of {@see ManaCost::payments()}.
     * @param ManaPool                                                 $pool
     * @param array<int, array{count: int, colors: string[], fixed?: string[][]}> $sources Untapped mana sources by object id: each makes `count` mana of one of `colors`, or one mana of each of `fixed`.
     *
     * @return array{pool: array<string, int>, tap: int[], float: array<string, int>, made: array<int, string>}|null What to take from the pool, which sources to tap, the mana they make beyond the cost (it stays in the pool) and the type each tapped source made. Null when it cannot be paid.
     */
    public static function plan(array $payment, ManaPool $pool, array $sources): ?array
    {
        // Every mana that could be spent, floating mana first.
        $units = [];
        foreach (ManaCost::TYPES as $type) {
            for ($i = 0; $i < $pool->get($type); $i++) {
                $units[] = ['source' => null, 'colors' => [$type]];
            }
        }
        uasort($sources, fn (array $a, array $b) => count($a['colors']) <=> count($b['colors']));
        foreach ($sources as $id => $source) {
            // A bounce land's `{W}{U}`: one mana of each, fixed.
            foreach ($source['fixed'] ?? array_fill(0, $source['count'], $source['colors']) as $colors) {
                $units[] = ['source' => $id, 'colors' => $colors];
            }
        }

        $pips = [];
        foreach ($payment['mana'] as $type => $amount) {
            if ($type !== 'generic') {
                array_push($pips, ...array_fill(0, $amount, $type));
            }
        }
        $generic = $payment['mana']['generic'] ?? 0;
        if (count($pips) + $generic > count($units)) {
            return null;
        }

        // Kuhn's augmenting paths: pip => unit.
        $owner = [];
        foreach ($pips as $pip => $type) {
            $seen = [];
            if (! self::augment($pip, $pips, $units, $owner, $seen)) {
                return null;
            }
        }

        $used = [];
        foreach ($owner as $unit => $pip) {
            $used[$unit] = $pips[$pip];
        }
        // Generic mana: floating mana and what already-tapped sources make, then new sources.
        $tapped = [];
        foreach (array_keys($used) as $unit) {
            if ($units[$unit]['source'] !== null) {
                $tapped[$units[$unit]['source']] = true;
            }
        }
        foreach ([true, false] as $firstPass) {
            foreach ($units as $unit => $data) {
                if ($generic === 0) {
                    break 2;
                }
                $free = $data['source'] === null || isset($tapped[$data['source']]);
                if (! isset($used[$unit]) && (! $firstPass || $free)) {
                    $used[$unit] = $data['colors'][0];
                    if ($data['source'] !== null) {
                        $tapped[$data['source']] = true;
                    }
                    $generic--;
                }
            }
        }
        if ($generic > 0) {
            return null;
        }

        $fromPool = [];
        $tap = [];
        $made = [];
        foreach ($used as $unit => $type) {
            if ($units[$unit]['source'] === null) {
                $fromPool[$type] = ($fromPool[$type] ?? 0) + 1;
            } else {
                $tap[$units[$unit]['source']] = true;
                $made[$units[$unit]['source']] ??= $type;
            }
        }

        // A tapped source makes all its mana; what the cost does not use floats.
        $float = [];
        foreach ($units as $unit => $data) {
            if ($data['source'] !== null && isset($tap[$data['source']]) && ! isset($used[$unit])) {
                $float[$data['colors'][0]] = ($float[$data['colors'][0]] ?? 0) + 1;
            }
        }

        return ['pool' => $fromPool, 'tap' => array_keys($tap), 'float' => $float, 'made' => $made];
    }

    /**
     * @param int   $pip
     * @param array $pips
     * @param array $units
     * @param array $owner Unit => pip.
     * @param array $seen
     *
     * @return bool
     */
    private static function augment(int $pip, array $pips, array $units, array &$owner, array &$seen): bool
    {
        foreach ($units as $unit => $data) {
            if (isset($seen[$unit]) || ! in_array($pips[$pip], $data['colors'], true)) {
                continue;
            }
            $seen[$unit] = true;
            if (! isset($owner[$unit]) || self::augment($owner[$unit], $pips, $units, $owner, $seen)) {
                $owner[$unit] = $pip;

                return true;
            }
        }

        return false;
    }
}
