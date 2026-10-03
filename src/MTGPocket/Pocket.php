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

use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\DeckRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\PlayerRepository;
use MTGPocket\Storage\JsonStore;

/**
 * The game's persistent state, all kept as JSON files under one directory:
 *
 * - `players/{userId}.json`: profile and daily pack timer
 * - `inventories/{userId}.json`: the cards a player owns
 * - `decks/{userId}.json`: a player's decks, each with a main and side deck
 * - `pools/{SET}.json`: the cards packs of a set are drawn from, by color and rarity
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

    /**
     * @param string $dataDirectory Where the JSON files are kept, e.g. `var/data`.
     */
    public function __construct(string $dataDirectory)
    {
        $this->store = new JsonStore($dataDirectory);
        $this->players = new PlayerRepository($this->store);
        $this->inventories = new InventoryRepository($this->store);
        $this->decks = new DeckRepository($this->store);
        $this->pools = new CardPoolRepository($this->store);
    }
}
