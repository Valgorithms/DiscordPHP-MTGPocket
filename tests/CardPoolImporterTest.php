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

use MTG\Database\Database;
use MTG\Http\Http;
use MTGPocket\Cards\CardPool;
use MTGPocket\Cards\CardPoolImporter;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Pocket;
use MTGPocket\Rentals\RentalDeck;
use MTGPocket\Rentals\RentalDeckImporter;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Browser;

/**
 * Imports from a small hand-made stand-in for MTGJSON's AllPrintings build.
 *
 * @covers \MTGPocket\Cards\CardPool
 * @covers \MTGPocket\Cards\CardPoolImporter
 * @covers \MTGPocket\Repository\CardPoolRepository
 * @covers \MTGPocket\Rentals\RentalDeckImporter
 * @covers \MTGPocket\Repository\RentalRepository
 */
final class CardPoolImporterTest extends StorageTestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->directory, 0777, true);
        $path = $this->directory.'/AllPrintings.sqlite';

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE "meta" ("version" TEXT, "date" TEXT)');
        $pdo->exec('INSERT INTO "meta" VALUES (\'5.3.0+test\', \'2026-10-03\')');
        $pdo->exec('CREATE TABLE "sets" ("code" TEXT, "name" TEXT, "releaseDate" TEXT, "type" TEXT, "isOnlineOnly" BOOLEAN)');
        $pdo->exec('INSERT INTO "sets" VALUES (\'TST\', \'Test Set\', \'2020-01-01\', \'expansion\', 0), (\'PRM\', \'Promos\', \'2020-01-01\', \'promo\', 0), (\'FUT\', \'Future\', \'2999-01-01\', \'expansion\', 0), (\'TSB\', \'Time Spiral Timeshifted\', \'2006-10-06\', \'expansion\', 0)');
        $pdo->exec('CREATE TABLE "cards" ("uuid" TEXT, "name" TEXT, "setCode" TEXT, "number" TEXT, "rarity" TEXT, "colors" TEXT, "manaValue" FLOAT, "type" TEXT, "supertypes" TEXT, "side" TEXT, "isPromo" BOOLEAN, "boosterTypes" TEXT)');
        $pdo->exec('CREATE TABLE "cardIdentifiers" ("uuid" TEXT, "scryfallId" TEXT)');

        $cards = [
            // uuid, name, number, rarity, colors, supertypes, side, isPromo, boosterTypes
            ['bolt', 'Lightning Bolt', '1', 'common', 'R', null, null, 0, 'default'],
            ['bolt-showcase', 'Lightning Bolt', '300', 'common', 'R', null, null, 0, 'default'],
            ['angel', 'Serra Angel', '2', 'uncommon', 'W', null, null, 0, 'default'],
            ['dragon', 'Shivan Dragon', '3', 'rare', 'R', null, null, 0, 'default'],
            ['nicol', 'Nicol Bolas', '4', 'mythic', 'B, R, U', null, null, 0, 'default'],
            ['ring', 'Sol Ring', '5', 'uncommon', null, null, null, 0, 'default'],
            ['dfc-front', 'Delver of Secrets', '6', 'common', 'U', null, 'a', 0, 'default'],
            ['dfc-back', 'Delver of Secrets', '6', 'common', 'U', null, 'b', 0, 'default'],
            ['promo', 'Promo Card', '7', 'rare', 'G', null, null, 1, 'default'],
            ['island', 'Island', '8', 'common', null, 'Basic', null, 0, 'default'],
            ['deck-only', 'Deck Card', '9', 'rare', 'G', null, null, 0, 'deck'],
            ['token-ish', 'Special', '10', 'special', 'G', null, null, 0, 'default'],
        ];
        $card = $pdo->prepare('INSERT INTO "cards" VALUES (?, ?, \'TST\', ?, ?, ?, 1, \'Card\', ?, ?, ?, ?)');
        $identifier = $pdo->prepare('INSERT INTO "cardIdentifiers" VALUES (?, ?)');
        foreach ($cards as $row) {
            $card->execute($row);
            $identifier->execute([$row[0], 'scry-'.$row[0]]);
        }
        $pdo->exec('INSERT INTO "cards" VALUES (\'akroma\', \'Akroma, Angel of Wrath\', \'TSB\', \'1\', \'special\', \'W\', 8, \'Card\', null, null, 0, null)');
        // Rules columns, as the real build has them.
        foreach (['manaCost', 'power', 'toughness', 'text', 'types', 'subtypes', 'keywords'] as $column) {
            $pdo->exec("ALTER TABLE \"cards\" ADD COLUMN \"{$column}\" TEXT");
        }
        $pdo->exec('UPDATE "cards" SET "manaCost" = \'{3}{W}{W}\', "power" = \'4\', "toughness" = \'4\', "text" = \'Flying, vigilance\', "types" = \'Creature\', "subtypes" = \'Angel\', "keywords" = \'Flying, Vigilance\' WHERE "uuid" = \'angel\'');
        $pdo->exec('UPDATE "cards" SET "manaCost" = \'{R}\', "text" = \'Lightning Bolt deals 3 damage to any target.\', "types" = \'Instant\' WHERE "uuid" = \'bolt\'');
        // Official decks, for rentals.
        $pdo->exec('CREATE TABLE "setDecks" ("code" TEXT, "name" TEXT, "type" TEXT, "releaseDate" TEXT, "mainBoard" TEXT, "sideBoard" TEXT, "commander" TEXT)');
        $deck = $pdo->prepare('INSERT INTO "setDecks" VALUES (\'TST\', ?, ?, ?, ?, ?, ?)');
        $board = fn (array $counts) => json_encode(array_map(fn (string $uuid, int $count) => ['uuid' => $uuid, 'count' => $count], array_keys($counts), $counts));
        $deck->execute(['Red Starter', 'Starter Kit', '2020-02-01', $board(['bolt' => 20, 'island' => 18, 'angel' => 2]), $board(['dragon' => 1]), null]);
        $deck->execute(['Too Small', 'Theme Deck', null, $board(['bolt' => 10]), '[]', null]);
        $deck->execute(['Big Commander', 'Commander Deck', null, $board(['bolt' => 99]), '[]', $board(['nicol' => 1])]);
        $deck->execute(['Missing Cards', 'Theme Deck', null, $board(['bolt' => 20, 'not-in-build' => 20]), '[]', null]);
        $pdo = null;

        $loop = Loop::get();
        $logger = new NullLogger();
        $this->database = new Database($loop, $logger, new Http('', $loop, $logger), new Browser(null, $loop), $path, 0);
        $this->database->ready();
        $this->assertTrue($this->database->isOpen());
    }

    protected function tearDown(): void
    {
        $this->database->close();
        parent::tearDown();
    }

    public function testListsReleasedPaperSets(): void
    {
        $this->assertSame(['TSB', 'TST'], array_keys((new CardPoolImporter($this->database))->sets()));
    }

    public function testASetOfOnlySpecialCardsCountsThemAsRares(): void
    {
        $pool = (new CardPoolImporter($this->database))->import('TSB');
        $this->assertSame(['akroma'], $pool->uuids('W', 'rare'));
    }

    public function testSortsBoosterCardsByColorAndRarity(): void
    {
        $pool = (new CardPoolImporter($this->database))->import('tst');

        $this->assertSame('TST', $pool->setCode);
        $this->assertSame('Test Set', $pool->setName);
        $this->assertSame('5.3.0+test', $pool->mtgjsonVersion);
        $this->assertSame(6, $pool->size(), 'No back faces, promos, basics, deck-only cards, specials or second printings.');

        $this->assertSame(['bolt'], $pool->uuids('R', 'common'));
        $this->assertSame(['dragon'], $pool->uuids('R', 'rare'));
        $this->assertSame(['angel'], $pool->uuids('W'));
        $this->assertSame(['dfc-front'], $pool->uuids('U'));
        $this->assertSame(['nicol'], $pool->uuids(CardPool::MULTICOLOR, 'mythic'));
        $this->assertSame(['ring'], $pool->uuids(CardPool::COLORLESS));
        $this->assertSame(['W', 'U', 'R', 'M', 'C'], $pool->colors());
        $this->assertSame('scry-dragon', $pool->card('dragon')['scryfallId']);

        $angel = $pool->card('angel');
        $this->assertSame(['{3}{W}{W}', '4', '4', ['Creature'], ['Angel']], [$angel['manaCost'], $angel['power'], $angel['toughness'], $angel['types'], $angel['subtypes']]);
        $this->assertSame(['flying', 'vigilance'], (new CardDefinition($angel))->keywords, 'The rules engine can read it.');
        $this->assertArrayHasKey('manaCost', $pool->card('ring'), 'No mana cost is kept as null: it cannot be cast.');
        $this->assertNull($pool->card('ring')['manaCost']);

        $this->assertTrue($pool->hasRareSlot('R'));
        $this->assertFalse($pool->hasRareSlot('W'));
    }

    public function testImportsOfficialDecksAsRentals(): void
    {
        $decks = (new RentalDeckImporter($this->database))->import('tst');
        $this->assertSame(['Red Starter'], array_map(fn (RentalDeck $deck) => $deck->name, $decks), 'Not decks under 40 cards, Commander decks or decks with unknown cards.');

        $deck = $decks[0];
        $this->assertSame(['tst-red-starter', 'TST', 'Test Set', 'Starter Kit', '2020-02-01', 40], [$deck->id, $deck->setCode, $deck->setName, $deck->type, $deck->releaseDate, $deck->mainCount()]);
        $this->assertSame(['dragon' => 1], $deck->deck('p', 'standard')->side->toArray());
        $this->assertSame('{R}', $deck->card('bolt')['manaCost'], 'It carries the rules data.');
        $this->assertSame('TST', $deck->card('bolt')['setCode']);
        $this->assertCount(40, $deck->mainCards());

        $pocket = new Pocket($this->directory.'/data');
        $pocket->rentalDecks->save($deck);
        $this->assertEquals($deck, (new Pocket($this->directory.'/data'))->rentalDecks->find('tst-red-starter'));
    }

    public function testUnknownSetIsAnError(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new CardPoolImporter($this->database))->import('NOPE');
    }

    public function testPoolsRoundTripThroughStorage(): void
    {
        $pool = (new CardPoolImporter($this->database))->import('TST');
        (new Pocket($this->directory.'/data'))->pools->save($pool);

        $loaded = (new Pocket($this->directory.'/data'))->pools->find('tst');

        $this->assertSame(['TST'], (new Pocket($this->directory.'/data'))->pools->setCodes());
        $this->assertSame($pool->jsonSerialize(), $loaded->jsonSerialize());
    }
}
