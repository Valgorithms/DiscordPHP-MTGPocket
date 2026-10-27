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

/** @covers \MTGPocket\Game\Game */
final class DisguiseTokenCopyTest extends GameTestCase
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

    private function walker(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/dog-walker-disguise.json'));
        $this->assertSame('27a09d399cdd6cbe3e819319bd47c28c0b08295bc635020daa55f2f4fcca973c', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('7037c6a6-2329-5e62-a365-ddd83f37d0aa', $card['uuid']);
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported, 'Complete cached record recognition is separate from gameplay.');
        $this->assertSame(['kind' => 'disguise', 'cost' => '{R/W}{R/W}'], $definition->morph);

        return $card;
    }

    private function disguised(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $mana = $this->lands($actor, 'Forest', 3);
        $id = $this->game->addCard($actor, $this->walker(), GameObject::HAND)->id;
        $this->game->cast($actor, $id, 0, [], 'morph');
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $object = $this->game->objects[$id];
        $this->assertTrue($object->faceDown);
        $this->assertSame([2, 2], [$this->game->power($object), $this->game->toughness($object)]);
        $this->assertSame(['mana' => '{2}'], $object->definition()->ward);

        return $id;
    }

    private function castCopy(int $actor, int $id): array
    {
        // Reuse the paid copier unit fixture; no printed copy-spell certificate.
        $card = ['name' => 'Permanent Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target permanent you control."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $mana = $this->lands($actor, 'Island', 1)[0];
        if ($this->game->priority !== $actor) {
            $this->game->pass($this->game->priority);
        }
        $this->game->cast($actor, $this->game->addCard($actor, $card, GameObject::HAND)->id, 0, ['o:'.$id]);
        $this->assertTrue($this->game->objects[$mana]->tapped);

        return array_keys($this->game->objects);
    }

    private function copied(array $before): int
    {
        $ids = array_values(array_diff(array_keys($this->game->objects), $before));
        $this->assertCount(1, $ids);

        return $ids[0];
    }

    private function assertDisguiseCopy(int $id, int $actor): void
    {
        $copy = $this->game->objects[$id];
        $this->assertSame([2, 2], [$this->game->power($copy), $this->game->toughness($copy)], 'Copy the disguised 2/2, not printed Dog Walker 3/1.');
        $this->assertSame(GameObject::BATTLEFIELD, $copy->zone);
        $this->assertTrue($copy->definition()->isToken());
        $this->assertFalse($copy->faceDown, 'The token copy enters face up.');
        $this->assertSame([$actor, $actor], [$copy->owner, $copy->controller]);
        $this->assertSame('', $copy->definition()->name);
        $this->assertSame([], $copy->definition()->colors);
        $this->assertSame([], $copy->definition()->subtypes);
        $this->assertNull($copy->definition()->card['manaCost']);
        $this->assertSame(0, $copy->battlefieldManaValue());
        $this->assertSame(['mana' => '{2}'], $copy->definition()->ward);
        $this->assertNotContains('vigilance', $copy->definition()->keywords);
        $this->assertSame([], $copy->definition()->activated);
        $this->assertSame([], $copy->definition()->triggered);
        $this->assertNull($copy->definition()->morph);
        $this->assertFalse($this->game->canActivate($actor, $id, 0));
    }

    /** @dataProvider actors */
    public function testPaidDisguiseThenCopyPreservesOnlyCopiableFaceDownValues(int $actor): void
    {
        $source = $this->disguised($actor);
        $before = $this->castCopy($actor, $source);
        $this->resolveOne();
        $this->assertDisguiseCopy($this->copied($before), $actor);
        $this->assertTrue($this->game->objects[$source]->faceDown);
        $this->assertSame([], $this->game->stack, 'Copying/entering face up does not turn Dog Walker face up or create Dogs.');
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

    private function turnUp(int $actor, int $source): void
    {
        $mana = $this->lands($actor, 'Mountain', 2);
        if ($this->game->priority !== $actor) {
            $this->game->pass($this->game->priority);
        }
        $this->game->activate($actor, $source, 0);
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped, 'Pay the printed hybrid disguise cost using two red mana.');
        }
        $object = $this->game->objects[$source];
        $this->assertFalse($object->faceDown);
        $this->assertSame('Dog Walker', $object->name());
        $this->assertSame([3, 1], [$this->game->power($object), $this->game->toughness($object)]);
        $this->assertTrue($this->game->hasKeyword($object, 'vigilance'));
        $this->assertNull($object->definition()->ward);
    }

    private function dogs(): array
    {
        return array_values(array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->definition()->isToken() && in_array('Dog', $o->definition()->subtypes, true)));
    }

    /** @dataProvider actors */
    public function testSourceTurnUpAndQueuedResolvedJsonAreIndependentOfCopy(int $actor): void
    {
        $source = $this->disguised($actor);
        $oldIds = $this->castCopy($actor, $source);
        $queued = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $copy = $this->copied($oldIds);
        $this->assertDisguiseCopy($copy, $actor);
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
        $this->game = Game::fromArray($resolved);
        $this->assertDisguiseCopy($copy, $actor);
        $this->assertSame([], $this->dogs());
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->turnUp($actor, $source);
        $this->assertSame($incarnation, $this->game->objects[$source]->incarnation);
        $this->assertCount(1, $this->game->stack, 'Only the original Dog Walker turn-up trigger is queued.');
        $this->assertDisguiseCopy($copy, $actor);
        $this->resolveOne();
        $dogs = $this->dogs();
        $this->assertCount(2, $dogs);
        foreach ($dogs as $dog) {
            $this->assertSame($actor, $dog->controller);
            $this->assertTrue($dog->tapped);
            $this->assertSame(['W'], $dog->definition()->colors);
            $this->assertSame([1, 1], [$this->game->power($dog), $this->game->toughness($dog)]);
        }
        $this->assertDisguiseCopy($copy, $actor);
        $this->lands($actor, 'Mountain', 2);
        $this->reject(fn () => $this->game->activate($actor, $copy, 0));
        $this->game = Game::fromArray($this->saved());
        $this->assertDisguiseCopy($copy, $actor);
    }

    /** @dataProvider actors */
    public function testCopyOfCopyKeepsWardAfterOriginalLeaves(int $actor): void
    {
        $source = $this->disguised($actor);
        $old = $this->castCopy($actor, $source);
        $this->resolveOne();
        $first = $this->copied($old);
        // Engine movement primitive isolates departure; no removal-card certificate.
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->game = Game::fromArray($this->saved());
        $old = $this->castCopy($actor, $first);
        $this->resolveOne();
        $second = $this->copied($old);
        $this->assertDisguiseCopy($first, $actor);
        $this->assertDisguiseCopy($second, $actor);
        $this->assertSame(GameObject::EXILE, $this->zone($source));
        $this->assertSame([], $this->dogs());
        $this->game = Game::fromArray($this->saved());
        $this->assertDisguiseCopy($second, $actor);
    }

    /** @dataProvider actors */
    public function testTurnUpInResponseCopiesPrintedResolutionCharacteristics(int $actor): void
    {
        $source = $this->disguised($actor);
        $this->castCopy($actor, $source);
        $this->turnUp($actor, $source);
        $this->assertCount(2, $this->game->stack, 'The source trigger is above the pending copier.');
        $this->resolveOne();
        $this->assertCount(2, $this->dogs());
        $before = array_keys($this->game->objects);
        $queued = $this->saved();
        $this->resolveOne();
        $id = $this->copied($before);
        $copy = $this->game->objects[$id];
        $this->assertSame('Dog Walker', $copy->name());
        $this->assertSame([3, 1], [$this->game->power($copy), $this->game->toughness($copy)]);
        $this->assertTrue($this->game->hasKeyword($copy, 'vigilance'));
        $this->assertNull($copy->definition()->ward);
        $this->assertFalse($copy->faceDown);
        $this->assertFalse($this->game->canActivate($actor, $id, 0));
        $this->assertCount(2, $this->dogs(), 'A face-up copy enters; it was not turned face up and creates no additional Dogs.');
        $after = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($after, $this->saved());
    }

    /** @dataProvider actors */
    public function testCopiedWardFeedsExistingTargetTaxWithoutClaimingWardTiming(int $actor): void
    {
        $source = $this->disguised($actor);
        $old = $this->castCopy($actor, $source);
        $this->resolveOne();
        $id = $this->copied($old);
        $opponent = 1 - $actor;
        $this->game->pass($actor);
        // Existing engine tax contract only; Ward's actual triggered timing stays outside this slice.
        $shock = $this->hand($opponent, 'Shock');
        $mana = $this->lands($opponent, 'Mountain', 1);
        $this->reject(fn () => $this->game->cast($opponent, $shock, 0, ['o:'.$id]));
        $mana = [...$mana, ...$this->lands($opponent, 'Mountain', 2)];
        $this->game->cast($opponent, $shock, 0, ['o:'.$id]);
        foreach ($mana as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($id));
        $this->assertTrue($this->game->objects[$source]->faceDown);
        $this->assertSame([], $this->dogs());
    }

    /** @dataProvider actors */
    public function testCopyAndSourceTurnUpRejectInvalidCostsAndTargetsAtomically(int $actor): void
    {
        $source = $this->disguised($actor);
        $card = ['name' => 'Permanent Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target permanent you control."];
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $spell, 0, ['o:'.$source]));
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->lands($actor, 'Island', 1);
        $other = $this->battlefield(1 - $actor, 'Grizzly Bears');
        $this->reject(fn () => $this->game->cast($actor, $spell, 0, ['o:'.$other]));
        $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0));
    }

    /** @dataProvider actors */
    public function testNoncopyModifiersAndCountersAreNotCopied(int $actor): void
    {
        $source = $this->disguised($actor);
        $object = $this->game->objects[$source];
        $object->addCounters('+1/+1', 2);
        $object->tapped = true;
        $object->damage = 1;
        $object->untilEndOfTurn[] = ['power' => 1, 'toughness' => 1, 'keywords' => ['flying']];
        $old = $this->castCopy($actor, $source);
        $this->resolveOne();
        $id = $this->copied($old);
        $this->assertDisguiseCopy($id, $actor);
        $copy = $this->game->objects[$id];
        $this->assertFalse($copy->tapped);
        $this->assertSame(0, $copy->damage);
        $this->assertSame([], $copy->counters);
        $this->assertFalse($this->game->hasKeyword($copy, 'flying'));
    }

    /** @dataProvider actors */
    public function testDepartedTargetProducesNoCopy(int $actor): void
    {
        $source = $this->disguised($actor);
        $old = $this->castCopy($actor, $source);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->resolveOne();
        $this->assertSame($old, array_keys($this->game->objects));
        $this->assertSame([], $this->dogs());
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

}
