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
 * Thrown when a player has already opened today's free pack.
 *
 * @since 0.2.0
 */
class DailyPackUnavailableException extends \RuntimeException
{
    /**
     * @param int $availableAt Unix time the next free pack can be opened.
     */
    public function __construct(public readonly int $availableAt)
    {
        parent::__construct('You have already opened today\'s free pack. The next one is ready <t:'.$availableAt.':R>.');
    }
}
