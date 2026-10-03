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
 * The steps of a turn, with each main phase as one step (rule 500.1).
 *
 * @since 0.3.0
 */
enum Step: string
{
    case Untap = 'untap';
    case Upkeep = 'upkeep';
    case Draw = 'draw';
    case PrecombatMain = 'main1';
    case BeginCombat = 'combat';
    case DeclareAttackers = 'attackers';
    case DeclareBlockers = 'blockers';
    case FirstStrikeDamage = 'first_strike';
    case CombatDamage = 'damage';
    case EndCombat = 'end_combat';
    case PostcombatMain = 'main2';
    case End = 'end';
    case Cleanup = 'cleanup';

    public function label(): string
    {
        return match ($this) {
            self::Untap => 'Untap step',
            self::Upkeep => 'Upkeep',
            self::Draw => 'Draw step',
            self::PrecombatMain => 'Main phase 1',
            self::BeginCombat => 'Beginning of combat',
            self::DeclareAttackers => 'Declare attackers',
            self::DeclareBlockers => 'Declare blockers',
            self::FirstStrikeDamage => 'First-strike damage',
            self::CombatDamage => 'Combat damage',
            self::EndCombat => 'End of combat',
            self::PostcombatMain => 'Main phase 2',
            self::End => 'End step',
            self::Cleanup => 'Cleanup',
        };
    }

    public function isMain(): bool
    {
        return $this === self::PrecombatMain || $this === self::PostcombatMain;
    }

    public function next(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $cases[$index + 1] ?? null;
    }
}
