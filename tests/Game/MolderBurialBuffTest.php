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
use MTGPocket\Game\Step;
use MTGPocket\Builders\MatchMessageBuilder;

/** @covers \MTGPocket\Game\Game */
final class MolderBurialBuffTest extends GameTestCase
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

    private function beast(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Molder Beast'))[0];
        $this->assertSame('CMR', $entry['setCode']);
        $card = $entry['card'];
        $this->assertSame('28682398-1b11-5f20-b87c-d2d57835060e', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(5, $this->game->power($this->game->objects[$id]));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'trample'));

        return $id;
    }

    private function shatterstorm(int $seat): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'));
        $this->assertSame('9bfd9ecb99db8022a5bc52b775f1e66c8d06ed65c849f8fd202d41a6d8c9bcae', hash('sha256', $bytes));
        $fixture = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('906afe47-bdfc-58e5-9a58-bc6b03ad242b', $fixture['card']['uuid']);
        $this->assertSame([], (new CardDefinition($fixture['card']))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 4);
        $this->game->cast($seat, $this->game->addCard($seat, $fixture['card'], GameObject::HAND)->id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
    }

    /** @dataProvider controllers */
    public function testPaidBeastAndShatterstormStackBuffsUntilCleanupWithJsonReplay(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->beast(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $artifact = $this->battlefield($artifactController, 'Ornithopter');
        $second = $this->battlefield($artifactController, 'Ornithopter');
        $this->shatterstorm($active);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($second));
        $this->assertCount(2, $this->game->stack);
        $this->assertSame(5, $this->game->power($this->game->objects[$source]));
        foreach ($this->game->stack as $item) {
            $this->assertSame($controller, $item['controller']);
            $this->assertSame([], $item['targets']);
        }
        $queued = $this->saved();
        $this->finishBuffsAndTurn($source);
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($artifact) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->finishBuffsAndTurn($source);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function finishBuffsAndTurn(int $source): void
    {
        foreach ([7, 9] as $power) {
            $this->resolveOne();
            $this->assertSame($power, $this->game->power($this->game->objects[$source]));
            $this->assertSame(3, $this->game->toughness($this->game->objects[$source]));
            $this->assertNull($this->game->choiceAwaiting());
            $this->assertStringContainsString(" {$power}/3", MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
            $this->game = Game::fromArray($this->saved());
        }
        $this->assertSame([], $this->game->stack);
        $this->assertCount(2, $this->game->objects[$source]->untilEndOfTurn);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame([], $this->game->objects[$source]->untilEndOfTurn);
        $this->assertSame([5, 3], [$this->game->power($this->game->objects[$source]), $this->game->toughness($this->game->objects[$source])]);
        $this->assertStringContainsString(' 5/3', MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
    }

    private function buryOne(): int
    {
        $this->newGame();
        $source = $this->beast(0);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);

        return $source;
    }

    /** @dataProvider sourceMoves */
    public function testDepartedAndReturnedIncarnationsDoNotReceiveOldBoost(string $zone, bool $returns): void
    {
        $source = $this->buryOne();
        $incarnation = $this->game->objects[$source]->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], $zone);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
            $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($returns ? GameObject::BATTLEFIELD : $zone, $this->zone($source));
        $this->assertSame([], $this->game->objects[$source]->untilEndOfTurn);
        $this->assertSame(5, $this->game->power($this->game->objects[$source]));
        $this->assertSame([], $this->game->stack);
    }

    public static function sourceMoves(): array
    {
        $cases = [];
        foreach ([GameObject::GRAVEYARD, GameObject::HAND, GameObject::EXILE] as $zone) {
            foreach ([false, true] as $returns) {
                $cases[] = [$zone, $returns];
            }
        }

        return $cases;
    }

    public function testSameIncarnationReceivesBoostAfterControlChanges(): void
    {
        $source = $this->buryOne();
        $this->assertSame(0, $this->game->stack[0]['controller']);
        $this->game->objects[$source]->controller = 1;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(7, $this->game->power($this->game->objects[$source]));
        $this->assertSame(1, $this->game->objects[$source]->controller);
    }

    public function testBoostAlreadyAppliedIsClearedAndRemainingOldTriggerCannotBoostReturn(): void
    {
        $source = $this->buryOne();
        $artifact = $this->battlefield(0, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(2, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(7, $this->game->power($this->game->objects[$source]));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::HAND);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 0, false, false);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(5, $this->game->power($this->game->objects[$source]));
        $this->assertSame([], $this->game->objects[$source]->untilEndOfTurn);
    }

    /** @dataProvider movements */
    public function testOnlyActualArtifactBurialsCreateTheBoost(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $source = $this->beast(0);
        $object = $this->game->addCard(1, ['name' => 'Burial Movement Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $object->zone);
            $this->resolveOne();
        }
        $this->assertSame(5 + 2 * $count, $this->game->power($this->game->objects[$source]));
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

    /** @dataProvider deathOrders */
    public function testSimultaneouslyDepartedSourceCannotBoostReturnedIncarnation(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->beast(1 - $controller) : null;
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(1, 'Ornithopter');
        $source ??= $this->beast(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $incarnation = $this->game->objects[$source]->incarnation;
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR)['cards']['Damnation']['card'];
        $this->game->active = $this->game->priority = $active;
        $this->lands($active, 'Swamp', 4);
        $this->game->cast($active, $this->game->addCard($active, $card, GameObject::HAND)->id);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(2, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame([$source, $incarnation, $controller, []], [$item['object'], $item['incarnation'], $item['controller'], $item['targets']]);
        }
        $this->game = Game::fromArray($this->saved());
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1 - $controller, false, false);
        $this->game = Game::fromArray($this->saved());
        $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame(5, $this->game->power($this->game->objects[$source]));
        $this->assertSame([], $this->game->objects[$source]->untilEndOfTurn);
        $this->assertSame([], $this->game->stack);
    }

    public static function deathOrders(): array
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
