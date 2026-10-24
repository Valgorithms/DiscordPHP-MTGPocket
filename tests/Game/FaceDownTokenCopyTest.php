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

/** @covers \MTGPocket\Game\Game */
final class FaceDownTokenCopyTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function loxodon(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/woolly-loxodon-morph.json'));
        $this->assertSame('03f2ef9e9d6cd60ce492c3c82929ee8ecfac874e1d88643b9180be86f99a20c1', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('58ad74dd-6e19-5f96-9694-f2cee61a442e', $card['uuid']);
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertSame(['kind' => 'morph', 'cost' => '{5}{G}'], $definition->morph);

        return $card;
    }

    private function morph(int $actor): int
    {
        $this->game->active = $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 3);
        $id = $this->game->addCard($actor, $this->loxodon(), GameObject::HAND)->id;
        $this->game->cast($actor, $id, 0, [], 'morph');
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $object = $this->game->objects[$id];
        $this->assertTrue($object->faceDown);
        $this->assertSame(2, $this->game->power($object));
        $this->assertSame(2, $this->game->toughness($object));
        $this->assertSame(0, $object->battlefieldManaValue());

        return $id;
    }

    private function castCopy(int $caster, int $id): array
    {
        // Existing synthetic effect, not a certificate for any printed copy spell.
        $card = ['name' => 'Permanent Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target permanent you control."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->priority = $caster;
        $land = $this->lands($caster, 'Island', 1)[0];
        $this->game->cast($caster, $this->game->addCard($caster, $card, GameObject::HAND)->id, 0, ['o:'.$id]);
        $this->assertTrue($this->game->objects[$land]->tapped);

        return array_keys($this->game->objects);
    }

    private function newCopy(array $oldIds): int
    {
        $ids = array_values(array_diff(array_keys($this->game->objects), $oldIds));
        $this->assertCount(1, $ids);

        return $ids[0];
    }

    private function assertPlainCopy(int $copy, int $caster): void
    {
        $object = $this->game->objects[$copy];
        $this->assertSame(2, $this->game->power($object), 'Copy the face-down characteristics, not the printed 6/7.');
        $this->assertSame(2, $this->game->toughness($object));
        $this->assertSame(GameObject::BATTLEFIELD, $object->zone);
        $this->assertSame($caster, $object->owner);
        $this->assertSame($caster, $object->controller);
        $this->assertTrue($object->definition()->isToken());
        $this->assertFalse($object->faceDown, 'The token enters face up with the copied characteristics.');
        $this->assertFalse($object->transformed);
        $this->assertSame('', $object->name());
        $this->assertSame(['Creature'], $object->definition()->types);
        $this->assertSame([], $object->definition()->subtypes);
        $this->assertSame([], $object->definition()->supertypes);
        $this->assertSame([], $object->definition()->colors);
        $this->assertSame(0, $object->battlefieldManaValue());
        $this->assertSame([], $object->definition()->activated, 'The source turn-up cost is engine bookkeeping, not a copied ability.');
        $this->assertNull($object->definition()->morph);
        $this->assertFalse($this->game->canActivate($caster, $copy, 0));
    }

    private function turnUp(int $caster, int $id): void
    {
        $this->game->priority = $caster;
        $lands = $this->lands($caster, 'Forest', 6);
        $stack = $this->game->stack;
        $this->game->activate($caster, $id, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->assertSame($stack, $this->game->stack, 'Turning up is a special action, not a stack item.');
        $this->assertFalse($this->game->objects[$id]->faceDown);
        $this->assertSame('Woolly Loxodon', $this->game->objects[$id]->name());
        $this->assertSame(6, $this->game->power($this->game->objects[$id]));
        $this->assertSame(7, $this->game->toughness($this->game->objects[$id]));
    }

    /** @dataProvider players */
    public function testPaidMorphThenCopyUsesFaceDownCharacteristics(int $actor, int $caster): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        // Explicit supporting unit control change; the real morph/copy costs are paid.
        $this->game->objects[$id]->controller = $caster;
        $incarnation = $this->game->objects[$id]->incarnation;
        $oldIds = $this->castCopy($caster, $id);
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertPlainCopy($copy, $caster);
        $this->assertTrue($this->game->objects[$id]->faceDown);
        $this->assertSame(2, $this->game->power($this->game->objects[$id]));
        $after = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->assertSame($after, $this->saved(), 'A queued copy replays identically after JSON save/load.');
        $this->game = Game::fromArray($after);
        $this->assertPlainCopy($copy, $caster);
        $this->turnUp($caster, $id);
        $this->assertSame($incarnation, $this->game->objects[$id]->incarnation);
        $this->assertSame($actor, $this->game->objects[$id]->owner);
        $this->assertPlainCopy($copy, $caster);
        $this->game = Game::fromArray($this->saved());
        $this->assertPlainCopy($copy, $caster);
        $this->lands($caster, 'Forest', 6);
        $this->game->priority = $caster;
        $snapshot = $this->saved();
        try {
            $this->game->activate($caster, $copy, 0);
            $this->fail('Even with mana available, the copy has no turn-up action.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($snapshot, $this->saved());
    }

    /** @dataProvider actors */
    public function testTurnUpInResponseCopiesResolutionCharacteristics(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $this->castCopy($actor, $id);
        $this->turnUp($actor, $id);
        $oldIds = array_keys($this->game->objects);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $object = $this->game->objects[$copy];
        $this->assertSame('Woolly Loxodon', $object->name());
        $this->assertSame(6, $this->game->power($object));
        $this->assertSame(7, $this->game->toughness($object));
        $this->assertSame(7, $object->battlefieldManaValue());
        $this->assertSame(['G'], $object->definition()->colors);
        $this->assertSame(['Elephant', 'Warrior'], $object->definition()->subtypes);
        $this->assertSame(['kind' => 'morph', 'cost' => '{5}{G}'], $object->definition()->morph);
        $this->assertFalse($object->faceDown);
        $this->assertFalse($this->game->canActivate($actor, $copy, 0));
    }

    /** @dataProvider actors */
    public function testPaidFaceUpCardStillCopiesPrintedCharacteristics(int $actor): void
    {
        $this->newGame();
        $this->game->active = $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 7);
        $id = $this->game->addCard($actor, $this->loxodon(), GameObject::HAND)->id;
        $this->game->cast($actor, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $oldIds = $this->castCopy($actor, $id);
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertSame($this->game->objects[$id]->printed()->card, array_diff_key($this->game->objects[$copy]->card, ['token' => true]));
        $this->assertSame(6, $this->game->power($this->game->objects[$copy]));
        $this->assertSame(7, $this->game->objects[$copy]->battlefieldManaValue());
        $this->assertTrue($this->game->objects[$copy]->definition()->isToken());
    }

    /** @dataProvider actors */
    public function testCountersDamageTappedAndTemporaryBonusesAreNotCopied(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $source = $this->game->objects[$id];
        // Supporting characteristic controls, not additional paid spells.
        $source->counters['+1/+1'] = 2;
        $source->damage = 1;
        $source->tapped = true;
        $source->untilEndOfTurn[] = ['power' => 3, 'toughness' => 3, 'keywords' => ['haste']];
        $oldIds = $this->castCopy($actor, $id);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertPlainCopy($copy, $actor);
        $object = $this->game->objects[$copy];
        $this->assertSame([], $object->counters);
        $this->assertSame(0, $object->damage);
        $this->assertFalse($object->tapped);
        $this->assertSame([], $object->untilEndOfTurn);
        $this->assertFalse($this->game->hasKeyword($object, 'haste'));
        $this->assertSame(7, $this->game->power($this->game->objects[$id]));
        $this->assertSame(1, $this->game->objects[$id]->damage);
    }

    /** @dataProvider actors */
    public function testCopyOfCopyRetainsBlankCharacteristicsAfterSourceLeaves(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $oldIds = $this->castCopy($actor, $id);
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $oldIds = $this->castCopy($actor, $copy);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $second = $this->newCopy($oldIds);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        $this->game = Game::fromArray($this->saved());
        $this->assertPlainCopy($copy, $actor);
        $this->assertPlainCopy($second, $actor);
        $this->assertSame(GameObject::EXILE, $this->zone($id));
    }

    /** @dataProvider actors */
    public function testRemovedTargetCreatesNoToken(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $oldIds = $this->castCopy($actor, $id);
        // Unit removal route exercises resolution-time target revalidation.
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($oldIds, array_keys($this->game->objects));
        $this->assertSame(GameObject::EXILE, $this->zone($id));
    }

    /** @dataProvider actors */
    public function testCopyRejectsUnpaidCostAndOpposingTargetAtomically(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $card = ['name' => 'Permanent Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target permanent you control."];
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->priority = $actor;
        $before = $this->saved();
        try {
            $this->game->cast($actor, $spell, 0, ['o:'.$id]);
            $this->fail('The copy spell requires its blue mana.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
        $land = $this->lands($actor, 'Island', 1)[0];
        $this->game->objects[$id]->controller = 1 - $actor;
        $before = $this->saved();
        try {
            $this->game->cast($actor, $spell, 0, ['o:'.$id]);
            $this->fail('A permanent controlled by the opponent is not a legal target.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
        $this->assertFalse($this->game->objects[$land]->tapped);
    }

    /** @dataProvider actors */
    public function testSourceTurnUpRejectsUnpaidCostWithoutMutation(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $this->game->priority = $actor;
        $before = $this->saved();
        $this->assertFalse($this->game->canActivate($actor, $id, 0));
        try {
            $this->game->activate($actor, $id, 0);
            $this->fail('The original morph still requires {5}{G} to turn up.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
    }

    /** @dataProvider actors */
    public function testEntryObserversSeeCreatureWithoutItsHiddenPrintedEntryAbility(int $actor): void
    {
        $this->newGame();
        // Seeded plain-morph characteristic unit; only the copy spell is paid.
        $card = ['name' => 'Hidden Entry Unit Fixture', 'type' => 'Creature — Elf', 'colors' => ['G'], 'manaCost' => '{5}{G}', 'power' => '6', 'toughness' => '6',
            'text' => "Morph {G}\nWhen Hidden Entry Unit Fixture enters, you gain 7 life."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $this->game->addCard($actor, $card, GameObject::BATTLEFIELD)->id;
        $this->game->objects[$id]->faceDown = true;
        $watcher = ['name' => 'Creature Entry Unit Fixture', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control enters, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($watcher))->unsupported);
        $this->game->addCard($actor, $watcher, GameObject::BATTLEFIELD);
        $life = $this->game->players[$actor]->life;
        $oldIds = $this->castCopy($actor, $id);
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertPlainCopy($copy, $actor);
        $this->assertCount(1, $this->game->stack, 'The external observer triggers; the hidden printed ETB does not.');
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame($life + 1, $this->game->players[$actor]->life);
        $after = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($after, $this->saved());
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function players(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }
}
