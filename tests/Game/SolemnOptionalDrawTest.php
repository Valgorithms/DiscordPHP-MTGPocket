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
final class SolemnOptionalDrawTest extends GameTestCase
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

    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/solemn-simulacrum-optional-draw.json'));
        $this->assertSame('9a79f3ccf7bd725f6d3c326e166ab68872235caa5bd4df8b47e95b1017b940e0', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('9c3514e4-85a9-5c7f-9a10-fe160bc6dc35', $card['uuid']);
        $this->assertCount(1, (new CardDefinition($card))->unsupported, 'The entry search remains a separate gap.');
        return $card;
    }

    private function die(int $actor, ?int $controller = null, bool $resolve = true): int
    {
        $controller ??= $actor;
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $lands = $this->lands($actor, 'Plains', 4);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->game->objects[$source]->controller = $controller;
        $smelt = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $mountain = $this->lands($actor, 'Mountain', 1)[0];
        $this->game->cast($actor, $this->game->addCard($actor, $smelt, GameObject::HAND)->id, 0, ['o:'.$source]);
        $this->assertTrue($this->game->objects[$mountain]->tapped);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(1, $this->game->stack);
        if (! $resolve) {
            return $source;
        }
        $before = count($this->game->players[$controller]->hand);
        $this->resolveOne();
        $this->assertSame($before, count($this->game->players[$controller]->hand), 'Optional drawing must wait for its controller.');
        $this->assertSame('optional_death_draw', $this->game->choiceAwaiting()['type'] ?? null);
        return $source;
    }

    /** @dataProvider options */
    public function testPaidDeathDrawWaitsForControllerAndAcceptsOrDeclines(int $actor, string $option): void
    {
        $this->die($actor);
        $before = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($actor, $choice['seat']);
        $this->game = Game::fromArray($before);
        $this->game->chooseOptionalDeathDraw($actor, $choice['id'], $option);
        $this->assertCount(count($before['players'][$actor]['hand']) + ($option === 'accept' ? 1 : 0), $this->game->players[$actor]->hand);
        $this->assertSame($before['players'][1 - $actor], $this->saved()['players'][1 - $actor]);
        if ($option === 'accept') {
            $this->assertContains($before['players'][$actor]['library'][array_key_last($before['players'][$actor]['library'])], $this->game->players[$actor]->hand);
        } else {
            $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        }
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testIllegalAnswersAndPriorityCannotConsumeOrRepeatTheChoice(): void
    {
        $this->die(0);
        $choice = $this->game->choiceAwaiting();
        $before = $this->saved();
        foreach ([[1, $choice['id'], 'accept'], [0, $choice['id'] + 1, 'accept'], [0, $choice['id'] - 1, 'decline'], [0, $choice['id'], 'both']] as [$seat, $id, $option]) {
            try {
                $this->game->chooseOptionalDeathDraw($seat, $id, $option);
                $this->fail('An invalid answer consumed the choice.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        try {
            $this->game->pass($this->game->priority);
            $this->fail('Priority skipped a pending choice.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
        $this->game->chooseOptionalDeathDraw(0, $choice['id'], 'accept');
        $after = $this->saved();
        try {
            $this->game->chooseOptionalDeathDraw(0, $choice['id'], 'accept');
            $this->fail('A repeated answer drew again.');
        } catch (GameException) {
            $this->assertSame($after, $this->saved());
        }
    }

    /** @dataProvider options */
    public function testOriginalDeathControllerResumesAfterSourceChangesZones(int $actor, string $option): void
    {
        $controller = 1 - $actor;
        $source = $this->die($actor, $controller, false);
        $queued = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->game->stack[array_key_last($this->game->stack)]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        $move->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($controller, $choice['seat']);
        $this->assertSame(20, $this->life($controller));
        // Fixture relocation proves a queued trigger is independent of its old source incarnation.
        $move->invoke($this->game, $this->game->objects[$source], GameObject::HAND);
        $this->game->objects[$source]->controller = $actor;
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->chooseOptionalDeathDraw($controller, $choice['id'], $option);
        $this->assertCount(count($before['players'][$controller]['hand']) + ($option === 'accept' ? 1 : 0), $this->game->players[$controller]->hand);
        $this->assertSame($before['players'][$actor]['hand'], $this->game->players[$actor]->hand);
        $this->assertSame(22, $this->life($controller));
        $after = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->chooseOptionalDeathDraw($controller, $choice['id'], $option);
        $this->assertSame($after, $this->saved(), 'JSON resume produces the identical draw and continuation.');
    }

    /** @dataProvider emptyLibrary */
    public function testEmptyLibraryAndPracticePolicy(string $option, bool $practice): void
    {
        $this->die(0);
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        foreach ($this->game->players[0]->library as $id) {
            $move->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1));
        $this->assertSame($before, $this->saved());
        if ($practice) {
            $this->assertTrue(PracticeBot::act($this->game, 0));
        } else {
            $this->game->chooseOptionalDeathDraw(0, $this->game->choiceAwaiting()['id'], $option);
        }
        $this->assertSame($option === 'accept', $this->game->players[0]->drewFromEmpty);
        $this->assertSame($option === 'accept' ? 1 : null, $this->game->winner);
        $this->assertSame($before['players'][0]['hand'], $this->game->players[0]->hand);
    }

    public static function emptyLibrary(): array
    {
        return [['accept', false], ['decline', false], ['decline', true]];
    }

    public function testPracticeAcceptsWithCardsAndOldPlainStateStillLoads(): void
    {
        $this->die(0);
        $before = count($this->game->players[0]->hand);
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertCount($before + 1, $this->game->players[0]->hand);
        $saved = $this->saved();
        $saved['schemaVersion'] = 12;
        $this->assertSame(Game::STATE_VERSION, Game::fromArray($saved)->toArray()['schemaVersion']);
    }

    /** @dataProvider malformed */
    public function testMalformedSavedChoicesAreRejected(string $key, mixed $value): void
    {
        $this->die(0);
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
        $cases = SummoningStationChoiceTest::malformed();
        $cases[0] = ['schemaVersion', 12];
        $cases[] = ['schemaVersion', 14];

        return $cases;
    }

    public function testMandatoryDeathDrawDoesNotBecomeOptional(): void
    {
        $card = $this->fixture();
        $card['text'] = 'When this creature dies, draw a card.';
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertSame('draw', $definition->triggersOn('dies')[0]['effects'][0]['type']);
        $card['text'] = 'When this creature enters, you may draw a card.';
        $this->assertSame('draw', (new CardDefinition($card))->triggersOn('enters')[0]['effects'][0]['type']);
    }

    public static function options(): array
    {
        return [[0, 'accept'], [0, 'decline'], [1, 'accept'], [1, 'decline']];
    }
}
