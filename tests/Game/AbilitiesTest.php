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
 * Triggered and activated abilities, loyalty abilities, Equipment and tokens.
 *
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 * @covers \MTGPocket\Game\CardDefinition
 */
final class AbilitiesTest extends GameTestCase
{
    public function testReadingAbilities(): void
    {
        $kavu = new CardDefinition(self::card('Flametongue Kavu'));
        $this->assertSame('enters', $kavu->triggered[0]['event']);
        $this->assertSame([['type' => 'damage', 'amount' => 4, 'target' => 'creature']], $kavu->triggered[0]['effects'], '"it" is the Kavu.');
        $this->assertSame([], $kavu->unsupported);

        $traveler = new CardDefinition(self::card('Doomed Traveler'));
        $this->assertSame('dies', $traveler->triggered[0]['event']);
        $token = $traveler->triggered[0]['effects'][0];
        $this->assertSame('token', $token['type']);
        $this->assertSame(['Creature'], $token['token']['types']);
        $this->assertSame(['Spirit'], $token['token']['subtypes']);
        $this->assertSame(['W'], $token['token']['colors']);
        $this->assertSame('Flying', $token['token']['text']);

        $caves = new CardDefinition(self::card('Bloodfell Caves'));
        $this->assertTrue($caves->entersTapped);
        $this->assertSame(['B', 'R'], $caves->manaAbility['colors']);
        $this->assertSame('enters', $caves->triggered[0]['event']);

        $leader = new CardDefinition(self::card('Raid Leader'));
        $this->assertSame(['attacks', 'combat_damage'], array_column($leader->triggered, 'event'));
        $this->assertSame('upkeep', (new CardDefinition(self::card('Dark Tutelage')))->triggered[0]['event']);

        $pyromancer = new CardDefinition(self::card('Prodigal Pyromancer'));
        $this->assertSame(['tap' => true], $pyromancer->activated[0]['cost']);
        $this->assertSame([['type' => 'damage', 'amount' => 1, 'target' => 'any']], $pyromancer->activated[0]['effects']);

        $this->assertSame(['sacrifice' => true], (new CardDefinition(self::card('Bottle Gnomes')))->activated[0]['cost']);
        $cub = (new CardDefinition(self::card('Wild Cub')))->activated[0];
        $this->assertSame([['mana' => '{G}'], true, false], [$cub['cost'], $cub['once'], $cub['sorcery']]);

        $chandra = new CardDefinition(self::card('Chandra, Pyrogenius'));
        $this->assertSame([2, -3], array_map(fn ($a) => $a['cost']['loyalty'], $chandra->activated));
        $this->assertCount(1, $chandra->unsupported, 'The −10 is not read yet.');

        $bonesplitter = new CardDefinition(self::card('Bonesplitter'));
        $this->assertTrue($bonesplitter->isEquipment());
        $this->assertSame(['power' => 2, 'toughness' => 0, 'keywords' => []], $bonesplitter->equipment);
        $this->assertSame(['mana' => '{1}'], $bonesplitter->activated[0]['cost']);
        $this->assertTrue($bonesplitter->activated[0]['sorcery']);

        $this->assertSame('token', (new CardDefinition(self::card('Raise the Alarm')))->effects[0]['type']);

        // A cost or effect the engine cannot read leaves the whole ability unsupported.
        $odd = new CardDefinition(['name' => 'Odd', 'type' => 'Creature — Ooze', 'manaCost' => '{G}', 'power' => '1', 'toughness' => '1', 'text' => "{X}, {T}: Draw X cards.\nDiscard a card: Draw a card.\nWhen Odd enters, investigate."]);
        $this->assertSame([], $odd->activated);
        $this->assertSame([], $odd->triggered);
        $this->assertCount(3, $odd->unsupported);
    }

    public function testEntersTriggerWithoutAChoiceGoesOnTheStackByItself(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 2);
        $visionary = $this->hand(0, 'Elvish Visionary');
        $game->cast(0, $visionary);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($visionary));
        $this->assertCount(1, $game->stack);
        $this->assertTrue(Game::isAbility($game->stack[0]));
        $this->assertSame("Elvish Visionary's ability", $game->stack[0]['label']);
        $this->assertSame(0, $game->priority);

        $game->pass(0);
        $game->pass(1);
        $this->assertSame([], $game->stack);
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testTriggerTargetsAreChosenByItsController(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $kavu = $this->hand(0, 'Flametongue Kavu');
        $game->cast(0, $kavu);
        $game->pass(0);
        $game->pass(1);

        $this->assertSame('trigger', $game->decision(0));
        $this->assertNull($game->decision(1));
        $this->assertSame([0], $game->waitingOn());
        $this->assertSame(['o:'.$bears, 'o:'.$kavu], $game->targetOptions(0, $game->triggerAwaitingTargets()['kinds'][0]));
        $this->assertThrows(fn () => $game->pass(0), 'priority');
        $this->assertThrows(fn () => $game->chooseTriggerTargets(0, ['p:1']), 'not a legal target');

        $game->chooseTriggerTargets(0, ["o:{$bears}"]);
        $this->assertSame('priority', $game->decision(0));
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
    }

    public function testATriggerWithOnlyItsSourceToTargetMustTargetIt(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $kavu = $this->hand(0, 'Flametongue Kavu');
        $game->cast(0, $kavu);
        $game->pass(0);
        $game->pass(1);
        // The only creature is the Kavu, so it targets itself (rule 603.3d needs a legal target if there is one).
        $this->assertSame(['o:'.$kavu.':'.$game->objects[$kavu]->incarnation], $game->stack[0]['targets']);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($kavu));
    }

    public function testDiesTriggerMakesATokenThatCeasesToExistWhenItLeaves(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Swamp', 3);
        $traveler = $this->battlefield(0, 'Doomed Traveler');
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$traveler}"]);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($traveler));
        $this->assertSame("Doomed Traveler's ability", $game->stack[0]['label']);
        $game->pass(0);
        $game->pass(1);

        $tokens = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->definition()->isToken()));
        $this->assertCount(1, $tokens);
        $spirit = $tokens[0];
        $this->assertSame('Spirit Token', $spirit->name());
        $this->assertSame(0, $spirit->owner);
        $this->assertTrue($game->hasKeyword($spirit, 'flying'));
        $this->assertSame([1, 1], [$game->power($spirit), $game->toughness($spirit)]);

        $murder = $this->hand(0, 'Murder');
        $game->cast(0, $murder, 0, ["o:{$spirit->id}"]);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GONE, $spirit->zone);
        $this->assertNotContains($spirit->id, $game->players[0]->graveyard);
        $this->assertNotContains($spirit->id, $game->battlefield);
    }

    public function testLandEntersTrigger(): void
    {
        $game = $this->newGame();
        $caves = $this->hand(0, 'Bloodfell Caves');
        $game->playLand(0, $caves);
        $this->assertTrue($game->objects[$caves]->tapped);
        $this->assertCount(1, $game->stack);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(21, $this->life(0));
    }

    public function testAttackAndCombatDamageTriggers(): void
    {
        $game = $this->newGame();
        $leader = $this->battlefield(0, 'Raid Leader');
        $this->passUntil(Step::DeclareAttackers);
        $game->declareAttackers(0, [$leader]);
        $this->assertCount(1, $game->stack);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(21, $this->life(0));

        $this->passUntil(Step::CombatDamage);
        $this->assertSame(18, $this->life(1));
        $this->assertCount(1, $game->stack);
        $game->pass(0);
        $game->pass(1);
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testUpkeepTrigger(): void
    {
        $game = $this->newGame();
        $this->battlefield(0, 'Dark Tutelage');
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->stack, "Only on its controller's upkeep.");
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(1, $game->stack);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertCount(2, $game->players[0]->hand, 'One from the ability, one from the draw.');
        $this->assertSame(19, $this->life(0));
    }

    public function testTapAbility(): void
    {
        $game = $this->newGame();
        $pyromancer = $this->battlefield(0, 'Prodigal Pyromancer');
        $this->assertSame([[$pyromancer, 0]], $game->activatableAbilities(0));
        $this->assertThrows(fn () => $game->activate(0, $pyromancer, 0, []), 'needs 1 target');

        $game->activate(0, $pyromancer, 0, ['p:1']);
        $this->assertTrue($game->objects[$pyromancer]->tapped);
        $this->assertSame([], $game->activatableAbilities(0));
        $this->assertThrows(fn () => $game->activate(0, $pyromancer, 0, ['p:1']), 'is tapped');
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(19, $this->life(1));

        $sick = $game->addCard(0, self::card('Prodigal Pyromancer'), GameObject::BATTLEFIELD);
        $sick->sick = true;
        $this->assertThrows(fn () => $game->activate(0, $sick->id, 0, ['p:1']), 'since your turn began');
        $this->assertThrows(fn () => $game->activate(1, $pyromancer, 0, ['p:0']), 'priority');
    }

    public function testSacrificeAndOnceEachTurn(): void
    {
        $game = $this->newGame();
        $gnomes = $this->battlefield(0, 'Bottle Gnomes');
        $game->activate(0, $gnomes, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($gnomes), 'Sacrificed as a cost.');
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(23, $this->life(0));

        $this->lands(0, 'Forest', 2);
        $cub = $this->battlefield(0, 'Wild Cub');
        $game->activate(0, $cub, 0);
        $this->assertThrows(fn () => $game->activate(0, $cub, 0), 'already activated');
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(2, $game->power($game->objects[$cub]));
    }

    public function testLoyaltyAbilities(): void
    {
        $game = $this->newGame();
        $chandra = $this->battlefield(0, 'Chandra, Pyrogenius');
        $this->assertSame(5, $game->objects[$chandra]->counter('loyalty'));
        $bears = $this->battlefield(1, 'Grizzly Bears');

        $game->activate(0, $chandra, 0);
        $this->assertSame(7, $game->objects[$chandra]->counter('loyalty'));
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(18, $this->life(1));
        $this->assertThrows(fn () => $game->activate(0, $chandra, 1, ["o:{$bears}"]), 'already activated a loyalty ability');

        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertThrows(fn () => $game->activate(0, $chandra, 1, ["o:{$bears}"]), 'do not have priority');
        $this->passUntil(Step::PrecombatMain, 3);
        $game->activate(0, $chandra, 1, ["o:{$bears}"]);
        $this->assertSame(4, $game->objects[$chandra]->counter('loyalty'));
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
    }

    public function testEquipment(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $bonesplitter = $this->battlefield(0, 'Bonesplitter');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->assertThrows(fn () => $game->activate(0, $bonesplitter, 0, ["o:{$theirs}"]), 'not a legal target');

        $game->activate(0, $bonesplitter, 0, ["o:{$bears}"]);
        $this->assertThrows(fn () => $game->activate(0, $bonesplitter, 0, ["o:{$bears}"]), 'only in your own main phase');
        $game->pass(0);
        $game->pass(1);
        $this->assertSame($bears, $game->objects[$bonesplitter]->attachedTo);
        $this->assertSame([4, 2], [$game->power($game->objects[$bears]), $game->toughness($game->objects[$bears])]);

        // The creature dies; the Equipment stays, unattached.
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$bears}"]);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bonesplitter));
        $this->assertNull($game->objects[$bonesplitter]->attachedTo);
    }

    public function testTokensFromASpell(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 2);
        $this->lands(0, 'Island', 1);
        $game->cast(0, $this->hand(0, 'Raise the Alarm'));
        $game->pass(0);
        $game->pass(1);
        $soldiers = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Soldier Token'));
        $this->assertCount(2, $soldiers);
        $this->assertTrue($soldiers[0]->sick);

        $game->cast(0, $this->hand(0, 'Unsummon'), 0, ["o:{$soldiers[0]->id}"]);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GONE, $soldiers[0]->zone);
        $this->assertSame([], $game->players[0]->hand);
    }

    public function testSavingWithAbilitiesPending(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $this->battlefield(1, 'Grizzly Bears');
        $pyromancer = $this->battlefield(0, 'Prodigal Pyromancer');
        $game->cast(0, $this->hand(0, 'Flametongue Kavu'));
        $game->activate(0, $pyromancer, 0, ['p:1']);
        $this->assertCount(2, $game->stack);
        $this->assertRoundTrip($game);

        for ($i = 0; $i < 4; $i++) {
            $game->pass($game->priority);
        }
        $this->assertSame('trigger', $game->decision(0));
        $copy = $this->assertRoundTrip($game);
        $this->assertSame('trigger', $copy->decision(0));
    }

    private function assertRoundTrip(Game $game): Game
    {
        $copy = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(json_encode($game->toArray()), json_encode($copy->toArray()));

        return $copy;
    }

    public function testAutoPassStopsForAbilitiesOnlyWhenThereIsSomethingToDo(): void
    {
        $game = $this->newGame(true);
        $this->battlefield(1, 'Prodigal Pyromancer');
        $mountain = $this->hand(0, 'Mountain');
        // Bob's untapped Pyromancer does not stop him in Alice's turn with an empty stack…
        $game->pass(0);
        $this->assertSame(0, $game->priority);
        $this->assertSame(Step::PostcombatMain, $game->step);

        // …but it does when there is a spell to respond to.
        $shock = $this->hand(0, 'Shock');
        $game->playLand(0, $mountain);
        $game->cast(0, $shock, 0, ['p:1']);
        $this->assertSame(1, $game->priority);
    }

    private function assertThrows(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected an error containing \"{$message}\".");
        } catch (GameException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
