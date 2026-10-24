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

/** @covers \MTGPocket\Game\Game */
final class SoulStairExpeditionReturnsTest extends GameTestCase
{
    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/soul-stair-expedition-returns.json'));
        $this->assertSame('61a718050f3e11e158c08640c538218d38dda28135337bedb0d44d11959e231f', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][0];
        $this->assertSame('22b41c42-6a08-5efd-9959-b125f48535cf', $card['uuid']);

        return $card;
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

    private function expedition(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $mana = $this->lands($actor, 'Swamp', 1)[0];
        $card = $this->fixture();
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));

        return $source;
    }

    private function charge(int $actor, int $source): void
    {
        for ($n = 1; $n <= 3; $n++) {
            if ($this->game->players[$actor]->landsPlayed > 0) {
                $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
            }
            $this->game->playLand($actor, $this->hand($actor, 'Swamp'));
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->assertSame($n, $this->game->objects[$source]->counter('quest'));
        }
    }

    /** @dataProvider actors */
    public function testPaidCastAndThreeLandfallsReturnTwoOwnCreatures(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $a = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $b = $this->game->addCard($actor, self::card('Ornithopter'), GameObject::GRAVEYARD)->id;
        $this->game->activate($actor, $source, 0, ["o:{$a}", "o:{$b}"]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->resolveOne();
        $this->assertSame([GameObject::HAND, GameObject::HAND], [$this->zone($a), $this->zone($b)]);
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Expected an invalid action to reject.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider actors */
    public function testDuplicateTargetsRejectBeforeQuestAndSacrificeCosts(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $a = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ["o:{$a}", "o:{$a}"]));
    }
    public static function actors(): array
    {
        return [[0], [1]];
    }
}
