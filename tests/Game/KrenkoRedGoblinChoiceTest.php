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
final class KrenkoRedGoblinChoiceTest extends GameTestCase
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

    private function krenko(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Krenko, Baron of Tin Street'))[0];
        $this->assertSame('MKM', $entry['setCode']);
        $this->assertSame('026a8fcb-c5db-50c9-9c9c-13db04701f21', $entry['card']['uuid']);
        $this->assertSame([], (new CardDefinition($entry['card']))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 3);
        $id = $this->game->addCard($seat, $entry['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'haste'));

        return $id;
    }

    private function smelt(int $seat, int $artifact): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, 0, ['o:'.$artifact]);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    private function goblins(int $seat): array
    {
        return array_values(array_filter($this->game->objects, fn (GameObject $object) => $object->zone === GameObject::BATTLEFIELD && $object->controller === $seat && $object->definition()->isToken() && in_array('Goblin', $object->definition()->subtypes, true)));
    }

    /** @dataProvider controllers */
    public function testPaidKrenkoAndSmeltOfferOriginalControllerRedPaymentAndTemporaryHaste(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->krenko(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
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
        $this->assertSame('optional_red_goblin', $this->game->decision($controller));
        $this->assertSame([], $this->goblins($controller));
        $pending = $this->saved();
        $this->payAndAssertToken($controller);
        $expected = $this->saved();
        foreach ([$before, $queued, $pending] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->choiceAwaiting() === null) {
                if (($this->game->stack[0]['kind'] ?? null) !== 'ability') {
                    $this->resolveOne();
                }
                $this->resolveOne();
            }
            $this->payAndAssertToken($controller);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function payAndAssertToken(int $seat): void
    {
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $choice = $this->game->choiceAwaiting();
        $this->assertTrue($this->game->canPayOptionalRedGoblin($seat));
        $this->game->chooseOptionalRedGoblin($seat, $choice['id'], 'accept');
        $this->assertTrue($this->game->objects[$land]->tapped);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount(1, $tokens = $this->goblins($seat));
        $token = $tokens[0];
        $this->assertSame($seat, $token->owner);
        $this->assertSame(['R'], $token->definition()->colors);
        $this->assertSame(['Goblin'], $token->definition()->subtypes);
        $this->assertFalse($token->definition()->is('Artifact'));
        $this->assertSame([1, 1], [$this->game->power($token), $this->game->toughness($token)]);
        $this->assertTrue($token->sick);
        $this->assertTrue($this->game->hasKeyword($token, 'haste'));
        $this->game->active = $seat;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($seat, [$token->id]);
        $this->assertArrayHasKey($token->id, $this->game->attackers);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(GameObject::BATTLEFIELD, $token->zone);
        $this->assertFalse($this->game->hasKeyword($token, 'haste'));
        $this->assertSame([1, 1], [$this->game->power($token), $this->game->toughness($token)]);
    }

    public static function controllers(): array
    {
        return SummoningStationChoiceTest::controllers();
    }
}
