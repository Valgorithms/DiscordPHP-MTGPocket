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

    /**
     * Set code by card uuid, across every pool; built on first use.
     *
     * @var array<string, string>|null
     */
    protected ?array $cardIndex = null;

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
        $this->cardIndex = null;
    }

    /**
     * Every imported pool, by set code, oldest set first.
     *
     * @return array<string, CardPool>
     */
    public function all(): array
    {
        $pools = [];
        foreach ($this->setCodes() as $setCode) {
            if ($pool = $this->find($setCode)) {
                $pools[$pool->setCode] = $pool;
            }
        }
        uasort($pools, fn (CardPool $a, CardPool $b) => [$a->releaseDate ?? '', $a->setCode] <=> [$b->releaseDate ?? '', $b->setCode]);

        return $pools;
    }

    /**
     * A card from any pool, with its `setCode` and `setName` added.
     *
     * @param string $uuid
     *
     * @return array|null Null when no imported pool has it.
     */
    public function card(string $uuid): ?array
    {
        if ($this->cardIndex === null) {
            $this->cardIndex = [];
            foreach ($this->all() as $setCode => $pool) {
                foreach (CardPool::COLORS as $color) {
                    foreach ($pool->uuids($color) as $cardUuid) {
                        $this->cardIndex[$cardUuid] ??= $setCode;
                    }
                }
            }
        }

        $setCode = $this->cardIndex[$uuid] ?? null;
        $pool = $setCode === null ? null : $this->find($setCode);
        $card = $pool?->card($uuid);

        return $card === null ? null : $card + ['setCode' => $pool->setCode, 'setName' => $pool->setName];
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
