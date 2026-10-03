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
 * Opening hands, the turn structure, priority, the stack, lands and spells.
 *
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\GameObject
 * @covers \MTGPocket\Game\GamePlayer
 * @covers \MTGPocket\Game\Step
 */
final class TurnsAndSpellsTest extends GameTestCase
{
    public function testOpeningHandsAndLondonMulligan(): void
    {
        $deck = array_fill(0, 40, self::card('Forest'));
        $game = Game::start('g', [['id' => '1', 'name' => 'Alice', 'cards' => $deck], ['id' => '2', 'name' => 'Bob', 'cards' => $deck]], 'seed');

        $this->assertSame(Game::MULLIGAN, $game->stage);
        $this->assertCount(7, $game->players[0]->hand);
        $this->assertCount(33, $game->players[0]->library);
        $this->assertSame([0, 1], $game->waitingOn());

        $game->mulligan(1);
        $this->assertCount(7, $game->players[1]->hand, 'A mulligan draws seven again.');
        $game->keep(1);
        $this->assertSame('bottom', $game->decision(1));
        $bottom = $game->players[1]->hand[0];
        try {
            $game->bottom(1, []);
            $this->fail('Must put exactly one card on the bottom.');
        } catch (GameException $e) {
            $this->assertStringContainsString('exactly 1 card', $e->getMessage());
        }
        $game->bottom(1, [$bottom]);
        $this->assertCount(6, $game->players[1]->hand);
        $this->assertSame($bottom, $game->players[1]->library[0], 'It went to the very bottom.');
        $this->assertSame(Game::MULLIGAN, $game->stage, 'Still waiting on Alice.');

        $game->keep(0);
        $this->assertSame(Game::PLAYING, $game->stage);
        $this->assertSame(Step::PrecombatMain, $game->step);
        $this->assertCount(7, $game->players[$game->startingPlayer]->hand, 'The starting player skips their first draw.');
    }

    public function testSameSeedSameGame(): void
    {
        $deck = array_map(fn ($n) => ['uuid' => "c{$n}", 'name' => "Card {$n}", 'type' => 'Land', 'text' => null], range(1, 40));
        $players = [['id' => '1', 'name' => 'A', 'cards' => $deck], ['id' => '2', 'name' => 'B', 'cards' => $deck]];

        $this->assertSame(Game::start('g', $players, 'x')->toArray(), Game::start('g', $players, 'x')->toArray());
        $this->assertNotSame(Game::start('g', $players, 'x')->players[0]->library, Game::start('g', $players, 'y')->players[0]->library);
    }

    public function testTurnPassesAndTheNextPlayerDraws(): void
    {
        $this->newGame(true);
        $bobHand = count($this->game->players[1]->hand);

        $this->game->pass(0);
        // Nothing to do for either player: the turn runs on to Bob's first main phase.
        $this->assertSame(2, $this->game->turn);
        $this->assertSame(1, $this->game->active);
        $this->assertSame(Step::PrecombatMain, $this->game->step);
        $this->assertCount($bobHand + 1, $this->game->players[1]->hand);
        $this->assertStringContainsString('Turn 2: Bob.', implode("\n", $this->game->log));
    }

    public function testOnlyTheActivePlayerActsAndPassingNeedsPriority(): void
    {
        $this->newGame();
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('You do not have priority');
        $this->game->pass(1);
    }

    public function testOneLandPerTurnAtSorcerySpeed(): void
    {
        $this->newGame();
        $forest = $this->hand(0, 'Forest');
        $second = $this->hand(0, 'Forest');
        $this->assertEqualsCanonicalizing([$forest, $second], $this->game->playableCards(0));

        $this->game->playLand(0, $forest);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($forest));
        $this->assertSame([], $this->game->playableCards(0));

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('already played a land');
        $this->game->playLand(0, $second);
    }

    public function testCastingTapsLandsAndCreaturesHaveSummoningSickness(): void
    {
        $this->newGame();
        [$a, $b, $c] = $this->lands(0, 'Forest', 3);
        $bears = $this->hand(0, 'Grizzly Bears');
        $bolt = $this->hand(0, 'Lightning Bolt');

        $this->assertSame([$bears], $this->game->playableCards(0), 'Bolt needs red mana.');
        $this->game->cast(0, $bears);
        $this->assertSame(GameObject::STACK, $this->zone($bears));
        $this->assertSame(0, $this->game->priority, 'The caster keeps priority.');
        $this->assertSame(2, count(array_filter([$a, $b, $c], fn ($id) => $this->game->objects[$id]->tapped)));

        $this->game->pass(0);
        $this->assertSame(GameObject::STACK, $this->zone($bears), 'Bob may still respond.');
        $this->game->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears));
        $this->assertTrue($this->game->objects[$bears]->sick);
        $this->assertSame(0, $this->game->priority, 'After a spell resolves, the active player gets priority.');

        // A summoning-sick creature cannot attack, so there is no attack to declare.
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame([], $this->game->attackers);
    }

    public function testSpellsResolveLastInFirstOut(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Forest', 1);
        $this->lands(1, 'Mountain', 1);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $growth = $this->hand(0, 'Giant Growth');
        $bolt = $this->hand(1, 'Lightning Bolt');
        $shock = $this->hand(0, 'Shock');

        // Alice passes in her main phase and Bob bolts the Bears; Alice answers with Giant Growth.
        $this->game->pass(0);
        $this->assertSame(1, $this->game->priority);
        $this->game->cast(1, $bolt, 0, ["o:{$bears}"]);
        $this->game->pass(1);
        $this->assertSame(0, $this->game->priority);
        $this->game->cast(0, $growth, 0, ["o:{$bears}"]);
        $this->assertCount(2, $this->game->stack);

        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($growth), 'Giant Growth, on top, resolves first.');
        $this->assertSame(GameObject::STACK, $this->zone($bolt));
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears), 'A 5/5 survives 3 damage.');
        $this->assertSame(3, $this->game->objects[$bears]->damage);
        $this->assertSame(GameObject::HAND, $this->zone($shock));

        // At end of turn the damage and the +3/+3 wear off.
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame(0, $this->game->objects[$bears]->damage);
        $this->assertSame([], $this->game->objects[$bears]->untilEndOfTurn);
        $this->assertSame(2, $this->game->power($this->game->objects[$bears]));
    }

    public function testLethalDamageDestroysACreature(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $bolt = $this->hand(0, 'Lightning Bolt');

        $this->game->cast(0, $bolt, 0, ["o:{$bears}"]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame([$bears], $this->game->players[1]->graveyard);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertStringContainsString('Grizzly Bears dies.', implode("\n", $this->game->log));
    }

    public function testSpellWithItsTargetGoneDoesNothing(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Island', 1);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $bolt = $this->hand(0, 'Lightning Bolt');
        $unsummon = $this->hand(1, 'Unsummon');

        $this->game->cast(0, $bolt, 0, ["o:{$bears}"]);
        $this->game->pass(0);
        $this->game->cast(1, $unsummon, 0, ["o:{$bears}"]);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->assertSame(GameObject::HAND, $this->zone($bears));
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertStringContainsString('Lightning Bolt has no legal targets left and does nothing.', implode("\n", $this->game->log));
        $this->assertSame(20, $this->life(1));
    }

    public function testCounterspell(): void
    {
        $this->newGame();
        $this->lands(0, 'Forest', 2);
        $this->lands(1, 'Island', 2);
        $bears = $this->hand(0, 'Grizzly Bears');
        $counter = $this->hand(1, 'Counterspell');

        $this->game->cast(0, $bears);
        $this->game->pass(0);
        $stackId = $this->game->stack[0]['id'];
        $this->assertSame(["s:{$stackId}"], $this->game->targetOptions(1, 'spell'));
        $this->game->cast(1, $counter, 0, ["s:{$stackId}"]);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($counter));
        $this->assertSame([], $this->game->stack);
    }

    public function testSorcerySpeedAndXCosts(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $this->lands(1, 'Island', 3);
        $blaze = $this->hand(0, 'Blaze');
        $divination = $this->hand(1, 'Divination');

        $this->assertSame(3, $this->game->maxX(0, $blaze));
        $this->game->cast(0, $blaze, 3, ['p:1']);
        $this->game->pass(0);
        $this->assertSame(1, $this->game->priority);
        $this->assertNotContains($divination, $this->game->playableCards(1), 'A sorcery waits for its owner\'s main phase.');
        $this->expectException(GameException::class);
        $this->game->cast(1, $divination);
    }

    public function testXResolvesWithTheChosenValueAndDivinationDraws(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $blaze = $this->hand(0, 'Blaze');
        $this->game->cast(0, $blaze, 3, ['p:1']);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(17, $this->life(1));

        $this->lands(0, 'Island', 3);
        $divination = $this->hand(0, 'Divination');
        $before = count($this->game->players[0]->hand);
        $this->game->cast(0, $divination);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertCount($before - 1 + 2, $this->game->players[0]->hand);
    }

    public function testPhyrexianManaAndCardsWithoutACost(): void
    {
        $this->newGame();
        $probe = $this->hand(0, 'Gitaxian Probe');
        $vision = $this->hand(0, 'Ancestral Vision');
        $ornithopter = $this->hand(0, 'Ornithopter');

        $this->assertEqualsCanonicalizing([$probe, $ornithopter], $this->game->playableCards(0), 'Probe can be paid with life; Vision has no mana cost.');
        $this->game->cast(0, $ornithopter);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($ornithopter));

        $this->assertSame(['Look at target player\'s hand.'], $this->game->objects[$probe]->definition()->unsupported);
        $this->game->cast(0, $probe);
        $this->assertSame(18, $this->life(0), 'With no blue mana, {U/P} costs 2 life.');

        try {
            $this->game->cast(0, $vision);
            $this->fail('Ancestral Vision has no mana cost.');
        } catch (GameException $e) {
            $this->assertStringContainsString('no mana cost', $e->getMessage());
        }
    }

    public function testHexproofAndShroudAreChecked(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->game->objects[$bears]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['hexproof']];
        $mine = $this->battlefield(1, 'Llanowar Elves');

        $this->assertNotContains("o:{$bears}", $this->game->targetOptions(0, 'any'));
        $this->assertContains("o:{$bears}", $this->game->targetOptions(1, 'any'), 'Its controller can still target it.');
        $this->assertContains("o:{$mine}", $this->game->targetOptions(0, 'any'));
    }

    public function testManaCreaturesAndTappedLands(): void
    {
        $this->newGame();
        $elves = $this->hand(0, 'Llanowar Elves');
        $gate = $this->hand(0, 'Selesnya Guildgate');
        $this->lands(0, 'Forest', 1);

        $this->game->playLand(0, $gate);
        $this->assertTrue($this->game->objects[$gate]->tapped, 'A Guildgate enters tapped.');
        $this->game->cast(0, $elves);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame([], $this->game->manaSources(0), 'Summoning-sick Elves cannot tap for mana; the Forest is tapped.');

        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertEqualsCanonicalizing([$elves, $gate, $this->game->battlefield[0]], array_keys($this->game->manaSources(0)));
    }

    public function testDiscardToHandSizeAtCleanup(): void
    {
        $this->newGame();
        $cards = array_map(fn () => $this->hand(0, 'Hill Giant'), range(1, 9));

        $this->passUntil(Step::Cleanup);
        $this->assertSame('discard', $this->game->decision(0));
        $this->game->discard(0, [$cards[0], $cards[1]]);
        $this->assertCount(7, $this->game->players[0]->hand);
        $this->assertSame(2, $this->game->turn);
    }

    public function testDrawingFromAnEmptyLibraryLoses(): void
    {
        $this->newGame(true);
        $this->game->players[1]->library = [];
        $this->game->pass(0);
        $this->assertSame(Game::OVER, $this->game->stage);
        $this->assertSame(0, $this->game->winner);
        $this->assertTrue($this->game->players[1]->lost);
        $this->assertSame([], $this->game->waitingOn());
    }

    public function testConcede(): void
    {
        $this->newGame();
        $this->game->concede(0);
        $this->assertSame(1, $this->game->winner);
        $this->assertStringContainsString('Alice concedes and loses the game.', implode("\n", $this->game->log));

        $this->expectException(GameException::class);
        $this->game->pass(1);
    }

    public function testLegendRule(): void
    {
        $this->newGame();
        $this->lands(0, 'Plains', 1);
        $first = $this->battlefield(0, 'Isamaru, Hound of Konda');
        $second = $this->hand(0, 'Isamaru, Hound of Konda');
        $this->game->cast(0, $second);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($first));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($second));
    }

    public function testAurasAttachAndFallOff(): void
    {
        $this->newGame();
        $this->lands(0, 'Forest', 1);
        $this->lands(1, 'Mountain', 1);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $aura = $this->hand(0, 'Rancor Lite');
        $bolt = $this->hand(1, 'Lightning Bolt');

        $this->game->cast(0, $aura, 0, ["o:{$bears}"]);
        $this->game->pass(0);
        $this->game->pass(1);
        $this->assertSame($bears, $this->game->objects[$aura]->attachedTo);
        $this->assertSame(4, $this->game->power($this->game->objects[$bears]));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$bears], 'trample'));

        $this->game->pass(0);
        $this->game->cast(1, $bolt, 0, ["o:{$bears}"]);
        $this->game->pass(1);
        $this->game->pass(0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($aura), 'An Aura attached to nothing goes to the graveyard.');
    }

    public function testSavedGameLoadsTheSame(): void
    {
        $this->newGame();
        $this->lands(0, 'Forest', 2);
        $bears = $this->hand(0, 'Grizzly Bears');
        $this->game->cast(0, $bears);

        $saved = json_decode(json_encode($this->game->toArray()), true);
        $loaded = Game::fromArray($saved);
        $this->assertSame(json_encode($this->game->toArray()), json_encode($loaded->toArray()));

        $loaded->pass(0);
        $loaded->pass(1);
        $this->assertSame(GameObject::BATTLEFIELD, $loaded->objects[$bears]->zone);
    }
}
