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
final class DepartureObserverKindTest extends GameTestCase
{
    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
    }

    private function removeAndReplay(int $id, string $destination, int $expectedLife): void
    {
        $this->lands(0, 'Forest', 1);
        $text = $destination === GameObject::GRAVEYARD ? 'Destroy target permanent.' : 'Exile target permanent.';
        $spell = ['name' => 'Removal Fixture', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => $text];
        $this->assertSame([], (new CardDefinition($spell))->unsupported);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['o:'.$id]);
        $saved = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame($destination, $this->zone($id));
        $this->assertSame($expectedLife, $this->life(0));
        $this->assertSame(20, $this->life(1));
        $expected = $this->game->toArray();
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame($expected, $this->game->toArray());
    }

    private function changingCard(string $front, string $back, string $face, string $frontText = '', string $backText = ''): int
    {
        // Synthetic faces make each observer's positive/negative type choice independent.
        $card = ['name' => 'Observer Front Fixture', 'type' => $front, 'manaCost' => '{1}', 'power' => '2', 'toughness' => '2',
            'text' => "{1}: Transform Observer Front Fixture.\nMorph {1}\n".$frontText,
            'back' => ['name' => 'Observer Back Fixture', 'type' => $back, 'power' => '2', 'toughness' => '2', 'text' => $backText]];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $this->game->addCard(0, $card, $face === 'face down' ? GameObject::HAND : GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Forest', 3);
        if ($face === 'face down') {
            $this->game->cast(0, $id, 0, [], 'morph');
            $this->resolveAll();
        } elseif ($face === 'back') {
            $this->game->activate(0, $id, 0);
            $this->resolveAll();
        }

        return $id;
    }

    public static function observedKinds(): array
    {
        return [
            'front artifact counts' => ['Artifact', 'Enchantment', 'front', 'artifact', GameObject::GRAVEYARD, 0, 21],
            'back enchantment is not artifact' => ['Artifact', 'Enchantment', 'back', 'artifact', GameObject::GRAVEYARD, 0, 20],
            'back artifact counts' => ['Enchantment', 'Artifact', 'back', 'artifact', GameObject::GRAVEYARD, 0, 21],
            'front enchantment is not artifact' => ['Enchantment', 'Artifact', 'front', 'artifact', GameObject::GRAVEYARD, 0, 20],
            'back enchantment counts' => ['Artifact', 'Enchantment', 'back', 'enchantment', GameObject::GRAVEYARD, 0, 21],
            'front artifact is not enchantment' => ['Artifact', 'Enchantment', 'front', 'enchantment', GameObject::GRAVEYARD, 0, 20],
            'back land counts' => ['Artifact', 'Land', 'back', 'land', GameObject::GRAVEYARD, 0, 21],
            'front Human leaves' => ['Creature - Human', 'Creature - Elf', 'front', 'Human', GameObject::EXILE, 0, 21],
            'back Elf is not Human' => ['Creature - Human', 'Creature - Elf', 'back', 'Human', GameObject::EXILE, 0, 20],
            'back Human leaves' => ['Creature - Elf', 'Creature - Human', 'back', 'Human', GameObject::EXILE, 0, 21],
            'face down has no Human subtype' => ['Creature - Human', 'Creature - Elf', 'face down', 'Human', GameObject::EXILE, 0, 20],
            'face down is a creature' => ['Artifact', 'Enchantment', 'face down', 'creature', GameObject::EXILE, 0, 21],
            'exile is not artifact graveyard' => ['Enchantment', 'Artifact', 'back', 'artifact', GameObject::EXILE, 0, 20],
            'wrong controller does not observe' => ['Enchantment', 'Artifact', 'back', 'artifact', GameObject::GRAVEYARD, 1, 20],
        ];
    }

    /** @dataProvider observedKinds */
    public function testSurvivingObserversUseTheDepartingKind(string $front, string $back, string $face, string $kind, string $destination, int $controller, int $expectedLife): void
    {
        $this->newGame();
        $event = in_array($kind, ['Human', 'creature'], true) ? 'leaves the battlefield' : 'is put into a graveyard from the battlefield';
        $article = in_array($kind, ['artifact', 'enchantment'], true) ? 'an' : 'a';
        $watcher = ['name' => 'Kind Observer Fixture', 'type' => 'Enchantment', 'text' => "Whenever {$article} {$kind} you control {$event}, you gain 1 life."];
        $this->assertSame([], (new CardDefinition($watcher))->unsupported);
        $this->game->addCard(0, $watcher, GameObject::BATTLEFIELD);
        $id = $this->changingCard($front, $back, $face);
        $this->game->objects[$id]->controller = $controller;
        $this->removeAndReplay($id, $destination, $expectedLife);
    }

    public static function selfFaces(): array
    {
        return [['front', 21], ['back', 22], ['face down', 20]];
    }

    /** @dataProvider selfFaces */
    public function testDepartingObserverUsesItsOwnBattlefieldAbilityAndKind(string $face, int $expectedLife): void
    {
        $this->newGame();
        $id = $this->changingCard(
            'Artifact',
            'Enchantment',
            $face,
            'Whenever an artifact you control is put into a graveyard from the battlefield, you gain 1 life.',
            'Whenever an enchantment you control is put into a graveyard from the battlefield, you gain 2 life.'
        );
        $this->removeAndReplay($id, GameObject::GRAVEYARD, $expectedLife);
    }
}
