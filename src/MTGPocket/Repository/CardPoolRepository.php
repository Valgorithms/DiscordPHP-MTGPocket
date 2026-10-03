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

use MTGPocket\Cards\CardPool;
use MTGPocket\Storage\JsonStore;

/**
 * Imported set pools, one JSON file each: `pools/{SETCODE}.json`.
 *
 * @since 0.1.0
 */
class CardPoolRepository
{
    public const COLLECTION = 'pools';

    /**
     * Pools read this run, by set code.
     *
     * @var array<string, CardPool>
     */
    protected array $cache = [];

    public function __construct(protected JsonStore $store)
    {
    }

    /**
     * @param string $setCode
     *
     * @return CardPool|null Null when the set has not been imported.
     */
    public function find(string $setCode): ?CardPool
    {
        $setCode = strtoupper($setCode);
        if (isset($this->cache[$setCode])) {
            return $this->cache[$setCode];
        }

        $data = $this->store->get(self::COLLECTION, $setCode);

        return $data === null ? null : $this->cache[$setCode] = CardPool::fromArray($data);
    }

    /**
     * @param CardPool $pool
     */
    public function save(CardPool $pool): void
    {
        $this->store->put(self::COLLECTION, strtoupper($pool->setCode), $pool->jsonSerialize());
        $this->cache[strtoupper($pool->setCode)] = $pool;
    }

    /**
     * The codes of every imported set.
     *
     * @return string[]
     */
    public function setCodes(): array
    {
        return $this->store->ids(self::COLLECTION);
    }
}
