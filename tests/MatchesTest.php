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
use MTGPocket\Cards\CardPool;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Matches\PanelActions;
use MTGPocket\Pocket;

/**
 * Challenges, the games they start, the action panel's flow, and the
 * match messages.
 *
 * @covers \MTGPocket\Matches\MatchService
 * @covers \MTGPocket\Matches\MatchRecord
 * @covers \MTGPocket\Matches\PanelActions
 * @covers \MTGPocket\Repository\MatchRepository
 * @covers \MTGPocket\Builders\MatchMessageBuilder
 */
final class MatchesTest extends PocketTestCase
{
    private const string ALICE = '111111111111111111';
    private const string BOB = '222222222222222222';

    private int $seed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pocket = new Pocket($this->directory, null, fn () => $this->now, fn () => str_pad(dechex(++$this->seed), 32, 'a', STR_PAD_LEFT));

        $pool = new CardPool('TST', 'Test Set', '2020-01-01');
        $pool->add(['uuid' => 'bears', 'name' => 'Grizzly Bears', 'rarity' => 'common', 'colors' => ['G'], 'manaValue' => 2.0, 'type' => 'Creature — Bear', 'manaCost' => '{1}{G}', 'power' => '2', 'toughness' => '2']);
        $pool->add(['uuid' => 'shock', 'name' => 'Shock', 'rarity' => 'common', 'colors' => ['R'], 'manaValue' => 1.0, 'type' => 'Instant', 'manaCost' => '{R}', 'text' => 'Shock deals 2 damage to any target.']);
        $pool->add(['uuid' => 'oldcard', 'name' => 'Old Import', 'rarity' => 'common', 'colors' => ['R'], 'manaValue' => 1.0, 'type' => 'Instant']);
        $this->pocket->pools->save($pool);

        foreach ([[self::ALICE, 'Alice'], [self::BOB, 'Bob']] as [$id, $name]) {
            $this->pocket->inventories->addCards($id, ['bears' => 4, 'shock' => 4, 'oldcard' => 1]);
            $builder = $this->pocket->deckBuilder;
            $builder->create($id, $name, 'Gruul', 'casual');
            $builder->add($id, 'Gruul', 'bears', 4);
            $builder->add($id, 'Gruul', 'shock', 4);
            $builder->add($id, 'Gruul', 'Forest', 16);
            $builder->add($id, 'Gruul', 'Mountain', 16);
        }
    }

    private function startMatch(): MatchRecord
    {
        $match = $this->pocket->matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');

        return $this->pocket->matches->accept($match->id, self::BOB, 'Bob');
    }

    public function testChallengeRules(): void
    {
        $matches = $this->pocket->matches;
        $this->assertException(fn () => $matches->challenge(self::ALICE, 'Alice', self::ALICE, 'Alice'), 'cannot challenge yourself');
        $this->assertException(fn () => $matches->challenge('333', 'Carol', self::BOB, 'Bob'), 'no deck to play with');

        $this->pocket->deckBuilder->create(self::ALICE, 'Alice', 'Tiny', 'casual');
        $this->assertException(fn () => $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob', 'Tiny'), 'Casual needs at least 40');

        $this->pocket->deckBuilder->add(self::ALICE, 'Tiny', 'oldcard', 1);
        $this->pocket->deckBuilder->add(self::ALICE, 'Tiny', 'Mountain', 39);
        $this->assertException(fn () => $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob', 'Tiny'), 'rules data for **Old Import** has not been imported');

        $match = $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');
        $this->assertSame(MatchRecord::PENDING, $match->status);
        $this->assertSame('Gruul', $match->challenger()['deckName']);
        $this->assertSame($match->id, $matches->current(self::ALICE)?->id);
        $this->assertNull($matches->current(self::BOB), 'Bob is not in it until he accepts.');
        $this->assertException(fn () => $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob'), 'already in a match');
        $this->assertException(fn () => $matches->accept($match->id, self::ALICE, 'Alice'), 'Only **Bob** can accept');

        $declined = $matches->decline($match->id, self::BOB);
        $this->assertSame(MatchRecord::DECLINED, $declined->status);
        $this->assertNull($matches->current(self::ALICE));
        $this->assertException(fn () => $matches->accept($match->id, self::BOB, 'Bob'), 'no longer open');
    }

    public function testAcceptStartsTheGameAndItIsSaved(): void
    {
        $match = $this->startMatch();
        $this->assertSame(MatchRecord::PLAYING, $match->status);
        $this->assertSame(Game::MULLIGAN, $match->game->stage);
        foreach ($match->game->players as $player) {
            $this->assertCount(7, $player->hand);
            $this->assertCount(33, $player->library);
        }
        $this->assertSame($match->id, $this->pocket->matches->current(self::BOB)?->id);

        $reloaded = (new Pocket($this->directory))->matches->find($match->id);
        $this->assertSame(json_encode($match->game->toArray()), json_encode($reloaded->game->toArray()));
    }

    public function testPlayingThroughThePanel(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        $ids = [self::ALICE, self::BOB];

        // Bob mulligans once, then both keep; Bob puts one card on the bottom.
        [$match, $changed] = $panel->run($match->id, self::BOB, 'mull');
        $this->assertTrue($changed);
        $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $this->assertSame('bottom', $match->game->decision(1));
        [$match] = $panel->run($match->id, self::BOB, 'bottom', [], [(string) $match->game->players[1]->hand[0]]);
        $this->assertSame(Game::PLAYING, $match->game->stage);

        // Whoever plays first stops in their first main phase and plays a land.
        $game = $match->game;
        $active = $game->active;
        $player = $ids[$active];
        $this->assertSame(Step::PrecombatMain, $game->step);
        $land = $this->landInHand($game, $active);
        if ($land !== null) {
            [$match] = $panel->run($match->id, $player, 'play', [], [(string) $land]);
            $this->assertSame(GameObject::BATTLEFIELD, $match->game->objects[$land]->zone);
        }

        // A spell with a target is cast in steps: pick it, then its target.
        $match = $this->giveShock($match, $active);
        $shock = end($match->game->players[$active]->hand);
        [$match, $changed] = $panel->run($match->id, $player, 'play', [], [(string) $shock]);
        $this->assertFalse($changed, 'Nothing is cast until the target is picked.');
        $this->assertSame($shock, $match->choice($player)['cast']['id']);
        $panelJson = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Choose a target for Shock', $panelJson);

        $opponent = 'p:'.$match->game->opponent($active);
        [$match, $changed] = $panel->run($match->id, $player, 'tgt', [], [$opponent]);
        $this->assertTrue($changed);
        $this->assertSame([], $match->choice($player), 'The picks are cleared once it is cast.');
        $this->assertSame(GameObject::STACK, $match->game->objects[$shock]->zone);

        // Both pass: it resolves.
        while ($match->game->stack !== []) {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertSame(18, $match->game->players[$match->game->opponent($active)]->life);

        // A bad pick is explained and changes nothing.
        try {
            $panel->run($match->id, $ids[$match->game->opponent($active)], 'pass');
            $this->fail('Only the player with priority can pass.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('priority', $e->getMessage());
        }

        // Leaving concedes.
        $over = $this->pocket->matches->leave(self::BOB);
        $this->assertSame(MatchRecord::OVER, $over->status);
        $this->assertSame(0, $over->game->winner);
        $this->assertNull($this->pocket->matches->current(self::ALICE));
        $this->assertStringContainsString('🏆 **Alice** wins!', json_encode(MatchMessageBuilder::board($over), JSON_UNESCAPED_UNICODE));
    }

    public function testAttackingAndBlockingThroughThePanel(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        $ids = [self::ALICE, self::BOB];
        $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');

        // Each player gets a Grizzly Bears that can fight, then combat begins.
        $match = $this->modifyGame($match, function (Game $game) use (&$attacker, &$blocker): void {
            $attacker = $game->addCard($game->active, $this->pocket->deckBuilder->cardData('bears'), GameObject::BATTLEFIELD)->id;
            $blocker = $game->addCard($game->defender(), $this->pocket->deckBuilder->cardData('bears'), GameObject::BATTLEFIELD)->id;
            $game->setAutoPass(0, false);
            $game->setAutoPass(1, false);
        });
        $active = $match->game->active;
        while ($match->game->decision($active) !== 'attack') {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }

        [$match, $changed] = $panel->run($match->id, $ids[$active], 'atk', [], [(string) $attacker]);
        $this->assertFalse($changed);
        $json = json_encode(MatchMessageBuilder::actions($match, $ids[$active]), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Attack with 1', $json);
        [$match] = $panel->run($match->id, $ids[$active], 'atkgo');
        $this->assertArrayHasKey($attacker, $match->game->attackers);

        while ($match->game->decision($match->game->defender()) !== 'block') {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $defender = $ids[$match->game->defender()];
        [$match] = $panel->run($match->id, $defender, 'blk', [(string) $attacker], [(string) $blocker]);
        $json = json_encode(MatchMessageBuilder::actions($match, $defender), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Block Grizzly Bears 2/2 with', $json);
        [$match] = $panel->run($match->id, $defender, 'blkgo');
        $this->assertSame([$blocker => $attacker], $match->game->blockers);

        while ($match->game->step !== Step::PostcombatMain) {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertSame(GameObject::GRAVEYARD, $match->game->objects[$attacker]->zone);
        $this->assertSame(GameObject::GRAVEYARD, $match->game->objects[$blocker]->zone);
    }

    public function testAbilitiesThroughThePanel(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        $ids = [self::ALICE, self::BOB];
        $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $active = $match->game->active;
        $player = $ids[$active];
        $opponent = $match->game->opponent($active);

        $match = $this->modifyGame($match, function (Game $game) use ($active, $opponent, &$pyromancer, &$kavu, &$bears): void {
            $pyromancer = $game->addCard($active, ['uuid' => 'pyro', 'name' => 'Prodigal Pyromancer', 'type' => 'Creature — Human Wizard', 'manaCost' => '{2}{R}', 'power' => '1', 'toughness' => '1', 'text' => '{T}: This creature deals 1 damage to any target.'], GameObject::BATTLEFIELD)->id;
            $kavu = $game->addCard($active, ['uuid' => 'kavu', 'name' => 'Flametongue Kavu', 'type' => 'Creature — Kavu', 'manaCost' => '{3}{R}', 'power' => '4', 'toughness' => '2', 'text' => 'When this creature enters, it deals 4 damage to target creature.'], GameObject::HAND)->id;
            foreach (range(1, 4) as $n) {
                $game->addCard($active, $this->pocket->deckBuilder->cardData('basic:Mountain'), GameObject::BATTLEFIELD);
            }
            $bears = $game->addCard($opponent, $this->pocket->deckBuilder->cardData('bears'), GameObject::BATTLEFIELD)->id;
        });

        // An ability with a target: pick it, then its target.
        $json = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Use an ability', $json);
        $this->assertStringContainsString('"value":"'.$pyromancer.'.0"', $json);
        $this->assertStringContainsString('Prodigal Pyromancer deals 1 damage to any target.', $json);
        [$match, $changed] = $panel->run($match->id, $player, 'ability', [], ["{$pyromancer}.0"]);
        $this->assertFalse($changed);
        $this->assertStringContainsString("Choose a target for Prodigal Pyromancer's ability", json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE));
        [$match, $changed] = $panel->run($match->id, $player, 'atgt', [], ["p:{$opponent}"]);
        $this->assertTrue($changed);
        $this->assertSame([], $match->choice($player));
        $this->assertStringContainsString("Prodigal Pyromancer's ability", json_encode(MatchMessageBuilder::board($match), JSON_UNESCAPED_UNICODE));
        while ($match->game->stack !== []) {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertSame(19, $match->game->players[$opponent]->life);

        // A triggered ability's target is picked in the panel of its controller.
        [$match] = $panel->run($match->id, $player, 'play', [], [(string) $kavu]);
        while ($match->game->decision($active) !== 'trigger') {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertStringContainsString('which just triggered', json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString("to choose targets for Flametongue Kavu's ability", json_encode(MatchMessageBuilder::board($match), JSON_UNESCAPED_UNICODE));
        $this->assertException(fn () => $panel->run($match->id, $ids[$opponent], 'trig', [], ["o:{$kavu}"]), 'No ability of yours');
        [$match, $changed] = $panel->run($match->id, $player, 'trig', [], ["o:{$bears}"]);
        $this->assertTrue($changed);
        while ($match->game->stack !== []) {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertSame(GameObject::GRAVEYARD, $match->game->objects[$bears]->zone);
    }

    public function testRevealedTopCardShowsOnTheBoard(): void
    {
        $match = $this->startMatch();
        $match = $this->modifyGame($match, function (Game $game) use (&$oracle): void {
            $oracle = $game->addCard(0, ['uuid' => 'oracle', 'name' => 'Open Oracle', 'type' => 'Creature — Human Wizard', 'manaCost' => '{1}{G}', 'power' => '1', 'toughness' => '1', 'text' => 'Play with the top card of your library revealed.'], GameObject::BATTLEFIELD);
            $game->addCard(0, ['uuid' => 'giant', 'name' => 'Hill Giant', 'type' => 'Creature — Giant', 'manaCost' => '{3}{R}', 'power' => '3', 'toughness' => '3', 'text' => ''], GameObject::LIBRARY);
        });
        $this->assertSame([], $match->game->objects[$oracle->id]->definition()->unsupported);
        $this->assertStringContainsString('Top of library (revealed): Hill Giant', json_encode(MatchMessageBuilder::board($match), JSON_UNESCAPED_UNICODE));
    }

    public function testFinishedGamesKeepTheirRecordForReview(): void
    {
        $matches = $this->pocket->matches;
        $this->assertException(fn () => $matches->forReview(self::ALICE), 'not played a game yet');

        $match = $this->startMatch();
        $panel = new PanelActions($matches);
        $panel->run($match->id, self::ALICE, 'keep');
        $panel->run($match->id, self::BOB, 'keep');
        $this->assertSame($match->id, $matches->forReview(self::BOB)->id, 'A live game can be reviewed too.');
        $this->assertStringContainsString('[Result "*"]', $matches->forReview(self::BOB)->transcript());

        $over = $matches->leave(self::BOB);
        $this->assertSame(MatchRecord::OVER, $over->status);
        $this->assertSame([$match->id], array_map(fn (MatchRecord $m) => $m->id, $matches->history(self::ALICE)));
        $this->assertSame([$match->id], array_map(fn (MatchRecord $m) => $m->id, $matches->history(self::BOB)));

        $review = $matches->forReview(self::ALICE);
        $this->assertSame($match->id, $review->id, 'With no live game, the last finished one.');
        $this->assertSame($match->id, $matches->forReview('333', $match->id)->id, 'Anyone can look a game up by its id.');
        $this->assertException(fn () => $matches->forReview(self::ALICE, 'ffff'), 'no game with that id');

        $text = $review->transcript();
        $this->assertStringContainsString('[Match "'.$match->id.'"]', $text);
        $this->assertStringContainsString('[Mode "Casual (friendly)"]', $text);
        $this->assertStringContainsString('[Seat 1 deck "Gruul"]', $text);
        $this->assertStringContainsString('[Result "1-0"]', $text);
        $this->assertStringContainsString('[Termination "Bob concedes"]', $text);
        $this->assertStringContainsString('Bob: resign', $text);

        $message = MatchMessageBuilder::log($review);
        $this->assertSame([["match-{$match->id}.txt", $text]], $message->getFiles());
        $this->assertStringContainsString('1-0 after 1 turn, won by **Alice**', json_encode($message, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString("pocket:m:{$match->id}:log", json_encode(MatchMessageBuilder::board($review), JSON_UNESCAPED_UNICODE));
        $this->assertSame('vs Bob · won · 1 turn · Casual · '.gmdate('M j', $this->now), MatchMessageBuilder::historyLine($review, self::ALICE));
        $this->assertStringStartsWith('vs Alice · lost', MatchMessageBuilder::historyLine($review, self::BOB));

        // A declined challenge never had a game, so it is not in anyone's history.
        $declined = $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');
        $matches->decline($declined->id, self::BOB);
        $this->assertCount(1, $matches->history(self::ALICE));
    }

    public function testMessagesFitDiscord(): void
    {
        $match = $this->pocket->matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob');
        $challenge = json_encode(MatchMessageBuilder::challenge($match), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('<@'.self::BOB.'>, **Alice** challenges you', $challenge);
        $this->assertStringContainsString('"users":["'.self::BOB.'"]', $challenge, 'Only the opponent is mentioned.');
        $this->assertStringContainsString("pocket:m:{$match->id}:accept", $challenge);

        $match = $this->pocket->matches->accept($match->id, self::BOB, 'Bob');
        // Fill the board to check sizes.
        $match = $this->modifyGame($match, function (Game $game): void {
            foreach ([0, 1] as $seat) {
                foreach (range(1, 20) as $n) {
                    $game->addCard($seat, $this->pocket->deckBuilder->cardData($n % 2 ? 'bears' : 'basic:Forest'), GameObject::BATTLEFIELD);
                }
            }
        });

        $messages = [
            MatchMessageBuilder::board($match, true),
            MatchMessageBuilder::actions($match, self::ALICE),
            MatchMessageBuilder::actions($match, self::BOB, '⚠️ Something.'),
            MatchMessageBuilder::actions($match, '999'),
        ];
        foreach ($messages as $message) {
            $json = json_encode($message, JSON_UNESCAPED_UNICODE);
            preg_match_all('/"custom_id":"([^"]+)"/', $json, $customIds);
            foreach ($customIds[1] as $customId) {
                $this->assertLessThanOrEqual(100, strlen($customId));
            }
            preg_match_all('/"content":"((?:[^"\\\\]|\\\\.)*)"/', $json, $texts);
            $this->assertLessThanOrEqual(4000, array_sum(array_map(fn ($text) => mb_strlen(json_decode('"'.$text.'"')), $texts[1])), 'Components V2 text is limited to 4000 characters.');
        }

        $board = json_encode($messages[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Alice vs Bob', $board);
        $this->assertStringContainsString('Opening hands', $board);
        $this->assertStringContainsString('Forest ×10', $board);
        $this->assertStringContainsString('"users":["'.self::ALICE.'","'.self::BOB.'"]', $board, 'The board pings who it waits on.');
        $this->assertStringNotContainsString('Shock', $board, 'Hands stay hidden.');

        $panel = json_encode($messages[1], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Keep this hand', $panel);
        $this->assertStringContainsString("pocket:m:{$match->id}:keep", $panel);
        $this->assertStringContainsString('You are not playing in this game.', json_encode($messages[3], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Changes a saved game directly, for setting up a test.
     *
     * @param MatchRecord $match
     * @param callable    $change
     *
     * @return MatchRecord
     */
    public function testModesAndScryThroughThePanel(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        $ids = [self::ALICE, self::BOB];
        $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $active = $match->game->active;
        $player = $ids[$active];

        $charm = $opt = null;
        $match = $this->modifyGame($match, function (Game $game) use ($active, &$charm, &$opt): void {
            foreach (range(1, 2) as $i) {
                $game->addCard($active, $this->pocket->deckBuilder->cardData('basic:Mountain'), GameObject::BATTLEFIELD);
                $game->addCard($active, $this->pocket->deckBuilder->cardData('basic:Island'), GameObject::BATTLEFIELD);
            }
            $charm = $game->addCard($active, ['uuid' => 'charm', 'name' => 'Fiery Charm', 'type' => 'Instant', 'manaCost' => '{R}', 'text' => "Choose one —\n• Fiery Charm deals 2 damage to target creature.\n• Fiery Charm deals 1 damage to each opponent."], GameObject::HAND)->id;
            $opt = $game->addCard($active, ['uuid' => 'opt', 'name' => 'Opt', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => 'Scry 1. Draw a card.'], GameObject::HAND)->id;
        });

        $panelJson = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Fiery Charm — mode 2', $panelJson);
        $this->assertStringContainsString("\"{$charm}:m1\"", $panelJson);
        $this->assertStringNotContainsString("\"{$charm}:m0\"", $panelJson, 'Nothing for the first mode to target.');

        [$match, $changed] = $panel->run($match->id, $player, 'play', [], ["{$charm}:m1"]);
        $this->assertTrue($changed);
        while ($match->game->stack !== []) {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertSame(19, $match->game->players[$match->game->opponent($active)]->life);

        [$match] = $panel->run($match->id, $player, 'play', [], ["{$opt}:"]);
        while ($match->game->decision($active) !== 'scry') {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $panelJson = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Scry 1: from the top of your library', $panelJson);
        $top = $match->game->choiceAwaiting()['cards'][0];
        [$match] = $panel->run($match->id, $player, 'away', [], [(string) $top]);
        $this->assertSame($top, $match->game->players[$active]->library[0]);
        $this->assertSame('priority', $match->game->decision($active));
    }

    public function testLookAndModalTriggersThroughThePanel(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        $ids = [self::ALICE, self::BOB];
        $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $active = $match->game->active;
        $player = $ids[$active];

        $impulse = $sprite = null;
        $match = $this->modifyGame($match, function (Game $game) use ($active, &$impulse, &$sprite): void {
            foreach (range(1, 2) as $i) {
                $game->addCard($active, $this->pocket->deckBuilder->cardData('basic:Island'), GameObject::BATTLEFIELD);
                $game->addCard($active, $this->pocket->deckBuilder->cardData('basic:Forest'), GameObject::BATTLEFIELD);
            }
            $game->addCard($game->opponent($active), ['uuid' => 'thopter', 'name' => 'Ornithopter', 'type' => 'Artifact Creature — Thopter', 'manaCost' => '{0}', 'power' => '0', 'toughness' => '2', 'text' => 'Flying'], GameObject::BATTLEFIELD);
            $impulse = $game->addCard($active, ['uuid' => 'impulse', 'name' => 'Impulse', 'type' => 'Instant', 'manaCost' => '{1}{U}', 'text' => 'Look at the top four cards of your library. Put one of them into your hand and the rest on the bottom of your library in any order.'], GameObject::HAND)->id;
            $sprite = $game->addCard($active, ['uuid' => 'sprite', 'name' => 'Mode Sprite', 'type' => 'Creature — Faerie', 'manaCost' => '{1}{G}', 'power' => '2', 'toughness' => '2', 'text' => "When this creature enters, choose one —\n• Draw a card.\n• Destroy target artifact."], GameObject::HAND)->id;
        });

        [$match] = $panel->run($match->id, $player, 'play', [], ["{$impulse}:"]);
        while ($match->game->decision($active) !== 'look') {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $panelJson = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Choose 1 to put into your hand', $panelJson);
        $top = $match->game->choiceAwaiting()['cards'][0];
        [$match] = $panel->run($match->id, $player, 'take', [], [(string) $top]);
        $this->assertContains($top, $match->game->players[$active]->hand);

        while ($match->game->stack !== []) {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        [$match] = $panel->run($match->id, $player, 'play', [], ["{$sprite}:"]);
        while ($match->game->decision($active) !== 'mode') {
            [$match] = $panel->run($match->id, $ids[$match->game->priority], 'pass');
        }
        $this->assertStringContainsString('choose modes for Mode Sprite', MatchMessageBuilder::waiting($match)['text']);
        $panelJson = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Destroy target artifact', $panelJson);
        [$match] = $panel->run($match->id, $player, 'mode', [], ['1']);
        $this->assertSame([], $match->game->choiceAwaiting() ?? []);
    }

    /** @dataProvider optionalLifeOptions */
    public function testOptionalLifeLossPanelPersistsAndRejectsWrongOrRepeatedAnswers(string $option): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        [$match] = $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $seat = $match->game->active;
        $player = $match->game->players[$seat]->id;
        $other = $match->game->players[1 - $seat]->id;
        $source = null;
        $match = $this->modifyGame($match, function (Game $game) use ($seat, &$source): void {
            $game->setAutoPass(0, false);
            $game->setAutoPass(1, false);
            for ($n = 0; $n < 5; $n++) {
                $game->addCard($seat, $this->pocket->deckBuilder->cardData('basic:Swamp'), GameObject::BATTLEFIELD);
            }
            $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/artifact-observer-disciple-of-the-vault.json'), true, flags: JSON_THROW_ON_ERROR);
            $game->addCard($seat, $fixture['card'], GameObject::BATTLEFIELD);
            $game->addCard(1 - $seat, ['name' => 'Artifact Route Unit Fixture', 'type' => 'Artifact Creature', 'power' => '2', 'toughness' => '2', 'text' => null], GameObject::BATTLEFIELD);
            $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR);
            $source = $game->addCard($seat, $fixture['cards']['Damnation']['card'], GameObject::HAND)->id;
        });
        [$match] = $panel->run($match->id, $player, 'play', [], [(string) $source]);
        for ($n = 0; $match->game->choiceAwaiting() === null && $n < 8; $n++) {
            [$match] = $panel->run($match->id, $match->game->players[$match->game->priority]->id, 'pass');
        }
        $choice = $match->game->choiceAwaiting();
        $this->assertSame('optional_life_loss', $choice['type']);
        $this->assertStringContainsString('choose whether the targeted opponent loses 1 life', MatchMessageBuilder::waiting($match)['text']);
        $json = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Opponent loses 1 life', $json);
        $this->assertStringContainsString('Decline', $json);
        $this->assertStringContainsString('maylife:'.$choice['id'].':accept', $json);
        $this->assertStringNotContainsString('maylife:', json_encode(MatchMessageBuilder::actions($match, $other)));
        $before = $match->game->toArray();
        foreach ([[$other, (string) $choice['id'], $option], [$player, (string) ($choice['id'] + 1), $option], [$player, (string) $choice['id'], 'both']] as [$actor, $choiceId, $answer]) {
            $this->assertException(fn () => $panel->run($match->id, $actor, 'maylife', [$choiceId, $answer]), 'no longer available');
            $this->assertSame($before, $this->pocket->matches->find($match->id)->game->toArray());
        }
        [$match, $changed] = $panel->run($match->id, $player, 'maylife', [(string) $choice['id'], $option]);
        $this->assertTrue($changed);
        $this->assertSame($option === 'accept' ? 19 : 20, $match->game->players[1 - $seat]->life);
        $this->assertNull($match->game->choiceAwaiting());
        $this->assertSame($match->game->toArray(), $this->pocket->matches->find($match->id)->game->toArray());
        $this->assertException(fn () => $panel->run($match->id, $player, 'maylife', [(string) $choice['id'], $option]), 'no longer available');
        $this->assertSame($match->game->toArray(), $this->pocket->matches->find($match->id)->game->toArray());
    }

    public static function optionalLifeOptions(): array
    {
        return [['accept'], ['decline']];
    }

    /** @dataProvider fabricateOptions */
    public function testFabricatePanelPersistsAndRejectsWrongOrRepeatedAnswers(string $option): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        [$match] = $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $seat = $match->game->active;
        $player = $match->game->players[$seat]->id;
        $other = $match->game->players[1 - $seat]->id;
        $source = null;
        $match = $this->modifyGame($match, function (Game $game) use ($seat, &$source): void {
            $game->setAutoPass(0, false);
            $game->setAutoPass(1, false);
            for ($n = 0; $n < 6; $n++) {
                $game->addCard($seat, $this->pocket->deckBuilder->cardData('basic:Swamp'), GameObject::BATTLEFIELD);
            }
            $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/artifact-observer-marionette-master.json'), true, flags: JSON_THROW_ON_ERROR);
            $source = $game->addCard($seat, $fixture['card'], GameObject::HAND)->id;
        });
        [$match] = $panel->run($match->id, $player, 'play', [], [(string) $source]);
        for ($n = 0; $match->game->choiceAwaiting() === null && $n < 8; $n++) {
            [$match] = $panel->run($match->id, $match->game->players[$match->game->priority]->id, 'pass');
        }
        $choice = $match->game->choiceAwaiting();
        $this->assertSame('fabricate', $choice['type']);
        $this->assertStringContainsString('choose counters or Servos', MatchMessageBuilder::waiting($match)['text']);
        $json = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Put 3 +1/+1 counters', $json);
        $this->assertStringContainsString('Create 3 Servos', $json);
        $this->assertStringContainsString('fabricate:'.$choice['id'].':servos', $json);
        $this->assertStringNotContainsString('fabricate:', json_encode(MatchMessageBuilder::actions($match, $other)));
        $before = $match->game->toArray();
        foreach ([[$other, (string) $choice['id'], $option], [$player, (string) ($choice['id'] + 1), $option], [$player, (string) $choice['id'], 'both']] as [$actor, $choiceId, $answer]) {
            $this->assertException(fn () => $panel->run($match->id, $actor, 'fabricate', [$choiceId, $answer]), 'no longer available');
            $this->assertSame($before, $this->pocket->matches->find($match->id)->game->toArray());
        }
        [$match, $changed] = $panel->run($match->id, $player, 'fabricate', [(string) $choice['id'], $option]);
        $this->assertTrue($changed);
        $this->assertSame($option === 'counters' ? 3 : 0, $match->game->objects[$source]->counter('+1/+1'));
        $this->assertNull($match->game->choiceAwaiting());
        $this->assertSame($match->game->toArray(), $this->pocket->matches->find($match->id)->game->toArray());
        $this->assertException(fn () => $panel->run($match->id, $player, 'fabricate', [(string) $choice['id'], $option]), 'no longer available');
        $this->assertSame($match->game->toArray(), $this->pocket->matches->find($match->id)->game->toArray());
    }

    public static function fabricateOptions(): array
    {
        return [['counters'], ['servos']];
    }

    public function testEntryLifePanelPersistsItsChoiceAndRejectsWrongOrRepeatedAnswers(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        [$match] = $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $seat = $match->game->active;
        $player = $match->game->players[$seat]->id;
        $other = $match->game->players[$match->game->opponent($seat)]->id;
        $id = null;
        $match = $this->modifyGame($match, function (Game $game) use ($seat, &$id): void {
            $game->setAutoPass(0, false);
            $game->setAutoPass(1, false);
            $id = $game->addCard($seat, ['uuid' => 'pool-fixture', 'name' => 'Breeding Pool', 'type' => 'Land — Forest Island',
                'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {G} or {U}."], GameObject::HAND)->id;
        });
        [$match, $changed] = $panel->run($match->id, $player, 'play', [], [(string) $id]);
        $this->assertTrue($changed);
        $choice = $match->game->choiceAwaiting();
        $this->assertSame('entry_life', $choice['type']);
        $json = json_encode(MatchMessageBuilder::actions($match, $player), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Pay 2 life', $json);
        $this->assertStringContainsString("Don't pay", $json);
        $this->assertStringContainsString('entrylife:'.$choice['id'].':pay', $json);
        $this->assertStringNotContainsString('entrylife:', json_encode(MatchMessageBuilder::actions($match, $other)));
        $before = $match->game->toArray();
        $this->assertException(fn () => $panel->run($match->id, $other, 'entrylife', [(string) $choice['id'], 'pay']), 'no longer available');
        $this->assertSame($before, $this->pocket->matches->find($match->id)->game->toArray());
        [$match, $changed] = $panel->run($match->id, $player, 'entrylife', [(string) $choice['id'], 'pay']);
        $this->assertTrue($changed);
        $this->assertSame(18, $match->game->players[$seat]->life);
        $this->assertFalse($match->game->objects[$id]->tapped);
        $this->assertSame($match->game->toArray(), $this->pocket->matches->find($match->id)->game->toArray());
        $this->assertException(fn () => $panel->run($match->id, $player, 'entrylife', [(string) $choice['id'], 'pay']), 'no longer available');
        $this->assertSame(18, $this->pocket->matches->find($match->id)->game->players[$seat]->life);
    }

    public function testSacrificePanelKeepsPagedSelectionsAndRejectsStaleOrIllegalAnswers(): void
    {
        $match = $this->startMatch();
        $panel = new PanelActions($this->pocket->matches);
        [$match] = $panel->run($match->id, self::ALICE, 'keep');
        [$match] = $panel->run($match->id, self::BOB, 'keep');
        $seat = $match->game->active;
        $otherSeat = 1 - $seat;
        $player = $match->game->players[$seat]->id;
        $other = $match->game->players[$otherSeat]->id;
        $cards = [];
        $spell = null;
        $match = $this->modifyGame($match, function (Game $game) use ($seat, $otherSeat, &$cards, &$spell): void {
            $game->setAutoPass(0, false);
            $game->setAutoPass(1, false);
            for ($i = 0; $i < 4; $i++) {
                $game->addCard($seat, ['name' => 'Swamp', 'type' => 'Basic Land — Swamp'], GameObject::BATTLEFIELD);
            }
            foreach ([$seat => 27, $otherSeat => 3] as $controller => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $cards[$controller][] = $game->addCard($controller, ['name' => 'Panel Bear '.$i, 'type' => 'Creature — Bear', 'power' => '2', 'toughness' => '2'], GameObject::BATTLEFIELD)->id;
                }
            }
            $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/simultaneous-sacrifices.json'), true, flags: JSON_THROW_ON_ERROR);
            $spell = $game->addCard($seat, $fixture['cards']['Barter in Blood']['card'], GameObject::HAND)->id;
        });
        [$match] = $panel->run($match->id, $player, 'play', [], [(string) $spell]);
        for ($i = 0; $i < 2; $i++) {
            [$match] = $panel->run($match->id, $match->game->players[$match->game->priority]->id, 'pass');
        }
        $id = (string) $match->game->choiceAwaiting()['id'];
        $menu = $this->sacrificeComponent($match, $player, 'sacpick:'.$id.':0');
        $this->assertCount(25, $menu['options']);
        $this->assertSame([0, 2], [$menu['min_values'], $menu['max_values']]);
        $this->assertTrue($this->sacrificeComponent($match, $player, 'sacgo:'.$id)['disabled']);
        $this->assertStringNotContainsString('sacpick:', json_encode(MatchMessageBuilder::actions($match, $other)));
        $gameBefore = $match->game->toArray();
        $reject = function (string $who, string $action, array $args, array $values, string $message) use ($panel, &$match): void {
            $before = json_encode($this->pocket->matches->find($match->id)->toArray());
            $this->assertException(fn () => $panel->run($match->id, $who, $action, $args, $values), $message);
            $this->assertSame($before, json_encode($this->pocket->matches->find($match->id)->toArray()));
        };
        $reject($other, 'sacpage', [$id, '0'], [], 'no longer available');
        $reject($player, 'sacpage', [(string) ((int) $id + 1), '0'], [], 'no longer available');
        $reject($player, 'sacpage', [$id, '99'], [], 'page is unavailable');
        $reject($player, 'sacpick', [$id, '0'], [(string) $cards[$seat][26]], 'only creatures on this');
        $reject($player, 'sacgo', [$id], [], 'exactly 2 distinct');
        [$match, $changed] = $panel->run($match->id, $player, 'sacpage', [$id, '1']);
        $this->assertFalse($changed);
        $menu = $this->sacrificeComponent($match, $player, 'sacpick:'.$id.':1');
        $this->assertSame(array_map('strval', array_slice($cards[$seat], 25)), array_column($menu['options'], 'value'));
        [$match] = $panel->run($match->id, $player, 'sacpick', [$id, '1'], [(string) $cards[$seat][26]]);
        [$match] = $panel->run($match->id, $player, 'sacpick', [$id, '0'], [(string) $cards[$seat][0]]);
        $this->assertSame([$cards[$seat][26], $cards[$seat][0]], $match->choice($player)['sacrifice']['selected']);
        $reject($player, 'sacpick', [$id, '0'], [(string) $cards[$seat][0], (string) $cards[$seat][1]], '2 creatures in total');
        $reject($player, 'sacpick', [$id, '0'], [(string) $cards[$seat][0], (string) $cards[$seat][0]], 'only creatures on this');
        $menu = $this->sacrificeComponent($match, $player, 'sacpick:'.$id.':0');
        $this->assertTrue($menu['options'][0]['default']);
        $this->assertFalse($this->sacrificeComponent($match, $player, 'sacgo:'.$id)['disabled']);
        $this->assertSame($gameBefore, $match->game->toArray(), 'Staging does not move a creature or advance the game.');
        [$match] = $panel->run($match->id, $player, 'sacreset', [$id]);
        $this->assertSame([], $match->choice($player)['sacrifice']['selected']);
        $this->assertTrue($this->sacrificeComponent($match, $player, 'sacgo:'.$id)['disabled']);
        [$match] = $panel->run($match->id, $player, 'sacpick', [$id, '1'], [(string) $cards[$seat][26]]);
        [$match] = $panel->run($match->id, $player, 'sacpick', [$id, '0'], [(string) $cards[$seat][0]]);
        [$match, $changed] = $panel->run($match->id, $player, 'sacgo', [$id]);
        $this->assertTrue($changed);
        $this->assertSame([], $match->choice($player));
        $next = (string) $match->game->choiceAwaiting()['id'];
        $this->assertNotSame($id, $next);
        $nextPanel = json_encode(MatchMessageBuilder::actions($match, $other));
        $this->assertStringContainsString('Already chosen by '.$match->game->players[$seat]->name, $nextPanel);
        $this->assertStringContainsString('Panel Bear 26', $nextPanel, 'The next player sees the earlier public battlefield choices.');
        foreach (array_merge(...array_values($cards)) as $object) {
            $this->assertSame(GameObject::BATTLEFIELD, $match->game->objects[$object]->zone);
        }
        $reject($player, 'sacgo', [$id], [], 'no longer available');
        $reject($other, 'sacpick', [$id, '0'], [(string) $cards[$otherSeat][0]], 'no longer available');
        [$match] = $panel->run($match->id, $other, 'sacpick', [$next, '0'], array_map('strval', array_slice($cards[$otherSeat], 0, 2)));
        [$match] = $panel->run($match->id, $other, 'sacgo', [$next]);
        foreach ([$cards[$seat][26], $cards[$seat][0], $cards[$otherSeat][0], $cards[$otherSeat][1]] as $object) {
            $this->assertSame(GameObject::GRAVEYARD, $match->game->objects[$object]->zone);
        }
        $this->assertSame(GameObject::BATTLEFIELD, $match->game->objects[$cards[$seat][1]]->zone);
        $this->assertSame($match->game->toArray(), $this->pocket->matches->find($match->id)->game->toArray());
        $reject($other, 'sacgo', [$next], [], 'no longer available');
    }

    private function sacrificeComponent(MatchRecord $match, string $player, string $suffix): array
    {
        $find = function (array $node) use (&$find, $suffix): ?array {
            if (isset($node['custom_id']) && str_ends_with($node['custom_id'], ':'.$suffix)) {
                return $node;
            }
            foreach ($node as $child) {
                if (is_array($child) && ($found = $find($child)) !== null) {
                    return $found;
                }
            }

            return null;
        };
        $component = $find(json_decode(json_encode(MatchMessageBuilder::actions($match, $player)), true));
        $this->assertNotNull($component, 'Missing sacrifice control '.$suffix);

        return $component;
    }

    private function modifyGame(MatchRecord $match, callable $change): MatchRecord
    {
        $repository = new \MTGPocket\Repository\MatchRepository($this->pocket->store);

        return $repository->modify($match->id, fn (MatchRecord $match) => $change($match->game));
    }

    private function giveShock(MatchRecord $match, int $seat): MatchRecord
    {
        return $this->modifyGame($match, function (Game $game) use ($seat): void {
            $game->addCard($seat, $this->pocket->deckBuilder->cardData('basic:Mountain'), GameObject::BATTLEFIELD);
            $game->addCard($seat, $this->pocket->deckBuilder->cardData('shock'), GameObject::HAND);
        });
    }

    private function landInHand(Game $game, int $seat): ?int
    {
        foreach ($game->players[$seat]->hand as $id) {
            if ($game->objects[$id]->definition()->isLand()) {
                return $id;
            }
        }

        return null;
    }
}
