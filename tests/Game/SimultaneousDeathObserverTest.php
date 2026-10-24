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
final class SimultaneousDeathObserverTest extends GameTestCase
{
    public function testExactPinnedPrintingFixtureHasNotDrifted(): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'));
        $this->assertSame('44e9b312a983d7cc1e477ba44d6049c961fcb2e7c6096352836552e2aa8c0cb6', hash('sha256', $bytes));
    }

    private function put(string $name, int $owner = 0, string $zone = GameObject::BATTLEFIELD): int
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR);
        $card = $fixture['cards'][$name]['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported, $name);

        return $this->game->addCard($owner, $card, $zone)->id;
    }

    private function resolveAll(): void
    {
        for ($i = 0; $this->game->stack !== [] && $i < 30; $i++) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
    }

    private function reload(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider cases */
    public function testDestroyAllRetainsEveryPreEventObserver(string $name, bool $first, bool $stolen, bool $bounce): void
    {
        $this->newGame();
        $this->lands(0, $bounce ? 'Island' : 'Swamp', 5);
        $observer = $first ? $this->put($name, $stolen ? 1 : 0) : null;
        $own = $this->battlefield(0, 'Grizzly Bears');
        $opposing = $this->battlefield(1, 'Grizzly Bears');
        $token = $this->game->addCard(0, ['name' => 'Bear token', 'type' => 'Creature - Bear', 'token' => true, 'power' => '2', 'toughness' => '2', 'text' => null], GameObject::BATTLEFIELD)->id;
        $observer ??= $this->put($name, $stolen ? 1 : 0);
        $this->game->objects[$observer]->controller = 0;
        $initialHand = count($this->game->players[0]->hand);
        $spell = $this->put($bounce ? 'Evacuation' : 'Damnation', 0, GameObject::HAND);
        $this->game->cast(0, $spell);
        $saved = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->resolveAll();
        $expected = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($saved);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $pending = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount($bounce ? 0 : ($name === 'Zulaport Cutthroat' ? 3 : 1), $this->game->stack, 'Each qualifying event queues once, with no self/token/opponent duplicates.');
        $this->resolveAll();
        $this->assertSame($expected, json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game = Game::fromArray($pending);
        $this->resolveAll();
        $this->assertSame($expected, json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        foreach ([$own, $opposing, $observer] as $id) {
            $this->assertSame($bounce ? GameObject::HAND : GameObject::GRAVEYARD, $this->game->objects[$id]->zone);
        }
        $this->assertSame(GameObject::GONE, $this->game->objects[$token]->zone);
        $drain = ! $bounce && $name === 'Zulaport Cutthroat' ? 3 : 0;
        $this->assertSame([20 + $drain, 20 - $drain], array_map(fn ($player) => $player->life, $this->game->players));
        $draws = ! $bounce && $name === 'Grim Haruspex' ? 1 : 0;
        $returned = $bounce ? 1 + (int) ! $stolen : 0;
        $this->assertCount($initialHand + $draws + $returned, $this->game->players[0]->hand, 'Only another owned nontoken death draws; bounced cards are not deaths.');
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['Zulaport Cutthroat', 'Grim Haruspex'] as $name) {
            foreach ([true, false] as $first) {
                foreach ([false, true] as $stolen) {
                    $cases[$name.' '.(int) $first.' '.(int) $stolen] = [$name, $first, $stolen, false];
                }
                $cases[$name.' bounce '.(int) $first] = [$name, $first, false, true];
            }
        }

        return $cases;
    }

    private function probe(string $text, string $zone = GameObject::BATTLEFIELD, string $type = 'Creature - Human'): int
    {
        $card = ['name' => 'Batch probe', 'type' => $type, 'manaCost' => '{2}{B}{B}', 'power' => '1', 'toughness' => '1', 'text' => $text];
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard(0, $card, $zone)->id;
    }

    /** @dataProvider protections */
    public function testOnlyActualDeathsNotifyTheBatch(string $protection, bool $observerSurvives): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $observer = $this->put('Zulaport Cutthroat');
        $victim = $this->battlefield(0, 'Grizzly Bears');
        $protected = $this->game->objects[$observerSurvives ? $observer : $victim];
        match ($protection) {
            'indestructible' => $protected->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['indestructible']],
            'shield' => $protected->addCounters('shield', 1),
            'regenerate', 'no regeneration' => $protected->shields = 1,
            'exile' => $protected->exileIfDies = $this->game->turn,
        };
        $spell = $protection === 'no regeneration' ? $this->put('Damnation', 0, GameObject::HAND) : $this->probe('Destroy all creatures.', GameObject::HAND, 'Sorcery');
        $this->game->cast(0, $spell);
        $this->reload();
        $this->resolveAll();
        $deaths = $protection === 'no regeneration' ? 2 : 1;
        $this->assertSame([20 + $deaths, 20 - $deaths], [$this->life(0), $this->life(1)]);
        $zone = match ($protection) {
            'exile' => GameObject::EXILE,
            'no regeneration' => GameObject::GRAVEYARD,
            default => GameObject::BATTLEFIELD,
        };
        $this->assertSame($zone, $this->zone($protected->id));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($observerSurvives ? $victim : $observer));
        if ($protection === 'shield') {
            $this->assertSame(0, $this->game->objects[$protected->id]->counter('shield'));
        }
    }

    public static function protections(): array
    {
        return [['indestructible', false], ['shield', false], ['regenerate', false], ['exile', false], ['no regeneration', false], ['indestructible', true], ['shield', true], ['regenerate', true]];
    }

    /** @dataProvider eventFacts */
    public function testEachDeathKeepsItsOwnFactsAndOncePerTurnMarks(string $text, bool $observerFirst, bool $strongFirst): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $observer = $observerFirst ? $this->probe($text) : null;
        $first = $this->battlefield(0, 'Grizzly Bears');
        $second = $this->battlefield(0, 'Grizzly Bears');
        $this->game->objects[$strongFirst ? $first : $second]->addCounters('+1/+1', 1);
        $opponent = $this->battlefield(1, 'Grizzly Bears');
        $this->game->objects[$opponent]->addCounters('+1/+1', 2);
        $observer ??= $this->probe($text);
        $this->game->cast(0, $this->put('Damnation', 0, GameObject::HAND));
        $this->resolveAll();
        $this->assertCount(1, $this->game->players[0]->hand, 'Only the qualifying own death draws; the once-per-turn ability includes its own death at most once.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($observer));
    }

    public static function eventFacts(): array
    {
        $cases = [];
        foreach (['Whenever another creature you control with power 3 or greater dies, draw a card.', 'Whenever another creature you control with a +1/+1 counter on it dies, draw a card.', 'Whenever a creature you control dies, draw a card. This ability triggers only once each turn.'] as $text) {
            foreach ([true, false] as $observerFirst) {
                foreach ([true, false] as $strongFirst) {
                    $cases[] = [$text, $observerFirst, $strongFirst];
                }
            }
        }

        return $cases;
    }

    public function testFaceDownObserverHasNoPrintedDeathAbility(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 7);
        $observer = $this->put('Grim Haruspex', 0, GameObject::HAND);
        $this->game->cast(0, $observer, 0, [], 'morph');
        $this->resolveAll();
        $this->assertTrue($this->game->objects[$observer]->faceDown);
        $this->battlefield(0, 'Grizzly Bears');
        $this->game->cast(0, $this->put('Damnation', 0, GameObject::HAND));
        $this->reload();
        $this->resolveAll();
        $this->assertSame([], $this->game->players[0]->hand);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($observer));
    }

    /** @dataProvider observerOrders */
    public function testBothControllersKeepTheirOwnObserverAbilities(bool $ownFirst): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $this->put('Zulaport Cutthroat', $ownFirst ? 0 : 1);
        $this->battlefield(0, 'Grizzly Bears');
        $this->battlefield(0, 'Grizzly Bears');
        $this->put('Zulaport Cutthroat', $ownFirst ? 1 : 0);
        $this->game->cast(0, $this->put('Damnation', 0, GameObject::HAND));
        $this->reload();
        $this->resolveAll();
        $this->assertSame([22, 18], [$this->life(0), $this->life(1)], 'Alice has three deaths, Bob one; each ability retains its own controller.');
    }

    public static function observerOrders(): array
    {
        return [[true], [false]];
    }

    public function testDepartedObserverDoesNotSeeLaterSeparateDeaths(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 7);
        $observer = $this->put('Zulaport Cutthroat');
        $this->battlefield(0, 'Grizzly Bears');
        $this->game->cast(0, $this->put('Damnation', 0, GameObject::HAND));
        $this->resolveAll();
        $this->assertSame([22, 18], [$this->life(0), $this->life(1)]);
        $later = $this->battlefield(0, 'Grizzly Bears');
        $this->game->cast(0, $this->hand(0, 'Murder'), 0, ['o:'.$later]);
        $this->reload();
        $this->resolveAll();
        $this->assertSame([22, 18], [$this->life(0), $this->life(1)]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($observer));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($later));
    }
}
