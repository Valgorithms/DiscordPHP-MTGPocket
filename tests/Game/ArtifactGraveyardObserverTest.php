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
final class ArtifactGraveyardObserverTest extends GameTestCase
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

    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
    }

    private function damnation(int $seat): int
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR);
        $card = $fixture['cards']['Damnation']['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->lands($seat, 'Swamp', 4);

        return $this->game->addCard($seat, $card, GameObject::HAND)->id;
    }

    public function testExactFixtureHasNotDrifted(): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-observer-marionette-master.json'));
        $this->assertSame('7f7ce7cdddcd200976510eebe22ccead17a0e7eea68ff7c179e990f02f05b335', hash('sha256', $bytes));
    }

    public function testCompleteDiscipleWordingIsRecognizedWithoutDroppingItsOptionalTarget(): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-observer-disciple-of-the-vault.json'));
        $this->assertSame('b33eed918ceedab68166974331934d0653d67a4764ae58e672b2b5d34acc0913', hash('sha256', $bytes));
        $fixture = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $definition = new CardDefinition($fixture['card']);
        $this->assertSame([], $definition->unsupported);
        $this->assertSame([['type' => 'optional_life_loss', 'amount' => 1, 'target' => 'opponent']], $definition->triggersOn('artifact_to_graveyard_any')[0]['effects']);
    }

    /** @dataProvider orders */
    public function testPrintedPowerMarionetteSeesItsSimultaneouslyDyingArtifacts(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $this->game->active = $active;
        $this->game->priority = $active;
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-observer-marionette-master.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], (new CardDefinition($fixture['card']))->unsupported);
        $add = fn () => $this->game->addCard(1 - $controller, $fixture['card'], GameObject::BATTLEFIELD)->id;
        $observer = $first ? $add() : null;
        $artifacts = [];
        foreach ([$controller, $controller, 1 - $controller, 1 - $controller] as $seat) {
            $artifacts[] = $this->battlefield($seat, 'Ornithopter');
        }
        $observer ??= $add();
        $this->game->objects[$observer]->controller = $controller;
        $incarnation = $this->game->objects[$observer]->incarnation;
        $this->game->cast($active, $this->damnation($active));
        $before = $this->saved();
        $this->resolveOne();
        $this->assertCount(2, $this->game->stack, 'Only its own two artifact creatures cause abilities, regardless of observer insertion order.');
        foreach ($this->game->stack as $item) {
            $this->assertSame($controller, $item['controller']);
            $this->assertSame($incarnation, $item['incarnation']);
        }
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame(18, $this->life(1 - $controller));
        $this->assertSame(20, $this->life($controller));
        foreach (array_merge([$observer], $artifacts) as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function orders(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $first) {
                    $cases[] = [$active, $controller, $first];
                }
            }
        }

        return $cases;
    }

    /** @dataProvider burialKinds */
    public function testSelectedBurialsKeepOwnOtherTokenAndOncePerTurnFilters(string $kind, string $article, bool $token, bool $once, int $gain): void
    {
        $this->newGame();
        $text = "Whenever {$article} {$kind} you control is put into a graveyard from the battlefield, you gain 1 life.".($once ? ' This ability triggers only once each turn.' : '');
        $card = ['name' => 'Artifact Burial Observer', 'type' => 'Artifact Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => $text];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $object->controller = 0;
        $partner = $this->game->addCard(0, ['name' => 'Artifact Burial Partner', 'type' => 'Artifact Creature - Construct', 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], GameObject::BATTLEFIELD);
        $this->battlefield(1, 'Ornithopter');
        $incarnation = $object->incarnation;
        $this->game->cast(0, $this->damnation(0));
        $this->resolveOne();
        $this->assertCount($gain, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame(0, $item['controller']);
            $this->assertSame($incarnation, $item['incarnation']);
        }
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame([20 + $gain, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $partner->zone);
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function burialKinds(): array
    {
        return [
            ['artifact', 'an', false, false, 2],
            ['artifact', 'another', false, false, 1],
            ['artifact', 'an', true, false, 2],
            ['nontoken artifact', 'an', true, false, 1],
            ['nontoken artifact', 'another', true, false, 0],
            ['artifact', 'an', false, true, 1],
            ['permanent', 'a', false, false, 2],
            ['permanent', 'another', false, false, 1],
            ['nontoken permanent', 'a', true, false, 1],
            ['land', 'a', false, false, 0],
        ];
    }

    /** @dataProvider replacedBurials */
    public function testExileReplacementIsNotArtifactBurial(bool $sourceExiled, bool $artifactsExiled, int $gain): void
    {
        $this->newGame();
        $card = ['name' => 'Replaced Artifact Observer', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => 'Whenever an artifact you control is put into a graveyard from the battlefield, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        if ($sourceExiled) {
            $object->exileIfDies = $this->game->turn;
        }
        $ids = [$this->battlefield(0, 'Ornithopter'), $this->battlefield(0, 'Ornithopter')];
        foreach ($ids as $id) {
            if ($artifactsExiled) {
                $this->game->objects[$id]->exileIfDies = $this->game->turn;
            }
        }
        $this->game->cast(0, $this->damnation(0));
        $before = $this->saved();
        $this->resolveAll();
        $this->assertSame([20 + $gain, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame($sourceExiled ? GameObject::EXILE : GameObject::GRAVEYARD, $object->zone);
        foreach ($ids as $id) {
            $this->assertSame($artifactsExiled ? GameObject::EXILE : GameObject::GRAVEYARD, $this->zone($id));
        }
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function replacedBurials(): array
    {
        return [[false, false, 2], [true, false, 2], [false, true, 0], [true, true, 0]];
    }

    public function testBackFaceObserverKeepsItsAbilityAndController(): void
    {
        $this->newGame();
        $card = ['name' => 'Artifact Observer Front', 'type' => 'Artifact', 'text' => '{1}: Transform Artifact Observer Front.',
            'back' => ['name' => 'Artifact Observer Back', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => 'Whenever an artifact you control is put into a graveyard from the battlefield, you gain 1 life.']];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $object->controller = 0;
        $this->lands(0, 'Forest', 1);
        $this->game->activate(0, $object->id, 0);
        $this->resolveAll();
        $this->battlefield(0, 'Ornithopter');
        $this->game->cast(0, $this->damnation(0));
        $before = $this->saved();
        $this->resolveAll();
        $this->assertSame([21, 20], [$this->life(0), $this->life(1)]);
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public function testFaceDownSourceDoesNotUseItsPrintedBurialAbility(): void
    {
        $this->newGame();
        $card = ['name' => 'Hidden Artifact Observer', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => "Morph {1}\nWhenever an artifact you control is put into a graveyard from the battlefield, you gain 1 life."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->lands(0, 'Forest', 3);
        $id = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $this->game->cast(0, $id, 0, [], 'morph');
        $this->resolveAll();
        $this->battlefield(0, 'Ornithopter');
        $this->game->cast(0, $this->damnation(0));
        $this->resolveAll();
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    /** @dataProvider singleDepartures */
    public function testSingleSourceBurialUsesOldIncarnationAndOnlyActualGraveyardEntry(string $text, string $zone, int $gain): void
    {
        $this->newGame();
        $card = ['name' => 'Single Artifact Observer', 'type' => 'Artifact', 'text' => 'Whenever an artifact you control is put into a graveyard from the battlefield, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $object->controller = 0;
        $incarnation = $object->incarnation;
        $this->lands(0, 'Forest', 1);
        $spell = ['name' => 'Single Artifact Removal', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => $text];
        $this->assertSame([], (new CardDefinition($spell))->unsupported);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['o:'.$object->id]);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertCount($gain, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame($incarnation, $item['incarnation']);
            $this->assertSame(0, $item['controller']);
        }
        $this->resolveAll();
        $this->assertSame($zone, $object->zone);
        $this->assertSame([20 + $gain, 20], [$this->life(0), $this->life(1)]);
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function singleDepartures(): array
    {
        return [['Destroy target artifact.', GameObject::GRAVEYARD, 1], ['Exile target artifact.', GameObject::EXILE, 0], ["Return target artifact to its owner's hand.", GameObject::HAND, 0]];
    }

    public function testDiscardingAnArtifactIsNotBattlefieldBurial(): void
    {
        $this->newGame();
        $card = ['name' => 'Discard Artifact Observer', 'type' => 'Enchantment', 'text' => 'Whenever an artifact you control is put into a graveyard from the battlefield, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $artifact = $this->hand(0, 'Ornithopter');
        $this->lands(0, 'Swamp', 1);
        $spell = ['name' => 'Artifact Discard Probe', 'type' => 'Sorcery', 'manaCost' => '{B}', 'text' => 'Target player discards a card.'];
        $this->assertSame([], (new CardDefinition($spell))->unsupported);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['p:0']);
        $this->resolveAll();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
    }
}
