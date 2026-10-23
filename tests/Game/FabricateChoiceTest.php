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
final class FabricateChoiceTest extends GameTestCase
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

    private function castMaster(int $seat = 0): int
    {
        $this->newGame();
        $this->game->active = $this->game->priority = $seat;
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-observer-marionette-master.json'));
        $this->assertSame('7f7ce7cdddcd200976510eebe22ccead17a0e7eea68ff7c179e990f02f05b335', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $lands = $this->lands($seat, 'Swamp', 6);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertNull($this->game->choiceAwaiting(), 'The choice is made when the ETB ability resolves, not on entry.');
        $this->assertCount(1, $this->game->stack);

        return $id;
    }

    private function servos(int $seat): array
    {
        return array_values(array_filter($this->game->permanents($seat), fn (GameObject $object) => $object->definition()->isToken() && in_array('Servo', $object->definition()->subtypes, true)));
    }

    /** @dataProvider choices */
    public function testPaidEntryChoicesAndJsonReplay(int $seat, string $option): void
    {
        $id = $this->castMaster($seat);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame('fabricate', $this->game->decision($seat));
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['counters', 'servos'], $choice['options']);
        $pending = $this->saved();
        $this->game->chooseFabricate($seat, $choice['id'], $option);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($option === 'counters' ? 4 : 1, $this->game->power($this->game->objects[$id]));
        $this->assertCount($option === 'servos' ? 3 : 0, $this->servos($seat));
        foreach ($this->servos($seat) as $servo) {
            $this->assertSame($seat, $servo->owner);
            $this->assertSame([1, 1], [$this->game->power($servo), $this->game->toughness($servo)]);
            $this->assertSame([], $servo->definition()->colors);
            $this->assertTrue($servo->definition()->is('Artifact'));
        }
        $expected = $this->saved();
        foreach ([$queued, $pending] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->choiceAwaiting() === null) {
                $this->resolveOne();
            }
            $this->game->chooseFabricate($seat, $this->game->choiceAwaiting()['id'], $option);
            $this->assertSame($expected, $this->saved());
        }
    }

    public static function choices(): array
    {
        return [[0, 'counters'], [0, 'servos'], [1, 'counters'], [1, 'servos']];
    }

    public function testSourceRemovedInResponseCreatesServosWithoutAChoice(): void
    {
        $id = $this->castMaster();
        $this->lands(0, 'Island', 1);
        $this->game->cast(0, $this->hand(0, 'Unsummon'), 0, ["o:{$id}"]);
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($id));
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount(3, $this->servos(0));
        $this->assertSame(0, $this->game->objects[$id]->counter('+1/+1'));
    }
}
