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
 * Card data for tokens the rules create, in the shape {@see CardDefinition}
 * reads. Predefined artifact tokens (Food, Clue, Treasure, …) stay with the
 * parser; see {@see TextParser::artifactToken()}.
 *
 * @since 0.8.0
 */
final class Tokens
{
    /**
     * A creature token named after its subtypes, such as a 1/1 red Goblin.
     *
     * @param string[] $subtypes  In printed order, e.g. `['Phyrexian', 'Germ']`.
     * @param string[] $colors    Color letters; empty for colorless.
     * @param string   $power
     * @param string   $toughness
     * @param bool     $artifact  Whether it is an artifact creature.
     * @param string   $text      Rules text, usually its keywords.
     *
     * @return array<string, mixed>
     */
    public static function creature(array $subtypes, array $colors, string $power, string $toughness, bool $artifact = false, string $text = ''): array
    {
        $name = implode(' ', $subtypes);

        return [
            'name' => "{$name} Token",
            'type' => 'Token '.($artifact ? 'Artifact ' : '')."Creature — {$name}",
            'types' => $artifact ? ['Artifact', 'Creature'] : ['Creature'],
            'subtypes' => $subtypes,
            'colors' => $colors,
            'power' => $power,
            'toughness' => $toughness,
            'text' => $text,
            'manaCost' => null,
        ];
    }
}
