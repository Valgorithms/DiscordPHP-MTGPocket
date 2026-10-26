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

use MTGPocket\Cards\FunctionalCoverageLedger;
use MTGPocket\Game\CardDefinition;

require __DIR__.'/../vendor/autoload.php';

// Explicit local files only; parse matching candidates, never the entire pool.
try {
    if ($argc !== 3 || trim($argv[2]) === '') {
        throw new InvalidArgumentException('Usage: php bin/ability-candidates.php POOLS_DIRECTORY "LITERAL TEXT FRAGMENT"');
    }
    $files = glob(rtrim($argv[1], '/\\').'/*.json');
    if ($files === false || $files === []) {
        throw new InvalidArgumentException('No pool files at the explicit path.');
    }
    $pools = (function () use ($files): Generator {
        foreach ($files as $file) {
            yield json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }
    })();
    $selected = FunctionalCoverageLedger::select($pools);
    $normalize = fn (string $text): string => (string) preg_replace('/\s+/u', ' ', $text);
    $needle = $normalize(trim($argv[2]));
    $candidates = [];
    foreach ($selected['cards'] as $name => $entry) {
        $texts = [];
        $face = $entry['card'];
        do {
            $texts[] = (string) ($face['text'] ?? '');
            $face = $face['back'] ?? null;
        } while (is_array($face));
        if (stripos($normalize(implode("\n", $texts)), $needle) === false) {
            continue;
        }
        $definition = new CardDefinition($entry['card']);
        $candidates[] = $entry + ['unsupported' => $definition->unsupported];
    }
    echo json_encode([
        'scope' => 'Literal-fragment candidates only; no full parser audit or whole-card certification.',
        'corpus' => $selected['corpus'], 'selectedNames' => count($selected['cards']),
        'fragment' => $needle, 'count' => count($candidates), 'candidates' => $candidates,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
