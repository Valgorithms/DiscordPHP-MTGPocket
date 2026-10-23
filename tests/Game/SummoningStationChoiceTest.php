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
final class SummoningStationChoiceTest extends GameTestCase
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

    private function station(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Summoning Station'))[0];
        $this->assertSame('5DN', $entry['setCode']);
        $this->assertSame('972dafe2-254f-520f-be72-cb21ac19a5c4', $entry['card']['uuid']);
        $this->assertSame([], (new CardDefinition($entry['card']))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 7);
        $id = $this->game->addCard($seat, $entry['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function activateStation(int $seat, int $source): int
    {
        $this->game->priority = $seat;
        $this->assertTrue($this->game->canActivate($seat, $source, 0));
        $before = array_keys($this->game->objects);
        $this->game->activate($seat, $source, 0);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $this->assertFalse($this->game->canActivate($seat, $source, 0));
        $this->resolveOne();
        $added = array_values(array_diff(array_keys($this->game->objects), $before));
        $this->assertCount(1, $added);
        $token = $this->game->objects[$added[0]];
        $this->assertTrue($token->token);
        $this->assertSame(GameObject::BATTLEFIELD, $token->zone);
        $this->assertSame($seat, $token->controller);
        $this->assertFalse($token->definition()->is('Artifact'));
        $this->assertSame(['Pincher'], $token->definition()->subtypes);
        $this->assertSame([], $token->definition()->colors);
        $this->assertSame([2, 2], [$this->game->power($token), $this->game->toughness($token)]);

        return $token->id;
    }

    private function smelt(int $seat, int $artifact): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('bf9f48d0-70bb-59e6-9bb7-116a924204a4', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, ['o:'.$artifact]);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    /** @dataProvider controllers */
    public function testPaidStationActivationAndSmeltOfferOriginalControllerAnOptionalUntap(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->station(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->activateStation($controller, $source);
        $artifact = $this->battlefield(1 - $artifactController, 'Ornithopter');
        $this->game->objects[$artifact]->controller = $artifactController;
        $this->smelt($active, $artifact);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame([$controller, []], [$this->game->stack[0]['controller'], $this->game->stack[0]['targets']]);
        $queued = $this->saved();
        $this->resolveOne();
        $this->answer($controller, 'accept');
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->activateStation($controller, $source);
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if (($this->game->stack[0]['kind'] ?? null) !== 'ability') {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->answer($controller, 'accept');
            $this->activateStation($controller, $source);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function answer(int $seat, string $option): void
    {
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_source_untap', $this->game->decision($seat));
        $this->assertSame(['accept', 'decline'], $choice['options']);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalSourceUntap($seat, $choice['id'], $option);
        $this->assertNull($this->game->choiceAwaiting());
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
