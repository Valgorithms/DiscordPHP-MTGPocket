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

use Discord\Parts\User\Activity;
use Discord\WebSockets\Intents;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use MTG\MTG;
use MTG\Modules\About;
use MTG\Modules\Cards;
use MTG\Modules\Help;
use MTGPocket\Modules\Collection;
use MTGPocket\Modules\Matches;
use MTGPocket\Modules\Packs;
use MTGPocket\Modules\PlayerDecks;
use MTGPocket\Modules\Shop;
use MTGPocket\Modules\Trades;
use MTGPocket\Economy\PriceList;
use MTGPocket\Pocket;

use function React\Promise\set_rejection_handler;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/src/env.php';

set_time_limit(0);
ini_set('memory_limit', '-1');

loadEnv(__DIR__.'/.env');

try {
    $level = Level::fromName(getenv('LOG_LEVEL') ?: 'info');
} catch (\UnhandledMatchError) {
    $level = Level::Info;
}
$handler = new StreamHandler('php://stdout', $level);
$handler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('MTGPOCKET', [$handler]);
set_rejection_handler(fn (\Throwable $e) => $logger->warning("Unhandled promise rejection: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}]"));

// Players, collections, decks and card pools, as JSON files; shop prices
// from config/economy.php (or MTGPOCKET_ECONOMY).
$pocket = new Pocket(
    getenv('MTGPOCKET_DATA') ?: __DIR__.'/var/data',
    prices: PriceList::fromFile(getenv('MTGPOCKET_ECONOMY') ?: PriceList::DEFAULT_FILE),
);

$mtg = new MTG([
    'logger' => $logger,
    'token' => getenv('TOKEN'),
    'intents' => Intents::getDefaultIntents(),
    'disableVoiceClient' => true,
    'mtgjson' => [
        'database' => getenv('MTGJSON_DATABASE') ?: __DIR__.'/var/mtgjson/AllPrintings.sqlite',
        'prices' => in_array($prices = getenv('MTG_PRICES'), [false, ''], true) || filter_var($prices, FILTER_VALIDATE_BOOLEAN),
    ],
]);

// Card lookups come from DiscordPHP-MTG; daily packs, collections, decks,
// matches, the shop and trades are the game's own.
$mtg
    ->addModule(new Packs($pocket))
    ->addModule(new Collection($pocket))
    ->addModule(new PlayerDecks($pocket))
    ->addModule(new Matches($pocket))
    ->addModule(new Shop($pocket))
    ->addModule(new Trades($pocket))
    ->addModule(new Cards())
    ->addModule(new Help())
    ->addModule(new About());

$mtg->once('init', function (MTG $mtg) use ($pocket, $logger): void {
    $pools = $pocket->pools->setCodes();
    if ($pools === []) {
        $logger->warning('No card pools imported yet; run `composer import-cards`.');
    } else {
        $logger->info(sprintf('%d card pools loaded from %s', count($pools), $pocket->store->getDirectory()));
    }
    $mtg->updatePresence(new Activity($mtg, ['name' => 'Magic: The Gathering', 'type' => Activity::TYPE_PLAYING]));
});

$mtg->run();
