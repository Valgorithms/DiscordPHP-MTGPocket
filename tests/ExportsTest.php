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
use MTGPocket\Exports\Exporter;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Deck;
use MTGPocket\Modules\Exports;

/**
 * Decks and collections exported for MTG Arena, Tabletop Simulator and Frogtown.
 *
 * @covers \MTGPocket\Exports\Exporter
 * @covers \MTGPocket\Modules\Exports
 */
final class ExportsTest extends PocketTestCase
{
    /**
     * Cards of every shape an export has to name.
     *
     * @var array<string, array>
     */
    private const array CARDS = [
        'bolt' => ['uuid' => 'bolt', 'name' => 'Lightning Bolt', 'setCode' => 'M10', 'number' => '146', 'type' => 'Instant', 'layout' => 'normal', 'scryfallId' => 'abcdef01-0000-0000-0000-000000000000'],
        'delver' => ['uuid' => 'delver', 'name' => 'Delver of Secrets // Insectile Aberration', 'setCode' => 'ISD', 'number' => '51', 'type' => 'Creature — Human Wizard', 'layout' => 'transform', 'scryfallId' => '11bf83bb-0000-0000-0000-000000000000'],
        'fireice' => ['uuid' => 'fireice', 'name' => 'Fire // Ice', 'setCode' => 'MH2', 'number' => '290', 'type' => 'Instant // Instant', 'layout' => 'split', 'scryfallId' => '22cc0000-0000-0000-0000-000000000000'],
        'isamaru' => ['uuid' => 'isamaru', 'name' => 'Isamaru, Hound of Konda', 'setCode' => 'CHK', 'number' => '19', 'type' => 'Legendary Creature — Dog', 'layout' => 'normal', 'scryfallId' => '33dd0000-0000-0000-0000-000000000000'],
    ];

    private static function exporter(): Exporter
    {
        return new Exporter(fn (string $key) => BasicLands::card($key) ?? self::CARDS[$key]);
    }

    private static function deck(): Deck
    {
        return new Deck(
            'd1',
            '1',
            'Mono Red: Burn!',
            'commander',
            new CardCounts(['bolt' => 4, 'delver' => 2, 'fireice' => 1, 'basic:Mountain' => 10]),
            new CardCounts(['bolt' => 1]),
            commander: 'isamaru',
        );
    }

    public function testArenaTextHasCommanderDeckAndSideboard(): void
    {
        [$name, $text] = self::exporter()->deck(self::deck(), 'arena');

        $this->assertSame('Mono-Red-Burn-arena.txt', $name);
        $this->assertSame(
            "Commander\n1 Isamaru, Hound of Konda (CHK) 19\n\n"
            ."Deck\n4 Lightning Bolt (M10) 146\n2 Delver of Secrets (ISD) 51\n1 Fire // Ice (MH2) 290\n10 Mountain\n\n"
            ."Sideboard\n1 Lightning Bolt (M10) 146\n",
            $text,
            'Arena names double-faced cards by their front, split cards in full, and basics without a set.',
        );
    }

    public function testFrogtownIsAPlainDecklist(): void
    {
        [$name, $text] = self::exporter()->deck(self::deck(), 'frogtown');

        $this->assertSame('Mono-Red-Burn.txt', $name);
        $this->assertSame(
            "1 Isamaru, Hound of Konda\n4 Lightning Bolt\n2 Delver of Secrets // Insectile Aberration\n1 Fire // Ice\n10 Mountain\n\n1 Lightning Bolt\n",
            $text,
        );
    }

    public function testTabletopSimulatorSavedObject(): void
    {
        [$name, $json] = self::exporter()->deck(self::deck(), 'tts');
        $this->assertSame('Mono-Red-Burn.json', $name);

        $objects = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['ObjectStates'];
        $this->assertCount(3, $objects, 'Main deck, side deck and commander, side by side.');
        [$main, $side, $commander] = $objects;

        $this->assertSame('DeckCustom', $main['Name']);
        $this->assertSame('Mono Red: Burn!', $main['Nickname']);
        $this->assertCount(17, $main['DeckIDs']);
        $this->assertCount(17, $main['ContainedObjects']);
        $this->assertSame(180.0, (float) $main['Transform']['rotZ'], 'Face down.');
        foreach ($main['ContainedObjects'] as $n => $card) {
            $this->assertSame($main['DeckIDs'][$n], $card['CardID']);
            $deckNumber = intdiv($card['CardID'], 100);
            $this->assertArrayHasKey($deckNumber, $main['CustomDeck']);
            $this->assertSame(Exporter::CARD_BACK, $main['CustomDeck'][$deckNumber]['BackURL']);
        }

        $faces = array_column($main['ContainedObjects'], 'Nickname');
        $this->assertSame(4, count(array_keys($faces, 'Lightning Bolt')));
        $bolt = $main['ContainedObjects'][0];
        $this->assertSame('https://cards.scryfall.io/large/front/a/b/abcdef01-0000-0000-0000-000000000000.jpg', $main['CustomDeck'][intdiv($bolt['CardID'], 100)]['FaceURL']);

        $delver = $main['ContainedObjects'][4];
        $this->assertSame('Delver of Secrets', $delver['Nickname']);
        $back = $delver['States']['2'];
        $this->assertSame('Insectile Aberration', $back['Nickname']);
        $this->assertSame('https://cards.scryfall.io/large/back/1/1/11bf83bb-0000-0000-0000-000000000000.jpg', $back['CustomDeck'][(string) intdiv($back['CardID'], 100)]['FaceURL']);

        $this->assertSame('Fire // Ice', $main['ContainedObjects'][6]['Nickname']);
        $mountain = $main['ContainedObjects'][7];
        $this->assertSame('https://api.scryfall.com/cards/named?format=image&version=large&exact=Mountain', $main['CustomDeck'][intdiv($mountain['CardID'], 100)]['FaceURL']);

        // One card is a card, not a deck.
        $this->assertSame('Card', $side['Name']);
        $this->assertSame('Lightning Bolt', $side['Nickname']);
        $this->assertSame('Card', $commander['Name']);
        $this->assertSame('Isamaru, Hound of Konda', $commander['Nickname']);
        $this->assertSame(0.0, (float) $commander['Transform']['rotZ'], 'The commander lies face up.');

        $ids = [];
        foreach ([...$main['ContainedObjects'], $side, $commander] as $card) {
            $ids[$card['CardID']] = $card['Nickname'];
        }
        $this->assertCount(6, $ids, 'Each printing has its own custom deck; the side deck\'s bolt is another.');
    }

    public function testEmptyGroupsAreLeftOut(): void
    {
        $deck = new Deck('d2', '1', 'Tiny', main: new CardCounts(['bolt' => 2]));

        $this->assertSame("Deck\n2 Lightning Bolt (M10) 146\n", self::exporter()->deck($deck, 'arena')[1]);
        $this->assertSame("2 Lightning Bolt\n", self::exporter()->deck($deck, 'frogtown')[1]);
        $this->assertCount(1, json_decode(self::exporter()->deck($deck, 'tts')[1], true)['ObjectStates']);
    }

    public function testCollectionExport(): void
    {
        [$name, $text] = self::exporter()->collection(new CardCounts(['bolt' => 3, 'fireice' => 1]), "Val's collection", 'arena');

        $this->assertSame('Val-s-collection-arena.txt', $name);
        $this->assertSame("3 Lightning Bolt (M10) 146\n1 Fire // Ice (MH2) 290\n", $text);

        $this->expectException(\InvalidArgumentException::class);
        self::exporter()->collection(new CardCounts(['bolt' => 1]), 'x', 'mtgo');
    }

    public function testExportCommands(): void
    {
        $this->importPool('TST', ['R' => ['common' => 2, 'rare' => 1], 'U' => ['common' => 1]]);
        $this->pocket->players->findOrCreate('1', 'Val');
        $this->pocket->inventories->addCards('1', ['TST-R-common-1' => 4, 'TST-R-rare-1' => 1, 'TST-U-common-1' => 2]);
        $builder = $this->pocket->deckBuilder;
        $deck = $builder->create('1', 'Val', 'Burn');
        $builder->add('1', 'Burn', 'TST-R-common-1', 4);
        $builder->add('1', 'Burn', 'Mountain', 16);
        $builder->add('1', 'Burn', 'TST-R-rare-1', 1, true);
        $module = new Exports($this->pocket);

        // The active deck, as Arena text.
        $message = $module->exportDeck('1', '', 'arena');
        $this->assertStringContainsString('**Burn** for MTG Arena (main deck 20, side deck 1)', $message->getContent());
        $this->assertSame([['Burn-arena.txt', "Deck\n4 TST R common 1 (TST) 1\n16 Mountain\n\nSideboard\n1 TST R rare 1 (TST) 1\n"]], $message->getFiles());

        // From the deck view's picker, by id.
        $message = $module->exportDeck('1', $deck->id, 'tts', true);
        $this->assertSame('Burn.json', $message->getFiles()[0][0]);
        $this->assertStringContainsString('Saved Objects', $message->getContent());
        $this->assertStringContainsString('no longer exists', json_encode($module->exportDeck('1', 'gone', 'arena', true)));
        $this->assertStringContainsString('Pick a format', json_encode($module->exportDeck('1', 'Burn', 'mtgo')));

        // The collection, filtered like /collection.
        $message = $module->exportCollection('1', 'frogtown', ['color' => 'R']);
        $this->assertSame([["Val-s-collection-Red.txt", "1 TST R rare 1\n4 TST R common 1\n"]], $message->getFiles());
        $this->assertStringContainsString('5 cards, 2 different', $message->getContent());
        $this->assertSame("2 TST U common 1\n", $module->exportCollection('1', 'frogtown', ['color' => 'U'])->getFiles()[0][1]);
        $this->assertStringContainsString('no cards to export', json_encode($module->exportCollection('2', 'arena')));
    }
}
