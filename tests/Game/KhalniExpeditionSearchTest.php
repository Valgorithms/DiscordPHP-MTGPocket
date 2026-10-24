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
final class KhalniExpeditionSearchTest extends GameTestCase
{
    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/khalni-expedition-quest-search.json'));
        $this->assertSame('4c8373e38f293c70846d4bbd5a792fc0c3243856ca5edba587533476c9943e54', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('0e4db2dc-36a6-5164-a5f7-d6e2efc3fbd0', $card['uuid']);

        return $card;
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function expedition(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $card = $this->fixture();
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $mana = $this->lands($actor, 'Forest', 2);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));

        return $source;
    }

    private function landfall(int $actor): array
    {
        if ($this->game->players[$actor]->landsPlayed > 0) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
        }
        $this->game->playLand($actor, $this->hand($actor, 'Forest'));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame([], $this->game->stack[0]['targets']);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_source_counter', $choice['type']);
        $this->assertSame('quest', $choice['counterKind']);
        $this->assertSame($actor, $choice['seat']);

        return $choice;
    }

    private function charge(int $actor, int $source): void
    {
        for ($n = 1; $n <= 3; $n++) {
            $choice = $this->landfall($actor);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->assertSame($n, $this->game->objects[$source]->counter('quest'));
        }
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid Khalni action accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider actors */
    public function testLandfallOffersAcceptOrDeclineAndRejectsWrongOrRepeatedAnswers(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $choice = $this->landfall($actor);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $pending = $this->saved();
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter(1 - $actor, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'both'));
        foreach (['accept', 'decline'] as $answer) {
            $this->game = Game::fromArray($pending);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], $answer);
            $this->assertSame($answer === 'accept' ? 1 : 0, $this->game->objects[$source]->counter('quest'));
            $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
            $this->assertNull($this->game->choiceAwaiting());
            $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'], $answer));
            $this->reject(fn () => $this->game->activate($actor, $source, 0));
        }
    }

    /** @dataProvider selections */
    public function testThreeEarnedCountersPaySacrificeAndZeroOneOrTwoTappedFinds(int $actor, int $count): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $a = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $b = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $c = $this->game->addCard($actor, self::card('Swamp'), GameObject::LIBRARY)->id;
        $nonbasic = $this->game->addCard($actor, ['name' => 'Nonbasic forest unit fixture', 'type' => 'Land — Forest', 'types' => ['Land'], 'subtypes' => ['Forest'], 'text' => null], GameObject::LIBRARY)->id;
        $creature = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $before = $this->saved();
        $this->game->activate($actor, $source, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertNull($this->game->choiceAwaiting());
        $queued = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('look', $choice['type']);
        $this->assertSame([$actor, 2, true, 'tapped'], [$choice['seat'], $choice['take'], $choice['may'], $choice['to']]);
        $this->assertContains($a, $choice['eligible']);
        $this->assertContains($b, $choice['eligible']);
        $this->assertNotContains($nonbasic, $choice['eligible']);
        $this->assertNotContains($creature, $choice['eligible']);
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $pending = $this->saved();
        $this->reject(fn () => $this->game->take(1 - $actor, [$a]));
        $this->reject(fn () => $this->game->take($actor, [$nonbasic]));
        $this->reject(fn () => $this->game->take($actor, [$a, $b, $c]));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, 0, 'accept'));
        $selected = array_slice([$a, $b], 0, $count);
        $this->game->take($actor, $selected);
        foreach ([$a, $b] as $id) {
            $this->assertSame(in_array($id, $selected, true) ? GameObject::BATTLEFIELD : GameObject::LIBRARY, $this->zone($id));
            $this->assertSame(in_array($id, $selected, true), $this->game->objects[$id]->tapped);
        }
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $expected = $this->saved();
        foreach ([$queued, $pending] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->choiceAwaiting() === null) {
                $this->resolveOne();
            }
            $this->game->take($actor, $selected);
            $this->assertSame($expected, $this->saved());
        }
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->reject(fn () => $this->game->take($actor, $selected));
    }

    /** @dataProvider actors */
    public function testQuestChoiceSaveValidationAndLegacyCounterMigration(int $actor): void
    {
        $source = $this->expedition($actor);
        $choice = $this->landfall($actor);
        $saved = $this->saved();
        $this->assertSame(16, $saved['schemaVersion']);
        $this->assertSame($saved, Game::fromArray($saved)->toArray());
        foreach ([null, 'loyalty', 1, [], '+3/+3'] as $kind) {
            $bad = $saved;
            $bad['pendingChoice']['counterKind'] = $kind;
            $this->assertInvalidState($bad);
        }
        $bad = $saved;
        unset($bad['pendingChoice']['counterKind']);
        $this->assertInvalidState($bad);
        foreach ([10, 15] as $version) {
            $bad = $saved;
            $bad['schemaVersion'] = $version;
            $this->assertInvalidState($bad);
        }
        // A synthetic old +1/+1 choice exercises migration, not a second real card.
        $legacy = $saved;
        unset($legacy['pendingChoice']['counterKind']);
        foreach ([10, 15] as $version) {
            $legacy['schemaVersion'] = $version;
            $loaded = Game::fromArray($legacy);
            $this->assertSame('+1/+1', $loaded->choiceAwaiting()['counterKind']);
            $loaded = Game::fromArray($loaded->toArray());
            $loaded->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->assertSame(1, $loaded->objects[$source]->counter('+1/+1'));
            $this->assertSame(0, $loaded->objects[$source]->counter('quest'));
        }
    }

    private function assertInvalidState(array $saved): void
    {
        try {
            Game::fromArray($saved);
            $this->fail('Invalid saved quest-counter state accepted.');
        } catch (GameException $exception) {
            $this->assertStringContainsString('source-counter', $exception->getMessage());
        }
    }

    /** @dataProvider actors */
    public function testPracticeUsesExistingCounterRouteAndNonlandEntryDoesNotTrigger(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->assertSame([], $this->game->stack);
        $this->landfall($actor);
        $this->assertTrue(PracticeBot::act($this->game, $actor));
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testQueuedLandfallKeepsControllerAndOriginalIncarnation(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->game->playLand($actor, $this->hand($actor, 'Forest'));
        $queued = $this->saved();
        foreach ([false, true] as $returns) {
            $this->game = Game::fromArray($queued);
            if ($returns) {
                $move = new \ReflectionMethod(Game::class, 'moveTo');
                $move->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
                (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], $actor);
            } else {
                $this->game->objects[$source]->controller = 1 - $actor;
            }
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame($actor, $choice['seat']);
            $this->reject(fn () => $this->game->chooseOptionalSourceCounter(1 - $actor, $choice['id'], 'accept'));
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->assertSame($returns ? 0 : 1, $this->game->objects[$source]->counter('quest'));
        }
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    /** @dataProvider actors */
    public function testOpponentsLandDoesNotChargeAndTwoCountersCannotActivate(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->game->playLand(1 - $actor, $this->hand(1 - $actor, 'Forest'));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        for ($n = 1; $n <= 2; $n++) {
            $choice = $this->landfall($actor);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->reject(fn () => $this->game->activate($actor, $source, 0));
            $this->assertSame($n, $this->game->objects[$source]->counter('quest'));
        }
    }

    /** @dataProvider actors */
    public function testFailToFindAndTwoSameNameBasicsUseOneFinalShuffle(int $actor): void
    {
        foreach ([0, 2] as $count) {
            $source = $this->expedition($actor);
            $this->charge($actor, $source);
            $move = new \ReflectionMethod(Game::class, 'moveTo');
            foreach ($this->game->players[$actor]->library as $id) {
                $move->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
            }
            $selected = [];
            for ($n = 0; $n < $count; $n++) {
                $selected[] = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
            }
            $before = $this->saved();
            $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0));
            $this->game->activate($actor, $source, 0);
            $this->resolveOne();
            if ($count > 0) {
                $this->assertEqualsCanonicalizing($selected, $this->game->choiceAwaiting()['eligible']);
                $this->game = Game::fromArray($this->saved());
                $this->game->take($actor, $selected);
            } else {
                $this->assertNull($this->game->choiceAwaiting());
                $this->game = Game::fromArray($this->saved());
                $this->assertNull($this->game->choiceAwaiting());
            }
            $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
            foreach ($selected as $id) {
                $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
                $this->assertTrue($this->game->objects[$id]->tapped);
            }
        }
    }

    public static function selections(): array
    {
        return [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2]];
    }
}
