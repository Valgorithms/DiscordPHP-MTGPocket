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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class EdictChoiceTest extends GameTestCase
{
    private function put(string $name, int $seat, string $zone = GameObject::BATTLEFIELD): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/simultaneous-sacrifices.json'));
        $this->assertSame('fd0f0aae92c0e7560953565d2fbf14f2993711d3ff02e3d42f5ce772c7ad627a', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][$name]['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

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
        for ($i = 0; $this->game->stack !== [] && $i < 30; $i++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
    }

    /** @return array{0: int[][], 1: int, 2: int[]} */
    private function begin(int $active = 0, bool $continuation = false): array
    {
        $this->newGame();
        $this->game->active = $active;
        $this->game->priority = $active;
        $lands = $this->lands($active, 'Swamp', 4);
        $cards = [];
        foreach ([0 => 'Zulaport Cutthroat', 1 => 'Grim Haruspex'] as $seat => $name) {
            $cards[$seat] = [$this->put($name, $seat), $this->battlefield($seat, 'Grizzly Bears'), $this->battlefield($seat, 'Hill Giant')];
        }
        if ($continuation) {
            $spell = $this->game->addCard($active, ['name' => 'Edict continuation probe', 'type' => 'Sorcery', 'manaCost' => '{2}{B}{B}', 'text' => "Each player sacrifices two creatures of their choice.\nDraw a card."], GameObject::HAND)->id;
            $this->assertSame([], $this->game->objects[$spell]->definition()->unsupported);
        } else {
            $spell = $this->put('Barter in Blood', $active, GameObject::HAND);
        }
        $this->game->cast($active, $spell);
        $this->resolveOne();
        $this->assertSame('sacrifice', $this->game->decision($active));

        return [$cards, $spell, $lands];
    }

    /** @dataProvider activePlayers */
    public function testBothPlayersChooseBeforeAnythingMovesAndJsonResumes(int $active): void
    {
        [$cards, $spell, $lands] = $this->begin($active);
        $first = $this->saved();
        $this->assertSame(GameObject::STACK, $this->zone($spell));
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped, 'The spell was paid before the choice.');
        }
        $other = 1 - $active;
        $this->assertNull($this->game->decision($other));
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($cards[$active], $choice['cards']);
        $this->assertSame([2, 2], [$choice['min'], $choice['max']]);
        $this->game->chooseSacrifice($active, $choice['id'], [$cards[$active][0], $cards[$active][2]]);
        $next = $this->game->choiceAwaiting();
        $this->assertSame($other, $next['seat']);
        $this->assertGreaterThan($choice['id'], $next['id']);
        foreach (array_merge(...$cards) as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id), 'All selections wait for both players.');
        }
        $this->assertSame([], $this->game->pendingTriggers);
        $second = $this->saved();
        $this->game->chooseSacrifice($other, $next['id'], [$cards[$other][0], $cards[$other][2]]);
        $this->assertNull($this->game->choiceAwaiting());
        $this->resolveAll();
        $expected = $this->saved();
        $this->assertSame([22, 18], [$this->life(0), $this->life(1)]);
        $this->assertCount(1, $this->game->players[1]->hand);
        foreach ([0, 1] as $seat) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($cards[$seat][1]), 'A player may keep their weakest creature.');
            foreach ([$cards[$seat][0], $cards[$seat][2]] as $id) {
                $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
            }
        }
        $this->game = Game::fromArray($second);
        $this->game->chooseSacrifice($other, $next['id'], [$cards[$other][0], $cards[$other][2]]);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
        $this->game = Game::fromArray($first);
        $this->game->chooseSacrifice($active, $choice['id'], [$cards[$active][0], $cards[$active][2]]);
        $this->game->chooseSacrifice($other, $next['id'], [$cards[$other][0], $cards[$other][2]]);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function activePlayers(): array
    {
        return [[0], [1]];
    }

    public function testInvalidAnswersChangeNothing(): void
    {
        [$cards, , $lands] = $this->begin();
        $id = $this->game->choiceAwaiting()['id'];
        $valid = [$cards[0][0], $cards[0][2]];
        foreach ([[1, $id, $valid], [0, $id + 1, $valid], [0, $id, []], [0, $id, [$cards[0][0]]], [0, $id, $cards[0]], [0, $id, [$cards[0][0], $cards[0][0]]], [0, $id, [$cards[0][0], $cards[1][0]]], [0, $id, [$cards[0][0], $lands[0]]], [0, $id, [$cards[0][0], 999999]], [0, $id, [(string) $cards[0][0], $cards[0][2]]]] as $args) {
            $this->reject(fn () => $this->game->chooseSacrifice(...$args));
        }
        $this->reject(fn () => $this->game->pass(0));
        $this->game->chooseSacrifice(0, $id, $valid);
        $this->reject(fn () => $this->game->chooseSacrifice(0, $id, $valid));
    }

    private function reject(callable $answer): void
    {
        $before = $this->saved();
        try {
            $answer();
            $this->fail('Invalid answer was accepted.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->saved());
    }

    public function testAChangedPreviouslyChosenObjectPreventsPartialSacrifices(): void
    {
        [$cards] = $this->begin();
        $this->game->chooseSacrifice(0, $this->game->choiceAwaiting()['id'], [$cards[0][0], $cards[0][2]]);
        $this->game->objects[$cards[0][0]]->incarnation++;
        $this->reject(fn () => $this->game->chooseSacrifice(1, $this->game->choiceAwaiting()['id'], [$cards[1][0], $cards[1][2]]));
        foreach (array_merge(...$cards) as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
    }

    public function testLaterEffectsWaitAndPracticeBotUsesTheChoiceApi(): void
    {
        [$cards] = $this->begin(0, true);
        $this->assertSame([], $this->game->players[0]->hand);
        $this->game->chooseSacrifice(0, $this->game->choiceAwaiting()['id'], [$cards[0][0], $cards[0][2]]);
        $this->assertSame([], $this->game->players[0]->hand);
        $this->assertTrue(PracticeBot::act($this->game, 1));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount(1, $this->game->players[0]->hand, 'The next spell instruction ran exactly once.');
        $this->resolveAll();
    }

    public function testVersionFourLoadsAndNewChoicesRequireVersionFive(): void
    {
        [$cards] = $this->begin();
        $saved = $this->saved();
        $this->assertSame(5, $saved['schemaVersion']);
        foreach ([4, 5] as $version) {
            $bad = $saved;
            $bad['schemaVersion'] = $version;
            if ($version === 5) {
                unset($bad['pendingChoice']['groups']);
            }
            try {
                Game::fromArray($bad);
                $this->fail('Invalid saved sacrifice choice was accepted.');
            } catch (GameException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $legacy = $this->newGame()->toArray();
        $legacy['schemaVersion'] = 4;
        $this->assertSame(5, Game::fromArray($legacy)->toArray()['schemaVersion']);
    }

    /** @dataProvider invalidSavedChoices */
    public function testMalformedSavedChoicesAreRejected(string $fault): void
    {
        [$cards] = $this->begin();
        $saved = $this->saved();
        switch ($fault) {
            case 'identity':
                $saved['pendingChoice']['id'] = $saved['nextChoiceId'];
                break;
            case 'seat':
                $saved['pendingChoice']['seat'] = 1;
                break;
            case 'count':
                $saved['pendingChoice']['count'] = 1;
                break;
            case 'duplicate seat':
                $saved['pendingChoice']['groups'][1]['seat'] = 0;
                break;
            case 'skipped player':
                $saved['pendingChoice']['group'] = 1;
                $saved['pendingChoice']['seat'] = 1;
                $saved['pendingChoice']['cards'] = $cards[1];
                break;
            case 'duplicate selection':
                $saved['pendingChoice']['groups'][1]['selected'] = [$cards[1][0], $cards[1][0]];
                break;
            case 'foreign selection':
                $saved['pendingChoice']['groups'][1]['selected'] = [$cards[1][0], $cards[0][0]];
                break;
            case 'missing resume':
                unset($saved['pendingChoice']['resume']);
                break;
            case 'scalar resume':
                $saved['pendingChoice']['resume'] = 'invalid';
                break;
            case 'scalar followed':
                $saved['pendingChoice']['resume']['followed'] = 'invalid';
                break;
            case 'new incarnation':
                $saved['objects'][$cards[0][0]]['incarnation']++;
                break;
            case 'missing count':
                unset($saved['pendingChoice']['count'], $saved['pendingChoice']['groups'][0]['count']);
                break;
            case 'empty item':
                $saved['pendingChoice']['resume']['item'] = [];
                break;
            case 'invalid step':
                $saved['pendingChoice']['resume']['steps'] = ['not a resolution instruction'];
                break;
            case 'scalar self':
                $saved['pendingChoice']['resume']['self'] = 'invalid';
                break;
        }
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    public static function invalidSavedChoices(): array
    {
        return array_map(fn (string $fault) => [$fault], ['identity', 'seat', 'count', 'duplicate seat', 'skipped player', 'duplicate selection', 'foreign selection', 'missing resume', 'scalar resume', 'scalar followed', 'new incarnation', 'missing count', 'empty item', 'invalid step', 'scalar self']);
    }

    /** @dataProvider forcedPlayers */
    public function testOnlyThePlayerWithExtraCreaturesMustChoose(int $active, int $forcedCount): void
    {
        $this->newGame();
        $this->game->active = $active;
        $this->game->priority = $active;
        $this->lands($active, 'Swamp', 4);
        $choosing = 1 - $active;
        $forced = [];
        for ($i = 0; $i < $forcedCount; $i++) {
            $forced[] = $this->battlefield($active, 'Grizzly Bears');
        }
        $cards = [$this->battlefield($choosing, 'Grizzly Bears'), $this->battlefield($choosing, 'Hill Giant'), $this->battlefield($choosing, 'Hill Giant')];
        $spell = $this->put('Barter in Blood', $active, GameObject::HAND);
        $this->game->cast($active, $spell);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($choosing, $choice['seat']);
        foreach ($forced as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseSacrifice($choosing, $choice['id'], [$cards[1], $cards[2]]);
        $this->assertNull($this->game->choiceAwaiting());
        foreach (array_merge($forced, [$cards[1], $cards[2]]) as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($cards[0]));
        $this->reject(fn () => $this->game->chooseSacrifice($choosing, $choice['id'], [$cards[1], $cards[2]]));
    }

    public static function forcedPlayers(): array
    {
        return [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2]];
    }

    public function testAnEntryAbilityResumesAfterItsOwnSourceIsSacrificed(): void
    {
        $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $cards = [];
        foreach ([0 => 2, 1 => 3] as $seat => $count) {
            for ($i = 0; $i < $count; $i++) {
                $cards[$seat][] = $this->battlefield($seat, 'Grizzly Bears');
            }
        }
        $source = $this->game->addCard(0, ['name' => 'Entry edict continuation fixture', 'type' => 'Creature — Human', 'manaCost' => '{2}{B}{B}', 'power' => '3', 'toughness' => '3',
            'text' => 'When this creature enters, each player sacrifices two creatures of their choice. Draw a card.'], GameObject::HAND)->id;
        $this->assertSame([], $this->game->objects[$source]->definition()->unsupported);
        $this->game->cast(0, $source);
        $this->resolveOne();
        $this->resolveOne();
        $first = $this->game->choiceAwaiting();
        $this->assertSame('sacrifice', $first['type']);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseSacrifice(0, $first['id'], [$source, $cards[0][0]]);
        $second = $this->game->choiceAwaiting();
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseSacrifice(1, $second['id'], [$cards[1][0], $cards[1][1]]);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(1, $this->game->players[0]->hand, 'The independent triggered ability finishes after its source leaves.');
    }
}
