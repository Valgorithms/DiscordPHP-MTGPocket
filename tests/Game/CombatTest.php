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

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Declaring attackers and blockers, and combat damage with the keywords
 * that change it.
 *
 * @covers \MTGPocket\Game\Game
 */
final class CombatTest extends GameTestCase
{
    /**
     * Passes to Alice's declare-attackers step.
     *
     * @return void
     */
    private function toAttack(): void
    {
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(Step::BeginCombat, $this->game->step);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(Step::DeclareAttackers, $this->game->step);
        $this->assertSame('attack', $this->game->decision(0));
    }

    /**
     * Passes through the rest of combat to the second main phase.
     *
     * @return void
     */
    private function finishCombat(): void
    {
        $this->passUntil(Step::PostcombatMain);
    }

    public function testUnblockedAttackerHitsThePlayer(): void
    {
        $this->newGame();
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $angel = $this->battlefield(0, 'Serra Angel');
        $this->toAttack();
        $this->game->declareAttackers(0, [$bears, $angel]);

        $this->assertTrue($this->game->objects[$bears]->tapped);
        $this->assertFalse($this->game->objects[$angel]->tapped, 'Vigilance: attacking does not tap it.');
        $this->assertSame(0, $this->game->priority);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(Step::DeclareBlockers, $this->game->step);
        $this->assertSame(0, $this->game->priority, 'Bob has no creatures, so there is nothing to block with.');

        $this->finishCombat();
        $this->assertSame(14, $this->life(1));
        $this->assertSame([], $this->game->attackers, 'Combat is over.');
    }

    public function testWhoCanAttack(): void
    {
        $this->newGame();
        $wall = $this->battlefield(0, 'Wall of Stone');
        $goblin = $this->hand(0, 'Raging Goblin');
        $sick = $this->hand(0, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Forest', 2);
        $this->game->cast(0, $goblin);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->game->cast(0, $sick);
        $this->game->pass(0);
        $this->game->pass(1);

        $this->toAttack();
        $this->assertSame([$goblin], $this->game->attackCandidates(), 'Haste, yes; defender and summoning sickness, no.');
        $this->expectException(GameException::class);
        $this->game->declareAttackers(0, [$wall]);
    }

    public function testBlockingAndTradingDamage(): void
    {
        $this->newGame();
        $giant = $this->battlefield(0, 'Hill Giant');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $spider = $this->battlefield(1, 'Giant Spider');
        $this->toAttack();
        $this->game->declareAttackers(0, [$giant]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame('block', $this->game->decision(1));

        $this->game->declareBlockers(1, [$bears => $giant, $spider => $giant]);
        $this->assertSame(0, $this->game->priority);
        $this->finishCombat();

        // The Giant assigns lethal (2) to the Bears first, the last 1 to the Spider; it takes 2 + 2.
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($spider));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($giant));
        $this->assertSame(20, $this->life(1));
    }

    public function testFlyingAndReach(): void
    {
        $this->newGame();
        $angel = $this->battlefield(0, 'Serra Angel');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $spider = $this->battlefield(1, 'Giant Spider');
        $this->toAttack();
        $this->game->declareAttackers(0, [$angel]);
        $this->game->pass(0);
        $this->game->pass(1);

        $this->assertSame([$spider], $this->game->blockCandidates());
        try {
            $this->game->declareBlockers(1, [$bears => $angel]);
            $this->fail('Bears cannot block a flyer.');
        } catch (GameException $e) {
            $this->assertStringContainsString('cannot block', $e->getMessage());
        }
        $this->game->declareBlockers(1, [$spider => $angel]);
        $this->finishCombat();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spider));
        $this->assertSame(2, $this->game->objects[$angel]->damage);
    }

    public function testMenaceNeedsTwoBlockers(): void
    {
        $this->newGame();
        $goblin = $this->battlefield(0, 'Goblin Heelcutter');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $spider = $this->battlefield(1, 'Giant Spider');
        $this->toAttack();
        $this->game->declareAttackers(0, [$goblin]);
        $this->game->pass(0);
        $this->game->pass(1);

        try {
            $this->game->declareBlockers(1, [$bears => $goblin]);
            $this->fail('Menace needs two blockers.');
        } catch (GameException $e) {
            $this->assertStringContainsString('menace', $e->getMessage());
        }
        $this->game->declareBlockers(1, [$bears => $goblin, $spider => $goblin]);
        $this->assertSame([$bears => $goblin, $spider => $goblin], $this->game->blockers);
    }

    public function testFirstStrikeKillsBeforeDamageBack(): void
    {
        $this->newGame();
        $knight = $this->battlefield(0, 'White Knight');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->toAttack();
        $this->game->declareAttackers(0, [$knight]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->game->declareBlockers(1, [$bears => $knight]);
        $this->game->pass(0);
        $this->game->pass(1);

        $this->assertSame(Step::FirstStrikeDamage, $this->game->step);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->finishCombat();
        $this->assertSame(0, $this->game->objects[$knight]->damage, 'The Bears died before they could deal damage.');
    }

    public function testDoubleStrikeHitsTwice(): void
    {
        $this->newGame();
        $ace = $this->battlefield(0, 'Fencing Ace');
        $this->toAttack();
        $this->game->declareAttackers(0, [$ace]);
        $this->finishCombat();
        $this->assertSame(18, $this->life(1));
    }

    public function testTrampleDeathtouchAndLifelink(): void
    {
        $this->newGame();
        $dreadmaw = $this->battlefield(0, 'Colossal Dreadmaw');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->toAttack();
        $this->game->declareAttackers(0, [$dreadmaw]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->game->declareBlockers(1, [$bears => $dreadmaw]);
        $this->finishCombat();
        $this->assertSame(16, $this->life(1), 'Trample: 2 to the Bears, 4 to Bob.');

        // Bob's turn: a deathtouch lifelinker blocked by the Dreadmaw kills it.
        $nighthawk = $this->battlefield(1, 'Vampire Nighthawk');
        $this->passUntil(Step::PrecombatMain, 2);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->game->declareAttackers(1, [$nighthawk]);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->assertSame([], $this->game->blockCandidates(), 'Nothing of Alice\'s can block a flyer.');
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(18, $this->life(0));
        $this->assertSame(18, $this->life(1), 'Lifelink gained Bob 2.');
    }

    public function testDeathtouchBlocker(): void
    {
        $this->newGame();
        $wurm = $this->battlefield(0, 'Craw Wurm');
        $rats = $this->battlefield(1, 'Typhoid Rats');
        $this->toAttack();
        $this->game->declareAttackers(0, [$wurm]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->game->declareBlockers(1, [$rats => $wurm]);
        $this->finishCombat();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($wurm));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($rats));
    }

    public function testCombatCanEndTheGame(): void
    {
        $this->newGame();
        $this->game->players[1]->life = 2;
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->toAttack();
        $this->game->declareAttackers(0, [$bears]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->game->pass(1);

        $this->assertSame(Game::OVER, $this->game->stage);
        $this->assertSame(0, $this->game->winner);
        $this->assertStringContainsString('Alice wins the game!', implode("\n", $this->game->log));
    }

    public function testNoAttackSkipsToEndOfCombat(): void
    {
        $this->newGame();
        $this->battlefield(0, 'Grizzly Bears');
        $this->toAttack();
        $this->game->declareAttackers(0, []);
        $this->assertSame(Step::EndCombat, $this->game->step);
    }
}
