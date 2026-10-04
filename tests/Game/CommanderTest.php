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
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * The Commander rules (903): the command zone, commander tax, commanders
 * returning to the command zone and commander damage.
 *
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\GamePlayer
 */
final class CommanderTest extends GameTestCase
{
    private function commander(int $seat, string $name, string $zone = GameObject::COMMAND): int
    {
        $id = $this->game->addCard($seat, self::card($name), $zone)->id;
        $this->game->commanders[$id] = 0;

        return $id;
    }

    public function testCommandersStartInTheCommandZone(): void
    {
        $game = Game::start('game-1', [
            ['id' => '1', 'name' => 'Alice', 'cards' => array_fill(0, 30, self::card('Plains')), 'commander' => self::card('Isamaru, Hound of Konda')],
            ['id' => '2', 'name' => 'Bob', 'cards' => array_fill(0, 30, self::card('Forest'))],
        ], 'seed', 40);

        $this->assertCount(1, $game->command);
        $commander = $game->objects[$game->command[0]];
        $this->assertSame(['Isamaru, Hound of Konda', 0, GameObject::COMMAND], [$commander->name(), $commander->owner, $commander->zone]);
        $this->assertSame([$commander->id], $game->commandCards(0));
        $this->assertSame([], $game->commandCards(1));
        $this->assertSame(23, count($game->players[0]->library), 'The commander is not shuffled in.');
        $this->assertSame(40, $game->players[0]->life);

        $game->players[1]->commanderDamage[$commander->id] = 5;
        $game->commanders[$commander->id] = 2;
        $copy = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame($game->command, $copy->command);
        $this->assertSame([$commander->id => 2], $copy->commanders);
        $this->assertSame([$commander->id => 5], $copy->players[1]->commanderDamage);
    }

    public function testCastingFromTheCommandZoneCostsMoreEachTime(): void
    {
        $this->newGame();
        $isamaru = $this->commander(0, 'Isamaru, Hound of Konda');
        $plains = $this->lands(0, 'Plains', 3);
        $this->lands(0, 'Mountain', 1);

        $this->assertContains($isamaru, $this->game->playableCards(0));
        $this->assertSame(0, $this->game->commanderTax($isamaru));
        $this->game->cast(0, $isamaru);
        $this->assertStringContainsString('casts Isamaru, Hound of Konda from the command zone.', end($this->game->log));
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($isamaru));
        $this->assertSame(2, $this->game->commanderTax($isamaru));

        // It dies and goes back to the command zone.
        $bolt = $this->hand(0, 'Lightning Bolt');
        $this->game->cast(0, $bolt, 0, ["o:{$isamaru}"]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::COMMAND, $this->zone($isamaru));
        $this->assertContains('Isamaru, Hound of Konda returns to the command zone.', $this->game->log);
        $this->assertSame([$bolt], $this->game->players[0]->graveyard, 'Only the bolt is in the graveyard.');

        // Now it costs {2}{W}: two untapped Plains are not enough.
        $this->assertNotContains($isamaru, $this->game->playableCards(0));
        $this->lands(0, 'Plains', 1);
        $this->game->cast(0, $isamaru);
        $this->assertStringContainsString('(tax {2})', end($this->game->log));
        $this->assertSame(4, $this->game->commanderTax($isamaru));
        $this->assertTrue($this->game->objects[$plains[2]]->tapped);
    }

    public function testTwentyOneCommanderDamageLoses(): void
    {
        $this->newGame();
        $this->game->players[1]->life = 40;
        $isamaru = $this->commander(0, 'Isamaru, Hound of Konda', GameObject::BATTLEFIELD);
        $this->game->players[1]->commanderDamage[$isamaru] = 20;

        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, [$isamaru]);
        for ($i = 0; $i < 20 && $this->game->stage !== Game::OVER; $i++) {
            $this->game->pass((int) $this->game->priority);
        }

        $this->assertSame(Game::OVER, $this->game->stage);
        $this->assertSame(38, $this->life(1), 'Plenty of life left.');
        $this->assertSame(22, $this->game->players[1]->commanderDamage[$isamaru]);
        $this->assertSame(0, $this->game->winner);
        $this->assertSame('has taken 21 combat damage from one commander', $this->game->players[1]->lossReason);
    }

    public function testOtherDamageIsNotCommanderDamage(): void
    {
        $this->newGame();
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, [$bears]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(18, $this->life(1));
        $this->assertSame([], $this->game->players[1]->commanderDamage);
    }
}
