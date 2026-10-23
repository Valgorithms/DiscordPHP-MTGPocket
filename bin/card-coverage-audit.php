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

// Explicit offline input; no dotenv loading, import, HTTP clients, or downloads.
// php -d memory_limit=512M bin/card-coverage-audit.php var/data
// php -d memory_limit=512M bin/card-coverage-audit.php var/data modern path/to/AllPrintings.sqlite
use MTGPocket\Cards\CoverageAudit;

require __DIR__.'/../vendor/autoload.php';

try {
    if (! isset($argv[1]) || count($argv) > 4 || (isset($argv[2]) && ! isset($argv[3]))) {
        throw new InvalidArgumentException('Usage: card-coverage-audit.php DATA_DIRECTORY [FORMAT SQLITE_DATABASE]');
    }
    $files = glob($argv[1].'/pools/*.json');
    if ($files === false || $files === []) {
        throw new InvalidArgumentException('No imported pools in the supplied data directory.');
    }
    $legal = null;
    $database = null;
    if (isset($argv[2])) {
        if (! preg_match('/^[a-z]+$/D', $argv[2]) || ! is_file($argv[3])) {
            throw new InvalidArgumentException('Supply a lowercase MTGJSON format and an existing SQLite database.');
        }
        $flags = class_exists(Pdo\Sqlite::class)
            ? [Pdo\Sqlite::ATTR_OPEN_FLAGS => Pdo\Sqlite::OPEN_READONLY]
            : [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY];
        $pdo = new PDO('sqlite:'.$argv[3], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + $flags);
        $columns = $pdo->query('PRAGMA table_info("cardLegalities")')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (! in_array($argv[2], $columns, true)) {
            throw new InvalidArgumentException('Unknown legality format.');
        }
        $legal = $pdo->query('SELECT DISTINCT c.name FROM cards c JOIN cardLegalities l ON l.uuid = c.uuid WHERE l."'.$argv[2].'" = \'Legal\'')->fetchAll(PDO::FETCH_COLUMN);
        $database = ['sha256' => hash_file('sha256', $argv[3]), 'meta' => $pdo->query('SELECT * FROM meta')->fetchAll(PDO::FETCH_ASSOC)];
    }
    $pools = array_map(fn (string $file) => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), $files);
    $report = CoverageAudit::report($pools, $legal);
    $report['format'] = $argv[2] ?? 'all';
    $report['legalityDatabase'] = $database;
    $report['codeSha256'] = [];
    foreach (['Game/CardDefinition.php', 'Game/TextParser.php', 'Game/Game.php', 'Cards/CardPoolImporter.php', 'Cards/CoverageAudit.php'] as $file) {
        $report['codeSha256'][$file] = hash_file('sha256', __DIR__.'/../src/MTGPocket/'.$file);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
