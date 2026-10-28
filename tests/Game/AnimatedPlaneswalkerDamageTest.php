<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Exact cached Bolt; animation is seeded and does not certify Gideon's abilities. */
final class AnimatedPlaneswalkerDamageTest extends GameTestCase
{
    private function fixture(string $name, string $uuid): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/animated-planeswalker-damage.json'));
        $this->assertSame('7762e84fd91d5e1f8f95f139e73aa3c2e38ccdeca8dbb6a9c9a955b3bc20904b', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'];
        $card = array_values(array_filter($cards, fn (array $candidate) => $candidate['name'] === $name))[0];
        $this->assertSame($uuid, $card['uuid']);

        return $card;
    }

    private function setupTarget(int $seat, bool $animated): array
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $gideon = $this->game->addCard(1 - $seat, $this->fixture('Gideon, Ally of Zendikar', '5ae34679-cf9a-5de0-a03f-aeb166372c7f'), GameObject::BATTLEFIELD)->id;
        if ($animated) {
            // Isolate an already-animated planeswalker. Do not claim printed ability support.
            $this->game->objects[$gideon]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['indestructible'], 'creature' => true, 'base' => [5, 5]];
        }
        $mana = $this->lands($seat, 'Mountain', 1)[0];
        $boltCard = $this->fixture('Lightning Bolt', '78380203-7623-50bc-ab48-577cd655ee9a');
        $this->assertSame([], (new CardDefinition($boltCard))->unsupported);
        $bolt = $this->game->addCard($seat, $boltCard, GameObject::HAND)->id;
        $this->assertSame(4, $this->game->objects[$gideon]->counter('loyalty'));
        $this->assertSame($animated, $this->game->isCreature($this->game->objects[$gideon]));

        return [$gideon, $mana, $bolt];
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    /** @dataProvider seats */
    public function testPaidBoltMarksDamageAndRemovesLoyaltyFromAnimatedPlaneswalker(int $seat): void
    {
        [$target, $mana, $bolt] = $this->setupTarget($seat, true);
        // Gideon's printed ability prevents damage; isolate an unpreventable hit.
        $this->game->unpreventableTurn = $this->game->turn;
        $this->game->cast($seat, $bolt, 0, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($target));
        $this->assertSame(3, $this->game->objects[$target]->damage);
        $this->assertSame(1, $this->game->objects[$target]->counter('loyalty'));
        $this->restore();
        $this->assertSame([3, 1], [$this->game->objects[$target]->damage, $this->game->objects[$target]->counter('loyalty')]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
    }

    /** @dataProvider seats */
    public function testZeroLoyaltyRemovesAnimatedIndestructiblePlaneswalker(int $seat): void
    {
        [$target, , $bolt] = $this->setupTarget($seat, true);
        $this->game->objects[$target]->addCounters('loyalty', -1);
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$target], 'indestructible'));
        $this->game->unpreventableTurn = $this->game->turn;
        $this->game->cast($seat, $bolt, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($target));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
    }

    /** @dataProvider seats */
    public function testOrdinaryPlaneswalkerLosesLoyaltyWithoutMarkedCreatureDamage(int $seat): void
    {
        [$target, , $bolt] = $this->setupTarget($seat, false);
        $this->game->cast($seat, $bolt, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($target));
        $this->assertSame([0, 1], [$this->game->objects[$target]->damage, $this->game->objects[$target]->counter('loyalty')]);
    }

    /** @dataProvider seats */
    public function testPartialPreventionReducesBothDamageConsequences(int $seat): void
    {
        [$target, , $bolt] = $this->setupTarget($seat, true);
        $this->game->prevent['o:'.$target] = 2;
        $this->game->cast($seat, $bolt, 0, ['o:'.$target]);
        $this->restore();
        $this->resolveOne();
        $this->assertSame([1, 3, 0], [$this->game->objects[$target]->damage,
            $this->game->objects[$target]->counter('loyalty'), $this->game->prevent['o:'.$target]]);
    }

    /** @dataProvider seats */
    public function testShieldCounterPreventsBothDamageConsequences(int $seat): void
    {
        [$target, , $bolt] = $this->setupTarget($seat, true);
        $this->game->objects[$target]->addCounters('shield', 1);
        $this->game->cast($seat, $bolt, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->assertSame([0, 4, 0], [$this->game->objects[$target]->damage,
            $this->game->objects[$target]->counter('loyalty'), $this->game->objects[$target]->counter('shield')]);
    }

    /** @dataProvider seats */
    public function testInfectCombatAddsMinusCountersAndRemovesLoyalty(int $seat): void
    {
        [$target] = $this->setupTarget($seat, true);
        $attacker = $this->game->addCard($seat, ['name' => 'Infect Probe', 'type' => 'Creature - Horror',
            'power' => '1', 'toughness' => '1', 'text' => 'Infect'], GameObject::BATTLEFIELD)->id;
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$attacker], 'infect'));
        $this->game->unpreventableTurn = $this->game->turn;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($seat, [$attacker]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->game->declareBlockers(1 - $seat, [$target => $attacker]);
        $this->restore();
        $this->passUntil(Step::EndCombat);
        $this->assertSame([0, 3, 1], [$this->game->objects[$target]->damage,
            $this->game->objects[$target]->counter('loyalty'), $this->game->objects[$target]->counter('-1/-1')]);
    }

    /** @dataProvider seats */
    public function testReturnedTargetIsNotDamagedByOlderBolt(int $seat): void
    {
        [$target, , $bolt] = $this->setupTarget($seat, true);
        $this->lands(1 - $seat, 'Island', 1);
        $bounce = $this->hand(1 - $seat, 'Unsummon');
        $this->game->cast($seat, $bolt, 0, ['o:'.$target]);
        $this->game->pass($seat);
        $this->game->cast(1 - $seat, $bounce, 0, ['o:'.$target]);
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($target));
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::HAND, $this->zone($target));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
