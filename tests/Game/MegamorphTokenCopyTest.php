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
final class MegamorphTokenCopyTest extends GameTestCase
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

    private function denProtector(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/den-protector-megamorph.json'));
        $this->assertSame('d35324e93fdf9a45b288f238e9db4ddf801857cd8870a6a6032ffaf8c90f81b7', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('e0c322a9-4486-5282-9d62-4a1bf6d49453', $card['uuid']);
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertSame(['kind' => 'megamorph', 'cost' => '{1}{G}'], $definition->morph);

        return $card;
    }

    private function morph(int $actor): int
    {
        $this->game->active = $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 3);
        $id = $this->game->addCard($actor, $this->denProtector(), GameObject::HAND)->id;
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
        $this->assertSame(2, $this->game->power($object), 'Copy the face-down characteristics, not the printed 2/1.');
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
        $this->assertSame([], $object->definition()->triggered);
        $this->assertSame([], $object->definition()->keywords);
        $this->assertNull($object->definition()->morph);
        $this->assertFalse($this->game->canActivate($caster, $copy, 0));
    }

    private function turnUp(int $caster, int $id): void
    {
        $this->game->priority = $caster;
        $lands = $this->lands($caster, 'Forest', 2);
        $this->game->activate($caster, $id, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $source = $this->game->objects[$id];
        $this->assertFalse($source->faceDown);
        $this->assertSame('Den Protector', $source->name());
        $this->assertSame(1, $source->counter('+1/+1'));
        $this->assertSame(3, $this->game->power($source));
        $this->assertSame(2, $this->game->toughness($source));
    }

    private function assertRejectedWithoutMutation(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('The invalid action must be rejected.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
    }

    /** @dataProvider players */
    public function testPaidMegamorphThenCopyUsesFaceDownCharacteristics(int $actor, int $caster): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        // Explicit supporting unit control change; the real morph/copy costs are paid.
        $this->game->objects[$id]->controller = $caster;
        $incarnation = $this->game->objects[$id]->incarnation;
        $oldIds = $this->castCopy($caster, $id);
        $beforeCopy = $this->saved();
        $this->game = Game::fromArray($beforeCopy);
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertPlainCopy($copy, $caster);
        $this->assertTrue($this->game->objects[$id]->faceDown);
        $this->assertSame(2, $this->game->power($this->game->objects[$id]));
        $afterCopy = $this->saved();
        $this->game = Game::fromArray($beforeCopy);
        $this->resolveOne();
        $this->assertSame($afterCopy, $this->saved());
        $mine = $this->game->addCard($caster, self::card('Giant Growth'), GameObject::GRAVEYARD)->id;
        $theirs = $this->game->addCard(1 - $caster, self::card('Shock'), GameObject::GRAVEYARD)->id;
        $this->turnUp($caster, $id);
        $this->assertSame($incarnation, $this->game->objects[$id]->incarnation);
        $this->assertSame($actor, $this->game->objects[$id]->owner);
        $this->assertSame('trigger', $this->game->decision($caster));
        $pending = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->assertRejectedWithoutMutation(fn () => $this->game->chooseTriggerTargets(1 - $caster, ['o:'.$mine]));
        $this->assertRejectedWithoutMutation(fn () => $this->game->chooseTriggerTargets($caster, ['o:'.$theirs]));
        $this->game->chooseTriggerTargets($caster, ['o:'.$mine]);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($mine));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($theirs));
        $this->assertSame(1, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertSame([], $this->game->objects[$copy]->counters);
        $this->assertPlainCopy($copy, $caster);
        $expected = $this->saved();
        foreach ([$pending, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->decision($caster) === 'trigger') {
                $this->game->chooseTriggerTargets($caster, ['o:'.$mine]);
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
        $this->game->priority = $caster;
        $this->lands($caster, 'Forest', 2);
        $this->assertRejectedWithoutMutation(fn () => $this->game->activate($caster, $copy, 0));
    }

    /** @dataProvider actors */
    public function testPaidTurnUpInResponseCopiesPrintedStatsWithoutMegamorphCounter(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $mine = $this->game->addCard($actor, self::card('Giant Growth'), GameObject::GRAVEYARD)->id;
        $this->castCopy($actor, $id);
        $this->turnUp($actor, $id);
        $this->assertCount(2, $this->game->stack, 'The turn-up trigger is above the copy spell.');
        $oldIds = array_keys($this->game->objects);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($mine));
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $object = $this->game->objects[$copy];
        $this->assertSame('Den Protector', $object->name());
        $this->assertSame(2, $this->game->power($object));
        $this->assertSame(1, $this->game->toughness($object));
        $this->assertSame([], $object->counters);
        $this->assertSame(2, $object->battlefieldManaValue());
        $this->assertSame(['G'], $object->definition()->colors);
        $this->assertSame(['Human', 'Warrior'], $object->definition()->subtypes);
        $this->assertSame(['kind' => 'megamorph', 'cost' => '{1}{G}'], $object->definition()->morph);
        $this->assertFalse($object->faceDown);
        $this->assertFalse($this->game->canActivate($actor, $copy, 0));
        $this->assertCount(0, $this->game->stack, 'The copy entered face up; it was not turned face up.');
        $this->assertSame(3, $this->game->power($this->game->objects[$id]));
    }

    /** @dataProvider actors */
    public function testCountersAndTemporaryModifiersAreNotCopied(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $source = $this->game->objects[$id];
        $source->addCounters('+1/+1', 2);
        $source->tapped = true;
        $source->damage = 1;
        $source->untilEndOfTurn[] = ['power' => 3, 'toughness' => 3, 'keywords' => ['haste']];
        $oldIds = $this->castCopy($actor, $id);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertPlainCopy($copy, $actor);
        $object = $this->game->objects[$copy];
        $this->assertSame([], $object->counters);
        $this->assertSame([], $object->untilEndOfTurn);
        $this->assertFalse($object->tapped);
        $this->assertSame(0, $object->damage);
        $this->assertFalse($this->game->hasKeyword($object, 'haste'));
        $this->assertSame(7, $this->game->power($this->game->objects[$id]));
    }

    /** @dataProvider actors */
    public function testCopyOfCopyRemainsBlankAfterSourceLeaves(int $actor): void
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
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($oldIds, array_keys($this->game->objects));
        $this->assertSame(GameObject::EXILE, $this->zone($id));
    }

    /** @dataProvider actors */
    public function testCopyAndTurnUpRejectUnpaidCostsAndOpposingTargets(int $actor): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        $this->game->priority = $actor;
        $this->assertRejectedWithoutMutation(fn () => $this->game->activate($actor, $id, 0));
        $card = ['name' => 'Permanent Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target permanent you control."];
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->assertRejectedWithoutMutation(fn () => $this->game->cast($actor, $spell, 0, ['o:'.$id]));
        $land = $this->lands($actor, 'Island', 1)[0];
        $this->game->objects[$id]->controller = 1 - $actor;
        $this->assertRejectedWithoutMutation(fn () => $this->game->cast($actor, $spell, 0, ['o:'.$id]));
        $this->assertFalse($this->game->objects[$land]->tapped);
    }

    /** @dataProvider actors */
    public function testPaidFaceUpCastingStillCopiesPrintedKeywordsAndTrigger(int $actor): void
    {
        $this->newGame();
        $this->game->active = $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 2);
        $id = $this->game->addCard($actor, $this->denProtector(), GameObject::HAND)->id;
        $this->game->cast($actor, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $oldIds = $this->castCopy($actor, $id);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $object = $this->game->objects[$copy];
        $this->assertSame('Den Protector', $object->name());
        $this->assertSame(2, $this->game->power($object));
        $this->assertSame(1, $this->game->toughness($object));
        $this->assertSame(2, $object->battlefieldManaValue());
        $this->assertSame($this->game->objects[$id]->definition()->keywords, $object->definition()->keywords);
        $this->assertCount(1, $object->definition()->triggered);
        $this->assertTrue($object->definition()->isToken());
        $this->assertSame([], $object->counters);
        $this->assertSame([], $this->game->stack, 'Entering face up is not turning face up.');
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
