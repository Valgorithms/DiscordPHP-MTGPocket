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
 * How much of the imported cards' rules text the engine reads.
 *
 *   php bin/card-coverage.php        the share of cards read whole, and the 40 most common unread lines
 *   php bin/card-coverage.php 100    … and the 100 most common
 *
 * Reads the pools `composer import-cards` wrote to MTGPOCKET_DATA
 * (default var/data). Each card is counted once, by name.
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

$seen = [];
$whole = 0;
$lines = [];
foreach ($files as $file) {
    foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['cards'] as $card) {
        if (isset($seen[$card['name']])) {
            continue;
        }
        $seen[$card['name']] = true;
        $unsupported = (new CardDefinition($card))->unsupported;
        if ($unsupported === []) {
            $whole++;
        }
        foreach ($unsupported as $line) {
            // Group lines that differ only in numbers, costs and names.
            $pattern = preg_replace(['/\{[^}]+\}/', '/\b\d+\b/', '/\b(one|two|three|four|five|six)\b/'], ['{…}', 'N', 'N'], strtok($line, "\n"));
            $lines[$pattern] = ($lines[$pattern] ?? 0) + 1;
        }
    }
}

$total = count($seen);
printf("%s of %s cards (%.1f%%) are read whole.\n\nMost common lines not read yet:\n", number_format($whole), number_format($total), 100 * $whole / max(1, $total));
arsort($lines);
foreach (array_slice($lines, 0, (int) ($argv[1] ?? 40), true) as $line => $count) {
    printf("%6d  %s\n", $count, mb_strimwidth($line, 0, 110, '…'));
}
