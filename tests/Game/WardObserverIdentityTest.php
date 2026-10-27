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
use MTGPocket\Game\WardTargetingEvent;

/** Diagnostic observer/identity contracts only; no Ward timing certificate. @covers \MTGPocket\Game\Game */
final class WardObserverIdentityTest extends GameTestCase
{
    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function wardBearer(int $controller, string $text = 'Ward {2}'): int
    {
        $card = ['name' => 'Ward Bearer Unit Fixture', 'type' => 'Creature', 'manaCost' => '{1}{G}',
            'power' => '2', 'toughness' => '2', 'text' => $text];
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($controller, $card, GameObject::BATTLEFIELD)->id;
    }

    public function testPaidCastReturnsItsOriginalEntryBeforeCopiesAppend(): void
    {
        $this->newGame();
        $this->game->cast(0, $this->hand(0, 'Ornithopter'));
        $this->game->pass(0);
        $this->game->pass(1);
        $card = ['name' => 'Storm Bolt Unit Fixture', 'type' => 'Instant', 'manaCost' => '{R}',
            'text' => "Storm\nStorm Bolt Unit Fixture deals 2 damage to any target."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->lands(0, 'Mountain', 1);
        $id = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $entry = $this->game->cast(0, $id, 0, ['p:1']);
        $this->assertCount(2, $this->game->stack);
        $this->assertIsArray($entry, 'Return the original committed entry, not null or the appended copy.');
        $this->assertSame($this->game->stack[0], $entry);
        $this->assertNotSame($this->game->stack[1]['id'], $entry['id']);
    }

    public function testSourceRemovingActivationReturnsTheCommittedAbilityIdentity(): void
    {
        $this->newGame();
        $id = $this->battlefield(0, 'Bottle Gnomes');
        $incarnation = $this->game->objects[$id]->incarnation;
        $entry = $this->game->activate(0, $id, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertCount(1, $this->game->stack);
        $this->assertIsArray($entry);
        $this->assertSame($this->game->stack[0], $entry);
        $this->assertSame($incarnation, $entry['incarnation']);
        $this->assertNotSame($entry['incarnation'], $this->game->objects[$id]->incarnation);
    }

    /** @dataProvider seats */
    public function testDefaultOffAndEnabledObserverKeepIdenticalUpfrontPaymentGameplay(int $payer): void
    {
        $states = [];
        foreach ([false, true] as $enabled) {
            $this->newGame();
            if ($enabled) {
                $this->game->setWardObservation(true);
            }
            $ward = $this->wardBearer(1 - $payer);
            $this->lands($payer, 'Mountain', 3);
            if ($payer === 1) {
                $this->game->pass(0);
            }
            $receipt = $this->game->cast($payer, $this->hand($payer, 'Shock'), 0, ['o:'.$ward]);
            $states[] = $this->game->toArray();
            $records = $this->game->wardObservations();
            $this->assertCount($enabled ? 1 : 0, $records);
            if ($enabled) {
                $this->assertSame('observed', $records[0]['status']);
                $this->assertSame($receipt['id'], $records[0]['event']['offending']['id']);
                $this->assertSame('unselected', $records[0]['event']['ordering']);
                $this->assertSame(1 - $payer, $records[0]['event']['instances'][0]['controller']);
                $this->assertSame($payer, $records[0]['event']['instances'][0]['payer']);
                $this->assertSame($records[0]['event'], WardTargetingEvent::fromArray($records[0]['event'])->toArray());
            }
        }
        $this->assertSame($states[0], $states[1], 'Observer adds no gameplay/log/payment/pending state.');
    }

    public function testFailedPaidActionPublishesNothingAndConsumesNoObservationId(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1);
        $this->lands(0, 'Mountain', 1);
        $shock = $this->hand(0, 'Shock');
        $before = $this->game->toArray();
        try {
            $this->game->cast(0, $shock, 0, ['o:'.$ward]);
            $this->fail('The retained upfront Ward cost is not affordable.');
        } catch (GameException $e) {
            $this->assertStringContainsString('and ward', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
        $this->assertSame([], $this->game->wardObservations());
        $this->lands(0, 'Mountain', 2);
        $receipt = $this->game->cast(0, $shock, 0, ['o:'.$ward]);
        $record = $this->game->wardObservations()[0];
        $this->assertSame(1, $record['id']);
        $this->assertSame($receipt['id'], $record['event']['offending']['id']);
    }

    public function testStormCopiesAndOriginalBindToTheirOwnCommittedIds(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $this->game->cast(0, $this->hand(0, 'Ornithopter'));
        $this->resolveOne();
        $ward = $this->wardBearer(1);
        $this->lands(0, 'Mountain', 3);
        $card = ['name' => 'Storm Bolt Unit Fixture', 'type' => 'Instant', 'manaCost' => '{R}',
            'text' => "Storm\nStorm Bolt Unit Fixture deals 2 damage to any target."];
        $receipt = $this->game->cast(0, $this->game->addCard(0, $card, GameObject::HAND)->id, 0, ['o:'.$ward]);
        $this->assertSame($this->game->stack[0], $receipt);
        $records = $this->game->wardObservations();
        $this->assertCount(2, $records);
        $this->assertSame(array_column($this->game->stack, 'id'), array_map(fn (array $record) => $record['event']['offending']['id'], $records));
        $copy = (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $receipt, 0, 'identity probe');
        $this->assertSame($this->game->stack[2], $copy);
        $this->assertSame($copy['id'], $this->game->wardObservations()[2]['event']['offending']['id']);
        $this->assertNotSame($copy['id'], $receipt['id']);
    }

    /** @dataProvider triggerPayment */
    public function testPaidTargetedTriggerReturnsItsEntryOrNullWithoutInventingAnEvent(bool $affordable): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1);
        $this->lands(0, 'Mountain', $affordable ? 6 : 4);
        $source = $this->hand(0, 'Flametongue Kavu');
        $this->game->cast(0, $source);
        $this->resolveOne();
        $this->assertSame('trigger', $this->game->decision(0));
        $receipt = $this->game->chooseTriggerTargets(0, ['o:'.$ward]);
        if (! $affordable) {
            $this->assertNull($receipt);
            $this->assertSame([], $this->game->wardObservations());

            return;
        }
        $this->assertSame($this->game->stack[0], $receipt);
        $this->assertSame($source, $receipt['object']);
        $this->assertSame($receipt['id'], $this->game->wardObservations()[0]['event']['offending']['id']);
    }

    public function testSacrificeCostKeepsTheOffendingAbilityAndWardSnapshotIndependent(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1);
        $card = ['name' => 'Sacrifice Pinger Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1',
            'text' => 'Sacrifice this creature: This creature deals 1 damage to target creature.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->lands(0, 'Forest', 1);
        $before = $this->game->toArray();
        try {
            $this->game->activate(0, $source, 0, ['o:'.$ward]);
            $this->fail('The retained Ward payment must reject before sacrifice.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
            $this->assertSame([], $this->game->wardObservations());
        }
        $this->lands(0, 'Forest', 1);
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$ward]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame($incarnation, $receipt['incarnation']);
        $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        $event = $this->game->wardObservations()[0]['event'];
        $this->assertSame($receipt['id'], $event['offending']['id']);
        $this->assertSame($ward, $event['instances'][0]['object']);
    }

    public function testGrantSourceSacrificedAsCostIsCapturedBeforeItLeaves(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, 'Vigilance');
        $card = ['name' => 'Ward Sacrifice Aura Unit Fixture', 'type' => 'Enchantment — Aura',
            'text' => "Enchant creature\nEnchanted creature has ward {1}.\nSacrifice this enchantment: This enchantment deals 1 damage to target creature."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $this->game->objects[$source]->attachedTo = $ward;
        $old = $this->game->objects[$source]->incarnation;
        $this->lands(0, 'Forest', 1);
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$ward]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNotContains('ward {1}', $this->game->keywords($this->game->objects[$ward]));
        $event = $this->game->wardObservations()[0]['event'];
        $this->assertSame($receipt['id'], $event['offending']['id']);
        $this->assertSame("attachment:{$source}:{$old}:0", $event['instances'][0]['key']);
        $this->assertSame(['kind' => 'mana', 'symbols' => '{1}'], $event['instances'][0]['cost']);
    }

    public function testCostRemovedTargetKeepsPreCostPinsForGameplayAndObservation(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, 'Ward {1}');
        $fodder = $this->battlefield(0, 'Ornithopter');
        $old = $this->game->objects[$fodder]->incarnation;
        $card = ['name' => 'Two Target Sacrifice Unit Fixture', 'type' => 'Enchantment',
            'text' => 'Sacrifice a creature: Target creature gets +1/+1 until end of turn. Target creature gets -1/-1 until end of turn.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Forest', 1);
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$fodder, 'o:'.$ward]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        $this->assertNotSame($old, $this->game->objects[$fodder]->incarnation);
        $record = $this->game->wardObservations()[0];
        $this->assertSame('observed', $record['status']);
        $this->assertSame("o:{$fodder}:{$old}", $record['targets'][0]);
        $this->assertSame("o:{$fodder}:{$old}", $receipt['targets'][0]);
        $this->assertSame($receipt['targets'], $record['event']['targets']);
        $this->assertSame('unselected', $record['event']['ordering']);
    }

    public function testIdenticalIntrinsicAndGrantedInstancesStayDistinctWhileLegacyTaxIsUnchanged(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, "Ward {2}\nWard {2}");
        $this->assertCount(2, $this->game->objects[$ward]->definition()->wardInstances);
        $banner = ['name' => 'Ward Banner Unit Fixture', 'type' => 'Enchantment', 'text' => 'Creatures you control have ward {2}.'];
        $a = $this->game->addCard(1, $banner, GameObject::BATTLEFIELD)->id;
        $b = $this->game->addCard(1, $banner, GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Mountain', 3);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $event = $this->game->wardObservations()[0]['event'];
        $this->assertCount(4, $event['instances']);
        $this->assertCount(4, array_unique(array_column($event['instances'], 'key')));
        $this->assertSame('unselected', $event['ordering']);
        $this->assertStringContainsString('anthem:'.$a.':', $event['instances'][2]['key']);
        $this->assertStringContainsString('anthem:'.$b.':', $event['instances'][3]['key']);
    }

    public function testUnsupportedCostIsHeldOnlyAfterCommitAndLoadResetsDiagnostics(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, 'Ward—Discard a card.');
        $this->lands(0, 'Mountain', 1);
        $this->hand(0, 'Forest');
        $receipt = $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $record = $this->game->wardObservations()[0];
        $this->assertSame('held', $record['status']);
        $this->assertSame($receipt['id'], $record['offending']['id']);
        $this->assertArrayNotHasKey('event', $record);
        $this->assertStringContainsString('Ward cost held', $record['reasons'][0]);
        $this->game = Game::fromArray($this->game->toArray());
        $this->assertSame([], $this->game->wardObservations());
        $this->lands(0, 'Mountain', 1);
        $this->hand(0, 'Forest');
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $this->assertSame([], $this->game->wardObservations(), 'Reload also restores the default-off configuration.');
    }

    public function testGenuineTargetingTaxStillRejectsUnderpaymentAndProducesNoWardEvent(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $taxed = $this->wardBearer(1, 'Spells your opponents cast that target Ward Bearer Unit Fixture cost {2} more to cast.');
        $this->assertTrue($this->game->objects[$taxed]->definition()->ward['tax']);
        $this->assertSame([], $this->game->objects[$taxed]->definition()->wardInstances);
        $this->lands(0, 'Mountain', 1);
        $shock = $this->hand(0, 'Shock');
        $before = $this->game->toArray();
        try {
            $this->game->cast(0, $shock, 0, ['o:'.$taxed]);
            $this->fail('The genuine additional casting cost is mandatory.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
        }
        $this->lands(0, 'Mountain', 2);
        $receipt = $this->game->cast(0, $shock, 0, ['o:'.$taxed]);
        $this->assertSame($this->game->stack[0], $receipt);
        $this->assertSame([], $this->game->wardObservations());
    }

    public function testLegacyFrostTitanStylePayerClauseIsHeldWithoutChangingItsTax(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, 'Whenever this creature becomes the target of a spell or ability an opponent controls, counter that spell or ability unless its controller pays {2}.');
        $this->lands(0, 'Mountain', 3);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $record = $this->game->wardObservations()[0];
        $this->assertSame('held', $record['status']);
        $this->assertArrayNotHasKey('event', $record);
        $this->assertStringContainsString('payer semantics', $record['reasons'][0]);
    }

    public function testFixedLifeAndTemporaryDuplicateGrantsAreObservedWithoutChangingTheirCosts(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, 'Ward—Pay 3 life.');
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $this->assertSame(17, $this->life(0), 'The old upfront life payment remains.');
        $this->assertSame(['kind' => 'life', 'amount' => 3], $this->game->wardObservations()[0]['event']['instances'][0]['cost']);

        $this->newGame();
        $this->game->setWardObservation(true);
        $ward = $this->wardBearer(1, 'Vigilance');
        foreach ([0, 1] as $slot) {
            $this->game->objects[$ward]->untilEndOfTurn[$slot] = ['power' => 0, 'toughness' => 0, 'keywords' => ['ward {1}']];
        }
        $this->lands(0, 'Mountain', 2);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $instances = $this->game->wardObservations()[0]['event']['instances'];
        $this->assertSame(['temporary-slot:0:0', 'temporary-slot:1:0'], array_column($instances, 'key'));
    }

    public function testFaceUpSpecialActionReturnsNoStackReceiptOrWardEvent(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $bytes = file_get_contents(__DIR__.'/../Fixtures/dog-walker-disguise.json');
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $source = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $this->lands(0, 'Forest', 3);
        $this->game->cast(0, $source, 0, [], 'morph');
        $this->resolveOne();
        $this->lands(0, 'Mountain', 2);
        $this->assertNull($this->game->activate(0, $source, 0));
        $this->assertFalse($this->game->objects[$source]->faceDown);
        $this->assertSame([], $this->game->wardObservations());
    }

    public static function triggerPayment(): array
    {
        return [[false], [true]];
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
