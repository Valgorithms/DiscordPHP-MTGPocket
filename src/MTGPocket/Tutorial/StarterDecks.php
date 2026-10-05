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

namespace MTGPocket\Tutorial;

use MTGPocket\Cards\BasicLands;
use MTGPocket\Game\Mana\ManaCost;

/**
 * The two fixed 40-card decks of a practice game: plain creatures, a few
 * simple spells and the keywords the tutorial explains (haste, reach,
 * flying, vigilance, deathtouch, trample). Their card data is kept here,
 * so practice games work before any set has been imported and need no
 * cards from anyone's collection.
 *
 * @since 0.7.0
 */
final class StarterDecks
{
    /** The new player's deck. */
    public const string PLAYER_DECK = 'Red-Green Starter';

    /** The bot's deck. */
    public const string BOT_DECK = 'White-Black Starter';

    /**
     * The cards both decks use, shaped like pool cards.
     *
     * @var array<string, array>
     */
    public const array CARDS = [
        // Red and green.
        'Raging Goblin' => ['manaCost' => '{R}', 'type' => 'Creature — Goblin Berserker', 'power' => '1', 'toughness' => '1', 'text' => 'Haste', 'colors' => ['R']],
        'Grizzly Bears' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Bear', 'power' => '2', 'toughness' => '2', 'text' => null, 'colors' => ['G']],
        'Gray Ogre' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Ogre', 'power' => '2', 'toughness' => '2', 'text' => null, 'colors' => ['R']],
        'Centaur Courser' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Centaur Warrior', 'power' => '3', 'toughness' => '3', 'text' => null, 'colors' => ['G']],
        'Hill Giant' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Giant', 'power' => '3', 'toughness' => '3', 'text' => null, 'colors' => ['R']],
        'Giant Spider' => ['manaCost' => '{3}{G}', 'type' => 'Creature — Spider', 'power' => '2', 'toughness' => '4', 'text' => 'Reach', 'colors' => ['G']],
        'Craw Wurm' => ['manaCost' => '{4}{G}{G}', 'type' => 'Creature — Wurm', 'power' => '6', 'toughness' => '4', 'text' => null, 'colors' => ['G']],
        'Colossal Dreadmaw' => ['manaCost' => '{4}{G}{G}', 'type' => 'Creature — Dinosaur', 'power' => '6', 'toughness' => '6', 'text' => 'Trample', 'colors' => ['G']],
        'Shock' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => 'Shock deals 2 damage to any target.', 'colors' => ['R']],
        'Giant Growth' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Target creature gets +3/+3 until end of turn.', 'colors' => ['G']],
        // White and black.
        'Savannah Lions' => ['manaCost' => '{W}', 'type' => 'Creature — Cat', 'power' => '2', 'toughness' => '1', 'text' => null, 'colors' => ['W']],
        'Glory Seeker' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'power' => '2', 'toughness' => '2', 'text' => null, 'colors' => ['W']],
        'Walking Corpse' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie', 'power' => '2', 'toughness' => '2', 'text' => null, 'colors' => ['B']],
        'Typhoid Rats' => ['manaCost' => '{B}', 'type' => 'Creature — Rat', 'power' => '1', 'toughness' => '1', 'text' => 'Deathtouch (Any amount of damage this deals to a creature is enough to destroy it.)', 'colors' => ['B']],
        'Pillarfield Ox' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Ox', 'power' => '2', 'toughness' => '4', 'text' => null, 'colors' => ['W']],
        'Siege Mastodon' => ['manaCost' => '{4}{W}', 'type' => 'Creature — Elephant', 'power' => '3', 'toughness' => '5', 'text' => null, 'colors' => ['W']],
        'Serra Angel' => ['manaCost' => '{3}{W}{W}', 'type' => 'Creature — Angel', 'power' => '4', 'toughness' => '4', 'text' => 'Flying, vigilance', 'colors' => ['W']],
        'Bogstomper' => ['manaCost' => '{4}{B}{B}', 'type' => 'Creature — Beast', 'power' => '6', 'toughness' => '5', 'text' => null, 'colors' => ['B']],
        'Murder' => ['manaCost' => '{1}{B}{B}', 'type' => 'Instant', 'text' => 'Destroy target creature.', 'colors' => ['B']],
    ];

    /**
     * The new player's deck: name => copies.
     *
     * @var array<string, int>
     */
    public const array PLAYER = [
        'Forest' => 8, 'Mountain' => 8,
        'Raging Goblin' => 2, 'Grizzly Bears' => 4, 'Gray Ogre' => 2, 'Centaur Courser' => 3, 'Hill Giant' => 3,
        'Giant Spider' => 2, 'Craw Wurm' => 2, 'Colossal Dreadmaw' => 1, 'Shock' => 3, 'Giant Growth' => 2,
    ];

    /**
     * The bot's deck: name => copies.
     *
     * @var array<string, int>
     */
    public const array BOT = [
        'Plains' => 8, 'Swamp' => 8,
        'Savannah Lions' => 4, 'Glory Seeker' => 4, 'Walking Corpse' => 4, 'Typhoid Rats' => 2, 'Pillarfield Ox' => 3,
        'Siege Mastodon' => 2, 'Serra Angel' => 2, 'Bogstomper' => 2, 'Murder' => 1,
    ];

    /**
     * A deck as card data, one entry per copy.
     *
     * @param array<string, int> $list Name => copies.
     *
     * @return array[]
     */
    public static function cards(array $list): array
    {
        $cards = [];
        foreach ($list as $name => $count) {
            array_push($cards, ...array_fill(0, $count, self::card((string) $name)));
        }

        return $cards;
    }

    /**
     * One card's data.
     *
     * @param string $name
     *
     * @throws \OutOfBoundsException When neither deck has the card.
     *
     * @return array
     */
    public static function card(string $name): array
    {
        if (($key = BasicLands::key($name)) !== null) {
            return BasicLands::card($key);
        }
        if (! isset(self::CARDS[$name])) {
            throw new \OutOfBoundsException("The starter decks have no {$name}.");
        }
        $card = self::CARDS[$name];

        return [
            'uuid' => 'starter:'.strtolower((string) preg_replace('/\W+/', '-', $name)),
            'name' => $name,
            'rarity' => 'common',
            'manaValue' => (float) ManaCost::parse($card['manaCost'])->manaValue(),
            'scryfallId' => null,
        ] + $card;
    }
}
