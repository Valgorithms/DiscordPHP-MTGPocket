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
}
