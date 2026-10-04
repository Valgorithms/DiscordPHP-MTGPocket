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

namespace MTGPocket;

use MTGPocket\Collection\CollectionQuery;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Economy\PriceList;
use MTGPocket\Economy\Shop;
use MTGPocket\Matches\MatchService;
use MTGPocket\Packs\DailyPacks;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\DeckRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\MatchRepository;
use MTGPocket\Repository\PlayerRepository;
use MTGPocket\Repository\TradeRepository;
use MTGPocket\Storage\JsonStore;
use MTGPocket\Trades\TradeService;

/**
 * The game's persistent state, all kept as JSON files under one directory:
 *
 * - `players/{userId}.json`: profile, daily pack timer and shop points
 * - `inventories/{userId}.json`: the cards a player owns
 * - `decks/{userId}.json`: a player's decks, each with a main and side deck
 * - `pools/{SET}.json`: the cards packs of a set are drawn from, by color and rarity
 * - `matches/{id}.json`: a challenge or a game, saved after every action
 * - `live/{userId}.json`: the match a player is in
 * - `trades/{id}.json`: an open trade offer
 *
 * On top of them sit the game's services: free daily packs, collection
 * listings, the deck builder, matches, the points shop and trades.
 *
 * @since 0.1.0
 */
class Pocket
{
    public readonly JsonStore $store;
    public readonly PlayerRepository $players;
    public readonly InventoryRepository $inventories;
    public readonly DeckRepository $decks;
    public readonly CardPoolRepository $pools;
    public readonly DailyPacks $dailyPacks;
    public readonly CollectionQuery $collection;
    public readonly DeckBuilder $deckBuilder;
    public readonly MatchService $matches;
    public readonly Shop $shop;
    public readonly TradeService $trades;

    /**
     * @param string                 $dataDirectory Where the JSON files are kept, e.g. `var/data`.
     * @param PackGenerator|null     $generator     A seeded one makes packs repeatable, for tests.
     * @param (\Closure(): int)|null $clock         The current Unix time; defaults to {@see time()}.
     * @param (\Closure(): string)|null $random     Random hex for match and trade ids and game seeds, for tests.
     * @param PriceList|null         $prices        The shop's prices; defaults to `config/economy.php`.
     */
    public function __construct(string $dataDirectory, ?PackGenerator $generator = null, ?\Closure $clock = null, ?\Closure $random = null, ?PriceList $prices = null)
    {
        $generator ??= new PackGenerator();
        $this->store = new JsonStore($dataDirectory);
        $this->players = new PlayerRepository($this->store);
        $this->inventories = new InventoryRepository($this->store);
        $this->decks = new DeckRepository($this->store);
        $this->pools = new CardPoolRepository($this->store);
        $this->dailyPacks = new DailyPacks($this->players, $this->inventories, $this->pools, $generator, $clock);
        $this->collection = new CollectionQuery($this->pools);
        $this->deckBuilder = new DeckBuilder($this->decks, $this->inventories, $this->players, $this->pools);
        $this->matches = new MatchService(new MatchRepository($this->store), $this->deckBuilder, $this->inventories, $random);
        $this->shop = new Shop($this->players, $this->inventories, $this->pools, $this->deckBuilder, $this->dailyPacks, $generator, $prices ?? PriceList::fromFile(), $clock);
        $this->trades = new TradeService(new TradeRepository($this->store), $this->players, $this->inventories, $this->pools, $this->deckBuilder, $clock, $random);
    }
}
