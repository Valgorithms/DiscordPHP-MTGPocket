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

/** Explicit-mode engine fixtures, not new enabling-card or whole-card claims. @covers \MTGPocket\Game\Game */
final class WardExecutionIdentityTest extends GameTestCase
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

    private function fixture(int $seat, string $text, string $type = 'Creature', string $zone = GameObject::BATTLEFIELD, string $mana = ''): int
    {
        $card = ['name' => 'Ward Identity Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '5', 'text' => $text, 'manaCost' => $mana];
        $definition = new CardDefinition($card);
        $expectedUnread = str_contains($text, "Ward {1}\nWhenever") ? ['Whenever CARDNAME becomes the target of a spell or ability an opponent controls, counter that spell or ability unless its controller pays {2}.'] : [];
        $this->assertSame($expectedUnread, $definition->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

    private function setupSpell(string $text = 'Ward {2}', bool $uncounterable = false): array
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, $text);
        $this->lands(0, 'Mountain', 1);
        $spell = $uncounterable ? $this->fixture(0, "This spell can't be countered.\nCARDNAME deals 2 damage to any target.", 'Instant', GameObject::HAND, '{R}') : $this->hand(0, 'Shock');
        $entry = $this->game->cast(0, $spell, 0, ['o:'.$ward]);

        return [$ward, $spell, $entry];
    }

    private function decline(): void
    {
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('ward_payment', $choice['type']);
        $this->game->chooseWardPayment($choice['seat'], $choice['id'], 'decline');
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid action accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    public function testCounteringWardPrimitiveDoesNotMoveItsPermanentOrOriginalSpell(): void
    {
        [$ward, $spell, $entry] = $this->setupSpell();
        $wardEntry = $this->game->stack[1];
        // Existing stack-counter primitive; Counterspell cannot legally target abilities.
        // This control certifies countering Ward itself, not a Stifle parser or paid card path.
        (new \ReflectionMethod(Game::class, 'counterStackItem'))->invoke($this->game, $wardEntry['id']);
        $this->assertSame([$entry], $this->game->stack);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($ward));
        $this->assertSame(GameObject::STACK, $this->zone($spell));
        $this->assertNull($this->game->choiceAwaiting());
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$ward]->damage);
    }

    /** @dataProvider departure */
    public function testWardSurvivesSourceDepartureAndOriginalControllerAndPayerStayFrozen(bool $return): void
    {
        [$ward, $spell] = $this->setupSpell();
        $old = $this->game->objects[$ward]->incarnation;
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        $move->invoke($this->game, $this->game->objects[$ward], GameObject::EXILE);
        if ($return) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$ward], 1);
            $this->game->objects[$ward]->controller = 0;
        }
        $this->game->stack[0]['controller'] = 1; // Entry control change must not replace rule 702.21a's event player.
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(0, $choice['seat']);
        $this->assertSame(1, $choice['instance']['controller']);
        $this->assertSame($old, $choice['instance']['incarnation']);
        $this->decline();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame($return ? GameObject::BATTLEFIELD : GameObject::EXILE, $this->zone($ward));
    }

    public static function departure(): array
    {
        return [[false], [true]];
    }

    public function testPaidResponseReturnsWardSourceButDoesNotRemoveQueuedAbility(): void
    {
        [$ward, $spell, $entry] = $this->setupSpell();
        $this->lands(1, 'Island', 1);
        $response = $this->hand(1, 'Unsummon');
        $this->game->pass(0);
        $this->game->cast(1, $response, 0, ['o:'.$ward]);
        $this->assertCount(3, $this->game->stack, 'Targeting your own permanent creates no second Ward trigger.');
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($ward));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($response));
        $this->assertCount(2, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($entry['id'], $this->game->choiceAwaiting()['offending']['id']);
        $this->decline();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(1, $this->game->players[1]->expended);
    }

    public function testCopyKeepsStaleTargetAndDoesNotTriggerReturnedIncarnationsWard(): void
    {
        [$ward, $spell, $entry] = $this->setupSpell();
        $object = $this->game->objects[$ward];
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::EXILE);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $object, 1);
        $copy = (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $entry, 0, 'stale Ward copy probe');
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(3, $this->game->stack);
        $this->assertSame($entry['targets'], $copy['targets']);
        $this->assertSame(2, $this->saved()['wardExecution']['nextEventId'], 'No event is published for a stale reference to the old target.');
        $this->resolveOne();
        $this->assertSame(0, $object->damage);
        $this->resolveOne();
        $this->decline();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($ward));
    }

    public function testUnaffordableFixedLifeAcceptanceIsAtomic(): void
    {
        $this->setupSpell('Ward—Pay 21 life.');
        $this->resolveOne();
        $this->assertFalse($this->game->canPayWard(0));
        $this->reject(fn () => $this->game->chooseWardPayment(0, $this->game->choiceAwaiting()['id'], 'accept'));
        $this->assertSame(20, $this->life(0));
        $this->decline();
        $this->assertSame([], $this->game->stack);
    }

    public function testCounteringDepartedActivationOnlyRemovesItsReferencedEntry(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, 'Ward {1}');
        $source = $this->fixture(0, 'Sacrifice this creature: This creature deals 1 damage to target creature.');
        $old = $this->game->objects[$source]->incarnation;
        $entry = $this->game->activate(0, $source, 0, ['o:'.$ward]);
        $this->assertSame($old, $entry['incarnation']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        // A new incarnation must not be buried by countering the old ability.
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 0);
        $new = $this->game->objects[$source]->toArray();
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->decline();
        $this->assertSame($new, $this->game->objects[$source]->toArray());
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider departure */
    public function testUncounterableOriginalAndCopyStillOfferOptionalPayment(bool $copy): void
    {
        [$ward, $spell, $entry] = $this->setupSpell(uncounterable: true);
        if ($copy) {
            $copied = (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $entry, 0, 'Ward identity probe');
            (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
            $this->assertTrue($copied['uncounterable']);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(($copy ? $copied : $entry)['id'], $this->game->choiceAwaiting()['offending']['id']);
        $this->decline();
        $this->assertSame(($copy ? $copied : $entry)['id'], end($this->game->stack)['id']);
        $this->assertSame(GameObject::STACK, $this->zone($spell));
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$ward]->damage);
    }

    public function testCopyAndOriginalHaveIndependentWardEntriesAndCopyCounterDoesNotMoveCard(): void
    {
        [$ward, $spell, $entry] = $this->setupSpell();
        $copy = (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $entry, 0, 'Ward identity probe');
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->resolveOne();
        $this->assertSame($copy['id'], $this->game->choiceAwaiting()['offending']['id']);
        $this->decline();
        $this->assertSame(GameObject::STACK, $this->zone($spell));
        $this->assertSame([$entry['id']], array_column(array_filter($this->game->stack, fn ($i) => ($i['effects'][0]['type'] ?? '') !== 'ward_fixed'), 'id'));
        $this->resolveOne();
        $this->assertSame($entry['id'], $this->game->choiceAwaiting()['offending']['id']);
        $this->decline();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame([], $this->game->stack);
    }

    public function testMissingOffendingEntryDoesNotCancelOptionalLifePaymentOrTouchAnotherEntry(): void
    {
        [$ward, $spell, $entry] = $this->setupSpell('Ward—Pay 2 life.');
        (new \ReflectionMethod(Game::class, 'counterStackItem'))->invoke($this->game, $entry['id']);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertTrue($this->game->canPayWard(0));
        $this->game->chooseWardPayment(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(18, $this->life(0));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($ward));
    }

    public function testRepeatedTargetSlotsDeduplicateEventsButIdenticalWardInstancesStaySeparate(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, "Ward {1}\nWard {1}");
        $source = $this->fixture(0, '{G}: Target creature gets +1/+1 until end of turn. Target creature gets +1/+1 until end of turn.', 'Enchantment');
        $this->lands(0, 'Forest', 1);
        $entry = $this->game->activate(0, $source, 0, ['o:'.$ward, 'o:'.$ward]);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('trigger_order', $choice['type']);
        $this->assertCount(2, $choice['options']);
        $this->game->chooseNextTrigger(1, $choice['id'], $choice['options'][1]);
        $wardEntries = array_slice($this->game->stack, 1);
        $this->assertCount(2, $wardEntries);
        $this->assertSame(['printed:1', 'printed:0'], array_map(fn ($i) => $i['effects'][0]['instance']['key'], $wardEntries));
        $this->assertSame([$entry['id'], $entry['id']], array_map(fn ($i) => $i['effects'][0]['offending']['id'], $wardEntries));
        $this->resolveOne();
        $this->decline();
        $this->resolveOne();
        $this->decline();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider unsupported */
    public function testUnsupportedCostAndLegacyPayerClauseRefuseBeforeAnyCosts(string $text): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, $text);
        $this->lands(0, 'Mountain', 5);
        $this->hand(0, 'Forest');
        $spell = $this->hand(0, 'Shock');
        $this->reject(fn () => $this->game->cast(0, $spell, 0, ['o:'.$ward]));
        $this->assertSame(1, $this->saved()['wardExecution']['nextEventId']);
    }

    public static function unsupported(): array
    {
        return [['Ward—Discard a card.'], ['Ward {X}'],
            ['Whenever this creature becomes the target of a spell or ability an opponent controls, counter that spell or ability unless its controller pays {2}.'],
            ["Ward {1}\nWhenever this creature becomes the target of a spell or ability an opponent controls, counter that spell or ability unless its controller pays {2}."]];
    }

    /** @dataProvider staticTaxes */
    public function testGenuineTaxIsMandatoryAtCastAndTrueWardIsSeparate(bool $trueWard): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, 'Spells your opponents cast that target Ward Identity Unit Fixture cost {2} more to cast.'.($trueWard ? "\nWard {1}" : ''));
        $spell = $this->hand(0, 'Shock');
        $this->lands(0, 'Mountain', 1);
        $this->reject(fn () => $this->game->cast(0, $spell, 0, ['o:'.$ward]));
        $this->lands(0, 'Mountain', 2);
        $entry = $this->game->cast(0, $spell, 0, ['o:'.$ward]);
        $this->assertSame(3, $this->game->players[0]->expended);
        $this->assertCount($trueWard ? 2 : 1, $this->game->stack);
        if ($trueWard) {
            $this->resolveOne();
            $this->decline();
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        } else {
            $this->assertSame([$entry], $this->game->stack);
        }
    }

    public static function staticTaxes(): array
    {
        return [[false], [true]];
    }

    public function testFailedPaymentPublishesNoWardEventAndOwnWardDoesNotTrigger(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, 'Ward {2}');
        $spell = $this->hand(0, 'Shock');
        $this->reject(fn () => $this->game->cast(0, $spell, 0, ['o:'.$ward]));
        $this->assertSame([], $this->game->pendingTriggers);
        $this->assertSame([], $this->game->stack);
        $this->assertSame(1, $this->saved()['wardExecution']['nextEventId']);
        $own = $this->fixture(0, 'Ward {2}');
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $spell, 0, ['o:'.$own]);
        $this->assertCount(1, $this->game->stack);
        $this->assertSame(1, $this->saved()['wardExecution']['nextEventId']);
    }
}
