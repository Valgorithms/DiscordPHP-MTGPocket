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

/** @covers \MTGPocket\Game\Game */
final class QuestGembladesTest extends GameTestCase
{
    private function cardData(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-gemblades.json'));
        $this->assertSame('4ff5e1ed95dbf1af435c6dff68f579186948f8381afa31b13aa54890d836948e', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'];

        return array_values(array_filter($cards, fn (array $card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function quest(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $mana = $this->lands($actor, 'Forest', 2);
        $card = $this->cardData('Quest for the Gemblades');
        $this->assertSame('72d2c199-508a-566e-a058-bb604d7817ac', $card['uuid']);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function goblin(int $actor): int
    {
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $source = $this->game->addCard($actor, $this->cardData('Raging Goblin'), GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();

        return $source;
    }

    private function blocked(int $actor, array $attackers, array $blockers): void
    {
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($actor, $attackers);
        $this->resolveOne();
        if ($this->game->decision(1 - $actor) === 'block') {
            $this->game->declareBlockers(1 - $actor, $blockers);
        } else {
            $this->assertSame([], $blockers, 'Only an empty blocker selection may already be automatic.');
        }
        $this->passUntil(Step::CombatDamage);
    }

    /** @dataProvider actors */
    public function testPaidCombatOffersOptionalQuestCounter(int $actor): void
    {
        $source = $this->quest($actor);
        $attacker = $this->goblin($actor);
        $blocker = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->blocked($actor, [$attacker], [$blocker => $attacker]);
        $this->assertSame(1, $this->game->objects[$blocker]->damage);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($attacker));
        $this->assertCount(1, $this->game->stack, 'Actual combat damage to a creature must queue the optional Quest trigger.');
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider targets */
    public function testOneCounterSacrificePutsFourCountersOnEitherPlayersCreature(int $actor, int $targetSeat): void
    {
        $source = $this->quest($actor);
        // Isolate the activation baseline; the counter and target are explicitly seeded.
        $this->game->objects[$source]->addCounters('quest', 1);
        $target = $this->game->addCard($targetSeat, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD)->id;
        $this->game->activate($actor, $source, 0, ['o:'.$target]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertSame(0, $this->game->objects[$target]->counter('+1/+1'));
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(4, $this->game->objects[$target]->counter('+1/+1'));
        $this->assertSame([6, 6], [$this->game->power($this->game->objects[$target]), $this->game->toughness($this->game->objects[$target])]);
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function targets(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid Quest actions must reject atomically.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    private function answer(int $actor, string $answer = 'accept'): void
    {
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], $answer);
    }

    private function earn(int $actor): void
    {
        $attacker = $this->goblin($actor);
        $blocker = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->blocked($actor, [$attacker], [$blocker => $attacker]);
    }

    public function testCompleteCachedRecordReadsTheBoundedQuestContract(): void
    {
        $definition = new CardDefinition($this->cardData('Quest for the Gemblades'));
        $this->assertSame([], $definition->unsupported);
        $this->assertCount(1, $definition->triggersOn('your_creature_combat_damages_creature'));
        $this->assertCount(1, $definition->activated);
        $this->assertSame(['quest', 1], $definition->activated[0]['cost']['remove']);
        $this->assertTrue($definition->activated[0]['cost']['sacrifice']);
    }

    /** @dataProvider targets */
    public function testPaidEarnedCounterPaysCostBeforeSavedTargetedResolution(int $actor, int $targetSeat): void
    {
        $source = $this->quest($actor);
        $target = $this->game->addCard($targetSeat, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD)->id;
        $this->earn($actor);
        $this->answer($actor);
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
        $this->game->activate($actor, $source, 0, ['o:'.$target]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$target]->counter('+1/+1'));
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(4, $this->game->objects[$target]->counter('+1/+1'));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['o:'.$target]));
    }

    /** @dataProvider actors */
    public function testActivationRejectsWrongCostsControllersAndTargetsBeforeSacrifice(int $actor): void
    {
        $source = $this->quest($actor);
        $target = $this->game->addCard($actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD)->id;
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['o:'.$target]));
        $this->game->objects[$source]->addCounters('+1/+1', 1);
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['o:'.$target]));
        $this->game->objects[$source]->addCounters('quest', 1);
        $land = $this->lands($actor, 'Forest', 1)[0];
        foreach ([[], ['p:'.$actor], ['o:'.$land], ['o:999999'], ['o:'.$target, 'o:'.$target]] as $targets) {
            $this->reject(fn () => $this->game->activate($actor, $source, 0, $targets));
        }
        $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0, ['o:'.$target]));
        $protected = $this->game->addCard(1 - $actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD);
        $protected->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['hexproof']];
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['o:'.$protected->id]));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider departures */
    public function testDepartedOrReturnedActivationTargetReceivesNoCounters(int $actor, bool $returns): void
    {
        $source = $this->quest($actor);
        $this->game->objects[$source]->addCounters('quest', 1);
        $target = $this->game->addCard($actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD);
        $this->game->activate($actor, $source, 0, ['o:'.$target->id]);
        $incarnation = $target->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $target, GameObject::EXILE);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $target, $actor);
        }
        $this->assertGreaterThan($incarnation, $target->incarnation);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$target->id]->counter('+1/+1'));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
    }

    /** @dataProvider actors */
    public function testActivationKeepsLegalTargetAfterControlChangeAndSourceReturn(int $actor): void
    {
        $source = $this->quest($actor);
        $this->game->objects[$source]->addCounters('quest', 1);
        $target = $this->game->addCard($actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD);
        $this->game->activate($actor, $source, 0, ['o:'.$target->id]);
        $target->controller = 1 - $actor;
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], $actor);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(4, $this->game->objects[$target->id]->counter('+1/+1'));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testNoncombatCreatureDamageAndUnblockedCombatDoNotCount(int $actor): void
    {
        $source = $this->quest($actor);
        $target = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $bolt = $this->game->addCard($actor, $this->cardData('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->cast($actor, $bolt, 0, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($target));
        $this->assertSame([], $this->game->stack);
        $attacker = $this->goblin($actor);
        $life = $this->life(1 - $actor);
        $this->blocked($actor, [$attacker], []);
        $this->assertSame($life - 1, $this->life(1 - $actor));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider damageCases */
    public function testOnlyPositiveUnpreventedCombatDamageCounts(int $actor, string $mode, int $damage): void
    {
        $source = $this->quest($actor);
        $attacker = $this->goblin($actor);
        $blocker = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD);
        // Explicit unit setup for prevention boundaries; combat itself uses normal declarations.
        match ($mode) {
            'zero' => $this->game->objects[$attacker]->untilEndOfTurn[] = ['power' => -1, 'toughness' => 0, 'keywords' => []],
            'source' => $this->game->objects[$attacker]->preventDealing = $this->game->turn,
            'shield', 'partial', 'unpreventable' => $this->game->prevent['o:'.$blocker->id] = 1,
            'protection' => $blocker->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['protection from red']],
            'shield-counter' => $blocker->addCounters('shield', 1),
        };
        if ($mode === 'partial') {
            $this->game->objects[$attacker]->untilEndOfTurn[] = ['power' => 1, 'toughness' => 0, 'keywords' => []];
        }
        if ($mode === 'unpreventable') {
            $this->game->unpreventableTurn = $this->game->turn;
        }
        $this->blocked($actor, [$attacker], [$blocker->id => $attacker]);
        $this->assertSame($damage, $blocker->damage);
        $this->assertCount($damage > 0 ? 1 : 0, $this->game->stack);
        if ($damage > 0) {
            $this->answer($actor);
        }
        $this->assertSame($damage > 0 ? 1 : 0, $this->game->objects[$source]->counter('quest'));
    }

    public static function damageCases(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach (['zero' => 0, 'source' => 0, 'shield' => 0, 'protection' => 0, 'shield-counter' => 0, 'partial' => 1, 'unpreventable' => 1] as $mode => $damage) {
                $cases[] = [$actor, $mode, $damage];
            }
        }

        return $cases;
    }

    /** @dataProvider actors */
    public function testForeignOwnedCreatureUnderYourControlStillCounts(int $actor): void
    {
        $source = $this->quest($actor);
        // A foreign-owned permanent under this controller; ownership is immutable.
        $attacker = $this->game->addCard(1 - $actor, $this->cardData('Raging Goblin'), GameObject::BATTLEFIELD)->id;
        $this->game->objects[$attacker]->controller = $actor;
        $blocker = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->blocked($actor, [$attacker], [$blocker => $attacker]);
        $this->assertContains($attacker, $this->game->players[1 - $actor]->graveyard);
        $this->assertCount(1, $this->game->stack);
        $this->answer($actor);
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider observerControllers */
    public function testCombatUsesObserverAndDamageSourceControllersRatherThanOwners(int $actor, bool $transferQuest): void
    {
        $source = $this->quest($actor);
        $other = 1 - $actor;
        if ($transferQuest) {
            $this->game->objects[$source]->controller = $other;
        }
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $attacker = $this->game->addCard($actor, $this->cardData('Raging Goblin'), GameObject::BATTLEFIELD)->id;
        $this->game->objects[$attacker]->controller = $other;
        $blocker = $this->game->addCard($actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->game->objects[$blocker]->untilEndOfTurn[] = ['power' => -3, 'toughness' => 0, 'keywords' => []];
        $this->blocked($other, [$attacker], [$blocker => $attacker]);
        $this->assertSame(1, $this->game->objects[$blocker]->damage);
        $this->assertCount($transferQuest ? 1 : 0, $this->game->stack);
        if ($transferQuest) {
            $this->answer($other);
        }
        $this->assertSame($transferQuest ? 1 : 0, $this->game->objects[$source]->counter('quest'));
    }

    public static function observerControllers(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    /** @dataProvider actors */
    public function testSeparateAttackersOfferSeparateAcceptAndDeclineChoices(int $actor): void
    {
        $source = $this->quest($actor);
        $attackers = [$this->goblin($actor), $this->goblin($actor)];
        $blockers = [];
        foreach ($attackers as $attacker) {
            $blocker = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
            $blockers[$blocker] = $attacker;
        }
        $this->blocked($actor, $attackers, $blockers);
        $this->assertCount(2, $this->game->stack);
        $this->answer($actor, 'decline');
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->answer($actor);
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testPaidDoubleStrikeOffersOneChoiceInEachDamageStep(int $actor): void
    {
        $source = $this->quest($actor);
        $mana = $this->lands($actor, 'Plains', 2);
        $attacker = $this->game->addCard($actor, $this->cardData('Fencing Ace'), GameObject::HAND)->id;
        $this->game->cast($actor, $attacker);
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
        $blocker = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($actor, [$attacker]);
        $this->resolveOne();
        $this->game->declareBlockers(1 - $actor, [$blocker => $attacker]);
        $this->passUntil(Step::FirstStrikeDamage);
        $this->assertSame(1, $this->game->objects[$blocker]->damage);
        $this->assertCount(1, $this->game->stack);
        $this->answer($actor);
        $this->passUntil(Step::CombatDamage);
        $this->assertSame(2, $this->game->objects[$blocker]->damage);
        $this->assertCount(1, $this->game->stack);
        $this->answer($actor);
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
    }

    public static function departures(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    /** @dataProvider actors */
    public function testYourBlockingCreatureAlsoProducesACombatCounter(int $actor): void
    {
        $source = $this->quest($actor);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $attacker = $this->goblin(1 - $actor);
        $blocker = $this->game->addCard($actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->blocked(1 - $actor, [$attacker], [$blocker => $attacker]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($attacker));
        $this->assertCount(1, $this->game->stack);
        $this->answer($actor);
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testOneCreatureDamagingTwoBlockersOffersTwoChoices(int $actor): void
    {
        $source = $this->quest($actor);
        $attacker = $this->goblin($actor);
        $this->game->objects[$attacker]->untilEndOfTurn[] = ['power' => 3, 'toughness' => 3, 'keywords' => []];
        $first = $this->game->addCard(1 - $actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD)->id;
        $second = $this->game->addCard(1 - $actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD)->id;
        $this->blocked($actor, [$attacker], [$first => $attacker, $second => $attacker]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($first));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($second));
        $this->assertCount(2, $this->game->stack);
        $this->answer($actor);
        $this->game = Game::fromArray($this->saved());
        $this->answer($actor);
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testQueuedAndPendingChoicesKeepOriginalControllerAndRejectInvalidAnswers(int $actor): void
    {
        $source = $this->quest($actor);
        $this->earn($actor);
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($actor, $choice['seat']);
        $this->game = Game::fromArray($this->saved());
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter(1 - $actor, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'invalid'));
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept'));
    }

    /** @dataProvider sourceIncarnations */
    public function testDepartedOrReturnedQuestCannotReceiveItsOldCombatCounter(int $actor, bool $pending, bool $returns): void
    {
        $source = $this->quest($actor);
        $this->earn($actor);
        if ($pending) {
            $this->resolveOne();
        }
        $object = $this->game->objects[$source];
        $incarnation = $object->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::EXILE);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $object, $actor);
        }
        $this->assertGreaterThan($incarnation, $object->incarnation);
        $this->game = Game::fromArray($this->saved());
        if (! $pending) {
            $this->resolveOne();
        }
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertSame($returns ? GameObject::BATTLEFIELD : GameObject::EXILE, $this->zone($source));
    }

    public static function sourceIncarnations(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $pending) {
                foreach ([false, true] as $returns) {
                    $cases[] = [$actor, $pending, $returns];
                }
            }
        }

        return $cases;
    }

    /** @dataProvider actors */
    public function testLaterHexproofInvalidatesTargetWithoutRefundingPaidCost(int $actor): void
    {
        $source = $this->quest($actor);
        $this->game->objects[$source]->addCounters('quest', 1);
        $target = $this->game->addCard(1 - $actor, $this->cardData('Runeclaw Bear'), GameObject::BATTLEFIELD);
        $this->game->activate($actor, $source, 0, ['o:'.$target->id]);
        $target->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['hexproof']];
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$target->id]->counter('+1/+1'));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
    }
}
