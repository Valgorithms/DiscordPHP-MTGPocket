<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Scoped cached Disfigure gameplay; synthetic targets do not certify other cards. */
final class DisfigureCoverageTest extends GameTestCase
{
    private function disfigure(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-gravelord-death-counters.json'));
        $this->assertSame('c26e0bf5385b4bbf8ad5178f31263bfaf6f03f9391ddcbdc13c5cb3222924300', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][0];
        $this->assertSame('15269cf5-9eb9-5b7d-8c79-42154e8ece24', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $card;
    }

    private function setupSeat(int $seat): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider seatsAndTargets */
    public function testPaidCastAppliesSignedStatsAndExpiresAfterJson(int $seat, int $controller): void
    {
        $this->setupSeat($seat);
        $mana = $this->lands($seat, 'Swamp', 1)[0];
        $victim = $this->game->addCard($controller, ['name' => 'Toughness Probe', 'type' => 'Creature - Wall',
            'power' => '0', 'toughness' => '4'], GameObject::BATTLEFIELD)->id;
        $spell = $this->game->addCard($seat, $this->disfigure(), GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$victim]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->assertSame(GameObject::STACK, $this->zone($spell));
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame([-2, 2], [$this->game->power($this->game->objects[$victim]), $this->game->toughness($this->game->objects[$victim])]);
        $this->restore();
        $this->assertSame([-2, 2], [$this->game->power($this->game->objects[$victim]), $this->game->toughness($this->game->objects[$victim])]);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame([0, 4], [$this->game->power($this->game->objects[$victim]), $this->game->toughness($this->game->objects[$victim])]);
    }

    public static function seatsAndTargets(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    /** @dataProvider seats */
    public function testZeroToughnessKillsIndestructibleWithoutDamage(int $seat): void
    {
        $this->setupSeat($seat);
        $this->lands($seat, 'Swamp', 1);
        $victim = $this->game->addCard(1 - $seat, ['name' => 'Indestructible Probe', 'type' => 'Creature - Construct',
            'power' => '2', 'toughness' => '2', 'text' => 'Indestructible'], GameObject::BATTLEFIELD)->id;
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$victim], 'indestructible'));
        $spell = $this->game->addCard($seat, $this->disfigure(), GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$victim]);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    /** @dataProvider seats */
    public function testIllegalTargetsRejectBeforePaying(int $seat): void
    {
        $this->setupSeat($seat);
        $mana = $this->lands($seat, 'Swamp', 1)[0];
        $spell = $this->game->addCard($seat, $this->disfigure(), GameObject::HAND)->id;
        foreach (['p:'.(1 - $seat), 'o:'.$mana] as $target) {
            $before = $this->game->toArray();
            try {
                $this->game->cast($seat, $spell, 0, [$target]);
                $this->fail('An illegal Disfigure target was accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->game->toArray());
                $this->assertFalse($this->game->objects[$mana]->tapped);
            }
        }
    }

    /** @dataProvider seats */
    public function testRemovedTargetMakesPaidSpellFailToAffectAnything(int $seat): void
    {
        $this->setupSeat($seat);
        $this->lands($seat, 'Swamp', 1);
        $this->lands(1 - $seat, 'Island', 1);
        $victim = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $spell = $this->game->addCard($seat, $this->disfigure(), GameObject::HAND)->id;
        $bounce = $this->hand(1 - $seat, 'Unsummon');
        $this->game->cast($seat, $spell, 0, ['o:'.$victim]);
        $this->game->pass($seat);
        $this->game->cast(1 - $seat, $bounce, 0, ['o:'.$victim]);
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($victim));
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::HAND, $this->zone($victim));
        $this->assertSame([], $this->game->objects[$victim]->untilEndOfTurn);
    }

    /** @dataProvider seats */
    public function testNegativePowerDoesNotDealCombatDamage(int $seat): void
    {
        $this->setupSeat($seat);
        $this->lands($seat, 'Swamp', 1);
        $attacker = $this->game->addCard($seat, ['name' => 'Small Power Probe', 'type' => 'Creature - Beast',
            'power' => '1', 'toughness' => '4'], GameObject::BATTLEFIELD)->id;
        $spell = $this->game->addCard($seat, $this->disfigure(), GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$attacker]);
        $this->resolveOne();
        $this->assertSame([-1, 2], [$this->game->power($this->game->objects[$attacker]), $this->game->toughness($this->game->objects[$attacker])]);
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($seat, [$attacker]);
        $this->passUntil(Step::EndCombat);
        $this->assertSame(20, $this->life(1 - $seat));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($attacker));
    }

    /** @dataProvider seats */
    public function testCounteredSpellDoesNotApplyItsEffect(int $seat): void
    {
        $this->setupSeat($seat);
        $this->lands($seat, 'Swamp', 1);
        $this->lands(1 - $seat, 'Island', 2);
        $victim = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $spell = $this->game->addCard($seat, $this->disfigure(), GameObject::HAND)->id;
        $counter = $this->hand(1 - $seat, 'Counterspell');
        $this->game->cast($seat, $spell, 0, ['o:'.$victim]);
        $id = $this->game->stack[0]['id'];
        $this->game->pass($seat);
        $this->game->cast(1 - $seat, $counter, 0, ['s:'.$id]);
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($victim));
        $this->assertSame([2, 2], [$this->game->power($this->game->objects[$victim]), $this->game->toughness($this->game->objects[$victim])]);
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
