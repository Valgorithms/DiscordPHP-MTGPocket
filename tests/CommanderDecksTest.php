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

use MTGPocket\Builders\MatchMessageBuilder;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Cards\ColorIdentity;
use MTGPocket\Game\GameObject;
use MTGPocket\Models\Deck;
use MTGPocket\Pocket;

/**
 * Commander decks (a commander, its colors, 100 cards) and Commander games.
 *
 * @covers \MTGPocket\Cards\ColorIdentity
 * @covers \MTGPocket\Decks\DeckBuilder
 * @covers \MTGPocket\Modes\GameMode
 * @covers \MTGPocket\Matches\MatchService
 * @covers \MTGPocket\Models\Deck
 */
final class CommanderDecksTest extends PocketTestCase
{
    private const string ALICE = '111111111111111111';
    private const string BOB = '222222222222222222';

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pocket = new Pocket($this->directory, null, fn () => $this->now, fn () => str_pad(dechex(++$this->seed), 32, 'a', STR_PAD_LEFT));

        $pool = new CardPool('CMD', 'Commander Set', '2020-01-01');
        $pool->add(['uuid' => 'isamaru', 'name' => 'Isamaru, Hound of Konda', 'rarity' => 'rare', 'colors' => ['W'], 'colorIdentity' => ['W'], 'manaValue' => 1.0, 'type' => 'Legendary Creature — Dog', 'manaCost' => '{W}', 'power' => '2', 'toughness' => '2']);
        $pool->add(['uuid' => 'lions', 'name' => 'Savannah Lions', 'rarity' => 'common', 'colors' => ['W'], 'colorIdentity' => ['W'], 'manaValue' => 1.0, 'type' => 'Creature — Cat', 'manaCost' => '{W}', 'power' => '2', 'toughness' => '1']);
        $pool->add(['uuid' => 'shock', 'name' => 'Shock', 'rarity' => 'common', 'colors' => ['R'], 'colorIdentity' => ['R'], 'manaValue' => 1.0, 'type' => 'Instant', 'manaCost' => '{R}', 'text' => 'Shock deals 2 damage to any target.']);
        $this->pocket->pools->save($pool);
        foreach ([[self::ALICE, 'Alice'], [self::BOB, 'Bob']] as [$id, $name]) {
            $this->pocket->inventories->addCards($id, ['isamaru' => 1, 'lions' => 1, 'shock' => 1]);
        }
    }

    private function legalDeck(string $playerId, string $name): Deck
    {
        $builder = $this->pocket->deckBuilder;
        $builder->create($playerId, $name, 'Commander', 'commander');
        $builder->add($playerId, 'Commander', 'Savannah Lions');
        $builder->add($playerId, 'Commander', 'Plains', 98);

        return $builder->setCommander($playerId, 'Commander', 'Isamaru, Hound of Konda')[0];
    }

    public function testColorIdentity(): void
    {
        $this->assertSame(['W', 'U'], ColorIdentity::of(['name' => 'X', 'colors' => ['U'], 'manaCost' => '{1}{U}', 'text' => '{W}: Tap target creature.']));
        $this->assertSame(['B', 'G'], ColorIdentity::of(['name' => 'X', 'colorIdentity' => ['G', 'B']]));
        $this->assertSame(['G'], ColorIdentity::of(['name' => 'Forest', 'colors' => []]));
        $this->assertSame(['R'], ColorIdentity::of(['name' => 'X', 'colors' => [], 'manaCost' => '{2/R}']));
        $this->assertSame('white, blue and green', ColorIdentity::describe(['G', 'W', 'U']));
        $this->assertSame('colorless', ColorIdentity::describe([]));
    }

    public function testCommanderDeckRules(): void
    {
        $builder = $this->pocket->deckBuilder;
        $deck = $builder->create(self::ALICE, 'Alice', 'Commander', 'commander');
        $builder->add(self::ALICE, 'Commander', 'Savannah Lions');
        $deck = $builder->add(self::ALICE, 'Commander', 'Shock')[0];
        $this->assertSame([
            'The main deck has 2 cards; Commander needs exactly 100.',
            'A Commander deck needs a commander: pick a legendary creature with `/decks commander`.',
        ], $this->pocket->matches->problems($deck));

        $this->assertException(fn () => $builder->setCommander(self::ALICE, 'Commander', 'Savannah Lions'), 'not a legendary creature');
        [$deck, $card] = $builder->setCommander(self::ALICE, 'Commander', 'Isamaru, Hound of Konda');
        $this->assertSame('isamaru', $deck->commander);
        $this->assertSame('Isamaru, Hound of Konda', $card['name']);
        $deck = $builder->add(self::ALICE, 'Commander', 'Plains', 97)[0];
        $this->assertSame(100, $deck->size());
        $this->assertSame(["Cards outside **Isamaru, Hound of Konda**'s colors (white): **Shock**."], $this->pocket->matches->problems($deck));

        $deck = $builder->remove(self::ALICE, 'Commander', 'Shock')[0];
        $this->assertSame(['The deck has, with its commander, 99 cards; Commander needs exactly 100.'], $this->pocket->matches->problems($deck));
        $deck = $builder->add(self::ALICE, 'Commander', 'Plains')[0];
        $this->assertSame([], $this->pocket->matches->problems($deck));

        // The commander is one of the copies the deck uses.
        $this->assertException(fn () => $builder->add(self::ALICE, 'Commander', 'Isamaru, Hound of Konda'), 'this deck already uses 1');
        $this->assertSame(['count' => 1, 'deck' => 'Commander'], $builder->copiesInUse(self::ALICE)['isamaru']);

        $json = json_encode(PocketMessageBuilder::deck($deck, $builder->cardData(...), true, null, []), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('👑 Isamaru, Hound of Konda', $json);

        // Other formats have no commander.
        $deck = $builder->setFormat(self::ALICE, 'Commander', 'casual');
        $this->assertContains('Casual decks have no commander; take it out with `/decks commander` and no card.', $this->pocket->matches->problems($deck));
        [$deck] = $builder->setCommander(self::ALICE, 'Commander', null);
        $this->assertNull($deck->commander);
        $this->assertNull($this->pocket->decks->find(self::ALICE, $deck->id)->commander, 'Saved.');
    }

    public function testCommanderGames(): void
    {
        $this->legalDeck(self::ALICE, 'Alice');
        $this->legalDeck(self::BOB, 'Bob');
        $matches = $this->pocket->matches;
        $this->assertNull($matches->queue(self::ALICE, 'Alice')['match']);
        $match = $matches->queue(self::BOB, 'Bob')['match'];
        $this->assertSame('commander', $match->mode);

        $game = $match->game;
        $this->assertSame([40, 40], [$game->players[0]->life, $game->players[1]->life]);
        $this->assertCount(2, $game->command);
        foreach ($game->command as $id) {
            $this->assertSame(['Isamaru, Hound of Konda', GameObject::COMMAND], [$game->objects[$id]->name(), $game->objects[$id]->zone]);
        }
        $this->assertSame(99, count($game->players[0]->library) + count($game->players[0]->hand));

        $json = json_encode(MatchMessageBuilder::board($matches->find($match->id)), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('👑 Command zone: Isamaru, Hound of Konda', $json);
        $this->assertStringContainsString('Commander (ranked)', $json);
        $json = json_encode(MatchMessageBuilder::actions($matches->find($match->id), self::ALICE), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('👑 ', $json, 'The commander is listed with the hand.');
        $this->assertStringContainsString('command zone', $json);
    }
}
