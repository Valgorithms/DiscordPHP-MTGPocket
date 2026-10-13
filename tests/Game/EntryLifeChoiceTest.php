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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class EntryLifeChoiceTest extends GameTestCase
{
    private static function pool(): array
    {
        return ['uuid' => 'breeding-pool', 'name' => 'Breeding Pool', 'type' => 'Land — Forest Island',
            'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {G} or {U}."];
    }

    private function poolInHand(int $seat = 0): int
    {
        return $this->game->addCard($seat, self::pool(), GameObject::HAND)->id;
    }

    private function answer(bool $pay): void
    {
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseEntryLife($choice['seat'], $choice['id'], $pay ? 'pay' : 'decline');
    }

    public function testDecliningDoesNotPayOrEnterBeforeTheAnswer(): void
    {
        $game = $this->newGame();
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $this->assertSame('entry_life', $game->decision(0));
        $this->assertNull($game->decision(1));
        $this->assertSame(['pay', 'decline'], $game->choiceAwaiting()['options']);
        $this->assertSame(GameObject::HAND, $game->objects[$id]->zone);
        $this->assertNotContains($id, $game->battlefield);
        $this->assertSame(20, $game->players[0]->life);
        $this->assertSame(1, $game->players[0]->landsPlayed);
        $this->assertSame([], $game->plays(0));
        $this->answer(false);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$id]->zone);
        $this->assertTrue($game->objects[$id]->tapped);
        $this->assertSame(20, $game->players[0]->life);
        $this->assertNull($game->choiceAwaiting());
    }

    public function testPayingAtLowLifeAndSavingWhilePending(): void
    {
        $game = $this->newGame();
        $game->players[0]->life = 3;
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $saved = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $choice = $game->choiceAwaiting();
        $game->chooseEntryLife(0, $choice['id'], 'pay');
        $saved->chooseEntryLife(0, $choice['id'], 'pay');
        $this->assertSame(json_encode($game->toArray()), json_encode($saved->toArray()));
        $this->assertSame(1, $game->players[0]->life);
        $this->assertFalse($game->objects[$id]->tapped);
        $this->assertSame(1, count(array_filter($game->battlefield, fn ($land) => $land === $id)));
        $this->assertSame($game->turn, $game->players[0]->lostLifeOnTurn);
    }

    public function testInvalidAnswersDoNotChangeStateAndOldPanelsCannotAnswerNewChoices(): void
    {
        $game = $this->newGame();
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $choice = $game->choiceAwaiting();
        foreach ([[1, $choice['id'], 'pay'], [0, $choice['id'] + 1, 'pay'], [0, $choice['id'], 'bogus']] as $args) {
            $before = $game->toArray();
            try {
                $game->chooseEntryLife(...$args);
                $this->fail('Invalid answer accepted.');
            } catch (GameException) {
                $this->assertSame($before, $game->toArray());
            }
        }
        $this->answer(true);
        $game->players[0]->landsPlayed = 0;
        $game->playLand(0, $this->poolInHand());
        $before = $game->toArray();
        try {
            $game->chooseEntryLife(0, $choice['id'], 'pay');
            $this->fail('Old panel answered a different entry.');
        } catch (GameException) {
            $this->assertSame($before, $game->toArray());
        }
        $this->answer(false);
        $before = $game->toArray();
        try {
            $game->chooseEntryLife(0, $choice['id'], 'pay');
            $this->fail('Repeated answer accepted.');
        } catch (GameException) {
            $this->assertSame($before, $game->toArray());
        }
        $this->assertSame(18, $game->players[0]->life);
    }

    public function testInsufficientLifeOnlyOffersDeclining(): void
    {
        $game = $this->newGame();
        $game->players[0]->life = 1;
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $this->assertSame(['decline'], $game->choiceAwaiting()['options']);
        $before = $game->toArray();
        try {
            $this->answer(true);
            $this->fail('Cannot pay more life than available.');
        } catch (GameException) {
            $this->assertSame($before, $game->toArray());
        }
        $this->answer(false);
        $this->assertSame(1, $game->players[0]->life);
        $this->assertTrue($game->objects[$id]->tapped);
    }

    public function testPaymentEqualToLifeIsLegalAndThenLosesTheGame(): void
    {
        $game = $this->newGame();
        $game->players[0]->life = 2;
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $this->answer(true);
        $this->assertSame(0, $game->players[0]->life);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$id]->zone);
        $this->assertSame(Game::OVER, $game->stage);
        $this->assertSame(1, $game->winner);
    }

    public function testResolutionWaitsForEntryOnTheOtherPlayersTurnAndResumesAfterRestart(): void
    {
        $game = $this->newGame();
        $id = $this->poolInHand(1);
        $this->lands(1, 'Forest', 1);
        $spell = $game->addCard(1, ['uuid' => 'entry-fixture', 'name' => 'Entry Fixture', 'type' => 'Instant', 'manaCost' => '{G}',
            'text' => 'You may put a land card from your hand onto the battlefield. You gain 3 life.'], GameObject::HAND);
        $this->assertSame([], $spell->definition()->unsupported);
        $game->pass(0);
        $game->cast(1, $spell->id);
        $game->pass(1);
        $game->pass(0);
        $this->assertSame('entry_life', $game->decision(1));
        $this->assertSame(0, $game->active);
        $this->assertSame(GameObject::STACK, $spell->zone);
        $this->assertSame(20, $game->players[1]->life, 'Later gain-life effect waits.');
        $this->assertSame(GameObject::HAND, $game->objects[$id]->zone);
        $saved = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $choice = $saved->choiceAwaiting();
        $saved->chooseEntryLife(1, $choice['id'], 'pay');
        $this->assertSame(21, $saved->players[1]->life);
        $this->assertFalse($saved->objects[$id]->tapped);
        $this->assertSame(GameObject::GRAVEYARD, $saved->objects[$spell->id]->zone);
    }

    public function testEntryTriggersWaitAndFireOnce(): void
    {
        $game = $this->newGame();
        $game->addCard(0, ['uuid' => 'landfall-fixture', 'name' => 'Landfall Fixture', 'type' => 'Enchantment',
            'text' => 'Whenever a land you control enters, you gain 1 life.'], GameObject::BATTLEFIELD);
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $this->assertSame([], $game->pendingTriggers);
        $this->assertSame([], $game->stack);
        $this->answer(true);
        $this->assertCount(1, $game->stack);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(19, $game->players[0]->life);
        $this->assertSame([], $game->stack);
    }

    public function testForcedTappedEntryStillAllowsPaymentAndWaitsForItsChoice(): void
    {
        $game = $this->newGame();
        $id = $this->poolInHand();
        $this->lands(0, 'Forest', 1);
        $spell = $game->addCard(0, ['uuid' => 'tapped-fixture', 'name' => 'Tapped Fixture', 'type' => 'Sorcery', 'manaCost' => '{G}',
            'text' => 'You may put a land card from your hand onto the battlefield tapped. You gain 3 life.'], GameObject::HAND);
        $this->assertSame([], $spell->definition()->unsupported);
        $game->cast(0, $spell->id);
        $game->pass(0);
        $game->pass(1);
        $this->assertTrue($game->choiceAwaiting()['tapped']);
        $this->answer(true);
        $this->assertTrue($game->objects[$id]->tapped);
        $this->assertSame(21, $game->players[0]->life);
        $this->assertSame(GameObject::GRAVEYARD, $spell->zone);
    }

    public function testCardSelectionCanStartTwoSavedEntryChoicesWithoutLosingResolution(): void
    {
        $game = $this->newGame();
        $ids = [];
        foreach (['pool-a', 'pool-b'] as $uuid) {
            $ids[] = $game->addCard(0, ['uuid' => $uuid] + self::pool(), GameObject::LIBRARY)->id;
        }
        $this->lands(0, 'Forest', 1);
        $spell = $game->addCard(0, ['uuid' => 'look-fixture', 'name' => 'Look Fixture', 'type' => 'Sorcery', 'manaCost' => '{G}',
            'text' => 'Look at the top two cards of your library. Put up to two land cards from among them onto the battlefield tapped. Put the rest on the bottom of your library in any order.'], GameObject::HAND);
        $this->assertSame([], $spell->definition()->unsupported);
        $game->cast(0, $spell->id);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame('look', $game->decision(0));
        $game->take(0, $ids);
        $first = $game->choiceAwaiting()['id'];
        $this->assertSame('entry_life', $game->decision(0));
        $this->assertSame(GameObject::STACK, $spell->zone);
        $this->answer(false);
        $this->assertSame('entry_life', $game->decision(0));
        $this->assertGreaterThan($first, $game->choiceAwaiting()['id']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->answer(true);
        foreach ($ids as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$id]->zone);
            $this->assertTrue($game->objects[$id]->tapped);
            $this->assertSame(1, count(array_filter($game->battlefield, fn ($land) => $land === $id)));
        }
        $this->assertSame(18, $game->players[0]->life);
        $this->assertNull($game->choiceAwaiting());
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$spell->id]->zone);
    }

    public function testPracticeBotUsesTheSameChoiceApi(): void
    {
        $game = $this->newGame();
        $game->playLand(0, $id = $this->poolInHand());
        $this->assertTrue(PracticeBot::act($game, 0));
        $this->assertSame(18, $game->players[0]->life);
        $this->assertFalse($game->objects[$id]->tapped);
        $this->assertSame(Game::PLAYING, $game->stage);
    }

    public function testUnversionedStatesStillLoadAndFutureVersionsAreRejected(): void
    {
        $data = $this->newGame()->toArray();
        unset($data['schemaVersion'], $data['pendingEntries'], $data['nextChoiceId']);
        $saved = Game::fromArray($data)->toArray();
        $this->assertSame(Game::STATE_VERSION, $saved['schemaVersion']);
        $this->assertSame([], $saved['pendingEntries']);
        $this->assertSame(1, $saved['nextChoiceId']);
        $data['schemaVersion'] = Game::STATE_VERSION + 1;
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Unsupported saved game schema');
        Game::fromArray($data);
    }
}
