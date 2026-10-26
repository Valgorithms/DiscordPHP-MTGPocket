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
use MTGPocket\Game\GameException;

/** @covers \MTGPocket\Game\Game */
final class TransformingTokenCopyTest extends GameTestCase
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

    private function incubate(int $actor): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/converter-beast-incubate.json'));
        $this->assertSame('2ac7e35b8c1862547ddaa153242c3f598efd5446c2e592450be6eb417315d739', hash('sha256', $bytes));
        $entry = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('7d369fb5-4b98-59da-8373-60a0cd03486f', $entry['card']['uuid']);
        $this->assertSame([], (new CardDefinition($entry['card']))->unsupported);
        $this->game->active = $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 4);
        $this->game->cast($actor, $this->game->addCard($actor, $entry['card'], GameObject::HAND)->id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $tokens = array_values(array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->name() === 'Incubator Token'));
        $this->assertCount(1, $tokens);
        $this->assertSame(5, $tokens[0]->counter('+1/+1'));

        return $tokens[0]->id;
    }

    private function transform(int $actor, int $id): void
    {
        $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 2);
        $this->game->activate($actor, $id, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame('Phyrexian Token', $this->game->objects[$id]->name());
        $this->assertSame(5, $this->game->power($this->game->objects[$id]));
    }

    private function source(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        $card = array_values(array_filter($entries, fn (array $entry) => $entry['card']['uuid'] === '6e88e450-3e9c-575e-96b2-6b0ac6a9434d'))[0]['card'];
        $this->game->active = $this->game->priority = $seat;
        $this->lands($seat, 'Mountain', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        $this->resolveOne();

        return $id;
    }

    private function castCopy(int $caster, int $id): array
    {
        // Existing effect family, synthetic spell: this does not certify a printed copy card.
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

    /** @dataProvider players */
    public function testPaidCopyOfTransformedIncubatorEntersAsBackAndDiesWithoutCopiedCounters(int $actor, int $caster): void
    {
        $this->newGame();
        $source = $this->source(1 - $caster);
        $id = $this->incubate($actor);
        $this->transform($actor, $id);
        // Supporting control-change unit route, not a paid control spell.
        $this->game->objects[$id]->controller = $caster;
        foreach (['Whenever a creature you control enters, you gain 1 life.', 'Whenever another creature dies, you gain 1 life.'] as $text) {
            $watcher = ['name' => 'Copy Entry Death Unit Fixture', 'type' => 'Enchantment', 'text' => $text];
            $this->assertSame([], (new CardDefinition($watcher))->unsupported);
            $this->game->addCard($caster, $watcher, GameObject::BATTLEFIELD);
        }
        $life = $this->game->players[$caster]->life;
        $incarnation = $this->game->objects[$id]->incarnation;
        $this->game = Game::fromArray($this->saved());
        $oldIds = $this->castCopy($caster, $id);
        $before = $this->saved();
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertSame(GameObject::GONE, $this->zone($copy), 'The zero-counter back enters as a creature and dies.');
        $this->assertSame($caster, $this->game->objects[$copy]->owner);
        $this->assertSame([], $this->game->objects[$copy]->counters);
        foreach ($this->game->players as $player) {
            $this->assertNotContains($copy, $player->graveyard);
        }
        $this->assertCount(3, $this->game->stack, 'Creature entry, creature death and zero-value artifact burial each trigger.');
        $this->assertSame([0, 1, 1], $this->sortedAmounts());
        $queued = $this->saved();
        for ($i = 0; $i < 3; $i++) {
            $this->resolveOne();
        }
        $this->assertSame($life + 2, $this->game->players[$caster]->life);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame($incarnation, $this->game->objects[$id]->incarnation);
        $this->assertSame(5, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertSame(5, $this->game->power($this->game->objects[$id]));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if (! isset($this->game->objects[$copy])) {
                $this->resolveOne();
            }
            for ($i = 0; $i < 3; $i++) {
                $this->resolveOne();
            }
            $this->assertSame($expected, $this->saved());
        }
    }

    private function sortedAmounts(): array
    {
        $amounts = array_map(fn (array $item) => $item['effects'][0]['amount'], $this->game->stack);
        sort($amounts);

        return $amounts;
    }

    /** @dataProvider players */
    public function testPaidCopyOfFrontIncubatorRetainsBothFacesButDoesNotCopyCounters(int $actor, int $caster): void
    {
        $this->newGame();
        $id = $this->incubate($actor);
        $this->game->objects[$id]->controller = $caster;
        $oldIds = $this->castCopy($caster, $id);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $object = $this->game->objects[$copy];
        $this->assertSame(GameObject::BATTLEFIELD, $object->zone);
        $this->assertSame($caster, $object->owner);
        $this->assertSame('Incubator Token', $object->name());
        $this->assertFalse($object->transformed);
        $this->assertFalse($this->game->isCreature($object));
        $this->assertSame(0, $object->counter('+1/+1'));
        $this->assertTrue($object->printed()->back->isToken());
        $this->game->priority = $caster;
        $lands = $this->lands($caster, 'Forest', 2);
        $this->game->activate($caster, $copy, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($copy));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(5, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertFalse($this->game->isCreature($this->game->objects[$id]));
    }

    /** @dataProvider transformingUnits */
    public function testPaidCopyUsesActiveFaceForEntryAndRetainsTransformingCardCharacteristics(int $actor, bool $token, bool $back): void
    {
        $this->newGame();
        // Seeded characteristic unit control; only copying and later transformation are paid.
        $card = ['name' => 'Copy Front Unit Fixture', 'type' => 'Artifact Creature', 'manaCost' => '{4}', 'power' => '2', 'toughness' => '3', 'token' => $token, 'layout' => 'transform',
            'text' => "When Copy Front Unit Fixture enters, you gain 3 life.\n{1}: Transform Copy Front Unit Fixture.",
            'back' => ['name' => 'Copy Back Unit Fixture', 'type' => 'Artifact Creature', 'manaCost' => null, 'power' => '3', 'toughness' => '4',
                'text' => "When Copy Back Unit Fixture enters, you gain 7 life.\n{1}: Transform Copy Back Unit Fixture."]];
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertSame([], $definition->back->unsupported);
        $id = $this->game->addCard($actor, $card, GameObject::BATTLEFIELD)->id;
        $original = $this->game->objects[$id];
        $original->transformed = $back;
        $original->addCounters('+1/+1', 2);
        $original->damage = 1;
        $original->tapped = true;
        $original->untilEndOfTurn[] = ['power' => 3, 'toughness' => 0, 'keywords' => ['haste']];
        $oldIds = $this->castCopy($actor, $id);
        $life = $this->game->players[$actor]->life;
        $before = $this->saved();
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $object = $this->game->objects[$copy];
        $this->assertSame($back, $object->transformed);
        $this->assertSame($back ? 'Copy Back Unit Fixture' : 'Copy Front Unit Fixture', $object->name());
        $this->assertTrue($object->printed()->isToken());
        $this->assertTrue($object->printed()->back->isToken());
        $this->assertSame($back ? 3 : 2, $this->game->power($object));
        $this->assertSame($back ? 4 : 3, $this->game->toughness($object));
        $this->assertSame([], $object->counters);
        $this->assertSame(0, $object->damage);
        $this->assertFalse($object->tapped);
        $this->assertFalse($this->game->hasKeyword($object, 'haste'));
        $this->assertSame(4, $object->battlefieldManaValue());
        $this->assertSame($back ? 0 : 4, $object->definition()->cost->manaValue());
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame($life + ($back ? 7 : 3), $this->game->players[$actor]->life);
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
        $incarnation = $this->game->objects[$copy]->incarnation;
        foreach ([! $back, $back] as $face) {
            $this->game->priority = $actor;
            $land = $this->lands($actor, 'Forest', 1)[0];
            $this->game->activate($actor, $copy, 0);
            $this->assertTrue($this->game->objects[$land]->tapped);
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
            $this->assertSame($face, $this->game->objects[$copy]->transformed);
            $this->assertSame($incarnation, $this->game->objects[$copy]->incarnation);
            $this->assertSame(4, $this->game->objects[$copy]->battlefieldManaValue());
            $this->assertSame([], $this->game->stack, 'Transforming does not cause another entry trigger.');
        }
        $this->assertSame(2, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertSame($back, $this->game->objects[$id]->transformed);
    }

    public static function transformingUnits(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $token) {
                foreach ([false, true] as $back) {
                    $cases[] = [$actor, $token, $back];
                }
            }
        }

        return $cases;
    }

    /** @dataProvider actors */
    public function testPaidCopyUsesFaceAtResolutionAfterPaidTransformInResponse(int $actor): void
    {
        $this->newGame();
        $id = $this->incubate($actor);
        $this->castCopy($actor, $id);
        $this->game->priority = $actor;
        $lands = $this->lands($actor, 'Forest', 2);
        $this->game->activate($actor, $id, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $oldIds = array_keys($this->game->objects);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame('Phyrexian Token', $this->game->objects[$id]->name());
        $this->resolveOne();
        $copy = $this->newCopy($oldIds);
        $this->assertSame(GameObject::GONE, $this->zone($copy));
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider actors */
    public function testRemovedIncubatorTargetProducesNoCopyAtResolution(int $actor): void
    {
        $this->newGame();
        $id = $this->incubate($actor);
        $this->transform($actor, $id);
        $oldIds = $this->castCopy($actor, $id);
        // Supporting response-removal unit route; copy target is revalidated at resolution.
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame([], array_diff(array_keys($this->game->objects), $oldIds));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GONE, $this->zone($id));
    }

    /** @dataProvider actors */
    public function testCopyRejectsUnpaidCostAndOpposingTargetWithoutMutation(int $actor): void
    {
        $this->newGame();
        $id = $this->incubate($actor);
        $this->transform($actor, $id);
        $card = ['name' => 'Permanent Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target permanent you control."];
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->priority = $actor;
        $before = $this->saved();
        try {
            $this->game->cast($actor, $spell, 0, ['o:'.$id]);
            $this->fail('A blue copy cost cannot be paid without a blue source.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
        $land = $this->lands($actor, 'Island', 1)[0];
        $this->game->objects[$id]->controller = 1 - $actor;
        $before = $this->saved();
        try {
            $this->game->cast($actor, $spell, 0, ['o:'.$id]);
            $this->fail('The copy spell requires a permanent its caster controls.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
        $this->assertFalse($this->game->objects[$land]->tapped);
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
