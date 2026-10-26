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

namespace MTGPocket\Cards;

use MTGPocket\Game\CardDefinition;

/** Offline recognition inventory. It deliberately makes no gameplay certification. */
final class CoverageAudit
{
    /**
     * Count each name in its newest imported pool, as card-coverage.php does.
     * Fingerprints exclude import timestamps and include complete selected card data,
     * including back/split/prepared faces. Unread clauses remain available for triage.
     *
     * @param array[]       $pools
     * @param string[]|null $legalNames Null includes all imported names.
     *
     * @return array
     */
    public static function report(array $pools, ?array $legalNames = null): array
    {
        foreach ($pools as $pool) {
            if (! is_string($pool['setCode'] ?? null) || ! is_array($pool['cards'] ?? null)) {
                throw new \InvalidArgumentException('Each pool needs a setCode and cards array.');
            }
        }
        usort($pools, fn (array $a, array $b) => [$b['releaseDate'] ?? '', $b['setCode']] <=> [$a['releaseDate'] ?? '', $a['setCode']]);
        $legal = $legalNames === null ? null : array_fill_keys($legalNames, true);
        $selected = [];
        $versions = [];
        foreach ($pools as $pool) {
            $versions[(string) ($pool['mtgjsonVersion'] ?? 'unknown')] = true;
            foreach ($pool['cards'] as $card) {
                if (! is_string($card['name'] ?? null) || $card['name'] === '') {
                    throw new \InvalidArgumentException('Each card needs a nonempty name.');
                }
                $name = $card['name'];
                if (! isset($selected[$name]) && ($legal === null || isset($legal[$name]))) {
                    $selected[$name] = ['setCode' => $pool['setCode'], 'card' => $card];
                }
            }
        }
        ksort($selected, SORT_STRING);
        ksort($versions, SORT_STRING);
        $hash = hash_init('sha256');
        $recognized = [];
        $unread = [];
        $clauses = 0;
        foreach ($selected as $name => $entry) {
            hash_update($hash, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $unsupported = (new CardDefinition($entry['card']))->unsupported;
            if ($unsupported === []) {
                $recognized[] = $name;
            }
            $clauses += count($unsupported);
            foreach (array_unique($unsupported) as $line) {
                $pattern = preg_replace(['/\{[^}]+\}/', '/\b\d+\b/', '/\b(one|two|three|four|five|six)\b/'], ['{.}', 'N', 'N'], $line);
                $unread[$pattern][$name] = true;
            }
        }
        $families = [];
        foreach ($unread as $text => $names) {
            $families[] = ['text' => $text, 'cards' => count($names), 'examples' => array_slice(array_keys($names), 0, 3)];
        }
        usort($families, fn (array $a, array $b) => ($b['cards'] <=> $a['cards']) ?: strcmp($a['text'], $b['text']));

        return [
            'schemaVersion' => 1,
            'scope' => 'Newest printing per name in the supplied imported pools; not the entire MTGJSON database.',
            'corpus' => ['pools' => count($pools), 'mtgjsonVersions' => array_keys($versions), 'selectedCardsSha256' => hash_final($hash)],
            'recognition' => ['total' => count($selected), 'whole' => count($recognized), 'withUnreadText' => count($selected) - count($recognized), 'unreadClauses' => $clauses],
            'implementedRules' => ['count' => null, 'reason' => 'Requires reviewed parser/engine contracts; recognition is not implementation evidence.'],
            'supportedWholeCards' => ['count' => null, 'reason' => 'Requires a card-by-card certification against a declared rules/mode scope.'],
            'gameplayTestedWholeCards' => ['count' => null, 'reason' => 'Mechanic tests and games reaching an end do not certify every ability or interaction of a whole card.'],
            'recognizedNames' => $recognized,
            'unreadPatterns' => $families,
        ];
    }
}
