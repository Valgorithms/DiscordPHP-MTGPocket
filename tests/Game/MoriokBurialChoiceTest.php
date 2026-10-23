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

use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class MoriokBurialChoiceTest extends GameTestCase
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

    private function rigger(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Moriok Rigger'))[0];
        $this->assertSame('5DN', $entry['setCode']);
        $card = $entry['card'];
        $this->assertSame('0bb7d751-e71c-5e5e-8a39-1de6aca509ca', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Swamp', 3);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(0, $this->game->objects[$id]->counter('+1/+1'));

        return $id;
    }

    private function shatterstorm(int $seat): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'));
        $this->assertSame('9bfd9ecb99db8022a5bc52b775f1e66c8d06ed65c849f8fd202d41a6d8c9bcae', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 4);
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
    }

    /** @dataProvider orders */
    public function testPaidRiggerAndStolenArtifactsOfferIndependentCountersWithJsonAndCleanup(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->rigger(1 - $controller) : null;
        foreach ([0, 1] as $owner) {
            $artifact = $this->battlefield($owner, 'Ornithopter');
            $this->game->objects[$artifact]->controller = 1 - $owner;
        }
        $source ??= $this->rigger(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->shatterstorm($active);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertCount(2, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame($controller, $item['controller']);
            $this->assertSame([], $item['targets']);
        }
        $queued = $this->saved();
        $this->answerTwo($source, $controller);
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if (count($this->game->stack) === 1) {
                $this->resolveOne();
            }
            $this->answerTwo($source, $controller);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function answerTwo(int $source, int $seat): void
    {
        $last = 0;
        foreach (['accept', 'decline'] as $option) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame('optional_source_counter', $this->game->decision($seat));
            $this->assertSame(['accept', 'decline'], $choice['options']);
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->assertSame($option === 'accept' ? 0 : 1, $this->game->objects[$source]->counter('+1/+1'));
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalSourceCounter($seat, $choice['id'], $option);
        }
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame([], $this->game->stack);
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame([3, 3], [$this->game->power($this->game->objects[$source]), $this->game->toughness($this->game->objects[$source])]);
        $this->assertStringContainsString(' 3/3', MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
    }

    private function buryOne(bool $pending = true): int
    {
        $this->newGame();
        $source = $this->rigger(0);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);
        if ($pending) {
            $this->resolveOne();
        }

        return $source;
    }

    public function testSuccessiveAcceptedBurialsAccumulatePermanentCounters(): void
    {
        $this->newGame();
        $source = $this->rigger(0);
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(1, 'Ornithopter');
        $this->shatterstorm(1);
        $this->resolveOne();
        foreach ([1, 2] as $count) {
            $this->resolveOne();
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
            $this->assertSame($count, $this->game->objects[$source]->counter('+1/+1'));
        }
        $this->assertSame([4, 4], [$this->game->power($this->game->objects[$source]), $this->game->toughness($this->game->objects[$source])]);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testWrongStaleInvalidCrossEffectAndRepeatedAnswersPreserveState(): void
    {
        $source = $this->buryOne();
        $choice = $this->game->choiceAwaiting();
        foreach ([[1, $choice['id'], 'accept'], [0, $choice['id'] + 1, 'accept'], [0, $choice['id'], 'invalid']] as [$seat, $id, $option]) {
            $before = $this->saved();
            try {
                $this->game->chooseOptionalSourceCounter($seat, $id, $option);
                $this->fail('An illegal answer was accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        foreach (['chooseOptionalLifeGain', 'chooseOptionalLifeLoss'] as $method) {
            $before = $this->saved();
            try {
                $this->game->$method(0, $choice['id'], 'accept');
                $this->fail('Another effect answered this decision.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->game->chooseOptionalSourceCounter(0, $choice['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $before = $this->saved();
        try {
            $this->game->chooseOptionalSourceCounter(0, $choice['id'], 'accept');
            $this->fail('A repeated answer was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider sourceMoves */
    public function testDepartedAndReturnedIncarnationsNeverReceiveAnOldCounter(string $zone, bool $returns, bool $pending): void
    {
        $source = $this->buryOne($pending);
        $old = $this->game->objects[$source]->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], $zone);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
            $this->assertNotSame($old, $this->game->objects[$source]->incarnation);
        }
        $this->game = Game::fromArray($this->saved());
        if (! $pending) {
            $this->resolveOne();
        }
        $this->assertSame(0, $this->game->choiceAwaiting()['seat']);
        $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($returns ? GameObject::BATTLEFIELD : $zone, $this->zone($source));
    }

    public static function sourceMoves(): array
    {
        $cases = [];
        foreach ([GameObject::GRAVEYARD, GameObject::HAND, GameObject::EXILE] as $zone) {
            foreach ([false, true] as $returns) {
                foreach ([false, true] as $pending) {
                    $cases[] = [$zone, $returns, $pending];
                }
            }
        }

        return $cases;
    }

    public function testOriginalControllerAnswersAfterSamePermanentChangesControl(): void
    {
        $source = $this->buryOne();
        $this->game->objects[$source]->controller = 1;
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(0, $this->game->choiceAwaiting()['seat']);
        $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(1, $this->game->objects[$source]->controller);
    }

    /** @dataProvider movements */
    public function testOnlyActualArtifactBurialOffersACounter(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $source = $this->rigger(0);
        $object = $this->game->addCard(1, ['name' => 'Counter Movement Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $object->zone);
            $this->resolveOne();
            $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
        }
        $this->assertSame($count, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function movements(): array
    {
        return [
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 1],
            ['Token Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, true, false, 1],
            ['Artifact Creature', GameObject::HAND, GameObject::GRAVEYARD, false, false, 0],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::EXILE, false, false, 0],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::HAND, false, false, 0],
            ['Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 0],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, true, 0],
        ];
    }

    public function testPracticePolicyUsesTheSamePersistedDecision(): void
    {
        $source = $this->buryOne();
        $this->game = Game::fromArray($this->saved());
        $this->assertTrue((new PracticeBot())->act($this->game, 0));
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider options */
    public function testLaterEffectsWaitAndResumeExactlyOnceEvenWhenSourceIsGone(string $option): void
    {
        $source = $this->buryOne(false);
        $this->game->stack[array_key_last($this->game->stack)]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $this->resolveOne();
        $this->assertSame(20, $this->life(0));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(22, $this->life(0));
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(22, $this->life(0));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function options(): array
    {
        return [['accept'], ['decline']];
    }

    /** @dataProvider malformed */
    public function testMalformedSavedDecisionsAreRejected(string $key, mixed $value): void
    {
        $this->buryOne();
        $saved = $this->saved();
        $field = &$saved;
        foreach (explode('.', $key) as $part) {
            $field = &$field[$part];
        }
        $field = $value;
        unset($field);
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    public static function malformed(): array
    {
        return [
            ['schemaVersion', 9], ['nextChoiceId', 1], ['nextChoiceId', '2'], ['pendingChoice.id', 0],
            ['pendingChoice.seat', 9], ['pendingChoice.options', ['accept']], ['pendingChoice.target', 'p:0'], ['pendingChoice.object', -1],
            ['pendingChoice.incarnation', -1], ['pendingChoice.label', null], ['pendingChoice.resume', null],
            ['pendingChoice.resume.item', []], ['pendingChoice.resume.item.controller', 1],
            ['pendingChoice.resume.item.incarnation', 99], ['pendingChoice.resume.item.targets', ['p:0']],
            ['pendingChoice.resume.self', [1, 99]], ['pendingChoice.resume.steps', 'bad'],
            ['pendingChoice.resume.steps', [[[], null]]], ['pendingChoice.resume.followed', [0 => 1]],
        ];
    }

    public function testVersionNinePendingLifeGainStillResumes(): void
    {
        $this->newGame();
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'), true, flags: JSON_THROW_ON_ERROR);
        $entry = array_values(array_filter($fixture['candidates'], fn (array $entry) => $entry['card']['name'] === 'Fangren Marauder'))[0];
        $this->game->addCard(0, $entry['card'], GameObject::BATTLEFIELD);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->resolveOne();
        $saved = $this->saved();
        $saved['schemaVersion'] = 9;
        $this->game = Game::fromArray($saved);
        $before = $this->saved();
        try {
            $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
            $this->fail('A counter answer was accepted for an old life-gain choice.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
        $this->game->chooseOptionalLifeGain(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(25, $this->life(0));
    }

    /** @dataProvider orders */
    public function testSimultaneouslyBuriedSourceRetainsChoiceWithoutCounteringItsReturn(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->rigger(1 - $controller) : null;
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(1, 'Ornithopter');
        $source ??= $this->rigger(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $old = $this->game->objects[$source]->incarnation;
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR)['cards']['Damnation']['card'];
        $this->game->active = $this->game->priority = $active;
        $this->lands($active, 'Swamp', 4);
        $this->game->cast($active, $this->game->addCard($active, $card, GameObject::HAND)->id);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(2, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1 - $controller, false, false);
        $this->assertNotSame($old, $this->game->objects[$source]->incarnation);
        foreach (['accept', 'decline'] as $option) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame([$controller, $old], [$choice['seat'], $choice['incarnation']]);
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalSourceCounter($controller, $choice['id'], $option);
        }
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame([], $this->game->stack);
    }

    public static function orders(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $first) {
                    $cases[] = [$active, $controller, $first];
                }
            }
        }

        return $cases;
    }
}
