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

use Discord\Builders\MessageBuilder;
use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Builders\MenuMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Game\Game;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Panels\PanelResult;
use MTGPocket\Panels\Panels;
use MTGPocket\Pocket;
use MTGPocket\Rentals\RentalDeck;
use MTGPocket\Tests\Game\AutoPlayer;
use MTGPocket\Tutorial\PracticeBot;
use MTGPocket\Tutorial\StarterDecks;
use MTGPocket\Tutorial\Tutorial;

/**
 * How to play: its pages, and practice games against the bot.
 *
 * @covers \MTGPocket\Tutorial\Tutorial
 * @covers \MTGPocket\Tutorial\PracticeBot
 * @covers \MTGPocket\Tutorial\StarterDecks
 * @covers \MTGPocket\Matches\MatchService
 * @covers \MTGPocket\Panels\Panels
 */
final class TutorialTest extends PocketTestCase
{
    private const string ALICE = '111111111111111111';

    private Panels $panels;

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pocket = new Pocket($this->directory, null, fn () => $this->now, fn () => sprintf('%012x%020x', ++$this->seed, $this->seed));
        $this->panels = new Panels($this->pocket);
    }

    private function click(string $action, array $values = []): PanelResult
    {
        $result = $this->panels->handle('pocket:ui:'.self::ALICE.":{$action}", self::ALICE, 'Alice', $values);
        foreach ([$result->panel, $result->announce] as $message) {
            if ($message !== null) {
                $this->assertLessThanOrEqual(40, MenuMessageBuilder::componentCount($message));
            }
        }

        return $result;
    }

    private static function json(?MessageBuilder $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_UNICODE);
    }

    public function testThePagesLeadFromTheBasicsToAPracticeGame(): void
    {
        $home = self::json($this->click('home')->panel);
        $this->assertStringContainsString('New to Magic?', $home, 'A new player is pointed at How to play.');
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':tut"', $home);

        $first = self::json($this->click('tut')->panel);
        $this->assertStringContainsString('How to play · Welcome', $first);
        $this->assertStringContainsString('Page 1 of '.Tutorial::count(), $first);
        $this->assertStringContainsString('20 life', $first);
        $this->assertMatchesRegularExpression('/"custom_id":"pocket:ui:'.self::ALICE.':tut:-1","disabled":true|"disabled":true[^}]*"custom_id":"pocket:ui:'.self::ALICE.':tut:-1"/', $first, 'No page before the first.');
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':tut:1', $first);

        foreach (array_keys(Tutorial::PAGES) as $page => $title) {
            $json = self::json($this->click("tut:{$page}")->panel);
            $this->assertStringContainsString("How to play · {$title}", $json);
            $this->assertStringContainsString('pocket:ui:'.self::ALICE.':home', $json);
            $this->assertStringContainsString('pocket:ui:'.self::ALICE.':tplay', $json);
            $this->assertLessThanOrEqual(3800, mb_strlen(Tutorial::page($page)[2]) + 100);
        }

        $last = self::json($this->click('tjump', [(string) (Tutorial::count() - 1)])->panel);
        $this->assertStringContainsString('Start a practice game', $last);
        $this->assertStringNotContainsString('Next ▶', $last);
        $this->assertSame(0, Tutorial::page(-5)[0]);
        $this->assertSame(Tutorial::count() - 1, Tutorial::page(99)[0]);
    }

    public function testAPracticeGameIsPrivateAndNeedsNoCards(): void
    {
        $balance = $this->pocket->shop->balance(self::ALICE);
        $result = $this->click('tplay');
        $this->assertNull($result->announce, 'Nothing is posted in the channel.');
        $board = self::json($result->panel);
        $this->assertStringContainsString('Alice vs '.PracticeBot::NAME, $board);
        $this->assertStringContainsString('(practice)', $board);

        $match = $this->pocket->matches->current(self::ALICE);
        $this->assertNotNull($match);
        $this->assertTrue($match->practice);
        $this->assertFalse($match->ranked);
        $this->assertSame(['Alice', PracticeBot::NAME], array_column($match->players, 'name'));
        $this->assertSame([StarterDecks::PLAYER_DECK, StarterDecks::BOT_DECK], array_column($match->players, 'deckName'));
        $this->assertSame(40, count($match->game->players[0]->library) + count($match->game->players[0]->hand));
        $this->assertSame([0], $match->game->waitingOn(), 'The bot kept its hand at once; only Alice decides.');
        $this->assertNull($this->pocket->matches->current(PracticeBot::ID), 'The bot is never tied to one game.');

        // Saved and loaded, it is still a practice game.
        $this->assertTrue(MatchRecord::fromArray(json_decode(json_encode($match->toArray()), true))->practice);

        // The action panel explains the decision.
        $actions = self::json(MatchMessageBuilder::actions($match, self::ALICE));
        $this->assertStringContainsString('💡 A good opening hand has 2 to 5 lands', $actions);

        // The board stays private, and so does conceding.
        $this->assertNull($this->click('pboard')->announce);
        $this->assertStringContainsString(PracticeBot::NAME, self::json($this->click('pboard')->panel));
        $this->assertStringContainsString('Concede', self::json($this->click('pconc')->panel));
        $conceded = $this->click('pconcok');
        $this->assertNull($conceded->announce);
        $this->assertStringContainsString('You conceded', self::json($conceded->panel));
        $this->assertNull($this->pocket->matches->current(self::ALICE));
        $this->assertCount(1, $this->pocket->matches->history(self::ALICE));
        $this->assertSame([], $this->pocket->matches->history(PracticeBot::ID));
        $this->assertSame($balance, $this->pocket->shop->balance(self::ALICE), 'Practice costs and pays nothing.');

        $play = self::json($this->click('play')->panel);
        $this->assertStringContainsString('Practice vs bot', $play);
    }

    public function testOnlyOneMatchAtATime(): void
    {
        $this->click('tplay');
        $refused = self::json($this->click('tplay')->panel);
        $this->assertStringContainsString('already in a match', $refused);
        $this->assertStringNotContainsString('Practice vs bot', $refused);
    }

    public function testTheBotAnswersEveryMoveUntilTheGameEnds(): void
    {
        foreach (range(1, 4) as $game) {
            $match = $this->pocket->matches->practice(self::ALICE, 'Alice');
            for ($moves = 0; $match->status === MatchRecord::PLAYING; $moves++) {
                $this->assertLessThan(3000, $moves, 'The game never ended.');
                $this->assertSame([0], $match->game->waitingOn(), "Only Alice is waited on, in {$match->game->step->label()} of turn {$match->game->turn}.");
                $match = $this->pocket->matches->act($match->id, self::ALICE, function (Game $game, int $seat): void {
                    $this->assertTrue(AutoPlayer::act($game, $seat));
                });
            }
            $this->assertSame(MatchRecord::OVER, $match->status);
            $this->assertNotNull($match->game->winner, "Game {$game} was a draw.");
            $this->assertSame([], $match->rewards, 'Practice pays nothing.');
            $this->assertNull($this->pocket->matches->current(self::ALICE));
        }
    }

    public function testTheBotPlaysWholeGamesOnItsOwn(): void
    {
        $wins = [0, 0];
        foreach (range(1, 6) as $n) {
            $game = Game::start("bot-{$n}", [
                ['id' => '1', 'name' => 'Red-Green', 'cards' => StarterDecks::cards(StarterDecks::PLAYER)],
                ['id' => '2', 'name' => 'White-Black', 'cards' => StarterDecks::cards(StarterDecks::BOT)],
            ], "practice-seed-{$n}");
            for ($moves = 0; $game->stage !== Game::OVER; $moves++) {
                $this->assertLessThan(3000, $moves);
                $waiting = $game->waitingOn();
                $this->assertNotSame([], $waiting);
                $this->assertTrue(PracticeBot::act($game, $waiting[0]), "Seat {$waiting[0]} could not make a {$game->decision($waiting[0])} move.");
            }
            $this->assertNotNull($game->winner);
            $wins[$game->winner]++;
        }
        $this->assertSame(6, array_sum($wins));
    }

    public function testStarterDecks(): void
    {
        $this->assertCount(40, StarterDecks::cards(StarterDecks::PLAYER));
        $this->assertCount(40, StarterDecks::cards(StarterDecks::BOT));
        $this->assertSame(3.0, StarterDecks::card('Centaur Courser')['manaValue']);
        $this->assertSame(6.0, StarterDecks::card('Bogstomper')['manaValue']);
        $this->assertSame('basic:Forest', StarterDecks::card('Forest')['uuid']);
        $this->expectException(\OutOfBoundsException::class);
        StarterDecks::card('Black Lotus');
    }

    /**
     * Saves a 40-card rental deck (read padded to 60) of a current set: Grizzly Bears and Forests.
     */
    private function saveRental(string $id = 'bears', string $name = 'Bear Kit'): void
    {
        $bears = ['uuid' => "{$id}-bears", 'name' => 'Grizzly Bears', 'rarity' => 'common', 'colors' => ['G'], 'manaValue' => 2.0, 'type' => 'Creature — Bear', 'types' => ['Creature'], 'manaCost' => '{1}{G}', 'power' => '2', 'toughness' => '2'];
        $forest = ['uuid' => "{$id}-forest", 'name' => 'Forest', 'rarity' => 'common', 'colors' => [], 'manaValue' => 0.0, 'type' => 'Basic Land — Forest', 'types' => ['Land'], 'supertypes' => ['Basic'], 'subtypes' => ['Forest'], 'manaCost' => null];
        $this->pocket->rentalDecks->save(new RentalDeck($id, $name, 'NEW', 'New Set', 'Starter Kit', '2026-06-01', [['count' => 16, 'card' => $bears], ['count' => 24, 'card' => $forest]]));
    }

    public function testARentalDeckPlaysTheBot(): void
    {
        $this->saveRental();
        $this->pocket->rentals->rent(self::ALICE, 'Alice', 'Bear Kit');
        $left = $this->pocket->rentals->gamesLeft(self::ALICE);

        $play = self::json($this->click('play')->panel);
        $this->assertStringContainsString('Practice vs bot with your deck', $play);
        $this->assertStringContainsString('Starter decks vs bot', $play);

        $result = $this->click('pbot', ['casual']);
        $this->assertNull($result->announce, 'Nothing is posted in the channel.');
        $match = $this->pocket->matches->current(self::ALICE);
        $this->assertNotNull($match);
        $this->assertTrue($match->practice);
        $this->assertFalse($match->isTutorial());
        $this->assertFalse($match->ranked);
        $this->assertSame('casual', $match->mode);
        $this->assertSame([RentalDeck::PREFIX.'bears', null], array_column($match->players, 'deckId'));
        $this->assertSame(['Bear Kit (rental)', 'Bear Kit (rental)'], array_column($match->players, 'deckName'), 'The bot plays an offered rental.');
        $this->assertSame(60, count($match->game->players[0]->library) + count($match->game->players[0]->hand), 'Rentals are padded to 60 with basic lands.');
        $this->assertSame($left, $this->pocket->rentals->gamesLeft(self::ALICE), 'Bot games use up no rental games.');
        $this->assertStringNotContainsString('💡', self::json(MatchMessageBuilder::actions($match, self::ALICE)), 'Tips are for the starter decks.');

        for ($moves = 0; $match->status === MatchRecord::PLAYING; $moves++) {
            $this->assertLessThan(3000, $moves, 'The game never ended.');
            $this->assertSame([0], $match->game->waitingOn());
            $match = $this->pocket->matches->act($match->id, self::ALICE, function (Game $game, int $seat): void {
                $this->assertTrue(AutoPlayer::act($game, $seat));
            });
        }
        $this->assertSame([], $match->rewards, 'Practice pays nothing.');
        $this->assertSame($left, $this->pocket->rentals->gamesLeft(self::ALICE));
        $this->assertNull($this->pocket->matches->current(self::ALICE));
    }

    public function testAnOwnDeckPlaysTheBot(): void
    {
        $pool = new CardPool('OLD', 'Old Set', '2015-01-01');
        $pool->add(['uuid' => 'bears', 'name' => 'Grizzly Bears', 'rarity' => 'common', 'colors' => ['G'], 'manaValue' => 2.0, 'type' => 'Creature — Bear', 'manaCost' => '{1}{G}', 'power' => '2', 'toughness' => '2']);
        $this->pocket->pools->save($pool);
        $this->pocket->inventories->addCards(self::ALICE, ['bears' => 8]);
        $this->pocket->deckBuilder->create(self::ALICE, 'Alice', 'Bears', 'standard');
        $this->pocket->deckBuilder->add(self::ALICE, 'Bears', 'bears', 8);
        $this->pocket->deckBuilder->add(self::ALICE, 'Bears', 'Forest', 32);

        // Its format's library refuses the old set; Casual takes it.
        $this->assertException(fn () => $this->pocket->matches->practice(self::ALICE, 'Alice', 'Bears', 'standard'), 'cannot be played in Standard');
        $match = $this->pocket->matches->practice(self::ALICE, 'Alice', '');
        $this->assertSame('casual', $match->mode);
        $this->assertSame(['Bears', StarterDecks::BOT_DECK], array_column($match->players, 'deckName'), 'With no rental offered, the bot plays its starter deck.');
        $this->assertSame([0], $match->game->waitingOn());
    }

    public function testTips(): void
    {
        $match = $this->pocket->matches->practice(self::ALICE, 'Alice');
        $game = $match->game;
        $this->assertStringContainsString('opening hand', Tutorial::tip($game, 0, 'mulligan'));
        $this->assertStringContainsString('taps your creatures', Tutorial::tip($game, 0, 'attack'));
        $this->assertStringContainsString('Blocking', Tutorial::tip($game, 0, 'block'));
        $this->assertStringContainsString('Pick the target', Tutorial::tip($game, 0, 'priority', ['cast' => 1]));
        $this->assertNull(Tutorial::tip($game, 0, null));
    }
}
