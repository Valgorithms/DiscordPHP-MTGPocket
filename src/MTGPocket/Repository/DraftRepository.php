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

use MTGPocket\Drafts\Draft;
use MTGPocket\Storage\JsonStore;

/**
 * Draft events, one file each under `drafts/`, and the draft each player
 * last joined, under `drafters/{playerId}.json`.
 *
 * @since 0.5.0
 */
class DraftRepository
{
    public const COLLECTION = 'drafts';
    public const ENTRIES = 'drafters';

    public function __construct(protected JsonStore $store)
    {
    }

    public function find(string $id): ?Draft
    {
        if (! preg_match('/^[a-f0-9]{1,32}$/', $id)) {
            return null;
        }
        $data = $this->store->get(self::COLLECTION, $id);

        return $data === null ? null : Draft::fromArray($data);
    }

    public function save(Draft $draft): void
    {
        $this->store->put(self::COLLECTION, $draft->id, $draft->toArray());
    }

    /**
     * Changes a draft under a lock. When `$change` throws, nothing is saved.
     *
     * @param string                 $id
     * @param callable(Draft): void  $change
     *
     * @throws \OutOfBoundsException When there is no such draft.
     *
     * @return Draft As saved.
     */
    public function modify(string $id, callable $change): Draft
    {
        if ($this->find($id) === null) {
            throw new \OutOfBoundsException('That draft no longer exists.');
        }
        $saved = null;
        $this->store->update(self::COLLECTION, $id, function (?array $data) use ($change, &$saved): array {
            $draft = Draft::fromArray((array) $data);
            $change($draft);
            $saved = $draft;

            return $draft->toArray();
        });

        return $saved;
    }

    /**
     * Every draft's id.
     *
     * @return string[]
     */
    public function ids(): array
    {
        return $this->store->ids(self::COLLECTION);
    }

    /**
     * The id of the draft a player last joined.
     *
     * @param string $playerId
     *
     * @return string|null
     */
    public function entry(string $playerId): ?string
    {
        return $this->store->get(self::ENTRIES, $playerId)['draft'] ?? null;
    }

    /**
     * Records the draft a player joined, or that they left it.
     *
     * @param string      $playerId
     * @param string|null $draftId
     *
     * @return void
     */
    public function setEntry(string $playerId, ?string $draftId): void
    {
        if ($draftId === null) {
            $this->store->delete(self::ENTRIES, $playerId);
        } else {
            $this->store->put(self::ENTRIES, $playerId, ['draft' => $draftId]);
        }
    }
}
