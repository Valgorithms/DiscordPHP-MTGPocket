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

    public function testReanimatedLandFollowUpTracksItsNewIncarnationAfterRestart(): void
    {
        $game = $this->newGame();
        $land = $game->addCard(0, ['type' => 'Land Creature — Forest Island Dryad', 'power' => '1', 'toughness' => '1'] + self::pool(), GameObject::GRAVEYARD);
        $this->lands(0, 'Forest', 1);
        $spell = $game->addCard(0, ['uuid' => 'reanimate-fixture', 'name' => 'Reanimate Fixture', 'type' => 'Sorcery', 'manaCost' => '{G}',
            'text' => 'Return target creature card from your graveyard to the battlefield. It gains haste. Exile it at the beginning of the next end step.'], GameObject::HAND);
        $this->assertSame([], $spell->definition()->unsupported);
        $game->cast(0, $spell->id, 0, ["o:{$land->id}"]);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame('entry_life', $game->decision(0));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->answer(false);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$land->id]->zone);
        $this->assertSame(['haste'], $game->objects[$land->id]->untilEndOfTurn[0]['keywords']);
        $this->assertSame('end_exile', $game->objects[$land->id]->alt);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$spell->id]->zone);
        $this->passUntil(\MTGPocket\Game\Step::End);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::EXILE, $game->objects[$land->id]->zone);
    }

    public function testNestedPaidEffectsWaitForEntryAndResumeAfterRestart(): void
    {
        $game = $this->newGame();
        $id = $this->poolInHand();
        $game->players[0]->energy = 1;
        $source = $game->addCard(0, ['uuid' => 'payment-fixture', 'name' => 'Payment Fixture', 'type' => 'Enchantment',
            'text' => 'Whenever a land you control enters, you may pay {E}. If you do, you may put a land card from your hand onto the battlefield. You gain 3 life.'], GameObject::BATTLEFIELD);
        $this->assertSame([], $source->definition()->unsupported);
        $forest = $game->addCard(0, self::card('Forest'), GameObject::HAND);
        $game->playLand(0, $forest->id);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame('entry_life', $game->decision(0));
        $this->assertSame(20, $game->players[0]->life);
        $this->assertSame(0, $game->players[0]->energy);
        $this->assertSame(GameObject::HAND, $game->objects[$id]->zone);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->answer(false);
        $this->assertSame(23, $game->players[0]->life);
        $this->assertSame(0, $game->players[0]->energy, 'The paid cost is not repeated on resume.');
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$id]->zone);
        $this->assertSame([], $game->toArray()['pendingEntries']);
    }

    public function testStateBasedEntryChoicePrecedesModalDeathTrigger(): void
    {
        $game = $this->newGame();
        $land = $game->addCard(0, self::pool(), GameObject::EXILE);
        $source = $game->addCard(0, ['uuid' => 'holding-fixture', 'name' => 'Holding Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '0',
            'text' => "When this creature dies, choose one —\n• You gain 3 life.\n• Draw a card."], GameObject::BATTLEFIELD);
        $this->assertSame([], $source->definition()->unsupported);
        $source->holding = [$land->id];
        $game->pass(0);
        $this->assertSame('entry_life', $game->decision(0));
        $this->assertSame(GameObject::EXILE, $land->zone);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->answer(false);
        $this->assertSame('mode', $game->decision(0));
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$land->id]->zone);
        $this->assertTrue($game->objects[$land->id]->tapped);
        $this->assertSame([], $game->toArray()['pendingEntries']);
        $game->chooseMode(0, 0);
        $this->assertNull($game->choiceAwaiting());
    }

    public function testUnearthEntryRetainsHasteAndExileReplacementAfterRestart(): void
    {
        $game = $this->newGame();
        $land = $game->addCard(0, ['uuid' => 'unearth-land', 'name' => 'Unearth Land', 'type' => 'Land Creature — Dryad', 'power' => '1', 'toughness' => '1',
            'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\nUnearth {G}"], GameObject::GRAVEYARD);
        $this->assertSame([], $land->definition()->unsupported);
        $this->lands(0, 'Forest', 1);
        $game->unearth(0, $land->id);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame('entry_life', $game->decision(0));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->answer(false);
        $this->assertTrue($game->objects[$land->id]->unearthed);
        $this->assertSame(['haste'], $game->objects[$land->id]->untilEndOfTurn[0]['keywords']);
        $this->passUntil(\MTGPocket\Game\Step::End);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::EXILE, $game->objects[$land->id]->zone);
    }

    public function testLandTokenCopyRetainsItsEntryInstructionsAfterRestart(): void
    {
        $game = $this->newGame();
        $id = $this->poolInHand();
        $game->playLand(0, $id);
        $this->answer(false);
        $this->lands(0, 'Forest', 1);
        $spell = $game->addCard(0, ['uuid' => 'copy-fixture', 'name' => 'Copy Fixture', 'type' => 'Sorcery', 'manaCost' => '{G}',
            'text' => "Create a token that's a copy of target permanent, except it has haste. Exile it at the beginning of the next end step."], GameObject::HAND);
        $this->assertSame([], $spell->definition()->unsupported);
        $game->cast(0, $spell->id, 0, ["o:{$id}"]);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame('entry_life', $game->decision(0));
        $copy = $game->choiceAwaiting()['object'];
        $this->assertNotSame($id, $copy);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->answer(true);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$copy]->zone);
        $this->assertSame(['haste'], $game->objects[$copy]->untilEndOfTurn[0]['keywords']);
        $this->assertSame('end_exile', $game->objects[$copy]->alt);
        $this->assertSame(18, $game->players[0]->life);
        $this->passUntil(\MTGPocket\Game\Step::End);
        $game->pass(0);
        $game->pass(1);
        $this->assertSame(GameObject::GONE, $game->objects[$copy]->zone);
    }

    public function testMissingSavedEntryIsRejected(): void
    {
        $game = $this->newGame();
        $game->playLand(0, $this->poolInHand());
        $data = $game->toArray();
        $data['pendingEntries'] = [];
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Invalid saved entry choice');
        Game::fromArray($data);
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
