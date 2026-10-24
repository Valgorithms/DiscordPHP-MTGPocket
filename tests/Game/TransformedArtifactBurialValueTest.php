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
final class TransformedArtifactBurialValueTest extends GameTestCase
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

    private function fixture(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/transformed-artifact-mana-value.json'));
        $this->assertSame('c619b7468400e04a89556ed6e4994c624e9181958a583e0f63f24112030a3655', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];

        return array_values(array_filter($entries, fn (array $entry) => str_starts_with($entry['card']['name'], $name.' //')))[0]['card'];
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

    private function craft(int $actor, int $owner): int
    {
        $card = $this->fixture('Clay-Fired Bricks');
        $this->assertSame('32480bfd-5be9-5eb9-989d-6650eb53c16e', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $actor;
        // Seed the front face to isolate burial evidence; paid entry/search is covered separately.
        $artifact = $this->game->addCard($owner, $card, GameObject::BATTLEFIELD)->id;
        $this->game->objects[$artifact]->controller = $actor;
        $material = $this->battlefield($actor, 'Bonesplitter');
        $lands = $this->lands($actor, 'Plains', 7);
        $incarnation = $this->game->objects[$artifact]->incarnation;
        $this->game->activate($actor, $artifact, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->assertSame(GameObject::EXILE, $this->zone($material));
        $this->game = Game::fromArray($this->saved());
        for ($i = 0; $this->game->stack !== [] && $i < 5; $i++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNotSame($incarnation, $this->game->objects[$artifact]->incarnation);
        $this->assertTrue($this->game->objects[$artifact]->transformed);
        $this->assertSame('Cosmium Kiln', $this->game->objects[$artifact]->name());
        $this->assertSame(0, $this->game->objects[$artifact]->definition()->cost->manaValue(), 'The back face has no printed mana cost.');

        return $artifact;
    }

    /** @dataProvider controllers */
    public function testPaidCraftAndSmeltUseFrontManaValueOfDepartingTransformedArtifact(int $actor, int $observer, int $owner): void
    {
        $this->newGame();
        $source = $this->source($observer);
        $artifact = $this->craft($actor, $owner);
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $smelt = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->lands($actor, 'Mountain', 1);
        $spell = $this->game->addCard($actor, $smelt, GameObject::HAND)->id;
        $this->game->cast($actor, $spell, 0, ['o:'.$artifact]);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$artifact]->zone);
        $this->assertFalse($this->game->objects[$artifact]->transformed);
        $this->assertCount(1, $this->game->stack);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->objects[$artifact]->zone === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
    }

    /** @dataProvider characteristicCases */
    public function testPaidBurialPreservesModalCopiedFaceMergedTokenAndXDistinctions(string $kind, int $expected): void
    {
        $this->newGame();
        $source = $this->source(0);
        // These are characteristic unit fixtures, not additional corpus-card claims.
        $card = ['name' => 'Characteristic Front Unit Fixture', 'type' => 'Artifact', 'manaCost' => '{5}', 'text' => null, 'layout' => 'transform',
            'back' => ['name' => 'Characteristic Back Unit Fixture', 'type' => 'Artifact', 'manaCost' => null, 'text' => null]];
        if ($kind === 'modal') {
            $card['layout'] = 'modal_dfc';
            $card['back']['manaCost'] = '{2}';
        }
        if ($kind === 'copied-back-token') {
            // A single-faced copy of the back has no front face to inherit from.
            $card = ['token' => true] + $card['back'];
        } elseif ($kind === 'transforming-token') {
            $card['token'] = $card['back']['token'] = true;
        } elseif ($kind === 'x') {
            $card['manaCost'] = '{X}{2}';
        }
        $object = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $object->transformed = $kind !== 'front';
        if ($kind === 'merged') {
            $top = $this->game->addCard(1, ['name' => 'Merged Unit Fixture', 'type' => 'Artifact', 'manaCost' => '{4}', 'text' => null], GameObject::EXILE);
            $object->addMutation($top->id, $top->card, true);
        }
        $this->assertSame($expected, $object->battlefieldManaValue());
        $this->lands(0, 'Mountain', 1);
        $smelt = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->cast(0, $this->game->addCard(0, $smelt, GameObject::HAND)->id, 0, ['o:'.$object->id]);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(in_array($kind, ['copied-back-token', 'transforming-token'], true) ? GameObject::GONE : GameObject::GRAVEYARD, $this->zone($object->id));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame($expected, $this->game->stack[0]['effects'][0]['amount']);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($expected, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function characteristicCases(): array
    {
        return [['front', 5], ['transform', 5], ['modal', 2], ['copied-back-token', 0], ['transforming-token', 5], ['merged', 4], ['x', 2]];
    }

    public function testFaceDownManaValueIsZeroAndCreatureIsNotAnArtifact(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $object = $this->game->addCard(1, $this->fixture('Clay-Fired Bricks'), GameObject::BATTLEFIELD);
        $object->transformed = $object->faceDown = true;
        $this->assertSame(0, $object->battlefieldManaValue());
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
    }

    /** @dataProvider departureRoutes */
    public function testCraftedBackFaceValueIsFrozenAcrossDepartureRoutesAndJson(string $route): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->craft(1, 0);
        if ($route === 'sacrifice') {
            (new \ReflectionMethod(Game::class, 'sacrifice'))->invoke($this->game, $this->game->objects[$artifact]);
            (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        } elseif ($route === 'exile' || $route === 'hand') {
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], $route);
            (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
            $this->assertSame([], $this->game->stack);
            $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));

            return;
        } else {
            $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'));
            $this->assertSame('9bfd9ecb99db8022a5bc52b775f1e66c8d06ed65c849f8fd202d41a6d8c9bcae', hash('sha256', $bytes));
            $spell = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
            $this->game->active = $this->game->priority = 0;
            $this->lands(0, 'Mountain', 4);
            $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id);
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $amounts = array_column(array_column($this->game->stack, 'effects'), 0);
        $amounts = array_column($amounts, 'amount');
        sort($amounts);
        $this->assertSame($route === 'sacrifice' ? [2] : [0, 0, 2], $amounts);
        // Resetting or returning the departing card cannot change the queued amount.
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$artifact], 1);
        $this->assertFalse($this->game->objects[$artifact]->transformed);
        $this->game = Game::fromArray($this->saved());
        // The returned front now has a supported entry trigger; flush it and
        // resolve it as well as the previously frozen burial observers.
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        for ($i = 0; $i < 6 && $this->game->stack !== []; $i++) {
            $this->resolveOne();
        }
        $this->assertSame(22, $this->life(1));
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame([], $this->game->stack);
    }

    public static function departureRoutes(): array
    {
        return [['sacrifice'], ['destroy-group'], ['exile'], ['hand']];
    }

    public static function controllers(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([0, 1] as $observer) {
                foreach ([0, 1] as $owner) {
                    $cases[] = [$actor, $observer, $owner];
                }
            }
        }

        return $cases;
    }
}
