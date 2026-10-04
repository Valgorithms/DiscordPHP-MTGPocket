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

namespace MTGPocket\Trades;

/**
 * Thrown when a player accepts an offer that has changed since they saw it.
 * It carries the offer as it is now, to show them.
 *
 * @since 0.4.0
 */
class StaleOfferException extends \InvalidArgumentException
{
    public function __construct(public readonly TradeOffer $offer)
    {
        parent::__construct('This offer changed after you saw it. Here it is now; accept again if you still want it.');
    }
}
