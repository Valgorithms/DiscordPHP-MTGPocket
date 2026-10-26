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
final class IorRuinExpeditionTest extends GameTestCase
{
    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function expedition(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/ior-ruin-expedition.json'));
        $this->assertSame('303f638d4bd967a25b3604f9e162766272a16ad5d7c53d0d7e6b5866a6204e5c', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][0];
        $this->assertSame('82c2fdfb-c6f6-591a-9c85-8913e559a5de', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $mana = $this->lands($actor, 'Island', 2);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function landfall(int $actor, string $answer = 'accept'): void
    {
        if ($this->game->active !== $actor) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        if ($this->game->players[$actor]->landsPlayed > 0) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
        }
        $this->game->playLand($actor, $this->hand($actor, 'Island'));
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], $answer);
    }

    private function charge(int $actor, int $source): void
    {
        for ($n = 1; $n <= 3; $n++) {
            $this->landfall($actor);
            $this->assertSame($n, $this->game->objects[$source]->counter('quest'));
        }
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid Ior Ruin action must reject.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider actors */
    public function testPaidActivationDrawsTwoInOrderAndReplaysQueuedAbility(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $hand = $this->game->players[$actor]->hand;
        $otherHand = $this->game->players[1 - $actor]->hand;
        $library = $this->game->players[$actor]->library;
        $expected = [array_pop($library), array_pop($library)];
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->game->activate($actor, $source, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertSame($hand, $this->game->players[$actor]->hand, 'Costs are paid before any cards are drawn.');
        $this->assertCount(1, $this->game->stack);
        $ability = $this->game->stack[0];
        $this->assertSame([$source, $incarnation, $actor, []], [$ability['object'], $ability['incarnation'], $ability['controller'], $ability['targets']]);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(array_merge($hand, $expected), $this->game->players[$actor]->hand);
        $this->assertSame($library, $this->game->players[$actor]->library);
        $this->assertSame($otherHand, $this->game->players[1 - $actor]->hand);
        foreach ($expected as $id) {
            $this->assertSame(GameObject::HAND, $this->zone($id));
        }
        $this->assertNull($this->game->winner);
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
    }

    /** @dataProvider actors */
    public function testOpponentLandDeclineAndInsufficientCountersDoNotPayCosts(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->game->playLand(1 - $actor, $this->hand(1 - $actor, 'Island'));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->landfall($actor);
        $this->landfall($actor);
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->landfall($actor, 'decline');
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
    }

    /** @dataProvider actors */
    public function testReturnedSourceCannotRedirectThePaidDraw(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $incarnation = $this->game->objects[$source]->incarnation;
        $hand = $this->game->players[$actor]->hand;
        $otherHand = $this->game->players[1 - $actor]->hand;
        $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0));
        $this->game->activate($actor, $source, 0);
        // Isolate source identity; no enabling blink/reanimation card claim.
        $object = $this->game->objects[$source];
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $object, 1 - $actor);
        $this->assertGreaterThan($incarnation, $object->incarnation);
        $this->game = Game::fromArray($this->saved());
        $ability = $this->game->stack[0];
        $this->assertSame([$source, $incarnation, $actor], [$ability['object'], $ability['incarnation'], $ability['controller']]);
        $this->resolveOne();
        $this->assertCount(count($hand) + 2, $this->game->players[$actor]->hand);
        $this->assertSame($otherHand, $this->game->players[1 - $actor]->hand);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame(1 - $actor, $this->game->objects[$source]->controller);
    }

    /** @dataProvider libraryCases */
    public function testDrawTwoWithShortLibrary(int $actor, int $remaining): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        // Synthetic short-library setup; activation and drawing use gameplay APIs.
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        $remove = array_slice($this->game->players[$actor]->library, 0, count($this->game->players[$actor]->library) - $remaining);
        foreach ($remove as $id) {
            $move->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
        $this->assertCount($remaining, $this->game->players[$actor]->library);
        $hand = $this->game->players[$actor]->hand;
        $this->game->activate($actor, $source, 0);
        $this->assertNull($this->game->winner);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertCount(count($hand) + min(2, $remaining), $this->game->players[$actor]->hand);
        $this->assertSame([], $this->game->players[$actor]->library);
        $this->assertSame($remaining < 2, $this->game->players[$actor]->drewFromEmpty);
        $this->assertSame($remaining < 2 ? 1 - $actor : null, $this->game->winner);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function libraryCases(): array
    {
        return [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2]];
    }
}
