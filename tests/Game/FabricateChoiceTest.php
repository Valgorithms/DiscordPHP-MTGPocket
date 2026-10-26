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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class FabricateChoiceTest extends GameTestCase
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

    private function castMaster(int $seat = 0): int
    {
        $this->newGame();
        $this->game->active = $this->game->priority = $seat;
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-observer-marionette-master.json'));
        $this->assertSame('7f7ce7cdddcd200976510eebe22ccead17a0e7eea68ff7c179e990f02f05b335', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $lands = $this->lands($seat, 'Swamp', 6);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertNull($this->game->choiceAwaiting(), 'The choice is made when the ETB ability resolves, not on entry.');
        $this->assertCount(1, $this->game->stack);

        return $id;
    }

    private function servos(int $seat): array
    {
        return array_values(array_filter($this->game->permanents($seat), fn (GameObject $object) => $object->definition()->isToken() && in_array('Servo', $object->definition()->subtypes, true)));
    }

    /** @dataProvider choices */
    public function testPaidEntryChoicesAndJsonReplay(int $seat, string $option): void
    {
        $id = $this->castMaster($seat);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame('fabricate', $this->game->decision($seat));
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['counters', 'servos'], $choice['options']);
        $pending = $this->saved();
        $this->game->chooseFabricate($seat, $choice['id'], $option);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($option === 'counters' ? 4 : 1, $this->game->power($this->game->objects[$id]));
        $this->assertCount($option === 'servos' ? 3 : 0, $this->servos($seat));
        foreach ($this->servos($seat) as $servo) {
            $this->assertSame($seat, $servo->owner);
            $this->assertSame([1, 1], [$this->game->power($servo), $this->game->toughness($servo)]);
            $this->assertSame([], $servo->definition()->colors);
            $this->assertTrue($servo->definition()->is('Artifact'));
        }
        $expected = $this->saved();
        foreach ([$queued, $pending] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->choiceAwaiting() === null) {
                $this->resolveOne();
            }
            $this->game->chooseFabricate($seat, $this->game->choiceAwaiting()['id'], $option);
            $this->assertSame($expected, $this->saved());
        }
    }

    public static function choices(): array
    {
        return [[0, 'counters'], [0, 'servos'], [1, 'counters'], [1, 'servos']];
    }

    public function testSourceRemovedInResponseCreatesServosWithoutAChoice(): void
    {
        $id = $this->castMaster();
        $this->lands(0, 'Island', 1);
        $this->game->cast(0, $this->hand(0, 'Unsummon'), 0, ["o:{$id}"]);
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($id));
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount(3, $this->servos(0));
        $this->assertSame(0, $this->game->objects[$id]->counter('+1/+1'));
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('The action must be rejected.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    public function testInvalidWrongPlayerStaleAndRepeatedAnswersCannotChangeState(): void
    {
        $this->castMaster();
        $this->resolveOne();
        $id = $this->game->choiceAwaiting()['id'];
        $this->reject(fn () => $this->game->chooseFabricate(1, $id, 'servos'));
        $this->reject(fn () => $this->game->chooseFabricate(0, $id + 1, 'servos'));
        $this->reject(fn () => $this->game->chooseFabricate(0, $id, 'both'));
        $this->reject(fn () => $this->game->pass(0));
        $this->game->chooseFabricate(0, $id, 'servos');
        $this->reject(fn () => $this->game->chooseFabricate(0, $id, 'servos'));
    }

    /** @dataProvider choices */
    public function testTriggerControllerChoosesAfterControlChanges(int $seat, string $option): void
    {
        $id = $this->castMaster($seat);
        $this->game->objects[$id]->controller = 1 - $seat;
        $this->resolveOne();
        $this->assertSame('fabricate', $this->game->decision($seat));
        $this->assertNull($this->game->decision(1 - $seat));
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseFabricate($seat, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame($option === 'counters' ? 3 : 0, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertCount($option === 'servos' ? 3 : 0, $this->servos($seat));
        $this->assertSame([], $this->servos(1 - $seat));
    }

    public function testReturnedIncarnationDoesNotReceiveOldCounters(): void
    {
        $id = $this->castMaster();
        $this->lands(0, 'Island', 1);
        $this->game->cast(0, $this->hand(0, 'Unsummon'), 0, ["o:{$id}"]);
        $this->resolveOne();
        // Bounded identity control: return without a second entry trigger.
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$id], 0, false);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(0, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertCount(3, $this->servos(0));
    }

    public function testPracticeBotAnswersPersistedChoice(): void
    {
        $id = $this->castMaster(1);
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->assertTrue(PracticeBot::act($this->game, 1));
        $this->assertSame(3, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testServoCreationIsOneBatchAndThreeCreatureEntries(): void
    {
        $this->castMaster();
        foreach (['Whenever you create one or more tokens, you gain 1 life.', 'Whenever another creature enters, you gain 1 life.'] as $text) {
            $observer = $this->game->addCard(0, ['name' => 'Synthetic Servo observer', 'type' => 'Enchantment', 'text' => $text], GameObject::BATTLEFIELD);
            $this->assertSame([], $observer->definition()->unsupported);
        }
        $this->resolveOne();
        $this->game->chooseFabricate(0, $this->game->choiceAwaiting()['id'], 'servos');
        $this->assertCount(4, $this->game->stack);
        for ($i = 0; $this->game->stack !== [] && $i < 10; $i++) {
            $this->resolveOne();
        }
        $this->assertSame(24, $this->life(0));
    }

    public function testTwoPendingChoicesHaveDifferentIdsAndRetainTheirContinuation(): void
    {
        $id = $this->castMaster();
        $top = count($this->game->stack) - 1;
        // Synthetic two-step continuation; the printed card has just one Fabricate.
        $this->game->stack[$top]['effects'][] = ['type' => 'fabricate', 'amount' => 1, 'self' => true];
        $this->resolveOne();
        $first = $this->game->choiceAwaiting()['id'];
        $this->game->chooseFabricate(0, $first, 'counters');
        $second = $this->game->choiceAwaiting()['id'];
        $this->assertGreaterThan($first, $second);
        $this->reject(fn () => $this->game->chooseFabricate(0, $first, 'servos'));
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseFabricate(0, $second, 'servos');
        $this->assertSame(3, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertCount(1, $this->servos(0));
    }

    /** @dataProvider choices */
    public function testFabricateFeedsTheExistingArtifactDeathAndPowerRules(int $seat, string $option): void
    {
        $id = $this->castMaster($seat);
        $this->resolveOne();
        $this->game->chooseFabricate($seat, $this->game->choiceAwaiting()['id'], $option);
        $this->battlefield($seat, 'Ornithopter');
        $this->battlefield($seat, 'Ornithopter');
        $this->lands($seat, 'Swamp', 4);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR);
        $spell = $this->game->addCard($seat, $fixture['cards']['Damnation']['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $spell);
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        for ($i = 0; $this->game->stack !== [] && $i < 10; $i++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
        $this->assertSame($option === 'counters' ? 12 : 15, $this->life(1 - $seat));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    public function testNestedResolutionContinuesExactlyOnceAfterJsonChoice(): void
    {
        $this->castMaster();
        // Synthetic continuation probe, not a claim about another printed card.
        $top = count($this->game->stack) - 1;
        $fabricate = $this->game->stack[$top]['effects'];
        $this->game->stack[$top]['effects'] = [['type' => 'pay', 'cost' => '{0}', 'then' => [...$fabricate, ['type' => 'gain_life', 'amount' => 1]]]];
        $this->game->stack[$top]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $this->resolveOne();
        $this->assertSame(20, $this->life(0));
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseFabricate(0, $this->game->choiceAwaiting()['id'], 'servos');
        $this->assertSame(23, $this->life(0));
        $this->assertCount(3, $this->servos(0));
    }

    /** @dataProvider malformedChoices */
    public function testMalformedSavedChoicesAreRejected(string $key, mixed $value): void
    {
        $this->castMaster();
        $this->resolveOne();
        $saved = $this->saved();
        $path = explode('.', $key);
        $field = &$saved;
        foreach ($path as $part) {
            $field = &$field[$part];
        }
        $field = $value;
        unset($field);
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    public static function malformedChoices(): array
    {
        return [
            ['schemaVersion', 6], ['nextChoiceId', 1], ['pendingChoice.id', 0], ['pendingChoice.seat', 9],
            ['pendingChoice.count', -1], ['pendingChoice.count', '3'], ['pendingChoice.options', ['counters']],
            ['pendingChoice.object', 0], ['pendingChoice.incarnation', 900], ['pendingChoice.label', null],
            ['pendingChoice.resume', null], ['pendingChoice.resume.item', []], ['pendingChoice.resume.self', null],
            ['pendingChoice.resume.steps', 'bad'], ['pendingChoice.resume.steps', [[[], null]]],
            ['pendingChoice.resume.followed', [0 => 1]],
        ];
    }

    public function testVersionSixGameWithoutNewChoiceMigrates(): void
    {
        $this->newGame();
        $saved = $this->saved();
        $saved['schemaVersion'] = 6;
        $this->game = Game::fromArray($saved);
        $this->assertSame(Game::STATE_VERSION, $this->game->toArray()['schemaVersion']);
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testVersionSixAlreadyEncodedCounterAbilityKeepsItsHistoricalEffect(): void
    {
        $id = $this->castMaster();
        $saved = $this->saved();
        $saved['schemaVersion'] = 6;
        $saved['stack'][0]['effects'] = [['type' => 'counters', 'amount' => 3, 'self' => true]];
        $this->game = Game::fromArray($saved);
        $this->resolveOne();
        $this->assertSame(3, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertNull($this->game->choiceAwaiting(), 'Old encoded effects are preserved; this is not retroactive Fabricate certification.');
    }
}
