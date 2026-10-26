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

namespace MTGPocket\Game;

/** Facts needed by creature-death observers, captured before zone reset. */
final class CreatureDeath
{
    public function __construct(
        public readonly int $id,
        public readonly int $incarnation,
        public readonly int $controller,
        public readonly DepartureCharacteristics $characteristics,
        public readonly int $power,
        public readonly bool $hadPlusCounter,
    ) {
    }
}
