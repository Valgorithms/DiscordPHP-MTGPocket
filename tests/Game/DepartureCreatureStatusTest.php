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
final class DepartureCreatureStatusTest extends GameTestCase
{
    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
    }

    private function observer(int $seat = 0, string $kind = 'creature'): void
    {
        $card = ['name' => 'Departure Observer Fixture', 'type' => 'Enchantment',
            'text' => "Whenever a {$kind} you control leaves the battlefield, you gain 1 life."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->addCard($seat, $card, GameObject::BATTLEFIELD);
    }

    private function removeAndReplay(int $id, string $destination, array $life): void
    {
        $token = $this->game->objects[$id]->definition()->isToken();
        $this->lands(0, 'Forest', 1);
        $text = match ($destination) {
            GameObject::GRAVEYARD => 'Destroy target permanent.',
            GameObject::EXILE => 'Exile target permanent.',
            GameObject::HAND => "Return target permanent to its owner's hand.",
        };
        $spell = ['name' => 'Status Removal Fixture', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => $text];
        $this->assertSame([], (new CardDefinition($spell))->unsupported);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['o:'.$id]);
        $saved = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame($token ? GameObject::GONE : $destination, $this->zone($id));
        $this->assertSame($life, [$this->life(0), $this->life(1)]);
        $expected = $this->game->toArray();
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame($expected, $this->game->toArray());
    }

    public static function animations(): array
    {
        return [
            'animated land exiled' => ['Land', true, GameObject::EXILE, 0, 0, 'creature', [21, 20]],
            'animated artifact exiled' => ['Artifact', true, GameObject::EXILE, 0, 0, 'creature', [21, 20]],
            'animated land destroyed' => ['Land', true, GameObject::GRAVEYARD, 0, 0, 'creature', [21, 20]],
            'animated artifact bounced' => ['Artifact', true, GameObject::HAND, 0, 0, 'creature', [21, 20]],
            'ordinary land is not creature' => ['Land', false, GameObject::EXILE, 0, 0, 'creature', [20, 20]],
            'ordinary artifact is not creature' => ['Artifact', false, GameObject::EXILE, 0, 0, 'creature', [20, 20]],
            'wrong controller' => ['Land', true, GameObject::EXILE, 1, 0, 'creature', [20, 20]],
            'previous controller' => ['Artifact', true, GameObject::GRAVEYARD, 1, 1, 'creature', [20, 21]],
            'nontoken animated land' => ['Land', true, GameObject::EXILE, 0, 0, 'nontoken creature', [21, 20]],
            'animated land remains land' => ['Land', true, GameObject::EXILE, 0, 0, 'land', [21, 20]],
            'animated artifact token is creature token' => ['Artifact', true, GameObject::EXILE, 0, 0, 'creature token', [21, 20], true],
            'ordinary artifact token is not creature token' => ['Artifact', false, GameObject::EXILE, 0, 0, 'creature token', [20, 20], true],
            'nontoken animation is not creature token' => ['Artifact', true, GameObject::EXILE, 0, 0, 'creature token', [20, 20]],
            'animated token is not nontoken creature' => ['Artifact', true, GameObject::EXILE, 0, 0, 'nontoken creature', [20, 20], true],
        ];
    }

    /** @dataProvider animations */
    public function testObserverUsesCreatureStatusBeforeAnimationIsCleared(string $type, bool $animate, string $destination, int $controller, int $observer, string $kind, array $life, bool $token = false): void
    {
        $this->newGame();
        $this->observer($observer, $kind);
        $card = ['name' => 'Animated Departure Fixture', 'type' => $type, 'token' => $token,
            'text' => $type === 'Land'
                ? "{1}: Animated Departure Fixture becomes a 2/2 green Elemental creature until end of turn. It's still a land."
                : '{1}: Animated Departure Fixture becomes a 2/2 Construct artifact creature until end of turn.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $object->controller = $controller;
        $this->lands($controller, 'Forest', 1);
        if ($animate) {
            if ($this->game->priority !== $controller) {
                $this->game->pass($this->game->priority);
            }
            $this->game->activate($controller, $object->id, 0);
            $this->resolveAll();
        }
        $this->assertSame($animate, $this->game->isCreature($object));
        $this->removeAndReplay($object->id, $destination, $life);
    }

    public static function noncreatureModes(): array
    {
        return [['bestow'], ['impending']];
    }

    /** @dataProvider noncreatureModes */
    public function testPrintedCreatureThatIsNotCurrentlyACreatureDoesNotNotifyObserver(string $mode): void
    {
        $this->newGame();
        $this->observer();
        $card = $mode === 'bestow'
            ? ['name' => 'Hopeful Eidolon', 'type' => 'Enchantment Creature - Spirit', 'manaCost' => '{W}', 'power' => '1', 'toughness' => '1',
                'text' => "Bestow {3}{W}\nLifelink\nEnchanted creature gets +1/+1 and has lifelink."]
            : ['name' => 'Impending Departure Fixture', 'type' => 'Enchantment Creature - Avatar', 'manaCost' => '{3}{G}{G}', 'power' => '5', 'toughness' => '5', 'text' => 'Impending 2—{1}{G}{G}'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->lands(0, $mode === 'bestow' ? 'Plains' : 'Forest', 4);
        $id = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $targets = $mode === 'bestow' ? ['o:'.$this->battlefield(0, 'Grizzly Bears')] : [];
        $this->game->cast(0, $id, 0, $targets, $mode);
        $this->resolveAll();
        $this->assertFalse($this->game->isCreature($this->game->objects[$id]));
        $this->removeAndReplay($id, GameObject::EXILE, [20, 20]);
    }
}
