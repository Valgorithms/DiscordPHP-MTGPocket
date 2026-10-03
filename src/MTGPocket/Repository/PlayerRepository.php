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

use MTGPocket\Models\Player;
use MTGPocket\Storage\JsonStore;

/**
 * Players, one JSON file each: `players/{discordUserId}.json`.
 *
 * @since 0.1.0
 */
class PlayerRepository
{
    public const COLLECTION = 'players';

    public function __construct(protected JsonStore $store)
    {
    }

    /**
     * @param string $id Discord user id.
     *
     * @return Player|null
     */
    public function find(string $id): ?Player
    {
        $data = $this->store->get(self::COLLECTION, $id);

        return $data === null ? null : Player::fromArray($data);
    }

    /**
     * The player, registering them on first use. Keeps their display name
     * current.
     *
     * @param string $id   Discord user id.
     * @param string $name Their display name now.
     *
     * @return Player
     */
    public function findOrCreate(string $id, string $name = ''): Player
    {
        $data = $this->store->update(self::COLLECTION, $id, function (?array $data) use ($id, $name): array {
            $player = $data === null ? new Player($id, $name) : Player::fromArray($data);
            if ($name !== '') {
                $player->name = $name;
            }

            return $player->jsonSerialize();
        });

        return Player::fromArray($data);
    }

    /**
     * Changes a player under a lock, so concurrent changes are not lost.
     *
     * @param string                 $id
     * @param callable(Player): void $change
     *
     * @throws \OutOfBoundsException When there is no such player.
     *
     * @return Player The player as saved.
     */
    public function modify(string $id, callable $change): Player
    {
        $data = $this->store->update(self::COLLECTION, $id, function (?array $data) use ($id, $change): array {
            if ($data === null) {
                throw new \OutOfBoundsException("No player {$id}.");
            }
            $player = Player::fromArray($data);
            $change($player);

            return $player->jsonSerialize();
        });

        return Player::fromArray($data);
    }

    /**
     * @param Player $player
     */
    public function save(Player $player): void
    {
        $this->store->put(self::COLLECTION, $player->id, $player->jsonSerialize());
    }

    /**
     * Every registered player id.
     *
     * @return string[]
     */
    public function ids(): array
    {
        return $this->store->ids(self::COLLECTION);
    }
}
