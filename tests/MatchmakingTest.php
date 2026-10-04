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

namespace MTGPocket\Tests;

use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Matches\Ladder;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Matches\MatchService;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Deck;
use MTGPocket\Modes\GameMode;
use MTGPocket\Modes\GameModes;
use MTGPocket\Pocket;

/**
 * Game modes (deck rules and libraries), the matchmaking queue and the
 * ratings ladder.
 *
 * @covers \MTGPocket\Modes\GameMode
 * @covers \MTGPocket\Modes\GameModes
 * @covers \MTGPocket\Matches\MatchService
 * @covers \MTGPocket\Matches\Ladder
 * @covers \MTGPocket\Repository\MatchRepository
 */
final class MatchmakingTest extends PocketTestCase
{
    private const string ALICE = '111111111111111111';
    private const string BOB = '222222222222222222';
    private const string CAROL = '333333333333333333';

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makePocket();
    }

    private function makePocket(?GameModes $modes = null): void
    {
        $this->pocket = new Pocket($this->directory, null, fn () => $this->now, fn () => str_pad(dechex(++$this->seed), 32, 'a', STR_PAD_LEFT), modes: $modes);

        // An old set and a recent one (the clock is 2026-09-21).
        $old = new CardPool('OLD', 'Old Set', '2015-01-01');
        $old->add(['uuid' => 'bears', 'name' => 'Grizzly Bears', 'rarity' => 'common', 'colors' => ['G'], 'manaValue' => 2.0, 'type' => 'Creature — Bear', 'manaCost' => '{1}{G}', 'power' => '2', 'toughness' => '2']);
        $this->pocket->pools->save($old);
        $new = new CardPool('NEW', 'New Set', '2026-06-01');
        $new->add(['uuid' => 'shock', 'name' => 'Shock', 'rarity' => 'common', 'colors' => ['R'], 'manaValue' => 1.0, 'type' => 'Instant', 'manaCost' => '{R}', 'text' => 'Shock deals 2 damage to any target.']);
        $this->pocket->pools->save($new);

        foreach ([[self::ALICE, 'Alice'], [self::BOB, 'Bob'], [self::CAROL, 'Carol']] as [$id, $name]) {
            if ($this->pocket->deckBuilder->list($id) !== []) {
                continue;
            }
            $this->pocket->inventories->addCards($id, ['bears' => 8, 'shock' => 8]);
            $builder = $this->pocket->deckBuilder;
            $builder->create($id, $name, 'Casual', 'casual');
            $builder->add($id, 'Casual', 'bears', 8);
            $builder->add($id, 'Casual', 'Forest', 32);
        }
    }

    public function testTheModesThatComeWithTheGame(): void
    {
        $modes = GameModes::fromFile();
        $this->assertSame(['standard' => 'Standard', 'casual' => 'Casual', 'limited' => 'Limited'], $modes->playable());
        $this->assertFalse($modes->get('commander')->playable);
        $this->assertSame(40, $modes->get('commander')->life);
        $this->assertSame('main deck at least 60 cards · side deck up to 15 · up to 4 copies of a card · 20 life · sets from the last 3 years', $modes->get('standard')->summary());
        $this->assertSame('main deck exactly 100 cards · no side deck · one copy of each card · 40 life', $modes->get('commander')->summary());

        $this->expectException(\InvalidArgumentException::class);
        GameModes::fromArray(['standard' => ['main_min' => 60]]);
    }

    public function testDeckRules(): void
    {
        $mode = GameMode::fromArray('standard', 'Standard', [
            'main_min' => 60, 'side_max' => 2, 'copies' => 4, 'sets' => ['new', 'OLD'], 'released_within_days' => 365, 'banned' => ['Shock'],
        ]);
        $deck = new Deck('d', self::ALICE, 'Test', 'standard', new CardCounts(['bears' => 5, 'shock' => 1, 'basic:Forest' => 30]), new CardCounts(['shock' => 3]));
        $card = fn (string $key) => $this->pocket->deckBuilder->cardData($key);
        $date = fn (string $set) => $this->pocket->pools->find($set)?->releaseDate;

        $this->assertSame([
            'The main deck has 36 cards; Standard needs at least 60.',
            'The side deck has 3 cards; Standard allows at most 2.',
            '**Shock** is banned in Standard.',
            '5 copies of **Grizzly Bears**; Standard allows 4.',
            'Cards from OLD are not in the Standard library: **Grizzly Bears**.',
        ], $mode->problems($deck, $card, $date, $this->now));

        $this->assertTrue($mode->allowsSet('NEW', $date, $this->now));
        $this->assertFalse($mode->allowsSet('XYZ', $date, $this->now), 'Not in the set list.');
        $this->assertSame([], GameMode::fromArray('casual', 'Casual', ['main_min' => 36])->problems($deck, $card, $date, $this->now));
    }

    public function testDeckViewSaysWhetherItMeetsItsFormat(): void
    {
        $deck = $this->pocket->deckBuilder->find(self::ALICE, 'Casual');
        $json = json_encode(PocketMessageBuilder::deck($deck, $this->pocket->deckBuilder->cardData(...), true, null, $this->pocket->matches->problems($deck)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Meets the Casual rules', $json);

        $deck = $this->pocket->deckBuilder->setFormat(self::ALICE, 'Casual', 'standard');
        $json = json_encode(PocketMessageBuilder::deck($deck, $this->pocket->deckBuilder->cardData(...), true, null, $this->pocket->matches->problems($deck)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Does not meet the Standard rules yet', $json);
        $this->assertStringContainsString('Standard needs at least 60', $json);
    }

    public function testQueuePairsTwoPlayersInARankedGame(): void
    {
        $matches = $this->pocket->matches;
        $first = $matches->queue(self::ALICE, 'Alice');
        $this->assertNull($first['match']);
        $this->assertSame('casual', $first['mode']->id, "The deck's format is the default mode.");
        $this->assertSame('casual', $matches->queued(self::ALICE)['mode']->id);
        $this->assertSame(1, $matches->queueSizes()['casual']);
        $this->assertNull($matches->current(self::ALICE), 'Waiting is not a match.');
        $this->assertStringContainsString('Looking for a Casual opponent', json_encode(MatchMessageBuilder::queued($first['mode'], 'Casual', 1000, 30), JSON_UNESCAPED_UNICODE));

        // Queuing again just keeps one spot.
        $matches->queue(self::ALICE, 'Alice');
        $this->assertSame(1, $matches->queueSizes()['casual']);

        $paired = $matches->queue(self::BOB, 'Bob');
        $match = $paired['match'];
        $this->assertNotNull($match);
        $this->assertTrue($match->ranked);
        $this->assertSame('casual', $match->mode);
        $this->assertSame(MatchRecord::PLAYING, $match->status);
        $this->assertSame([self::ALICE, self::BOB], array_column($match->players, 'id'), 'Whoever waited is listed first.');
        $this->assertSame($match->id, $matches->current(self::ALICE)?->id);
        $this->assertSame($match->id, $matches->current(self::BOB)?->id);
        $this->assertNull($matches->queued(self::ALICE));
        $this->assertSame(0, $matches->queueSizes()['casual']);
        $this->assertStringContainsString('Casual (ranked)', json_encode(MatchMessageBuilder::board($match), JSON_UNESCAPED_UNICODE));
        $this->assertException(fn () => $matches->queue(self::ALICE, 'Alice'), 'already in a match');

        // Bob concedes: the ladder moves.
        $matches->leave(self::BOB);
        $this->assertSame(['id' => self::ALICE, 'name' => 'Alice', 'rating' => 1016, 'wins' => 1, 'losses' => 0, 'draws' => 0], $matches->ladder->entry('casual', self::ALICE));
        $this->assertSame(984, $matches->ladder->entry('casual', self::BOB)['rating']);
        $this->assertSame([self::ALICE, self::BOB], array_column($matches->ladder->standings('casual'), 'id'));

        $json = json_encode(MatchMessageBuilder::ladder($matches->modes->get('casual'), $matches->ladder->standings('casual'), self::BOB), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('1. **Alice** · 1016 · 1–0', $json);
        $this->assertStringContainsString('You are #2 of 2.', $json);
    }

    public function testChallengesAreNotRanked(): void
    {
        $matches = $this->pocket->matches;
        $match = $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob', null, 'limited');
        $this->assertSame('limited', $match->mode);
        $this->assertStringContainsString('a Limited game', json_encode(MatchMessageBuilder::challenge($match), JSON_UNESCAPED_UNICODE));
        $match = $matches->accept($match->id, self::BOB, 'Bob');
        $this->assertFalse($match->ranked);
        $matches->leave(self::BOB);
        $this->assertSame([], $matches->ladder->standings('limited'));
    }

    public function testClosestRatingIsPairedFirstAndSpotsLapse(): void
    {
        $matches = $this->pocket->matches;
        // Alice is rated well above the others: Carol is out of her range, so both wait.
        $this->pocket->store->put(Ladder::COLLECTION, 'casual', ['players' => [['id' => self::ALICE, 'name' => 'Alice', 'rating' => 1400, 'wins' => 20, 'losses' => 0, 'draws' => 0]]]);
        $matches->queue(self::ALICE, 'Alice');
        $this->now += 60;
        $this->assertNull($matches->queue(self::CAROL, 'Carol')['match']);
        $this->assertSame(2, $matches->queueSizes()['casual']);

        // Bob is paired with Carol, the closest rating.
        $this->now += 60;
        $match = $matches->queue(self::BOB, 'Bob')['match'];
        $this->assertSame([self::CAROL, self::BOB], array_column($match->players, 'id'));
        $this->assertSame('casual', $matches->queued(self::ALICE)['mode']->id);
        $matches->leave(self::BOB);

        // The longer Alice waits, the wider her range: after 4 minutes, 400 points.
        $this->now += 60;
        $this->assertNull($matches->queue(self::BOB, 'Bob')['match'], 'Bob is now 1400 - 984 = 416 away after 3 minutes.');
        $matches->unqueue(self::BOB);
        $this->now += 120;
        $this->assertNotNull($matches->queue(self::CAROL, 'Carol')['match'], 'Carol (1016) is 384 away after 5 minutes.');
        $matches->leave(self::CAROL);

        // A spot lapses.
        $matches->queue(self::BOB, 'Bob');
        $this->now += MatchService::QUEUE_WAIT + 1;
        $this->assertNull($matches->queued(self::BOB));
    }

    public function testLeavingTheQueue(): void
    {
        $matches = $this->pocket->matches;
        $matches->queue(self::ALICE, 'Alice');
        $this->assertSame('casual', $matches->unqueue(self::ALICE)?->id);
        $this->assertNull($matches->queued(self::ALICE));
        $this->assertNull($matches->unqueue(self::ALICE));

        // Starting a challenge also leaves the queue.
        $matches->queue(self::ALICE, 'Alice');
        $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');
        $this->assertNull($matches->queued(self::ALICE));
    }

    public function testDecksMustMeetTheMode(): void
    {
        $matches = $this->pocket->matches;
        $this->assertException(fn () => $matches->queue(self::ALICE, 'Alice', 'standard'), 'Standard needs at least 60');
        $this->assertException(fn () => $matches->queue(self::ALICE, 'Alice', 'commander'), 'Commander games cannot be played yet');
        $this->assertException(fn () => $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob', null, 'standard'), 'Cards from OLD are not in the Standard library');
        $this->assertException(fn () => $matches->queue(self::ALICE, 'Alice', 'vintage'), 'The mode must be one of');

        // The opponent's deck must meet the challenge's mode too.
        $match = $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');
        $this->pocket->deckBuilder->remove(self::BOB, 'Casual', 'Forest', 1);
        $this->assertException(fn () => $matches->accept($match->id, self::BOB, 'Bob'), 'Casual needs at least 40');
    }

    public function testStartingLifeComesFromTheMode(): void
    {
        $config = require GameModes::DEFAULT_FILE;
        $config['casual']['life'] = 30;
        $this->makePocket(GameModes::fromArray($config));
        $matches = $this->pocket->matches;
        $matches->queue(self::ALICE, 'Alice');
        $match = $matches->queue(self::BOB, 'Bob')['match'];
        $this->assertSame([30, 30], array_map(fn ($player) => $player->life, $match->game->players));
    }
}
