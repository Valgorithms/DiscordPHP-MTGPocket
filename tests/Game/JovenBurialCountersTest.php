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

/** @covers \MTGPocket\Game\Game */
final class JovenBurialCountersTest extends GameTestCase
{
    private const UNREAD = '{R}{R}{R}, {T}: Gain control of target artifact until end of turn. Untap it. It gains haste until end of turn.';

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function selected(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        $entry = array_values(array_filter($entries, fn (array $entry) => $entry['card']['name'] === 'Joven and Chandler'))[0];
        $this->assertSame('MBC', $entry['setCode']);
        $this->assertSame('6e88e450-3e9c-575e-96b2-6b0ac6a9434d', $entry['card']['uuid']);

        return $entry['card'];
    }

    private function source(int $seat): int
    {
        $card = $this->selected();
        $definition = new CardDefinition($card);
        $this->assertSame([self::UNREAD], $definition->unsupported, 'The separate temporary-control ability remains unread.');
        $this->assertCount(1, $definition->triggersOn('artifact_to_graveyard_any'));
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function smelt(int $seat, int $target): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, 0, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    /** @dataProvider controllers */
    public function testPaidSourceAndSmeltUseBuriedManaValueAcrossControllersAndJson(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->source(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $artifact = $this->battlefield(1 - $artifactController, 'Bottle Gnomes');
        $this->game->objects[$artifact]->controller = $artifactController;
        $this->smelt($active, $artifact);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame($controller, $this->game->stack[0]['controller']);
        $this->assertSame([], $this->game->stack[0]['targets']);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(6, $this->game->power($this->game->objects[$source]));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($artifact) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
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
