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
use MTGPocket\Game\GameException;

/** @covers \MTGPocket\Game\Game */
final class DepartedSourceStatisticsTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
    }

    private function marionette(int $controller): GameObject
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-observer-marionette-master.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], (new CardDefinition($fixture['card']))->unsupported);
        $object = $this->game->addCard(1 - $controller, $fixture['card'], GameObject::BATTLEFIELD);
        $object->controller = $controller;

        return $object;
    }

    private function damnation(int $seat): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->lands($seat, 'Swamp', 4);
        $id = $this->game->addCard($seat, $fixture['cards']['Damnation']['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $id);
    }

    /** @dataProvider modifiedOrders */
    public function testSimultaneousBurialRetainsModifiedPower(int $active, int $controller, bool $first, bool $counters): void
    {
        $this->newGame();
        $this->game->active = $this->game->priority = $active;
        $source = $first ? $this->marionette($controller) : null;
        $this->battlefield($controller, 'Ornithopter');
        $this->battlefield($controller, 'Ornithopter');
        $source ??= $this->marionette($controller);
        if ($counters) {
            $source->addCounters('+1/+1', 3);
        } else {
            $source->untilEndOfTurn[] = ['power' => 2, 'toughness' => 0, 'keywords' => []];
        }
        $power = $this->game->power($source);
        $this->assertSame($counters ? 4 : 3, $power);
        $this->damnation($active);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertCount(2, $this->game->stack);
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame(20 - 2 * $power, $this->life(1 - $controller));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            $this->resolveAll();
            $this->assertSame($expected, $this->saved());
        }
    }

    public static function modifiedOrders(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $first) {
                    foreach ([false, true] as $counters) {
                        $cases[] = [$active, $controller, $first, $counters];
                    }
                }
            }
        }

        return $cases;
    }

    public function testSurvivingOriginalSourceUsesItsPowerAtResolution(): void
    {
        $this->newGame();
        $source = $this->marionette(0);
        $source->addCounters('+1/+1', 2);
        $artifact = $this->battlefield(0, 'Ornithopter');
        $spell = ['name' => 'Artifact Removal Fixture', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => 'Destroy target artifact.'];
        $this->lands(0, 'Forest', 1);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['o:'.$artifact]);
        $this->resolveOne();
        $this->assertCount(1, $this->game->stack);
        $source->addCounters('+1/+1', 4);
        $this->assertSame(7, $this->game->power($source));
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame(13, $this->life(1));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider laterDepartures */
    public function testReturningIncarnationCannotReplaceEarlierSourcePower(bool $leaveAgain): void
    {
        $this->newGame();
        $source = $this->marionette(0);
        $source->addCounters('+1/+1', 3);
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(0, 'Ornithopter');
        $this->damnation(0);
        $this->resolveOne();
        $this->assertCount(2, $this->game->stack);
        $incarnation = $source->incarnation;
        // Inject only the later zone transitions; the paid removal and queued abilities are real.
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $source, 0, false);
        $source->addCounters('+1/+1', 7);
        $this->assertGreaterThan($incarnation, $source->incarnation);
        if ($leaveAgain) {
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $source, GameObject::EXILE);
        }
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame(12, $this->life(1));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function laterDepartures(): array
    {
        return [[false], [true]];
    }

    public function testVersionFiveMigratesWithoutInventingDepartureStatistics(): void
    {
        $this->newGame();
        $saved = $this->saved();
        $saved['schemaVersion'] = 5;
        foreach ($saved['objects'] as &$object) {
            unset($object['lastKnownStatistics']);
        }
        unset($object);
        $loaded = Game::fromArray($saved);
        $this->assertSame(6, $loaded->toArray()['schemaVersion']);
        foreach ($loaded->objects as $object) {
            $this->assertSame([], $object->lastKnownStatistics);
        }
    }

    public function testVersionSixCannotLoseItsSourceStatisticsField(): void
    {
        $saved = $this->newGame()->toArray();
        unset($saved['objects'][array_key_first($saved['objects'])]['lastKnownStatistics']);
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    /** @dataProvider malformedStatistics */
    public function testMalformedStatisticsAreRejected(mixed $statistics): void
    {
        $saved = $this->newGame()->toArray();
        $saved['objects'][array_key_first($saved['objects'])]['lastKnownStatistics'] = $statistics;
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    public static function malformedStatistics(): array
    {
        return [[null], ['invalid'], [[-1 => ['power' => 1, 'toughness' => 2]]],
            [[999 => ['power' => 1, 'toughness' => 2]]], [['bad' => ['power' => 1, 'toughness' => 2]]],
            [[0 => ['power' => 1.5, 'toughness' => 2]]], [[0 => ['power' => 1]]],
            [[0 => ['power' => 1, 'toughness' => 2, 'extra' => 3]]]];
    }


    public function testDepartureAfterTriggerUsesTheLaterPower(): void
    {
        $this->newGame();
        $source = $this->marionette(0);
        $source->addCounters('+1/+1', 2);
        $artifact = $this->battlefield(0, 'Ornithopter');
        $spell = ['name' => 'Artifact Removal Fixture', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => 'Destroy target artifact.'];
        $this->lands(0, 'Forest', 1);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['o:'.$artifact]);
        $this->resolveOne();
        $source->addCounters('+1/+1', 4);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $source, GameObject::EXILE);
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame(13, $this->life(1), 'Last-known power is captured at departure, not at trigger creation.');
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public function testTargetSelectionRetainsDepartedSourceInformation(): void
    {
        $this->newGame();
        $card = ['name' => 'Power Death Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '2', 'text' => 'When this creature dies, it deals damage equal to its power to any target.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $source->addCounters('+1/+1', 3);
        $this->damnation(0);
        $this->resolveOne();
        $this->assertNotNull($this->game->triggerAwaitingTargets());
        $waiting = $this->saved();
        $this->game->chooseTriggerTargets(0, ['p:1']);
        $this->resolveAll();
        $this->assertSame(15, $this->life(1));
        $expected = $this->saved();
        $this->game = Game::fromArray($waiting);
        $this->game->chooseTriggerTargets(0, ['p:1']);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider ownStatistics */
    public function testOwnDeathUsesLastKnownPowerOrToughness(string $statistic, int $power, int $toughness, int $counters, int $gain): void
    {
        $this->newGame();
        $card = ['name' => 'Statistic Death Fixture', 'type' => 'Creature', 'power' => (string) $power, 'toughness' => (string) $toughness, 'text' => 'When this creature dies, you gain life equal to its '.$statistic.'.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $source->addCounters('+1/+1', $counters);
        $this->damnation(0);
        $this->resolveOne();
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame(20 + $gain, $this->life(0));
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function ownStatistics(): array
    {
        return [['power', 2, 3, 3, 5], ['toughness', 2, 3, 3, 6], ['power', -2, 3, 0, 0]];
    }

}
