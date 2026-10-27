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

use MTGPocket\Game\Game;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Paid cached-card regressions, promoted from the preserved failing probe.
 * Seeded-counter activation cases are separate from the earned-counter case.
 *
 * @covers \MTGPocket\Game\Game
 */
final class QuestPureFlameTest extends GameTestCase
{
    private function cardData(string $name): array
    {
        if (in_array($name, ['Act of Aggression', 'Act of Treason', 'Prodigal Pyromancer', 'Pyroclasm', 'Hunt the Weak', 'Twin Bolt', 'Shivan Reef', 'Justice Strike', 'Shivan Dragon'], true)) {
            $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-pure-flame-interactions.json'));
            $this->assertSame('a56d6ee3028d77a615f917a4e7aa6adc6ed1e9237fa6bca816a1099538a65e21', hash('sha256', $bytes));
            $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'];

            return array_values(array_filter($cards, fn (array $card) => $card['name'] === $name))[0];
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-pure-flame.json'));
        $this->assertSame('67ab998793f431650a7a452f4e2a3b546fbc0c587320a8ed0ee51baf7743852b', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'];

        return array_values(array_filter($cards, fn (array $card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    public function testCompleteCachedQuestRecognizesOnlyTheBoundedDamageContract(): void
    {
        $definition = new CardDefinition($this->cardData('Quest for Pure Flame'));
        $this->assertSame([], $definition->unsupported);
        $this->assertCount(1, $definition->triggersOn('your_source_damages_opponent'));
        $this->assertSame(['quest', 4], $definition->activated[0]['cost']['remove']);
        $this->assertTrue($definition->activated[0]['cost']['sacrifice']);
        $this->assertSame([['type' => 'double_damage_turn']], $definition->activated[0]['effects']);
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
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $card = $this->cardData('Quest for Pure Flame');
        $this->assertSame('03fcb278-4971-58a7-860b-9999263c347b', $card['uuid']);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function bolt(int $actor, string $target): void
    {
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $spell = $this->game->addCard($actor, $this->cardData('Lightning Bolt'), GameObject::HAND)->id;
        if ($this->game->priority !== $actor) {
            $this->game->pass($this->game->priority);
        }
        $this->game->cast($actor, $spell, 0, [$target]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    /** @dataProvider actors */
    public function testPaidOpponentSpellDamageOffersOptionalQuestCounter(int $actor): void
    {
        $source = $this->quest($actor);
        $life = $this->life(1 - $actor);
        $this->bolt($actor, 'p:'.(1 - $actor));
        $this->assertSame($life - 3, $this->life(1 - $actor));
        $this->assertCount(1, $this->game->stack, 'One positive source/opponent damage event must offer one optional counter, not three.');
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testPaidCreatureCombatDamageToOpponentAlsoCounts(int $actor): void
    {
        $this->quest($actor);
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $goblin = $this->game->addCard($actor, $this->cardData('Raging Goblin'), GameObject::HAND)->id;
        $this->game->cast($actor, $goblin);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $life = $this->life(1 - $actor);
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($actor, [$goblin]);
        $this->passUntil(Step::CombatDamage);
        $this->assertSame($life - 1, $this->life(1 - $actor));
        $this->assertCount(1, $this->game->stack, 'Combat damage is also damage from a controlled source.');
    }

    /** @dataProvider targets */
    public function testPaidQuestWithSeededFourCountersActivatesPersistentTurnDoubling(int $actor, int $target): void
    {
        $source = $this->quest($actor);
        // Activation-isolation baseline only; these four counters were not earned.
        $this->game->objects[$source]->addCounters('quest', 4);
        $this->game->activate($actor, $source, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $queued = $this->saved();
        $this->resolveOne();
        $active = $this->saved();
        $life = $this->life($target);
        $this->bolt($actor, 'p:'.$target);
        $this->assertSame($life - 6, $this->life($target));
        // The effect lasts this turn; it is not consumed by the first damage event.
        $this->bolt($actor, 'p:'.$target);
        $this->assertSame($life - 12, $this->life($target));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($active, $this->saved());
        $this->bolt($actor, 'p:'.$target);
        $this->bolt($actor, 'p:'.$target);
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider negatives */
    public function testOtherRecipientsControllersAndFullyPreventedDamageDoNotCount(int $actor, string $mode): void
    {
        $source = $this->quest($actor);
        $targetSeat = $mode === 'self' ? $actor : 1 - $actor;
        $target = 'p:'.$targetSeat;
        if ($mode === 'creature') {
            $victim = $this->game->addCard(1 - $actor, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
            $target = 'o:'.$victim;
        }
        if ($mode === 'prevented') {
            $this->game->prevent[$target] = 3;
        }
        $this->bolt($mode === 'other-controller' ? 1 - $actor : $actor, $target);
        if ($mode === 'creature') {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        } else {
            $this->assertSame($mode === 'prevented' ? 20 : 17, $this->life($targetSeat));
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Illegal action accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    private function doubling(int $actor): int
    {
        $quest = $this->quest($actor);
        $this->game->objects[$quest]->addCounters('quest', 4);
        $this->game->activate($actor, $quest, 0);
        $this->assertSame([], $this->saved()['damageEffects']);
        $this->resolveOne();
        $this->assertCount(1, $this->saved()['damageEffects']);

        return $quest;
    }

    private function pendingBolt(int $actor, string $target): int
    {
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $bolt = $this->game->addCard($actor, $this->cardData('Lightning Bolt'), GameObject::HAND)->id;
        if ($this->game->priority !== $actor) {
            $this->game->pass($this->game->priority);
        }
        $this->game->cast($actor, $bolt, 0, [$target]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();

        return $bolt;
    }

    /** @dataProvider ordering */
    public function testAffectedPlayerOrdersReplacementAndPreventionWithAtomicReplay(int $actor, string $first, int $damage): void
    {
        $this->doubling($actor);
        $other = 1 - $actor;
        $this->game->prevent['p:'.$other] = 2;
        $bolt = $this->pendingBolt($actor, 'p:'.$other);
        $this->assertSame(20, $this->life($other));
        $this->assertSame(2, $this->game->prevent['p:'.$other]);
        $this->assertSame(GameObject::STACK, $this->zone($bolt));
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['damage_order', $other, ['double:1', 'prevent']], [$choice['type'], $choice['seat'], $choice['options']]);
        $this->reject(fn () => $this->game->chooseDamageOrder($actor, $choice['id'], $first));
        $this->reject(fn () => $this->game->chooseDamageOrder($other, $choice['id'] + 1, $first));
        $this->reject(fn () => $this->game->chooseDamageOrder($other, $choice['id'], 'double:2'));
        $pending = $this->saved();
        $this->game->chooseDamageOrder($other, $choice['id'], $first);
        $this->assertSame(20 - $damage, $this->life($other));
        $this->assertSame(0, $this->game->prevent['p:'.$other]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertNull($this->saved()['pendingDamage']);
        $expected = $this->saved();
        $this->reject(fn () => $this->game->chooseDamageOrder($other, $choice['id'], $first));
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder($other, $choice['id'], $first);
        $this->assertSame($expected, $this->saved());
    }

    public static function ordering(): array
    {
        return [[0, 'double:1', 4], [0, 'prevent', 2], [1, 'double:1', 4], [1, 'prevent', 2]];
    }

    /** @dataProvider paymentOrders */
    public function testPainfulManaChoiceResumesPaidCastExactlyOnce(int $actor, string $first, int $life): void
    {
        $this->doubling($actor);
        $reef = $this->game->addCard($actor, $this->cardData('Shivan Reef'), GameObject::BATTLEFIELD)->id;
        $bolt = $this->game->addCard($actor, $this->cardData('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->prevent['p:'.$actor] = 1;
        $this->game->cast($actor, $bolt, 0, ['p:'.(1 - $actor)]);
        $this->assertTrue($this->game->objects[$reef]->tapped);
        $this->assertSame(GameObject::HAND, $this->zone($bolt));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(20, $this->life($actor));
        $pending = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['damage_order', $actor], [$choice['type'], $choice['seat']]);
        $this->reject(fn () => $this->game->chooseDamageOrder(1 - $actor, $choice['id'], $first));
        $this->game->chooseDamageOrder($actor, $choice['id'], $first);
        $this->assertSame($life, $this->life($actor));
        $this->assertSame(GameObject::STACK, $this->zone($bolt));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame(2, $this->game->players[$actor]->expended);
        $this->resolveOne();
        $this->assertSame(14, $this->life(1 - $actor));
        $expected = $this->saved();
        $this->reject(fn () => $this->game->chooseDamageOrder($actor, $choice['id'], $first));
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder($actor, $choice['id'], $first);
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
    }

    public static function paymentOrders(): array
    {
        return [[0, 'double:1', 19], [0, 'prevent', 20], [1, 'double:1', 19], [1, 'prevent', 20]];
    }

    public function testPainfulManaChoiceResumesPaidActivationExactlyOnce(): void
    {
        $this->doubling(0);
        $mana = $this->lands(0, 'Mountain', 6);
        $dragon = $this->game->addCard(0, $this->cardData('Shivan Dragon'), GameObject::HAND)->id;
        $this->game->cast(0, $dragon);
        $this->resolveOne();
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $reef = $this->game->addCard(0, $this->cardData('Shivan Reef'), GameObject::BATTLEFIELD)->id;
        $this->game->prevent['p:0'] = 1;
        $this->game->activate(0, $dragon, 0);
        $this->assertTrue($this->game->objects[$reef]->tapped);
        $this->assertSame([], $this->game->stack);
        $this->assertSame(5, $this->game->power($this->game->objects[$dragon]));
        $pending = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(6, $this->game->power($this->game->objects[$dragon]));
        $this->assertSame(8, $this->game->players[0]->expended);
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
    }

    public function testMalformedPaidActionCannotResumeOrSpendFurtherCosts(): void
    {
        $this->doubling(0);
        $this->game->addCard(0, $this->cardData('Shivan Reef'), GameObject::BATTLEFIELD);
        $bolt = $this->game->addCard(0, $this->cardData('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->prevent['p:0'] = 1;
        $this->game->cast(0, $bolt, 0, ['p:1']);
        $pending = $this->saved();
        foreach ([
            function ($s) {
                $s['pendingDamage']['after']['kind'] = 'call_anything';
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['after']['x'] = [];
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['after']['options']['kicked'] = [];
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['after']['incarnation']++;
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['after']['payment']['life'] = -1;
                return $s;
            },
            function ($s) {
                $s['nextChoiceId'] = (string) $s['nextChoiceId'];
                return $s;
            },
        ] as $change) {
            $this->reject(fn () => Game::fromArray($change($pending)));
        }
    }

    /** @dataProvider selfDamageOrders */
    public function testSelfDamageUsesAffectedCreatureSourceAndCurrentRecipientController(string $first, int $amount, bool $dies): void
    {
        $this->doubling(0);
        $creature = $this->game->addCard(1, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Mountain', 3);
        $steal = $this->game->addCard(0, $this->cardData('Act of Treason'), GameObject::HAND)->id;
        $this->game->cast(0, $steal, 0, ['o:'.$creature]);
        $this->resolveOne();
        $this->assertSame([1, 0], [$this->game->objects[$creature]->owner, $this->game->objects[$creature]->controller]);
        $incarnation = $this->game->objects[$creature]->incarnation;
        $this->game->prevent['o:'.$creature] = 2;
        $this->lands(1, 'Mountain', 1);
        $this->lands(1, 'Plains', 1);
        $strike = $this->game->addCard(1, $this->cardData('Justice Strike'), GameObject::HAND)->id;
        $this->game->pass(0);
        $this->game->cast(1, $strike, 0, ['o:'.$creature]);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(0, $choice['seat'], 'The recipient controller chooses, not its owner or the spell controller.');
        $pending = $this->saved();
        $this->game->chooseDamageOrder(0, $choice['id'], $first);
        $actual = $this->game->objects[$creature];
        $this->assertSame($dies ? GameObject::GRAVEYARD : GameObject::BATTLEFIELD, $actual->zone);
        $this->assertSame($amount, $dies ? $actual->lastKnownDamage[$incarnation]['object']['damage'] : $actual->damage);
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], $first);
        $this->assertSame($expected, $this->saved());
    }

    public static function selfDamageOrders(): array
    {
        return [['double:1', 4, true], ['prevent', 2, false]];
    }

    public function testFightFreezesBothDirectionsUntilOrderChoiceAndThenCompletesOnce(): void
    {
        $this->doubling(0);
        $fighter = $this->game->addCard(0, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD);
        $victim = $this->game->addCard(1, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD);
        $this->game->prevent['o:'.$victim->id] = 2;
        $mana = $this->lands(0, 'Forest', 4);
        $spell = $this->game->addCard(0, $this->cardData('Hunt the Weak'), GameObject::HAND)->id;
        $this->game->cast(0, $spell, 0, ['o:'.$fighter->id, 'o:'.$victim->id]);
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(1, $fighter->counter('+1/+1'));
        $this->assertSame([0, 0], [$fighter->damage, $victim->damage]);
        $this->assertSame(GameObject::BATTLEFIELD, $victim->zone);
        $pending = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(1, $choice['seat']);
        $this->game->chooseDamageOrder(1, $choice['id'], 'double:1');
        $this->assertSame(3, $this->game->objects[$fighter->id]->damage, 'Return fight damage must happen before state-based deaths.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim->id));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(1, $choice['id'], 'double:1');
        $this->assertSame($expected, $this->saved());
    }

    public function testDividedBatchCanSaveBetweenTwoRecipientChoicesWithoutPartialDamage(): void
    {
        $this->doubling(0);
        $victim = $this->game->addCard(1, $this->cardData('Centaur Courser'), GameObject::BATTLEFIELD);
        $this->game->prevent['p:1'] = $this->game->prevent['o:'.$victim->id] = 1;
        $this->lands(0, 'Mountain', 2);
        $spell = $this->game->addCard(0, $this->cardData('Twin Bolt'), GameObject::HAND)->id;
        $this->game->cast(0, $spell, 0, ['p:1', 'o:'.$victim->id]);
        $this->resolveOne();
        $first = $this->game->choiceAwaiting();
        $this->game->chooseDamageOrder(1, $first['id'], 'prevent');
        $second = $this->game->choiceAwaiting();
        $this->assertGreaterThan($first['id'], $second['id']);
        $this->assertSame(20, $this->life(1));
        $this->assertSame(0, $victim->damage);
        $this->assertSame(1, $this->game->prevent['p:1']);
        $this->reject(fn () => $this->game->chooseDamageOrder(1, $first['id'], 'double:1'));
        $pending = $this->saved();
        $this->game->chooseDamageOrder(1, $second['id'], 'double:1');
        $this->assertSame(20, $this->life(1));
        $this->assertSame(1, $this->game->objects[$victim->id]->damage);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(0, $this->game->prevent['p:1']);
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(1, $second['id'], 'double:1');
        $this->assertSame($expected, $this->saved());
    }

    public function testCombatBatchWaitsThenUsesFinalDamageForLifelinkAndTriggers(): void
    {
        $this->doubling(0);
        $goblins = [];
        for ($n = 0; $n < 2; $n++) {
            $this->lands(0, 'Mountain', 1);
            $id = $this->game->addCard(0, $this->cardData('Raging Goblin'), GameObject::HAND)->id;
            $this->game->cast(0, $id);
            $this->resolveOne();
            // Isolated lifelink interaction, not a claim about another card.
            $this->game->objects[$id]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['lifelink']];
            $goblins[] = $id;
        }
        $this->game->players[0]->life = 10;
        $this->game->prevent['p:1'] = 1;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, $goblins);
        $this->passUntil(Step::CombatDamage);
        $this->assertSame([10, 20], [$this->life(0), $this->life(1)]);
        $pending = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseDamageOrder(1, $choice['id'], 'double:1');
        $this->assertSame([13, 17], [$this->life(0), $this->life(1)]);
        $this->assertSame([], $this->game->stack);
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(1, $choice['id'], 'double:1');
        $this->assertSame($expected, $this->saved());
    }

    public function testCurrentControllerOfForeignOwnedSourceAndRecipientChooses(): void
    {
        $this->doubling(0);
        $foreign = $this->game->addCard(1, $this->cardData('Raging Goblin'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 3);
        $steal = $this->game->addCard(0, $this->cardData('Act of Treason'), GameObject::HAND)->id;
        $this->game->cast(0, $steal, 0, ['o:'.$foreign->id]);
        $this->resolveOne();
        $this->assertSame([1, 0], [$foreign->owner, $foreign->controller]);
        $this->game->prevent['p:1'] = 1;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, [$foreign->id]);
        $this->passUntil(Step::CombatDamage);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(1, $choice['seat']);
        $this->game->chooseDamageOrder(1, $choice['id'], 'double:1');
        $this->assertSame(19, $this->life(1));
    }

    public function testUnpreventableDamageDoublesWithoutConsumingAnyShield(): void
    {
        $this->doubling(0);
        $this->game->prevent['p:1'] = 2;
        $this->game->unpreventableTurn = $this->game->turn;
        $this->bolt(0, 'p:1');
        $this->assertSame(14, $this->life(1));
        $this->assertSame(2, $this->game->prevent['p:1']);
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testFullPreventionDoesNotOfferMeaninglessOrder(): void
    {
        $this->doubling(0);
        $mana = $this->lands(0, 'Mountain', 1)[0];
        $pinger = $this->game->addCard(0, $this->cardData('Raging Goblin'), GameObject::HAND)->id;
        $this->game->cast(0, $pinger);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->game->objects[$pinger]->preventDealing = $this->game->turn;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, [$pinger]);
        $this->passUntil(Step::CombatDamage);
        $this->assertSame(20, $this->life(1));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testPracticeUsesTheSameValidatedOrderingAction(): void
    {
        $this->doubling(0);
        $this->game->prevent['p:1'] = 2;
        $bolt = $this->pendingBolt(0, 'p:1');
        $this->assertTrue(\MTGPocket\Tutorial\PracticeBot::act($this->game, 1));
        $this->assertSame(18, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testForgedAndOversizedDamageContinuationsRejectWithoutMutation(): void
    {
        $this->doubling(0);
        $this->game->prevent['p:1'] = 2;
        $this->pendingBolt(0, 'p:1');
        $pending = $this->saved();
        foreach ([
            function ($s) {
                $s['pendingDamage']['sources'][0]['object']['controller'] = 1;
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['batch']['events'][0]['incarnation'] = PHP_INT_MAX;
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['batch']['resources']['prevent:p:1'] = 3;
                return $s;
            },
            function ($s) {
                $s['pendingChoice']['resume']['steps'] = array_fill(0, 257, [['type' => 'draw', 'amount' => 1], null]);
                return $s;
            },
            function ($s) {
                unset($s['pendingChoice']['resume']);
                return $s;
            },
            function ($s) {
                $s['pendingChoice']['padding'] = str_repeat('x', 1048577);
                return $s;
            },
            function ($s) {
                $s['pendingDamage']['completion'] = 'run_arbitrary_method';
                return $s;
            },
            function ($s) {
                $s['damageEffects'][] = $s['damageEffects'][0];
                return $s;
            },
        ] as $change) {
            $this->reject(fn () => Game::fromArray($change($pending)));
        }
        // Live source or recipient corruption also refuses the answer atomically.
        $choice = $this->game->choiceAwaiting();
        $source = $pending['pendingDamage']['batch']['events'][0]['source'];
        $this->game->objects[$source]->incarnation++;
        $this->reject(fn () => $this->game->chooseDamageOrder(1, $choice['id'], 'prevent'));
    }

    public function testCounteredActivationInstallsNoEffectAndSacrificeIsNotReplayed(): void
    {
        $quest = $this->quest(0);
        $this->game->objects[$quest]->addCounters('quest', 4);
        $this->game->activate(0, $quest, 0);
        $item = $this->game->stack[0];
        $incarnation = $this->game->objects[$quest]->incarnation;
        // Exercise the existing counter effect primitive; ability-target parser
        // support is separate and this test does not claim support for Stifle.
        $counter = new \ReflectionMethod(Game::class, 'applyEffect');
        $counter->invoke($this->game, ['type' => 'counter'], $this->game->objects[$quest], null, 1, 0, 's:'.$item['id']);
        $this->assertSame([], $this->game->stack);
        $this->assertSame([], $this->saved()['damageEffects']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($quest));
        $this->assertSame($incarnation, $this->game->objects[$quest]->incarnation);
        $this->bolt(0, 'p:1');
        $this->assertSame(17, $this->life(1));
    }

    /** @dataProvider returnedSource */
    public function testReturnedQuestIsIndependentOfItsOldActivation(bool $countered): void
    {
        $quest = $this->quest(0);
        $this->game->objects[$quest]->addCounters('quest', 4);
        $this->game->activate(0, $quest, 0);
        $item = $this->game->stack[0];
        // Engine entry/counter primitives isolate identity; no reanimation or
        // ability-countering card is certified by this probe.
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$quest], 0);
        $incarnation = $this->game->objects[$quest]->incarnation;
        $this->assertGreaterThan($item['incarnation'], $incarnation);
        if ($countered) {
            (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke($this->game, ['type' => 'counter'], $this->game->objects[$quest], null, 1, 0, 's:'.$item['id']);
        } else {
            $this->resolveOne();
        }
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($quest));
        $this->assertSame($incarnation, $this->game->objects[$quest]->incarnation);
        $this->assertCount($countered ? 0 : 1, $this->saved()['damageEffects']);
        $this->bolt(0, 'p:1');
        $this->assertSame($countered ? 17 : 14, $this->life(1));
        $this->assertSame(0, $this->game->objects[$quest]->counter('quest'));
        $this->assertCount(1, $this->game->stack, 'The new Quest incarnation observes the new damage normally.');
    }

    public static function returnedSource(): array
    {
        return [[false], [true]];
    }

    public function testDepartedSourceUsesLastKnownControlAfterPaidTheftAndDestruction(): void
    {
        $observer = $this->quest(0);
        $this->lands(0, 'Mountain', 3);
        $pinger = $this->game->addCard(0, $this->cardData('Prodigal Pyromancer'), GameObject::HAND)->id;
        $this->game->cast(0, $pinger);
        $this->resolveOne();
        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Mountain', 1);
        $quest = $this->game->addCard(1, $this->cardData('Quest for Pure Flame'), GameObject::HAND)->id;
        $this->game->cast(1, $quest);
        $this->resolveOne();
        $this->game->objects[$quest]->addCounters('quest', 4);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->game->pass(0);
        $this->game->activate(1, $quest, 0);
        $this->resolveOne();
        $this->assertFalse($this->game->objects[$pinger]->sick);
        $this->game->activate(0, $pinger, 0, ['p:0']);
        $original = $this->game->stack[0];
        $this->assertSame(0, $original['controller']);
        $this->game->pass(0);
        $this->lands(1, 'Mountain', 5);
        $steal = $this->game->addCard(1, $this->cardData('Act of Aggression'), GameObject::HAND)->id;
        $this->game->cast(1, $steal, 0, ['o:'.$pinger]);
        $this->resolveOne();
        $this->assertSame([0, 1], [$this->game->objects[$pinger]->owner, $this->game->objects[$pinger]->controller]);
        // Isolated keyword interaction: last-known lifelink must survive departure.
        $this->game->objects[$pinger]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['lifelink']];
        $this->game->pass(0);
        $this->lands(1, 'Mountain', 1);
        $kill = $this->game->addCard(1, $this->cardData('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->cast(1, $kill, 0, ['o:'.$pinger]);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($pinger));
        $this->assertSame(0, $this->game->objects[$pinger]->controller, 'Graveyard routing resets current control to the owner.');
        $this->assertSame(1, $this->game->objects[$pinger]->lastKnownDamage[$original['incarnation']]['object']['controller']);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame([18, 22], [$this->life(0), $this->life(1)], 'Last-known control supplies the doubling and last-known lifelink recipient.');
        $this->assertSame([], $this->game->stack, 'The original controller Quest must not observe damage from the departed, stolen source.');
        $this->assertSame(0, $this->game->objects[$observer]->counter('quest'));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider actors */
    public function testFourPaidEventsEarnCountersBeforePaidSacrifice(int $actor): void
    {
        $quest = $this->quest($actor);
        $this->game->players[1 - $actor]->life = 40;
        for ($n = 1; $n <= 4; $n++) {
            $this->bolt($actor, 'p:'.(1 - $actor));
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->assertSame($n, $this->game->objects[$quest]->counter('quest'));
        }
        $this->game->activate($actor, $quest, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($quest));
        $queued = $this->saved();
        $this->assertSame([], $queued['damageEffects']);
        $this->resolveOne();
        $this->bolt($actor, 'p:'.(1 - $actor));
        $this->assertSame(22, $this->life(1 - $actor));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->bolt($actor, 'p:'.(1 - $actor));
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider actors */
    public function testActivationCostsAndExtraTargetsRejectAtomically(int $actor): void
    {
        $quest = $this->quest($actor);
        $this->reject(fn () => $this->game->activate($actor, $quest, 0));
        $this->game->objects[$quest]->addCounters('quest', 3);
        $this->reject(fn () => $this->game->activate($actor, $quest, 0));
        $this->game->objects[$quest]->addCounters('quest', 1);
        $this->reject(fn () => $this->game->activate($actor, $quest, 0, ['p:'.$actor]));
        $this->reject(fn () => $this->game->activate(1 - $actor, $quest, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($quest));
        $this->assertSame(4, $this->game->objects[$quest]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testDecliningOptionalCounterAndReturningObserverCannotReceiveOldCounter(int $actor): void
    {
        $quest = $this->quest($actor);
        $this->bolt($actor, 'p:'.(1 - $actor));
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'decline');
        $this->assertSame(0, $this->game->objects[$quest]->counter('quest'));
        $this->bolt($actor, 'p:'.(1 - $actor));
        // Simulate a new battlefield incarnation before the queued trigger resolves.
        // The identity assertion is independent from blink-card recognition.
        $this->game->objects[$quest]->incarnation++;
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(0, $this->game->objects[$quest]->counter('quest'));
    }

    public function testTwoResolvedEffectsMultiplyAndExpireAtCleanup(): void
    {
        $this->doubling(0);
        $second = $this->game->addCard(0, $this->cardData('Quest for Pure Flame'), GameObject::HAND)->id;
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $second);
        $this->resolveOne();
        $this->game->objects[$second]->addCounters('quest', 4);
        $this->game->activate(0, $second, 0);
        $this->resolveOne();
        $this->assertCount(2, $this->saved()['damageEffects']);
        $this->bolt(0, 'p:1');
        $this->assertSame(8, $this->life(1));
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame([], $this->saved()['damageEffects']);
        $this->bolt(0, 'p:1');
        $this->assertSame(5, $this->life(1));
    }

    public function testLegacyStateMigratesToEmptyAndMalformedPendingStateRejects(): void
    {
        $this->doubling(0);
        $this->game->prevent['p:1'] = 2;
        $this->pendingBolt(0, 'p:1');
        $pending = $this->saved();
        $this->assertSame($pending, Game::fromArray($pending)->toArray());
        $malformed = [];
        $bad = $pending;
        unset($bad['pendingDamage']);
        $malformed[] = $bad;
        $bad = $pending;
        $bad['pendingDamage']['batch']['selections'] = ['absent'];
        $malformed[] = $bad;
        $bad = $pending;
        $bad['pendingChoice']['seat'] = 0;
        $malformed[] = $bad;
        $bad = $pending;
        $bad['pendingDamage']['batch']['events'][0]['target'] = 'p:0';
        $malformed[] = $bad;
        $bad = $pending;
        $bad['damageEffects'][0]['factor'] = 3;
        $malformed[] = $bad;
        foreach ($malformed as $bad) {
            $this->reject(fn () => Game::fromArray($bad));
        }
        $this->newGame();
        $old = $this->saved();
        $old['schemaVersion'] = 16;
        unset($old['damageEffects'], $old['pendingDamage'], $old['nextDamageEffectId']);
        $migrated = Game::fromArray($old)->toArray();
        $this->assertSame([], $migrated['damageEffects']);
        $this->assertNull($migrated['pendingDamage']);
        $this->assertSame(1, $migrated['nextDamageEffectId']);
    }

    public static function targets(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    public static function negatives(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach (['self', 'creature', 'prevented', 'other-controller'] as $mode) {
                $cases[] = [$actor, $mode];
            }
        }

        return $cases;
    }
}
