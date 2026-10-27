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

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Synthetic contracts for existing action continuations, not card certificates.
 * QuestPureFlameTest separately proves the paid cached-card path.
 * @covers \MTGPocket\Game\Game
 */
final class PaidDamageContinuationTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @dataProvider utilities */
    public function testExistingUtilityCostPausesAndResumesItsTailExactlyOnce(string $action, string $text, string $from, string $to, int $stack): void
    {
        $this->newGame();
        if (in_array($action, ['bloodrush', 'ninjutsu'], true)) {
            $attacker = $this->battlefield(0, 'Raging Goblin');
            $this->passUntil(Step::DeclareAttackers);
            $this->game->declareAttackers(0, [$attacker]);
            $this->passUntil(Step::DeclareBlockers);
        }
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => 0, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $count = $action === 'foretell' ? 2 : 1;
        $mana = [];
        for ($n = 0; $n < $count; $n++) {
            $mana[] = $this->game->addCard(0, ['uuid' => 'contract-painer-'.$n, 'name' => 'Painer',
                'type' => 'Land', 'text' => '{T}: Add {R}. Painer deals 1 damage to you.'], GameObject::BATTLEFIELD)->id;
        }
        $card = $this->game->addCard(0, ['uuid' => 'contract-utility-'.$action, 'name' => 'Utility', 'type' => 'Creature - Human',
            'power' => '1', 'toughness' => '1', 'manaCost' => '{R}', 'text' => $text, 'colors' => ['R']], $from)->id;
        $this->game->prevent['p:0'] = 1;
        if ($action === 'embalm') {
            $this->game->embalm(0, $card, 'embalm');
        } else {
            $this->game->{$action}(0, $card);
        }
        $this->assertSame($from, $this->zone($card));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(20, $this->life(0));
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $pending = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $choice['type']);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($to, $this->zone($card));
        $this->assertCount($stack, $this->game->stack);
        $this->assertSame($count, $this->game->players[0]->expended);
        $this->assertSame($count === 2 ? 18 : 20, $this->life(0));
        $expected = $this->saved();
        try {
            $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
            $this->fail('A replayed payment was accepted.');
        } catch (GameException) {
            $this->assertSame($expected, $this->saved());
        }
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($expected, $this->saved());
    }

    public static function utilities(): array
    {
        return [
            ['cycle', 'Cycling {R}', GameObject::HAND, GameObject::GRAVEYARD, 1],
            ['unearth', 'Unearth {R}', GameObject::GRAVEYARD, GameObject::GRAVEYARD, 1],
            ['plot', 'Plot {R}', GameObject::HAND, GameObject::EXILE, 0],
            ['foretell', 'Foretell {R}', GameObject::HAND, GameObject::EXILE, 0],
            ['transmute', 'Transmute {R}', GameObject::HAND, GameObject::GRAVEYARD, 1],
            ['bloodrush', 'Bloodrush — {R}, Discard Utility: Target attacking creature gets +1/+1 until end of turn.', GameObject::HAND, GameObject::GRAVEYARD, 1],
            ['ninjutsu', 'Ninjutsu {R}', GameObject::HAND, GameObject::HAND, 1],
            ['suspend', 'Suspend 2—{R}', GameObject::HAND, GameObject::EXILE, 0],
            ['regrow', '{R}: Return Utility from your graveyard to your hand.', GameObject::GRAVEYARD, GameObject::GRAVEYARD, 1],
            ['embalm', 'Embalm {R}', GameObject::GRAVEYARD, GameObject::EXILE, 1],
        ];
    }

    private function enginePaymentGame(): int
    {
        $this->newGame();
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => 0, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $mana = $this->game->addCard(0, ['uuid' => 'contract-painer', 'name' => 'Painer', 'type' => 'Land',
            'text' => '{T}: Add one mana of any color. Painer deals 1 damage to you.'], GameObject::BATTLEFIELD)->id;
        $this->game->prevent['p:0'] = 1;

        return $mana;
    }

    private function queue(array $effects, int $source): void
    {
        $push = new \ReflectionMethod(Game::class, 'pushAbility');
        $push->invoke($this->game, ['source' => $source, 'incarnation' => $this->game->objects[$source]->incarnation,
            'controller' => 0, 'effects' => $effects, 'kinds' => [], 'label' => 'Engine contract'], [], 'is activated');
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    /** @dataProvider resolutionPayments */
    public function testPaidResolutionContinuationRunsItsEffectsAfterDamageOnce(array $effect, int $life, int $draw): void
    {
        $mana = $this->enginePaymentGame();
        $source = $this->battlefield(0, 'Grizzly Bears');
        $hand = count($this->game->players[0]->hand);
        $this->queue([$effect], $source);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $this->assertCount($hand, $this->game->players[0]->hand);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $choice['type']);
        $pending = $this->saved();
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($life, $this->life(0));
        $this->assertSame($effect['type'] === 'extort' ? 19 : 20, $this->life(1));
        $this->assertCount($hand + $draw, $this->game->players[0]->hand);
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($expected, $this->saved());
    }

    public static function resolutionPayments(): array
    {
        return [
            [['type' => 'pay', 'cost' => '{R}', 'then' => [['type' => 'draw', 'amount' => 1]]], 20, 1],
            [['type' => 'extort'], 21, 0],
        ];
    }

    public function testOptionalPaymentPreservesTokenAndRemainingResolutionAcrossDamageChoice(): void
    {
        $this->enginePaymentGame();
        $source = $this->battlefield(0, 'Grizzly Bears');
        $this->queue([['type' => 'optional_red_goblin'], ['type' => 'gain_life', 'amount' => 2]], $source);
        $optional = $this->game->choiceAwaiting();
        $this->game->chooseOptionalRedGoblin(0, $optional['id'], 'accept');
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $choice['type']);
        $this->assertSame(20, $this->life(0));
        $pending = $this->saved();
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $tokens = array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->definition()->isToken());
        $this->assertCount(1, $tokens);
        $this->assertSame(22, $this->life(0));
        $this->assertSame([], $this->game->stack);
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($expected, $this->saved());
    }

    public function testAutomaticWardPaymentStopsFlushingOtherTriggersUntilAnswered(): void
    {
        $this->enginePaymentGame();
        $source = $this->battlefield(0, 'Grizzly Bears');
        $victim = $this->game->addCard(1, ['uuid' => 'contract-ward', 'name' => 'Ward Body', 'type' => 'Creature - Human',
            'power' => '1', 'toughness' => '1', 'text' => 'Ward {R}'], GameObject::BATTLEFIELD)->id;
        $this->game->pendingTriggers = [
            ['source' => $source, 'incarnation' => $this->game->objects[$source]->incarnation, 'controller' => 0,
                'effects' => [['type' => 'destroy', 'target' => 'creature_opponent']], 'kinds' => ['creature_opponent'], 'label' => 'Ward target', 'text' => 'Engine contract'],
            ['source' => $source, 'incarnation' => $this->game->objects[$source]->incarnation, 'controller' => 0,
                'effects' => [['type' => 'draw', 'amount' => 1]], 'kinds' => [], 'label' => 'Later trigger', 'text' => 'Engine contract'],
        ];
        // Settle the synthetic queued events; the sole opponent creature is the target.
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $choice['type']);
        $this->assertSame([], $this->game->stack);
        $this->assertCount(1, $this->game->pendingTriggers);
        $pending = $this->saved();
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertCount(2, $this->game->stack);
        $this->assertSame([], $this->game->pendingTriggers);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($victim));
        $expected = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider sourceTriggerModes */
    public function testLiveDamageSourceKeepsItsOncePerTurnTriggerState(bool $doubling): void
    {
        $this->newGame();
        if ($doubling) {
            $saved = $this->saved();
            $saved['damageEffects'] = [['id' => 1, 'controller' => 0, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
            $saved['nextDamageEffectId'] = 2;
            $this->game = Game::fromArray($saved);
        }
        $this->lands(0, 'Mountain', 2);
        $source = $this->game->addCard(0, ['uuid' => 'contract-once-source', 'name' => 'Once Source', 'type' => 'Creature - Human',
            'power' => '1', 'toughness' => '1', 'text' => "{R}: Once Source deals 1 damage to any target.\nWhenever Once Source deals damage to a player, draw a card. This ability triggers only once each turn."], GameObject::BATTLEFIELD)->id;
        $hand = count($this->game->players[0]->hand);
        $this->game->activate(0, $source, 0, ['p:1']);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertCount(1, $this->game->stack);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertCount($hand + 1, $this->game->players[0]->hand);
        $this->game->activate(0, $source, 0, ['p:1']);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame([], $this->game->stack);
        $this->assertCount($hand + 1, $this->game->players[0]->hand);
        $this->assertSame($doubling ? 16 : 18, $this->life(1));
    }

    public static function sourceTriggerModes(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider sourceTriggerModes */
    public function testDepartedSourceDoesNotRetainBattlefieldTriggeredAbilities(bool $doubling): void
    {
        $this->newGame();
        if ($doubling) {
            $saved = $this->saved();
            $saved['damageEffects'] = [['id' => 1, 'controller' => 0, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
            $saved['nextDamageEffectId'] = 2;
            $this->game = Game::fromArray($saved);
        }
        $this->lands(0, 'Mountain', 1);
        $source = $this->game->addCard(0, ['uuid' => 'contract-departed-source', 'name' => 'Departed Source', 'type' => 'Creature - Human',
            'power' => '1', 'toughness' => '1', 'text' => "{R}, Sacrifice Departed Source: Departed Source deals 1 damage to any target.\nWhenever Departed Source deals damage, you gain that much life."], GameObject::BATTLEFIELD)->id;
        $this->game->activate(0, $source, 0, ['p:1']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $queued = $this->saved();
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame($doubling ? 18 : 19, $this->life(1));
        $this->assertSame(20, $this->life(0));
        $this->assertSame([], $this->game->stack, 'The departed creature no longer has an observing battlefield trigger.');
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame($expected, $this->saved());
    }
}
