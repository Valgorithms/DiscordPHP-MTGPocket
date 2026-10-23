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
final class SummoningStationChoiceTest extends GameTestCase
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

    private function station(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Summoning Station'))[0];
        $this->assertSame('5DN', $entry['setCode']);
        $this->assertSame('972dafe2-254f-520f-be72-cb21ac19a5c4', $entry['card']['uuid']);
        $this->assertSame([], (new CardDefinition($entry['card']))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 7);
        $id = $this->game->addCard($seat, $entry['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function activateStation(int $seat, int $source): int
    {
        $this->game->priority = $seat;
        $this->assertTrue($this->game->canActivate($seat, $source, 0));
        $before = array_keys($this->game->objects);
        $this->game->activate($seat, $source, 0);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $this->assertFalse($this->game->canActivate($seat, $source, 0));
        $this->resolveOne();
        $added = array_values(array_diff(array_keys($this->game->objects), $before));
        $this->assertCount(1, $added);
        $token = $this->game->objects[$added[0]];
        $this->assertTrue($token->definition()->isToken());
        $this->assertSame(GameObject::BATTLEFIELD, $token->zone);
        $this->assertSame($seat, $token->controller);
        $this->assertFalse($token->definition()->is('Artifact'));
        $this->assertSame(['Pincher'], $token->definition()->subtypes);
        $this->assertSame([], $token->definition()->colors);
        $this->assertSame([2, 2], [$this->game->power($token), $this->game->toughness($token)]);

        return $token->id;
    }

    private function smelt(int $seat, int $artifact): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('bf9f48d0-70bb-59e6-9bb7-116a924204a4', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, 0, ['o:'.$artifact]);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    /** @dataProvider controllers */
    public function testPaidStationActivationAndSmeltOfferOriginalControllerAnOptionalUntap(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->station(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->activateStation($controller, $source);
        $artifact = $this->battlefield(1 - $artifactController, 'Ornithopter');
        $this->game->objects[$artifact]->controller = $artifactController;
        $this->smelt($active, $artifact);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame([$controller, []], [$this->game->stack[0]['controller'], $this->game->stack[0]['targets']]);
        $queued = $this->saved();
        $this->resolveOne();
        $this->answer($controller, 'accept');
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->activateStation($controller, $source);
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if (($this->game->stack[0]['kind'] ?? null) !== 'ability') {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->answer($controller, 'accept');
            $this->activateStation($controller, $source);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function answer(int $seat, string $option): void
    {
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_source_untap', $this->game->decision($seat));
        $this->assertSame(['accept', 'decline'], $choice['options']);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalSourceUntap($seat, $choice['id'], $option);
        $this->assertNull($this->game->choiceAwaiting());
    }

    private function burial(bool $pending = true): int
    {
        $this->newGame();
        $source = $this->station(0);
        $this->activateStation(0, $source);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);
        if ($pending) {
            $this->resolveOne();
        }

        return $source;
    }

    /** @dataProvider untapChoices */
    public function testChoiceRespectsTappedStateAndStunReplacement(string $option, bool $tapped, int $stun, bool $expectedTapped, int $expectedStun): void
    {
        $source = $this->burial();
        $this->game->objects[$source]->tapped = $tapped;
        $this->game->objects[$source]->addCounters('stun', $stun);
        $this->answer(0, $option);
        $this->assertSame($expectedTapped, $this->game->objects[$source]->tapped);
        $this->assertSame($expectedStun, $this->game->objects[$source]->counter('stun'));
    }

    public static function untapChoices(): array
    {
        return [
            ['accept', true, 0, false, 0], ['decline', true, 0, true, 0],
            ['accept', false, 0, false, 0], ['decline', false, 0, false, 0],
            ['accept', true, 2, true, 1], ['decline', true, 2, true, 2],
            ['accept', false, 2, false, 2],
        ];
    }

    public function testIndependentTriggersConsumeStunBeforeAnAcceptedUntap(): void
    {
        $source = $this->burial(false);
        $this->game->objects[$source]->addCounters('stun', 1);
        $artifact = $this->battlefield(0, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(2, $this->game->stack);
        $last = 0;
        foreach ([true, false] as $tapped) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->answer(0, 'accept');
            $this->assertSame($tapped, $this->game->objects[$source]->tapped);
            $this->assertSame(0, $this->game->objects[$source]->counter('stun'));
        }
        $this->activateStation(0, $source);
    }

    public function testSinglePermanentTapAndUntapShareStunRules(): void
    {
        $this->newGame();
        $source = $this->game->objects[$this->battlefield(0, 'Ornithopter')];
        $source->tapped = true;
        $source->addCounters('stun', 2);
        $effect = new \ReflectionMethod(Game::class, 'applyEffect');
        foreach ([[true, 1], [true, 0], [false, 0]] as [$tapped, $stun]) {
            $effect->invoke($this->game, ['type' => 'untap', 'self' => true], $source, $source, 0, 0, null, $source->incarnation);
            $this->assertSame([$tapped, $stun], [$source->tapped, $source->counter('stun')]);
        }
        $source->addCounters('stun', 1);
        $effect->invoke($this->game, ['type' => 'untap', 'self' => true], $source, $source, 0, 0, null, $source->incarnation);
        $this->assertSame([false, 1], [$source->tapped, $source->counter('stun')]);
        $effect->invoke($this->game, ['type' => 'tap', 'self' => true], $source, $source, 0, 0, null, $source->incarnation);
        $this->assertSame([true, 1], [$source->tapped, $source->counter('stun')]);
    }

    /** @dataProvider movements */
    public function testOnlyActualArtifactBurialOffersUntap(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $source = $this->station(0);
        $this->activateStation(0, $source);
        $object = $this->game->addCard(1, ['name' => 'Untap Movement Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->resolveOne();
            $this->answer(0, 'accept');
        }
        $this->assertSame($count === 0, $this->game->objects[$source]->tapped);
    }

    public static function movements(): array
    {
        return MoriokBurialChoiceTest::movements();
    }

    /** @dataProvider sourceOrders */
    public function testStationSeesItsOwnSimultaneousBurialButCannotUntapItsReturn(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->station(1 - $controller) : null;
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(1, 'Ornithopter');
        $source ??= $this->station(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $old = $this->game->objects[$source]->incarnation;
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->game->active = $this->game->priority = $active;
        $this->lands($active, 'Mountain', 4);
        $this->game->cast($active, $this->game->addCard($active, $fixture['card'], GameObject::HAND)->id);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(3, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1 - $controller, false, false);
        $this->game->objects[$source]->tapped = true;
        $this->assertNotSame($old, $this->game->objects[$source]->incarnation);
        foreach (['accept', 'decline', 'accept'] as $option) {
            $this->resolveOne();
            $this->assertSame([$controller, $old], [$this->game->choiceAwaiting()['seat'], $this->game->choiceAwaiting()['incarnation']]);
            $this->answer($controller, $option);
            $this->assertTrue($this->game->objects[$source]->tapped);
        }
    }

    public static function sourceOrders(): array
    {
        return MoriokBurialChoiceTest::orders();
    }

    public function testWrongStaleCrossEffectAndRepeatedAnswersPreserveState(): void
    {
        $source = $this->burial();
        $choice = $this->game->choiceAwaiting();
        foreach ([[1, $choice['id'], 'accept'], [0, $choice['id'] + 1, 'accept'], [0, $choice['id'], 'bad']] as [$seat, $id, $option]) {
            $before = $this->saved();
            try {
                $this->game->chooseOptionalSourceUntap($seat, $id, $option);
                $this->fail('Invalid answer accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        foreach (['chooseOptionalSourceCounter', 'chooseOptionalLifeGain', 'chooseOptionalLifeLoss'] as $method) {
            $before = $this->saved();
            try {
                $this->game->$method(0, $choice['id'], 'accept');
                $this->fail('Another effect answered an untap choice.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->game->chooseOptionalSourceUntap(0, $choice['id'], 'accept');
        $this->assertFalse($this->game->objects[$source]->tapped);
        $before = $this->saved();
        try {
            $this->game->chooseOptionalSourceUntap(0, $choice['id'], 'accept');
            $this->fail('Repeated answer accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider departures */
    public function testDepartedOrReturnedIncarnationsAreNeverUntapped(string $zone, bool $return, bool $pending): void
    {
        $source = $this->burial($pending);
        $incarnation = $this->game->objects[$source]->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], $zone);
        if ($return) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
            $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        }
        $this->game->objects[$source]->tapped = true;
        if (! $pending) {
            (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        }
        while ($this->game->choiceAwaiting() !== null || $this->game->stack !== []) {
            if ($this->game->choiceAwaiting() === null) {
                $this->resolveOne();
            }
            $this->answer(0, 'accept');
            $this->assertTrue($this->game->objects[$source]->tapped);
        }
    }

    public static function departures(): array
    {
        $cases = [];
        foreach ([GameObject::GRAVEYARD, GameObject::HAND, GameObject::EXILE] as $zone) {
            foreach ([false, true] as $return) {
                foreach ([false, true] as $pending) {
                    $cases[] = [$zone, $return, $pending];
                }
            }
        }

        return $cases;
    }

    public function testSameIncarnationUntapsAfterControlChangesAndPracticeUsesTheApi(): void
    {
        $source = $this->burial();
        $this->game->objects[$source]->controller = 1;
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(0, $this->game->choiceAwaiting()['seat']);
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertSame(1, $this->game->objects[$source]->controller);
    }

    /** @dataProvider options */
    public function testLaterEffectsResumeOnceAfterSourceDeparts(string $option): void
    {
        $source = $this->burial(false);
        $this->game->stack[array_key_last($this->game->stack)]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $this->resolveOne();
        $this->assertSame(20, $this->life(0));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->answer(0, $option);
        $this->assertSame(22, $this->life(0));
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(22, $this->life(0));
    }

    public static function options(): array
    {
        return [['accept'], ['decline']];
    }

    /** @dataProvider malformed */
    public function testMalformedSavedChoicesAreRejected(string $key, mixed $value): void
    {
        $this->burial();
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
            ['schemaVersion', 10], ['nextChoiceId', 1], ['nextChoiceId', '2'], ['pendingChoice.id', 0],
            ['pendingChoice.seat', 9], ['pendingChoice.options', ['accept']], ['pendingChoice.target', 'p:0'], ['pendingChoice.object', -1],
            ['pendingChoice.incarnation', -1], ['pendingChoice.label', null], ['pendingChoice.resume', null],
            ['pendingChoice.resume.item', []], ['pendingChoice.resume.item.controller', 1],
            ['pendingChoice.resume.item.incarnation', 99], ['pendingChoice.resume.item.targets', ['p:0']],
            ['pendingChoice.resume.self', [1, 99]], ['pendingChoice.resume.steps', 'bad'],
            ['pendingChoice.resume.steps', [[[], null]]], ['pendingChoice.resume.followed', [0 => 1]],
        ];
    }

    public function testVersionTenCounterDecisionStillResumesAndCannotBeAnsweredAsUntap(): void
    {
        $this->newGame();
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'), true, flags: JSON_THROW_ON_ERROR);
        $entry = array_values(array_filter($fixture['candidates'], fn (array $entry) => $entry['card']['name'] === 'Moriok Rigger'))[0];
        $source = $this->game->addCard(0, $entry['card'], GameObject::BATTLEFIELD)->id;
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->resolveOne();
        $saved = $this->saved();
        $saved['schemaVersion'] = 10;
        $this->game = Game::fromArray($saved);
        $before = $this->saved();
        try {
            $this->game->chooseOptionalSourceUntap(0, $this->game->choiceAwaiting()['id'], 'accept');
            $this->fail('Untap answered a counter decision.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
        $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function controllers(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([0, 1] as $artifactController) {
                    $cases[] = [$active, $controller, $artifactController];
                }
            }
        }

        return $cases;
    }
}
