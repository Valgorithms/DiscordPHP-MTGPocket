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

use MTGPocket\Rentals\RentalDeck;
use MTGPocket\Storage\JsonStore;

/**
 * Rental decks, one JSON file each: `rentals/{id}.json`, written by
 * `composer import-cards`.
 *
 * @since 0.4.0
 */
class RentalRepository
{
    public const COLLECTION = 'rentals';

    /** @var array<string, RentalDeck> Loaded decks, by id. */
    private array $cache = [];

    public function __construct(protected JsonStore $store)
    {
    }

    public function find(string $id): ?RentalDeck
    {
        if (! preg_match(JsonStore::NAME_PATTERN, $id)) {
            return null;
        }
        if (! isset($this->cache[$id])) {
            $data = $this->store->get(self::COLLECTION, $id);
            if ($data === null) {
                return null;
            }
            $this->cache[$id] = RentalDeck::fromArray($data);
        }

        return $this->cache[$id];
    }

    public function save(RentalDeck $deck): void
    {
        $this->store->put(self::COLLECTION, $deck->id, $deck->toArray());
        $this->cache[$deck->id] = $deck;
    }

    /**
     * Every rental deck, newest first.
     *
     * @return RentalDeck[]
     */
    public function all(): array
    {
        $decks = array_values(array_filter(array_map(fn (string $id) => $this->find($id), $this->store->ids(self::COLLECTION))));
        usort($decks, fn (RentalDeck $a, RentalDeck $b) => [$b->releaseDate ?? '', $a->name] <=> [$a->releaseDate ?? '', $b->name]);

        return $decks;
    }
}
