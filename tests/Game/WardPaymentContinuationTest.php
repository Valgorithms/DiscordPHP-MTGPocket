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

/** Fixed-cost execution contracts; seeded damage effects isolate composition, not card certification. @covers \MTGPocket\Game\Game */
final class WardPaymentContinuationTest extends GameTestCase
{
    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(int $seat, array $card, string $zone = GameObject::BATTLEFIELD): int
    {
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

    private function ordering(): void
    {
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => 0, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $this->game->prevent['p:0'] = 1;
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid or replayed choice accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider orderingOptions */
    public function testWardManaPaymentSuspendsAndResumesExactlyOnce(string $first, int $life): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $this->ordering();
        $ward = $this->fixture(1, ['name' => 'Ward Payment Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward {B}']);
        $this->lands(0, 'Mountain', 1);
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/recover-damage-ordering.json'));
        $this->assertSame('c9f0b605834854b60e5415a12424d22b6127faf64d47a5b23c78512fce089d13', hash('sha256', $bytes));
        $river = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'], fn (array $c) => $c['name'] === 'Underground River'))[0];
        $mana = $this->fixture(0, $river);
        $spell = $this->hand(0, 'Shock');
        $receipt = $this->game->cast(0, $spell, 0, ['o:'.$ward]);
        $queued = $this->saved();
        $this->resolveOne();
        $optional = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('ward_payment', $choice['type']);
        $this->assertTrue($this->game->canPayWard(0));
        $this->reject(fn () => $this->game->chooseWardPayment(1, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseWardPayment(0, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseWardPayment(0, $choice['id'], 'bogus'));
        $this->game->chooseWardPayment(0, $choice['id'], 'accept');
        $pending = $this->saved();
        $order = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $order['type']);
        $this->assertSame([[['type' => 'ward_paid'], null]], $order['resume']['steps']);
        $this->assertSame(2, $this->game->players[0]->expended);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->assertSame($receipt['id'], $this->game->stack[0]['id']);
        $this->reject(fn () => $this->game->chooseWardPayment(0, $choice['id'], 'accept'));
        $this->game->chooseDamageOrder(0, $order['id'], $first);
        $this->assertSame($life, $this->life(0));
        $this->assertNull($this->game->choiceAwaiting());
        $done = $this->saved();
        foreach ([$queued, $optional, $pending] as $index => $saved) {
            $this->game = Game::fromArray($saved);
            if ($index === 0) {
                $this->resolveOne();
            }
            if ($index < 2) {
                $this->game->chooseWardPayment(0, $choice['id'], 'accept');
            }
            $this->game->chooseDamageOrder(0, $order['id'], $first);
            $this->assertSame($done, $this->saved());
        }
        $this->reject(fn () => $this->game->chooseDamageOrder(0, $order['id'], $first));
        $this->resolveOne();
        $this->assertSame(4, $this->game->objects[$ward]->damage);
        $this->assertSame(2, $this->game->players[0]->expended);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    public static function orderingOptions(): array
    {
        return [['double:1', 19], ['prevent', 20]];
    }

    public function testPaidActivationRetainsSnapshotUntilItsSourceSacrificeAndCommitAfterResume(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $this->ordering();
        $ward = $this->fixture(1, ['name' => 'Activation Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward {1}']);
        $source = $this->fixture(0, ['name' => 'Sacrifice Activation Unit Fixture', 'type' => 'Enchantment',
            'text' => '{G}, Sacrifice this enchantment: Target creature gets +1/+1 until end of turn.']);
        $old = $this->game->objects[$source]->incarnation;
        $mana = $this->fixture(0, ['name' => 'Painful Green Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1',
            'text' => 'Sacrifice this creature: Add {G}. CARDNAME deals 1 damage to you.']);
        $this->assertNull($this->game->activate(0, $source, 0, ['o:'.$ward]));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($mana));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $pending = $this->saved();
        $this->assertSame('activate', $pending['pendingDamage']['after']['kind']);
        $this->assertSame(1, $pending['wardExecution']['nextEventId']);
        $this->game = Game::fromArray($pending);
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertCount(2, $this->game->stack);
        $entry = $this->game->stack[0];
        $this->assertSame($pending['nextStackId'], $entry['id']);
        $this->assertSame($old, $entry['incarnation']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame($entry['id'], $this->game->stack[1]['effects'][0]['offending']['id']);
        $done = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($done, $this->saved());
        $this->resolveOne();
        $this->game->chooseWardPayment(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertSame([], $this->game->stack);
        $this->assertSame(1, $this->game->players[0]->expended);
    }

    public function testOriginalCastPaymentResumesBeforePublishingWardWithRemovedGrantSource(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $this->ordering();
        $ward = $this->fixture(1, ['name' => 'Granted Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Vigilance']);
        $grant = $this->fixture(0, ['name' => 'Painful Ward Aura Unit Fixture', 'type' => 'Enchantment - Aura',
            'text' => "Enchant creature\nEnchanted creature has ward {1}.\nSacrifice this enchantment: Add {R}. CARDNAME deals 1 damage to you."]);
        $this->game->objects[$grant]->attachedTo = $ward;
        $old = $this->game->objects[$grant]->incarnation;
        $spell = $this->hand(0, 'Shock');
        $this->assertNull($this->game->cast(0, $spell, 0, ['o:'.$ward]));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($grant));
        $this->assertSame([], $this->game->stack);
        $pending = $this->saved();
        $this->assertSame(1, $pending['wardExecution']['nextEventId']);
        $snapshot = $pending['pendingDamage']['after']['wardExecution'];
        $this->assertSame("attachment:{$grant}:{$old}:0", $snapshot['instances'][0]['key']);
        $this->assertSame(1, $this->game->players[0]->expended);
        $choice = $this->game->choiceAwaiting();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertCount(2, $this->game->stack);
        $trigger = $this->game->stack[1];
        $this->assertSame($spell, $this->game->stack[0]['object']);
        $this->assertSame($this->game->stack[0]['id'], $trigger['effects'][0]['offending']['id']);
        $this->assertSame("attachment:{$grant}:{$old}:0", $trigger['effects'][0]['instance']['key']);
        $this->assertSame(2, $this->saved()['wardExecution']['nextEventId']);
        $done = $this->saved();
        $this->reject(fn () => $this->game->chooseDamageOrder(0, $choice['id'], 'prevent'));
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($done, $this->saved());
        $this->resolveOne();
        $this->game->chooseWardPayment(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertSame([], $this->game->stack);
        $this->assertSame(1, $this->game->players[0]->expended);
    }
}
