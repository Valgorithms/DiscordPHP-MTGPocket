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
final class StateBasedDeathObserverTest extends GameTestCase
{
    private function put(string $name, int $owner = 0, string $zone = GameObject::BATTLEFIELD): int
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/state-based-deaths.json'), true, flags: JSON_THROW_ON_ERROR);
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
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/state-based-deaths.json'));
        $this->assertSame('a2b90b3b251a714c4b86c5f94c92e54ef497f01572ae951afe679d2e302879d3', hash('sha256', $bytes));
    }

    /** @dataProvider cases */
    public function testStateBasedGroupRetainsItsObservers(string $name, string $spell, bool $first, bool $stolen): void
    {
        $this->newGame();
        $this->lands(0, $spell === 'Pyroclasm' ? 'Mountain' : 'Swamp', 3);
        $observer = $first ? $this->put($name, $stolen ? 1 : 0) : null;
        $own = $this->battlefield(0, 'Grizzly Bears');
        $opponent = $this->battlefield(1, 'Grizzly Bears');
        $token = $this->game->addCard(0, ['name' => 'Bear token', 'type' => 'Creature - Bear', 'token' => true, 'power' => '2', 'toughness' => '2', 'text' => null], GameObject::BATTLEFIELD)->id;
        $observer ??= $this->put($name, $stolen ? 1 : 0);
        $this->game->objects[$observer]->controller = 0;
        $this->game->cast(0, $this->put($spell, 0, GameObject::HAND));
        $pendingSpell = $this->saved();
        $this->resolveAll();
        $expected = $this->saved();
        $this->game = Game::fromArray($pendingSpell);
        $this->resolveOne();
        $this->assertCount($name === 'Zulaport Cutthroat' ? 3 : 1, $this->game->stack, 'Exactly one trigger per qualifying death.');
        $pendingTriggers = $this->saved();
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
        $this->game = Game::fromArray($pendingTriggers);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
        foreach ([$own, $opponent, $observer] as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        $this->assertSame(GameObject::GONE, $this->zone($token));
        $drain = $name === 'Zulaport Cutthroat' ? 3 : 0;
        $this->assertSame([20 + $drain, 20 - $drain], [$this->life(0), $this->life(1)]);
        $this->assertCount($name === 'Grim Haruspex' ? 1 : 0, $this->game->players[0]->hand);
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['Zulaport Cutthroat', 'Grim Haruspex'] as $name) {
            foreach (['Pyroclasm', 'Infest'] as $spell) {
                foreach ([true, false] as $first) {
                    foreach ([false, true] as $stolen) {
                        $cases[] = [$name, $spell, $first, $stolen];
                    }
                }
            }
        }

        return $cases;
    }

    /** @dataProvider continuousGroups */
    public function testContinuousSourceLossCreatesASeparateLaterGroup(string $name, bool $lordFirst): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $lordCard = ['name' => 'Group lord probe', 'type' => 'Creature - Human', 'power' => '1', 'toughness' => '1', 'text' => 'Other creatures you control get +1/+1.'];
        $this->assertSame([], (new CardDefinition($lordCard))->unsupported);
        $lord = $lordFirst ? $this->game->addCard(0, $lordCard, GameObject::BATTLEFIELD)->id : null;
        $observer = $this->put($name);
        $bear = $this->battlefield(0, 'Grizzly Bears');
        $lord ??= $this->game->addCard(0, $lordCard, GameObject::BATTLEFIELD)->id;
        $this->game->cast(0, $this->put('Pyroclasm', 0, GameObject::HAND));
        $this->resolveAll();
        foreach ([$lord, $observer, $bear] as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        // Zulaport (2 toughness with the lord) dies with it; the Bear (3) dies next pass.
        // Grim (3 with the lord) survives that first pass and dies with the Bear next pass.
        $this->assertSame($name === 'Zulaport Cutthroat' ? [22, 18] : [20, 20], [$this->life(0), $this->life(1)]);
        $this->assertCount($name === 'Grim Haruspex' ? 2 : 0, $this->game->players[0]->hand, 'Grim sees the lord in the first group and the Bear in the second group.');
    }

    public static function continuousGroups(): array
    {
        return [['Zulaport Cutthroat', true], ['Zulaport Cutthroat', false], ['Grim Haruspex', true], ['Grim Haruspex', false]];
    }

    /** @dataProvider protectedVictims */
    public function testNonlethalOrPreventedDamageDoesNotBecomeADeath(string $name, string $protection): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $this->put($name);
        $victim = $this->battlefield(0, 'Grizzly Bears');
        $object = $this->game->objects[$victim];
        match ($protection) {
            'nonlethal' => $object->addCounters('+1/+1', 2),
            'indestructible' => $object->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['indestructible']],
            'regenerate' => $object->shields = 1,
            'shield' => $object->addCounters('shield', 1),
        };
        $this->game->cast(0, $this->put('Pyroclasm', 0, GameObject::HAND));
        $this->game = Game::fromArray($this->saved());
        $this->resolveAll();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($victim));
        $this->assertSame($name === 'Zulaport Cutthroat' ? [21, 19] : [20, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame([], $this->game->players[0]->hand);
    }

    public static function protectedVictims(): array
    {
        $cases = [];
        foreach (['Zulaport Cutthroat', 'Grim Haruspex'] as $name) {
            foreach (['nonlethal', 'indestructible', 'regenerate', 'shield'] as $protection) {
                $cases[] = [$name, $protection];
            }
        }

        return $cases;
    }

    public function testZeroToughnessIsNotPreventedByIndestructibleOrRegeneration(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 3);
        $this->put('Zulaport Cutthroat');
        $victim = $this->battlefield(0, 'Grizzly Bears');
        $object = $this->game->objects[$victim];
        $object->shields = 1;
        $object->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['indestructible']];
        $this->game->cast(0, $this->put('Infest', 0, GameObject::HAND));
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        $this->assertSame([22, 18], [$this->life(0), $this->life(1)]);
    }
}
