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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class ViridianDeathSearchTest extends GameTestCase
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

    private function fixture(string $file = 'viridian-emissary-optional-search.json'): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/'.$file));
        $this->assertSame($file === 'viridian-emissary-optional-search.json' ? '68ed685d6e9366336d2f5a4688ca5857abb4820e381354a691bf601bd64c93f7' : 'ca08cf03fb0e80b2ea483cb937667c64a87c545f76d4180927071b760a897530', hash('sha256', $bytes));

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
    }

    private function castEmissary(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $lands = $this->lands($actor, 'Forest', 2);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());

        return $source;
    }

    private function kill(int $actor, int $source): void
    {
        $mountain = $this->lands($actor, 'Mountain', 1)[0];
        $shock = $this->game->addCard($actor, $this->fixture('viridian-death-search-shock.json'), GameObject::HAND)->id;
        $this->game->cast($actor, $shock, 0, ['o:'.$source]);
        $this->assertTrue($this->game->objects[$mountain]->tapped);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(1, $this->game->stack);
    }

    /** @dataProvider options */
    public function testPaidDeathWaitsForSearchOrDecline(int $actor, string $option): void
    {
        $source = $this->castEmissary($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $this->kill($actor, $source);
        $before = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_basic_land_search', $choice['type'] ?? null);
        $this->assertSame($actor, $choice['seat']);
        $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], $option);
        if ($option === 'accept') {
            $this->assertSame('look', $this->game->choiceAwaiting()['type']);
            $this->game->take($actor, [$land]);
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
            $this->assertTrue($this->game->objects[$land]->tapped);
        } else {
            $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        }
        $this->assertSame($before['shuffles'] + ($option === 'accept' ? 1 : 0), $this->saved()['shuffles']);
        $this->assertNull($this->game->choiceAwaiting());
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid death-search action accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider relocations */
    public function testOriginalDeathControllerAndContinuationSurviveRelocation(int $actor, string $option, bool $pending): void
    {
        $source = $this->castEmissary($actor);
        $controller = 1 - $actor;
        $land = $this->game->addCard($controller, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->game->objects[$source]->controller = $controller;
        $this->kill($actor, $source);
        $this->game->stack[array_key_last($this->game->stack)]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        if ($pending) {
            $this->resolveOne();
        }
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->game->objects[$source]->controller = $actor;
        $this->game = Game::fromArray($this->saved());
        if (! $pending) {
            $this->resolveOne();
        }
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_basic_land_search', $choice['type']);
        $this->assertSame($controller, $choice['seat']);
        $this->assertSame(20, $this->life($controller));
        $before = $this->saved();
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], $option));
        $this->game->chooseOptionalBasicLandSearch($controller, $choice['id'], $option);
        if ($option === 'accept') {
            $this->assertSame(20, $this->life($controller));
            $this->game = Game::fromArray($this->saved());
            $this->game->take($controller, [$land]);
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
            $this->assertSame($controller, $this->game->objects[$land]->controller);
            $this->assertTrue($this->game->objects[$land]->tapped);
        } else {
            $this->assertSame($before['players'][$controller]['library'], $this->game->players[$controller]->library);
        }
        $this->assertSame(22, $this->life($controller));
        $this->assertSame(20, $this->life($actor));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + ($option === 'accept' ? 1 : 0), $this->saved()['shuffles']);
        $after = $this->saved();
        $this->game = Game::fromArray($after);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($controller, $choice['id'], $option));
    }

    /** @dataProvider actors */
    public function testInvalidAnswersAndFailureToFindAreAtomicAndReplayable(int $actor): void
    {
        $source = $this->castEmissary($actor);
        $nonbasic = $this->game->addCard($actor, array_replace(self::card('Island'), ['name' => 'Nonbasic Island', 'supertypes' => [], 'type' => 'Land — Island']), GameObject::LIBRARY)->id;
        $opposing = $this->game->addCard(1 - $actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->kill($actor, $source);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        foreach ([[1 - $actor, $choice['id'], 'accept'], [$actor, $choice['id'] - 1, 'decline'], [$actor, $choice['id'] + 1, 'accept'], [$actor, $choice['id'], 'both']] as [$seat, $id, $option]) {
            $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($seat, $id, $option));
        }
        $this->reject(fn () => $this->game->chooseOptionalDeathDraw($actor, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->pass($this->game->priority));
        $before = $this->saved();
        $legacy = $before;
        $legacy['schemaVersion'] = 13;
        $this->expectInvalidSearchSave($legacy);
        $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], 'accept');
        $this->assertSame('look', $this->game->choiceAwaiting()['type']);
        $this->reject(fn () => $this->game->take($actor, [$nonbasic]));
        $this->reject(fn () => $this->game->take($actor, [$opposing]));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], 'decline'));
        $search = $this->saved();
        $this->game->take($actor, []);
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNull($this->game->choiceAwaiting());
        $after = $this->saved();
        $this->game = Game::fromArray($search);
        $this->game->take($actor, []);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, []));
    }

    private function expectInvalidSearchSave(array $state): void
    {
        try {
            Game::fromArray($state);
            $this->fail('A death-search decision was accepted under version 13.');
        } catch (GameException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @dataProvider emptySearches */
    public function testEmptyAndNoEligibleLibrariesPreserveTheOptionalDecision(int $actor, bool $empty, string $option): void
    {
        $source = $this->castEmissary($actor);
        foreach ($this->game->players[$actor]->library as $id) {
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
        if (! $empty) {
            $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY);
        }
        $this->kill($actor, $source);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_basic_land_search', $choice['type']);
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], $option);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertSame($before['shuffles'] + ($option === 'accept' ? 1 : 0), $this->saved()['shuffles']);
        $this->assertSame(Game::PLAYING, $this->game->stage);
    }

    /** @dataProvider actors */
    public function testPaidBounceIsNotDeathAndPracticeUsesTheSharedApi(int $actor): void
    {
        $source = $this->castEmissary($actor);
        $island = $this->lands($actor, 'Island', 1)[0];
        $this->game->cast($actor, $this->hand($actor, 'Unsummon'), 0, ['o:'.$source]);
        $this->assertTrue($this->game->objects[$island]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($source));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());

        $source = $this->castEmissary($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $this->kill($actor, $source);
        $this->resolveOne();
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1 - $actor));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, $actor));
        $this->assertSame('look', $this->game->choiceAwaiting()['type']);
        $this->game->take($actor, [$land]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    public function testOnlyTheExactOptionalDeathSearchUsesTheNewChoice(): void
    {
        $card = $this->fixture();
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertSame('dies', $definition->triggered[0]['event']);
        $this->assertSame([['type' => 'optional_basic_land_search']], $definition->triggered[0]['effects']);
        foreach ([['you may search', 'search'], ['a basic land card', 'an Island card'], ['battlefield tapped', 'battlefield']] as [$from, $to]) {
            $variant = new CardDefinition(array_replace($card, ['text' => str_replace($from, $to, $card['text'])]));
            foreach ($variant->triggered as $trigger) {
                $this->assertNotContains('optional_basic_land_search', array_column($trigger['effects'], 'type'));
            }
        }
    }

    /** @dataProvider actors */
    public function testUnpaidOpposingAndCounteredCastsNeverQueueADeathSearch(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Forest', 2);
        $this->reject(fn () => $this->game->cast(1 - $actor, $source));
        $this->game->cast($actor, $source);
        $this->game->pass($actor);
        $this->lands(1 - $actor, 'Island', 2);
        $this->game->cast(1 - $actor, $this->hand(1 - $actor, 'Counterspell'), 0, ['s:'.$this->game->stack[0]['id']]);
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function relocations(): array
    {
        $cases = [];
        foreach (self::options() as [$actor, $option]) {
            foreach ([false, true] as $pending) {
                $cases[] = [$actor, $option, $pending];
            }
        }

        return $cases;
    }

    public static function emptySearches(): array
    {
        $cases = [];
        foreach (self::options() as [$actor, $option]) {
            foreach ([false, true] as $empty) {
                $cases[] = [$actor, $empty, $option];
            }
        }

        return $cases;
    }

    public static function options(): array
    {
        return [[0, 'accept'], [0, 'decline'], [1, 'accept'], [1, 'decline']];
    }
}
