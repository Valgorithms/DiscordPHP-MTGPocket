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

/** Private failing rules probes only; no production correction or card certificate. */
final class MadnessTriggerOrderingReproductionTest extends GameTestCase
{
    private function cached(string $name): array
    {
        $raw = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/madness-trigger-ordering.json'));
        $this->assertSame('7bb25b92eb0e697b0955055a41e622d295dd9da23238173cf92d0b1fded78a5f', hash('sha256', $raw));

        return array_values(array_filter(json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['cards'], fn ($card) => $card['name'] === $name))[0];
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

    private function paidDiscard(int $owner): array
    {
        $this->newGame();
        if ($owner === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        // Isolate the old automatic additional-cost chooser: exactly one available fodder.
        // Clearing a drawn test Forest is setup, not a player-selected discard-cost API.
        foreach ($this->game->players[$owner]->hand as $id) {
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
        $this->lands($owner, 'Mountain', 2);
        $this->lands($owner, 'Swamp', 2);
        $target = $this->battlefield(1 - $owner, 'Grizzly Bears');
        $this->game->objects[$target]->tapped = true; // Target isolation; no Bears cast certificate.
        $compulsionCard = $this->cached('Murderous Compulsion');
        $voiceCard = $this->cached('Tormenting Voice');
        $this->assertSame([], (new CardDefinition($compulsionCard))->unsupported);
        $this->assertSame([], (new CardDefinition($voiceCard))->unsupported);
        $compulsion = $this->game->addCard($owner, $compulsionCard, GameObject::HAND)->id;
        $handIncarnation = $this->game->objects[$compulsion]->incarnation;
        $voice = $this->game->addCard($owner, $voiceCard, GameObject::HAND)->id;
        $this->game->cast($owner, $voice);
        $this->assertSame(2, $this->game->players[$owner]->expended, 'Actual {1}{R} paid before probing the Madness window.');
        $this->assertSame(GameObject::STACK, $this->zone($voice));
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->assertSame('madness', $this->game->objects[$compulsion]->exiledBy);
        $this->assertSame($handIncarnation + 1, $this->game->objects[$compulsion]->incarnation);
        $triggers = array_values(array_filter($this->game->stack, fn ($item) => $item['object'] === $compulsion && ($item['effects'] ?? null) === [['type' => 'madness']]));
        $this->assertCount(1, $triggers);
        $this->assertCount(2, $this->game->stack, 'Original paid Voice remains below the Madness trigger.');
        $this->assertSame($triggers[0]['id'], $this->game->stack[array_key_last($this->game->stack)]['id']);

        return [$compulsion, $voice, $target, $triggers[0]];
    }

    /** @dataProvider queuedSeats */
    public function testDirectMadnessCastBeforeItsTriggerResolvesMustRejectAtomically(int $owner, bool $restore): void
    {
        [$compulsion, $voice, $target, $trigger] = $this->paidDiscard($owner);
        if ($restore) {
            $this->game = Game::fromArray($this->saved());
        }
        $before = $this->saved();
        $rejected = false;
        try {
            $this->game->cast($owner, $compulsion, 0, ['o:'.$target], 'md');
        } catch (GameException) {
            $rejected = true;
        }
        $observed = ['rejected' => $rejected, 'restore' => $restore, 'expendedBefore' => $before['players'][$owner]['expended'],
            'expendedAfter' => $this->game->players[$owner]->expended, 'compulsionZone' => $this->zone($compulsion),
            'originalVoiceZone' => $this->zone($voice), 'madnessTriggerStillWaiting' => in_array($trigger['id'], array_column($this->game->stack, 'id'), true)];
        $this->assertTrue($rejected, 'No cast permission before the Madness trigger resolves: '.json_encode($observed, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $this->saved());
        $this->assertNotContains(['id' => $compulsion, 'how' => 'md'], $this->game->plays($owner));
    }

    public static function queuedSeats(): array
    {
        return [[0, false], [1, false], [0, true], [1, true]];
    }

    /** @dataProvider owners */
    public function testResolvingMadnessTriggerMustOfferCastOrDeclineInsteadOfAutomaticBurial(int $owner): void
    {
        [$compulsion, $voice] = $this->paidDiscard($owner);
        $this->resolveOne();
        $this->assertNotNull($this->game->choiceAwaiting(), 'Resolution must offer optional casting; actual card zone '.$this->zone($compulsion));
        $this->assertSame($owner, $this->game->choiceAwaiting()['seat']);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->assertSame(GameObject::STACK, $this->zone($voice));
        $this->assertSame(2, $this->game->players[$owner]->expended);
    }

    private function counterTrigger(int $owner): array
    {
        [$compulsion, $voice, $target, $trigger] = $this->paidDiscard($owner);
        // Existing counter primitive only; no Stifle recognition/card claim.
        (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke($this->game, ['type' => 'counter'], $this->game->objects[$compulsion], null, 1 - $owner, 0, 's:'.$trigger['id']);

        return [$compulsion, $voice, $target];
    }

    /** @dataProvider owners */
    public function testCounteredTriggerLeavesCardExiledAndDoesNotRepayCostsControl(int $owner): void
    {
        [$compulsion, $voice] = $this->counterTrigger($owner);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->assertSame(GameObject::STACK, $this->zone($voice));
        $this->assertSame(2, $this->game->players[$owner]->expended);
        $this->assertCount(1, $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
    }

    /** @dataProvider owners */
    public function testCounteredMadnessTriggerMustNotLeaveGlobalExileCastingPermission(int $owner): void
    {
        [$compulsion] = $this->counterTrigger($owner);
        $this->game = Game::fromArray($this->saved());
        $this->assertFalse($this->game->canCast($owner, $compulsion, 'md'), 'A countered trigger cannot grant Madness permission from a stale exiledBy marker.');
        $this->assertNotContains(['id' => $compulsion, 'how' => 'md'], $this->game->plays($owner));
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
    }

    /** @dataProvider owners */
    public function testOlderMadnessTriggerCannotMoveOrOfferNewExileIncarnation(int $owner): void
    {
        [$compulsion, $voice, , $trigger] = $this->paidDiscard($owner);
        $object = $this->game->objects[$compulsion];
        $oldIncarnation = $object->incarnation;
        // Explicit zone-change boundary only; this is not a reanimation/enabling-card certificate.
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::HAND);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::EXILE);
        $object->exiledBy = 'madness';
        $this->assertSame($oldIncarnation + 2, $object->incarnation);
        $this->assertSame($trigger['id'], $this->game->stack[array_key_last($this->game->stack)]['id']);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion), 'The old trigger must not move a different exile incarnation.');
        $this->assertSame($oldIncarnation + 2, $this->game->objects[$compulsion]->incarnation);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(GameObject::STACK, $this->zone($voice));
    }

    /** @dataProvider owners */
    public function testMadnessOwnerGetsResolutionDecisionWithoutHavingPriority(int $owner): void
    {
        $caster = 1 - $owner;
        $this->newGame();
        if ($caster === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->lands($caster, 'Swamp', 3);
        $this->lands($owner, 'Swamp', 2);
        $target = $this->battlefield($caster, 'Grizzly Bears');
        $this->game->objects[$target]->tapped = true;
        $compulsion = $this->game->addCard($owner, $this->cached('Murderous Compulsion'), GameObject::HAND)->id;
        $raw = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-ancient-secrets.json'));
        $this->assertSame('c120400c2ecf17ab694a6eb15d6eebcf24b41c88abd946bcc28a36b4aacf5005', hash('sha256', $raw));
        $rot = array_values(array_filter(json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['cards'], fn ($card) => $card['name'] === 'Mind Rot'))[0];
        $spell = $this->game->addCard($caster, $rot, GameObject::HAND)->id;
        $this->game->cast($caster, $spell, 0, ['p:'.$owner]);
        $this->resolveOne();
        $this->assertSame(3, $this->game->players[$caster]->expended);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->assertSame($caster, $this->game->priority);
        $this->assertCount(1, $this->game->stack, 'The discarded card has a queued Madness ability.');
        $this->resolveOne();
        $this->assertNotNull($this->game->choiceAwaiting(), 'The owner needs a resolution decision even though the other seat has priority.');
        $this->assertSame($owner, $this->game->choiceAwaiting()['seat']);
        $this->assertSame($caster, $this->game->priority);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $choice = $this->game->choiceAwaiting();
        $pending = $this->saved();
        $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
        $this->assertSame(GameObject::STACK, $this->zone($compulsion));
        $this->assertSame(2, $this->game->players[$owner]->expended);
        $this->assertSame($caster, $this->game->priority);
        $done = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
        $this->assertSame($done, $this->saved());
    }

    /** @dataProvider owners */
    public function testResolutionChoiceAcceptsOrDeclinesAndReplaysExactlyOnce(int $owner): void
    {
        [$compulsion, $voice, $target] = $this->paidDiscard($owner);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_madness', $choice['type']);
        $pending = $this->saved();
        try {
            $this->game->chooseMadness($owner, $choice['id'], 'accept', ['p:'.(1 - $owner)]);
            $this->fail('Illegal target accepted.');
        } catch (GameException) {
            $this->assertSame($pending, $this->saved());
        }
        $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
        $this->assertSame(GameObject::STACK, $this->zone($compulsion));
        $this->assertSame(GameObject::STACK, $this->zone($voice));
        $this->assertSame(4, $this->game->players[$owner]->expended);
        $this->assertNull($this->game->choiceAwaiting());
        $done = $this->saved();
        try {
            $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
            $this->fail('Replayed choice accepted.');
        } catch (GameException) {
            $this->assertSame($done, $this->saved());
        }
        $this->game = Game::fromArray($pending);
        $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
        $this->assertSame($done, $this->saved());
        $this->game = Game::fromArray($pending);
        $this->game->chooseMadness($owner, $choice['id'], 'decline');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($compulsion));
        $this->assertSame(GameObject::STACK, $this->zone($voice));
        $this->assertSame(2, $this->game->players[$owner]->expended);
    }

    /** @dataProvider owners */
    public function testInvalidUnpayableAndOldSchemaChoicesRejectWithoutMutation(int $owner): void
    {
        [$compulsion, , $target] = $this->paidDiscard($owner);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        foreach ($this->game->battlefield as $id) {
            if ($this->game->objects[$id]->controller === $owner && $this->game->objects[$id]->definition()->isLand()) {
                $this->game->objects[$id]->tapped = true;
            }
        }
        $before = $this->saved();
        $old = $before;
        $old['schemaVersion'] = 21;
        foreach ([fn () => $this->game->chooseMadness(1 - $owner, $choice['id'], 'decline'),
            fn () => $this->game->chooseMadness($owner, $choice['id'] + 1, 'decline'),
            fn () => $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]),
            fn () => Game::fromArray($old)] as $action) {
            try {
                $action();
                $this->fail('Invalid choice or old schema accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->game->chooseMadness($owner, $choice['id'], 'decline');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($compulsion));
    }

    /** @dataProvider owners */
    public function testPaidMadnessDamageChoiceRestoresOuterResolutionWithoutRepayment(int $owner): void
    {
        [$compulsion, $voice, $target] = $this->paidDiscard($owner);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        foreach ($this->game->battlefield as $id) {
            if ($this->game->objects[$id]->controller === $owner && $this->game->objects[$id]->definition()->isLand()) {
                $this->game->objects[$id]->tapped = true;
            }
        }
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/recover-damage-ordering.json'), true, flags: JSON_THROW_ON_ERROR);
        $river = array_values(array_filter($fixture['cards'], fn ($card) => $card['name'] === 'Underground River'))[0];
        $this->lands($owner, 'Island', 1);
        $this->game->addCard($owner, $river, GameObject::BATTLEFIELD);
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => $owner, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $this->game->prevent['p:'.$owner] = 1;
        $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
        $damage = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $damage['type']);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->assertSame(4, $this->game->players[$owner]->expended);
        $pending = $this->saved();
        $old = $pending;
        $old['schemaVersion'] = 21;
        try {
            Game::fromArray($old);
            $this->fail('Old schema accepted a new paid continuation.');
        } catch (GameException) {
            $this->assertSame($pending, $this->saved());
        }
        foreach (['triggerId', 'incarnation'] as $field) {
            $bad = $pending;
            $bad['pendingDamage']['after']['madnessChoice'][$field]++;
            try {
                Game::fromArray($bad);
                $this->fail('Tampered paid Madness identity accepted: '.$field);
            } catch (GameException) {
                $this->assertSame($pending, $this->saved());
            }
        }
        $bad = $pending;
        $bad['pendingDamage']['after']['madnessChoice']['resume']['steps'][] = [['type' => 'draw', 'amount' => 99], null];
        try {
            Game::fromArray($bad);
            $this->fail('Tampered paid Madness outer effects accepted.');
        } catch (GameException) {
            $this->assertSame($pending, $this->saved());
        }
        $this->game->chooseDamageOrder($owner, $damage['id'], $damage['options'][0]);
        $this->assertSame(GameObject::STACK, $this->zone($compulsion));
        $this->assertSame(GameObject::STACK, $this->zone($voice));
        $this->assertSame(4, $this->game->players[$owner]->expended);
        $done = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder($owner, $damage['id'], $damage['options'][0]);
        $this->assertSame($done, $this->saved());
    }

    /** @dataProvider owners */
    public function testStaleMadnessDecisionDoesNotMoveReturnedExileIncarnation(int $owner): void
    {
        [$compulsion] = $this->paidDiscard($owner);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $object = $this->game->objects[$compulsion];
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::HAND);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::EXILE);
        $object->exiledBy = 'madness';
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        foreach (['accept', 'decline'] as $option) {
            try {
                $this->game->chooseMadness($owner, $choice['id'], $option);
                $this->fail('Stale choice accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
    }

    public function testUnknownMadnessSourceIncarnationFailsClosed(): void
    {
        [$compulsion] = $this->paidDiscard(0);
        $before = $this->saved();
        $result = (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke($this->game, ['type' => 'madness'], $this->game->objects[$compulsion], null, 0, 0, null, null);
        $this->assertFalse($result);
        $this->assertSame($before, $this->saved());
    }

    /** @dataProvider malformedSavedIncarnations */
    public function testUnknownSavedMadnessIncarnationIsRejectedBeforeCoercion(int $version, string $location, string $form): void
    {
        [$compulsion, , , $trigger] = $this->paidDiscard(0);
        $state = $this->saved();
        $state['schemaVersion'] = $version;
        if ($location === 'stack') {
            $index = array_search($trigger['id'], array_column($state['stack'], 'id'), true);
            if ($form === 'missing') {
                unset($state['stack'][$index]['incarnation']);
            } else {
                $state['stack'][$index]['incarnation'] = $form === 'null' ? null : '2';
            }
        } else {
            $record = ['source' => $compulsion, 'incarnation' => 2, 'controller' => 0,
                'effects' => [['type' => 'madness']], 'kinds' => [], 'label' => 'Madness', 'text' => ''];
            if ($form === 'missing') {
                unset($record['incarnation']);
            } else {
                $record['incarnation'] = $form === 'null' ? null : '2';
            }
            $state['pendingTriggers'][] = $record;
        }
        $this->expectException(GameException::class);
        Game::fromArray($state);
    }

    public static function malformedSavedIncarnations(): array
    {
        $cases = [];
        foreach ([21, 22] as $version) {
            foreach (['stack', 'pending'] as $location) {
                foreach (['missing', 'null', 'string'] as $form) {
                    $cases[] = [$version, $location, $form];
                }
            }
        }

        return $cases;
    }

    /** @dataProvider owners */
    public function testSchema21QueuedMadnessRetainsItsOriginalSourceIdentity(int $owner): void
    {
        [$compulsion, $voice] = $this->paidDiscard($owner);
        $queued = $this->saved();
        $queued['schemaVersion'] = 21;
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_madness', $choice['type']);
        $this->assertSame($owner, $choice['seat']);
        $this->game->chooseMadness($owner, $choice['id'], 'decline');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($compulsion));
        $this->assertSame(GameObject::STACK, $this->zone($voice));
    }

    /** @dataProvider owners */
    public function testNonPriorityOwnerRestoresPaidDamageContinuation(int $owner): void
    {
        $caster = 1 - $owner;
        $this->newGame();
        if ($caster === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->lands($caster, 'Swamp', 3);
        $target = $this->battlefield($caster, 'Grizzly Bears');
        $this->game->objects[$target]->tapped = true;
        $compulsion = $this->game->addCard($owner, $this->cached('Murderous Compulsion'), GameObject::HAND)->id;
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/quest-ancient-secrets.json'), true, flags: JSON_THROW_ON_ERROR);
        $rot = array_values(array_filter($fixture['cards'], fn ($card) => $card['name'] === 'Mind Rot'))[0];
        $spell = $this->game->addCard($caster, $rot, GameObject::HAND)->id;
        $this->game->cast($caster, $spell, 0, ['p:'.$owner]);
        $this->resolveOne();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($caster, $this->game->priority);
        $riverFixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/recover-damage-ordering.json'), true, flags: JSON_THROW_ON_ERROR);
        $river = array_values(array_filter($riverFixture['cards'], fn ($card) => $card['name'] === 'Underground River'))[0];
        $this->lands($owner, 'Island', 1);
        $this->game->addCard($owner, $river, GameObject::BATTLEFIELD);
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => $owner, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $this->game->prevent['p:'.$owner] = 1;
        $this->game->chooseMadness($owner, $choice['id'], 'accept', ['o:'.$target]);
        $damage = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $damage['type']);
        $this->assertSame(2, $this->game->players[$owner]->expended);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion));
        $this->assertSame($caster, $this->game->priority);
        $pending = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder($owner, $damage['id'], $damage['options'][0]);
        $this->assertSame(GameObject::STACK, $this->zone($compulsion));
        $this->assertSame(2, $this->game->players[$owner]->expended);
        $this->assertSame($caster, $this->game->priority);
        $done = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder($owner, $damage['id'], $damage['options'][0]);
        $this->assertSame($done, $this->saved());
    }

    public function testMalformedMadnessChoiceCannotRebindWaitingTriggerOrOuterEffects(): void
    {
        $this->paidDiscard(0);
        $this->resolveOne();
        $state = $this->saved();
        foreach (['triggerId', 'object', 'incarnation', 'seat'] as $field) {
            $bad = $state;
            $bad['pendingChoice'][$field]++;
            try {
                Game::fromArray($bad);
                $this->fail('Mismatched choice identity accepted: '.$field);
            } catch (GameException) {
                $this->assertSame($state, $this->saved());
            }
        }
        $bad = $state;
        $bad['pendingChoice']['resume']['steps'][] = [['type' => 'draw', 'amount' => 99], null];
        try {
            Game::fromArray($bad);
            $this->fail('Unlisted outer effect accepted.');
        } catch (GameException) {
            $this->assertSame($state, $this->saved());
        }
    }

    /** @dataProvider practiceChoices */
    public function testPracticeUsesTheResolutionChoiceAndDeclinesWhenUnpayable(bool $payable): void
    {
        [$compulsion] = $this->paidDiscard(0);
        $this->resolveOne();
        if (! $payable) {
            foreach ($this->game->battlefield as $id) {
                if ($this->game->objects[$id]->controller === 0 && $this->game->objects[$id]->definition()->isLand()) {
                    $this->game->objects[$id]->tapped = true;
                }
            }
        }
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame($payable ? GameObject::STACK : GameObject::GRAVEYARD, $this->zone($compulsion));
        $this->assertSame($payable ? 4 : 2, $this->game->players[0]->expended);
    }

    public static function practiceChoices(): array
    {
        return [[true], [false]];
    }

    public static function owners(): array
    {
        return [[0], [1]];
    }
}
