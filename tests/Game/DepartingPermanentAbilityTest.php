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
final class DepartingPermanentAbilityTest extends GameTestCase
{
    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
    }

    private function save(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function departures(): array
    {
        return [
            'front leaves into exile' => ['leaves the battlefield', 'front', GameObject::EXILE, 0, 1],
            'back leaves into exile' => ['leaves the battlefield', 'back', GameObject::EXILE, 0, 2],
            'face down leaves into exile' => ['leaves the battlefield', 'face down', GameObject::EXILE, 0, 0],
            'back leaves into hand' => ['leaves the battlefield', 'back', GameObject::HAND, 0, 2],
            'back leaves into graveyard' => ['leaves the battlefield', 'back', GameObject::GRAVEYARD, 0, 2],
            'stolen back leaves into exile' => ['leaves the battlefield', 'back', GameObject::EXILE, 1, 2],
            'front goes to graveyard' => ['is put into a graveyard from the battlefield', 'front', GameObject::GRAVEYARD, 0, 1],
            'back goes to graveyard' => ['is put into a graveyard from the battlefield', 'back', GameObject::GRAVEYARD, 0, 2],
            'face down goes to graveyard' => ['is put into a graveyard from the battlefield', 'face down', GameObject::GRAVEYARD, 0, 0],
            'exile is not graveyard' => ['is put into a graveyard from the battlefield', 'back', GameObject::EXILE, 0, 0],
            'hand is not graveyard' => ['is put into a graveyard from the battlefield', 'back', GameObject::HAND, 0, 0],
            'stolen back goes to graveyard' => ['is put into a graveyard from the battlefield', 'back', GameObject::GRAVEYARD, 1, 2],
        ];
    }

    /** @dataProvider departures */
    public function testOwnDepartureAbilityUsesTheBattlefieldFace(string $event, string $face, string $destination, int $controller, int $gain): void
    {
        $game = $this->newGame();
        // Synthetic faces isolate static ability selection from unrelated card mechanics.
        $card = ['name' => 'Departing Front Fixture', 'type' => 'Creature - Human', 'manaCost' => '{1}', 'power' => '2', 'toughness' => '2',
            'text' => "{1}: Transform Departing Front Fixture.\nMorph {1}\nWhen Departing Front Fixture {$event}, you gain 1 life.",
            'back' => ['name' => 'Departing Back Fixture', 'type' => 'Creature - Elf', 'power' => '2', 'toughness' => '2',
                'text' => "When Departing Back Fixture {$event}, you gain 2 life."]];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $game->addCard(0, $card, $face === 'face down' ? GameObject::HAND : GameObject::BATTLEFIELD);
        $object->controller = $controller;
        $id = $object->id;
        $this->lands($controller, 'Forest', 3);
        if ($controller !== $game->priority) {
            $game->pass($game->priority);
        }
        if ($face === 'face down') {
            $game->cast($controller, $id, 0, [], 'morph');
            $this->resolveAll();
        } elseif ($face === 'back') {
            $game->activate($controller, $id, 0);
            $this->resolveAll();
        }
        $this->lands(0, 'Swamp', 3);
        $this->lands(0, 'Island', 1);
        $spell = match ($destination) {
            GameObject::GRAVEYARD => $this->hand(0, 'Murder'),
            GameObject::HAND => $this->hand(0, 'Unsummon'),
            GameObject::EXILE => $game->addCard(0, ['name' => 'Exile Fixture', 'manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Exile target creature.'], GameObject::HAND)->id,
        };
        $game->cast(0, $spell, 0, ['o:'.$id]);
        $savedRemoval = $this->save();
        $this->game = Game::fromArray($savedRemoval);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($destination, $this->zone($id));
        $this->assertCount($gain === 0 ? 0 : 1, $this->game->stack);
        if ($gain > 0) {
            $this->assertSame(($face === 'back' ? 'Departing Back Fixture' : 'Departing Front Fixture')."'s ability", $this->game->stack[0]['label']);
        }
        $this->game = Game::fromArray($this->save());
        $this->resolveAll();
        $this->assertSame('Departing Front Fixture', $this->game->objects[$id]->name());
        $this->assertSame([20 + ($controller === 0 ? $gain : 0), 20 + ($controller === 1 ? $gain : 0)], [$this->life(0), $this->life(1)]);
        $expected = $this->game->toArray();
        $this->game = Game::fromArray($savedRemoval);
        $this->resolveAll();
        $this->assertSame($expected, $this->game->toArray());
    }

    public function testThragtuskLeavesIntoHandAndCreatesABeast(): void
    {
        $game = $this->newGame();
        $card = ['name' => 'Thragtusk', 'manaCost' => '{4}{G}', 'type' => 'Creature - Beast', 'power' => '5', 'toughness' => '3',
            'text' => "When Thragtusk enters, you gain 5 life.\nWhen Thragtusk leaves the battlefield, create a 3/3 green Beast creature token."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Island', 1);
        $game->cast(0, $this->hand(0, 'Unsummon'), 0, ['o:'.$id]);
        $this->game = Game::fromArray($this->save());
        $this->resolveAll();
        $this->assertSame(GameObject::HAND, $this->zone($id));
        $beasts = array_values(array_filter($this->game->permanents(0), fn (GameObject $o) => $o->definition()->isToken() && in_array('Beast', $o->definition()->subtypes, true)));
        $this->assertCount(1, $beasts);
        $this->assertSame([3, 3], [$this->game->power($beasts[0]), $this->game->toughness($beasts[0])]);
    }

    public static function hatchingPlansZones(): array
    {
        return [['battlefield', 3], ['library', 0]];
    }

    /** @dataProvider hatchingPlansZones */
    public function testHatchingPlansOnlyDrawsFromTheBattlefield(string $origin, int $draw): void
    {
        $game = $this->newGame();
        $card = ['name' => 'Hatching Plans', 'manaCost' => '{1}{U}', 'type' => 'Enchantment',
            'text' => 'When Hatching Plans is put into a graveyard from the battlefield, draw three cards.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $game->addCard(0, $card, $origin)->id;
        $spell = $origin === GameObject::BATTLEFIELD
            ? ['name' => 'Naturalize', 'manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => 'Destroy target artifact or enchantment.']
            : ['name' => 'Mill Fixture', 'manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => 'Target player mills a card.'];
        $this->lands(0, 'Forest', 2);
        $game->cast(0, $game->addCard(0, $spell, GameObject::HAND)->id, 0, [$origin === GameObject::BATTLEFIELD ? 'o:'.$id : 'p:0']);
        $this->game = Game::fromArray($this->save());
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertCount($draw, $this->game->players[0]->hand);
    }
}
