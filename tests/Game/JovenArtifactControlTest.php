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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class JovenArtifactControlTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function selected(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        $entry = array_values(array_filter($entries, fn (array $entry) => $entry['card']['uuid'] === '6e88e450-3e9c-575e-96b2-6b0ac6a9434d'))[0];
        $this->assertSame('MBC', $entry['setCode']);

        return $entry['card'];
    }

    private function source(int $seat, bool $ready = true): int
    {
        $card = $this->selected();
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertCount(1, $definition->activated);
        $this->assertSame(['control', 'untap', 'pump'], array_column($definition->activated[0]['effects'], 'type'));
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        if ($ready) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
            $this->assertSame($seat, $this->game->active);
            $this->assertFalse($this->game->objects[$id]->sick);
        }

        return $id;
    }

    /** @dataProvider subjects */
    public function testPaidActivationControlsUntapsAndGrantsTemporaryHasteWithJson(int $actor, int $controller, bool $creature): void
    {
        $this->newGame();
        $source = $this->source($actor);
        $target = $this->battlefield(1 - $controller, $creature ? 'Bottle Gnomes' : 'Bonesplitter');
        $this->game->objects[$target]->controller = $controller;
        $this->game->objects[$target]->tapped = true;
        $before = $this->saved();
        $this->game->activate($actor, $source, 0, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $tapped = array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->controller === $actor && $o->name() === 'Mountain' && $o->tapped);
        $this->assertCount(3, $tapped);
        $queued = $this->saved();
        $this->resolveOne();
        $object = $this->game->objects[$target];
        $this->assertSame($actor, $object->controller);
        $this->assertSame($controller === $actor ? null : $controller, $object->borrowedFrom);
        $this->assertFalse($object->tapped);
        $this->assertTrue($this->game->hasKeyword($object, 'haste'));
        $this->assertSame($creature, in_array($target, $this->game->attackCandidates(), true));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->stack === []) {
                $this->game->activate($actor, $source, 0, ['o:'.$target]);
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame($controller, $this->game->objects[$target]->controller);
        $this->assertNull($this->game->objects[$target]->borrowedFrom);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    private function move(int $id, string $zone, bool $returns = false, int $controller = 0): void
    {
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], $zone);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$id], $controller, false, false);
        }
    }

    /** @dataProvider rejectedCosts */
    public function testInvalidCostsAndTargetsAreAtomic(string $case): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $actor = 0;
        $targets = ['o:'.$target];
        $mountains = array_values(array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->name() === 'Mountain'));
        switch ($case) {
            case 'tapped': $this->game->objects[$source]->tapped = true;
                break;
            case 'sick': $this->game->objects[$source]->sick = true;
                break;
            case 'wrong controller': $this->game->objects[$source]->controller = 1;
                break;
            case 'wrong priority': $actor = 1;
                break;
            case 'source gone': $this->move($source, GameObject::EXILE);
                break;
            case 'insufficient red':
                foreach (array_slice($mountains, 0, 3) as $mountain) {
                    $mountain->tapped = true;
                }
                $this->lands(0, 'Forest', 3);
                $this->lands(1, 'Mountain', 3);
                break;
            case 'player': $targets = ['p:1'];
                break;
            case 'nonartifact': $targets = ['o:'.$this->battlefield(1, 'Grizzly Bears')];
                break;
            case 'target gone': $this->move($target, GameObject::EXILE);
                break;
            case 'stale target':
                $targets = ['o:'.$target.':'.$this->game->objects[$target]->incarnation];
                $this->move($target, GameObject::EXILE, true, 1);
                break;
        }
        $before = $this->saved();
        try {
            $this->game->activate($actor, $source, 0, $targets);
            $this->fail('Invalid activation was accepted: '.$case);
        } catch (GameException) {
            $this->assertSame($before, $this->saved(), 'No payment, tap or stack item on rejection.');
        }
    }

    public static function rejectedCosts(): array
    {
        return array_map(fn (string $s) => [$s], ['tapped', 'sick', 'wrong controller', 'wrong priority', 'source gone', 'insufficient red', 'player', 'nonartifact', 'target gone', 'stale target']);
    }

    /** @dataProvider movement */
    public function testQueuedActivationRetainsActorWhenSourceLeavesOrChangesController(string $zone, bool $returns): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->objects[$target]->tapped = true;
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        if ($zone === GameObject::BATTLEFIELD) {
            $this->game->objects[$source]->controller = 1;
        } else {
            $this->move($source, $zone, $returns, 1);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$target]->controller);
        $this->assertFalse($this->game->objects[$target]->tapped);
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$target], 'haste'));
        $this->game = Game::fromArray($this->saved());
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(1, $this->game->objects[$target]->controller);
        $this->assertNull($this->game->objects[$target]->borrowedFrom);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    public static function movement(): array
    {
        return [[GameObject::EXILE, false], [GameObject::EXILE, true], [GameObject::HAND, false], [GameObject::BATTLEFIELD, false]];
    }

    /** @dataProvider targetMovement */
    public function testOldTargetCannotBeControlledUntappedOrHastedAfterMovement(bool $returns): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->move($target, GameObject::EXILE, $returns, 1);
        $this->game->objects[$target]->tapped = true;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(1, $this->game->objects[$target]->controller);
        $this->assertTrue($this->game->objects[$target]->tapped);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
        $this->assertNull($this->game->objects[$target]->borrowedFrom);
        $this->assertTrue($this->game->objects[$source]->tapped, 'The paid cost is not refunded.');
    }

    public static function targetMovement(): array
    {
        return [[false], [true]];
    }

    public function testTemporaryEffectsEndWhenTheBorrowedArtifactLeavesAndReturns(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->move($target, GameObject::EXILE, true, 1);
        $this->assertSame(1, $this->game->objects[$target]->controller);
        $this->assertNull($this->game->objects[$target]->borrowedFrom);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(1, $this->game->objects[$target]->controller);
    }

    public function testActivationOnOpponentsTurnStillExpiresThatTurn(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->pass(1);
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$target]->controller);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(1, $this->game->objects[$target]->controller);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    public function testStunCounterPreventsOnlyTheUntap(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->objects[$target]->tapped = true;
        $this->game->objects[$target]->addCounters('stun', 1);
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$target]->controller);
        $this->assertTrue($this->game->objects[$target]->tapped);
        $this->assertSame(0, $this->game->objects[$target]->counter('stun'));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    public function testActivatedArtifactCanAttackAndItsBurialStillTriggersTheSource(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, [$target]);
        $this->assertTrue($this->game->objects[$target]->tapped);
        $this->move($target, GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testPracticePassesThroughSavedActivationWithoutNeedingANewDecision(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $friendly = $this->battlefield(0, 'Bonesplitter');
        $ability = $this->game->objects[$source]->definition()->activated[0];
        $chosen = (new \ReflectionMethod(PracticeBot::class, 'targets'))->invoke(null, $this->game, 0, ['artifact'], $ability['effects'], $source);
        $this->assertSame(['o:'.$target], $chosen, 'Existing harmful-target policy prefers an opposing artifact.');
        $this->game->activate(0, $source, 0, $chosen);
        $this->game = Game::fromArray($this->saved());
        $this->assertTrue(PracticeBot::act($this->game, $this->game->priority));
        $this->assertTrue(PracticeBot::act($this->game, $this->game->priority));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(0, $this->game->objects[$target]->controller);
        $this->assertNull($this->game->objects[$friendly]->borrowedFrom);
    }

    public function testTargetThatStopsBeingAnArtifactIsRevalidatedBeforeEveryEffect(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->objects[$target]->tapped = true;
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->game->objects[$target]->faceDown = true; // Supporting type-change fixture: now a nonartifact 2/2.
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(1, $this->game->objects[$target]->controller);
        $this->assertTrue($this->game->objects[$target]->tapped);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    public function testControllerAtResolutionIsTheRestorationBoundary(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $this->game->objects[$target]->controller = 0; // Supporting controller-change fixture, not an Aura-layer test.
        $this->resolveOne();
        $this->assertNull($this->game->objects[$target]->borrowedFrom);
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$target], 'haste'));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(0, $this->game->objects[$target]->controller, 'Owner is not a fallback controller.');
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    public function testRepeatedActivationCannotPayTheTapCostTwice(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $target = $this->battlefield(1, 'Bottle Gnomes');
        $this->lands(0, 'Mountain', 3);
        $this->game->activate(0, $source, 0, ['o:'.$target]);
        $before = $this->saved();
        try {
            $this->game->activate(0, $source, 0, ['o:'.$target]);
            $this->fail('Tapped source cannot be reused.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
            $this->assertCount(1, $this->game->stack);
        }
    }

    public function testNearbyUnimplementedControlDurationsRemainUnread(): void
    {
        foreach (['Gain control of target permanent until end of turn.', 'Gain control of target artifact.', 'Gain control of target artifact until your next turn.'] as $text) {
            $card = $this->selected();
            $card['text'] = '{R}{R}{R}, {T}: '.$text;
            $this->assertNotSame([], (new CardDefinition($card))->unsupported);
        }
    }

    public static function subjects(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $creature) {
                    $cases[] = [$actor, $controller, $creature];
                }
            }
        }

        return $cases;
    }
}
