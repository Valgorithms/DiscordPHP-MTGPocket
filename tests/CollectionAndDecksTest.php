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

/**
 * @covers \MTGPocket\Collection\CollectionQuery
 * @covers \MTGPocket\Decks\DeckBuilder
 * @covers \MTGPocket\Cards\BasicLands
 * @covers \MTGPocket\Repository\DeckRepository
 */
final class CollectionAndDecksTest extends PocketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importPool('NEW', ['R' => ['common' => 2, 'rare' => 1], 'W' => ['mythic' => 1]], '2024-01-01');
        $this->importPool('OLD', ['G' => ['common' => 1, 'uncommon' => 1]], '2010-01-01');
        $this->pocket->inventories->addCards('1', [
            'NEW-R-common-1' => 4,
            'NEW-R-common-2' => 1,
            'NEW-R-rare-1' => 2,
            'NEW-W-mythic-1' => 1,
            'OLD-G-common-1' => 3,
            'OLD-G-uncommon-1' => 1,
            'gone-from-every-pool' => 1,
        ]);
    }

    public function testListsTheCollectionSortedAndFiltered(): void
    {
        $inventory = $this->pocket->inventories->get('1');
        $names = fn (array $entries) => array_map(fn (array $entry) => $entry['card']['name'].' ×'.$entry['count'], $entries);

        // Oldest set first, then W U B R G M C, rarest first, then name.
        $this->assertSame([
            'OLD G uncommon 1 ×1',
            'OLD G common 1 ×3',
            'NEW W mythic 1 ×1',
            'NEW R rare 1 ×2',
            'NEW R common 1 ×4',
            'NEW R common 2 ×1',
        ], $names($this->pocket->collection->entries($inventory)));

        $this->assertSame(['NEW R rare 1 ×2', 'NEW R common 1 ×4', 'NEW R common 2 ×1'], $names($this->pocket->collection->entries($inventory, ['set' => 'new', 'color' => 'r'])));
        $this->assertSame(['OLD G common 1 ×3', 'NEW R common 1 ×4', 'NEW R common 2 ×1'], $names($this->pocket->collection->entries($inventory, ['rarity' => 'Common'])));
        $this->assertSame(['NEW R common 2 ×1'], $names($this->pocket->collection->entries($inventory, ['name' => 'common 2'])));

        $this->assertSame(['common' => 8, 'uncommon' => 1, 'rare' => 2, 'mythic' => 1], $this->pocket->collection->rarityTotals($inventory));
    }

    public function testBuildsDecksFromOwnedCards(): void
    {
        $builder = $this->pocket->deckBuilder;
        $deck = $builder->create('1', 'Val', 'Red Deck Wins', 'standard');

        $this->assertSame($deck->id, $this->pocket->players->find('1')->activeDeckId, 'The first deck becomes the active one.');

        [$deck] = $builder->add('1', 'red deck wins', 'NEW-R-common-1', 3);
        [$deck] = $builder->add('1', $deck->id, 'NEW R common 1', 1, true);
        [$deck, $card] = $builder->add('1', $deck->id, 'mountain', 20);

        $this->assertSame('Mountain', $card['name']);
        $this->assertSame(['NEW-R-common-1' => 3, 'basic:Mountain' => 20], $deck->main->toArray());
        $this->assertSame(['NEW-R-common-1' => 1], $deck->side->toArray());

        try {
            $builder->add('1', $deck->id, 'NEW-R-common-1');
            $this->fail('Main and side together may not use more copies than are owned.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('You own 4 copies of **NEW R common 1**, and this deck already uses 4.', $e->getMessage());
        }

        // The same cards can go in another deck too.
        $builder->create('1', 'Val', 'Second');
        [$second] = $builder->add('1', 'Second', 'NEW-R-common-1', 4);
        $this->assertSame(4, $second->main->get('NEW-R-common-1'));

        [$deck] = $builder->remove('1', 'Red Deck Wins', 'Mountain', 5);
        [$deck] = $builder->remove('1', 'Red Deck Wins', 'NEW R common 1', null, true);
        $this->assertSame(15, $deck->main->get('basic:Mountain'));
        $this->assertSame(0, $deck->side->total());

        $this->assertSame($deck->main->toArray(), $this->pocket->decks->find('1', $deck->id)->main->toArray(), 'Edits are saved.');
    }

    /**
     * @dataProvider mistakes
     */
    public function testExplainsMistakes(callable $mistake, string $message): void
    {
        $this->pocket->deckBuilder->create('1', 'Val', 'Mine');

        try {
            $mistake($this->pocket->deckBuilder);
            $this->fail('Expected a mistake.');
        } catch (\InvalidArgumentException|\OutOfBoundsException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public static function mistakes(): array
    {
        return [
            'unknown deck' => [fn ($b) => $b->add('1', 'Nope', 'Mountain'), 'no deck called **Nope**'],
            'unowned card' => [fn ($b) => $b->add('1', 'Mine', 'Black Lotus'), "don't own **Black Lotus**"],
            'card owned by someone else' => [fn ($b) => $b->add('2', 'Mine', 'Mountain'), 'no deck called'],
            'zero copies' => [fn ($b) => $b->add('1', 'Mine', 'Mountain', 0), 'at least one'],
            'removing what is not there' => [fn ($b) => $b->remove('1', 'Mine', 'Island'), 'has no **Island**'],
            'removing too many' => [function ($b) {
                $b->add('1', 'Mine', 'Island', 2);
                $b->remove('1', 'Mine', 'Island', 3);
            }, 'has only 2 **Island**'],
            'duplicate name' => [fn ($b) => $b->create('1', 'Val', ' mine '), 'already have a deck called **Mine**'],
            'empty name' => [fn ($b) => $b->create('1', 'Val', '   '), '1 to 50 characters'],
            'renaming onto another deck' => [function ($b) {
                $b->create('1', 'Val', 'Other');
                $b->rename('1', 'Other', 'MINE');
            }, 'already have a deck called **Mine**'],
            'unknown format' => [fn ($b) => $b->create('1', 'Val', 'Other', 'vintage'), 'format must be one of'],
        ];
    }

    public function testRenamesReformatsActivatesAndDeletes(): void
    {
        $builder = $this->pocket->deckBuilder;
        $first = $builder->create('1', 'Val', 'First');
        $second = $builder->create('1', 'Val', 'Second');

        $this->assertSame('Renamed', $builder->rename('1', 'First', 'Renamed')->name);
        $this->assertSame('renamed', $builder->rename('1', 'Renamed', 'renamed')->name, 'A deck can change the case of its own name.');
        $this->assertSame('commander', $builder->setFormat('1', $second->id, 'Commander')->format);

        $builder->activate('1', 'Val', 'Second');
        $this->assertSame($second->id, $builder->activeDeckId('1'));

        $builder->delete('1', 'Second');
        $this->assertNull($builder->activeDeckId('1'));
        $this->assertSame([$first->id], array_keys($builder->list('1')));
    }

    public function testSuggestsBasicLandsThenOwnedCards(): void
    {
        $this->assertSame(['basic:Island' => 'Island (basic land)'], $this->pocket->deckBuilder->suggestCards('1', 'isl'));
        $this->assertSame([
            'NEW-R-common-1' => 'NEW R common 1 (NEW) ×4',
            'NEW-R-common-2' => 'NEW R common 2 (NEW) ×1',
        ], $this->pocket->deckBuilder->suggestCards('1', 'r common'));
        $this->assertSame('Basic Land — Forest', BasicLands::card('basic:Forest')['type']);
        $this->assertNull(BasicLands::key('Snow-Covered Forest'));
    }
}
