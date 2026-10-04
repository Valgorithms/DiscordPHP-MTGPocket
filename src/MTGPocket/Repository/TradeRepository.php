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

use MTGPocket\Storage\JsonStore;
use MTGPocket\Trades\TradeOffer;

/**
 * Open trade offers, one JSON file each: `trades/{id}.json`. An offer's file
 * is deleted once it is accepted, declined, cancelled or expired, so the
 * collection only ever holds open offers.
 *
 * @since 0.4.0
 */
class TradeRepository
{
    public const COLLECTION = 'trades';

    public function __construct(protected JsonStore $store)
    {
    }

    /**
     * @param string $id
     *
     * @return TradeOffer|null Null when there is no such open offer.
     */
    public function find(string $id): ?TradeOffer
    {
        if (! preg_match('/^[a-f0-9]{1,32}$/', $id)) {
            return null;
        }
        $data = $this->store->get(self::COLLECTION, $id);

        return $data === null ? null : TradeOffer::fromArray($data);
    }

    /**
     * @param TradeOffer $offer
     */
    public function save(TradeOffer $offer): void
    {
        $this->store->put(self::COLLECTION, $offer->id, $offer->toArray());
    }

    /**
     * Changes an offer under its lock. When `$change` throws, nothing is
     * saved; when it leaves the offer no longer open, the file is deleted.
     *
     * @param string                     $id
     * @param callable(TradeOffer): void $change
     *
     * @throws \OutOfBoundsException When there is no such open offer.
     *
     * @return TradeOffer The offer as it ended up.
     */
    public function modify(string $id, callable $change): TradeOffer
    {
        if ($this->find($id) === null) {
            throw new \OutOfBoundsException('That trade offer is no longer open.');
        }

        $saved = null;
        $this->store->update(self::COLLECTION, $id, function (?array $data) use ($change, &$saved): ?array {
            if ($data === null) {
                throw new \OutOfBoundsException('That trade offer is no longer open.');
            }
            $offer = TradeOffer::fromArray($data);
            $change($offer);
            $saved = $offer;

            return $offer->status === TradeOffer::OPEN ? $offer->toArray() : null;
        });

        return $saved;
    }

    /**
     * Every open offer.
     *
     * @return TradeOffer[] By id.
     */
    public function all(): array
    {
        $offers = [];
        foreach ($this->store->ids(self::COLLECTION) as $id) {
            if ($offer = $this->find($id)) {
                $offers[$id] = $offer;
            }
        }

        return $offers;
    }
}
