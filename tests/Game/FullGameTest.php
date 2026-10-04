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

use MTGPocket\Game\Game;
use MTGPocket\Game\GameRecord;

/**
 * Whole games between two automated players, saved and loaded after every
 * move the way the bot does, from the coin flip to the last point of life.
 *
 * Set MTGPOCKET_RECORD to a file path to keep the first game's record.
 */
final class FullGameTest extends GameTestCase
{
    private const array GRUUL = [
        'Mountain' => 9, 'Forest' => 8,
        'Llanowar Elves' => 2, 'Grizzly Bears' => 3, 'Raging Goblin' => 2, 'Hill Giant' => 2, 'Giant Spider' => 1,
        'Goblin Heelcutter' => 2, 'Flametongue Kavu' => 2, 'Craw Wurm' => 1, 'Colossal Dreadmaw' => 1,
        'Lightning Bolt' => 2, 'Shock' => 2, 'Giant Growth' => 1, 'Prodigal Pyromancer' => 1, 'Bonesplitter' => 1,
    ];

    private const array ORZHOV = [
        'Plains' => 9, 'Swamp' => 8,
        'Typhoid Rats' => 2, 'Doomed Traveler' => 2, 'Isamaru, Hound of Konda' => 1, 'White Knight' => 2, 'Fencing Ace' => 2,
        'Vampire Nighthawk' => 2, 'Raid Leader' => 2, 'Serra Angel' => 2, 'Murder' => 2, 'Raise the Alarm' => 2,
        'Dark Tutelage' => 1, 'Bottle Gnomes' => 1, 'Ornithopter' => 1, 'Bonesplitter' => 1,
    ];

    /**
     * @param array<string, int> $list
     *
     * @return array[]
     */
    private static function deck(array $list): array
    {
        $cards = [];
        foreach ($list as $name => $count) {
            array_push($cards, ...array_fill(0, $count, self::card($name)));
        }

        return $cards;
    }

    /**
     * Plays a game to the end, saving and loading it after every move.
     *
     * @param string $seed
     *
     * @return Game
     */
    private function simulate(string $seed): Game
    {
        $game = Game::start("sim-{$seed}", [
            ['id' => '1', 'name' => 'Alice', 'cards' => self::deck(self::GRUUL)],
            ['id' => '2', 'name' => 'Bob', 'cards' => self::deck(self::ORZHOV)],
        ], $seed);

        for ($moves = 0; $game->stage !== Game::OVER; $moves++) {
            $this->assertLessThan(5000, $moves, 'The game never ended.');
            $waiting = $game->waitingOn();
            $this->assertNotSame([], $waiting, "Nobody can move in {$game->step->label()} of turn {$game->turn}.");
            $this->assertTrue(AutoPlayer::act($game, $waiting[0]), "Seat {$waiting[0]} could not make a {$game->decision($waiting[0])} move.");
            $game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        }

        return $game;
    }

    public function testGamesPlayToTheEndAndAreRecorded(): void
    {
        foreach (['sim-7', 'sim-1', 'sim-2', 'sim-3', 'sim-4'] as $index => $seed) {
            $game = $this->simulate($seed);
            $this->assertSame(Game::OVER, $game->stage);
            $this->assertNotNull($game->winner, "Game {$seed} was a draw.");

            // The log on the board is capped; the record keeps everything.
            $this->assertLessThanOrEqual(Game::MAX_LOG, count($game->log));
            $this->assertGreaterThan(count($game->log), count($game->record));
            $lines = array_column($game->record, 'x');
            $this->assertSame(end($game->log), end($lines));
            $this->assertSame(0, $game->record[0]['t']);
            $turns = array_values(array_unique(array_column($game->record, 't')));
            $this->assertSame(range(0, $game->turn), $turns, 'Every turn is in the record.');
            $positions = array_values(array_filter($game->record, fn (array $entry) => isset($entry['pos'])));
            $this->assertCount($game->turn, $positions, 'A position for each turn that ended, and the final one.');

            $text = GameRecord::render($game, ['Match' => $seed, 'Mode' => 'Casual (simulated)', 'Seat 1' => 'Alice', 'Seat 1 deck' => 'Gruul (test deck)', 'Seat 2' => 'Bob', 'Seat 2 deck' => 'Orzhov (test deck)']);
            $this->assertStringContainsString('[Match "'.$seed.'"]', $text);
            $this->assertStringContainsString('[Result "'.($game->winner === 0 ? '1-0' : '0-1').'"]', $text);
            $this->assertStringContainsString('[Seed "'.$seed.'"]', $text);
            $this->assertStringContainsString("{$game->turn}. ", $text);
            $this->assertStringContainsString('Final position:', $text);

            if ($index === 0 && ($path = getenv('MTGPOCKET_RECORD'))) {
                file_put_contents($path, $text);
            }
        }
    }

    public function testTheSeedStaysHiddenWhileTheGameGoesOn(): void
    {
        $game = Game::start('live', [
            ['id' => '1', 'name' => 'Alice', 'cards' => self::deck(self::GRUUL)],
            ['id' => '2', 'name' => 'Bob', 'cards' => self::deck(self::ORZHOV)],
        ], 'secret-seed');
        $game->keep(0);
        $game->keep(1);

        $text = GameRecord::render($game);
        $this->assertStringContainsString('[Result "*"]', $text);
        $this->assertStringNotContainsString('secret-seed', $text);
    }

    public function testGamesFromBeforeTheRecordShowTheirLog(): void
    {
        $data = $this->newGame()->toArray();
        unset($data['record']);
        $game = Game::fromArray($data);

        $this->assertSame([], $game->record);
        $this->assertStringContainsString('before full match records', GameRecord::render($game));
    }
}
