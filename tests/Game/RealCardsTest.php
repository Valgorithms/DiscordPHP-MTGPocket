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
use MTGPocket\Rentals\RentalDeck;
use PHPUnit\Framework\TestCase;

/**
 * Every imported card read, and whole games between official decks, on the
 * card pools `composer import-cards` wrote. Skipped when nothing has been
 * imported (MTGPOCKET_DATA, default var/data), as in CI.
 *
 * Set MTGPOCKET_SIM_GAMES to play more games than the default.
 *
 * @coversNothing
 */
final class RealCardsTest extends TestCase
{
    private static function data(string $kind): string
    {
        $directory = (getenv('MTGPOCKET_DATA') ?: dirname(__DIR__, 2).'/var/data').'/'.$kind;
        if (glob($directory.'/*.json') === []) {
            self::markTestSkipped("No imported {$kind} in {$directory}; run composer import-cards.");
        }

        return $directory;
    }

    public function testEveryImportedCardCanBeRead(): void
    {
        $seen = [];
        foreach (glob(self::data('pools').'/*.json') as $file) {
            foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['cards'] as $card) {
                if (isset($seen[$card['name']])) {
                    continue;
                }
                $seen[$card['name']] = true;
                $definition = new CardDefinition($card);
                $this->assertGreaterThanOrEqual(0, $definition->cost->manaValue(), $card['name']);
            }
        }
        $this->assertNotEmpty($seen);
    }

    public function testOfficialDecksPlayToTheEnd(): void
    {
        // Decks are read one game at a time: every official deck at once is more than the default memory limit.
        $files = glob(self::data('rentals').'/*.json');
        $deck = function (string $key) use ($files): RentalDeck {
            for ($i = crc32($key); ; $i++) {
                $deck = RentalDeck::fromArray(json_decode(file_get_contents($files[$i % count($files)]), true, flags: JSON_THROW_ON_ERROR));
                if ($deck->mainCount() >= 40) {
                    return $deck;
                }
            }
        };

        $games = (int) (getenv('MTGPOCKET_SIM_GAMES') ?: 50);
        for ($seed = 0; $seed < $games; $seed++) {
            [$a, $b] = [$deck("a{$seed}"), $deck("b{$seed}")];
            $label = "seed {$seed}: {$a->id} vs {$b->id}";
            $game = Game::start("real-{$seed}", [
                ['id' => '1', 'name' => 'Alice', 'cards' => $a->mainCards()],
                ['id' => '2', 'name' => 'Bob', 'cards' => $b->mainCards()],
            ], "real-{$seed}");
            for ($moves = 0; $game->stage !== Game::OVER; $moves++) {
                $this->assertLessThan(5000, $moves, "{$label} never ended.");
                $waiting = $game->waitingOn();
                $this->assertNotSame([], $waiting, "{$label}: nobody can move in {$game->step->label()} of turn {$game->turn}.");
                $this->assertTrue(AutoPlayer::act($game, $waiting[0]), "{$label}: seat {$waiting[0]} could not make a {$game->decision($waiting[0])} move.");
                $game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
            }
        }
    }
}
