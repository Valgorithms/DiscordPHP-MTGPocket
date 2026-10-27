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
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use MTGPocket\Game\WardInstance;
use MTGPocket\Game\WardTargetingEvent;

/** Standalone model contracts; no Ward timing or enabling-card certification. @covers \MTGPocket\Game\WardInstance @covers \MTGPocket\Game\WardTargetingEvent */
final class WardTargetingModelTest extends GameTestCase
{
    private function instance(string $key = 'printed:0', int $object = 5, int $incarnation = 1, int $controller = 1, int $payer = 0): WardInstance
    {
        return WardInstance::capture($key, $object, $incarnation, $controller, ['kind' => 'mana', 'symbols' => '{2}'], $payer);
    }

    private function entry(array $targets = ['o:5:1']): array
    {
        return ['id' => 10, 'object' => 30, 'incarnation' => 2, 'controller' => 0, 'targets' => $targets];
    }

    private function event(): WardTargetingEvent
    {
        return WardTargetingEvent::publish(1, $this->entry(), ['o:5:1'], [$this->instance()]);
    }

    public function testDuplicateTargetSlotsDoNotCollapseDistinctIdenticalWardInstances(): void
    {
        $printed = $this->instance();
        $grant = $this->instance('grant:8:2:0');
        $slots = ['o:5:1', 'o:5:1', 'p:1', 's:9', '-'];
        $event = WardTargetingEvent::publish(1, $this->entry($slots), $slots, [$printed, $grant]);
        $this->assertSame(['o:5:1'], $event->targets);
        $this->assertSame([$printed, $grant], $event->instances);
        $this->assertSame($printed->cost, $grant->cost);
        $this->assertSame(['id' => 10, 'object' => 30, 'incarnation' => 2, 'controller' => 0, 'kind' => 'spell'], $event->offending);
        $this->assertSame('unselected', $event->toArray()['ordering']);
        $reversed = WardTargetingEvent::publish(1, $this->entry($slots), $slots, [$grant, $printed]);
        $this->assertSame([$grant, $printed], $reversed->instances, 'Observation order is retained, never advertised as chosen stack order.');
        $this->assertSame('unselected', $reversed->toArray()['ordering']);
    }

    public function testSamePrintedIndexOnTwoPermanentsRemainsTwoInstances(): void
    {
        $a = $this->instance();
        $b = $this->instance(object: 6);
        $slots = ['o:5:1', 'o:6:1', 'o:5:1'];
        $event = WardTargetingEvent::publish(2, $this->entry($slots), $slots, [$a, $b]);
        $this->assertSame(['o:5:1', 'o:6:1'], $event->targets);
        $this->assertSame([$a, $b], $event->instances);
    }

    public function testSnapshotsKeepValuesRatherThanMutableInputReferences(): void
    {
        $cost = ['kind' => 'life', 'amount' => 3];
        $instance = WardInstance::capture('printed:0', 5, 7, 1, $cost, 0);
        $entry = $this->entry(['o:5:7']);
        $event = WardTargetingEvent::publish(1, $entry, ['o:5:7'], [$instance]);
        $cost['amount'] = 99;
        $entry['id'] = 999;
        $entry['controller'] = 1;
        $this->assertSame(['kind' => 'life', 'amount' => 3], $event->instances[0]->cost);
        $this->assertSame([5, 7, 1, 0], [$instance->object, $instance->incarnation, $instance->controller, $instance->payer]);
        $this->assertSame(10, $event->offending['id']);
        $this->assertSame(0, $event->offending['controller']);
    }

    public function testJsonRoundTripAndAbilityShapedCopyIdentity(): void
    {
        $entry = $this->entry();
        $entry['kind'] = 'ability';
        $entry['label'] = 'A copy of a spell';
        $entry['uncounterable'] = false;
        $event = WardTargetingEvent::publish(1, $entry, ['o:5:1'], [$this->instance(), $this->instance('grant:8:2:0')]);
        $data = json_decode(json_encode($event->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($data, WardTargetingEvent::fromArray($data)->toArray());
        $this->assertSame(['id' => 10, 'object' => 30, 'incarnation' => 2, 'controller' => 0, 'kind' => 'ability'], $event->offending);
        $this->assertCount(2, $data['instances']);
    }

    /** @dataProvider paidActions */
    public function testActualPaidActionPublishesOnlyAfterSuccessWithoutQueuingWard(int $payer, bool $succeeds): void
    {
        $this->newGame();
        $controller = 1 - $payer;
        if ($controller === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/dog-walker-disguise.json'));
        $this->assertSame('27a09d399cdd6cbe3e819319bd47c28c0b08295bc635020daa55f2f4fcca973c', hash('sha256', $bytes));
        $walker = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $id = $this->game->addCard($controller, $walker, GameObject::HAND)->id;
        $this->lands($controller, 'Forest', 3);
        $this->game->cast($controller, $id, 0, [], 'morph');
        $this->game->pass($controller);
        $this->game->pass($payer);
        $source = $this->game->objects[$id];
        $this->assertSame(['mana' => '{2}'], $source->definition()->ward);
        $instance = WardInstance::capture(
            'disguise:0',
            $id,
            $source->incarnation,
            $controller,
            ['kind' => 'mana', 'symbols' => '{2}'],
            $payer
        );
        $targets = ["o:{$id}:{$source->incarnation}"];
        $this->game->pass($controller);
        // Three mana still pays the wrong upfront Ward tax; this step does not fix it.
        $this->lands($payer, 'Mountain', $succeeds ? 3 : 1);
        $shock = $this->hand($payer, 'Shock');
        $before = $this->game->toArray();
        $committed = null;
        try {
            $this->game->cast($payer, $shock, 0, $targets);
            $this->assertTrue($succeeds);
            $committed = $this->game->stack[0];
        } catch (GameException $e) {
            $this->assertFalse($succeeds);
            $this->assertStringContainsString('and ward', $e->getMessage());
            $this->assertSame($before, $this->game->toArray());
        }
        $afterAction = $this->game->toArray();
        $event = WardTargetingEvent::publish(1, $committed, $targets, [$instance]);
        $this->assertSame($afterAction, $this->game->toArray(), 'Pure model publishes no Game trigger or payment choice.');
        if (! $succeeds) {
            $this->assertNull($event);

            return;
        }
        $this->assertSame([$instance], $event->instances);
        $this->assertSame([$committed['id'], $shock, $committed['incarnation'], $payer], array_values(array_diff_key($event->offending, ['kind' => true])));
        $saved = $event->toArray();
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $source, GameObject::HAND);
        $source->controller = $payer;
        $this->assertNotSame($instance->incarnation, $source->incarnation);
        $this->assertSame($saved, WardTargetingEvent::fromArray($saved)->toArray(), 'Source departure/control change cannot alter the captured event.');
    }

    public function testFailedOrUnobservedActionProducesNoEvent(): void
    {
        $this->assertNull(WardTargetingEvent::publish(1, null, ['o:5:1'], [$this->instance()]));
        $this->assertNull(WardTargetingEvent::publish(1, $this->entry(), ['o:5:1'], []));
    }

    public function testSupportedFixedCostsArePreservedWithoutPayingThem(): void
    {
        foreach ([['kind' => 'mana', 'symbols' => '{0}'], ['kind' => 'mana', 'symbols' => '{12}{U}{C}'],
            ['kind' => 'life', 'amount' => 0], ['kind' => 'life', 'amount' => 3]] as $cost) {
            $instance = WardInstance::capture('printed:0', 5, 1, 1, $cost, 0);
            $this->assertSame($cost, $instance->cost);
            $this->assertSame($cost, WardInstance::fromArray($instance->toArray())->cost);
        }
    }

    public function testDuplicateInstanceIdentityIsRefusedRatherThanMergingGrants(): void
    {
        $this->expectException(GameException::class);
        WardTargetingEvent::publish(1, $this->entry(), ['o:5:1'], [$this->instance(), $this->instance()]);
    }

    /** @dataProvider heldCosts */
    public function testUnsupportedCostsAndStaticTaxesAreExplicitlyHeld(array $cost): void
    {
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Ward cost held');
        WardInstance::capture('printed:0', 5, 1, 1, $cost, 0);
    }

    /** @dataProvider malformedInstances */
    public function testMalformedInstanceIdentityIsNotCoerced(string $field, mixed $bad): void
    {
        $data = $this->instance()->toArray();
        $data[$field] = $bad;
        $this->expectException(GameException::class);
        WardInstance::fromArray($data);
    }

    /** @dataProvider mismatchedEvents */
    public function testEventBindingRefusesMismatchedTargetsPayerOrUnpinnedSlots(string $which): void
    {
        $entry = $this->entry();
        $before = ['o:5:1'];
        if ($which === 'target') {
            $entry['targets'] = ['o:6:1'];
        } elseif ($which === 'payer') {
            $entry['controller'] = 1;
        } elseif ($which === 'incarnation') {
            $before = $entry['targets'] = ['o:5:2'];
        } else {
            $before = $entry['targets'] = ['o:5'];
        }
        $this->expectException(GameException::class);
        WardTargetingEvent::publish(1, $entry, $before, [$this->instance()]);
    }

    /** @dataProvider malformedEvents */
    public function testMalformedOrPrematurelyOrderedSavedEventIsRefused(string $field, mixed $bad): void
    {
        $data = $this->event()->toArray();
        $data[$field] = $bad;
        $this->expectException(GameException::class);
        WardTargetingEvent::fromArray($data);
    }

    /** @dataProvider malformedCommittedEntries */
    public function testMalformedCommittedIdentityIsRefused(string $field, mixed $bad): void
    {
        $entry = $this->entry();
        $entry[$field] = $bad;
        $this->expectException(GameException::class);
        WardTargetingEvent::publish(1, $entry, ['o:5:1'], [$this->instance()]);
    }

    public static function paidActions(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    public static function heldCosts(): array
    {
        return [[['kind' => 'discard', 'count' => 1]], [['kind' => 'sacrifice', 'count' => 1]],
            [['kind' => 'mana', 'symbols' => '{X}']], [['kind' => 'mana', 'symbols' => '{W/P}']],
            [['kind' => 'mana', 'symbols' => '{R/W}']], [['kind' => 'mana', 'symbols' => '{S}']],
            [['kind' => 'mana', 'symbols' => '']], [['kind' => 'mana', 'symbols' => '{2}', 'tax' => true]],
            [['mana' => '{2}', 'tax' => true]], [['kind' => 'life', 'amount' => '3']],
            [['kind' => 'life', 'amount' => -1]], [['kind' => 'life', 'amount' => 1000001]]];
    }

    public static function malformedInstances(): array
    {
        return [['key', ''], ['object', '5'], ['object', 0], ['incarnation', -1], ['controller', '1'], ['payer', 1], ['payer', 2], ['extra', true]];
    }

    public static function mismatchedEvents(): array
    {
        return [['target'], ['payer'], ['incarnation'], ['unpinned']];
    }

    public static function malformedEvents(): array
    {
        return [['version', 2], ['eventId', '1'], ['eventId', 0], ['ordering', 'selected'], ['extra', true],
            ['instances', []], ['instances', [null]], ['targets', ['o:5:1', 'o:5:1']],
            ['targets', array_fill(0, 257, 'o:5:1')], ['instances', array_fill(0, 129, [])]];
    }

    public static function malformedCommittedEntries(): array
    {
        return [['id', 0], ['id', '10'], ['object', 0], ['incarnation', -1], ['controller', '0'],
            ['kind', null], ['kind', 'unknown'], ['targets', null]];
    }
}
