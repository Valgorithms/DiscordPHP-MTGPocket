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

use MTGPocket\Economy\PriceList;
use MTGPocket\Models\Player;

/**
 * @covers \MTGPocket\Economy\PriceList
 * @covers \MTGPocket\Economy\Shop
 * @covers \MTGPocket\Economy\Receipt
 */
final class ShopTest extends PocketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Now is 2026-09-21: NEW is under a year old, MID under three, OLD older than ten.
        $this->importPool('NEW', ['R' => self::fullColor()], '2026-06-01');
        $this->importPool('MID', ['W' => ['common' => 2, 'mythic' => 1]], '2024-06-01');
        $this->importPool('OLD', ['G' => ['common' => 20, 'uncommon' => 12, 'rare' => 2]], '2010-01-01');
    }

    public function testTheShippedPriceListLoadsAndCannotBeFarmed(): void
    {
        $prices = PriceList::fromFile();

        foreach (['common', 'uncommon', 'rare', 'mythic'] as $rarity) {
            foreach (['2026-09-01', '2024-01-01', '2018-01-01', '1995-01-01', null] as $date) {
                $this->assertLessThan($prices->buyPrice($rarity, $date, $this->now), $prices->sellPrice($rarity, $date, $this->now), "Selling a {$rarity} pays less than buying it.");
                $this->assertGreaterThanOrEqual(1, $prices->sellPrice($rarity, $date, $this->now));
            }
        }
    }

    public function testPricesScaleWithRarityAndSetAge(): void
    {
        $shop = $this->pocket->shop;

        $this->assertSame(['buy' => 40, 'sell' => 8], array_slice($shop->quote('NEW-R-common-1'), 1));
        $this->assertSame(['buy' => 600, 'sell' => 120], array_slice($shop->quote('NEW-R-rare-1'), 1));
        $this->assertSame(['buy' => 1800, 'sell' => 360], array_slice($shop->quote('NEW-R-mythic-1'), 1));
        $this->assertSame(['buy' => 1350, 'sell' => 270], array_slice($shop->quote('MID-W-mythic-1'), 1));
        $this->assertSame(['buy' => 15, 'sell' => 3], array_slice($shop->quote('OLD-G-common-1'), 1));
        $this->assertSame('NEW R rare 1', $shop->quote('new r RARE 1')['card']['name'], 'Cards can be named.');

        $packs = $shop->packPrices();
        $this->assertSame(['NEW', 'MID', 'OLD'], array_keys($packs), 'Newest first.');
        $this->assertSame(1000, $packs['NEW']['price']);
        $this->assertSame(750, $packs['MID']['price']);
        $this->assertSame(['W'], $packs['MID']['colors']);
        $this->assertSame(375, $packs['OLD']['price']);
    }

    public function testSellsSpareCopiesForPoints(): void
    {
        $this->pocket->inventories->addCards('1', ['NEW-R-rare-1' => 3, 'NEW-R-common-1' => 2]);

        $receipt = $this->pocket->shop->sell('1', 'Val', 'NEW-R-rare-1', 2);

        $this->assertSame(240, $receipt->total);
        $this->assertSame(240, $receipt->balance);
        $this->assertSame(240, $this->pocket->shop->balance('1'));
        $this->assertSame(1, $this->pocket->inventories->get('1')->cards->get('NEW-R-rare-1'));

        $this->expectException(\InvalidArgumentException::class);
        $this->pocket->shop->sell('1', 'Val', 'NEW R rare 1', 2);
    }

    public function testNeverSellsCardsADeckUses(): void
    {
        $this->pocket->inventories->addCards('1', ['NEW-R-rare-1' => 3]);
        $this->pocket->deckBuilder->create('1', 'Val', 'Burn');
        $this->pocket->deckBuilder->add('1', 'Burn', 'NEW-R-rare-1', 2);

        try {
            $this->pocket->shop->sell('1', 'Val', 'NEW-R-rare-1', 2);
            $this->fail('Sold a card a deck uses.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('You can sell 1 of your 3 **NEW R rare 1**: your deck **Burn** uses 2.', $e->getMessage());
        }
        $this->assertSame(3, $this->pocket->inventories->get('1')->cards->get('NEW-R-rare-1'), 'Nothing was sold.');
        $this->assertSame(0, $this->pocket->shop->balance('1'));

        $this->assertSame(120, $this->pocket->shop->sell('1', 'Val', 'NEW-R-rare-1')->total);
    }

    public function testSellsExtrasBeyondAKeepCountAndDecks(): void
    {
        $this->pocket->inventories->addCards('1', ['OLD-G-common-1' => 6, 'OLD-G-common-2' => 2, 'NEW-R-rare-1' => 7]);
        $this->pocket->deckBuilder->create('1', 'Val', 'Big');
        $this->pocket->deckBuilder->add('1', 'Big', 'NEW-R-rare-1', 6);

        $preview = $this->pocket->shop->extras('1', 4);
        $this->assertSame(['OLD-G-common-1' => 2, 'NEW-R-rare-1' => 1], $preview['cards']);
        $this->assertSame(2 * 3 + 120, $preview['points']);

        $this->assertSame(['OLD-G-common-1' => 2], $this->pocket->shop->extras('1', 4, 'common')['cards']);
        $this->assertSame(['OLD-G-common-1' => 6, 'OLD-G-common-2' => 2], $this->pocket->shop->extras('1', 0, null, 'old')['cards']);

        $receipt = $this->pocket->shop->sellExtras('1', 'Val', 4);
        $this->assertSame($preview['points'], $receipt->total);
        $this->assertSame(3, $receipt->cards());
        $this->assertSame(['OLD-G-common-1' => 4, 'OLD-G-common-2' => 2, 'NEW-R-rare-1' => 6], $this->pocket->inventories->get('1')->cards->toArray());

        $this->expectException(\InvalidArgumentException::class);
        $this->pocket->shop->sellExtras('1', 'Val', 4);
    }

    public function testBuysCardsWithPoints(): void
    {
        $this->givePoints('1', 1000);

        $receipt = $this->pocket->shop->buyCard('1', 'Val', 'NEW-R-rare-1');
        $this->assertSame(600, $receipt->total);
        $this->assertSame(400, $receipt->balance);
        $this->assertSame(1, $this->pocket->inventories->get('1')->cards->get('NEW-R-rare-1'));

        $this->assertSame(30, $this->pocket->shop->buyCard('1', 'Val', 'OLD G common 3', 2)->total, 'Cards can be named.');

        try {
            $this->pocket->shop->buyCard('1', 'Val', 'NEW-R-rare-2');
            $this->fail('Bought a card without the points.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('costs 600 points and you have 370', $e->getMessage());
        }
        $this->assertSame(0, $this->pocket->inventories->get('1')->cards->get('NEW-R-rare-2'));
        $this->assertSame(370, $this->pocket->shop->balance('1'));

        $this->expectException(\OutOfBoundsException::class);
        $this->pocket->shop->buyCard('1', 'Val', 'Not a card');
    }

    public function testBuysPacksOfAColorOfASet(): void
    {
        $this->givePoints('1', 1200);

        $opened = $this->pocket->shop->buyPack('1', 'Val', 'NEW', 'R');

        $this->assertSame(1000, $opened->price);
        $this->assertSame(200, $opened->balance);
        $this->assertNull($opened->nextPackAt);
        $this->assertCount(15, $opened->pack->cards);
        $this->assertNotEmpty($opened->pack->ofRarity('rare', 'mythic'), 'A bought pack has a rare or mythic too.');
        $this->assertSame(15, $this->pocket->inventories->get('1')->cards->total());
        $this->assertNull($this->pocket->players->find('1')->lastDailyPackAt, 'Buying a pack does not use up the free one.');

        try {
            $this->pocket->shop->buyPack('1', 'Val', 'OLD');
            $this->fail('Bought a pack without the points.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('A Set OLD pack costs 375 points and you have 200', $e->getMessage());
        }
        $this->assertSame(15, $this->pocket->inventories->get('1')->cards->total());
    }

    public function testRejectsPriceListsThatCouldBeFarmed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PriceList::fromArray(['buy' => ['common' => 1, 'uncommon' => 2, 'rare' => 3, 'mythic' => 4], 'pack' => 10, 'sell_rate' => 1.0, 'age' => [['days' => null, 'multiplier' => 1]]]);
    }

    private function givePoints(string $playerId, int $points): void
    {
        $this->pocket->players->findOrCreate($playerId, 'Val');
        $this->pocket->players->modify($playerId, fn (Player $player) => $player->points += $points);
    }
}
