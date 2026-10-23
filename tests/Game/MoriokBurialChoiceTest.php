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

use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** @covers \MTGPocket\Game\Game */
final class MoriokBurialChoiceTest extends GameTestCase
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

    private function rigger(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Moriok Rigger'))[0];
        $this->assertSame('5DN', $entry['setCode']);
        $card = $entry['card'];
        $this->assertSame('0bb7d751-e71c-5e5e-8a39-1de6aca509ca', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Swamp', 3);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(0, $this->game->objects[$id]->counter('+1/+1'));

        return $id;
    }

    private function shatterstorm(int $seat): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'));
        $this->assertSame('9bfd9ecb99db8022a5bc52b775f1e66c8d06ed65c849f8fd202d41a6d8c9bcae', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 4);
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
    }

    /** @dataProvider orders */
    public function testPaidRiggerAndStolenArtifactsOfferIndependentCountersWithJsonAndCleanup(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->rigger(1 - $controller) : null;
        foreach ([0, 1] as $owner) {
            $artifact = $this->battlefield($owner, 'Ornithopter');
            $this->game->objects[$artifact]->controller = 1 - $owner;
        }
        $source ??= $this->rigger(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->shatterstorm($active);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertCount(2, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame($controller, $item['controller']);
            $this->assertSame([], $item['targets']);
        }
        $queued = $this->saved();
        $this->answerTwo($source, $controller);
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if (count($this->game->stack) === 1) {
                $this->resolveOne();
            }
            $this->answerTwo($source, $controller);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function answerTwo(int $source, int $seat): void
    {
        $last = 0;
        foreach (['accept', 'decline'] as $option) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame('optional_source_counter', $this->game->decision($seat));
            $this->assertSame(['accept', 'decline'], $choice['options']);
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->assertSame($option === 'accept' ? 0 : 1, $this->game->objects[$source]->counter('+1/+1'));
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalSourceCounter($seat, $choice['id'], $option);
        }
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame([], $this->game->stack);
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame([3, 3], [$this->game->power($this->game->objects[$source]), $this->game->toughness($this->game->objects[$source])]);
        $this->assertStringContainsString(' 3/3', MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function orders(): array
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
}
