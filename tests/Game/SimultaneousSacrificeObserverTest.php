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

/** @covers \MTGPocket\Game\Game */
final class SimultaneousSacrificeObserverTest extends GameTestCase
{
    private function put(string $name, int $owner = 0, string $zone = GameObject::BATTLEFIELD): int
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-sacrifices.json'), true, flags: JSON_THROW_ON_ERROR);
        $card = $fixture['cards'][$name]['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported, $name);

        return $this->game->addCard($owner, $card, $zone)->id;
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

    private function resolveAll(): void
    {
        for ($i = 0; $this->game->stack !== [] && $i < 30; $i++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
    }

    public function testExactPinnedFixtureHasNotDrifted(): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/simultaneous-sacrifices.json'));
        $this->assertSame('fd0f0aae92c0e7560953565d2fbf14f2993711d3ff02e3d42f5ce772c7ad627a', hash('sha256', $bytes));
    }

    /** @dataProvider cases */
    public function testPaidBarterKeepsItsDyingObservers(string $name, bool $first, bool $stolen, bool $token): void
    {
        $this->newGame();
        $lands = $this->lands(0, 'Swamp', 4);
        $artifact = $this->game->addCard(0, ['name' => 'Untouched artifact probe', 'type' => 'Artifact', 'text' => null], GameObject::BATTLEFIELD)->id;
        $observer = $first ? $this->put($name, $stolen ? 1 : 0) : null;
        $partner = $token
            ? $this->game->addCard(0, ['name' => 'Bear token', 'type' => 'Creature - Bear', 'token' => true, 'power' => '4', 'toughness' => '4', 'text' => null], GameObject::BATTLEFIELD)->id
            : $this->battlefield(0, 'Grizzly Bears');
        if (! $token) {
            $this->game->objects[$partner]->addCounters('+1/+1', 2);
        }
        $opponent = $this->battlefield(1, 'Grizzly Bears');
        $observer ??= $this->put($name, $stolen ? 1 : 0);
        $this->game->objects[$observer]->controller = 0;
        $this->game->objects[$partner]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['indestructible']];
        $this->game->cast(0, $this->put('Barter in Blood', 0, GameObject::HAND));
        $pendingSpell = $this->saved();
        $this->resolveAll();
        $expected = $this->saved();
        $this->game = Game::fromArray($pendingSpell);
        $this->resolveOne();
        $triggers = $name === 'Zulaport Cutthroat' ? 2 : (int) ! $token;
        $this->assertCount($triggers, $this->game->stack, 'Each qualifying death queues once; sacrifice ignores indestructible.');
        $pendingTriggers = $this->saved();
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
        $this->game = Game::fromArray($pendingTriggers);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($observer));
        $this->assertContains($observer, $this->game->players[$stolen ? 1 : 0]->graveyard);
        $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $this->zone($partner));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($opponent));
        foreach (array_merge($lands, [$artifact]) as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
        $this->assertSame($name === 'Zulaport Cutthroat' ? [22, 18] : [20, 20], [$this->life(0), $this->life(1)]);
        $this->assertCount($name === 'Grim Haruspex' && ! $token ? 1 : 0, $this->game->players[0]->hand);
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['Zulaport Cutthroat', 'Grim Haruspex'] as $name) {
            foreach ([true, false] as $first) {
                foreach ([false, true] as $stolen) {
                    foreach ([false, true] as $token) {
                        $cases[] = [$name, $first, $stolen, $token];
                    }
                }
            }
        }

        return $cases;
    }

    public function testOneSimultaneousGroupSpansBothPlayers(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $card = ['name' => 'All deaths observer probe', 'type' => 'Creature - Human', 'power' => '1', 'toughness' => '1', 'text' => 'Whenever a creature dies, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $observer = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $own = $this->battlefield(0, 'Grizzly Bears');
        $opponents = [$this->battlefield(1, 'Grizzly Bears'), $this->battlefield(1, 'Grizzly Bears')];
        $this->game->cast(0, $this->put('Barter in Blood', 0, GameObject::HAND));
        $this->resolveOne();
        $this->assertCount(4, $this->game->stack, 'The observer sees both players\' simultaneous sacrifices, including itself.');
        $this->game = Game::fromArray($this->saved());
        $this->resolveAll();
        $this->assertSame([24, 20], [$this->life(0), $this->life(1)]);
        foreach (array_merge([$observer, $own], $opponents) as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
    }

    public function testBothControllersRetainTheirOwnAbilities(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $this->put('Zulaport Cutthroat');
        $own = $this->battlefield(0, 'Grizzly Bears');
        $this->game->objects[$own]->addCounters('+1/+1', 2);
        $this->put('Grim Haruspex', 1);
        $opponent = $this->battlefield(1, 'Grizzly Bears');
        $this->game->objects[$opponent]->addCounters('+1/+1', 2);
        $this->game->cast(0, $this->put('Barter in Blood', 0, GameObject::HAND));
        $this->resolveOne();
        $this->assertCount(3, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $this->resolveAll();
        $this->assertSame([22, 18], [$this->life(0), $this->life(1)]);
        $this->assertCount(1, $this->game->players[1]->hand);
        $this->assertSame([], $this->game->players[0]->hand);
    }

    public function testOnlyOneAvailableCreatureIsNotSelectedTwice(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $observer = $this->put('Zulaport Cutthroat');
        $this->game->cast(0, $this->put('Barter in Blood', 0, GameObject::HAND));
        $this->resolveOne();
        $this->assertCount(1, $this->game->stack);
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($observer));
        $this->assertSame([21, 19], [$this->life(0), $this->life(1)]);
    }

    public function testNoEligibleCreatureDoesNotSacrificeOtherPermanents(): void
    {
        $this->newGame();
        $lands = $this->lands(0, 'Swamp', 4);
        $artifact = $this->game->addCard(1, ['name' => 'Untouched artifact probe', 'type' => 'Artifact', 'text' => null], GameObject::BATTLEFIELD)->id;
        $this->game->cast(0, $this->put('Barter in Blood', 0, GameObject::HAND));
        $this->resolveOne();
        $this->assertSame([], $this->game->stack);
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        foreach (array_merge($lands, [$artifact]) as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
    }
}
