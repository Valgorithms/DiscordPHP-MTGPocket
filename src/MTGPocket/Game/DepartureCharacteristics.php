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

/**
 * The static definition and current creature status before a permanent
 * leaves the battlefield. This is not a complete last-known-state model.
 * Only event dispatch/filtering uses it; saved triggers contain effects.
 */
final class DepartureCharacteristics
{
    public function __construct(
        public readonly CardDefinition $definition,
        public readonly bool $isCreature,
    ) {
    }
}
