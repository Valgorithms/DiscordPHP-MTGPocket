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

/** @covers \MTGPocket\Game\Game */
final class ZektarShrineExpeditionTest extends GameTestCase
{
    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/zektar-shrine-expedition.json'));
        $this->assertSame('11d1193f0c0f2984020ab759075ec8cee3a6a06d1cb4d5ae3a5a01ad0b7a7357', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][0];
        $this->assertSame('d2525841-d3a3-55ea-99bf-ea8d2f2dfda6', $card['uuid']);

        return $card;
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
        $mana = $this->lands($actor, 'Mountain', 2);
        $card = $this->fixture();
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function landfall(int $actor): array
    {
        if ($this->game->active !== $actor) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        if ($this->game->players[$actor]->landsPlayed > 0) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
        }
        $this->game->playLand($actor, $this->hand($actor, 'Mountain'));
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);

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

    private function token(int $actor, int $source): int
    {
        $before = $this->game->battlefield;
        $this->game->activate($actor, $source, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->resolveOne();
        $made = array_values(array_diff($this->game->battlefield, $before));
        $this->assertCount(1, $made);
        $token = $this->game->objects[$made[0]];
        $this->assertTrue($token->definition()->isToken());
        $this->assertSame([$actor, $actor], [$token->owner, $token->controller]);
        $this->assertSame([7, 1], [$this->game->power($token), $this->game->toughness($token)]);
        $this->assertSame(['R'], $token->definition()->colors);
        $this->assertSame(['Elemental'], $token->definition()->subtypes);
        $this->assertTrue($this->game->hasKeyword($token, 'haste'));
        $this->assertTrue($this->game->hasKeyword($token, 'trample'));

        return $token->id;
    }

    private function reject(callable $action): void
    {
        $before = $this->game->toArray();
        try {
            $action();
            $this->fail('Invalid Zektar action must reject.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
        }
    }

    /** @dataProvider actors */
    public function testPaidLandfallActivationCreatesTokenAndItExpires(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $token = $this->token($actor, $source);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->passUntil(Step::End);
        if ($this->game->stack !== []) {
            $this->resolveOne();
        }
        $this->assertSame(GameObject::GONE, $this->zone($token));
    }

    /** @dataProvider actors */
    public function testOpponentLandDeclineAndUnderchargedActionsDoNotPayCosts(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->game->playLand(1 - $actor, $this->hand(1 - $actor, 'Mountain'));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        for ($n = 1; $n <= 2; $n++) {
            $choice = $this->landfall($actor);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        }
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $choice = $this->landfall($actor);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'decline');
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
    }

    /** @dataProvider actors */
    public function testEndStepExileAllowsResponsesBeforeItResolves(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $token = $this->token($actor, $source);
        $this->passUntil(Step::End);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($token), 'Next-end-step exile is a triggered ability; the token remains until that ability resolves.');
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @dataProvider actors */
    public function testDelayedExileRetainsOriginalSourceControllerAndJsonReplay(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $sourceIncarnation = $this->game->objects[$source]->incarnation;
        $token = $this->token($actor, $source);
        $context = ['source' => $source, 'incarnation' => $sourceIncarnation, 'controller' => $actor];
        $this->assertSame($context, $this->game->objects[$token]->endExileContext);
        // A control-transfer probe, not evidence for an enabling theft spell.
        $this->game->objects[$token]->controller = 1 - $actor;
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->passUntil(Step::End);
        $ability = $this->game->stack[0];
        $this->assertSame([$source, $sourceIncarnation, $actor, []], [$ability['object'], $ability['incarnation'], $ability['controller'], $ability['targets']]);
        $this->assertSame([['type' => 'exile', 'thatObject' => $token, 'thatIncarnation' => $this->game->objects[$token]->incarnation]], $ability['effects']);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->step !== Step::End) {
                $this->passUntil(Step::End);
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
    }

    /** @dataProvider actors */
    public function testActivationDuringEndStepWaitsForNextTurnsEndStep(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $this->passUntil(Step::End);
        $turn = $this->game->turn;
        $token = $this->token($actor, $source);
        $this->assertSame([], $this->game->stack);
        $this->passUntil(Step::PrecombatMain, $turn + 1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($token));
        $this->passUntil(Step::End, $turn + 1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($token));
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
    }

    /** @dataProvider actors */
    public function testRemovingQueuedExileDoesNotScheduleItAgain(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $token = $this->token($actor, $source);
        $this->passUntil(Step::End);
        // Isolate a countered triggered ability; no supporting-card claim.
        array_pop($this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $this->passUntil(Step::End, $this->game->turn + 1);
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($token));
        $this->assertNull($this->game->objects[$token]->alt);
        $this->assertNull($this->game->objects[$token]->endExileContext);
    }

    /** @dataProvider actors */
    public function testActualBounceResponseMovesTokenBeforeDelayedExileResolves(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $token = $this->token($actor, $source);
        $this->passUntil(Step::End);
        $mana = $this->lands($actor, 'Island', 1)[0];
        $spell = $this->hand($actor, 'Unsummon');
        $this->game->cast($actor, $spell, 0, ["o:{$token}"]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->assertCount(2, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider actors */
    public function testLegacyScheduleWithoutContextUsesExplicitFallback(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $token = $this->token($actor, $source);
        $saved = $this->saved();
        foreach ($saved['objects'] as &$object) {
            if ($object['id'] === $token) {
                unset($object['endExileContext']);
            }
        }
        unset($object);
        $this->game = Game::fromArray($saved);
        $this->assertNull($this->game->objects[$token]->endExileContext);
        $this->passUntil(Step::End);
        $this->assertSame([$token, $actor], [$this->game->stack[0]['object'], $this->game->stack[0]['controller']]);
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
    }

    /** @dataProvider invalidContexts */
    public function testMalformedSavedContextRejects(string $key, mixed $value): void
    {
        $source = $this->expedition(0);
        $this->charge(0, $source);
        $token = $this->token(0, $source);
        $saved = $this->saved();
        foreach ($saved['objects'] as &$object) {
            if ($object['id'] === $token) {
                if ($key === 'whole') {
                    $object['endExileContext'] = $value;
                } elseif ($key === 'alt') {
                    $object['alt'] = $value;
                } else {
                    $object['endExileContext'][$key] = $value;
                }
            }
        }
        unset($object);
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    public static function invalidContexts(): array
    {
        return [
            ['whole', false], ['whole', []], ['controller', 2], ['controller', '0'],
            ['source', 0], ['source', 99999], ['incarnation', -1], ['incarnation', 99999],
            ['extra', true], ['alt', null],
        ];
    }

    /** @dataProvider actors */
    public function testCreatedTokenAttacksImmediatelyAndTramplesOverABlocker(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $token = $this->token($actor, $source);
        $blocker = $this->battlefield(1 - $actor, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($actor, [$token]);
        $this->resolveOne();
        $this->game->declareBlockers(1 - $actor, [$blocker => $token]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(15, $this->life(1 - $actor));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($blocker));
        $this->assertSame(GameObject::GONE, $this->zone($token));
    }
}
