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

namespace MTGPocket\Packs;

/**
 * A pack a player just opened, with what it added to their collection.
 *
 * @since 0.2.0
 */
final class OpenedPack
{
    /**
     * @param Pack                $pack
     * @param array<string, bool> $new         Uuids of the cards the player did not own before.
     * @param int                 $nextPackAt  Unix time their next free pack is ready.
     */
    public function __construct(
        public readonly Pack $pack,
        public readonly array $new,
        public readonly int $nextPackAt,
    ) {
    }

    /**
     * Whether a card is new to the player.
     *
     * @param string $uuid
     *
     * @return bool
     */
    public function isNew(string $uuid): bool
    {
        return isset($this->new[$uuid]);
    }
}
