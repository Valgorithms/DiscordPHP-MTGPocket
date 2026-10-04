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

use Discord\Builders\MessageBuilder;
use MTGPocket\Builders\MenuMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Drafts\Draft;
use MTGPocket\Drafts\DraftRules;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Models\Player;
use MTGPocket\Modules\Menu;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Panels\Modal;
use MTGPocket\Panels\PanelResult;
use MTGPocket\Panels\Panels;
use MTGPocket\Pocket;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Playing the whole game from panels: buttons, menus and forms, without
 * typing a slash command's arguments.
 *
 * @covers \MTGPocket\Panels\Panels
 * @covers \MTGPocket\Panels\Modal
 * @covers \MTGPocket\Panels\PanelResult
 * @covers \MTGPocket\Builders\MenuMessageBuilder
 * @covers \MTGPocket\Builders\DraftMessageBuilder
 * @covers \MTGPocket\Modules\Menu
 */
final class PanelsTest extends PocketTestCase
{
    private const string ALICE = '111111111111111111';
    private const string BOB = '222222222222222222';

    private Panels $panels;

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pocket = new Pocket(
            $this->directory,
            new PackGenerator(new Randomizer(new Xoshiro256StarStar(3))),
            fn () => $this->now,
            fn () => sprintf('%012x%020x', ++$this->seed, $this->seed),
            draftRules: new DraftRules(entryFee: 100, podSize: 2, minPlayers: 2, packs: 1, pickSeconds: 60, buildMinutes: 30, deckMin: 10, roundHours: 2, signupHours: 12, eventDays: 3),
        );
        $this->panels = new Panels($this->pocket);

        // A set with rules data for games and full colors for packs.
        $pool = new CardPool('TST', 'Test Set', '2024-01-01');
        foreach (['R', 'G'] as $color) {
            foreach (self::fullColor() as $rarity => $size) {
                for ($n = 1; $n <= $size; $n++) {
                    $pool->add([
                        'uuid' => "TST-{$color}-{$rarity}-{$n}",
                        'name' => "{$color} {$rarity} bear {$n}",
                        'number' => (string) $n,
                        'rarity' => $rarity,
                        'colors' => [$color],
                        'manaValue' => 2.0,
                        'type' => 'Creature — Bear',
                        'manaCost' => "{1}{{$color}}",
                        'power' => '2',
                        'toughness' => '2',
                    ]);
                }
            }
        }
        $this->pocket->pools->save($pool);

        foreach ([self::ALICE => 'Alice', self::BOB => 'Bob'] as $id => $name) {
            $id = (string) $id;
            $this->pocket->players->findOrCreate($id, $name);
            $this->pocket->players->modify($id, fn (Player $player) => $player->points = 5000);
        }
    }

    /**
     * Clicks a panel's button or menu (or sends its form) as a player.
     *
     * @param string                         $player
     * @param string                         $action With its args, e.g. `deck:d1234`.
     * @param string[]                       $values
     * @param array<string, string|string[]> $fields
     * @param array<string, string>          $names
     *
     * @return PanelResult
     */
    private function click(string $player, string $action, array $values = [], array $fields = [], array $names = []): PanelResult
    {
        $result = $this->panels->handle("pocket:ui:{$player}:{$action}", $player, $player === self::ALICE ? 'Alice' : 'Bob', $values, $fields, '999', $names);
        foreach ([$result->panel, $result->announce] as $message) {
            if ($message !== null) {
                $this->assertFits($message);
            }
        }

        return $result;
    }

    private static function json(?MessageBuilder $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Asserts a message keeps to Discord's limits for Components V2.
     *
     * @param MessageBuilder $message
     *
     * @return void
     */
    private function assertFits(MessageBuilder $message): void
    {
        $json = self::json($message);
        $this->assertLessThanOrEqual(40, MenuMessageBuilder::componentCount($message), "Too many components:\n{$json}");
        preg_match_all('/"custom_id":"([^"]+)"/', $json, $ids);
        foreach ($ids[1] as $id) {
            $this->assertLessThanOrEqual(100, strlen($id), "{$id} is too long.");
        }
        $this->assertSame(count($ids[1]), count(array_unique($ids[1])), "Custom ids repeat:\n{$json}");
        $data = json_decode($json, true);
        $text = 0;
        array_walk_recursive($data, function ($value, $key) use (&$text): void {
            if ($key === 'content') {
                $text += mb_strlen((string) $value);
            }
        });
        $this->assertLessThanOrEqual(4000, $text);
        foreach ($data['components'] ?? [] as $component) {
            foreach ($component['components'] ?? [] as $child) {
                $this->assertLessThanOrEqual(25, count($child['options'] ?? []));
            }
        }
    }

    public function testTheHomePanelLeadsEverywhere(): void
    {
        $json = self::json($this->click(self::ALICE, 'home')->panel);
        $this->assertStringContainsString("Alice's Pocket", $json);
        $this->assertStringContainsString('5,000 points', $json);
        $this->assertStringContainsString('Your free pack is **ready**', $json);
        $this->assertStringContainsString('No deck yet', $json);
        foreach (array_keys(MenuMessageBuilder::SECTIONS) as $section) {
            $this->assertStringContainsString('"custom_id":"pocket:ui:'.self::ALICE.":{$section}\"", $json);
            $panel = $this->click(self::ALICE, $section)->panel;
            $this->assertNotNull($panel, $section);
            $this->assertStringContainsString('pocket:ui:'.self::ALICE.':home', self::json($panel), "{$section} leads back home.");
        }

        $old = $this->click(self::ALICE, 'no-such-action')->panel;
        $this->assertStringContainsString('older version', self::json($old));
        $this->assertStringContainsString('no longer works', self::json($this->panels->handle('pocket:other', self::ALICE, 'Alice')->panel));
    }

    public function testPacksArePickedAndOpenedFromMenus(): void
    {
        $json = self::json($this->click(self::ALICE, 'packs')->panel);
        $this->assertStringContainsString('🎲 Any set', $json);
        $this->assertStringContainsString('"custom_id":"pocket:ui:'.self::ALICE.':pset:"', $json);

        $json = self::json($this->click(self::ALICE, 'pset:', ['TST'])->panel);
        $this->assertStringContainsString('Test Set (TST)', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':pcolor:TST', $json);
        $this->assertStringContainsString('"value":"G"', $json, 'Only colors the set has packs of.');
        $this->assertStringNotContainsString('"value":"W"', $json);

        $json = self::json($this->click(self::ALICE, 'pcolor:TST', ['R'])->panel);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':popen:TST:R', $json);

        $opened = self::json($this->click(self::ALICE, 'popen:TST:R')->panel);
        $this->assertStringContainsString('Test Set — Red pack', $opened);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':packs:TST:R', $opened, 'The way back keeps the pick.');
        $this->assertSame(15, $this->pocket->inventories->get(self::ALICE)->cards->total());

        $again = self::json($this->click(self::ALICE, 'popen:TST:R')->panel);
        $this->assertStringContainsString('⚠️', $again);
        $this->assertStringContainsString('"disabled":true', $again, 'The free pack button waits for tomorrow.');

        $bought = self::json($this->click(self::ALICE, 'pbuy::G')->panel);
        $this->assertStringContainsString('Green pack', $bought);
        $this->assertSame(30, $this->pocket->inventories->get(self::ALICE)->cards->total());
    }

    public function testTheCollectionIsFilteredWithAForm(): void
    {
        $this->pocket->inventories->addCards(self::ALICE, ['TST-R-common-1' => 2, 'TST-G-rare-1' => 1]);
        $json = self::json($this->click(self::ALICE, 'coll')->panel);
        $this->assertStringContainsString("Alice's collection", $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':cfilt:..."', $json);

        $modal = $this->click(self::ALICE, 'cfilt:TST.R..')->modal;
        $this->assertNotNull($modal);
        $form = json_encode($modal);
        $this->assertStringContainsString('"custom_id":"pocket:ui:'.self::ALICE.':mcoll"', $form);
        $this->assertStringContainsString('"value":"TST"', $form, 'The form starts from the current filters.');
        $this->assertCount(4, $modal->fields());

        $json = self::json($this->click(self::ALICE, 'mcoll', fields: ['set' => 'tst', 'color' => ['G'], 'rarity' => ['*'], 'name' => ''])->panel);
        $this->assertStringContainsString('TST · Green', $json);
        $this->assertStringContainsString('G rare bear 1', $json);
        $this->assertStringNotContainsString('R common bear 1', $json);
    }

    public function testADeckIsBuiltWithoutTypingCommands(): void
    {
        $this->pocket->inventories->addCards(self::ALICE, ['TST-R-common-1' => 4, 'TST-R-common-2' => 2, 'TST-G-rare-1' => 1]);

        $modal = $this->click(self::ALICE, 'dnew')->modal;
        $this->assertStringContainsString('"custom_id":"pocket:ui:'.self::ALICE.':mnew"', json_encode($modal));
        $made = $this->click(self::ALICE, 'mnew', fields: ['name' => 'Fire', 'format' => ['casual']]);
        $deck = $this->pocket->deckBuilder->find(self::ALICE, 'Fire');
        $this->assertSame('casual', $deck->format);
        $json = self::json($made->panel);
        $this->assertStringContainsString('Started **Fire**', $json);
        foreach (['dbrowse', 'dtype', 'dremv', 'dren', 'dfmt', 'dlands', 'ddel'] as $action) {
            $this->assertStringContainsString("pocket:ui:".self::ALICE.":{$action}:{$deck->id}", $json);
        }

        // Pick cards from a menu of the ones with copies to spare.
        $json = self::json($this->click(self::ALICE, "dbrowse:{$deck->id}:0:")->panel);
        $this->assertStringContainsString('3 cards with copies to spare', $json);
        $this->assertStringContainsString('"value":"TST-R-common-1"', $json);
        $json = self::json($this->click(self::ALICE, "dqadd:{$deck->id}:0:", ['TST-R-common-1', 'TST-G-rare-1'])->panel);
        $this->assertStringContainsString('Added R common bear 1, G rare bear 1', $json);
        $this->assertStringContainsString('2 cards with copies to spare', $json, 'The rare has no copy left to add.');
        $json = self::json($this->click(self::ALICE, "dbcol:{$deck->id}", ['G'])->panel);
        $this->assertStringContainsString('No cards to add here', $json);

        // Or type a name and a count.
        $this->click(self::ALICE, "madd:{$deck->id}", fields: ['card' => 'R common bear 1', 'count' => '3', 'side' => ['side']]);
        $this->assertSame(3, $this->pocket->deckBuilder->find(self::ALICE, 'Fire')->side->get('TST-R-common-1'));
        $error = self::json($this->click(self::ALICE, "madd:{$deck->id}", fields: ['card' => 'R common bear 1', 'count' => 'lots', 'side' => ['main']])->panel);
        $this->assertStringContainsString('⚠️ **How many** must be a whole number', $error);
        $this->assertStringContainsString('### Fire', $error, 'A refused form shows the deck again.');

        // Basic lands from a form that starts with the current counts.
        $form = json_encode($this->click(self::ALICE, "dlands:{$deck->id}")->modal);
        $this->assertStringContainsString('"custom_id":"pocket:ui:'.self::ALICE.":mlands:{$deck->id}\"", $form);
        $this->click(self::ALICE, "mlands:{$deck->id}", fields: ['mountain' => '16', 'forest' => '2', 'plains' => '']);
        $this->click(self::ALICE, "mlands:{$deck->id}", fields: ['mountain' => '14', 'forest' => '2']);
        $deck = $this->pocket->deckBuilder->find(self::ALICE, 'Fire');
        $this->assertSame(14, $deck->main->get('basic:Mountain'));
        $this->assertSame(2, $deck->main->get('basic:Forest'));

        // Take out, re-format, rename, play with it, delete.
        $json = self::json($this->click(self::ALICE, "dqrem:{$deck->id}", ['basic:Forest'])->panel);
        $this->assertStringContainsString('Took out one Forest', $json);
        $this->click(self::ALICE, "mrem:{$deck->id}", fields: ['card' => 'Forest', 'count' => '', 'side' => ['main']]);
        $this->assertSame(0, $this->pocket->deckBuilder->find(self::ALICE, 'Fire')->main->get('basic:Forest'));
        $this->click(self::ALICE, "dfmt:{$deck->id}", ['limited']);
        $this->assertStringContainsString('"value":"Fire"', json_encode($this->click(self::ALICE, "dren:{$deck->id}")->modal));
        $this->click(self::ALICE, "mren:{$deck->id}", fields: ['name' => 'Inferno']);
        $deck = $this->pocket->deckBuilder->find(self::ALICE, 'Inferno');
        $this->assertSame('limited', $deck->format);

        $this->pocket->deckBuilder->create(self::ALICE, 'Alice', 'Other');
        $other = $this->pocket->deckBuilder->find(self::ALICE, 'Other');
        $this->click(self::ALICE, "duse:{$other->id}");
        $this->assertSame($other->id, $this->pocket->deckBuilder->activeDeckId(self::ALICE));
        $list = self::json($this->click(self::ALICE, 'decks')->panel);
        $this->assertStringContainsString('"value":"'.$deck->id.'"', $list);
        $this->assertStringContainsString('Open a deck', $list);
        $this->assertStringContainsString('### Inferno', self::json($this->click(self::ALICE, 'dopen', [$deck->id])->panel));

        $this->assertStringContainsString('Delete **Inferno**?', self::json($this->click(self::ALICE, "ddel:{$deck->id}")->panel));
        $this->assertStringContainsString('Deleted **Inferno**', self::json($this->click(self::ALICE, "ddelok:{$deck->id}")->panel));
        $this->assertCount(1, $this->pocket->deckBuilder->list(self::ALICE));

        // A deck that is gone falls back to the list.
        $this->assertStringContainsString('Your decks', self::json($this->click(self::ALICE, "duse:{$deck->id}")->panel));
    }

    public function testGamesAreStartedAndLeftFromThePlayPanel(): void
    {
        foreach ([self::ALICE => 'Alice', self::BOB => 'Bob'] as $id => $name) {
            $id = (string) $id;
            $this->pocket->inventories->addCards($id, ['TST-R-common-1' => 4, 'TST-G-common-1' => 4]);
            $this->pocket->deckBuilder->create($id, $name, 'Bears', 'casual');
            $this->pocket->deckBuilder->add($id, 'Bears', 'TST-R-common-1', 4);
            $this->pocket->deckBuilder->add($id, 'Bears', 'TST-G-common-1', 4);
            $this->pocket->deckBuilder->add($id, 'Bears', 'Mountain', 16);
            $this->pocket->deckBuilder->add($id, 'Bears', 'Forest', 16);
        }

        $json = self::json($this->click(self::ALICE, 'play')->panel);
        $this->assertStringContainsString('Playing with **Bears**', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':pqueue', $json);
        $this->assertStringContainsString('"type":5', $json, 'A menu to pick a player.');

        // A challenge goes to the channel.
        $result = $this->click(self::ALICE, 'pchal', [self::BOB], names: [self::BOB => 'Bobby']);
        $this->assertStringContainsString('<@'.self::BOB.'>, **Alice** challenges you', self::json($result->announce));
        $this->assertSame(MatchRecord::PENDING, $this->pocket->matches->current(self::ALICE)->status);
        $this->assertStringContainsString('Call it off', self::json($this->click(self::ALICE, 'pconc')->panel));
        $result = $this->click(self::ALICE, 'pconcok');
        $this->assertStringContainsString('called off the challenge', self::json($result->announce));
        $this->assertNull($this->pocket->matches->current(self::ALICE));

        // The queue: wait, leave, wait again and get matched.
        $json = self::json($this->click(self::ALICE, 'pqueue', ['casual'])->panel);
        $this->assertStringContainsString('You are in the Casual queue', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':pleave', $json);
        $this->assertStringContainsString('left the Casual queue', self::json($this->click(self::ALICE, 'pleave')->panel));
        $this->click(self::ALICE, 'pqueue', ['casual']);
        $result = $this->click(self::BOB, 'pqueue', ['casual']);
        $this->assertStringContainsString('Found an opponent', self::json($result->panel));
        $this->assertStringContainsString('Your hand & actions', self::json($result->announce));
        $this->assertStringContainsString('pocket:ui:'.self::BOB.':pboard', self::json($this->click(self::BOB, 'play')->panel));
        $this->assertNotNull($this->click(self::BOB, 'pboard')->announce);

        // Conceding needs a second click.
        $this->assertStringContainsString('Concede your game?', self::json($this->click(self::BOB, 'pconc')->panel));
        $result = $this->click(self::BOB, 'pconcok');
        $this->assertStringContainsString('Game over', self::json($result->announce));
        $log = $this->click(self::BOB, 'plog');
        $this->assertTrue($log->separate, 'The record comes as its own message.');
        $this->assertStringContainsString('Casual ladder', self::json($this->click(self::BOB, 'pladder', ['casual'])->panel));
    }

    public function testTradesAreOfferedWithForms(): void
    {
        $this->pocket->inventories->addCards(self::ALICE, ['TST-R-common-1' => 3]);
        $this->pocket->inventories->addCards(self::BOB, ['TST-G-rare-1' => 1]);

        $json = self::json($this->click(self::ALICE, 'trades')->panel);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':tnew', $json);
        $this->assertStringContainsString('cannot trade with yourself', self::json($this->click(self::ALICE, 'tnew', [self::ALICE])->panel));

        $modal = $this->click(self::ALICE, 'tnew', [self::BOB], names: [self::BOB => 'Bøbby'])->modal;
        $this->assertSame('Trade with Bøbby', $modal->title);
        $this->assertCount(4, $modal->fields());
        $action = substr($modal->customId, strlen('pocket:ui:'.self::ALICE.':'));

        $result = $this->click(self::ALICE, $action, fields: ['give' => "2x R common bear 1\n", 'want' => 'G rare bear 1', 'gp' => '', 'wp' => '50']);
        $offer = self::json($result->announce);
        $this->assertStringContainsString('<@'.self::BOB.'>, **Alice** offers you a trade', $offer);
        $this->assertStringContainsString('**Bøbby gives**', $offer, 'The name rides in the form id.');
        $this->assertStringContainsString('🪙 50 points', $offer);

        $sent = $this->pocket->trades->forPlayer(self::ALICE)['sent'][0];
        $json = self::json($result->panel);
        $this->assertStringContainsString('Offer sent', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':tadd', $json);

        $this->assertStringContainsString('Add to your offer', $this->click(self::ALICE, 'tadd', [$sent->id])->modal->title);
        $result = $this->click(self::ALICE, "mtadd:{$sent->id}", fields: ['give' => 'R common bear 1', 'want' => '', 'gp' => '10', 'wp' => '']);
        $this->assertStringContainsString('×3', self::json($result->announce));
        $this->assertStringContainsString('🪙 10 points', self::json($result->announce));
        $this->assertStringContainsString('Add a card or some points', self::json($this->click(self::ALICE, "mtadd:{$sent->id}", fields: ['give' => '', 'want' => ''])->panel));

        $this->assertStringContainsString('Called off your offer to **Bøbby**', self::json($this->click(self::ALICE, 'tcancel', [$sent->id])->panel));

        $this->assertSame(['Shock' => 2, 'Bolt' => 4, 'Island' => 1, 'Llanowar Elves' => 1], Panels::cardLines("2 Shock\nBolt x4\n 1 Island ,\n\nLlanowar Elves"));
    }

    public function testTheShopWorksFromForms(): void
    {
        $json = self::json($this->click(self::ALICE, 'shop')->panel);
        foreach (['sbuy', 'ssell', 'sextra', 'sprice', 'packs'] as $action) {
            $this->assertStringContainsString('pocket:ui:'.self::ALICE.":{$action}", $json);
        }
        $this->assertSame('Buy a card', $this->click(self::ALICE, 'sbuy')->modal->title);

        $json = self::json($this->click(self::ALICE, 'mbuy', fields: ['card' => 'R common bear 1', 'count' => '6'])->panel);
        $this->assertStringContainsString('R common bear 1', $json);
        $this->assertSame(6, $this->pocket->inventories->get(self::ALICE)->cards->get('TST-R-common-1'));

        $this->click(self::ALICE, 'msell', fields: ['card' => 'R common bear 1', 'count' => '1']);
        $this->assertSame(5, $this->pocket->inventories->get(self::ALICE)->cards->get('TST-R-common-1'));

        $json = self::json($this->click(self::ALICE, 'mextra', fields: ['keep' => '', 'rarity' => ['*'], 'set' => ''])->panel);
        $this->assertStringContainsString('pocket:sellx:'.self::ALICE.':4::', $json);

        $this->assertStringContainsString('Buy for', self::json($this->click(self::ALICE, 'mprice', fields: ['card' => 'G rare bear 2'])->panel));
        $error = self::json($this->click(self::ALICE, 'mprice', fields: ['card' => 'Nothing Like It'])->panel);
        $this->assertStringContainsString('⚠️ No imported set has a card called', $error);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':sbuy', $error, 'A refused form shows the shop again.');
    }

    public function testADraftIsPlayedFromPanels(): void
    {
        $json = self::json($this->click(self::ALICE, 'draft')->panel);
        $this->assertStringContainsString('No pod is open', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':dnewpod', $json);

        $result = $this->click(self::ALICE, 'dnewpod', ['TST']);
        $this->assertStringContainsString('Test Set booster draft', self::json($result->announce));
        $draft = $this->pocket->drafts->current(self::ALICE);
        $this->assertSame('999', $draft->channelId, 'The pod posts its news where it was made.');
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':dleave', self::json($result->panel));

        $json = self::json($this->click(self::BOB, 'draft')->panel);
        $this->assertStringContainsString('"value":"'.$draft->id.'"', $json);
        $this->click(self::BOB, 'djoin', [$draft->id]);
        $draft = $this->pocket->drafts->find($draft->id);
        $this->assertSame(Draft::DRAFTING, $draft->status, 'A full pod starts.');

        $json = self::json($this->click(self::ALICE, 'dpack')->panel);
        $this->assertStringContainsString('Take a card', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':draft', $json);

        $drafts = $this->pocket->drafts;
        for ($guard = 0; $draft->status === Draft::DRAFTING && $guard < 100; $guard++) {
            foreach ($draft->seats as $seat) {
                if (($cards = $drafts->packCards($draft, $seat->id)) !== null) {
                    $draft = $drafts->pick($seat->id, $cards[0]['uuid'], count($seat->pickLog));
                    break;
                }
            }
        }
        $this->assertSame(Draft::BUILDING, $draft->status);

        $json = self::json($this->click(self::ALICE, 'dpool')->panel);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':dpadd', $json);
        $this->assertStringContainsString('pocket:ui:'.self::ALICE.':dpready', $json);
        $picked = array_key_first($drafts->current(self::ALICE)->seat(self::ALICE)->picks->toArray());
        $this->assertStringContainsString('Added 1', self::json($this->click(self::ALICE, 'dpadd', [(string) $picked])->panel));
        $this->assertStringContainsString('Took out 1', self::json($this->click(self::ALICE, 'dprem', [(string) $picked])->panel));
        $this->assertSame(Modal::class, get_class($this->click(self::ALICE, 'dplands')->modal));
        $this->click(self::ALICE, 'mplands', fields: ['mountain' => '5', 'forest' => '4']);
        $this->assertSame(5, $drafts->current(self::ALICE)->seat(self::ALICE)->deck->get('basic:Mountain'));
        $this->assertStringContainsString('Say how many', self::json($this->click(self::ALICE, 'mplands', fields: [])->panel));
        $this->click(self::ALICE, 'dpauto');
        $this->assertStringContainsString('Your deck is in', self::json($this->click(self::ALICE, 'dpready')->panel));

        $this->assertStringContainsString('Leave the Test Set draft?', self::json($this->click(self::BOB, 'dleave')->panel));
        $this->assertStringContainsString('You left the Test Set draft', self::json($this->click(self::BOB, 'dleaveok')->panel));
    }

    public function testSentFormsAreRead(): void
    {
        $raw = [
            ['type' => 18, 'component' => ['type' => 4, 'custom_id' => 'card', 'value' => 'Shock']],
            ['type' => 18, 'component' => ['type' => 3, 'custom_id' => 'side', 'values' => ['side']]],
            ['type' => 1, 'components' => [['type' => 4, 'custom_id' => 'count', 'value' => '2']]],
        ];
        $this->assertSame(['card' => 'Shock', 'side' => ['side'], 'count' => '2'], Menu::collectFields($raw));
    }

    public function testNamesAndNumbersFromForms(): void
    {
        $this->assertSame('Bøbby', Panels::unpackName(Panels::packName('Bøbby')));
        $this->assertSame('Player', Panels::unpackName('!!'));
        $this->assertLessThanOrEqual(100, strlen('pocket:ui:11111111111111111111:mtrade:22222222222222222222:'.Panels::packName(str_repeat('ü', 32))));
        $this->assertSame(1200, Panels::number('1,200', 0, 'Points'));
        $this->assertSame(7, Panels::number('', 7, 'Points'));
        $this->assertException(fn () => Panels::number('-3', 0, 'Points', 0), 'whole number');
    }
}
