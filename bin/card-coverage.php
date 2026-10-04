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

/*
 * How much of the imported cards' rules text the engine reads, newest sets
 * first.
 *
 *   php bin/card-coverage.php                    the share read whole, per set for the newest 15, and the 40 most common unread lines in sets of the last two years
 *   php bin/card-coverage.php 100                … the 100 most common lines
 *   php bin/card-coverage.php 40 2020-01-01      … in sets released since 2020
 *   php bin/card-coverage.php 40 all             … in every set
 *
 * Reads the pools `composer import-cards` wrote to MTGPOCKET_DATA
 * (default var/data). Each card is counted once, by name, in the newest
 * set that prints it.
 */

use MTGPocket\Game\CardDefinition;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/env.php';

$baseDir = dirname(__DIR__);
loadEnv($baseDir.'/.env', false);
ini_set('memory_limit', '-1');

$files = glob((getenv('MTGPOCKET_DATA') ?: $baseDir.'/var/data').'/pools/*.json');
if ($files === []) {
    fwrite(STDERR, "No imported card pools; run composer import-cards first.\n");
    exit(1);
}

$pools = array_map(fn (string $file) => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
usort($pools, fn (array $a, array $b) => [$b['releaseDate'] ?? '', $b['setCode'] ?? ''] <=> [$a['releaseDate'] ?? '', $a['setCode'] ?? '']);
$newest = $pools[0]['releaseDate'] ?? date('Y-m-d');
$since = $argv[2] ?? date('Y-m-d', strtotime($newest.' -2 years'));
if ($since === 'all') {
    $since = '';
}

$seen = [];
$whole = 0;
$sets = [];
$lines = [];
foreach ($pools as $pool) {
    $date = $pool['releaseDate'] ?? '';
    $set = ['code' => $pool['setCode'] ?? '?', 'date' => $date, 'whole' => 0, 'total' => 0];
    foreach ($pool['cards'] as $card) {
        if (isset($seen[$card['name']])) {
            continue;
        }
        $seen[$card['name']] = true;
        $unsupported = (new CardDefinition($card))->unsupported;
        $set['total']++;
        if ($unsupported === []) {
            $whole++;
            $set['whole']++;
        }
        if ($date < $since) {
            continue;
        }
        foreach ($unsupported as $line) {
            // Group lines that differ only in numbers, costs and names.
            $pattern = preg_replace(['/\{[^}]+\}/', '/\b\d+\b/', '/\b(one|two|three|four|five|six)\b/'], ['{…}', 'N', 'N'], strtok($line, "\n"));
            $lines[$pattern]['count'] = ($lines[$pattern]['count'] ?? 0) + 1;
            $lines[$pattern]['set'] ??= $pool['setCode'] ?? '?';
        }
    }
    $sets[] = $set;
}

$total = count($seen);
printf("%s of %s cards (%.1f%%) are read whole.\n\nNewest sets (cards first printed there or reprinted from older sets):\n", number_format($whole), number_format($total), 100 * $whole / max(1, $total));
foreach (array_slice(array_filter($sets, fn (array $set) => $set['total'] > 0), 0, 15) as $set) {
    printf("  %-6s %s  %4d of %4d (%5.1f%%)\n", $set['code'], $set['date'] ?: '????-??-??', $set['whole'], $set['total'], 100 * $set['whole'] / $set['total']);
}
printf("\nMost common lines not read yet%s (newest set it appears in):\n", $since === '' ? '' : " in sets since {$since}");
uasort($lines, fn (array $a, array $b) => $b['count'] <=> $a['count']);
foreach (array_slice($lines, 0, (int) ($argv[1] ?? 40), true) as $line => $info) {
    printf("%6d  %-6s %s\n", $info['count'], $info['set'], mb_strimwidth((string) $line, 0, 104, '…'));
}
