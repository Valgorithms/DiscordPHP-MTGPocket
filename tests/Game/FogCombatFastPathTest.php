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

use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * Frozen #444 review finding: paid cached Fog, normal combat, no doubling effect.
 * Battlefield creatures, shields and unpreventable-turn flags isolate engine behavior.
 * No whole-card or enabling-card certification is claimed.
 *
 * @covers \MTGPocket\Game\Game
 */
final class FogCombatFastPathTest extends GameTestCase
{
    private function cached(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/fog-combat-fast-path.json'));
        $this->assertSame('c3e6ce305ee2d0287f4722b467209c271d6ab7e16e27446f44ce70e0d36689b1', hash('sha256', $bytes));

        return array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'], fn (array $card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    /** @return array{int, int, int} */
    private function combatWithPaidFog(string $shields = 'none', bool $unpreventable = false): array
    {
        $this->newGame();
        $goblin = $this->game->addCard(0, $this->cached('Raging Goblin'), GameObject::BATTLEFIELD)->id;
        $courser = $this->game->addCard(0, $this->cached('Centaur Courser'), GameObject::BATTLEFIELD)->id;
        $spider = $this->game->addCard(1, $this->cached('Giant Spider'), GameObject::BATTLEFIELD)->id;
        $forest = $this->lands(1, 'Forest', 1)[0];
        $fog = $this->game->addCard(1, $this->cached('Fog'), GameObject::HAND)->id;
        $this->assertSame([], $this->game->toArray()['damageEffects'] ?? [], 'Both source revisions must use ordinary combat without doubling.');
        if (in_array($shields, ['numbered', 'both'], true)) {
            $this->game->prevent['p:1'] = 5;
            $this->game->prevent['o:'.$courser] = 5;
            $this->game->prevent['o:'.$spider] = 5;
            // A numbered shield's life-gain side effect must not fire for Fog-prevented damage.
            $this->game->preventGain['p:1'] = 1;
        }
        if (in_array($shields, ['counter', 'both'], true)) {
            $this->game->objects[$courser]->addCounters('shield', 1);
            $this->game->objects[$spider]->addCounters('shield', 1);
        }
        if ($unpreventable) {
            // Existing engine status primitive: this does not certify an enabling card.
            $this->game->unpreventableTurn = $this->game->turn;
        }
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers(0, [$goblin, $courser]);
        $this->passUntil(Step::DeclareBlockers);
        $this->game->declareBlockers(1, [$spider => $courser]);
        if ($this->game->priority !== 1) {
            $this->game->pass($this->game->priority);
        }
        $this->game->cast(1, $fog);
        $this->assertTrue($this->game->objects[$forest]->tapped, 'Fog pays its actual green mana cost.');
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fog));
        $this->assertSame($this->game->turn, $this->game->fogTurn);
        $this->passUntil(Step::CombatDamage);

        return [$goblin, $courser, $spider];
    }

    public function testPaidFogPreventsUnblockedPlayerCombatDamageWithoutDoubling(): void
    {
        $this->combatWithPaidFog();
        $this->assertSame(20, $this->life(1));
        $this->assertSame(0, $this->game->players[1]->damagedOnTurn);
        $this->assertSame([], $this->game->stack);
    }

    public function testPaidFogPreventsBlockedCreatureCombatDamageWithoutDoubling(): void
    {
        [, $courser, $spider] = $this->combatWithPaidFog();
        foreach ([$courser, $spider] as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
            $this->assertSame(0, $this->game->objects[$id]->damage);
            $this->assertSame([], $this->game->objects[$id]->damagedBy);
        }
    }

    /** @dataProvider resourceShields */
    public function testFogDoesNotConsumeOtherPreventionResources(string $shields): void
    {
        [, $courser, $spider] = $this->combatWithPaidFog($shields);
        if (in_array($shields, ['numbered', 'both'], true)) {
            foreach (['p:1', 'o:'.$courser, 'o:'.$spider] as $target) {
                $this->assertSame(5, $this->game->prevent[$target], 'Fog must stop combat before numbered shields are consumed.');
            }
        }
        if (in_array($shields, ['counter', 'both'], true)) {
            foreach ([$courser, $spider] as $id) {
                $this->assertSame(1, $this->game->objects[$id]->counter('shield'));
            }
        }
        $this->assertSame(20, $this->life(1), 'No damage or shield life-gain side effect occurs.');
        $this->assertSame([0, 0], [$this->game->objects[$courser]->damage, $this->game->objects[$spider]->damage]);
    }

    /** @dataProvider allShields */
    public function testUnpreventableCombatIgnoresFogAndKeepsShields(string $shields): void
    {
        [, $courser, $spider] = $this->combatWithPaidFog($shields, true);
        $this->assertSame(19, $this->life(1));
        $this->assertSame([2, 3], [$this->game->objects[$courser]->damage, $this->game->objects[$spider]->damage]);
        if (in_array($shields, ['numbered', 'both'], true)) {
            foreach (['p:1', 'o:'.$courser, 'o:'.$spider] as $target) {
                $this->assertSame(5, $this->game->prevent[$target]);
            }
        }
        if (in_array($shields, ['counter', 'both'], true)) {
            $this->assertSame([1, 1], [$this->game->objects[$courser]->counter('shield'), $this->game->objects[$spider]->counter('shield')]);
        }
    }

    public function testFogDoesNotPreventPaidNoncombatSpellDamage(): void
    {
        $this->newGame();
        $this->lands(0, 'Forest', 1);
        $fog = $this->game->addCard(0, $this->cached('Fog'), GameObject::HAND)->id;
        $this->game->cast(0, $fog);
        $this->resolveOne();
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->game->addCard(0, $this->cached('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->cast(0, $bolt, 0, ['p:1']);
        $this->resolveOne();
        $this->assertSame(17, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
    }

    public static function resourceShields(): array
    {
        return [['numbered'], ['counter'], ['both']];
    }

    public static function allShields(): array
    {
        return [['none'], ...self::resourceShields()];
    }
}
