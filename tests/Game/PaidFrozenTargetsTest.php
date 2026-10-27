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

/** Paid continuation identity unit fixtures, not Ward execution or card certificates. @covers \MTGPocket\Game\Game */
final class PaidFrozenTargetsTest extends GameTestCase
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

    private function ordering(int $seat = 0): void
    {
        $state = $this->saved();
        $state['damageEffects'] = [['id' => 1, 'controller' => $seat, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $this->game->prevent['p:'.$seat] = 1;
    }

    private function painfulSacrificeMana(int $seat, string $color = 'G'): int
    {
        return $this->fixture($seat, ['name' => 'Painful Cantor Unit Fixture', 'type' => 'Creature', 'power' => '3', 'toughness' => '3',
            'text' => "Sacrifice CARDNAME: Add {{$color}}. CARDNAME deals 1 damage to you."]);
    }

    private function answerAndVerifyReplay(int $seat, array $pending): void
    {
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $choice['type']);
        $this->game->chooseDamageOrder($seat, $choice['id'], 'prevent');
        $expected = $this->saved();
        try {
            $this->game->chooseDamageOrder($seat, $choice['id'], 'prevent');
            $this->fail('A completed mana payment continuation cannot be replayed.');
        } catch (GameException) {
            $this->assertSame($expected, $this->saved());
        }
        $this->game = Game::fromArray($pending);
        $this->game->chooseDamageOrder($seat, $choice['id'], 'prevent');
        $this->assertSame($expected, $this->saved());
    }

    public function testPostCostTargetNotificationCannotBindToPersistReturnedIncarnation(): void
    {
        $this->newGame();
        $victim = $this->fixture(0, ['name' => 'Persistent Focus Unit Fixture', 'type' => 'Creature', 'power' => '3', 'toughness' => '3',
            'text' => "Persist\nWhenever this creature becomes the target of a spell or ability you control, draw a card."]);
        $old = $this->game->objects[$victim]->incarnation;
        $source = $this->fixture(0, ['name' => 'Sacrifice Pump Unit Fixture', 'type' => 'Enchantment',
            'text' => 'Sacrifice a creature: Target creature gets +1/+1 until end of turn.']);
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$victim]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($victim));
        $this->assertNotSame($old, $this->game->objects[$victim]->incarnation);
        $this->assertSame(["o:{$victim}:{$old}"], $receipt['targets']);
        $returned = $this->game->objects[$victim]->incarnation;
        $rebound = array_values(array_filter($this->game->stack, fn (array $item) => $item['object'] === $victim && $item['incarnation'] === $returned));
        $this->assertSame([], $rebound, 'The returned incarnation was never targeted; its new identity must not supply a target-trigger.');
        // This negative identity contract does not certify capture of the original pre-cost target-trigger.
        for ($i = 0; $i < 3 && $this->game->stack !== []; $i++) {
            $this->resolveOne();
        }
        $this->assertSame(2, $this->game->power($this->game->objects[$victim]));
    }
    /** @dataProvider seats */
    public function testPaidCastLoadsStaleFrozenTargetAndCompletesExactlyOnce(int $seat): void
    {
        $this->newGame();
        $this->ordering($seat);
        $mana = $this->painfulSacrificeMana($seat);
        $pin = 'o:'.$mana.':'.$this->game->objects[$mana]->incarnation;
        $growth = $this->hand($seat, 'Giant Growth');
        if ($this->game->priority !== $seat) {
            $this->game->pass($this->game->priority);
        }
        $receipt = $this->game->cast($seat, $growth, 0, ['o:'.$mana]);
        $this->assertNull($receipt, 'No committed entry exists while its paid tail is suspended.');
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($mana));
        $pending = $this->saved();
        $this->assertSame([$pin], $pending['pendingDamage']['after']['targets']);
        $next = $pending['nextStackId'];
        $this->game = Game::fromArray($pending);
        $this->answerAndVerifyReplay($seat, $pending);
        $this->assertSame($next, $this->game->stack[0]['id']);
        $this->assertSame($growth, $this->game->stack[0]['object']);
        $this->assertSame([$pin], $this->game->stack[0]['targets']);
        $this->assertSame(1, $this->game->players[$seat]->expended);
        $this->assertSame(20, $this->life($seat));
        $this->resolveOne();
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($growth));
    }

    public function testPaidActivationKeepsRepeatedPinsAndSacrificesItsSourceOnlyAfterResume(): void
    {
        $this->newGame();
        $this->ordering();
        $mana = $this->painfulSacrificeMana(0);
        $pin = 'o:'.$mana.':'.$this->game->objects[$mana]->incarnation;
        $source = $this->fixture(0, ['name' => 'Paid Sacrifice Pump Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1',
            'text' => '{G}, Sacrifice this creature: Target creature gets +1/+1 until end of turn. Target creature gets +2/+2 until end of turn.']);
        $sourceIncarnation = $this->game->objects[$source]->incarnation;
        $this->assertNull($this->game->activate(0, $source, 0, ['o:'.$mana, $pin]));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $pending = $this->saved();
        $this->assertSame([$pin, $pin], $pending['pendingDamage']['after']['targets']);
        $this->game = Game::fromArray($pending);
        $this->answerAndVerifyReplay(0, $pending);
        $entry = $this->game->stack[0];
        $this->assertSame($pending['nextStackId'], $entry['id']);
        $this->assertSame($sourceIncarnation, $entry['incarnation']);
        $this->assertSame([$pin, $pin], $entry['targets']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(1, $this->game->players[0]->expended);
        $this->resolveOne();
        $this->assertSame([], $this->game->stack);
    }

    public function testPaidTargetedTriggerKeepsStalePinsAcrossSuspensionAndLoad(): void
    {
        $this->newGame();
        $this->ordering();
        $mana = $this->painfulSacrificeMana(0);
        $pin = 'o:'.$mana.':'.$this->game->objects[$mana]->incarnation;
        $ward = $this->fixture(1, ['name' => 'Ward Target Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '2', 'text' => 'Ward {1}']);
        $source = $this->fixture(0, ['name' => 'Paid Two Target Entry Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1', 'manaCost' => '{0}',
            'text' => 'When this creature enters, target creature gets +1/+1 until end of turn. Target creature gets +2/+2 until end of turn.'], GameObject::HAND);
        $this->game->cast(0, $source);
        $this->resolveOne();
        $this->assertNull($this->game->chooseTriggerTargets(0, ['o:'.$mana, 'o:'.$ward]));
        $this->assertSame([], $this->game->stack);
        $pending = $this->saved();
        $this->assertSame($pin, $pending['pendingDamage']['after']['targets'][0]);
        $this->game = Game::fromArray($pending);
        $this->answerAndVerifyReplay(0, $pending);
        $this->assertSame($pending['nextStackId'], $this->game->stack[0]['id']);
        $this->assertSame($pending['pendingDamage']['after']['targets'], $this->game->stack[0]['targets']);
        $this->assertSame(1, $this->game->players[0]->expended);
        $this->resolveOne();
        $this->assertSame(4, $this->game->power($this->game->objects[$ward]));
        $this->assertSame([], $this->game->stack);
    }

    public function testPaidBloodrushKeepsSacrificedAttackerPinAcrossLoad(): void
    {
        $this->newGame();
        $attacker = $this->painfulSacrificeMana(0, 'R');
        $rush = $this->fixture(0, ['name' => 'Bloodrush Lite', 'manaCost' => '{2}{R}', 'type' => 'Creature', 'power' => '3', 'toughness' => '3',
            'text' => 'Bloodrush — {R}, Discard Bloodrush Lite: Target attacking creature gets +3/+3 until end of turn.'], GameObject::HAND);
        $this->passUntil(Step::DeclareAttackers, 3);
        $this->game->declareAttackers(0, [$attacker]);
        $pin = 'o:'.$attacker.':'.$this->game->objects[$attacker]->incarnation;
        $this->ordering();
        $this->game->bloodrush(0, $rush);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($attacker));
        $this->assertSame(GameObject::HAND, $this->zone($rush));
        $pending = $this->saved();
        $this->assertSame($pin, $pending['pendingDamage']['after']['attacker']);
        $this->game = Game::fromArray($pending);
        $this->answerAndVerifyReplay(0, $pending);
        $this->assertSame([$pin], $this->game->stack[0]['targets']);
        $this->assertSame($pending['nextStackId'], $this->game->stack[0]['id']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($rush));
        $this->assertSame(1, $this->game->players[0]->expended);
        $this->resolveOne();
        $this->assertSame([], $this->game->stack);
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }

    public function testSuspendedStormKeepsOriginalAndCopyIdsAndCounteredCopyNeverMovesSource(): void
    {
        $this->newGame();
        $this->game->cast(0, $this->hand(0, 'Ornithopter'));
        $this->resolveOne();
        $bear = $this->battlefield(0, 'Grizzly Bears');
        $pin = 'o:'.$bear.':'.$this->game->objects[$bear]->incarnation;
        $this->ordering();
        $mana = $this->fixture(0, ['name' => 'Painful Forest Unit Fixture', 'type' => 'Land',
            'text' => '{T}: Add {G}. Painful Forest Unit Fixture deals 1 damage to you.']);
        $growth = $this->fixture(0, ['name' => 'Storm Growth Unit Fixture', 'type' => 'Instant', 'manaCost' => '{G}',
            'text' => "Storm\nTarget creature gets +3/+3 until end of turn."], GameObject::HAND);
        $this->assertNull($this->game->cast(0, $growth, 0, ['o:'.$bear]));
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $pending = $this->saved();
        $this->answerAndVerifyReplay(0, $pending);
        $this->assertCount(2, $this->game->stack);
        [$original, $copy] = $this->game->stack;
        $this->assertSame($pending['nextStackId'], $original['id']);
        $this->assertSame($original['id'] + 1, $copy['id']);
        $this->assertSame($growth, $original['object']);
        $this->assertSame($growth, $copy['object']);
        $this->assertSame([$pin], $original['targets']);
        $this->assertSame($original['targets'], $copy['targets']);
        $this->assertSame(1, $this->game->players[0]->expended);
        (new \ReflectionMethod(Game::class, 'counterStackItem'))->invoke($this->game, $copy['id']);
        $this->assertSame([$original], $this->game->stack);
        $this->assertSame(GameObject::STACK, $this->zone($growth));
        $this->resolveOne();
        $this->assertSame(5, $this->game->power($this->game->objects[$bear]));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($growth));
    }

    public function testDiagnosticSnapshotSurvivesLiveSuspensionButIsNeverInventedAfterLoad(): void
    {
        $this->newGame();
        $this->ordering();
        $this->game->setWardObservation(true);
        $ward = $this->fixture(1, ['name' => 'Ward Target Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '2', 'text' => 'Ward {1}']);
        $this->lands(0, 'Forest', 1);
        $this->fixture(0, ['name' => 'Painful Mountain Unit Fixture', 'type' => 'Land',
            'text' => '{T}: Add {R}. Painful Mountain Unit Fixture deals 1 damage to you.']);
        $source = $this->fixture(0, ['name' => 'Paid Sacrifice Pinger Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1',
            'text' => '{R}, Sacrifice this creature: This creature deals 1 damage to target creature.']);
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->assertNull($this->game->activate(0, $source, 0, ['o:'.$ward]));
        $this->assertSame([], $this->game->wardObservations());
        $pending = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $expected = $this->saved();
        $record = $this->game->wardObservations()[0];
        $this->assertSame('observed', $record['status']);
        $this->assertSame($this->game->stack[0]['id'], $record['event']['offending']['id']);
        $this->assertSame($incarnation, $record['event']['offending']['incarnation']);
        $this->assertSame('unselected', $record['event']['ordering']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->game = Game::fromArray($pending);
        $this->game->setWardObservation(true);
        $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        $this->assertSame($expected, $this->saved(), 'Observation cannot affect authoritative gameplay.');
        $this->assertSame([], $this->game->wardObservations(), 'Loading cannot reconstruct a missing pre-cost diagnostic snapshot.');
    }

    /** @dataProvider malformedTargets */
    public function testMalformedPaidTargetsRejectWithoutMutatingTheLiveGame(string $case): void
    {
        $this->newGame();
        $this->ordering();
        $mana = $this->painfulSacrificeMana(0);
        $this->game->cast(0, $this->hand(0, 'Giant Growth'), 0, ['o:'.$mana]);
        $pending = $this->saved();
        $changed = $pending;
        $changed['pendingDamage']['after']['targets'] = match ($case) {
            'bare', 'bare-legacy18' => ['o:'.$mana],
            'future' => ['o:'.$mana.':'.($this->game->objects[$mana]->incarnation + 1)],
            'unknown' => ['o:2147483647:0'],
            'negative' => ['o:'.$mana.':-1'],
            'leading-zero' => ['o:0'.$mana.':1'],
            'overflow' => ['o:'.$mana.':9999999999'],
            'player' => ['p:2'],
            'wrong-player-kind' => ['p:0'],
            'wrong-stack-kind' => ['s:1'],
            'omitted' => ['-'],
            'missing-slot' => [],
            'extra-slot' => [$pending['pendingDamage']['after']['targets'][0], 'p:1'],
            'not-list' => ['selected' => $pending['pendingDamage']['after']['targets'][0]],
            'not-string' => [[$mana]],
            'too-long' => [str_repeat('o', 65)],
            'not-array' => null,
        };
        if ($case === 'bare-legacy18') {
            $changed['schemaVersion'] = 18;
        }
        try {
            Game::fromArray($changed);
            $this->fail('Malformed paid selection was accepted: '.$case);
        } catch (GameException) {
            $this->assertSame($pending, $this->saved());
        }
    }

    public static function malformedTargets(): array
    {
        return array_map(fn (string $case) => [$case], ['bare', 'bare-legacy18', 'future', 'unknown', 'negative', 'leading-zero', 'overflow', 'player',
            'wrong-player-kind', 'wrong-stack-kind', 'omitted', 'missing-slot', 'extra-slot', 'not-list', 'not-string', 'too-long', 'not-array']);
    }

    public function testSchema18EmptyPendingStateMigratesWithoutInventingAnAction(): void
    {
        $this->newGame();
        $state = $this->saved();
        $state['schemaVersion'] = 18;
        $this->game = Game::fromArray($state);
        $state['schemaVersion'] = Game::STATE_VERSION;
        $this->assertSame($state, $this->saved());
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider optionalTargetModes */
    public function testSavedOptionalTargetsKeepSlotsButRejectDuplicatesWithinOneClause(bool $omit): void
    {
        $this->newGame();
        $this->ordering();
        $this->fixture(0, ['name' => 'Painful Forest Unit Fixture', 'type' => 'Land',
            'text' => '{T}: Add {G}. Painful Forest Unit Fixture deals 1 damage to you.']);
        $a = $this->game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $b = $this->game->addCard(0, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $source = $this->fixture(0, ['name' => 'Two Optional Returns Unit Fixture', 'type' => 'Enchantment',
            'text' => '{G}: Return up to two target creature cards from your graveyard to your hand.']);
        $targets = $omit ? ['-', '-'] : ['o:'.$a, 'o:'.$b];
        $this->assertNull($this->game->activate(0, $source, 0, $targets));
        $pending = $this->saved();
        if (! $omit) {
            $bad = $pending;
            $bad['pendingDamage']['after']['targets'][1] = $bad['pendingDamage']['after']['targets'][0];
            try {
                Game::fromArray($bad);
                $this->fail('One target clause cannot contain duplicate object identities.');
            } catch (GameException) {
                $this->assertSame($pending, $this->saved());
            }
        }
        $this->game = Game::fromArray($pending);
        $this->answerAndVerifyReplay(0, $pending);
        $this->assertSame($pending['pendingDamage']['after']['targets'], $this->game->stack[0]['targets']);
        $this->resolveOne();
        $this->assertSame($omit ? GameObject::GRAVEYARD : GameObject::HAND, $this->zone($a));
        $this->assertSame($omit ? GameObject::GRAVEYARD : GameObject::HAND, $this->zone($b));
    }

    public static function optionalTargetModes(): array
    {
        return [[false], [true]];
    }
}
