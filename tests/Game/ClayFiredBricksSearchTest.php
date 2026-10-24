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
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class ClayFiredBricksSearchTest extends GameTestCase
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

    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/transformed-artifact-mana-value.json'));
        $this->assertSame('c619b7468400e04a89556ed6e4994c624e9181958a583e0f63f24112030a3655', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        return array_values(array_filter($entries, fn (array $entry) => $entry['card']['uuid'] === '32480bfd-5be9-5eb9-989d-6650eb53c16e'))[0]['card'];
    }

    private function prepare(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
    }

    private function castBricks(int $actor): int
    {
        $lands = $this->lands($actor, 'Plains', 2);
        $id = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->game->cast($actor, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(20, $this->life($actor));
        return $id;
    }

    private function libraryCard(int $seat, array $card): int
    {
        return $this->game->addCard($seat, $card, GameObject::LIBRARY)->id;
    }

    private function snowPlains(): array
    {
        return array_replace(self::card('Plains'), ['name' => 'Snow-Covered Plains', 'supertypes' => ['Basic', 'Snow'], 'type' => 'Basic Snow Land — Plains']);
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Illegal search action must be rejected.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
    }

    /** @dataProvider selections */
    public function testPaidEntryOffersOnlyBasicPlainsAndResumesLifeGain(int $actor, bool $snow): void
    {
        $this->prepare($actor);
        $plain = $this->libraryCard($actor, self::card('Plains'));
        $snowId = $this->libraryCard($actor, $this->snowPlains());
        $nonbasic = $this->libraryCard($actor, ['name' => 'Nonbasic Plains Fixture', 'type' => 'Land — Plains', 'types' => ['Land'], 'subtypes' => ['Plains'], 'supertypes' => [], 'text' => '']);
        $notPlains = $this->libraryCard($actor, self::card('Island'));
        $notLand = $this->libraryCard($actor, ['name' => 'Basic Nonland Plains Fixture', 'type' => 'Basic Creature — Plains', 'types' => ['Creature'], 'subtypes' => ['Plains'], 'supertypes' => ['Basic'], 'power' => '1', 'toughness' => '1', 'text' => '']);
        $opposing = $this->libraryCard(1 - $actor, self::card('Plains'));
        $hand = $this->hand($actor, 'Plains');
        $bricks = $this->castBricks($actor);
        $this->assertCount(1, $this->game->stack, 'Printed entry ability must trigger.');
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertNotNull($choice);
        $this->assertSame('look', $choice['type']);
        $this->assertSame($actor, $choice['seat']);
        $this->assertSame(1, $choice['take']);
        $this->assertTrue($choice['may']);
        $this->assertEqualsCanonicalizing([$plain, $snowId], $choice['eligible']);
        $this->assertSame([], (new CardDefinition($this->fixture()))->unsupported);
        $this->assertSame(20, $this->life($actor), 'Gain waits for search, reveal and shuffle.');
        $this->game = Game::fromArray($this->saved());
        $this->assertSame($choice, $this->game->choiceAwaiting());
        foreach ([$nonbasic, $notPlains, $notLand, $opposing, $hand] as $invalid) {
            $this->reject(fn () => $this->game->take($actor, [$invalid]));
        }
        $this->reject(fn () => $this->game->take(1 - $actor, [$plain]));
        $this->reject(fn () => $this->game->take($actor, [$plain, $snowId]));
        $this->reject(fn () => $this->game->pass($actor));
        $selected = $snow ? $snowId : $plain;
        $other = $snow ? $plain : $snowId;
        $library = $this->game->players[$actor]->library;
        $opponentLibrary = $this->game->players[1 - $actor]->library;
        $before = $this->saved();
        $this->game->take($actor, [$selected]);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame(GameObject::HAND, $this->zone($selected));
        $this->assertSame(GameObject::LIBRARY, $this->zone($other));
        $this->assertSame(GameObject::LIBRARY, $this->zone($nonbasic));
        $this->assertSame($actor, $this->game->objects[$selected]->owner);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bricks));
        $this->assertEqualsCanonicalizing(array_values(array_diff($library, [$selected])), $this->game->players[$actor]->library);
        $this->assertSame($opponentLibrary, $this->game->players[1 - $actor]->library);
        $after = $this->saved();
        $this->assertSame($before['shuffles'] + 1, $after['shuffles']);
        $name = $this->game->objects[$selected]->name();
        $this->assertContains($this->game->players[$actor]->name.' searches their library for '.$name.'.', $this->game->log, 'Selected card name is revealed in the shared game log.');
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$selected]);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, [$selected]));
    }

    /** @dataProvider actors */
    public function testFailingToFindStillShufflesAndGainsLife(int $actor): void
    {
        $this->prepare($actor);
        $plain = $this->libraryCard($actor, self::card('Plains'));
        $this->castBricks($actor);
        $this->resolveOne();
        $this->assertNotNull($this->game->choiceAwaiting());
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, []);
        $this->assertSame(GameObject::LIBRARY, $this->zone($plain));
        $this->assertSame(22, $this->life($actor));
        $after = $this->saved();
        $this->assertSame($before['shuffles'] + 1, $after['shuffles']);
        $this->assertEqualsCanonicalizing($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertFalse((bool) array_filter($this->game->log, fn (string $line) => str_contains($line, 'searches their library for Plains.')));
        $this->game = Game::fromArray($before);
        $this->game->take($actor, []);
        $this->assertSame($after, $this->saved());
    }

    /** @dataProvider noFind */
    public function testNoEligibleOrEmptyLibraryStillGainsLife(int $actor, bool $empty): void
    {
        $this->prepare($actor);
        if ($empty) {
            foreach ($this->game->players[$actor]->library as $id) {
                (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
            }
        }
        $this->castBricks($actor);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(22, $this->life($actor));
        $after = $this->saved();
        $this->assertSame($before['shuffles'] + 1, $after['shuffles']);
        $this->assertEqualsCanonicalizing($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertSame([], $this->game->stack);
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->assertSame($after, $this->saved());
    }

    /** @dataProvider actors */
    public function testPaidRemovalDoesNotCancelEntryTrigger(int $actor): void
    {
        $this->prepare($actor);
        $plain = $this->libraryCard($actor, self::card('Plains'));
        $bricks = $this->castBricks($actor);
        $lands = $this->lands($actor, 'Plains', 2);
        $spell = $this->game->addCard($actor, ['name' => 'Disenchant Fixture', 'type' => 'Instant', 'manaCost' => '{1}{W}', 'text' => 'Destroy target artifact.'], GameObject::HAND)->id;
        $this->game->cast($actor, $spell, 0, ['o:'.$bricks]);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bricks));
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertNotNull($this->game->choiceAwaiting());
        $this->game->take($actor, [$plain]);
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(GameObject::HAND, $this->zone($plain));
    }

    /** @dataProvider actors */
    public function testPaidFrontThenCraftOnlyRunsTheBackEntryAbility(int $actor): void
    {
        $this->prepare($actor);
        $plain = $this->libraryCard($actor, self::card('Plains'));
        $bricks = $this->castBricks($actor);
        $this->resolveOne();
        $this->assertNotNull($this->game->choiceAwaiting());
        $this->game->take($actor, [$plain]);
        $this->assertSame(22, $this->life($actor));
        $material = $this->battlefield($actor, 'Bonesplitter');
        $lands = $this->lands($actor, 'Plains', 7);
        $incarnation = $this->game->objects[$bricks]->incarnation;
        $before = $this->saved();
        $this->game->activate($actor, $bricks, 0);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->assertSame(GameObject::EXILE, $this->zone($material));
        $this->game = Game::fromArray($this->saved());
        for ($i = 0; $i < 5 && $this->game->stack !== []; $i++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame('Cosmium Kiln', $this->game->objects[$bricks]->name());
        $this->assertTrue($this->game->objects[$bricks]->transformed);
        $this->assertNotSame($incarnation, $this->game->objects[$bricks]->incarnation);
        $gnomes = array_values(array_filter($this->game->permanents($actor), fn (GameObject $o) => $o->name() === 'Gnome Token'));
        $this->assertCount(2, $gnomes);
        foreach ($gnomes as $gnome) {
            $this->assertSame(2, $this->game->power($gnome));
            $this->assertSame(2, $this->game->toughness($gnome));
        }
        $this->assertSame(22, $this->life($actor), 'Returning back face must not rerun front search/gain.');
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
    }

    /** @dataProvider actors */
    public function testCounteredPaidSpellNeverSearchesOrGainsLife(int $actor): void
    {
        $this->prepare($actor);
        $this->libraryCard($actor, self::card('Plains'));
        $lands = $this->lands($actor, 'Plains', 2);
        $id = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->game->cast($actor, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game->pass($actor);
        $islands = $this->lands(1 - $actor, 'Island', 2);
        $this->game->cast(1 - $actor, $this->hand(1 - $actor, 'Counterspell'), 0, ['s:'.$this->game->stack[0]['id']]);
        foreach ($islands as $island) {
            $this->assertTrue($this->game->objects[$island]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertSame(20, $this->life($actor));
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
    }

    /** @dataProvider actors */
    public function testUnpaidOrOpposingCastIsAtomic(int $actor): void
    {
        $this->prepare($actor);
        $id = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $id));
        $this->lands($actor, 'Plains', 2);
        $this->reject(fn () => $this->game->cast(1 - $actor, $id));
    }

    /** @dataProvider actors */
    public function testExistingPracticeChoiceUsesTriggerControllerAfterControlChanges(int $actor): void
    {
        $this->prepare($actor);
        $plain = $this->libraryCard($actor, self::card('Plains'));
        $bricks = $this->castBricks($actor);
        // Bounded control-change fixture after the entry trigger is on the stack.
        $this->game->objects[$bricks]->controller = 1 - $actor;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertNotNull($this->game->choiceAwaiting());
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1 - $actor));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, $actor));
        $this->assertSame(GameObject::HAND, $this->zone($plain));
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider unreadWording */
    public function testUntestedBasicLandWordingsRemainUnread(string $wording): void
    {
        $card = $this->fixture();
        $card['text'] = str_replace('basic Plains', $wording, $card['text']);
        $definition = new CardDefinition($card);
        $this->assertCount(1, $definition->unsupported);
        $this->assertStringContainsString($wording, $definition->unsupported[0]);
    }

    public static function unreadWording(): array
    {
        return [['basic Island'], ['basic Plains or Island'], ['snow Plains']];
    }

    public static function selections(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function noFind(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }
}
