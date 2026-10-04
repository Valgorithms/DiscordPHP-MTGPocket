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

use MTGPocket\Models\Player;
use MTGPocket\Trades\StaleOfferException;
use MTGPocket\Trades\TradeOffer;
use MTGPocket\Trades\TradeService;

/**
 * @covers \MTGPocket\Trades\TradeService
 * @covers \MTGPocket\Trades\TradeOffer
 * @covers \MTGPocket\Trades\StaleOfferException
 * @covers \MTGPocket\Repository\TradeRepository
 */
final class TradesTest extends PocketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importPool('TST', ['R' => self::fullColor(), 'G' => self::fullColor()]);
        $this->pocket->inventories->addCards('1', ['TST-R-rare-1' => 2, 'TST-R-common-1' => 4]);
        $this->pocket->inventories->addCards('2', ['TST-G-mythic-1' => 1]);
        $this->givePoints('1', 'Val', 500);
        $this->givePoints('2', 'Ana', 100);
    }

    public function testATradeHappensWhenBothAgree(): void
    {
        $trades = $this->pocket->trades;
        $offer = $trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1, 'TST R common 1' => 2], ['TST-G-mythic-1' => 1], 50, 0);

        $this->assertSame(TradeOffer::OPEN, $offer->status);
        $this->assertSame(['TST-R-rare-1' => 1, 'TST-R-common-1' => 2], $offer->give->toArray(), 'Names resolve to the cards owned.');
        $this->assertSame(1, $this->pocket->inventories->get('1')->cards->get('TST-R-rare-1') - 1, 'Nothing moves until it is accepted.');
        $this->assertCount(1, $trades->forPlayer('2')['received']);
        $this->assertCount(1, $trades->forPlayer('1')['sent']);

        $done = $trades->accept($offer->id, '2', 'Ana', $offer->revision);

        $this->assertSame(TradeOffer::ACCEPTED, $done->status);
        $this->assertSame(['TST-R-rare-1' => 1, 'TST-R-common-1' => 2, 'TST-G-mythic-1' => 1], $this->pocket->inventories->get('1')->cards->toArray());
        $this->assertSame(['TST-R-rare-1' => 1, 'TST-R-common-1' => 2], $this->pocket->inventories->get('2')->cards->toArray());
        $this->assertSame(450, $this->pocket->shop->balance('1'));
        $this->assertSame(150, $this->pocket->shop->balance('2'));
        $this->assertSame(['sent' => [], 'received' => []], $trades->forPlayer('1'), 'A finished offer is gone.');

        $this->expectException(\OutOfBoundsException::class);
        $trades->accept($offer->id, '2', 'Ana', $offer->revision);
    }

    public function testOnlyTheOtherPlayerCanAccept(): void
    {
        $offer = $this->pocket->trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1]);

        foreach (['1', '3'] as $player) {
            try {
                $this->pocket->trades->accept($offer->id, $player, 'Someone', $offer->revision);
                $this->fail("{$player} accepted.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Only **Ana** can accept', $e->getMessage());
            }
        }
        $this->assertSame(2, $this->pocket->inventories->get('1')->cards->get('TST-R-rare-1'));
    }

    public function testAnAcceptOfAnOlderRevisionDoesNothing(): void
    {
        $offer = $this->pocket->trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1]);
        $changed = $this->pocket->trades->add('1', null, true, 'TST-G-mythic-1');
        $this->assertSame(2, $changed->revision);

        try {
            $this->pocket->trades->accept($offer->id, '2', 'Ana', $offer->revision);
            $this->fail('Accepted an offer that changed.');
        } catch (StaleOfferException $e) {
            $this->assertSame(['TST-G-mythic-1' => 1], $e->offer->want->toArray(), 'The player is shown the offer as it is now.');
        }
        $this->assertSame(1, $this->pocket->inventories->get('2')->cards->get('TST-G-mythic-1'));

        $this->pocket->trades->accept($offer->id, '2', 'Ana', $changed->revision);
        $this->assertSame(1, $this->pocket->inventories->get('1')->cards->get('TST-G-mythic-1'));
    }

    public function testATradeEitherSideCannotHoldUpDoesNotHappen(): void
    {
        $offer = $this->pocket->trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 2], ['TST-G-mythic-1' => 1], 0, 100);

        // Val puts both rares in a deck after offering them.
        $this->pocket->deckBuilder->create('1', 'Val', 'Burn');
        $this->pocket->deckBuilder->add('1', 'Burn', 'TST-R-rare-1', 2);

        try {
            $this->pocket->trades->accept($offer->id, '2', 'Ana', $offer->revision);
            $this->fail('Traded away cards a deck uses.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('**Val** can trade only 0 **TST R rare 1**: the deck **Burn** uses 2.', $e->getMessage());
        }

        // Nothing moved on either side, and the offer is still open.
        $this->assertSame(['TST-R-rare-1' => 2, 'TST-R-common-1' => 4], $this->pocket->inventories->get('1')->cards->toArray());
        $this->assertSame(['TST-G-mythic-1' => 1], $this->pocket->inventories->get('2')->cards->toArray());
        $this->assertSame(500, $this->pocket->shop->balance('1'));
        $this->assertSame(100, $this->pocket->shop->balance('2'));
        $this->assertNotNull($this->pocket->trades->find($offer->id));

        // Ana spends the points they would have paid.
        $this->pocket->deckBuilder->remove('1', 'Burn', 'TST-R-rare-1', null);
        $this->pocket->players->modify('2', fn (Player $player) => $player->points = 50);
        $this->expectExceptionMessage('**Ana** doesn\'t have the 100 points');
        $this->pocket->trades->accept($offer->id, '2', 'Ana', $offer->revision);
    }

    public function testOffersAreCheckedWhenMade(): void
    {
        $trades = $this->pocket->trades;
        $cases = [
            'You cannot trade with yourself.' => fn () => $trades->offer('1', 'Val', '1', 'Val', ['TST-R-rare-1' => 1]),
            'Put at least one card or some points' => fn () => $trades->offer('1', 'Val', '2', 'Ana'),
            'You own 2 **TST R rare 1**, not 3.' => fn () => $trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 3]),
            '**Ana** doesn\'t own **TST R rare 1**.' => fn () => $trades->offer('1', 'Val', '2', 'Ana', [], ['TST-R-rare-1' => 1]),
            'You have 500 points, fewer than you offered.' => fn () => $trades->offer('1', 'Val', '2', 'Ana', [], [], 501),
        ];
        foreach ($cases as $message => $offer) {
            try {
                $offer();
                $this->fail("Offered: {$message}");
            } catch (\InvalidArgumentException|\OutOfBoundsException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(['sent' => [], 'received' => []], $trades->forPlayer('1'));
    }

    public function testDeclineCancelAndExpiry(): void
    {
        $trades = $this->pocket->trades;

        $offer = $trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1]);
        $this->assertSame(TradeOffer::DECLINED, $trades->decline($offer->id, '2')->status);
        $this->assertNull($trades->find($offer->id));

        $offer = $trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1]);
        try {
            $trades->cancel('2', $offer->id);
            $this->fail('Cancelled someone else\'s offer.');
        } catch (\OutOfBoundsException) {
        }
        $this->assertSame(TradeOffer::CANCELLED, $trades->cancel('1')->status);

        $offer = $trades->offer('1', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1]);
        $this->now += TradeService::LIFETIME;
        $this->assertSame(['sent' => [], 'received' => []], $trades->forPlayer('2'));
        $this->expectException(\OutOfBoundsException::class);
        $trades->accept($offer->id, '2', 'Ana', $offer->revision);
    }

    public function testLimitsOpenOffers(): void
    {
        for ($n = 0; $n < TradeService::MAX_OPEN; $n++) {
            $this->pocket->trades->offer('1', 'Val', '2', 'Ana', [], [], 1);
        }

        $this->expectExceptionMessage('You already have '.TradeService::MAX_OPEN.' open trade offers.');
        $this->pocket->trades->offer('1', 'Val', '2', 'Ana', [], [], 1);
    }

    private function givePoints(string $playerId, string $name, int $points): void
    {
        $this->pocket->players->findOrCreate($playerId, $name);
        $this->pocket->players->modify($playerId, fn (Player $player) => $player->points += $points);
    }
}
