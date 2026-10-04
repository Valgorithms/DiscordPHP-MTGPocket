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

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Lines common in the newest sets: "unless pays" counters, discard and
 * looting, team pumps, "can't be countered", evasion, infect, lands that
 * enter tapped unless, -1/-1 counters, Job select, Empower Jace,
 * landcycling, fight.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class CommonCardTextTest extends GameTestCase
{
    private const array MORE = [
        'Mana Leak' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'Counter target spell unless its controller pays {3}.', 'colors' => ['U']],
        'Dovin\'s Veto' => ['manaCost' => '{W}{U}', 'type' => 'Instant', 'text' => "This spell can't be countered.\nCounter target noncreature spell.", 'colors' => ['W', 'U']],
        'Faithless Looting' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => "Draw two cards, then discard two cards.\nFlashback {2}{R}", 'colors' => ['R']],
        'Mind Rot' => ['manaCost' => '{2}{B}', 'type' => 'Sorcery', 'text' => 'Target player discards two cards.', 'colors' => ['B']],
        'Overrun' => ['manaCost' => '{2}{G}{G}{G}', 'type' => 'Sorcery', 'text' => 'Creatures you control get +3/+3 and gain trample until end of turn.', 'colors' => ['G']],
        'Terminate' => ['manaCost' => '{B}{R}', 'type' => 'Instant', 'text' => "Destroy target creature. It can't be regenerated.", 'colors' => ['B', 'R']],
        'Goblin Rally Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Create two 1/1 red Goblin creature tokens. They gain haste until end of turn.', 'colors' => ['R']],
        'Ancient Lore' => ['manaCost' => '{3}{U}', 'type' => 'Sorcery', 'text' => "Draw three cards.\nExile Ancient Lore.", 'colors' => ['U']],
        'Endless One' => ['manaCost' => '{X}', 'type' => 'Creature — Eldrazi', 'power' => '0', 'toughness' => '0', 'text' => 'This creature enters with X +1/+1 counters on it.', 'colors' => []],
        'Burdened Stoneback' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Giant Warrior', 'power' => '4', 'toughness' => '4', 'text' => 'This creature enters with two -1/-1 counters on it.', 'colors' => ['W']],
        'Plague Stinger' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Phyrexian Insect Horror', 'power' => '1', 'toughness' => '1', 'text' => "Flying\nInfect", 'colors' => ['B']],
        'Dauthi Slayer' => ['manaCost' => '{B}{B}', 'type' => 'Creature — Dauthi Soldier', 'power' => '2', 'toughness' => '2', 'text' => "Shadow\nThis creature attacks each combat if able.", 'colors' => ['B']],
        'Severed Legion' => ['manaCost' => '{1}{B}{B}', 'type' => 'Creature — Zombie', 'power' => '2', 'toughness' => '2', 'text' => 'Fear', 'colors' => ['B']],
        'Phantom Warrior' => ['manaCost' => '{1}{U}{U}', 'type' => 'Creature — Illusion Warrior', 'power' => '2', 'toughness' => '2', 'text' => "This creature can't be blocked.", 'colors' => ['U']],
        'Furtive Homunculus' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Homunculus', 'power' => '2', 'toughness' => '1', 'text' => 'Skulk', 'colors' => ['U']],
        'Carnage Tyrant' => ['manaCost' => '{4}{G}{G}', 'type' => 'Creature — Dinosaur', 'power' => '7', 'toughness' => '6', 'text' => "This spell can't be countered.\nTrample, hexproof", 'colors' => ['G']],
        'Mistvein Borderpost Lite' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped unless you control two or more other lands.\n{T}: Add {U} or {B}.", 'colors' => []],
        'Drowned Catacomb' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped unless you control an Island or a Swamp.\n{T}: Add {U} or {B}.", 'colors' => []],
        'Shipwreck Marsh' => ['manaCost' => null, 'type' => 'Land', 'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {U} or {B}.", 'colors' => []],
        'Dragoon\'s Lance' => ['manaCost' => '{1}{W}', 'type' => 'Artifact — Equipment', 'text' => "Job select (When this Equipment enters, create a 1/1 colorless Hero creature token, then attach this to it.)\nEquipped creature gets +1/+0.\nGae Bolg — Equip {4}", 'colors' => ['W']],
        'Academic Ascent' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => "Target creature gets +2/+2 and gains flying until end of turn.\nEmpower Jace 2. (Put two loyalty counters on a Jace token you control. If you don't control one, first create a blue Jace planeswalker token with \"[−1]: Surveil 1\" and \"[−3]: Draw a card.\")", 'colors' => ['W']],
        'Undulating Witness' => ['manaCost' => '{4}{U}', 'type' => 'Creature — Serpent', 'power' => '3', 'toughness' => '5', 'text' => "Flying\nBasic landcycling {2} ({2}, Discard this card: Search your library for a basic land card, reveal it, put it into your hand, then shuffle.)", 'colors' => ['U']],
        'Prey Upon' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Target creature you control fights target creature you don\'t control.', 'colors' => ['G']],
        'Rabid Bite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Target creature you control deals damage equal to its power to target creature or planeswalker you don\'t control.', 'colors' => ['G']],
        'Night\'s Whisper Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Target player draws two cards and loses 2 life.', 'colors' => ['B']],
    ];

    private function put(int $seat, string $name, string $zone): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private static function more(string $name): array
    {
        return ['uuid' => 'uuid-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private static function read(string $name): CardDefinition
    {
        return new CardDefinition(self::more($name));
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    public function testEveryCardHereIsReadWhole(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
    }

    public function testCounterUnlessItsControllerPays(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $this->lands(1, 'Island', 2);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $leak = $this->put(1, 'Mana Leak', GameObject::HAND);
        $game->cast(0, $bolt, 0, ['p:1']);
        $game->pass(0);
        $game->cast(1, $leak, 0, ['s:'.$game->stack[0]['id']]);
        $this->resolve();
        $this->assertStringContainsString('Alice pays {3}, so Lightning Bolt is not countered.', implode("\n", $game->log));
        $this->resolve();
        $this->assertSame(17, $this->life(1));

        // Without the mana, the spell is countered.
        $game->pass(0);
        $game->pass(1);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(1, 'Island', 2);
        $shock = $this->hand(0, 'Shock');
        foreach ($game->permanents(0) as $land) {
            $land->tapped = true;
        }
        $this->lands(0, 'Mountain', 1);
        $leak = $this->put(1, 'Mana Leak', GameObject::HAND);
        $game->cast(0, $shock, 0, ['p:1']);
        $game->pass(0);
        $game->cast(1, $leak, 0, ['s:'.$game->stack[0]['id']]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($shock));
        $this->assertSame(17, $this->life(1));
    }

    public function testCantBeCountered(): void
    {
        $this->assertContains("can't be countered", self::read('Carnage Tyrant')->keywords);
        $game = $this->newGame();
        $this->lands(0, 'Forest', 6);
        $this->lands(1, 'Island', 2);
        $tyrant = $this->put(0, 'Carnage Tyrant', GameObject::HAND);
        $counter = $this->hand(1, 'Counterspell');
        $game->cast(0, $tyrant);
        $game->pass(0);
        $game->cast(1, $counter, 0, ['s:'.$game->stack[0]['id']]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($tyrant));
    }

    public function testLootingAndDiscardWaitForTheirPlayer(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $keep = $this->hand(0, 'Lightning Bolt');
        $looting = $this->put(0, 'Faithless Looting', GameObject::HAND);
        $game->cast(0, $looting);
        $this->resolve();
        $this->assertSame('discard', $game->decision(0));
        $this->assertSame(2, $game->discardCount());
        $hand = $game->players[0]->hand;
        $this->assertCount(3, $hand);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $away = array_values(array_diff($hand, [$keep]));
        $game->discard(0, $away);
        $this->assertSame([$keep], $game->players[0]->hand);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($looting));
        $this->assertSame('priority', $game->decision(0));

        // With no more cards than they must discard, there is nothing to choose.
        $this->lands(0, 'Swamp', 3);
        $rot = $this->put(0, 'Mind Rot', GameObject::HAND);
        $bears = $this->hand(1, 'Grizzly Bears');
        $game->cast(0, $rot, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame('priority', $game->decision(0));
    }

    public function testTeamPumpsAndTokensWithHaste(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 5);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $overrun = $this->put(0, 'Overrun', GameObject::HAND);
        $game->cast(0, $overrun);
        $this->resolve();
        $this->assertSame(5, $game->power($game->objects[$bears]));
        $this->assertTrue($game->hasKeyword($game->objects[$bears], 'trample'));
        $this->assertSame(2, $game->power($game->objects[$theirs]));

        $this->lands(0, 'Mountain', 3);
        $rally = $this->put(0, 'Goblin Rally Lite', GameObject::HAND);
        $game->cast(0, $rally);
        $this->resolve();
        $goblins = array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Goblin Token');
        $this->assertCount(2, $goblins);
        foreach ($goblins as $goblin) {
            $this->assertContains($goblin->id, $game->attackCandidates(), 'Tokens with haste can attack at once.');
        }
    }

    public function testTerminateAndExileTheSpell(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 1);
        $this->lands(0, 'Mountain', 1);
        $boa = $this->battlefield(1, 'Grizzly Bears');
        $game->objects[$boa]->shields = 1;
        $terminate = $this->put(0, 'Terminate', GameObject::HAND);
        $game->cast(0, $terminate, 0, ["o:{$boa}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($boa), 'It cannot be regenerated.');

        $this->lands(0, 'Island', 4);
        $lore = $this->put(0, 'Ancient Lore', GameObject::HAND);
        $game->cast(0, $lore);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($lore));
        $this->assertCount(3, $game->players[0]->hand);
    }

    public function testEntersWithXAndMinusCounters(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 5);
        $one = $this->put(0, 'Endless One', GameObject::HAND);
        $game->cast(0, $one, 3);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$one]));

        $stoneback = $this->put(0, 'Burdened Stoneback', GameObject::HAND);
        $game->cast(0, $stoneback);
        $this->resolve();
        $this->assertSame(2, $game->toughness($game->objects[$stoneback]));
    }

    public function testEvasion(): void
    {
        $game = $this->newGame();
        $attackers = [
            'Dauthi Slayer' => $this->put(0, 'Dauthi Slayer', GameObject::BATTLEFIELD),
            'Severed Legion' => $this->put(0, 'Severed Legion', GameObject::BATTLEFIELD),
            'Phantom Warrior' => $this->put(0, 'Phantom Warrior', GameObject::BATTLEFIELD),
            'Furtive Homunculus' => $this->put(0, 'Furtive Homunculus', GameObject::BATTLEFIELD),
            'Grizzly Bears' => $this->battlefield(0, 'Grizzly Bears'),
        ];
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $rats = $this->battlefield(1, 'Typhoid Rats');
        $this->passUntil(Step::DeclareAttackers, 3);
        // The Slayer must attack, so it joins even when left out.
        $game->declareAttackers(0, [$attackers['Severed Legion'], $attackers['Phantom Warrior'], $attackers['Furtive Homunculus'], $attackers['Grizzly Bears']]);
        $this->assertArrayHasKey($attackers['Dauthi Slayer'], $game->attackers);

        $can = fn (int $blocker, string $attacker) => $game->canBlock($game->objects[$blocker], $game->objects[$attackers[$attacker]]);
        $this->assertFalse($can($bears, 'Dauthi Slayer'), 'Shadow');
        $this->assertFalse($can($bears, 'Severed Legion'), 'Fear: not black');
        $this->assertTrue($can($rats, 'Severed Legion'), 'Fear: black');
        $this->assertFalse($can($rats, 'Phantom Warrior'));
        $this->assertTrue($can($rats, 'Furtive Homunculus'), 'Skulk: power 1');
        $this->assertTrue($can($bears, 'Furtive Homunculus'), 'Skulk: power 2');
        $this->assertTrue($can($bears, 'Grizzly Bears'));
    }

    public function testInfect(): void
    {
        $game = $this->newGame();
        $stinger = $this->put(0, 'Plague Stinger', GameObject::BATTLEFIELD);
        $spider = $this->battlefield(1, 'Giant Spider');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$stinger]);
        $this->passUntil(Step::EndCombat);
        $this->assertSame(1, $game->players[1]->poison);
        $this->assertSame(20, $this->life(1));

        // Damage to a creature is -1/-1 counters.
        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$stinger]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$spider => $stinger]);
        $this->passUntil(Step::EndCombat);
        $this->assertSame(1, $game->objects[$spider]->counter('-1/-1'));
        $this->assertSame(0, $game->objects[$spider]->damage);
    }

    public function testLandsThatEnterTappedUnless(): void
    {
        $game = $this->newGame();
        $catacomb = $this->put(0, 'Drowned Catacomb', GameObject::HAND);
        $game->playLand(0, $catacomb);
        $this->assertTrue($game->objects[$catacomb]->tapped, 'No Island or Swamp.');

        $this->passUntil(Step::PrecombatMain, 3);
        $this->battlefield(0, 'Swamp');
        $catacomb = $this->put(0, 'Drowned Catacomb', GameObject::HAND);
        $game->playLand(0, $catacomb);
        $this->assertFalse($game->objects[$catacomb]->tapped);

        $this->passUntil(Step::PrecombatMain, 5);
        $marsh = $this->put(0, 'Shipwreck Marsh', GameObject::HAND);
        $game->playLand(0, $marsh);
        $this->assertFalse($game->objects[$marsh]->tapped);
        $this->assertSame(18, $this->life(0));

        $this->passUntil(Step::PrecombatMain, 7);
        $post = $this->put(0, 'Mistvein Borderpost Lite', GameObject::HAND);
        $game->playLand(0, $post);
        $this->assertFalse($game->objects[$post]->tapped, 'Three other lands.');
    }

    public function testJobSelectAndNamedEquip(): void
    {
        $lance = self::read('Dragoon\'s Lance');
        $this->assertSame(['mana' => '{4}'], array_intersect_key($lance->activated[0]['cost'], ['mana' => true]));
        $game = $this->newGame();
        $this->lands(0, 'Plains', 2);
        $id = $this->put(0, 'Dragoon\'s Lance', GameObject::HAND);
        $game->cast(0, $id);
        $this->resolve();
        $this->resolve();
        $hero = $game->objects[$game->objects[$id]->attachedTo];
        $this->assertSame('Hero Token', $hero->name());
        $this->assertSame(2, $game->power($hero));
    }

    public function testEmpowerJace(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 4);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $ascent = $this->put(0, 'Academic Ascent', GameObject::HAND);
        $game->cast(0, $ascent, 0, ["o:{$bears}"]);
        $this->resolve();
        $jaces = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Jace Token'));
        $this->assertCount(1, $jaces);
        $this->assertSame(2, $jaces[0]->counter('loyalty'));
        $this->assertCount(2, $jaces[0]->definition()->activated);

        $ascent = $this->put(0, 'Academic Ascent', GameObject::HAND);
        $game->cast(0, $ascent, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(4, $jaces[0]->counter('loyalty'), 'The same Jace grows.');
    }

    public function testBasicLandcycling(): void
    {
        $game = $this->newGame();
        // Alice has Forests, so the Island is what she needs.
        $this->lands(0, 'Forest', 2);
        $game->addCard(0, self::card('Island'), GameObject::LIBRARY);
        $witness = $this->put(0, 'Undulating Witness', GameObject::HAND);
        $this->assertTrue($game->canCycle(0, $witness));
        $game->cycle(0, $witness);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertSame('Island', $game->objects[$game->players[0]->hand[0]]->name());
    }

    public function testFightAndBite(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 3);
        $giant = $this->battlefield(0, 'Hill Giant');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $prey = $this->put(0, 'Prey Upon', GameObject::HAND);
        $game->cast(0, $prey, 0, ["o:{$giant}", "o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(2, $game->objects[$giant]->damage);

        $spider = $this->battlefield(1, 'Giant Spider');
        $bite = $this->put(0, 'Rabid Bite', GameObject::HAND);
        $game->cast(0, $bite, 0, ["o:{$giant}", "o:{$spider}"]);
        $this->resolve();
        $this->assertSame(3, $game->objects[$spider]->damage);
        $this->assertSame(2, $game->objects[$giant]->damage, 'Bite is one-sided.');
    }

    public function testDrawAndLoseLifeTogether(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 2);
        $whisper = $this->put(0, 'Night\'s Whisper Lite', GameObject::HAND);
        $game->cast(0, $whisper, 0, ['p:1']);
        $this->resolve();
        $this->assertCount(2, $game->players[1]->hand);
        $this->assertSame(18, $this->life(1));
    }

    public function testDecksOfTheseCardsPlayToTheEnd(): void
    {
        $deck = function (array $basics, array $more): array {
            $cards = [];
            foreach ($basics as $name => $count) {
                array_push($cards, ...array_fill(0, $count, self::card($name)));
            }
            foreach ($more as $name => $count) {
                array_push($cards, ...array_fill(0, $count, self::more($name)));
            }

            return $cards;
        };
        $dimir = $deck(['Island' => 6, 'Swamp' => 6], [
            'Drowned Catacomb' => 3, 'Shipwreck Marsh' => 3, 'Mistvein Borderpost Lite' => 2, 'Mana Leak' => 3, 'Mind Rot' => 2, 'Ancient Lore' => 2,
            'Plague Stinger' => 3, 'Dauthi Slayer' => 3, 'Severed Legion' => 2, 'Phantom Warrior' => 3, 'Furtive Homunculus' => 3,
            'Undulating Witness' => 2, 'Night\'s Whisper Lite' => 2, 'Endless One' => 1,
        ]);
        $naya = $deck(['Forest' => 7, 'Plains' => 5, 'Mountain' => 5], [
            'Faithless Looting' => 2, 'Overrun' => 2, 'Goblin Rally Lite' => 2, 'Terminate' => 1, 'Burdened Stoneback' => 3, 'Carnage Tyrant' => 2,
            'Dragoon\'s Lance' => 3, 'Academic Ascent' => 3, 'Prey Upon' => 3, 'Rabid Bite' => 3, 'Dovin\'s Veto' => 1, 'Endless One' => 2,
        ]);

        $games = (int) (getenv('MTGPOCKET_SIM_GAMES') ?: 10);
        for ($seed = 0; $seed < $games; $seed++) {
            $game = Game::start("common-{$seed}", [
                ['id' => '1', 'name' => 'Alice', 'cards' => $dimir],
                ['id' => '2', 'name' => 'Bob', 'cards' => $naya],
            ], "common-{$seed}");
            for ($moves = 0; $game->stage !== Game::OVER; $moves++) {
                $this->assertLessThan(5000, $moves, "Seed {$seed} never ended.");
                $waiting = $game->waitingOn();
                $this->assertNotSame([], $waiting, "Seed {$seed}: nobody can move in {$game->step->label()} of turn {$game->turn}.");
                $this->assertTrue(AutoPlayer::act($game, $waiting[0]), "Seed {$seed}: seat {$waiting[0]} could not make a {$game->decision($waiting[0])} move.");
                $game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->assertSame(Game::OVER, $game->stage);
            if (getenv('MTGPOCKET_SIM_LOG')) {
                file_put_contents(getenv('MTGPOCKET_SIM_LOG'), implode("\n", $game->log)."\n", FILE_APPEND);
            }
        }
    }
}
