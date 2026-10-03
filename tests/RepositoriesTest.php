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

use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Inventory;
use MTGPocket\Pocket;

/**
 * @covers \MTGPocket\Pocket
 * @covers \MTGPocket\Repository\PlayerRepository
 * @covers \MTGPocket\Repository\InventoryRepository
 * @covers \MTGPocket\Repository\DeckRepository
 * @covers \MTGPocket\Models\CardCounts
 * @covers \MTGPocket\Models\Player
 * @covers \MTGPocket\Models\Inventory
 * @covers \MTGPocket\Models\Deck
 */
final class RepositoriesTest extends StorageTestCase
{
    public function testRegistersPlayersOnce(): void
    {
        $pocket = new Pocket($this->directory);

        $first = $pocket->players->findOrCreate('116927250145869826', 'Val');
        $again = $pocket->players->findOrCreate('116927250145869826', 'Valithor');

        $this->assertSame($first->createdAt, $again->createdAt);
        $this->assertSame('Valithor', $pocket->players->find('116927250145869826')->name);
        $this->assertNull($pocket->players->find('2'));
        $this->assertSame(['116927250145869826'], $pocket->players->ids());
    }

    public function testRecordsTheDailyPack(): void
    {
        $pocket = new Pocket($this->directory);
        $pocket->players->findOrCreate('1', 'Chandra');

        $pocket->players->modify('1', fn ($player) => $player->lastDailyPackAt = 1_790_000_000);

        $this->assertSame(1_790_000_000, $pocket->players->find('1')->lastDailyPackAt);
    }

    public function testInventoriesAddAndRemoveCards(): void
    {
        $pocket = new Pocket($this->directory);

        $this->assertSame(0, $pocket->inventories->get('1')->cards->total());

        $pocket->inventories->addCards('1', ['uuid-a' => 2, 'uuid-b' => 1]);
        $pocket->inventories->addCards('1', ['uuid-a' => 1]);
        $inventory = $pocket->inventories->modify('1', function (Inventory $inventory): void {
            $this->assertTrue($inventory->cards->remove('uuid-b'));
            $this->assertFalse($inventory->cards->remove('uuid-a', 9));
        });

        $this->assertSame(['uuid-a' => 3], $inventory->cards->toArray());
        $this->assertSame(['uuid-a' => 3], $pocket->inventories->get('1')->cards->toArray());
    }

    public function testDecksKeepMainAndSideDecks(): void
    {
        $pocket = new Pocket($this->directory);

        $deck = $pocket->decks->create('1', 'Mono Red', 'standard');
        $deck->main->add('bolt', 4)->add('mountain', 16);
        $deck->side->add('smash', 2);
        $pocket->decks->save($deck);
        $other = $pocket->decks->create('1', 'Draft');

        $loaded = $pocket->decks->find('1', $deck->id);
        $this->assertSame('Mono Red', $loaded->name);
        $this->assertSame(20, $loaded->main->total());
        $this->assertSame(['smash' => 2], $loaded->side->toArray());
        $this->assertSame(22, $loaded->allCards()->total());
        $this->assertSame([$deck->id, $other->id], array_keys($pocket->decks->forPlayer('1')));
        $this->assertSame([], $pocket->decks->forPlayer('2'));

        $this->assertTrue($pocket->decks->delete('1', $deck->id));
        $this->assertFalse($pocket->decks->delete('1', $deck->id));
        $this->assertNull($pocket->decks->find('1', $deck->id));
    }

    public function testCardCountsCheckOwnership(): void
    {
        $owned = new CardCounts(['a' => 4, 'b' => 1]);

        $this->assertTrue($owned->contains(new CardCounts(['a' => 4])));
        $this->assertFalse($owned->contains(new CardCounts(['b' => 2])));
        $this->assertSame('{}', json_encode(new CardCounts()));
    }
}
