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

require __DIR__.'/../vendor/autoload.php';

// Explicit files only: no dotenv, bot bootstrap, database, network or parser walk.
try {
    if ($argc < 2 || $argc > 4) {
        throw new InvalidArgumentException('Usage: php bin/functional-coverage.php POOLS_DIRECTORY [RECOGNITION_JSON [MANIFEST_JSON]]');
    }
    $root = dirname(__DIR__);
    $manifest = json_decode(file_get_contents($argv[3] ?? $root.'/config/coverage-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
    $sources = [];
    foreach ($manifest['sourcePins'] as $path => $hash) {
        if (! preg_match('~^(?:src|tests)/[a-zA-Z0-9_/]+\.php$~', $path)) {
            throw new InvalidArgumentException('Source pins must be repository PHP paths.');
        }
        $sources[$path] = file_get_contents($root.'/'.$path);
    }
    $recognition = FunctionalCoverageLedger::verifyInputs(file_get_contents($argv[2] ?? $root.'/config/coverage-recognition-2026-10-22.json'), $manifest, $sources);
    $files = glob(rtrim($argv[1], '/\\').'/*.json');
    if ($files === false || $files === []) {
        throw new InvalidArgumentException('No pool files at the explicit path.');
    }
    $pools = (function () use ($files): \Generator {
        foreach ($files as $file) {
            yield json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }
    })();
    $ledger = FunctionalCoverageLedger::report(FunctionalCoverageLedger::select($pools), $recognition, $manifest);
    echo json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
