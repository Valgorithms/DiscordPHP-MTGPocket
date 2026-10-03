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
use MTGPocket\Pocket;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Browser;

/**
 * Imports from a small hand-made stand-in for MTGJSON's AllPrintings build.
 *
 * @covers \MTGPocket\Cards\CardPool
 * @covers \MTGPocket\Cards\CardPoolImporter
 * @covers \MTGPocket\Repository\CardPoolRepository
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
        $pdo->exec('INSERT INTO "sets" VALUES (\'TST\', \'Test Set\', \'2020-01-01\', \'expansion\', 0), (\'PRM\', \'Promos\', \'2020-01-01\', \'promo\', 0), (\'FUT\', \'Future\', \'2999-01-01\', \'expansion\', 0)');
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
        $this->assertSame(['TST'], array_keys((new CardPoolImporter($this->database))->sets()));
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

        $this->assertTrue($pool->hasRareSlot('R'));
        $this->assertFalse($pool->hasRareSlot('W'));
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
