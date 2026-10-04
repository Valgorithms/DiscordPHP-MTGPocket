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

namespace MTGPocket\Tests\Game;

use MTGPocket\Cards\BasicLands;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use PHPUnit\Framework\TestCase;

/**
 * Real cards, shaped the way the card pool import stores them, and a game
 * set up past the mulligan with chosen cards in play.
 */
abstract class GameTestCase extends TestCase
{
    /**
     * @var array<string, array>
     */
    protected const array CARDS = [
        'Grizzly Bears' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Bear', 'power' => '2', 'toughness' => '2', 'text' => null, 'colors' => ['G']],
        'Llanowar Elves' => ['manaCost' => '{G}', 'type' => 'Creature — Elf Druid', 'power' => '1', 'toughness' => '1', 'text' => '{T}: Add {G}.', 'colors' => ['G']],
        'Hill Giant' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Giant', 'power' => '3', 'toughness' => '3', 'text' => null, 'colors' => ['R']],
        'Serra Angel' => ['manaCost' => '{3}{W}{W}', 'type' => 'Creature — Angel', 'power' => '4', 'toughness' => '4', 'text' => 'Flying, vigilance', 'colors' => ['W']],
        'Giant Spider' => ['manaCost' => '{3}{G}', 'type' => 'Creature — Spider', 'power' => '2', 'toughness' => '4', 'text' => 'Reach', 'colors' => ['G']],
        'Typhoid Rats' => ['manaCost' => '{B}', 'type' => 'Creature — Rat', 'power' => '1', 'toughness' => '1', 'text' => 'Deathtouch (Any amount of damage this deals to a creature is enough to destroy it.)', 'colors' => ['B']],
        'Raging Goblin' => ['manaCost' => '{R}', 'type' => 'Creature — Goblin Berserker', 'power' => '1', 'toughness' => '1', 'text' => 'Haste', 'colors' => ['R']],
        'White Knight' => ['manaCost' => '{W}{W}', 'type' => 'Creature — Human Knight', 'power' => '2', 'toughness' => '2', 'text' => "First strike\nProtection from black", 'colors' => ['W']],
        'Craw Wurm' => ['manaCost' => '{4}{G}{G}', 'type' => 'Creature — Wurm', 'power' => '6', 'toughness' => '4', 'text' => null, 'colors' => ['G']],
        'Colossal Dreadmaw' => ['manaCost' => '{4}{G}{G}', 'type' => 'Creature — Dinosaur', 'power' => '6', 'toughness' => '6', 'text' => 'Trample', 'colors' => ['G']],
        'Vampire Nighthawk' => ['manaCost' => '{1}{B}{B}', 'type' => 'Creature — Vampire Shaman', 'power' => '2', 'toughness' => '3', 'text' => "Flying\nDeathtouch\nLifelink", 'colors' => ['B']],
        'Goblin Heelcutter' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Goblin Warrior', 'power' => '3', 'toughness' => '2', 'text' => 'Menace', 'colors' => ['R']],
        'Wall of Stone' => ['manaCost' => '{1}{R}{R}', 'type' => 'Creature — Wall', 'power' => '0', 'toughness' => '8', 'text' => 'Defender', 'colors' => ['R']],
        'Fencing Ace' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '1', 'text' => 'Double strike', 'colors' => ['W']],
        'Isamaru, Hound of Konda' => ['manaCost' => '{W}', 'type' => 'Legendary Creature — Dog', 'supertypes' => ['Legendary'], 'types' => ['Creature'], 'subtypes' => ['Dog'], 'power' => '2', 'toughness' => '2', 'text' => null, 'colors' => ['W']],
        'Lightning Bolt' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => 'Lightning Bolt deals 3 damage to any target.', 'colors' => ['R']],
        'Shock' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => 'Shock deals 2 damage to any target.', 'colors' => ['R']],
        'Giant Growth' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Target creature gets +3/+3 until end of turn.', 'colors' => ['G']],
        'Counterspell' => ['manaCost' => '{U}{U}', 'type' => 'Instant', 'text' => 'Counter target spell.', 'colors' => ['U']],
        'Divination' => ['manaCost' => '{2}{U}', 'type' => 'Sorcery', 'text' => 'Draw two cards.', 'colors' => ['U']],
        'Murder' => ['manaCost' => '{1}{B}{B}', 'type' => 'Instant', 'text' => 'Destroy target creature.', 'colors' => ['B']],
        'Blaze' => ['manaCost' => '{X}{R}', 'type' => 'Sorcery', 'text' => 'Blaze deals X damage to any target.', 'colors' => ['R']],
        'Unsummon' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Return target creature to its owner\'s hand.', 'colors' => ['U']],
        'Rancor Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nEnchanted creature gets +2/+0 and has trample.", 'colors' => ['G']],
        'Selesnya Guildgate' => ['manaCost' => null, 'type' => 'Land — Gate', 'text' => "This land enters tapped.\n{T}: Add {G} or {W}.", 'colors' => []],
        'Ornithopter' => ['manaCost' => '{0}', 'type' => 'Artifact Creature — Thopter', 'power' => '0', 'toughness' => '2', 'text' => 'Flying', 'colors' => []],
        'Gitaxian Probe' => ['manaCost' => '{U/P}', 'type' => 'Sorcery', 'text' => 'Look at target player\'s hand.', 'colors' => ['U']],
        'Ancestral Vision' => ['manaCost' => null, 'type' => 'Sorcery', 'text' => 'Suspend 4—{U}', 'colors' => ['U']],
        // Abilities.
        'Elvish Visionary' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf Shaman', 'power' => '1', 'toughness' => '1', 'text' => 'When this creature enters, draw a card.', 'colors' => ['G']],
        'Flametongue Kavu' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Kavu', 'power' => '4', 'toughness' => '2', 'text' => 'When this creature enters, it deals 4 damage to target creature.', 'colors' => ['R']],
        'Doomed Traveler' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '1', 'text' => 'When this creature dies, create a 1/1 white Spirit creature token with flying.', 'colors' => ['W']],
        'Bloodfell Caves' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped.
When this land enters, you gain 1 life.
{T}: Add {B} or {R}.", 'colors' => []],
        'Raid Leader' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Human Soldier', 'power' => '2', 'toughness' => '2', 'text' => "Whenever this creature attacks, you gain 1 life.
Whenever this creature deals combat damage to a player, draw a card.", 'colors' => ['W']],
        'Dark Tutelage' => ['manaCost' => '{2}{B}', 'type' => 'Enchantment', 'text' => 'At the beginning of your upkeep, draw a card. You lose 1 life.', 'colors' => ['B']],
        'Prodigal Pyromancer' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '1', 'text' => '{T}: This creature deals 1 damage to any target.', 'colors' => ['R']],
        'Bottle Gnomes' => ['manaCost' => '{3}', 'type' => 'Artifact Creature — Gnome', 'power' => '1', 'toughness' => '3', 'text' => 'Sacrifice this creature: You gain 3 life.', 'colors' => []],
        'Wild Cub' => ['manaCost' => '{G}', 'type' => 'Creature — Cat', 'power' => '1', 'toughness' => '1', 'text' => '{G}: This creature gets +1/+1 until end of turn. Activate only once each turn.', 'colors' => ['G']],
        'Chandra, Pyrogenius' => ['manaCost' => '{4}{R}{R}', 'type' => 'Legendary Planeswalker — Chandra', 'supertypes' => ['Legendary'], 'types' => ['Planeswalker'], 'subtypes' => ['Chandra'], 'loyalty' => '5', 'text' => "+2: Chandra, Pyrogenius deals 2 damage to each opponent.
−3: Chandra, Pyrogenius deals 4 damage to target creature.
−10: Chandra, Pyrogenius deals 6 damage to target player and each creature that player controls.", 'colors' => ['R']],
        'Bonesplitter' => ['manaCost' => '{1}', 'type' => 'Artifact — Equipment', 'text' => "Equipped creature gets +2/+0.
Equip {1}", 'colors' => []],
        'Raise the Alarm' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => 'Create two 1/1 white Soldier creature tokens.', 'colors' => ['W']],
    ];

    protected Game $game;

    /**
     * Card data by name, or a basic land.
     *
     * @param string $name
     *
     * @return array
     */
    protected static function card(string $name): array
    {
        if (($key = BasicLands::key($name)) !== null) {
            return BasicLands::card($key);
        }

        return ['uuid' => 'uuid-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common', 'manaValue' => 0.0] + self::CARDS[$name];
    }

    /**
     * A game whose players kept their hands, at the first main phase of
     * turn 1 for player 0 (Alice). Libraries hold 27 Forests each; hands
     * are emptied so each test chooses its own cards.
     *
     * @param bool $autoPass Whether players with nothing to do pass by themselves. Off, every pass is the test's.
     *
     * @return Game
     */
    protected function newGame(bool $autoPass = false): Game
    {
        $deck = array_fill(0, 27, self::card('Forest'));
        $seed = 'test-seed';
        // Find a seed where Alice (seat 0) plays first, so every test reads the same way.
        for ($n = 0; ; $n++) {
            $game = Game::start('game-1', [
                ['id' => '1', 'name' => 'Alice', 'cards' => $deck],
                ['id' => '2', 'name' => 'Bob', 'cards' => $deck],
            ], "{$seed}-{$n}");
            if ($game->startingPlayer === 0) {
                break;
            }
        }
        foreach ($game->players as $seat => $player) {
            foreach ($player->hand as $id) {
                $game->objects[$id]->moveTo(GameObject::EXILE);
                $game->exile[] = $id;
            }
            $player->hand = [];
        }
        $game->setAutoPass(0, $autoPass);
        $game->setAutoPass(1, $autoPass);
        $game->keep(0);
        $game->keep(1);
        $this->game = $game;
        $this->passUntil(Step::PrecombatMain);
        $this->assertSame(0, $game->priority);

        return $game;
    }

    protected function hand(int $seat, string $name): int
    {
        return $this->game->addCard($seat, self::card($name), GameObject::HAND)->id;
    }

    protected function battlefield(int $seat, string $name): int
    {
        return $this->game->addCard($seat, self::card($name), GameObject::BATTLEFIELD)->id;
    }

    /**
     * @param int    $seat
     * @param string $name
     * @param int    $count
     *
     * @return int[]
     */
    protected function lands(int $seat, string $name, int $count): array
    {
        return array_map(fn () => $this->battlefield($seat, $name), range(1, $count));
    }

    /**
     * Passes priority back and forth, without attacking or blocking, until
     * the game reaches a step, failing on any other decision.
     *
     * @param Step     $step
     * @param int|null $turn
     *
     * @return void
     */
    protected function passUntil(Step $step, ?int $turn = null): void
    {
        for ($i = 0; $i < 100; $i++) {
            if ($this->game->step === $step && ($turn === null || $this->game->turn === $turn)) {
                return;
            }
            $priority = $this->game->priority;
            // Nobody attacks or blocks on the way.
            if ($this->game->decision($this->game->active) === 'attack') {
                $this->game->declareAttackers($this->game->active, []);

                continue;
            }
            if ($this->game->decision($this->game->defender()) === 'block') {
                $this->game->declareBlockers($this->game->defender(), []);

                continue;
            }
            if ($priority === null) {
                $this->fail("Waiting on a decision in {$this->game->step->label()} before reaching {$step->label()}.");
            }
            $this->game->pass($priority);
        }
        $this->fail("Never reached {$step->label()}.");
    }

    protected function zone(int $id): string
    {
        return $this->game->objects[$id]->zone;
    }

    protected function life(int $seat): int
    {
        return $this->game->players[$seat]->life;
    }
}
