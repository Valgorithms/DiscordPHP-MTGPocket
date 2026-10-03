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
            $builder->create($id, $name, 'Gruul');
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

        $this->pocket->deckBuilder->create(self::ALICE, 'Alice', 'Tiny');
        $this->assertException(fn () => $matches->challenge(self::ALICE, 'Alice', self::BOB, 'Bob', 'Tiny'), 'a match needs at least 40');

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

    private function assertException(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected an error containing \"{$message}\".");
        } catch (\InvalidArgumentException|\OutOfBoundsException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
