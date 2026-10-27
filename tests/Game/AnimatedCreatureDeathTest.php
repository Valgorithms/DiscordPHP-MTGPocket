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
final class AnimatedCreatureDeathTest extends GameTestCase
{
    private const array FIXTURES = [
        'Animated Land Fixture' => ['type' => 'Land', 'text' => "{1}: Animated Land Fixture becomes a 2/2 green Elemental creature until end of turn. It's still a land."],
        'Animated Artifact Fixture' => ['type' => 'Artifact', 'text' => '{1}: Animated Artifact Fixture becomes a 2/2 Construct artifact creature until end of turn.'],
        'Moldervine Reclamation' => ['type' => 'Enchantment', 'text' => 'Whenever a creature you control dies, you gain 1 life and draw a card.'],
        'Opponent Observer Fixture' => ['type' => 'Enchantment', 'text' => 'Whenever a creature an opponent controls dies, you gain 2 life.'],
        'Global Observer Fixture' => ['type' => 'Enchantment', 'text' => 'Whenever a creature dies, you gain 3 life.'],
        'Exile Fixture' => ['type' => 'Instant', 'manaCost' => '{B}', 'text' => 'Exile target creature.'],
        'Shatter Fixture' => ['type' => 'Instant', 'manaCost' => '{B}', 'text' => 'Destroy target artifact.'],
        'Recover Fixture' => ['type' => 'Sorcery', 'text' => 'Recover {1}'],
    ];

    private function put(int $owner, string $name, string $zone = GameObject::BATTLEFIELD): GameObject
    {
        $card = ['uuid' => 'animated-death-'.$name, 'name' => $name] + self::FIXTURES[$name];
        $this->assertSame([], (new CardDefinition($card))->unsupported, $name);

        return $this->game->addCard($owner, $card, $zone);
    }

    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 30; $n++) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
    }

    private function reload(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    private function observers(): void
    {
        $this->put(0, 'Moldervine Reclamation');
        $this->put(1, 'Moldervine Reclamation');
        $this->put(0, 'Opponent Observer Fixture');
        $this->put(0, 'Global Observer Fixture');
    }

    private function animate(string $name, int $owner = 0, int $controller = 0): int
    {
        $object = $this->put($owner, $name);
        $object->controller = $controller;
        $this->lands($controller, 'Forest', 1);
        $this->assertFalse($this->game->isCreature($object));
        if ($this->game->priority !== $controller) {
            $this->game->pass($this->game->priority);
        }
        $this->game->activate($controller, $object->id, 0);
        $this->resolveAll();
        $this->assertTrue($this->game->isCreature($object));

        return $object->id;
    }

    public static function dyingCreatures(): array
    {
        return [
            'own land' => ['Animated Land Fixture', 0, 0],
            'opponent land' => ['Animated Land Fixture', 1, 1],
            'own artifact' => ['Animated Artifact Fixture', 0, 0],
            'opponent artifact' => ['Animated Artifact Fixture', 1, 1],
            'owner differs from controller' => ['Animated Land Fixture', 0, 1],
        ];
    }

    /** @dataProvider dyingCreatures */
    public function testDeathObserversUseCreatureStatusAndControllerBeforeTheMove(string $name, int $owner, int $controller): void
    {
        $this->newGame();
        $this->observers();
        $id = $this->animate($name, $owner, $controller);
        $this->lands(0, 'Swamp', 3);
        $this->game->cast(0, $this->hand(0, 'Murder'), 0, ['o:'.$id]);
        $this->reload();
        $this->assertTrue($this->game->isCreature($this->game->objects[$id]));
        $saved = $this->game->toArray();
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertContains($id, $this->game->players[$owner]->graveyard);
        $this->assertFalse($this->game->isCreature($this->game->objects[$id]));
        $this->assertSame($controller === 0 ? 24 : 25, $this->life(0));
        $this->assertSame($controller === 1 ? 21 : 20, $this->life(1));
        $this->assertCount($controller === 0 ? 1 : 0, $this->game->players[0]->hand);
        $this->assertCount($controller === 1 ? 1 : 0, $this->game->players[1]->hand);
        $expected = $this->game->toArray();
        $this->game = Game::fromArray($saved);
        $this->resolveAll();
        $this->assertSame($expected, $this->game->toArray(), 'Saved pending removal resolves each death observer exactly once.');
    }

    public static function nonDeaths(): array
    {
        return [['regenerate'], ['indestructible'], ['exile'], ['replace with exile'], ['bounce'], ['ordinary artifact']];
    }

    /** @dataProvider nonDeaths */
    public function testNonDeathsDoNotNotifyCreatureDeathObservers(string $case): void
    {
        $this->newGame();
        $this->observers();
        $id = $case === 'ordinary artifact' ? $this->put(0, 'Animated Artifact Fixture')->id : $this->animate('Animated Artifact Fixture');
        if ($case === 'regenerate') {
            $this->game->objects[$id]->shields = 1;
        } elseif ($case === 'indestructible') {
            $this->game->objects[$id]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['indestructible']];
        } elseif ($case === 'replace with exile') {
            $this->game->objects[$id]->exileIfDies = $this->game->turn;
        }
        $this->lands(0, 'Swamp', 3);
        $this->lands(0, 'Island', 1);
        $spell = match ($case) {
            'exile' => $this->put(0, 'Exile Fixture', GameObject::HAND)->id,
            'ordinary artifact' => $this->put(0, 'Shatter Fixture', GameObject::HAND)->id,
            'bounce' => $this->hand(0, 'Unsummon'),
            default => $this->hand(0, 'Murder'),
        };
        $this->game->cast(0, $spell, 0, ['o:'.$id]);
        $this->reload();
        $this->resolveAll();
        $expected = match ($case) {
            'regenerate', 'indestructible' => GameObject::BATTLEFIELD,
            'exile', 'replace with exile' => GameObject::EXILE,
            'bounce' => GameObject::HAND,
            default => GameObject::GRAVEYARD,
        };
        $this->assertSame($expected, $this->zone($id));
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $this->assertCount($case === 'bounce' ? 1 : 0, $this->game->players[0]->hand);
        $this->assertSame([], $this->game->players[1]->hand);
    }

    public function testAnimatedCreatureDeathNotifiesExistingRecoverHandling(): void
    {
        $this->newGame();
        $id = $this->animate('Animated Land Fixture');
        $recover = $this->put(0, 'Recover Fixture', GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Swamp', 4);
        $this->game->cast(0, $this->hand(0, 'Murder'), 0, ['o:'.$id]);
        $this->reload();
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($recover));
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_recover', $choice['type']);
        $this->game->chooseRecover(0, $choice['id'], 'accept');
        $this->assertSame(GameObject::HAND, $this->zone($recover));
    }
}
