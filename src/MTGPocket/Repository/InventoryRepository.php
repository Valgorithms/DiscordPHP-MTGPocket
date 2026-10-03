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

namespace MTGPocket\Repository;

use MTGPocket\Models\Inventory;
use MTGPocket\Storage\JsonStore;

/**
 * Player collections, one JSON file each: `inventories/{discordUserId}.json`.
 *
 * @since 0.1.0
 */
class InventoryRepository
{
    public const COLLECTION = 'inventories';

    public function __construct(protected JsonStore $store)
    {
    }

    /**
     * A player's inventory; empty when they own nothing yet.
     *
     * @param string $playerId
     *
     * @return Inventory
     */
    public function get(string $playerId): Inventory
    {
        $data = $this->store->get(self::COLLECTION, $playerId);

        return $data === null ? new Inventory($playerId) : Inventory::fromArray($data);
    }

    /**
     * Changes an inventory under a lock, so a pack opened while a trade
     * settles loses neither.
     *
     * @param string                    $playerId
     * @param callable(Inventory): void $change
     *
     * @return Inventory The inventory as saved.
     */
    public function modify(string $playerId, callable $change): Inventory
    {
        $data = $this->store->update(self::COLLECTION, $playerId, function (?array $data) use ($playerId, $change): array {
            $inventory = $data === null ? new Inventory($playerId) : Inventory::fromArray($data);
            $change($inventory);

            return json_decode(json_encode($inventory, JSON_THROW_ON_ERROR), true);
        });

        return Inventory::fromArray($data);
    }

    /**
     * Adds cards to a player's collection.
     *
     * @param string             $playerId
     * @param array<string, int> $cards    Printing uuid => copies.
     *
     * @return Inventory
     */
    public function addCards(string $playerId, array $cards): Inventory
    {
        return $this->modify($playerId, function (Inventory $inventory) use ($cards): void {
            foreach ($cards as $uuid => $count) {
                $inventory->cards->add((string) $uuid, $count);
            }
        });
    }

    /**
     * @param Inventory $inventory
     */
    public function save(Inventory $inventory): void
    {
        $this->store->put(self::COLLECTION, $inventory->playerId, json_decode(json_encode($inventory, JSON_THROW_ON_ERROR), true));
    }
}
