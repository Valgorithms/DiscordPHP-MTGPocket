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

namespace MTGPocket\Cards;

/**
 * A card's color identity (rule 903.4): its colors and the colors of the
 * mana symbols in its cost and rules text. MTGJSON's `colorIdentity` is
 * used when a card has it; older pools without it fall back to reading the
 * cost and text.
 *
 * @since 0.4.0
 */
final class ColorIdentity
{
    public const array ORDER = ['W', 'U', 'B', 'R', 'G'];

    /**
     * @param array $card Card data.
     *
     * @return string[] Color letters in WUBRG order.
     */
    public static function of(array $card): array
    {
        if (isset($card['colorIdentity'])) {
            $colors = (array) $card['colorIdentity'];
        } elseif (isset(BasicLands::NAMES[$card['name'] ?? ''])) {
            $colors = [BasicLands::NAMES[$card['name']]];
        } else {
            $colors = (array) ($card['colors'] ?? []);
            preg_match_all('/\{([^}]+)\}/', ((string) ($card['manaCost'] ?? '')).' '.((string) ($card['text'] ?? '')), $symbols);
            foreach ($symbols[1] as $symbol) {
                foreach (str_split(strtoupper($symbol)) as $letter) {
                    $colors[] = $letter;
                }
            }
        }

        return array_values(array_intersect(self::ORDER, $colors));
    }

    /**
     * Color letters as names, e.g. `white and blue`, or `colorless`.
     *
     * @param string[] $colors
     *
     * @return string
     */
    public static function describe(array $colors): string
    {
        $names = ['W' => 'white', 'U' => 'blue', 'B' => 'black', 'R' => 'red', 'G' => 'green'];
        $words = array_map(fn (string $color) => $names[$color], array_values(array_intersect(self::ORDER, $colors)));

        return match (count($words)) {
            0 => 'colorless',
            1 => $words[0],
            default => implode(', ', array_slice($words, 0, -1)).' and '.end($words),
        };
    }
}
