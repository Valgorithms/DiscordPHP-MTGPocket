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
use MTGPocket\Tutorial\PracticeBot;

/** Exact cached Garza half-life Recover only; no whole-card certificate. @covers \MTGPocket\Game\Game */
final class GarzaRecoverLifeCostTest extends GameTestCase
{
    private function cached(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/garza-assassin-recover.json'));
        $this->assertSame('2e7a9a0a4a53cdbb774c75a4e01ad583de243fdd731579e01e2ea09e65bda04a', hash('sha256', $bytes));

        return array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'], fn ($card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function paidDeath(int $owner, int $life = 20): array
    {
        $this->newGame();
        if ($owner === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->lands($owner, 'Mountain', 1);
        $victim = $this->game->addCard($owner, $this->cached('Raging Goblin'), GameObject::HAND)->id;
        $this->game->cast($owner, $victim);
        $this->resolveOne();
        $this->lands($owner, 'Swamp', 3);
        $assassin = $this->game->addCard($owner, $this->cached("Garza's Assassin"), GameObject::HAND)->id;
        $this->game->cast($owner, $assassin);
        $this->resolveOne();
        $this->assertSame(4, $this->game->players[$owner]->expended);
        $this->game->players[$owner]->life = $life;
        $this->game->activate($owner, $assassin, 0, ['o:'.$victim]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($assassin), 'Sacrifice pays before its independent ability destroys the Goblin.');
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($assassin));

        return [$assassin, $victim];
    }

    /** @dataProvider owners */
    public function testPaidAssassinSacrificeThenOwnCreatureDeathOffersHalfLifeRecover(int $owner): void
    {
        [$assassin] = $this->paidDeath($owner);
        $this->assertCount(1, $this->game->stack, 'The already-buried Assassin must earn a Recover trigger from the later creature death.');
        $queued = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_recover', $choice['type']);
        $this->assertSame($owner, $choice['seat']);
        $this->assertSame('half your life, rounded up', $choice['cost']);
        $this->assertTrue($this->game->canPayRecover($owner));
        $this->game->chooseRecover($owner, $choice['id'], 'accept');
        $this->assertSame(10, $this->life($owner));
        $this->assertSame(GameObject::HAND, $this->zone($assassin));
        $done = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->game->chooseRecover($owner, $choice['id'], 'accept');
        $this->assertSame($done, $this->saved());
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid or replayed Recover answer accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider rounding */
    public function testHalfCurrentLifeRoundsUpAndPaysExactlyOnce(int $owner, int $life, int $left): void
    {
        [$assassin] = $this->paidDeath($owner, $life);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $pending = $this->saved();
        $this->assertSame([], (new CardDefinition($this->cached("Garza's Assassin")))->unsupported);
        $this->assertTrue($this->game->canPayRecover($owner));
        $this->assertFalse($this->game->canPayRecover(1 - $owner));
        $this->reject(fn () => $this->game->chooseRecover(1 - $owner, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseRecover($owner, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseRecover($owner, $choice['id'], 'both'));
        $this->game->chooseRecover($owner, $choice['id'], 'accept');
        $this->assertSame($left, $this->life($owner));
        $this->assertSame(GameObject::HAND, $this->zone($assassin));
        $this->assertSame(4, $this->game->players[$owner]->expended, 'The life cost spends no additional mana.');
        $this->assertSame($left === 0 ? Game::OVER : Game::PLAYING, $this->game->stage);
        $done = $this->saved();
        $this->reject(fn () => $this->game->chooseRecover($owner, $choice['id'], 'accept'));
        $this->game = Game::fromArray($pending);
        $this->game->chooseRecover($owner, $choice['id'], 'accept');
        $this->assertSame($done, $this->saved());
    }

    public static function rounding(): array
    {
        $cases = [];
        foreach ([0, 1] as $seat) {
            foreach ([[19, 9], [18, 9], [3, 1], [2, 1], [1, 0]] as [$life, $left]) {
                $cases[] = [$seat, $life, $left];
            }
        }

        return $cases;
    }

    /** @dataProvider owners */
    public function testDeclineDoesNotPayLifeAndExilesOnlyOriginalCard(int $owner): void
    {
        [$assassin, $victim] = $this->paidDeath($owner, 19);
        $this->resolveOne();
        $this->game->chooseRecover($owner, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertSame(19, $this->life($owner));
        $this->assertSame(GameObject::EXILE, $this->zone($assassin));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        $this->assertSame(4, $this->game->players[$owner]->expended);
    }

    public function testPaidResponseChangesLifeBeforeRecoverCostIsEvaluated(): void
    {
        [$assassin] = $this->paidDeath(0);
        $this->lands(1, 'Mountain', 1);
        $bolt = $this->game->addCard(1, $this->cached('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->pass(0);
        $this->game->cast(1, $bolt, 0, ['p:0']);
        $this->resolveOne();
        $this->assertSame(17, $this->life(0));
        $this->resolveOne();
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(8, $this->life(0), 'Pay ceil(17/2), not the ten life it would have cost when the trigger was created.');
        $this->assertSame(GameObject::HAND, $this->zone($assassin));
        $this->assertSame(1, $this->game->players[1]->expended);
    }

    public function testLifePaymentIsNotDamageAndConsumesNoPreventionOrDoubling(): void
    {
        [$assassin] = $this->paidDeath(0, 19);
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => 0, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $this->game->prevent['p:0'] = 8;
        $this->resolveOne();
        $effects = $this->saved()['damageEffects'];
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(9, $this->life(0));
        $this->assertSame(8, $this->game->prevent['p:0']);
        $this->assertSame($effects, $this->saved()['damageEffects']);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(GameObject::HAND, $this->zone($assassin));
    }

    /** @dataProvider decisions */
    public function testReturnedSourceCannotBeMovedByTheOlderRecoverChoice(string $option): void
    {
        [$assassin] = $this->paidDeath(0, 19);
        $this->resolveOne();
        $object = $this->game->objects[$assassin];
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $object, 0);
        $old = $object->toArray();
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame($old, $this->game->objects[$assassin]->toArray());
        $this->assertSame($option === 'accept' ? 9 : 19, $this->life(0));
    }

    public static function decisions(): array
    {
        return [['accept'], ['decline']];
    }

    /** @dataProvider oldStages */
    public function testNewHalfLifeRecoveryCannotBeInjectedIntoSchema20(string $stage): void
    {
        $this->paidDeath(0);
        if ($stage === 'optional') {
            $this->resolveOne();
        }
        $state = $this->saved();
        $state['schemaVersion'] = 20;
        $this->reject(fn () => Game::fromArray($state));
    }

    public static function oldStages(): array
    {
        return [['queued'], ['optional']];
    }

    public function testOlderFixedWardPendingChoiceRemainsReadableInSchema20(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->game->addCard(1, ['name' => 'Old Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward {1}'], GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $this->resolveOne();
        $state = $this->saved();
        $state['schemaVersion'] = 20;
        $this->game = Game::fromArray($state);
        $this->assertSame('ward_payment', $this->game->choiceAwaiting()['type']);
        $this->game->chooseWardPayment(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider practiceLife */
    public function testPracticePolicyAvoidsSpendingItsLastLife(int $life, bool $accept): void
    {
        [$assassin] = $this->paidDeath(0, $life);
        $this->resolveOne();
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame($accept ? GameObject::HAND : GameObject::EXILE, $this->zone($assassin));
        $this->assertSame($accept ? 1 : $life, $this->life(0));
        $this->assertSame(Game::PLAYING, $this->game->stage);
    }

    public static function practiceLife(): array
    {
        return [[1, false], [3, true]];
    }

    public function testOpponentOwnedCreatureDeathDoesNotEarnRecover(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 3);
        $assassin = $this->game->addCard(0, $this->cached("Garza's Assassin"), GameObject::HAND)->id;
        $this->game->cast(0, $assassin);
        $this->resolveOne();
        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Mountain', 1);
        $goblin = $this->game->addCard(1, $this->cached('Raging Goblin'), GameObject::HAND)->id;
        $this->game->cast(1, $goblin);
        $this->resolveOne();
        $this->game->pass(1);
        $this->game->activate(0, $assassin, 0, ['o:'.$goblin]);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($assassin));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($goblin));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(20, $this->life(0));
    }

    public function testCounteredRecoverPaysNothingAndLeavesSourceInGraveyard(): void
    {
        [$assassin] = $this->paidDeath(0, 19);
        $item = $this->game->stack[0];
        // Existing counter primitive only; no ability-counter card certificate.
        (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke($this->game, ['type' => 'counter'], $this->game->objects[$assassin], null, 1, 0, 's:'.$item['id']);
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(19, $this->life(0));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($assassin));
    }

    public function testSchema20ManaRecoverChoiceStillLoadsAndPays(): void
    {
        $this->newGame();
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/recover-damage-ordering.json'), true, flags: JSON_THROW_ON_ERROR);
        $card = array_values(array_filter($fixture['cards'], fn (array $card) => $card['name'] === 'Grim Harvest'))[0];
        $harvest = $this->game->addCard(0, $card, GameObject::GRAVEYARD)->id;
        $mana = $this->lands(0, 'Swamp', 3);
        // Compatibility isolation: original paid death paths are covered in RecoverDamageOrderingTest.
        $victim = $this->game->addCard(0, $this->cached('Raging Goblin'), GameObject::BATTLEFIELD);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $victim, GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->resolveOne();
        $state = $this->saved();
        $state['schemaVersion'] = 20;
        $this->game = Game::fromArray($state);
        $this->assertSame('{2}{B}', $this->game->choiceAwaiting()['cost']);
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(GameObject::HAND, $this->zone($harvest));
        $this->assertSame(20, $this->life(0));
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
    }

    public function testOtherLifeRecoverWordingRemainsExplicitlyUnread(): void
    {
        foreach (['Recover—Pay 3 life.', 'Recover—Pay half your life, rounded down.', 'Recover—Discard a card.'] as $text) {
            // Synthetic parser negatives only: no card support claim from these variants.
            $card = $this->cached("Garza's Assassin");
            $card['text'] = $text;
            $definition = new CardDefinition($card);
            $this->assertArrayNotHasKey('recover', $definition->altCosts);
            $this->assertSame([$text], $definition->unsupported);
        }
    }

    public static function owners(): array
    {
        return [[0], [1]];
    }
}
