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

use MTGPocket\Matches\MatchRecord;
use MTGPocket\Storage\JsonStore;

/**
 * Matches, one file each under `matches/`, and which live match each
 * player is in, under `live/{playerId}.json`.
 *
 * @since 0.3.0
 */
class MatchRepository
{
    public const COLLECTION = 'matches';
    public const LIVE = 'live';

    public function __construct(protected JsonStore $store)
    {
    }

    public function find(string $id): ?MatchRecord
    {
        if (! preg_match('/^[a-f0-9]{1,32}$/', $id)) {
            return null;
        }
        $data = $this->store->get(self::COLLECTION, $id);

        return $data === null ? null : MatchRecord::fromArray($data);
    }

    public function save(MatchRecord $match): void
    {
        $this->store->put(self::COLLECTION, $match->id, $match->toArray());
    }

    /**
     * Changes a match under a lock. When `$change` throws, nothing is saved.
     *
     * @param string                       $id
     * @param callable(MatchRecord): void  $change
     *
     * @throws \OutOfBoundsException When there is no such match.
     *
     * @return MatchRecord As saved.
     */
    public function modify(string $id, callable $change): MatchRecord
    {
        if ($this->find($id) === null) {
            throw new \OutOfBoundsException('That match no longer exists.');
        }
        $saved = null;
        $this->store->update(self::COLLECTION, $id, function (?array $data) use ($change, &$saved): array {
            $match = MatchRecord::fromArray((array) $data);
            $change($match);
            $match->updatedAt = time();
            $saved = $match;

            return $match->toArray();
        });

        return $saved;
    }

    /**
     * The id of the live match a player is in.
     *
     * @param string $playerId
     *
     * @return string|null
     */
    public function liveMatchId(string $playerId): ?string
    {
        return $this->store->get(self::LIVE, $playerId)['match'] ?? null;
    }

    /**
     * Records a player's live match, or that they have none.
     *
     * @param string      $playerId
     * @param string|null $matchId
     *
     * @return void
     */
    public function setLive(string $playerId, ?string $matchId): void
    {
        if ($matchId === null) {
            $this->store->delete(self::LIVE, $playerId);
        } else {
            $this->store->put(self::LIVE, $playerId, ['match' => $matchId]);
        }
    }
}
