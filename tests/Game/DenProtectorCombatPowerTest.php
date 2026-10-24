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

/** @covers \MTGPocket\Game\Game */
final class DenProtectorCombatPowerTest extends GameTestCase
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

    private function paidAttacker(int $actor, string $mode): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/den-protector-megamorph.json'));
        $this->assertSame('d35324e93fdf9a45b288f238e9db4ddf801857cd8870a6a6032ffaf8c90f81b7', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('e0c322a9-4486-5282-9d62-4a1bf6d49453', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $morph = in_array($mode, ['morph', 'megamorph', 'copy-morph'], true);
        $lands = $this->lands($actor, 'Forest', $morph ? 3 : 2);
        $id = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $id, 0, [], $morph ? 'morph' : '');
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertTrue($this->game->objects[$id]->sick);

        if (str_starts_with($mode, 'copy-')) {
            $island = $this->lands($actor, 'Island', 1)[0];
            $spell = $this->game->addCard($actor, [
                'name' => 'Combat Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}',
                'text' => "Create a token that's a copy of target permanent you control.",
            ], GameObject::HAND)->id;
            $ids = array_keys($this->game->objects);
            $this->game->cast($actor, $spell, 0, ['o:'.$id]);
            $this->assertTrue($this->game->objects[$island]->tapped);
            $this->resolveOne();
            $created = array_values(array_diff(array_keys($this->game->objects), $ids));
            $this->assertCount(1, $created);
            $id = $created[0];
            $this->assertTrue($this->game->objects[$id]->definition()->isToken());
            $this->assertFalse($this->game->objects[$id]->faceDown);
        }

        // Real turn progression, rather than clearing summoning sickness by hand.
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
        $this->assertSame($actor, $this->game->active);
        $this->assertFalse($this->game->objects[$id]->sick);
        if ($mode === 'megamorph') {
            $this->turnUp($actor, $id);
        }

        return $id;
    }

    private function turnUp(int $actor, int $id): void
    {
        $this->assertSame($actor, $this->game->priority);
        $untapped = $this->untappedLands($actor);
        $this->game->activate($actor, $id, 0);
        $paid = array_filter($untapped, fn (GameObject $land) => $land->tapped);
        $this->assertCount(2, $paid);
        $this->assertFalse($this->game->objects[$id]->faceDown);
        $this->assertSame(1, $this->game->objects[$id]->counter('+1/+1'));
        $this->assertSame(3, $this->game->power($this->game->objects[$id]));
        $this->assertNull($this->game->pendingChoice, 'There is no graveyard target in this combat-only setup.');
        $this->assertSame([], $this->game->stack);
    }

    /** @return GameObject[] */
    private function untappedLands(int $actor): array
    {
        return array_values(array_filter($this->game->permanents($actor), fn (GameObject $land) => $land->definition()->isLand() && ! $land->tapped));
    }

    private function blocker(int $actor, int $power, int $toughness = 7): int
    {
        return $this->game->addCard(1 - $actor, [
            'name' => 'Power Boundary Unit '.$power, 'type' => 'Creature',
            'power' => (string) $power, 'toughness' => (string) $toughness, 'text' => '',
        ], GameObject::BATTLEFIELD)->id;
    }

    private function attack(int $actor, int $id): void
    {
        $this->passUntil(Step::DeclareAttackers);
        $this->assertContains($id, $this->game->attackCandidates());
        $this->game->declareAttackers($actor, [$id]);
    }

    private function rejectWithoutMutation(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('The declaration must be rejected.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->saved());
    }

    /** @dataProvider modes */
    public function testPaidAttackerUsesCurrentFaceForBlockPowerBoundary(int $actor, string $mode): void
    {
        $id = $this->paidAttacker($actor, $mode);
        $restricted = in_array($mode, ['face-up', 'megamorph', 'copy-face-up'], true);
        $power = $this->game->power($this->game->objects[$id]);
        $this->assertSame($mode === 'megamorph' ? 3 : 2, $power);
        $weak = $this->blocker($actor, $power - 1);
        $equal = $this->blocker($actor, $power);
        $strong = $this->blocker($actor, $power + 1);
        $this->attack($actor, $id);
        $this->passUntil(Step::DeclareBlockers);
        $this->game = Game::fromArray($this->saved());
        $this->assertSame($restricted, $this->game->hasKeyword($this->game->objects[$id], "can't be blocked by lesser power"));
        $this->assertSame(! $restricted, $this->game->canBlock($this->game->objects[$weak], $this->game->objects[$id]));
        $this->assertTrue($this->game->canBlock($this->game->objects[$equal], $this->game->objects[$id]));
        $this->assertTrue($this->game->canBlock($this->game->objects[$strong], $this->game->objects[$id]));
        $this->assertSame($restricted ? [$equal, $strong] : [$weak, $equal, $strong], $this->game->blockCandidates());
        if ($restricted) {
            $this->rejectWithoutMutation(fn () => $this->game->declareBlockers(1 - $actor, [$weak => $id]));
        }
        $this->rejectWithoutMutation(fn () => $this->game->declareBlockers($actor, [$equal => $id]));
        $this->game->declareBlockers(1 - $actor, [$equal => $id]);
        $declared = $this->saved();
        $this->game = Game::fromArray($declared);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($equal));
        $this->assertSame($power, $this->game->objects[$equal]->damage);
        $expected = $this->saved();
        $this->game = Game::fromArray($declared);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider actors */
    public function testPaidTurnUpBeforeBlockDeclarationChangesEligibleBlockers(int $actor): void
    {
        $id = $this->paidAttacker($actor, 'morph');
        $weak = $this->blocker($actor, 2);
        $equal = $this->blocker($actor, 3);
        $this->attack($actor, $id);
        $this->assertTrue($this->game->canBlock($this->game->objects[$weak], $this->game->objects[$id]));
        $this->turnUp($actor, $id);
        $this->passUntil(Step::DeclareBlockers);
        $this->game = Game::fromArray($this->saved());
        $this->assertSame([$equal], $this->game->blockCandidates());
        $this->rejectWithoutMutation(fn () => $this->game->declareBlockers(1 - $actor, [$weak => $id]));
        $this->game->declareBlockers(1 - $actor, [$equal => $id]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    /** @dataProvider actors */
    public function testDecliningLegalBlockLetsPrintedAttackerDealPlayerDamage(int $actor): void
    {
        $id = $this->paidAttacker($actor, 'face-up');
        $weak = $this->blocker($actor, 1);
        $this->blocker($actor, 3);
        $this->attack($actor, $id);
        $this->passUntil(Step::DeclareBlockers);
        $this->rejectWithoutMutation(fn () => $this->game->declareBlockers(1 - $actor, [$weak => $id]));
        $this->game->declareBlockers(1 - $actor, []);
        $declared = $this->saved();
        $this->game = Game::fromArray($declared);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(18, $this->life(1 - $actor));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($weak));
        $expected = $this->saved();
        $this->game = Game::fromArray($declared);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider actors */
    public function testTurningUpAfterDeclarationDoesNotRemoveExistingBlock(int $actor): void
    {
        $id = $this->paidAttacker($actor, 'morph');
        $bear = $this->blocker($actor, 2, 2);
        $this->blocker($actor, 3);
        $this->attack($actor, $id);
        $this->passUntil(Step::DeclareBlockers);
        $this->game->declareBlockers(1 - $actor, [$bear => $id]);
        $this->turnUp($actor, $id);
        $this->assertFalse($this->game->canBlock($this->game->objects[$bear], $this->game->objects[$id]));
        $this->assertSame([$bear => $id], $this->game->blockers, 'New restrictions do not undo a block already declared.');
        $this->game = Game::fromArray($this->saved());
        $this->assertSame([$bear => $id], $this->game->blockers);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bear));
        $this->assertSame(20, $this->life(1 - $actor));
    }

    /** @dataProvider growthTargets */
    public function testPaidGrowthChangesTheBoundaryBeforeBlockDeclaration(int $actor, bool $growAttacker): void
    {
        $id = $this->paidAttacker($actor, 'face-up');
        $blocker = $this->blocker($actor, $growAttacker ? 2 : 1);
        $strong = $this->blocker($actor, 5);
        $this->attack($actor, $id);
        $this->assertSame($growAttacker, $this->game->canBlock($this->game->objects[$blocker], $this->game->objects[$id]));
        $caster = $growAttacker ? $actor : 1 - $actor;
        if (! $growAttacker) {
            $this->game->pass($actor);
        }
        $this->lands($caster, 'Forest', 1);
        $untapped = $this->untappedLands($caster);
        $growth = $this->hand($caster, 'Giant Growth');
        $this->game->cast($caster, $growth, 0, ['o:'.($growAttacker ? $id : $blocker)]);
        $this->assertCount(1, array_filter($untapped, fn (GameObject $land) => $land->tapped));
        $this->resolveOne();
        $this->passUntil(Step::DeclareBlockers);
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(! $growAttacker, $this->game->canBlock($this->game->objects[$blocker], $this->game->objects[$id]));
        $this->assertSame($growAttacker ? 5 : 2, $this->game->power($this->game->objects[$id]));
        $this->assertSame($growAttacker ? 2 : 4, $this->game->power($this->game->objects[$blocker]));
        $chosen = $growAttacker ? $strong : $blocker;
        if ($growAttacker) {
            $this->rejectWithoutMutation(fn () => $this->game->declareBlockers(1 - $actor, [$blocker => $id]));
        }
        $this->game->declareBlockers(1 - $actor, [$chosen => $id]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    public static function modes(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach (['face-up', 'morph', 'megamorph', 'copy-face-up', 'copy-morph'] as $mode) {
                $cases[$actor.'-'.$mode] = [$actor, $mode];
            }
        }

        return $cases;
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function growthTargets(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }
}
