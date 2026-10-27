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

/** Characterizes a retained rules gap; this does not certify Ward timing. @covers \MTGPocket\Game\Game */
final class WardUpfrontGapTest extends GameTestCase
{
    /** @dataProvider actors */
    public function testCachedDisguiseWardIsStillAnUpfrontTax(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/dog-walker-disguise.json'));
        $this->assertSame('27a09d399cdd6cbe3e819319bd47c28c0b08295bc635020daa55f2f4fcca973c', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $paid = $this->lands($actor, 'Forest', 3);
        $this->game->cast($actor, $id, 0, [], 'morph');
        $this->game->pass($actor);
        $this->game->pass(1 - $actor);
        foreach ($paid as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->assertSame(['mana' => '{2}'], $this->game->objects[$id]->definition()->ward);
        $opponent = 1 - $actor;
        $this->game->pass($actor);
        $this->lands($opponent, 'Mountain', 1);
        $shock = $this->hand($opponent, 'Shock');
        $before = $this->game->toArray();
        try {
            $this->game->cast($opponent, $shock, 0, ['o:'.$id]);
            $this->fail('Baseline gap changed: only the spell cost was affordable.');
        } catch (GameException $e) {
            $this->assertStringContainsString('and ward', $e->getMessage());
            $this->assertSame($before, $this->game->toArray());
        }
        $this->lands($opponent, 'Mountain', 2);
        $this->game->cast($opponent, $shock, 0, ['o:'.$id]);
        $this->assertCount(1, $this->game->stack, 'No separate Ward trigger exists: this remains unsupported.');
        $this->assertSame(0, count(array_filter($this->game->permanents($opponent), fn (GameObject $o) => $o->definition()->is('Land') && ! $o->tapped)));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame([], $this->game->pendingTriggers);
        $saved = $this->game->toArray();
        $this->game = Game::fromArray(json_decode(json_encode($saved, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($saved, $this->game->toArray());
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }
}
