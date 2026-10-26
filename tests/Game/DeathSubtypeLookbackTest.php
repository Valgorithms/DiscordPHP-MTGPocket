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
final class DeathSubtypeLookbackTest extends GameTestCase
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
            'front face' => ['front', ['Human'], 21],
            'transformed back face' => ['transformed', ['Elf'], 22],
            'face down' => ['face down', [], 20],
        ];
    }

    /** @dataProvider faces */
    public function testSubtypeObserversUseTheBattlefieldFace(string $face, array $subtypes, int $expectedLife): void
    {
        $game = $this->newGame();
        foreach (['Human' => 1, 'Elf' => 2] as $subtype => $life) {
            $card = ['name' => $subtype.' Observer Fixture', 'type' => 'Enchantment', 'text' => "Whenever a {$subtype} you control dies, you gain {$life} life."];
            $this->assertSame([], (new CardDefinition($card))->unsupported);
            $game->addCard(0, $card, GameObject::BATTLEFIELD);
        }
        $card = ['name' => 'Changing Fixture', 'type' => 'Creature - Human', 'manaCost' => '{1}', 'power' => '2', 'toughness' => '2',
            'text' => "{1}: Transform Changing Fixture.\nMorph {1}",
            'back' => ['name' => 'Changed Fixture', 'type' => 'Creature - Elf', 'power' => '2', 'toughness' => '2', 'text' => '']];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $game->addCard(0, $card, $face === 'face down' ? GameObject::HAND : GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Forest', 3);
        if ($face === 'face down') {
            $game->cast(0, $id, 0, [], 'morph');
            $this->resolveAll();
        } elseif ($face === 'transformed') {
            $game->activate(0, $id, 0);
            $this->resolveAll();
        }
        $this->assertSame($subtypes, $game->objects[$id]->definition()->subtypes);
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->hand(0, 'Murder'), 0, ['o:'.$id]);
        $saved = json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertSame(['Human'], $this->game->objects[$id]->definition()->subtypes);
        $this->assertSame($expectedLife, $this->life(0));
        $this->assertSame(20, $this->life(1));
        $expected = $this->game->toArray();
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame($expected, $this->game->toArray());
    }
}
