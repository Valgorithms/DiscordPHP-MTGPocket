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

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Mandatory delayed cantrip draws use a resumable, one-shot upkeep trigger.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class DelayedUpkeepDrawTest extends GameTestCase
{
    /** Real card wording copied into fixtures; no imported pool or live service is needed. */
    private const array MORE = [
        'Flare' => ['manaCost' => '{2}{R}', 'type' => 'Instant', 'colors' => ['R'], 'text' => "Flare deals 1 damage to any target.\nDraw a card at the beginning of the next turn's upkeep."],
        'Heal' => ['manaCost' => '{W}', 'type' => 'Instant', 'colors' => ['W'], 'text' => "Prevent the next 1 damage that would be dealt to any target this turn.\nDraw a card at the beginning of the next turn's upkeep."],
        'Ray of Erasure' => ['manaCost' => '{U}', 'type' => 'Instant', 'colors' => ['U'], 'text' => "Target player mills a card.\nDraw a card at the beginning of the next turn's upkeep."],
        'Pyknite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Ouphe', 'colors' => ['G'], 'power' => '1', 'toughness' => '1', 'text' => "When this creature enters, draw a card at the beginning of the next turn's upkeep."],
        'Carrier Pigeons' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Bird', 'colors' => ['W'], 'power' => '1', 'toughness' => '1', 'text' => "Flying\nWhen this creature enters, draw a card at the beginning of the next turn's upkeep."],
        'Regrowth' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'colors' => ['G'], 'text' => 'Return target card from your graveyard to your hand.'],
        'Coffin Purge' => ['manaCost' => '{B}', 'type' => 'Instant', 'colors' => ['B'], 'text' => "Exile target card from a graveyard.\nFlashback {B} (You may cast this card from your graveyard for its flashback cost. Then exile it.)"],
        'Reverberate' => ['manaCost' => '{R}{R}', 'type' => 'Instant', 'colors' => ['R'], 'text' => 'Copy target instant or sorcery spell. You may choose new targets for the copy.'],
        'Time Walk' => ['manaCost' => '{1}{U}', 'type' => 'Sorcery', 'colors' => ['U'], 'text' => 'Take an extra turn after this one.'],
    ];

    private static function more(string $name): array
    {
        return ['uuid' => 'delayed-draw-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private function put(int $seat, string $name, string $zone = GameObject::HAND): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function reload(): Game
    {
        return $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    private function assertInvalidSave(array $data): void
    {
        try {
            Game::fromArray($data);
            $this->fail('Malformed saved delayed draws must be rejected.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testRealCardWordingIsReadWhole(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $this->assertSame([], (new CardDefinition(self::more($name)))->unsupported, $name);
        }
        foreach (['Flare', 'Heal', 'Ray of Erasure'] as $name) {
            $this->assertSame('draw_next_upkeep', (new CardDefinition(self::more($name)))->effects[1]['type'], $name);
        }
    }

    public function testFlareDrawWaitsForTheOpponentsUpkeepAndCanBeRespondedTo(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $this->lands(1, 'Mountain', 1);
        $flare = $this->put(0, 'Flare');
        $bolt = $this->hand(1, 'Lightning Bolt');
        $game->cast(0, $flare, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(19, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($flare));
        $this->assertCount(0, $game->players[0]->hand);
        $this->assertCount(1, $game->delayedDraws);
        $this->assertSame([], $game->stack);

        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(1, $game->active);
        $this->assertCount(0, $game->players[0]->hand, 'Beginning upkeep puts the draw on the stack, not into hand.');
        $this->assertSame([], $game->delayedDraws);
        $this->assertCount(1, $game->stack);
        $this->assertSame(0, $game->stack[0]['controller']);
        $this->assertSame('ability', $game->stack[0]['kind']);

        $game->cast(1, $bolt, 0, ['p:0']);
        $this->resolve();
        $this->assertSame(17, $this->life(0));
        $this->assertCount(0, $game->players[0]->hand);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertCount(0, $game->players[1]->hand, 'This is not the active player’s normal draw.');
    }

    public function testHealPreventsDamageImmediatelyButDrawsOnlyNextTurn(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 1);
        $this->lands(1, 'Mountain', 1);
        $heal = $this->put(0, 'Heal');
        $shock = $this->hand(1, 'Shock');
        $game->cast(0, $heal, 0, ['p:0']);
        $this->resolve();
        $this->assertCount(0, $game->players[0]->hand);
        $game->pass(0);
        $game->cast(1, $shock, 0, ['p:0']);
        $this->resolve();
        $this->assertSame(19, $this->life(0), 'Heal prevented one of the two damage.');
        $this->assertCount(0, $game->players[0]->hand);
        $this->passUntil(Step::Upkeep, 2);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testRayMillsItsTargetButTheCasterGetsTheDelayedDraw(): void
    {
        $game = $this->newGame();
        $this->lands(1, 'Island', 1);
        $ray = $this->put(1, 'Ray of Erasure');
        $game->pass(0);
        $game->cast(1, $ray, 0, ['p:0']);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->graveyard);
        $this->assertCount(0, $game->players[1]->hand);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(1, $game->stack[0]['controller']);
        $this->resolve();
        $this->assertCount(1, $game->players[1]->hand);
        $this->assertCount(0, $game->players[0]->hand);
    }

    public function testSavingTheOriginalSpellOnTheStackDoesNotScheduleItEarly(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $flare = $this->put(0, 'Flare');
        $game->cast(0, $flare, 0, ['p:1']);
        $this->assertSame([], $game->delayedDraws);
        $game = $this->reload();
        $this->assertSame([], $game->delayedDraws);
        $this->resolve();
        $this->assertCount(1, $game->delayedDraws);
        $this->assertCount(0, $game->players[0]->hand);
        $this->passUntil(Step::Upkeep, 2);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testSourceExileAndBothSaveStagesPreserveOneDelayedDrawOnly(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $this->lands(0, 'Swamp', 1);
        $flare = $this->put(0, 'Flare');
        $purge = $this->put(0, 'Coffin Purge');
        $game->cast(0, $flare, 0, ['p:1']);
        $this->resolve();
        $game->cast(0, $purge, 0, ["o:{$flare}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($flare));
        $game = $this->reload();
        $this->assertCount(1, $game->delayedDraws);
        $this->passUntil(Step::Upkeep, 2);
        $game = $this->reload();
        $this->assertSame([], $game->delayedDraws);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $game = $this->reload();
        $this->passUntil(Step::Upkeep, 3);
        $this->assertSame([], $game->stack);
        $this->assertCount(1, $game->players[0]->hand);
        $this->passUntil(Step::Upkeep, 4);
        $this->assertSame([], $game->stack);
        $this->assertSame([], $game->delayedDraws);
        $this->assertCount(2, $game->players[0]->hand, 'Only turn 3’s normal draw was added.');
    }

    public function testRecastingTheSameObjectSchedulesIndependentDraws(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 6);
        $this->lands(0, 'Forest', 2);
        $flare = $this->put(0, 'Flare');
        $regrowth = $this->put(0, 'Regrowth');
        $game->cast(0, $flare, 0, ['p:1']);
        $this->resolve();
        $first = $game->delayedDraws[0];
        $game->cast(0, $regrowth, 0, ["o:{$flare}"]);
        $this->resolve();
        $game->cast(0, $flare, 0, ['p:1']);
        $this->resolve();
        $this->assertCount(2, $game->delayedDraws);
        $this->assertSame($first, $game->delayedDraws[0], 'Recasting must not replace the original delayed trigger.');
        $this->assertSame(18, $this->life(1));
        $game = $this->reload();
        $this->passUntil(Step::Upkeep, 2);
        $this->assertCount(2, $game->stack);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);
    }

    public function testSpellCopiesCreateIndependentDelayedDrawsOnResolution(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 5);
        $flare = $this->put(0, 'Flare');
        $copy = $this->put(0, 'Reverberate');
        $game->cast(0, $flare, 0, ['p:1']);
        $game->cast(0, $copy, 0, ["s:{$game->stack[0]['id']}"]);
        $this->resolve();
        $this->assertSame([], $game->delayedDraws);
        $this->resolve();
        $this->assertCount(1, $game->delayedDraws);
        $this->resolve();
        $this->assertCount(2, $game->delayedDraws);
        $this->assertSame(18, $this->life(1));
        $this->passUntil(Step::Upkeep, 2);
        $this->resolve();
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);
    }

    public function testEachPlayersDelayedDrawKeepsItsOwnController(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $this->lands(1, 'Island', 1);
        $first = $this->put(0, 'Ray of Erasure');
        $second = $this->put(1, 'Ray of Erasure');
        $game->cast(0, $first, 0, ['p:1']);
        $this->resolve();
        $game->pass(0);
        $game->cast(1, $second, 0, ['p:0']);
        $this->resolve();
        $this->passUntil(Step::Upkeep, 2);
        $this->assertCount(2, $game->stack);
        $this->assertSame(1, $game->active);
        $this->assertSame([1, 0], array_column($game->stack, 'controller'), 'The active player’s trigger is below the nonactive player’s trigger.');
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertCount(0, $game->players[1]->hand);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertCount(1, $game->players[1]->hand);
    }

    public function testSavingAPendingDelayedDrawBehindAnUpkeepTargetChoicePreservesApnapOrder(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $ray = $this->put(0, 'Ray of Erasure');
        $game->cast(0, $ray, 0, ['p:1']);
        $this->resolve();
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->battlefield(1, 'Grizzly Bears');
        $upkeep = ['uuid' => 'targeted-upkeep-fixture', 'name' => 'Targeted Upkeep Fixture', 'type' => 'Enchantment', 'text' => 'At the beginning of your upkeep, target creature gets +1/+1 until end of turn.'];
        $this->assertSame([], (new CardDefinition($upkeep))->unsupported);
        $game->addCard(1, $upkeep, GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->delayedDraws);
        $this->assertSame([1, 0], array_column($game->pendingTriggers, 'controller'));
        $this->assertSame('trigger', $game->decision(1));

        $game = $this->reload();
        $this->assertSame([], $game->delayedDraws);
        $this->assertSame([1, 0], array_column($game->pendingTriggers, 'controller'));
        $this->assertSame('trigger', $game->decision(1));
        $game->chooseTriggerTargets(1, ["o:{$bears}"]);
        $this->assertSame([], $game->pendingTriggers);
        $this->assertSame([1, 0], array_column($game->stack, 'controller'));
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertCount(0, $game->players[1]->hand);
        $this->assertCount(1, $game->stack);
        $this->assertSame(2, $game->power($game->objects[$bears]));
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$bears]));
        $this->passUntil(Step::Upkeep, 3);
        $this->assertSame([], $game->stack);
        $this->assertSame([], $game->delayedDraws);
        $this->assertCount(1, $game->players[0]->hand, 'Resuming the pending trigger must not schedule a duplicate draw.');
    }

    public function testCounteredSpellsDoNotCreateDelayedDraws(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $this->lands(1, 'Island', 2);
        $flare = $this->put(0, 'Flare');
        $counter = $this->hand(1, 'Counterspell');
        $game->cast(0, $flare, 0, ['p:1']);
        $game->pass(0);
        $game->cast(1, $counter, 0, ["s:{$game->stack[0]['id']}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($flare));
        $this->assertSame([], $game->delayedDraws);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->stack);
        $this->assertCount(0, $game->players[0]->hand);
    }

    public function testAFlareWithNoLegalTargetsDoesNotScheduleItsDraw(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $this->lands(1, 'Island', 1);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $flare = $this->put(0, 'Flare');
        $bounce = $this->hand(1, 'Unsummon');
        $game->cast(0, $flare, 0, ["o:{$bears}"]);
        $game->pass(0);
        $game->cast(1, $bounce, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($bears));
        $this->assertSame([], $game->delayedDraws);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->stack);
        $this->assertCount(0, $game->players[0]->hand);
    }

    public function testCastingDuringAnUpkeepWaitsForTheFollowingTurn(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $ray = $this->put(0, 'Ray of Erasure');
        $this->passUntil(Step::Upkeep, 2);
        $game->pass(1);
        $game->cast(0, $ray, 0, ['p:1']);
        $this->resolve();
        $this->assertCount(1, $game->delayedDraws);
        $this->assertSame([], $game->stack);
        $this->assertCount(0, $game->players[0]->hand);
        $this->passUntil(Step::BeginCombat, 2);
        $this->assertCount(1, $game->delayedDraws);
        $this->assertCount(0, $game->players[0]->hand);
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testTheNextTurnMayBeAnExtraTurnForTheSamePlayer(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 3);
        $ray = $this->put(0, 'Ray of Erasure');
        $walk = $this->put(0, 'Time Walk');
        $game->cast(0, $ray, 0, ['p:1']);
        $this->resolve();
        $game->cast(0, $walk);
        $this->resolve();
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(0, $game->active);
        $this->assertCount(1, $game->stack);
        $this->assertCount(0, $game->players[0]->hand);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testSkippingTheNormalDrawStepDoesNotSkipTheDelayedDraw(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $game->addCard(0, ['uuid' => 'skip-draw-fixture', 'name' => 'Skip Draw Fixture', 'type' => 'Enchantment', 'text' => 'Skip your draw step.'], GameObject::BATTLEFIELD);
        $ray = $this->put(0, 'Ray of Erasure');
        $this->passUntil(Step::Upkeep, 2);
        $game->pass(1);
        $game->cast(0, $ray, 0, ['p:1']);
        $this->resolve();
        $this->passUntil(Step::Upkeep, 3);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertCount(1, $game->players[0]->hand, 'The upkeep draw occurred, but the normal turn-based draw did not.');
    }

    public function testRealEntryTriggersScheduleOnlyWhenTheirAbilitiesResolve(): void
    {
        foreach (['Pyknite' => ['Forest', 3], 'Carrier Pigeons' => ['Plains', 4]] as $name => [$land, $count]) {
            $game = $this->newGame();
            $this->lands(0, $land, $count);
            $creature = $this->put(0, $name);
            $game->cast(0, $creature);
            $this->resolve();
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($creature));
            $this->assertSame([], $game->delayedDraws);
            $this->assertCount(1, $game->stack);
            $this->resolve();
            $this->assertCount(1, $game->delayedDraws);
            $this->assertCount(0, $game->players[0]->hand);
            $this->passUntil(Step::Upkeep, 2);
            $this->resolve();
            $this->assertCount(1, $game->players[0]->hand, $name);
        }
    }

    public function testASacrificedSourcesOwnerDoesNotReplaceTheAbilityController(): void
    {
        $game = $this->newGame();
        $card = ['uuid' => 'delayed-ledger', 'name' => 'Delayed Ledger', 'type' => 'Artifact', 'manaCost' => '{1}', 'text' => "Sacrifice this artifact: Draw a card at the beginning of the next turn's upkeep."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $ledger = $game->addCard(1, $card, GameObject::BATTLEFIELD);
        $ledger->controller = 0;
        $game->activate(0, $ledger->id, 0);
        $this->assertSame(GameObject::GRAVEYARD, $ledger->zone);
        $this->assertSame(1, $ledger->controller, 'The sacrificed object has reverted to its owner.');
        $this->assertSame([], $game->delayedDraws);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(0, $game->delayedDraws[0]['controller']);
        $game = $this->reload();
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(0, $game->stack[0]['controller']);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertCount(0, $game->players[1]->hand);
    }

    public function testBouncingTheSourceBeforeItsEntryTriggerResolvesStillSchedulesTheDraw(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 3);
        $this->lands(1, 'Island', 1);
        $pyknite = $this->put(0, 'Pyknite');
        $bounce = $this->hand(1, 'Unsummon');
        $game->cast(0, $pyknite);
        $this->resolve();
        $entryIncarnation = $game->stack[0]['incarnation'];
        $game->pass(0);
        $game->cast(1, $bounce, 0, ["o:{$pyknite}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($pyknite));
        $this->assertNotSame($entryIncarnation, $game->objects[$pyknite]->incarnation);
        $this->assertSame([], $game->delayedDraws);
        $this->assertCount(1, $game->stack);
        $game = $this->reload();
        $this->resolve();
        $this->assertCount(1, $game->delayedDraws, 'The entry trigger resolves independently of its departed source.');
        $this->assertSame($pyknite, $game->delayedDraws[0]['source']);
        $this->assertCount(1, $game->players[0]->hand, 'Only the bounced Pyknite is in hand.');
        $game = $this->reload();
        $this->passUntil(Step::Upkeep, 2);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);
        $this->assertContains($pyknite, $game->players[0]->hand);
    }

    public function testNearbyUnsupportedWordingIsNotAcceptedAsAMandatorySingleDraw(): void
    {
        foreach ([
            "You may draw a card at the beginning of the next turn's upkeep.",
            "Draw two cards at the beginning of the next turn's upkeep.",
            "Draw up to one card at the beginning of the next turn's upkeep.",
            "Target player draws a card at the beginning of the next turn's upkeep.",
            "You draw a card at the beginning of the next turn's upkeep.",
            'Draw a card at the beginning of your next upkeep.',
            'Draw a card at the beginning of each upkeep.',
        ] as $text) {
            $card = new CardDefinition(['uuid' => 'unsupported-delayed-draw', 'name' => 'Unsupported Delayed Draw', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => $text]);
            $this->assertNotSame([], $card->unsupported, $text);
            $this->assertSame([], $card->effects, $text);
        }
    }

    public function testLegacySavesDefaultTheQueueAndFutureVersionsAreRejected(): void
    {
        $data = $this->newGame()->toArray();
        $this->assertSame(Game::STATE_VERSION, $data['schemaVersion']);
        unset($data['delayedDraws']);
        foreach ([null, 1, 2] as $version) {
            $legacy = $data;
            if ($version === null) {
                unset($legacy['schemaVersion']);
            } else {
                $legacy['schemaVersion'] = $version;
            }
            $loaded = Game::fromArray($legacy);
            $this->assertSame([], $loaded->delayedDraws);
            $this->assertSame(Game::STATE_VERSION, $loaded->toArray()['schemaVersion']);
        }
        $this->assertInvalidSave($data);
        $data['schemaVersion'] = Game::STATE_VERSION + 1;
        $this->assertInvalidSave($data);
    }

    public function testMalformedDelayedDrawRecordsAreRejectedInsteadOfDiscarded(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $game->cast(0, $this->put(0, 'Ray of Erasure'), 0, ['p:1']);
        $this->resolve();
        $data = $game->toArray();
        $this->assertCount(1, $data['delayedDraws']);
        foreach (['not a queue', null, ['not a record'], ['draw' => $data['delayedDraws'][0]]] as $queue) {
            $invalid = $data;
            $invalid['delayedDraws'] = $queue;
            $this->assertInvalidSave($invalid);
        }
        foreach ([
            ['createdTurn', '1'], ['createdTurn', -1], ['createdTurn', 0], ['createdTurn', $game->turn + 1],
            ['source', 999999], ['controller', 999999],
            ['incarnation', -1], ['incarnation', $game->objects[$data['delayedDraws'][0]['source']]->incarnation + 1],
            ['label', []], ['label', ''],
        ] as [$field, $value]) {
            $invalid = $data;
            $invalid['delayedDraws'][0][$field] = $value;
            $this->assertInvalidSave($invalid);
        }
        foreach (['createdTurn', 'source', 'incarnation', 'controller', 'label'] as $field) {
            $invalid = $data;
            unset($invalid['delayedDraws'][0][$field]);
            $this->assertInvalidSave($invalid);
        }
        foreach ([1, 2] as $version) {
            $invalid = $data;
            $invalid['schemaVersion'] = $version;
            $this->assertInvalidSave($invalid);
        }
    }
}
