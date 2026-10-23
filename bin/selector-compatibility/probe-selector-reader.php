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


// One historical reader per isolated PHP process. No bot bootstrap or imports.
$label = $argv[1] ?? '';
if (! in_array($label, ['main', 'pr331'], true)) {
    throw new InvalidArgumentException('Choose main or pr331.');
}
$manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/manifest.json'), true, flags: JSON_THROW_ON_ERROR)[$label];
foreach ($manifest['files'] as $name => $hashes) {
    if (! hash_equals($hashes['sha256'], hash_file('sha256', dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/'.$label.'/'.$name))) {
        throw new RuntimeException('Historical source hash mismatch.');
    }
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/'.$label.'/Game.php';
require dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/'.$label.'/TextParser.php';
$fixture = dirname(__DIR__, 2).'/var/selector-compatibility/selector-version4-fixture.json';
$saved = json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
if (\MTGPocket\Game\Game::STATE_VERSION !== 3 || $saved['schemaVersion'] !== 4 ||
    ($saved['stack'][0]['effects'][0]['all'] ?? null) !== 'all artifacts, creatures, and lands') {
    throw new RuntimeException('Unexpected reader version or new-selector fixture.');
}
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
try {
    \MTGPocket\Game\Game::fromArray($saved);
} catch (\MTGPocket\Game\GameException $exception) {
    if ($exception->getMessage() !== 'Unsupported saved game schema version.') {
        throw $exception;
    }
    echo json_encode(['reader' => $label, 'readerCommit' => $manifest['commit'],
        'files' => $manifest['files'], 'fixtureSha256' => hash_file('sha256', $fixture),
        'result' => 'rejected', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
throw new RuntimeException('Historical reader accepted an incompatible saved selector.');
