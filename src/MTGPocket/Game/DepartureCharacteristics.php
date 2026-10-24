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
 * The battlefield definition, creature status, mana value and optional pre-group statistics.
 * Event filters use the definition/status; selected departures can retain the
 * statistics before earlier movements change the battlefield. This is not a
 * complete continuous-effect or replacement model.
 */
final class DepartureCharacteristics
{
    public function __construct(
        public readonly CardDefinition $definition,
        public readonly bool $isCreature,
        public readonly ?int $power = null,
        public readonly ?int $toughness = null,
        public readonly ?int $manaValue = null,
    ) {
    }
}
