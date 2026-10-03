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
 * Basic lands. Packs never hold them (card pools leave them out), so every
 * player may put as many as they like in a deck without owning any.
 *
 * In a deck they are kept under a key of their own, `basic:{Name}`, instead
 * of a printing uuid.
 *
 * @since 0.2.0
 */
final class BasicLands
{
    public const string PREFIX = 'basic:';

    /**
     * Names, with the color each makes.
     *
     * @var array<string, string>
     */
    public const array NAMES = [
        'Plains' => 'W',
        'Island' => 'U',
        'Swamp' => 'B',
        'Mountain' => 'R',
        'Forest' => 'G',
        'Wastes' => 'C',
    ];

    /**
     * The deck key of a basic land.
     *
     * @param string $name Any case.
     *
     * @return string|null Null when it is not a basic land.
     */
    public static function key(string $name): ?string
    {
        foreach (array_keys(self::NAMES) as $basic) {
            if (strcasecmp($basic, trim($name)) === 0 || strcasecmp(self::PREFIX.$basic, trim($name)) === 0) {
                return self::PREFIX.$basic;
            }
        }

        return null;
    }

    /**
     * Whether a deck key is a basic land.
     *
     * @param string $key
     *
     * @return bool
     */
    public static function isBasic(string $key): bool
    {
        return str_starts_with($key, self::PREFIX) && isset(self::NAMES[substr($key, strlen(self::PREFIX))]);
    }

    /**
     * A basic land's card data, shaped like a pool card.
     *
     * @param string $key
     *
     * @return array|null
     */
    public static function card(string $key): ?array
    {
        if (! self::isBasic($key)) {
            return null;
        }
        $name = substr($key, strlen(self::PREFIX));

        return [
            'uuid' => $key,
            'name' => $name,
            'rarity' => 'common',
            'colors' => [],
            'manaValue' => 0.0,
            'type' => $name === 'Wastes' ? 'Basic Land' : "Basic Land — {$name}",
            'scryfallId' => null,
        ];
    }
}
