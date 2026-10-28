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
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Fading removes a fade counter or sacrifices, not both (rule 702.32).
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class FadingTest extends GameTestCase
{
    /** Actual corpus wording, kept offline rather than using live card services. */
    private const array MORE = [
        'Blastoderm' => ['manaCost' => '{2}{G}{G}', 'type' => 'Creature — Beast', 'power' => '5', 'toughness' => '5', 'colors' => ['G'], 'text' => "Shroud (This creature can't be the target of spells or abilities.)\nFading 3 (This creature enters with three fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)"],
        'Cloudskate' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Illusion', 'power' => '2', 'toughness' => '2', 'colors' => ['U'], 'text' => "Flying\nFading 3 (This creature enters with three fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)"],
        'Skyshroud Ridgeback' => ['manaCost' => '{G}', 'type' => 'Creature — Beast', 'power' => '2', 'toughness' => '3', 'colors' => ['G'], 'text' => "Fading 2 (This creature enters with two fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)"],
        'Skyshroud Behemoth' => ['manaCost' => '{5}{G}{G}', 'type' => 'Creature — Beast', 'power' => '10', 'toughness' => '10', 'colors' => ['G'], 'text' => "Fading 2 (This creature enters with two fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)\nThis creature enters tapped."],
        'Rejuvenation Chamber' => ['manaCost' => '{3}', 'type' => 'Artifact', 'colors' => [], 'text' => "Fading 2 (This artifact enters with two fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)\n{T}: You gain 2 life."],
        'Phyrexian Prowler' => ['manaCost' => '{3}{B}', 'type' => 'Creature — Phyrexian Zombie Mercenary', 'power' => '3', 'toughness' => '3', 'colors' => ['B'], 'text' => "Fading 3 (This creature enters with three fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)\nRemove a fade counter from this creature: This creature gets +1/+1 until end of turn."],
        'Jolting Merfolk' => ['manaCost' => '{2}{U}{U}', 'type' => 'Creature — Merfolk', 'power' => '2', 'toughness' => '2', 'colors' => ['U'], 'text' => "Fading 4 (This creature enters with four fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)\nRemove a fade counter from this creature: Tap target creature."],
        'Defender en-Vec' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Human Cleric', 'power' => '2', 'toughness' => '4', 'colors' => ['W'], 'text' => "Fading 4 (This creature enters with four fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)\nRemove a fade counter from this creature: Prevent the next 2 damage that would be dealt to any target this turn."],
        'Woodripper' => ['manaCost' => '{3}{G}{G}', 'type' => 'Creature — Beast', 'power' => '4', 'toughness' => '6', 'colors' => ['G'], 'text' => "Fading 3 (This creature enters with three fade counters on it. At the beginning of your upkeep, remove a fade counter from it. If you can't, sacrifice it.)\n{1}, Remove a fade counter from this creature: Destroy target artifact."],
        'Control Magic' => ['manaCost' => '{2}{U}{U}', 'type' => 'Enchantment — Aura', 'colors' => ['U'], 'text' => "Enchant creature\nYou control enchanted creature."],
        'Act of Aggression' => ['manaCost' => '{3}{R/P}{R/P}', 'type' => 'Instant', 'colors' => ['R'], 'text' => "({R/P} can be paid with either {R} or 2 life.)\nGain control of target creature an opponent controls until end of turn. Untap that creature. It gains haste until end of turn."],
        'Momentary Blink' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'colors' => ['W'], 'text' => "Exile target creature you control, then return it to the battlefield under its owner's control.\nFlashback {3}{U} (You may cast this card from your graveyard for its flashback cost. Then exile it.)"],
        'Mortician Beetle' => ['manaCost' => '{B}', 'type' => 'Creature — Insect', 'power' => '1', 'toughness' => '1', 'colors' => ['B'], 'text' => 'Whenever a player sacrifices a creature, you may put a +1/+1 counter on this creature.'],
        'Calciderm' => ['manaCost' => '{2}{W}{W}', 'type' => 'Creature — Beast', 'power' => '5', 'toughness' => '5', 'colors' => ['W'], 'text' => "Shroud (This creature can't be the target of spells or abilities.)\nVanishing 4 (This creature enters with four time counters on it. At the beginning of your upkeep, remove a time counter from it. When the last is removed, sacrifice it.)"],
    ];

    private static function more(string $name): array
    {
        return ['uuid' => 'fading-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private static function fixture(string $text, string $type = 'Creature — Beast'): array
    {
        return ['uuid' => 'fading-fixture', 'name' => 'Fading Fixture', 'rarity' => 'common', 'manaCost' => '{0}', 'type' => $type, 'power' => '2', 'toughness' => '3', 'colors' => [], 'text' => $text];
    }

    private function put(int $seat, string $name, string $zone): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function reload(): Game
    {
        return $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRealCardWordingIsReadWhole(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $card = new CardDefinition(self::more($name));
            $this->assertSame([], $card->unsupported, $name);
            if (preg_match('/Fading (\d+)/', self::MORE[$name]['text'], $m)) {
                $this->assertSame(['fade' => (int) $m[1]], $card->entersWithOther, $name);
                $this->assertSame('upkeep', $card->triggered[0]['event'], $name);
                $this->assertSame([['type' => 'fading', 'self' => true]], $card->triggered[0]['effects'], $name);
            }
        }
    }

    public function testOnlyExactNumberedPermanentKeywordIsRecognized(): void
    {
        foreach (['Fading', 'Fading X', 'Fading -1', 'Fading 1.5', 'Fading three', 'Fading 3 or 4', 'Fading 3 with extra rules', 'Fading 3, an unknown keyword'] as $text) {
            $card = new CardDefinition(self::fixture($text));
            $this->assertNotSame([], $card->unsupported, $text);
            $this->assertSame([], $card->triggered, $text);
            $this->assertSame([], $card->entersWithOther, $text);
        }
        foreach (['Instant', 'Sorcery'] as $type) {
            $card = new CardDefinition(self::fixture('Fading 3', $type));
            $this->assertNotSame([], $card->unsupported);
            $this->assertSame([], $card->triggered);
            $this->assertSame([], $card->entersWithOther);
        }
    }

    public function testAdditionalAbilitiesAreNotBlanketRecognized(): void
    {
        // Actual unresolved clauses on Tangle Wire, Rusting Golem and Parallax Wave.
        foreach ([
            "At the beginning of each player's upkeep, that player taps an untapped artifact, creature, or land they control for each fade counter on this artifact.",
            "This creature's power and toughness are each equal to the number of fade counters on it.",
            'When this enchantment leaves the battlefield, each player returns to the battlefield all cards they own exiled with it.',
        ] as $extra) {
            $card = new CardDefinition(self::fixture("Fading 3\n{$extra}"));
            $this->assertCount(1, $card->unsupported, $extra);
            $this->assertCount(1, $card->triggered, $extra);
            $this->assertSame('fading', $card->triggered[0]['effects'][0]['type']);
        }
    }

    public static function basicFaders(): array
    {
        return [
            'Blastoderm' => ['Blastoderm', 'Forest', 3, false],
            'Cloudskate' => ['Cloudskate', 'Island', 3, false],
            'Skyshroud Ridgeback' => ['Skyshroud Ridgeback', 'Forest', 2, false],
            'Skyshroud Behemoth' => ['Skyshroud Behemoth', 'Forest', 2, true],
            'Rejuvenation Chamber' => ['Rejuvenation Chamber', 'Forest', 2, false],
        ];
    }

    /** @dataProvider basicFaders */
    public function testCastEntryAndCompleteUpkeepLifecycle(string $name, string $land, int $counters, bool $tapped): void
    {
        $game = $this->newGame();
        $this->lands(0, $land, 7);
        $id = $this->put(0, $name, GameObject::HAND);
        $game->cast(0, $id);
        $this->assertSame(0, $game->objects[$id]->counter('fade'), 'Counters are not on the spell.');
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame($counters, $game->objects[$id]->counter('fade'));
        $this->assertSame(0, $game->objects[$id]->counter('time'));
        $this->assertSame($tapped, $game->objects[$id]->tapped);
        $this->assertSame([], $game->stack, 'Entry counters do not use the stack.');
        if ($name === 'Blastoderm') {
            $this->assertTrue($game->hasKeyword($game->objects[$id], 'shroud'));
        } elseif ($name === 'Cloudskate') {
            $this->assertTrue($game->hasKeyword($game->objects[$id], 'flying'));
        } elseif ($name === 'Rejuvenation Chamber') {
            $game->activate(0, $id, 0);
            $this->resolve();
            $this->assertSame(22, $this->life(0));
        }
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->stack, 'No fading trigger in the opponent\'s upkeep.');
        for ($upkeep = 1; $upkeep <= $counters; $upkeep++) {
            $this->passUntil(Step::Upkeep, 2 * $upkeep + 1);
            $this->assertCount(1, $game->stack);
            $this->assertSame('ability', $game->stack[0]['kind']);
            $this->assertSame($counters - $upkeep + 1, $game->objects[$id]->counter('fade'), 'Removal waits for resolution.');
            $game = $this->reload();
            $this->resolve();
            $this->assertSame($counters - $upkeep, $game->objects[$id]->counter('fade'));
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id), 'Removing the last fade counter does not sacrifice it.');
            $this->assertSame([], $game->stack, 'Removing the last fade counter does not create a sacrifice trigger.');
        }
        $this->passUntil(Step::Upkeep, 2 * $counters + 3);
        $this->assertCount(1, $game->stack, 'Fading triggers even with no fade counters.');
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertContains($id, $game->players[0]->graveyard);
    }

    public function testFadingZeroSacrificesIndestructibleAndNotifiesSacrificeWatchers(): void
    {
        $game = $this->newGame();
        $id = $game->addCard(0, self::fixture("Fading 0\nIndestructible"), GameObject::BATTLEFIELD)->id;
        $beetle = $this->put(1, 'Mortician Beetle', GameObject::BATTLEFIELD);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(0, $game->objects[$id]->counter('fade'));
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(1, $game->stack);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertCount(1, $game->stack, 'Sacrifice watchers must see the sacrifice, not only the zone change.');
        $this->resolve();
        $this->assertSame(1, $game->objects[$beetle]->counter('+1/+1'));
    }

    public function testSpendingLastCounterOutsideUpkeepWaitsUntilNextUpkeep(): void
    {
        $game = $this->newGame();
        $id = $this->put(0, 'Phyrexian Prowler', GameObject::BATTLEFIELD);
        for ($i = 0; $i < 3; $i++) {
            $game->activate(0, $id, 0);
            $this->resolve();
        }
        $this->assertSame(0, $game->objects[$id]->counter('fade'));
        $this->assertSame(6, $game->power($game->objects[$id]));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame([], $game->stack);
        $before = $game->toArray();
        try {
            $game->activate(0, $id, 0);
            $this->fail('The empty permanent cannot pay another fade counter.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $game->toArray());
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->stack);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->passUntil(Step::Upkeep, 3);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    public function testSpendingLastCounterInResponseMakesFadingSacrifice(): void
    {
        $game = $this->newGame();
        $id = $this->put(0, 'Phyrexian Prowler', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 7);
        $this->assertSame(1, $game->objects[$id]->counter('fade'));
        $game->activate(0, $id, 0);
        $this->assertSame(0, $game->objects[$id]->counter('fade'), 'Removing the counter is the activation cost.');
        $this->assertCount(2, $game->stack);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    public function testAddingCounterInResponseToZeroCounterTriggerPreventsSacrifice(): void
    {
        $game = $this->newGame();
        // Synthetic combination isolates a counter arriving after the upkeep trigger is created.
        $id = $game->addCard(0, self::fixture("Fading 0\n{0}: Put a fade counter on this creature."), GameObject::BATTLEFIELD)->id;
        $this->assertSame([], $game->objects[$id]->definition()->unsupported);
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(1, $game->stack);
        $game->activate(0, $id, 0);
        $this->resolve();
        $this->assertSame(1, $game->objects[$id]->counter('fade'));
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(0, $game->objects[$id]->counter('fade'));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
    }

    public function testExistingCounterCostsStillTapPreventAndDestroy(): void
    {
        $game = $this->newGame();
        $merfolk = $this->put(0, 'Jolting Merfolk', GameObject::BATTLEFIELD);
        $defender = $this->put(0, 'Defender en-Vec', GameObject::BATTLEFIELD);
        $woodripper = $this->put(0, 'Woodripper', GameObject::BATTLEFIELD);
        $victim = $this->battlefield(1, 'Grizzly Bears');
        $artifact = $this->battlefield(1, 'Ornithopter');
        $this->lands(0, 'Forest', 1);
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->activate(0, $merfolk, 0, ["o:{$victim}"]);
        $this->resolve();
        $this->assertTrue($game->objects[$victim]->tapped);
        $this->assertSame(3, $game->objects[$merfolk]->counter('fade'));
        $game->activate(0, $defender, 0, ['p:0']);
        $this->resolve();
        $this->assertSame(3, $game->objects[$defender]->counter('fade'));
        $game->cast(0, $bolt, 0, ['p:0']);
        $this->resolve();
        $this->assertSame(19, $this->life(0), 'Two of the three damage were prevented.');
        $game->activate(0, $woodripper, 0, ["o:{$artifact}"]);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(2, $game->objects[$woodripper]->counter('fade'));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
    }

    public function testFadingFollowsControllerRatherThanOwner(): void
    {
        $game = $this->newGame();
        $id = $this->put(0, 'Skyshroud Ridgeback', GameObject::BATTLEFIELD);
        $this->lands(1, 'Island', 4);
        $aura = $this->put(1, 'Control Magic', GameObject::HAND);
        $this->passUntil(Step::PrecombatMain, 2);
        $game->cast(1, $aura, 0, ["o:{$id}"]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$id]->controller);
        $this->assertSame(0, $game->objects[$id]->owner);
        $game = $this->reload();
        $this->passUntil(Step::Upkeep, 3);
        $this->assertSame([], $game->stack, 'The owner\'s upkeep does not trigger fading.');
        $this->assertSame(2, $game->objects[$id]->counter('fade'));
        foreach ([4 => 1, 6 => 0] as $turn => $remaining) {
            $this->passUntil(Step::Upkeep, $turn);
            $this->assertSame(1, $game->stack[0]['controller']);
            $this->resolve();
            $this->assertSame($remaining, $game->objects[$id]->counter('fade'));
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
        $this->passUntil(Step::Upkeep, 8);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertContains($id, $game->players[0]->graveyard, 'Sacrifice returns it to its owner\'s graveyard.');
        $this->assertNotContains($id, $game->players[1]->graveyard);
    }

    public function testControlChangeInResponseDoesNotPreventRemovalButPreventsSacrifice(): void
    {
        foreach ([2, 0] as $counters) {
            $game = $this->newGame();
            $id = $game->addCard(0, self::fixture("Fading {$counters}"), GameObject::BATTLEFIELD)->id;
            $this->lands(1, 'Mountain', 5);
            $steal = $this->put(1, 'Act of Aggression', GameObject::HAND);
            $this->passUntil(Step::Upkeep, 3);
            $game->pass(0);
            $game->cast(1, $steal, 0, ["o:{$id}"]);
            $this->resolve();
            $this->assertSame(1, $game->objects[$id]->controller);
            $this->assertSame(0, $game->stack[0]['controller']);
            $game = $this->reload();
            $this->resolve();
            $this->assertSame(max(0, $counters - 1), $game->objects[$id]->counter('fade'));
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id), 'A player cannot sacrifice a permanent controlled by another player.');
            $this->assertSame([], $game->stack);
        }
    }

    public function testRemovingSourceInResponseInvalidatesItsSelfReference(): void
    {
        foreach (['Unsummon' => 'hand', 'Murder' => 'graveyard'] as $spell => $zone) {
            $game = $this->newGame();
            $id = $this->put(0, 'Cloudskate', GameObject::BATTLEFIELD);
            $this->lands(0, $spell === 'Murder' ? 'Swamp' : 'Island', 3);
            $removal = $this->hand(0, $spell);
            $this->passUntil(Step::Upkeep, 3);
            $game->cast(0, $removal, 0, ["o:{$id}"]);
            $this->resolve();
            $incarnation = $game->objects[$id]->incarnation;
            $game = $this->reload();
            $this->resolve();
            $this->assertSame($zone, $this->zone($id));
            $this->assertSame($incarnation, $game->objects[$id]->incarnation);
            $this->assertSame([], $game->objects[$id]->counters);
            $this->assertSame([], $game->stack);
        }
    }

    public function testBlinkResetsCountersAndOldTriggerCannotTouchNewIncarnation(): void
    {
        $game = $this->newGame();
        $id = $this->put(0, 'Cloudskate', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 2);
        $blink = $this->put(0, 'Momentary Blink', GameObject::HAND);
        $this->passUntil(Step::Draw, 7);
        $this->passUntil(Step::Upkeep, 9);
        $this->assertSame(0, $game->objects[$id]->counter('fade'));
        $old = $game->objects[$id]->incarnation;
        $game->cast(0, $blink, 0, ["o:{$id}"]);
        $this->resolve();
        $this->assertGreaterThan($old, $game->objects[$id]->incarnation);
        $this->assertSame(3, $game->objects[$id]->counter('fade'));
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(3, $game->objects[$id]->counter('fade'), 'The old upkeep trigger must not remove the returned creature\'s counters.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->passUntil(Step::Draw, 11);
        $this->assertSame(2, $game->objects[$id]->counter('fade'));
    }

    public function testMultipleInstancesEachAddCountersAndTrigger(): void
    {
        $game = $this->newGame();
        $id = $game->addCard(0, self::fixture("Fading 1\nFading 1"), GameObject::BATTLEFIELD)->id;
        $this->assertSame(2, $game->objects[$id]->counter('fade'));
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(2, $game->stack);
        $this->resolve();
        $this->assertSame(1, $game->objects[$id]->counter('fade'));
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(0, $game->objects[$id]->counter('fade'));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->passUntil(Step::Upkeep, 5);
        $this->assertCount(2, $game->stack);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $incarnation = $game->objects[$id]->incarnation;
        $this->resolve();
        $this->assertSame($incarnation, $game->objects[$id]->incarnation, 'The second trigger cannot sacrifice the departed object again.');
    }

    public function testVanishingKeepsItsDistinctTimeCounterLifecycle(): void
    {
        $game = $this->newGame();
        $fader = $this->put(0, 'Blastoderm', GameObject::BATTLEFIELD);
        $vanisher = $this->put(0, 'Calciderm', GameObject::BATTLEFIELD);
        $this->assertSame(3, $game->objects[$fader]->counter('fade'));
        $this->assertSame(4, $game->objects[$vanisher]->counter('time'));
        $this->assertSame(0, $game->objects[$vanisher]->counter('fade'));
        $this->passUntil(Step::Draw, 7);
        $this->assertSame(0, $game->objects[$fader]->counter('fade'));
        $this->assertSame(1, $game->objects[$vanisher]->counter('time'));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($fader));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($vanisher));
        $game = $this->reload();
        $this->passUntil(Step::Draw, 9);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fader));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($vanisher));
    }
}
