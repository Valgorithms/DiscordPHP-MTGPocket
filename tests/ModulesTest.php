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

use Discord\Factory\Factory;
use Discord\Helpers\Collection as DiscordCollection;
use Discord\Http\Http;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use MTG\Helpers\CommandSignature;
use MTG\MTG;
use MTGPocket\Builders\DraftMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Builders\ShopMessageBuilder;
use MTGPocket\Builders\TradeMessageBuilder;
use MTGPocket\Modules\Collection;
use MTGPocket\Modules\Drafts;
use MTGPocket\Modules\Matches;
use MTGPocket\Modules\Packs;
use MTGPocket\Modules\PlayerDecks;
use MTGPocket\Modules\Quests;
use MTGPocket\Modules\Shop;
use MTGPocket\Modules\Trades;

/**
 * The Discord side, without connecting: command definitions follow
 * Discord's rules, and the game's messages build and fit Discord's limits.
 *
 * @covers \MTGPocket\Modules\Packs
 * @covers \MTGPocket\Modules\Collection
 * @covers \MTGPocket\Modules\PlayerDecks
 * @covers \MTGPocket\Modules\Matches
 * @covers \MTGPocket\Modules\Shop
 * @covers \MTGPocket\Modules\Trades
 * @covers \MTGPocket\Modules\Drafts
 * @covers \MTGPocket\Builders\DraftMessageBuilder
 * @covers \MTGPocket\Builders\ShopMessageBuilder
 * @covers \MTGPocket\Builders\TradeMessageBuilder
 * @covers \MTGPocket\Modules\PocketTrait
 * @covers \MTGPocket\Builders\PocketMessageBuilder
 */
final class ModulesTest extends PocketTestCase
{
    /**
     * An MTG client that can build parts but never connects.
     *
     * @return MTG
     */
    private static function offlineClient(): MTG
    {
        $mtg = (new \ReflectionClass(MTG::class))->newInstanceWithoutConstructor();
        (function (): void {
            $this->http = (new \ReflectionClass(Http::class))->newInstanceWithoutConstructor();
            $this->factory = new Factory($this);
            $this->collectionClass = DiscordCollection::class;
        })->call($mtg);

        return $mtg;
    }

    public function testCommandsFollowDiscordsRules(): void
    {
        $mtg = self::offlineClient();
        $names = [];
        foreach ([new Packs($this->pocket), new Collection($this->pocket), new PlayerDecks($this->pocket), new Matches($this->pocket), new Quests($this->pocket), new Shop($this->pocket), new Trades($this->pocket), new Drafts($this->pocket)] as $module) {
            foreach ($module->commands($mtg) as $builder) {
                $command = $builder->jsonSerialize();
                $this->assertSame(Command::CHAT_INPUT, (int) $command['type']);
                $this->assertArrayNotHasKey($command['name'], $names);
                $names[$command['name']] = true;

                $this->assertMatchesRegularExpression('/^[-_\p{Ll}\p{N}]{1,32}$/u', $command['name']);
                $this->assertDescription($command['description'], $command['name']);
                $this->assertOptions($command['options'] ?? [], $command['name']);
                $this->assertLessThanOrEqual(4000, self::characters($command), "{$command['name']} is over Discord's 4000 characters.");
                $this->assertTrue(CommandSignature::same($command, json_decode(json_encode($command), true)));
            }
        }

        // None clashes with DiscordPHP-MTG's own commands.
        $this->assertSame(['pack', 'collection', 'decks', 'match', 'quests', 'shop', 'trade', 'draft'], array_keys($names));
    }

    public function testPackMessage(): void
    {
        $this->importPool('TST', ['R' => self::fullColor()]);
        $opened = $this->pocket->dailyPacks->open('116927250145869826', 'Val', 'TST', 'R');

        $json = json_encode(PocketMessageBuilder::pack($opened), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Set TST — Red pack', $json);
        $this->assertStringContainsString('15 new', $json);
        $this->assertStringContainsString('cards.scryfall.io', $json, 'The rare is shown big.');
        $this->assertStringContainsString('"custom_id":"pocket:card"', $json);
    }

    public function testDeckMessageAndExport(): void
    {
        $this->importPool('TST', ['R' => ['common' => 1, 'rare' => 1]]);
        $this->pocket->inventories->addCards('1', ['TST-R-common-1' => 4, 'TST-R-rare-1' => 1]);
        $builder = $this->pocket->deckBuilder;
        $deck = $builder->create('1', 'Val', 'Burn');
        $builder->add('1', 'Burn', 'TST-R-common-1', 4);
        $builder->add('1', 'Burn', 'Mountain', 16);
        [$deck] = $builder->add('1', 'Burn', 'TST-R-rare-1', 1, true);

        $json = json_encode(PocketMessageBuilder::deck($deck, $builder->cardData(...), true, 'Added.'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('main deck 20 · side deck 1 · ✅ active', $json);
        $this->assertStringContainsString('**Lands** (16)', $json);
        $this->assertStringContainsString('**Creatures** (4)', $json);
        $this->assertStringContainsString('pocket:export:1:'.$deck->id, $json);

        $this->assertSame("Deck\n4 TST R common 1 (TST) 1\n16 Mountain\n\nSideboard\n1 TST R rare 1 (TST) 1\n", PocketMessageBuilder::export($deck, $builder->cardData(...)));

        $list = json_encode(PocketMessageBuilder::deckList($builder->list('1'), $deck->id), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('**Burn** · Standard · 20 + 1 · ✅ active', $list);
    }

    public function testCollectionPagesKeepTheirQueryInTheCustomId(): void
    {
        $this->importPool('TST', ['R' => ['common' => 25]]);
        $this->pocket->inventories->addCards('116927250145869826', array_fill_keys(array_map(fn ($n) => "TST-R-common-{$n}", range(1, 25)), 2));
        $module = new Collection($this->pocket);
        $page = (new \ReflectionMethod($module, 'page'))->getClosure($module);
        $encode = (new \ReflectionMethod($module, 'encode'))->getClosure(null);
        $decode = (new \ReflectionMethod($module, 'decode'))->getClosure(null);

        $query = ['player' => '116927250145869826', 'set' => 'TST', 'color' => 'R', 'rarity' => 'common', 'name' => 'r common'];
        $json = json_encode($page(self::offlineClient(), $query, 2), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('page 2 of 3', $json);
        $this->assertStringContainsString('50 copies', $json);
        preg_match_all('/"custom_id":"([^"]+)"/', $json, $ids);
        foreach ($ids[1] as $id) {
            $this->assertLessThanOrEqual(100, strlen($id), "{$id} is too long for Discord.");
        }
        preg_match('/pocket:page:([^:"]+):3/', $json, $next);
        $this->assertSame($query, $decode($next[1]), 'The next page runs the same query.');
        $this->assertNull($decode('not-a-query'));

        $unicode = ['player' => '1', 'set' => '', 'color' => '', 'rarity' => '', 'name' => mb_strcut('Æther ünïcödé names, cut short', 0, 24)];
        $this->assertSame($unicode, $decode($encode($unicode)));
        $longest = $encode(['player' => '11692725014586982600', 'set' => 'ABCDEFGH', 'color' => 'M', 'rarity' => 'mythic', 'name' => $unicode['name']]);
        $this->assertLessThanOrEqual(100, strlen("pocket:page:{$longest}:999"), 'The longest query still fits in a custom id.');
    }

    public function testShopMessages(): void
    {
        $this->importPool('TST', ['R' => self::fullColor()], '2026-06-01');
        $shop = $this->pocket->shop;
        $this->pocket->inventories->addCards('116927250145869826', ['TST-R-common-1' => 9, 'TST-R-rare-1' => 1]);

        $balance = json_encode(ShopMessageBuilder::balance(0, $shop->prices, $shop->packPrices()), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('You have **0 points**', $balance);
        $this->assertStringContainsString('×2 up to 1 year old, ×1.5 up to 3 years old, ×1 up to 10 years old, ×0.75 older', $balance);
        $this->assertStringContainsString('`TST` Set TST — 1,000 points', $balance);

        $quote = json_encode(ShopMessageBuilder::quote($shop->quote('TST-R-rare-1'), 1, 0), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Buy for **600 points** · sell for **120 points**', $quote);

        $extras = ShopMessageBuilder::extras('116927250145869826', $shop->extras('116927250145869826', 4, 'common', 'TST'), $this->pocket->trades->cardData(...), 4, 'common', 'TST');
        $json = json_encode($extras, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Sell 5 cards for 40 points?', $json);
        $this->assertStringContainsString('"custom_id":"pocket:sellx:116927250145869826:4:c:TST"', $json);
        $this->assertLessThanOrEqual(100, strlen(ShopMessageBuilder::sellExtrasId('11692725014586982600', 99, 'uncommon', 'ABCDEFGH')));

        $receipt = json_encode(ShopMessageBuilder::receipt($shop->sellExtras('116927250145869826', 'Val', 4, 'common', 'TST'), true), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Sold 5 cards for 40 points', $receipt);
        $this->assertStringContainsString('You now have 40 points.', $receipt);

        $bought = json_encode(PocketMessageBuilder::pack(new \MTGPocket\Packs\OpenedPack($this->pocket->dailyPacks->open('2', 'Ana')->pack, [], null, 1000, 50)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('bought for 1,000 points · 50 points left', $bought);
    }

    public function testTradeMessages(): void
    {
        $this->importPool('TST', ['R' => self::fullColor()]);
        $this->pocket->inventories->addCards('116927250145869826', ['TST-R-rare-1' => 1]);
        $this->pocket->inventories->addCards('2', ['TST-R-mythic-1' => 1]);
        $trades = $this->pocket->trades;
        $offer = $trades->offer('116927250145869826', 'Val', '2', 'Ana', ['TST-R-rare-1' => 1], ['TST-R-mythic-1' => 1]);

        $json = json_encode(TradeMessageBuilder::offer($offer, $trades->cardData(...)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('<@2>, **Val** offers you a trade.', $json);
        $this->assertStringContainsString('**Val gives**\n🟡 **TST R rare 1**', str_replace('×1 ', '', $json));
        $this->assertStringContainsString('"users":["2"]', $json, 'Only the other player is mentioned.');
        $this->assertStringContainsString('"custom_id":"pocket:trade:'.$offer->id.':accept:1"', $json);
        $this->assertLessThanOrEqual(100, strlen(TradeMessageBuilder::id($offer, 'decline')));

        $list = json_encode(TradeMessageBuilder::list('2', $trades->forPlayer('2'), $trades->cardData(...)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('From **Val**: 1 card for 1 card', $list);
        $this->assertStringContainsString('"custom_id":"pocket:tradeview"', $list);

        $done = json_encode(TradeMessageBuilder::closed($trades->accept($offer->id, '2', 'Ana', 1), $trades->cardData(...)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('**Ana** accepted **Val**\'s offer.', $done);
    }

    public function testDraftMessages(): void
    {
        $this->importPool('TST', ['R' => self::fullColor(), 'G' => self::fullColor()]);
        $drafts = $this->pocket->drafts;
        foreach (['116927250145869826' => 'Val', '2' => 'Ana'] as $id => $name) {
            $this->pocket->players->findOrCreate((string) $id, $name);
            $this->pocket->players->modify((string) $id, fn (\MTGPocket\Models\Player $player) => $player->points = $drafts->rules->entryFee);
        }
        $draft = $drafts->create('116927250145869826', 'Val', 'TST', '1');

        $pod = json_encode(DraftMessageBuilder::pod($draft, $drafts->rules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Set TST booster draft', $pod);
        $this->assertStringContainsString('taking players · 1/8 players · 1,200 points to enter', $pod);
        $this->assertStringContainsString('Val · host', $pod);
        $this->assertStringContainsString('"custom_id":"pocket:dj:'.$draft->id.'"', $pod);

        $drafts->join('2', 'Ana');
        $draft = $drafts->start('116927250145869826');
        $seat = $draft->seat('2');
        $pack = json_encode(DraftMessageBuilder::draftPack($draft, $seat, $drafts->packCards($draft, '2'), $drafts->pickDeadline($draft, '2')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Pack 1 · pick 1', $pack);
        $this->assertStringContainsString('15 cards left', $pack);
        $this->assertStringContainsString('"custom_id":"pocket:dp:'.$draft->id.':0"', $pack);
        $this->assertLessThanOrEqual(100, strlen("pocket:dp:{$draft->id}:999"));

        $draft = $drafts->pick('2', $draft->packFor(1)[0], 0);
        $waiting = json_encode(DraftMessageBuilder::draftPack($draft, $draft->seat('2'), $drafts->packCards($draft, '2'), null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Pack 1 · waiting', $waiting);

        $pool = json_encode(DraftMessageBuilder::pool($draft, $draft->seat('2'), $this->pocket->deckBuilder->cardData(...), ['Too small.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('deck 0 · side deck 1 · drafted 1', $pool);

        $news = json_encode(DraftMessageBuilder::announcement($draft, $draft->news), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('has started with 2 players: <@116927250145869826>, <@2>', $news);
        $this->assertStringContainsString('"users":["116927250145869826","2"]', $news);
    }

    private function assertDescription(string $description, string $where): void
    {
        $this->assertGreaterThanOrEqual(1, mb_strlen($description), "{$where} needs a description.");
        $this->assertLessThanOrEqual(100, mb_strlen($description), "{$where}'s description is too long.");
    }

    private function assertOptions(array $options, string $where): void
    {
        $this->assertLessThanOrEqual(25, count($options), "{$where} has more than 25 options.");

        $optional = false;
        foreach ($options as $option) {
            $option = (array) $option;
            $name = "{$where} {$option['name']}";

            $this->assertMatchesRegularExpression('/^[-_\p{Ll}\p{N}]{1,32}$/u', $option['name']);
            $this->assertDescription($option['description'] ?? '', $name);

            if (in_array($option['type'], [Option::SUB_COMMAND, Option::SUB_COMMAND_GROUP], true)) {
                $this->assertOptions($option['options'] ?? [], $name);
                continue;
            }

            if (! empty($option['required'])) {
                $this->assertFalse($optional, "{$name}: required options must come first.");
            } else {
                $optional = true;
            }

            $choices = (array) ($option['choices'] ?? []);
            $this->assertLessThanOrEqual(25, count($choices));
            $this->assertFalse(! empty($choices) && ! empty($option['autocomplete']), "{$name}: choices or autocomplete, not both.");
        }
    }

    private static function characters(array $command): int
    {
        $count = mb_strlen($command['name'] ?? '') + mb_strlen($command['description'] ?? '');
        foreach ((array) ($command['options'] ?? []) as $option) {
            $count += self::characters((array) $option);
            foreach ((array) (((array) $option)['choices'] ?? []) as $choice) {
                $count += mb_strlen((string) ((array) $choice)['name']) + mb_strlen((string) ((array) $choice)['value']);
            }
        }

        return $count;
    }
}
