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

/** Private fixed-cost Ward mode only; no whole-card or broad APNAP certificate. @covers \MTGPocket\Game\Game */
final class WardExecutionTest extends GameTestCase
{
    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function fixture(int $seat, array $card, string $zone = GameObject::BATTLEFIELD): int
    {
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @dataProvider owners */
    public function testPaidCachedDisguiseOffersResponseWindowAndDeclineCountersOnlySpell(int $owner): void
    {
        $this->newGame();
        if ($owner === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/dog-walker-disguise.json'));
        $this->assertSame('27a09d399cdd6cbe3e819319bd47c28c0b08295bc635020daa55f2f4fcca973c', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $ward = $this->fixture($owner, $card, GameObject::HAND);
        $this->lands($owner, 'Forest', 3);
        $this->game->cast($owner, $ward, 0, [], 'morph');
        $this->resolveOne();
        $this->game->setWardExecution(true);
        $payer = 1 - $owner;
        $this->game->pass($owner);
        $mana = $this->lands($payer, 'Mountain', 1)[0];
        $shock = $this->hand($payer, 'Shock');
        $receipt = $this->game->cast($payer, $shock, 0, ['o:'.$ward]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->assertSame($payer, $this->game->priority, 'The targeting player can respond before Ward resolves.');
        $this->assertCount(2, $this->game->stack);
        $trigger = $this->game->stack[1];
        $this->assertSame([], $trigger['targets']);
        $this->assertSame([], $trigger['kinds']);
        $this->assertSame($owner, $trigger['controller']);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('ward_payment', $choice['type']);
        $this->assertSame($payer, $choice['seat']);
        $this->assertSame($receipt['id'], $choice['offending']['id']);
        $this->assertFalse($this->game->canPayWard($payer));
        $this->game->chooseWardPayment($payer, $choice['id'], 'decline');
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($shock));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($ward));
        $this->assertSame(0, $this->game->objects[$ward]->damage);
        $this->assertSame(1, $this->game->players[$payer]->expended);
    }

    public function testFixedLifeWardAcceptsPaymentAtResolution(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, ['name' => 'Life Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '3',
            'text' => 'Ward—Pay 2 life.']);
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $this->assertSame(20, $this->life(0));
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertTrue($this->game->canPayWard(0));
        $pending = $this->saved();
        $this->game->chooseWardPayment(0, $choice['id'], 'accept');
        $this->assertSame(18, $this->life(0));
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseWardPayment(0, $choice['id'], 'accept');
        $this->assertSame($expected, $this->saved());
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$ward]->damage);
    }

    public static function owners(): array
    {
        return [[0], [1]];
    }
}
