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
use MTGPocket\Economy\MatchRewards;
use MTGPocket\Game\Game;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Models\Player;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Pocket;
use MTGPocket\Quests\Quest;
use MTGPocket\Quests\QuestBook;
use MTGPocket\Quests\Quests;
use MTGPocket\Rentals\RentalDeck;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Rental decks, points for ranked games, and daily and weekly quests.
 *
 * @covers \MTGPocket\Rentals\RentalDeck
 * @covers \MTGPocket\Rentals\Rentals
 * @covers \MTGPocket\Repository\RentalRepository
 * @covers \MTGPocket\Economy\MatchRewards
 * @covers \MTGPocket\Quests\Quest
 * @covers \MTGPocket\Quests\QuestBook
 * @covers \MTGPocket\Quests\Quests
 * @covers \MTGPocket\Matches\MatchService
 * @covers \MTGPocket\Models\Player
 */
final class RentalsAndQuestsTest extends PocketTestCase
{
    private const string ALICE = '111111111111111111';
    private const string BOB = '222222222222222222';
    private const string DAVE = '444444444444444444';

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makePocket();
    }

    /**
     * @param array $rewards Overrides of the `matches` part of `config/economy.php`.
     */
    private function makePocket(array $rewards = []): void
    {
        $this->pocket = new Pocket(
            $this->directory,
            new PackGenerator(new Randomizer(new Xoshiro256StarStar(42))),
            fn () => $this->now,
            fn () => str_pad(dechex(++$this->seed), 32, 'a', STR_PAD_LEFT),
            rewards: MatchRewards::fromArray($rewards),
            questBook: QuestBook::fromArray([
                'daily_count' => 2,
                'weekly_count' => 1,
                'daily' => [
                    ['id' => 'play-1', 'label' => 'Play a ranked game', 'event' => 'ranked', 'goal' => 1, 'points' => 5],
                    ['id' => 'win-1', 'label' => 'Win a ranked game', 'event' => 'win', 'goal' => 1, 'points' => 40],
                ],
                'weekly' => [
                    ['id' => 'week-play', 'label' => 'Play 2 ranked games', 'event' => 'ranked', 'goal' => 2, 'points' => 100],
                ],
            ]),
        );

        $pool = new CardPool('OLD', 'Old Set', '2015-01-01');
        $pool->add(['uuid' => 'bears', 'name' => 'Grizzly Bears', 'rarity' => 'common', 'colors' => ['G'], 'manaValue' => 2.0, 'type' => 'Creature — Bear', 'manaCost' => '{1}{G}', 'power' => '2', 'toughness' => '2']);
        $this->pocket->pools->save($pool);
        foreach ([[self::ALICE, 'Alice'], [self::BOB, 'Bob']] as [$id, $name]) {
            if ($this->pocket->deckBuilder->list($id) === []) {
                $this->pocket->inventories->addCards($id, ['bears' => 8]);
                $this->pocket->deckBuilder->create($id, $name, 'Casual', 'casual');
                $this->pocket->deckBuilder->add($id, 'Casual', 'bears', 8);
                $this->pocket->deckBuilder->add($id, 'Casual', 'Forest', 32);
            }
        }

        // A deck of a current set and one of a set that has left Standard.
        $shock = ['uuid' => 'shock', 'name' => 'Shock', 'rarity' => 'common', 'colors' => ['R'], 'manaValue' => 1.0, 'type' => 'Instant', 'manaCost' => '{R}', 'text' => 'Shock deals 2 damage to any target.'];
        $mountain = ['uuid' => 'mountain-new', 'name' => 'Mountain', 'rarity' => 'common', 'colors' => [], 'manaValue' => 0.0, 'type' => 'Basic Land — Mountain', 'manaCost' => null];
        $this->pocket->rentalDecks->save(new RentalDeck('new-burn', 'Burn', 'NEW', 'New Set', 'Starter Kit', '2026-06-01', [['count' => 4, 'card' => $shock], ['count' => 56, 'card' => $mountain]], [['count' => 2, 'card' => ['uuid' => 'spark', 'name' => 'Spark'] + $shock]]));
        $this->pocket->rentalDecks->save(new RentalDeck('old-burn', 'Old Burn', 'OLD', 'Old Set', 'Theme Deck', '2015-01-01', [['count' => 4, 'card' => $shock], ['count' => 56, 'card' => $mountain]]));
    }

    /**
     * Plays a ranked Casual game between Alice and Bob that Bob concedes on
     * a given turn.
     *
     * @param int $turn
     *
     * @return MatchRecord
     */
    private function rankedGame(int $turn = 3): MatchRecord
    {
        $matches = $this->pocket->matches;
        $matches->queue(self::ALICE, 'Alice');
        $match = $matches->queue(self::BOB, 'Bob')['match'];
        $matches->act($match->id, self::ALICE, fn (Game $game) => $game->turn = $turn);

        return $matches->leave(self::BOB);
    }

    private function points(string $playerId): int
    {
        return $this->pocket->players->find($playerId)?->points ?? 0;
    }

    public function testRentalsComeFromTheCurrentSets(): void
    {
        $rentals = $this->pocket->rentals;
        $this->assertSame('standard', $rentals->mode()->id);
        $this->assertSame(['Burn'], array_map(fn (RentalDeck $deck) => $deck->name, $rentals->available()));
        $this->assertSame(['rental:new-burn' => 'Burn (rental, NEW)'], $rentals->suggest('bu'));
        $this->assertSame('new-burn', $rentals->find('burn')->id);
        $this->assertSame('new-burn', $rentals->find('rental:new-burn')->id);
        $this->assertException(fn () => $rentals->find('Old Burn'), 'no longer for rent');
        $this->assertException(fn () => $rentals->find('Nope'), 'no rental deck called');

        $json = json_encode(PocketMessageBuilder::rentalList($rentals->available(), 3, 3, 'Standard', null), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('**Burn** · New Set · Starter Kit · 60 cards', $json);
        $this->assertStringContainsString('3 games left today of 3', $json);
        $this->assertStringNotContainsString('added', $json, 'A 60-card precon is left as it is.');
    }

    public function testShortRentalsGetBasicLandsUpTo60Cards(): void
    {
        // Three red instants and three red-white creatures, four of each.
        $spells = [];
        foreach ([1, 2, 3] as $i) {
            $spells[] = ['count' => 4, 'card' => ['uuid' => "bolt-{$i}", 'name' => "Bolt {$i}", 'rarity' => 'common', 'colors' => ['R'], 'manaValue' => 1.0, 'type' => 'Instant', 'manaCost' => '{R}', 'text' => "Bolt {$i} deals 3 damage to any target."]];
            $spells[] = ['count' => 4, 'card' => ['uuid' => "mender-{$i}", 'name' => "Mender {$i}", 'rarity' => 'common', 'colors' => ['W', 'R'], 'manaValue' => 3.0, 'type' => 'Creature — Human', 'manaCost' => '{1}{R/W}{W}', 'power' => '2', 'toughness' => '2']];
        }
        $plains = ['uuid' => 'plains-new', 'name' => 'Plains', 'rarity' => 'common', 'colors' => [], 'manaValue' => 0.0, 'type' => 'Basic Land — Plains', 'manaCost' => null];
        $mountain = ['uuid' => 'mountain-new', 'name' => 'Mountain', 'rarity' => 'common', 'colors' => [], 'manaValue' => 0.0, 'type' => 'Basic Land — Mountain', 'manaCost' => null];

        // A 41-card theme deck with 9 Plains and 8 Mountains: the 19 lands follow that split.
        $this->pocket->rentalDecks->save(new RentalDeck('new-boros', 'Boros', 'NEW', 'New Set', 'Theme Deck', '2026-06-01', [...$spells, ['count' => 9, 'card' => $plains], ['count' => 8, 'card' => $mountain]]));
        // No basics at all: by mana symbols, red 24 (Bolts, Menders' hybrid) to white 24 (Menders' hybrid and white).
        $this->pocket->rentalDecks->save(new RentalDeck('new-spells', 'Spells', 'NEW', 'New Set', 'Theme Deck', '2026-06-01', $spells));

        $rentals = $this->pocket->rentals;
        $boros = $rentals->find('Boros');
        $this->assertSame(60, $boros->mainCount());
        $this->assertSame(['Plains' => 10, 'Mountain' => 9], $boros->addedLands());
        $this->assertSame([], $rentals->mode()->problems($boros->deck(self::DAVE, 'standard'), $boros->card(...), fn () => '2026-06-01', time()));

        $spells = $rentals->find('Spells');
        $this->assertSame(60, $spells->mainCount());
        $this->assertSame(['Mountain' => 18, 'Plains' => 18], $spells->addedLands());

        $json = json_encode(PocketMessageBuilder::rentalList($rentals->available(), 3, 3, 'Standard', null), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('**Boros** · New Set · Theme Deck · 60 cards (with 19 basic lands added)', $json);

        // And it plays: 60 cards less the opening hand.
        $rentals->rent(self::DAVE, 'Dave', 'Boros');
        $rentals->rent(self::BOB, 'Bob', 'Boros');
        $match = $this->pocket->matches->challenge(self::DAVE, 'Dave', self::BOB, 'Bob');
        $match = $this->pocket->matches->accept($match->id, self::BOB, 'Bob');
        $this->assertSame(53, count($match->game->players[0]->library));
        $this->assertContains('Plains', array_map(fn (int $id) => $match->game->object($id)->name(), [...$match->game->players[0]->library, ...$match->game->players[0]->hand]));
    }

    public function testRentingAndPlayingARentalDeck(): void
    {
        $rentals = $this->pocket->rentals;
        $rental = $rentals->rent(self::DAVE, 'Dave', 'Burn');
        $this->assertSame('rental:new-burn', $rentals->activeRental(self::DAVE));

        $deck = $rental->deck(self::DAVE, 'standard');
        $this->assertSame([], $this->pocket->matches->problems($deck), 'Printed basic lands are not held to the copy limit.');
        $json = json_encode(PocketMessageBuilder::deck($deck, $rental->card(...), true, null, [], false), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Burn (rental)', $json);
        $this->assertStringNotContainsString('Export for', $json, 'Rentals cannot be exported.');

        // Dave, who owns no cards, plays a ranked Standard game.
        $this->pocket->rentals->rent(self::BOB, 'Bob', 'rental:new-burn');
        $this->assertNull($this->pocket->matches->queue(self::DAVE, 'Dave')['match']);
        $match = $this->pocket->matches->queue(self::BOB, 'Bob')['match'];
        $this->assertSame('standard', $match->mode);
        $this->assertSame(['rental:new-burn', 'rental:new-burn'], array_column($match->players, 'deckId'));
        $this->assertSame(53, count($match->game->players[0]->library), 'The 60-card rental, less the opening hand.');
        $this->assertSame(2, $rentals->gamesLeft(self::DAVE));

        // Picking a deck of their own ends the rental.
        $this->pocket->deckBuilder->activate(self::BOB, 'Bob', 'Casual');
        $this->assertNull($rentals->activeRental(self::BOB));
        $this->assertSame('rental:new-burn', $rentals->activeRental(self::DAVE));
    }

    public function testRentalGamesAreLimitedEachDay(): void
    {
        $this->makePocket(['rental_games_per_day' => 1]);
        $matches = $this->pocket->matches;
        $this->pocket->rentals->rent(self::DAVE, 'Dave', 'Burn');
        $match = $matches->challenge(self::DAVE, 'Dave', self::ALICE, 'Alice');
        $this->assertSame('standard', $match->mode, "The rental's mode.");
        $matches->accept($match->id, self::ALICE, 'Alice', 'rental:new-burn');
        $this->assertSame(0, $this->pocket->rentals->gamesLeft(self::DAVE));
        $this->assertSame(0, $this->pocket->rentals->gamesLeft(self::ALICE));
        $matches->leave(self::DAVE);

        $this->assertException(fn () => $matches->challenge(self::DAVE, 'Dave', self::BOB, 'Bob'), 'rental games for today');

        $this->now += 86400;
        $this->assertSame(1, $this->pocket->rentals->gamesLeft(self::DAVE));
        $this->assertSame(MatchRecord::PENDING, $matches->challenge(self::DAVE, 'Dave', self::BOB, 'Bob')->status);
    }

    public function testRankedGamesPayPointsAndCountForQuests(): void
    {
        $match = $this->rankedGame();
        $this->assertSame(50 + 5 + 40, $this->points(self::ALICE), 'A win, and both daily quests.');
        $this->assertSame(10 + 5, $this->points(self::BOB), 'Playing it out, and the play quest.');
        $this->assertSame([
            ['id' => self::ALICE, 'points' => 50, 'quests' => [['label' => 'Play a ranked game', 'points' => 5], ['label' => 'Win a ranked game', 'points' => 40]]],
            ['id' => self::BOB, 'points' => 10, 'quests' => [['label' => 'Play a ranked game', 'points' => 5]]],
        ], $this->pocket->matches->find($match->id)->rewards);

        $json = json_encode(MatchMessageBuilder::board($this->pocket->matches->find($match->id)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Alice earned 50 points.', $json);
        $this->assertStringContainsString('Bob completed the quest **Play a ranked game**: +5 points.', $json);

        // The second game finishes the weekly quest; the daily ones pay once.
        $this->rankedGame();
        $this->assertSame(95 + 50 + 100, $this->points(self::ALICE));
        $this->assertSame(15 + 10 + 100, $this->points(self::BOB));
    }

    public function testShortGamesAndChallengesPayNothing(): void
    {
        $match = $this->rankedGame(2);
        $this->assertSame([], $match->rewards, 'Conceded before turn 3.');
        $this->assertSame(0, $this->points(self::ALICE));

        $matches = $this->pocket->matches;
        $challenge = $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');
        $matches->accept($challenge->id, self::BOB, 'Bob');
        $matches->act($challenge->id, self::ALICE, fn (Game $game) => $game->turn = 5);
        $this->assertSame([], $matches->leave(self::BOB)->rewards);
        $this->assertSame(0, $this->points(self::ALICE));
        $this->assertSame(0, $this->pocket->quests->board(self::ALICE)['daily'][0]['progress']);
    }

    public function testRewardedGamesAreCappedEachDay(): void
    {
        $this->makePocket(['rewarded_games_per_day' => 1]);
        $this->rankedGame();
        $this->assertSame(95, $this->points(self::ALICE));
        $match = $this->rankedGame();
        $this->assertSame(95 + 100, $this->points(self::ALICE), 'No more game points today, but quests still count.');
        $this->assertSame(0, $match->rewards[0]['points']);

        $this->now += 86400;
        $this->rankedGame();
        $this->assertSame(195 + 50 + 45, $this->points(self::ALICE));
    }

    public function testQuestsStartOverEachDayAndWeek(): void
    {
        $quests = $this->pocket->quests;
        $this->assertSame([['label' => 'Play a ranked game', 'points' => 5]], array_values(array_filter($quests->record(self::ALICE, 'ranked'), fn ($q) => $q['points'] === 5)));
        $board = $quests->board(self::ALICE);
        $progress = fn (array $entries) => array_combine(array_map(fn (array $entry) => $entry['quest']->id, $entries), array_column($entries, 'progress'));
        $this->assertEquals(['play-1' => 1, 'win-1' => 0], $progress($board['daily']));
        $this->assertSame([1], array_column($board['weekly'], 'progress'));
        $this->assertSame(1_790_035_200, $board['dailyEnds'], 'Midnight UTC.');
        $this->assertSame(1_790_553_600, $board['weeklyEnds'], 'Monday 2026-09-28, midnight UTC.');
        $this->assertSame('2026-W39', Quests::period(Quests::WEEKLY, $this->now));

        $json = json_encode(PocketMessageBuilder::quests($board, $this->points(self::ALICE)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('✅ ~~Play a ranked game~~ · 1/1 · 5 points', $json);
        $this->assertStringContainsString('**Play 2 ranked games** · 1/2 · 100 points', $json);

        // A new day keeps the week's progress.
        $this->now += 86400;
        $board = $quests->board(self::ALICE);
        $this->assertEquals(['play-1' => 0, 'win-1' => 0], $progress($board['daily']));
        $this->assertSame([1], array_column($board['weekly'], 'progress'));
        $this->now += 6 * 86400;
        $this->assertSame([0], array_column($quests->board(self::ALICE)['weekly'], 'progress'));

        // Progress is saved with the player.
        $saved = Player::fromArray(json_decode(json_encode($this->pocket->players->find(self::ALICE)), true));
        $this->assertSame(['period' => '2026-W39', 'progress' => ['week-play' => 1]], $saved->quests['weekly']);

        $this->assertException(fn () => $quests->record(self::ALICE, 'dance'), 'do not count');
    }

    public function testQuestsPickDifferentQuestsForDifferentPlayers(): void
    {
        $book = QuestBook::fromFile();
        $this->assertSame(3, $book->dailyCount);
        $quests = new Quests($this->pocket->players, $book, fn () => $this->now);
        $picks = fn (string $id, int $now) => array_map(fn (Quest $quest) => $quest->id, $quests->picks($id, Quests::DAILY, $now));

        $this->assertCount(3, $picks(self::ALICE, $this->now));
        $this->assertSame($picks(self::ALICE, $this->now), $picks(self::ALICE, $this->now + 60), 'The same all day.');
        $seen = [];
        foreach (range(0, 9) as $day) {
            $seen[implode(',', $picks(self::ALICE, $this->now + $day * 86400))] = true;
            $seen[implode(',', $picks(self::BOB, $this->now + $day * 86400))] = true;
        }
        $this->assertGreaterThan(3, count($seen));

        $this->assertException(fn () => QuestBook::fromArray(['daily' => [['id' => 'x', 'label' => 'X', 'event' => 'fly', 'goal' => 1]]]), 'must be one of');
        $this->assertException(fn () => QuestBook::fromArray(['daily' => [['id' => 'x', 'label' => 'X', 'event' => 'win'], ['id' => 'x', 'label' => 'Y', 'event' => 'win']]]), 'its own id');
    }

    public function testOpeningPacksCountsForQuests(): void
    {
        $this->pocket = new Pocket(
            $this->directory,
            new PackGenerator(new Randomizer(new Xoshiro256StarStar(42))),
            fn () => $this->now,
            questBook: QuestBook::fromArray(['daily_count' => 1, 'weekly_count' => 0, 'daily' => [['id' => 'pack-2', 'label' => 'Open 2 packs', 'event' => 'pack', 'goal' => 2, 'points' => 20]]]),
        );
        $this->importPool('PKS', ['R' => self::fullColor()]);

        $opened = $this->pocket->dailyPacks->open(self::ALICE, 'Alice');
        $this->assertSame([], $opened->quests);
        $this->pocket->players->modify(self::ALICE, fn (Player $player) => $player->points = 10_000);
        $bought = $this->pocket->shop->buyPack(self::ALICE, 'Alice');
        $this->assertSame([['label' => 'Open 2 packs', 'points' => 20]], $bought->quests);
        $this->assertSame($this->points(self::ALICE), $bought->balance, 'The balance shown counts the quest.');
        $this->assertStringContainsString('Quest done: **Open 2 packs**, +20 points.', json_encode(PocketMessageBuilder::pack($bought), JSON_UNESCAPED_UNICODE));
    }
}
