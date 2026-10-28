<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

final class ControlChangeCombatTest extends GameTestCase
{
    /** @dataProvider cases */
    public function testChangedAttackerIsNotBoostedEvenIfControlReturns(int $seat, bool $aura, bool $return): void
    {
        [$captain, $attacker, $remaining] = $this->setupCombat($seat);
        $other = 1 - $seat;
        $this->lands($other, 'Island', 5);
        $this->lands($seat, 'Forest', 2);
        $this->game->activate($seat, $captain, 0);
        $this->game->pass($seat);
        $card = $aura
            ? ['name' => 'Flash Control Fixture', 'type' => 'Enchantment - Aura', 'manaCost' => '{3}{U}{U}', 'text' => "Flash\nEnchant creature\nYou control enchanted creature."]
            : ['name' => 'Control Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => 'Gain control of target creature until end of turn.'];
        $control = $this->game->addCard($other, $card, GameObject::HAND)->id;
        $this->game->cast($other, $control, 0, ["o:{$attacker}"]);
        $this->resolve();
        $this->assertSame($other, $this->game->objects[$attacker]->controller);
        if ($return) {
            $returnCard = $aura
                ? ['name' => 'Disenchant Fixture', 'type' => 'Instant', 'manaCost' => '{1}{G}', 'text' => 'Destroy target artifact or enchantment.']
                : ['name' => 'Return Control Fixture', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => 'Gain control of target creature until end of turn.'];
            $spell = $this->game->addCard($seat, $returnCard, GameObject::HAND)->id;
            $this->game->cast($seat, $spell, 0, [$aura ? "o:{$control}" : "o:{$attacker}"]);
            $this->resolve();
            $this->assertSame($seat, $this->game->objects[$attacker]->controller);
        }
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey($attacker, $this->game->attackers);
        $this->resolve();
        $this->assertSame(2, $this->game->power($this->game->objects[$attacker]));
        $this->assertArrayHasKey($remaining, $this->game->attackers);
        $this->assertSame(3, $this->game->power($this->game->objects[$remaining]));
    }

    /** @dataProvider unchangedCases */
    public function testSameControllerDoesNotRemoveAttacker(int $seat, bool $aura): void
    {
        [$captain, $attacker] = $this->setupCombat($seat);
        $this->lands($seat, 'Island', 5);
        $this->game->activate($seat, $captain, 0);
        $card = $aura
            ? ['name' => 'Flash Control Fixture', 'type' => 'Enchantment - Aura', 'manaCost' => '{3}{U}{U}', 'text' => "Flash\nEnchant creature\nYou control enchanted creature."]
            : ['name' => 'Control Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => 'Gain control of target creature until end of turn.'];
        $spell = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ["o:{$attacker}"]);
        $this->resolve();
        $this->assertSame($seat, $this->game->objects[$attacker]->controller);
        $this->assertArrayHasKey($attacker, $this->game->attackers);
        $this->resolve();
        $this->assertSame(3, $this->game->power($this->game->objects[$attacker]));
    }

    /** @dataProvider blockingCases */
    public function testControlChangeUpdatesMultipleBlockRelationships(int $seat, bool $aura, string $remove): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $defender = 1 - $seat;
        $this->lands($seat, 'Island', 5);
        $this->lands($defender, 'Island', 5);
        $first = $this->battlefield($seat, 'Grizzly Bears');
        $second = $this->battlefield($seat, 'Grizzly Bears');
        $blocker = $game->addCard($defender, ['name' => 'Two-block fixture', 'type' => 'Creature - Human Soldier', 'manaCost' => '{W}',
            'power' => '3', 'toughness' => '5', 'text' => 'This creature can block an additional creature each combat.'], GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, $seat === 0 ? 1 : 2);
        $this->game->declareAttackers($seat, [$first, $second]);
        $this->game->pass($seat);
        $this->game->pass($defender);
        $this->game->declareBlockers($defender, [$blocker => [$first, $second]]);
        $target = match ($remove) {
            'first' => $first, 'second' => $second, default => $blocker
        };
        $thief = $remove === 'blocker' ? $seat : $defender;
        if ($thief !== $seat) {
            $this->game->pass($seat);
        }
        $card = $aura
            ? ['name' => 'Flash Control Fixture', 'type' => 'Enchantment - Aura', 'manaCost' => '{3}{U}{U}', 'text' => "Flash\nEnchant creature\nYou control enchanted creature."]
            : ['name' => 'Control Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => 'Gain control of target creature until end of turn.'];
        $spell = $this->game->addCard($thief, $card, GameObject::HAND)->id;
        $this->game->cast($thief, $spell, 0, ["o:{$target}"]);
        $this->resolve();
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        if ($remove === 'blocker') {
            $this->assertArrayNotHasKey($blocker, $this->game->blockers);
            $this->assertArrayHasKey($first, $this->game->blocked);
            $this->assertArrayHasKey($second, $this->game->blocked);
        } else {
            $remaining = $remove === 'first' ? $second : $first;
            $this->assertArrayNotHasKey($target, $this->game->attackers);
            $this->assertSame($remaining, $this->game->blockers[$blocker]);
            $this->assertArrayNotHasKey($blocker, $this->game->extraBlocks);
            $this->assertArrayHasKey($remaining, $this->game->blocked);
        }
        $this->passUntil(Step::PostcombatMain, $seat === 0 ? 1 : 2);
        $this->assertSame(20, $this->game->players[$defender]->life);
    }

    public static function unchangedCases(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    public static function blockingCases(): array
    {
        $cases = [];
        foreach ([0, 1] as $seat) {
            foreach ([false, true] as $aura) {
                foreach (['first', 'second', 'blocker'] as $remove) {
                    $cases[] = [$seat, $aura, $remove];
                }
            }
        }

        return $cases;
    }

    /** @return array{int, int, int} */
    private function setupCombat(int $seat): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/untap-symbol-combat-support.json'), true, flags: JSON_THROW_ON_ERROR)['cards'][1];
        $this->lands($seat, 'Plains', 3);
        $captain = $game->addCard($seat, $card, GameObject::HAND)->id;
        $game->cast($seat, $captain);
        $this->resolve();
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $this->game->objects[$captain]->tapped = true;
        $attacker = $this->battlefield($seat, 'Grizzly Bears');
        $remaining = $this->battlefield($seat, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, $seat === 0 ? 3 : 4);
        $this->game->declareAttackers($seat, [$attacker, $remaining]);

        return [$captain, $attacker, $remaining];
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    public static function cases(): array
    {
        $cases = [];
        foreach ([0, 1] as $seat) {
            foreach ([false, true] as $aura) {
                foreach ([false, true] as $return) {
                    $cases[] = [$seat, $aura, $return];
                }
            }
        }

        return $cases;
    }
}
