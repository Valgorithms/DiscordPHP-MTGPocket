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

    private function card(): array
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
        $id = $this->game->addCard($actor, $this->card(), GameObject::HAND)->id;
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

    /** @dataProvider players */
    public function testPaidMorphThenCopyUsesFaceDownCharacteristics(int $actor, int $caster): void
    {
        $this->newGame();
        $id = $this->morph($actor);
        // Explicit supporting unit control change; the real morph/copy costs are paid.
        $this->game->objects[$id]->controller = $caster;
        $oldIds = $this->castCopy($caster, $id);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertPlainCopy($copy, $caster);
        $this->assertTrue($this->game->objects[$id]->faceDown);
        $this->assertSame(2, $this->game->power($this->game->objects[$id]));
    }

    public static function players(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }
}
