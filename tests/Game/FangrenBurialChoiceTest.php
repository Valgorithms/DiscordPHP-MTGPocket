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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class FangrenBurialChoiceTest extends GameTestCase
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

    private function marauder(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Fangren Marauder'))[0];
        $this->assertSame('PLST', $entry['setCode']);
        $card = $entry['card'];
        $this->assertSame('4eb39ba0-12a9-5050-a83a-ca18a2b37b1f', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 6);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function damnation(int $seat): void
    {
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR)['cards']['Damnation']['card'];
        $this->game->active = $this->game->priority = $seat;
        $this->lands($seat, 'Swamp', 4);
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id);
    }

    /** @dataProvider orders */
    public function testPaidMarauderSeesBothPlayersArtifactsAndMakesIndependentOptionalChoices(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->marauder(1 - $controller) : null;
        foreach ([0, 1, 0, 1] as $seat) {
            $this->battlefield($seat, 'Ornithopter');
        }
        $source ??= $this->marauder(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->damnation($active);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(4, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame($controller, $item['controller']);
            $this->assertSame($incarnation, $item['incarnation']);
            $this->assertSame([], $item['targets'], 'Life gain does not target a player.');
        }
        $queued = $this->saved();
        $this->answerFour($controller);
        $this->assertSame(20, $this->life(1 - $controller));
        $this->assertSame(30, $this->life($controller));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($source) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->answerFour($controller);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function answerFour(int $seat): void
    {
        $last = 0;
        foreach (['accept', 'decline', 'accept', 'decline'] as $option) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame('optional_life_gain', $this->game->decision($seat));
            $this->assertSame(['accept', 'decline'], $choice['options']);
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalLifeGain($seat, $choice['id'], $option);
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
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
