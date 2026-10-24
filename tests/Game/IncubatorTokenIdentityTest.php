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

/** @covers \MTGPocket\Game\CardDefinition */
final class IncubatorTokenIdentityTest extends GameTestCase
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

    private function selected(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/converter-beast-incubate.json'));
        $this->assertSame('2ac7e35b8c1862547ddaa153242c3f598efd5446c2e592450be6eb417315d739', hash('sha256', $bytes));
        $entry = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('MOM', $entry['setCode']);
        $this->assertSame('7d369fb5-4b98-59da-8373-60a0cd03486f', $entry['card']['uuid']);
        $this->assertSame([], (new CardDefinition($entry['card']))->unsupported);

        return $entry['card'];
    }

    private function source(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        $card = array_values(array_filter($entries, fn (array $entry) => $entry['card']['uuid'] === '6e88e450-3e9c-575e-96b2-6b0ac6a9434d'))[0]['card'];
        $this->game->active = $this->game->priority = $seat;
        $this->lands($seat, 'Mountain', 5);
        $source = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $source);
        $this->resolveOne();

        return $source;
    }

    private function incubate(int $seat): int
    {
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 4);
        $beast = $this->game->addCard($seat, $this->selected(), GameObject::HAND)->id;
        $this->game->cast($seat, $beast);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertCount(1, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $tokens = array_values(array_filter($this->game->objects, fn (GameObject $object) => $object->zone === GameObject::BATTLEFIELD && $object->name() === 'Incubator Token'));
        $this->assertCount(1, $tokens);
        $token = $tokens[0];
        $this->assertTrue($token->definition()->isToken());
        $this->assertSame(5, $token->counter('+1/+1'));
        $this->assertFalse($this->game->isCreature($token));

        return $token->id;
    }

    private function transform(int $seat, int $id): void
    {
        $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 2);
        $incarnation = $this->game->objects[$id]->incarnation;
        $this->game->activate($seat, $id, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $object = $this->game->objects[$id];
        $this->assertSame($incarnation, $object->incarnation);
        $this->assertSame('Phyrexian Token', $object->name());
        $this->assertTrue($this->game->isCreature($object));
        $this->assertSame(5, $this->game->power($object));
        $this->assertSame(5, $object->counter('+1/+1'));
        $this->assertSame(0, $object->battlefieldManaValue());
    }

    /** @dataProvider players */
    public function testPaidConverterTransformAndSmeltRemoveTokenWithoutLeavingGraveyardCard(int $actor, int $observer, int $caster): void
    {
        $this->newGame();
        $source = $this->source($observer);
        $id = $this->incubate($actor);
        $this->transform($actor, $id);
        $this->game->priority = $caster;
        $land = $this->lands($caster, 'Mountain', 1)[0];
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $smelt = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->cast($caster, $this->game->addCard($caster, $smelt, GameObject::HAND)->id, 0, ['o:'.$id]);
        $this->assertTrue($this->game->objects[$land]->tapped);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($id));
        foreach ($this->game->players as $player) {
            $this->assertNotContains($id, $player->graveyard);
        }
        $this->assertCount(1, $this->game->stack, 'An artifact token with zero mana value still triggers Joven.');
        $this->assertSame(0, $this->game->stack[0]['effects'][0]['amount']);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($id) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
    }

    /** @dataProvider identityMarkers */
    public function testFrontTokenIdentitySurvivesBackDefinitionAndOldSavedShapes(bool $token, ?bool $backMarker): void
    {
        // Characteristic unit controls; token identity cannot be changed by the back's data.
        $card = ['name' => 'Identity Front Unit Fixture', 'type' => 'Artifact', 'text' => null, 'manaCost' => '{3}', 'token' => $token,
            'back' => ['name' => 'Identity Back Unit Fixture', 'type' => 'Artifact', 'text' => null, 'manaCost' => null]];
        if ($backMarker !== null) {
            $card['back']['token'] = $backMarker;
        }
        $definition = new CardDefinition($card);
        $this->assertSame($token, $definition->isToken());
        $this->assertSame($token, $definition->back->isToken());
        $this->assertSame(0, $definition->back->cost->manaValue());
        $this->newGame();
        $id = $this->game->addCard(1, $card, GameObject::BATTLEFIELD)->id;
        $this->game->objects[$id]->transformed = true;
        $this->game = Game::fromArray($this->saved());
        $this->assertSame($token, $this->game->objects[$id]->definition()->isToken());
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $this->zone($id));
        $this->assertSame(! $token, in_array($id, $this->game->players[1]->graveyard, true));
    }

    public static function identityMarkers(): array
    {
        return [[true, null], [true, false], [true, true], [false, null], [false, false], [false, true]];
    }

    /** @dataProvider departureRoutes */
    public function testActualIncubatorDepartureRoutesRetainBurialAndCreatureDeathSemantics(int $actor, bool $transformed, string $route): void
    {
        $this->newGame();
        $source = $this->source(1 - $actor);
        $observer = ['name' => 'Creature Death Life Unit Fixture', 'type' => 'Enchantment', 'manaCost' => '{1}', 'text' => 'Whenever another creature dies, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($observer))->unsupported);
        $this->game->addCard(0, $observer, GameObject::BATTLEFIELD);
        $id = $this->incubate($actor);
        if ($transformed) {
            $this->transform($actor, $id);
        }
        // Supporting direct movement/control unit route; creation and transform are paid.
        $this->game->objects[$id]->controller = 1 - $actor;
        $life = $this->game->players[0]->life;
        $this->game = Game::fromArray($this->saved());
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], $route);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame(GameObject::GONE, $this->zone($id));
        foreach ($this->game->players as $player) {
            foreach (['graveyard', 'hand', 'library'] as $zone) {
                $this->assertNotContains($id, $player->$zone);
            }
        }
        $this->assertNotContains($id, $this->game->exile);
        $burial = $route === GameObject::GRAVEYARD;
        $count = (int) $burial + (int) ($burial && $transformed);
        $this->assertCount($count, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        for ($i = 0; $i < $count; $i++) {
            $this->resolveOne();
        }
        $this->assertSame($life + (int) ($burial && $transformed), $this->game->players[0]->life);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame([], $this->game->stack);
    }

    public static function departureRoutes(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $transformed) {
                foreach ([GameObject::GRAVEYARD, GameObject::EXILE, GameObject::HAND, GameObject::LIBRARY] as $route) {
                    $cases[] = [$actor, $transformed, $route];
                }
            }
        }

        return $cases;
    }

    /** @dataProvider actors */
    public function testNontokenDeathObserversIgnoreTransformedIncubatorButSeeOrdinaryCard(int $actor): void
    {
        $this->newGame();
        $id = $this->incubate($actor);
        $this->transform($actor, $id);
        foreach (['Whenever a nontoken creature dies, you gain 1 life.', 'Whenever a nontoken permanent you control leaves the battlefield, you gain 1 life.'] as $text) {
            $watcher = ['name' => 'Nontoken Departure Unit Fixture', 'type' => 'Enchantment', 'text' => $text];
            $this->assertSame([], (new CardDefinition($watcher))->unsupported);
            $this->game->addCard($actor, $watcher, GameObject::BATTLEFIELD);
        }
        $life = $this->game->players[$actor]->life;
        $this->game = Game::fromArray($this->saved());
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame(GameObject::GONE, $this->zone($id));
        $this->assertSame([], $this->game->stack);
        $this->assertSame($life, $this->game->players[$actor]->life);
        $ordinary = $this->game->addCard($actor, ['name' => 'Ordinary Artifact Creature Unit Fixture', 'type' => 'Artifact Creature', 'power' => '1', 'toughness' => '1'], GameObject::BATTLEFIELD)->id;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$ordinary], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($ordinary));
        $this->assertCount(2, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame($life + 2, $this->game->players[$actor]->life);
    }

    /** @dataProvider actors */
    public function testTransformedIncubatorWithZeroToughnessDiesAsToken(int $actor): void
    {
        $this->newGame();
        $source = $this->source(1 - $actor);
        $id = $this->incubate($actor);
        $this->transform($actor, $id);
        $this->game->objects[$id]->counters = [];
        $this->game = Game::fromArray($this->saved());
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame(GameObject::GONE, $this->zone($id));
        $this->assertNotContains($id, $this->game->players[$actor]->graveyard);
        $this->assertCount(1, $this->game->stack);
        $this->assertSame(0, $this->game->stack[0]['effects'][0]['amount']);
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function players(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([0, 1] as $observer) {
                foreach ([0, 1] as $caster) {
                    $cases[] = [$actor, $observer, $caster];
                }
            }
        }

        return $cases;
    }
}
