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

use MTGPocket\Cards\BasicLands;
use MTGPocket\Cards\CardPool;
use MTGPocket\Drafts\Draft;
use MTGPocket\Drafts\DraftRules;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Models\Player;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Pocket;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Booster drafts: the buy-in, passing packs, building decks, Swiss rounds,
 * and players keeping what they drafted.
 *
 * @covers \MTGPocket\Drafts\DraftService
 * @covers \MTGPocket\Drafts\Draft
 * @covers \MTGPocket\Drafts\DraftSeat
 * @covers \MTGPocket\Drafts\DraftRules
 * @covers \MTGPocket\Repository\DraftRepository
 */
final class DraftsTest extends PocketTestCase
{
    private const array PLAYERS = ['100' => 'Ann', '200' => 'Ben', '300' => 'Cat', '400' => 'Dan'];

    private const int FEE = 1000;

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pocket = new Pocket(
            $this->directory,
            new PackGenerator(new Randomizer(new Xoshiro256StarStar(7))),
            fn () => $this->now,
            fn () => sprintf('%012x%020x', ++$this->seed, $this->seed),
            draftRules: new DraftRules(entryFee: self::FEE, podSize: 4, minPlayers: 2, packs: 3, pickSeconds: 60, buildMinutes: 30, deckMin: 40, roundHours: 2, signupHours: 12, eventDays: 3),
        );

        $pool = new CardPool('DRF', 'Draft Set', '2024-01-01');
        foreach (['W', 'U', 'B', 'R', 'G'] as $color) {
            foreach (['common' => 12, 'uncommon' => 6, 'rare' => 3, 'mythic' => 1] as $rarity => $size) {
                for ($n = 1; $n <= $size; $n++) {
                    $pool->add([
                        'uuid' => "DRF-{$color}-{$rarity}-{$n}",
                        'name' => "{$color} {$rarity} bear {$n}",
                        'rarity' => $rarity,
                        'colors' => [$color],
                        'manaValue' => 2.0,
                        'type' => 'Creature — Bear',
                        'manaCost' => "{1}{{$color}}",
                        'power' => '2',
                        'toughness' => '2',
                    ]);
                }
            }
        }
        $this->pocket->pools->save($pool);

        foreach (self::PLAYERS as $id => $name) {
            $this->pocket->players->findOrCreate((string) $id, $name);
            $this->pocket->players->modify((string) $id, fn (Player $player) => $player->points = 1500);
        }
    }

    private function points(string $id): int
    {
        return $this->pocket->players->find($id)->points;
    }

    /**
     * Makes a pod and has the given players join it.
     *
     * @param string[] $ids
     *
     * @return Draft
     */
    private function pod(array $ids = ['100', '200', '300', '400']): Draft
    {
        $drafts = $this->pocket->drafts;
        $draft = $drafts->create($ids[0], self::PLAYERS[$ids[0]], 'drf', '999');
        foreach (array_slice($ids, 1) as $id) {
            $draft = $drafts->join($id, self::PLAYERS[$id], $draft->id);
        }

        return $draft;
    }

    /**
     * Everyone takes the first card of each pack in front of them until
     * the packs are gone.
     *
     * @param Draft $draft
     *
     * @return Draft
     */
    private function draftAll(Draft $draft): Draft
    {
        $drafts = $this->pocket->drafts;
        for ($guard = 0; $draft->status === Draft::DRAFTING && $guard < 1000; $guard++) {
            foreach ($draft->seats as $seat) {
                if (! $seat->dropped && ($cards = $drafts->packCards($draft, $seat->id)) !== null) {
                    $draft = $drafts->pick($seat->id, $cards[0]['uuid'], count($seat->pickLog));
                    break;
                }
            }
        }

        return $draft;
    }

    public function testBuyInAndRefunds(): void
    {
        $drafts = $this->pocket->drafts;
        $draft = $drafts->create('100', 'Ann', 'DRF', '999');
        $this->assertSame(Draft::SIGNUP, $draft->status);
        $this->assertSame(500, $this->points('100'));
        $this->assertException(fn () => $drafts->create('100', 'Ann', 'DRF'), 'already in a draft');
        $this->assertException(fn () => $drafts->create('200', 'Ben', 'NOPE'), 'no set');

        $this->pocket->players->modify('300', fn (Player $player) => $player->points = 999);
        $this->assertException(fn () => $drafts->join('300', 'Cat'), 'costs 1,000 points to enter and you have 999');
        $this->assertSame(999, $this->points('300'), 'Nothing is taken when the fee cannot be paid.');

        // With one pod open, joining needs no id.
        $draft = $drafts->join('200', 'Ben');
        $this->assertCount(2, $draft->seats);
        $this->assertSame(500, $this->points('200'));
        $this->assertException(fn () => $drafts->start('200'), 'Only the player who made the pod');

        // Leaving before the draft refunds; the host's seat passes on.
        $draft = $drafts->leave('100');
        $this->assertSame(1500, $this->points('100'));
        $this->assertSame('200', $draft->hostId);
        $this->assertNull($drafts->current('100'));
        $this->assertException(fn () => $drafts->start('200'), 'at least 2 players');

        // Nobody else comes: the pod is called off and refunded.
        $this->now += 12 * 3600;
        $this->assertSame(Draft::CANCELLED, $drafts->find($draft->id)->status);
        $this->assertSame(1500, $this->points('200'));
        $this->assertNull($drafts->current('200'));
        $this->assertStringContainsString('refunded', $drafts->takeNews()[0]['news'][0]);
        $this->assertSame([], $drafts->takeNews(), 'News is posted once.');
    }

    public function testAFullPodDraftsPassingLeftThenRight(): void
    {
        $draft = $this->pod();
        $this->assertSame(Draft::DRAFTING, $draft->status, 'A full pod starts at once.');
        $this->assertSame(1, $draft->packNumber);
        $this->assertSame($this->now + 3 * 86400, $draft->endsAt);
        $colors = [];
        foreach (array_keys($draft->seats) as $seat) {
            $pack = $draft->packFor($seat);
            $this->assertCount(PackGenerator::SIZE, $pack);
            $colors[] = explode('-', $pack[0])[1];
        }
        $this->assertCount(4, array_unique($colors), 'Neighbors open packs of different colors.');

        // Ann takes a card; the rest of her pack goes to Ben, behind his own.
        $drafts = $this->pocket->drafts;
        $first = $draft->packFor(0);
        $draft = $drafts->pick('100', $first[3], 0);
        $this->assertSame([$first[3]], $draft->seats[0]->pickLog);
        $this->assertNull($draft->packFor(0), 'Ann waits for Dan to pass.');
        $this->assertCount(2, $draft->queues[1]);
        $this->assertSame(array_values(array_diff($first, [$first[3]])), $draft->queues[1][1]);
        $this->assertException(fn () => $drafts->pick('100', $first[0]), 'No pack is waiting for you');
        $this->assertException(fn () => $drafts->pick('200', $first[3], 0), 'not in your pack');
        $this->assertException(fn () => $drafts->pick('200', $draft->packFor(1)[0], 5), 'already moved on');
        $this->assertException(fn () => $drafts->addToDeck('100', 'Plains'), 'once every pack is empty');

        // Through the first pack; the second goes the other way.
        while ($draft->packNumber === 1) {
            $draft = $this->pickOnce($draft);
        }
        $this->assertSame(2, $draft->packNumber);
        $second = $draft->packFor(0);
        $draft = $drafts->pick('100', $second[0], 15);
        $this->assertSame(array_slice($second, 1), $draft->queues[3][1], 'The second pack passes right, to Dan.');

        $draft = $this->draftAll($draft);
        $this->assertSame(Draft::BUILDING, $draft->status);
        foreach ($draft->seats as $seat) {
            $this->assertSame(45, $seat->picks->total());
            $this->assertCount(45, $seat->pickLog);
        }
        $this->assertSame([], $this->pocket->inventories->get('100')->cards->toArray(), 'Picks are not theirs until the matches are done.');
    }

    private function pickOnce(Draft $draft): Draft
    {
        foreach ($draft->seats as $seat) {
            if (($cards = $this->pocket->drafts->packCards($draft, $seat->id)) !== null) {
                return $this->pocket->drafts->pick($seat->id, $cards[0]['uuid']);
            }
        }
        $this->fail('Nobody had a pack.');
    }

    public function testTheBotPicksForPlayersWhoWaitTooLong(): void
    {
        $draft = $this->pod(['100', '200']);
        $this->assertSame(Draft::SIGNUP, $draft->status);
        $draft = $this->pocket->drafts->start('100');
        $this->assertSame(Draft::DRAFTING, $draft->status);
        $this->assertSame($this->now + 60, $this->pocket->drafts->pickDeadline($draft, '200'));

        $draft = $this->pocket->drafts->pick('100', $draft->packFor(0)[0]);
        $this->now += 60;
        $draft = $this->pocket->drafts->tick($draft->id);
        // Ben's own pack timed out; Ann's pack only just reached him.
        $this->assertCount(1, $draft->seats[1]->pickLog);
        $this->assertNotNull($draft->packFor(1));
        $this->assertNotNull($draft->packFor(0), 'Ben passed his pack to Ann.');
        // The bot takes the rare first.
        $this->assertContains(explode('-', $draft->seats[1]->pickLog[0])[2], ['rare', 'mythic']);

        // Long enough and the whole draft picks itself.
        for ($i = 0; $i < 100 && $draft->status === Draft::DRAFTING; $i++) {
            $this->now += 60;
            $draft = $this->pocket->drafts->tick($draft->id);
        }
        $this->assertSame(Draft::BUILDING, $draft->status);
        $this->assertSame(45, $draft->seats[0]->picks->total(), 'Each player takes a card from every pack that passes: three packs\' worth.');
    }

    public function testBuildingPlayingAndKeepingTheCards(): void
    {
        $drafts = $this->pocket->drafts;
        $draft = $this->draftAll($this->pod());
        $this->assertSame(Draft::BUILDING, $draft->status);

        // Ann builds by hand.
        $card = $draft->seat('100')->pickLog[0];
        $name = $this->pocket->deckBuilder->cardData($card)['name'];
        [, $note] = $drafts->addToDeck('100', $name, 1);
        $this->assertStringContainsString('Added 1', $note);
        $this->assertException(fn () => $drafts->addToDeck('100', 'Not A Card'), 'no **Not A Card** left');
        $draft = $drafts->setLands('100', ['Plains' => 20, 'island' => 10]);
        $this->assertSame(31, $draft->seat('100')->deck->total());
        $this->assertException(fn () => $drafts->ready('100'), 'it needs at least 40');
        [$draft] = $drafts->removeFromDeck('100', 'Island', 2);
        $this->assertSame(8, $draft->seat('100')->deck->get(BasicLands::PREFIX.'Island'));
        $draft = $drafts->autoBuild('100');
        $deck = $draft->seat('100')->deck;
        $this->assertSame(40, $deck->total());
        $spells = array_filter($deck->toArray(), fn (int $count, string $key) => ! BasicLands::isBasic($key), ARRAY_FILTER_USE_BOTH);
        $this->assertGreaterThan(15, array_sum($spells));
        $this->assertLessThanOrEqual(23, array_sum($spells));
        $colors = array_unique(array_map(fn (string $uuid) => explode('-', $uuid)[1], array_keys($spells)));
        $this->assertCount(2, $colors, 'The bot builds in its two best colors.');
        foreach ($colors as $color) {
            $this->assertGreaterThan(0, $deck->get(BasicLands::PREFIX.array_search($color, BasicLands::NAMES, true)));
        }
        $draft = $drafts->ready('100');
        $this->assertSame(Draft::BUILDING, $draft->status, 'Rounds wait for everyone, or the clock.');

        // Time runs out: the others play what the bot builds.
        $this->now += 30 * 60;
        $draft = $drafts->find($draft->id);
        $this->assertSame(Draft::PLAYING, $draft->status);
        $this->assertSame(2, $draft->totalRounds);
        $round = $draft->currentRound();
        $this->assertCount(2, $round);
        // The first round pairs across the table.
        $this->assertSame(['100', '300'], [$round[0]['a'], $round[0]['b']]);
        $this->assertSame(['200', '400'], [$round[1]['a'], $round[1]['b']]);
        foreach ($round as $pairing) {
            $match = $this->pocket->matches->find($pairing['match']);
            $this->assertSame(MatchRecord::PLAYING, $match->status);
            $this->assertSame($draft->id, $match->event);
            $this->assertFalse($match->ranked);
            $this->assertCount(40, [...$match->game->players[0]->hand, ...$match->game->players[0]->library]);
        }
        $this->assertNotNull($this->pocket->matches->current('100'), 'Draft games are live matches like any other.');

        // Cat and Dan concede; the winners meet in round 2.
        $this->pocket->matches->leave('300');
        $draft = $drafts->find($draft->id);
        $this->assertSame('a', $draft->currentRound()[0]['result'], 'The game reports back to the draft.');
        $this->pocket->matches->leave('400');
        $draft = $drafts->find($draft->id);
        $this->assertCount(2, $draft->rounds);
        $round = $draft->currentRound();
        $this->assertSame(['100', '200'], [$round[0]['a'], $round[0]['b']]);
        $this->assertSame(['300', '400'], [$round[1]['a'], $round[1]['b']]);
        $this->assertSame([], $this->pocket->inventories->get('300')->cards->toArray());

        // Ben beats Ann; Cat and Dan run out of time.
        $this->pocket->matches->leave('100');
        $this->now += 2 * 3600;
        $draft = $drafts->find($draft->id);
        $this->assertSame(Draft::OVER, $draft->status);
        $this->assertSame('draw', $draft->currentRound()[1]['result']);
        $stopped = $this->pocket->matches->find($draft->currentRound()[1]['match']);
        $this->assertSame(MatchRecord::OVER, $stopped->status);
        $this->assertNull($stopped->game->winner);
        $this->assertNull($this->pocket->matches->current('300'));

        $standings = $draft->standings();
        // Dan lost to the winner, so he places above Cat on tiebreaks.
        $this->assertSame(['200', '100', '400', '300'], array_column($standings, 'id'));
        $this->assertSame([6, 3, 1, 1], array_column($standings, 'points'));

        // The entry fees (4 × 1,000) go to the top four: 40%, 25%, 15% and 10%.
        $this->assertSame([1600, 1000, 600, 400], $drafts->prizeTable($draft));
        $this->assertSame([['id' => '200', 'place' => 1, 'points' => 1600], ['id' => '100', 'place' => 2, 'points' => 1000], ['id' => '400', 'place' => 3, 'points' => 600], ['id' => '300', 'place' => 4, 'points' => 400]], $draft->prizes);
        $this->assertSame([2100, 1500, 1100, 900], [$this->points('200'), $this->points('100'), $this->points('400'), $this->points('300')]);
        $drafts->tickAll();
        $drafts->find($draft->id);
        $this->assertSame(2100, $this->points('200'), 'Prizes are paid once.');

        // Everyone keeps every card they drafted, and each win opened a
        // 15-card pack of the set: two for Ben, one for Ann.
        $wins = ['100' => 1, '200' => 2, '300' => 0, '400' => 0];
        foreach ($draft->seats as $seat) {
            $this->assertTrue($seat->collected);
            $this->assertSame($wins[$seat->id], $seat->packsWon);
            $owned = $this->pocket->inventories->get($seat->id)->cards;
            $this->assertTrue($owned->contains($seat->picks));
            $this->assertSame($seat->picks->total() + 15 * $wins[$seat->id], $owned->total());
        }
        $this->assertNull($drafts->current('100'));
        $this->assertSame($draft->id, $drafts->last('100')->id);
        $news = implode("\n", array_merge(...array_column($drafts->takeNews(), 'news')));
        $this->assertStringContainsString('**Ben** finishes first (2-0). Prizes: 1. Ben 1,600 points, 2. Ann 1,000 points', $news);
        $this->assertStringContainsString('🎁 **Ben** won a Draft Set pack', $news);
        $this->assertStringContainsString('The cards are in their collection.', $news);
    }

    public function testLeavingMidEventConcedesAndPaysOut(): void
    {
        $drafts = $this->pocket->drafts;
        $draft = $this->draftAll($this->pod());
        foreach ($draft->seats as $seat) {
            $drafts->autoBuild($seat->id);
            $draft = $drafts->ready($seat->id);
        }
        $this->assertSame(Draft::PLAYING, $draft->status);

        $draft = $drafts->leave('300');
        $this->assertSame('a', $draft->currentRound()[0]['result'], 'Ann wins the game Cat left.');
        $this->assertTrue($draft->seat('300')->dropped);
        $this->assertSame($draft->seat('300')->picks->toArray(), $this->pocket->inventories->get('300')->cards->toArray(), 'Their matches are done, so they keep their cards now.');
        $this->assertNull($drafts->current('300'));
        $this->assertNull($this->pocket->matches->current('300'));
        $this->assertException(fn () => $drafts->leave('300'), 'not in a draft');

        // Round 2 has three players: the last one without a bye sits out.
        $this->pocket->matches->leave('400');
        $draft = $drafts->find($draft->id);
        $round = $draft->currentRound();
        $this->assertCount(2, $draft->rounds);
        $this->assertSame(['100', '200'], [$round[0]['a'], $round[0]['b']]);
        $this->assertSame(['400', null, 'a'], [$round[1]['a'], $round[1]['b'], $round[1]['result']]);
        $this->assertException(fn () => $drafts->play('400'), 'You have a bye');
        $this->assertSame(1, $draft->seat('400')->packsWon, 'A bye is a win, and pays its pack at once.');
        $this->assertSame(15, $this->pocket->inventories->get('400')->cards->total());
        $this->assertSame($round[0]['match'], $drafts->play('100')->id, 'Play shows the game already going.');

        // Cat left, so she wins nothing; Ann beats Ben for first.
        $this->pocket->matches->leave('200');
        $draft = $drafts->find($draft->id);
        $this->assertSame(Draft::OVER, $draft->status);
        $this->assertSame(['100', '200', '400'], array_column($draft->prizes, 'id'));
        $this->assertSame(500, $this->points('300'));
        $this->assertSame(500 + 1600, $this->points('100'));
    }

    public function testGamesWaitForPlayersBusyElsewhere(): void
    {
        $drafts = $this->pocket->drafts;
        $this->pod(['100', '200']);
        $draft = $this->draftAll($drafts->start('100'));
        // Ben starts a friendly game meanwhile.
        $this->pocket->deckBuilder->create('200', 'Ben', 'Lands', 'casual');
        $this->pocket->deckBuilder->add('200', 'Lands', 'Forest', 40);
        $this->pocket->deckBuilder->create('500', 'Eve', 'Lands', 'casual');
        $this->pocket->deckBuilder->add('500', 'Lands', 'Forest', 40);
        $challenge = $this->pocket->matches->challenge('200', 'Ben', '500', 'Eve');
        $this->pocket->matches->accept($challenge->id, '500', 'Eve');

        $this->now += 30 * 60;
        $draft = $drafts->find($draft->id);
        $this->assertSame(Draft::PLAYING, $draft->status);
        $this->assertNull($draft->currentRound()[0]['match'], 'Ben is busy, so the game waits.');
        $this->assertException(fn () => $drafts->play('100'), '**Ben** is in another match');

        $this->pocket->matches->leave('200');
        $match = $drafts->play('100');
        $this->assertSame(MatchRecord::PLAYING, $match->status);
        $this->assertSame($match->id, $drafts->find($draft->id)->currentRound()[0]['match']);
    }

    public function testTheEventTimesOutAndEveryoneKeepsWhatTheyHave(): void
    {
        $drafts = $this->pocket->drafts;
        $draft = $this->pod(['100', '200']);
        $draft = $drafts->start('100');
        $draft = $drafts->pick('100', $draft->packFor(0)[0]);
        $draft = $drafts->pick('200', $draft->packFor(1)[0]);
        $draft = $drafts->pick('100', $draft->packFor(0)[0]);

        $this->now = $draft->endsAt;
        $draft = $drafts->find($draft->id);
        $this->assertSame(Draft::OVER, $draft->status);
        $this->assertSame(2, $this->pocket->inventories->get('100')->cards->total());
        $this->assertSame(1, $this->pocket->inventories->get('200')->cards->total());
        $this->assertSame(500, $this->points('100'), 'With no rounds played there are no standings, so no prizes.');
        $this->assertSame([], $draft->prizes);
        $this->assertException(fn () => $drafts->leave('100'), 'not in a draft');

        // Free to join the next one.
        $this->pocket->players->modify('200', fn (Player $player) => $player->points = 1000);
        $this->assertSame(Draft::SIGNUP, $drafts->create('200', 'Ben', 'DRF')->status);
    }

    public function testDraftsAreSavedAsTheyGo(): void
    {
        $draft = $this->pod();
        $reloaded = (new Pocket($this->directory, clock: fn () => $this->now, draftRules: $this->pocket->drafts->rules))->drafts->find($draft->id);
        $this->assertSame(json_encode($draft->toArray()), json_encode($reloaded->toArray()));
        $this->assertSame(DraftRules::fromFile()->entryFee, (new DraftRules())->entryFee);
        $this->assertSame(DraftRules::fromFile()->prizes, (new DraftRules())->prizes);
        $this->assertException(fn () => new DraftRules(prizes: [0.8, 0.3]), 'more than the entry fees');
    }
}
