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
final class DepartingCreatureAbilityTest extends GameTestCase
{
    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
    }

    public static function faces(): array
    {
        return [
            'front face' => ['front', 0, 21, 20, GameObject::GRAVEYARD],
            'transformed back face' => ['transformed', 0, 22, 20, GameObject::GRAVEYARD],
            'face down has no printed ability' => ['face down', 0, 20, 20, GameObject::GRAVEYARD],
            'back face returns new graveyard object' => ['return', 0, 20, 20, GameObject::HAND],
            'stolen back face uses previous controller' => ['transformed', 1, 20, 22, GameObject::GRAVEYARD],
        ];
    }

    /** @dataProvider faces */
    public function testOwnDeathAbilityUsesTheBattlefieldFace(string $face, int $controller, int $aliceLife, int $bobLife, string $destination): void
    {
        $game = $this->newGame();
        $card = ['name' => 'Changing Death Fixture', 'type' => 'Creature - Human', 'manaCost' => '{1}', 'power' => '2', 'toughness' => '2',
            'text' => "{1}: Transform Changing Death Fixture.\nMorph {1}\nWhen this creature dies, you gain 1 life.",
            'back' => ['name' => 'Changed Death Fixture', 'type' => 'Creature - Elf', 'power' => '2', 'toughness' => '2',
                'text' => $face === 'return' ? "When this creature dies, return it to its owner's hand." : 'When this creature dies, you gain 2 life.']];
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
        } elseif ($face !== 'front') {
            $game->activate($controller, $id, 0);
            $this->resolveAll();
        }
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->hand(0, 'Murder'), 0, ['o:'.$id]);
        $savedRemoval = json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($savedRemoval);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertCount($face === 'face down' ? 0 : 1, $this->game->stack);
        $savedTrigger = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($savedTrigger);
        $this->resolveAll();
        $this->assertSame($destination, $this->zone($id));
        $this->assertSame('Changing Death Fixture', $this->game->objects[$id]->name());
        $this->assertSame([$aliceLife, $bobLife], [$this->life(0), $this->life(1)]);
        $expected = $this->game->toArray();
        $this->game = Game::fromArray($savedRemoval);
        $this->resolveAll();
        $this->assertSame($expected, $this->game->toArray());
    }
}
