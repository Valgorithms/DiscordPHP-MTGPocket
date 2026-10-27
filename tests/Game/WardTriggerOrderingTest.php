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

/** Explicit complete pending-group order in two seats only. @covers \MTGPocket\Game\Game */
final class WardTriggerOrderingTest extends GameTestCase
{
    private function fixture(int $seat, array $card, string $zone = GameObject::BATTLEFIELD): int
    {
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid ordering choice was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    private function mixed(int $active): array
    {
        $this->newGame();
        if ($active === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setWardExecution(true);
        $other = 1 - $active;
        $watcher = $this->fixture($active, ['name' => 'Cast Watch Unit Fixture', 'type' => 'Enchantment',
            'text' => 'Whenever a player casts a spell, you gain 1 life.']);
        $ward = $this->fixture($other, ['name' => 'Two Ward Focus Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '3',
            'text' => "Ward {1}\nWard {2}\nWhenever this creature becomes the target of a spell or ability, you gain 1 life."]);
        $this->lands($active, 'Mountain', 1);
        $this->lands($active, 'Forest', 3);
        $entry = $this->game->cast($active, $this->hand($active, 'Shock'), 0, ['o:'.$ward]);
        $this->assertSame('trigger_order', $this->game->decision($other));
        $this->assertCount(2, $this->game->stack, 'The active player puts their ordinary trigger on first.');
        $this->assertSame($watcher, $this->game->stack[1]['object']);
        $this->assertCount(3, $this->game->choiceAwaiting()['options'], 'Both Ward instances and the ordinary target trigger share the controller choice.');

        return [$entry, $ward, $watcher];
    }

    /** @dataProvider activeSeats */
    public function testControllerOrdersWardAndOrdinaryTriggersTogetherAndPersistsPartialOrder(int $active): void
    {
        [$entry, $ward, $watcher] = $this->mixed($active);
        $other = 1 - $active;
        $queue = $this->game->pendingTriggers;
        $ordinary = array_values(array_filter($queue, fn (array $trigger) => $trigger['effects'][0]['type'] === 'gain_life'))[0]['orderId'];
        $choice = $this->game->choiceAwaiting();
        $this->reject(fn () => $this->game->chooseNextTrigger($active, $choice['id'], $ordinary));
        $this->reject(fn () => $this->game->chooseNextTrigger($other, $choice['id'], 2147483647));
        $this->game->chooseNextTrigger($other, $choice['id'], $ordinary);
        $partial = $this->saved();
        $this->reject(fn () => $this->game->chooseNextTrigger($other, $choice['id'], $ordinary));
        $this->assertNotSame($choice['id'], $this->game->choiceAwaiting()['id']);
        $this->assertSame([$ordinary], $partial['wardExecution']['triggerGroup']['picks']);
        $this->game = Game::fromArray($partial);
        $choice = $this->game->choiceAwaiting();
        $oneMana = array_values(array_filter($this->game->pendingTriggers, fn (array $trigger) => ($trigger['effects'][0]['instance']['cost']['symbols'] ?? null) === '{1}'))[0]['orderId'];
        $this->game->chooseNextTrigger($other, $choice['id'], $oneMana);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount(5, $this->game->stack);
        $this->assertSame($entry, $this->game->stack[0]);
        $this->assertSame($watcher, $this->game->stack[1]['object']);
        $this->assertSame('gain_life', $this->game->stack[2]['effects'][0]['type']);
        $this->assertSame('{1}', $this->game->stack[3]['effects'][0]['instance']['cost']['symbols']);
        $this->assertSame('{2}', $this->game->stack[4]['effects'][0]['instance']['cost']['symbols']);
        $ordered = $this->saved();
        foreach ([2, 1] as $amount) {
            $this->resolveOne();
            $payment = $this->game->choiceAwaiting();
            $this->assertSame($active, $payment['seat']);
            $this->assertSame('{'.$amount.'}', $payment['instance']['cost']['symbols']);
            $this->game->chooseWardPayment($active, $payment['id'], 'accept');
        }
        $this->resolveOne();
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame([21, 21], [$this->life(0), $this->life(1)]);
        $this->assertSame(2, $this->game->objects[$ward]->damage);
        $this->assertSame(4, $this->game->players[$active]->expended);
        $expected = $this->saved();
        $this->game = Game::fromArray($ordered);
        foreach ([2, 1] as $ignored) {
            $this->resolveOne();
            $this->game->chooseWardPayment($active, $this->game->choiceAwaiting()['id'], 'accept');
        }
        $this->resolveOne();
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
    }

    public function testWardCreatedWhilePlacingTargetedTriggerWaitsForTheOlderWholeGroup(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, ['name' => 'Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '3', 'text' => 'Ward {1}']);
        $source = $this->fixture(0, ['name' => 'Two Entry Triggers Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1', 'manaCost' => '{G}',
            'text' => "When this creature enters, target creature gets +1/+1 until end of turn.\nWhen this creature enters, you gain 1 life."], GameObject::HAND);
        $this->lands(0, 'Forest', 1);
        $this->game->cast(0, $source);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $targeted = array_values(array_filter($this->game->pendingTriggers, fn (array $trigger) => $trigger['kinds'] !== []))[0]['orderId'];
        $this->game->chooseNextTrigger(0, $choice['id'], $targeted);
        $this->assertSame('trigger', $this->game->decision(0));
        $this->assertSame(2, $this->saved()['wardExecution']['triggerGroup']['remaining']);
        $this->game = Game::fromArray($this->saved());
        $entry = $this->game->chooseTriggerTargets(0, ['o:'.$ward]);
        $this->assertCount(3, $this->game->stack);
        $this->assertSame($entry, $this->game->stack[0]);
        $this->assertSame('gain_life', $this->game->stack[1]['effects'][0]['type']);
        $this->assertSame('ward_fixed', $this->game->stack[2]['effects'][0]['type']);
        $this->resolveOne();
        $this->game->chooseWardPayment(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source), 'Countering the offending trigger never moves its source creature.');
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(21, $this->life(0));
        $this->assertSame(2, $this->game->power($this->game->objects[$ward]));
    }

    /** @dataProvider malformedGroups */
    public function testMalformedSavedGroupRefusesWithoutChangingTheOriginal(string $case): void
    {
        $this->mixed(0);
        $state = $this->saved();
        $bad = $state;
        match ($case) {
            'participant-order' => $bad['wardExecution']['triggerGroup']['participants'] = [1, 0],
            'ordered-seat' => $bad['wardExecution']['triggerGroup']['ordered'] = [1],
            'unknown-pick' => $bad['wardExecution']['triggerGroup']['picks'] = [2147483647],
            'duplicate-option' => $bad['pendingChoice']['options'][1] = $bad['pendingChoice']['options'][0],
            'wrong-seat' => $bad['pendingChoice']['seat'] = 0,
            'future-choice' => $bad['pendingChoice']['id'] = $bad['nextChoiceId'],
            'missing-order-id' => $bad['pendingTriggers'][0]['orderId'] = null,
            'wrong-remaining' => $bad['wardExecution']['triggerGroup']['remaining'] = 999,
        };
        $this->reject(fn () => Game::fromArray($bad));
        $this->assertSame($state, $this->saved());
    }

    public static function activeSeats(): array
    {
        return [[0], [1]];
    }

    /** @dataProvider activeSeats */
    public function testBothControllersChooseTheirWholeGroupInActivePlayerOrder(int $active): void
    {
        $this->newGame();
        if ($active === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setWardExecution(true);
        foreach ([0, 1] as $seat) {
            for ($n = 0; $n < 2; $n++) {
                $this->fixture($seat, ['name' => "Ordinary Group {$seat} {$n} Unit Fixture", 'type' => 'Enchantment',
                    'text' => 'Whenever a player casts a spell, you gain 1 life.']);
            }
        }
        $ward = $this->fixture(1 - $active, ['name' => 'Grouped Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward {1}']);
        $this->lands($active, 'Mountain', 1);
        $this->game->cast($active, $this->hand($active, 'Shock'), 0, ['o:'.$ward]);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($active, $choice['seat']);
        $this->assertCount(2, $choice['options']);
        $this->assertCount(1, $this->game->stack);
        $this->game->chooseNextTrigger($active, $choice['id'], $choice['options'][1]);
        $this->assertSame([$active, $active, $active], array_column($this->game->stack, 'controller'));
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(1 - $active, $choice['seat']);
        $this->assertCount(3, $choice['options']);
        $saved = $this->saved();
        $this->game = Game::fromArray($saved);
        while (($choice = $this->game->choiceAwaiting()) !== null) {
            $this->game->chooseNextTrigger(1 - $active, $choice['id'], end($choice['options']));
        }
        $this->assertSame([$active, $active, $active, 1 - $active, 1 - $active, 1 - $active], array_column($this->game->stack, 'controller'));
        $this->assertSame([], $this->game->pendingTriggers);
    }

    public static function malformedGroups(): array
    {
        return array_map(fn (string $case) => [$case], ['participant-order', 'ordered-seat', 'unknown-pick', 'duplicate-option', 'wrong-seat', 'future-choice', 'missing-order-id', 'wrong-remaining']);
    }
}
