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
 * Imports card pools from MTGJSON into the game's JSON storage.
 *
 *   php bin/import-cards.php            every set packs can come from
 *   php bin/import-cards.php KTK DMU    just these sets
 *
 * Reads the AllPrintings build DiscordPHP-MTG keeps (MTGJSON_DATABASE,
 * default var/mtgjson/AllPrintings.sqlite), downloading it (~250 MB) if
 * there is none yet, and writes pools to MTGPOCKET_DATA (default var/data).
 */

use Discord\Http\Drivers\React;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use MTG\Database\Database;
use MTG\Http\Http;
use MTGPocket\Cards\CardPoolImporter;
use MTGPocket\Pocket;
use React\EventLoop\Loop;
use React\Http\Browser;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/env.php';

$baseDir = dirname(__DIR__);
loadEnv($baseDir.'/.env', false);
ini_set('memory_limit', '-1');

$loop = Loop::get();
$logger = new Logger('import', [new StreamHandler('php://stderr', getenv('LOG_LEVEL') ?: 'info')]);
$database = new Database(
    $loop,
    $logger,
    new Http('', $loop, $logger, new React($loop)),
    new Browser(null, $loop),
    getenv('MTGJSON_DATABASE') ?: $baseDir.'/var/mtgjson/'.Database::FILE,
    0,
);
$pocket = new Pocket(getenv('MTGPOCKET_DATA') ?: $baseDir.'/var/data');
$codes = array_map('strtoupper', array_slice($argv, 1));
$status = 0;

$database->ready()->then(function () use ($database, $pocket, $codes, $logger, &$status): void {
    $importer = new CardPoolImporter($database);
    $codes = $codes ?: array_keys($importer->sets());
    $logger->info(sprintf('Importing %d set(s) from MTGJSON %s', count($codes), $database->getVersion() ?? '(unknown version)'));

    foreach ($codes as $code) {
        try {
            $pool = $importer->import($code);
        } catch (\OutOfBoundsException $e) {
            $logger->error($e->getMessage());
            $status = 1;
            continue;
        }
        if ($pool->size() === 0) {
            $logger->warning("{$code}: no booster cards, skipped");
            continue;
        }
        $pocket->pools->save($pool);
        $logger->info(sprintf('%s: %d cards (%s)', $code, $pool->size(), implode(' ', array_map(fn ($c) => $c.count($pool->uuids($c)), $pool->colors()))));
    }

    $database->close();
    Loop::stop();
}, function (\Throwable $e) use ($logger, &$status): void {
    $logger->error('The MTGJSON build is not available: '.$e->getMessage());
    $status = 1;
    Loop::stop();
});

$loop->run();
exit($status);
