<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameException;
use MTGPocket\Game\Step;

/** Complete cached cards; test the counter-paid damage ability only. */
final class CounterCostSourceDamageTest extends GameTestCase
{
    private function prepare(int $seat, int $index): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/counter-cost-source-damage.json'));
        $this->assertSame('19c6bf8a451d38466ecf47f599279fd03243d9165b34e9968a40e520a5f97797', hash('sha256', $bytes));
        $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'][$index]['card'];
        $this->assertSame($index === 0 ? '0cd5be94-3bcf-560a-982c-c911323e0557' : '78532063-e9e5-5761-b911-cdf4a65b4f90', $record['uuid']);
        $lands = $this->lands($seat, 'Mountain', $index === 0 ? 6 : 3);
        $id = $game->addCard($seat, $record, GameObject::HAND)->id;
        $game->cast($seat, $id);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $kind = $index === 0 ? 'fade' : '+1/+1';
        $count = $index === 0 ? 5 : 1;
        $this->assertSame($count, $game->objects[$id]->counter($kind));

        return [$id, $lands, $kind, $count];
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider cardsAndSeats */
    public function testPaidActivationRemovesCounterAndDealsDamageAfterRestore(int $seat, int $index): void
    {
        [$id, $lands, $kind, $count] = $this->prepare($seat, $index);
        $definition = $this->game->objects[$id]->definition();
        $this->assertSame([], $definition->unsupported);
        $this->assertSame(['mana' => '{1}', 'remove' => [$kind, 1]], $definition->activated[0]['cost']);
        $this->assertSame([['type' => 'damage', 'amount' => 1, 'target' => 'any']], $definition->activated[0]['effects']);
        $life = $this->game->players[1 - $seat]->life;
        $this->game->activate($seat, $id, 0, ['p:'.(1 - $seat)]);
        $this->assertSame($count - 1, $this->game->objects[$id]->counter($kind));
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($life - 1, $this->game->players[1 - $seat]->life);
    }

    /** @dataProvider cardsAndSeats */
    public function testIllegalTargetAndMissingCostsRejectWithoutMutation(int $seat, int $index): void
    {
        [$id, $lands, $kind, $count] = $this->prepare($seat, $index);
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0, ['o:'.$lands[0]]);
            $this->fail('A land is not any target.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
        $tapped = [];
        foreach ($lands as $land) {
            $tapped[$land] = $this->game->objects[$land]->tapped;
            $this->game->objects[$land]->tapped = true;
        }
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0, ['p:'.(1 - $seat)]);
            $this->fail('The mana cost is required.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
        foreach ($tapped as $land => $wasTapped) {
            $this->game->objects[$land]->tapped = $wasTapped;
        }
        $this->game->objects[$id]->addCounters($kind, -$count);
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0, ['p:'.(1 - $seat)]);
            $this->fail('A counter is required.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @dataProvider cardsAndSeats */
    public function testAbilityStillDealsDamageAfterSourceReturnsToHand(int $seat, int $index): void
    {
        [$id] = $this->prepare($seat, $index);
        $other = 1 - $seat;
        $this->lands($other, 'Island', 1);
        $bounce = $this->hand($other, 'Unsummon');
        $life = $this->game->players[$other]->life;
        $this->game->activate($seat, $id, 0, ['p:'.$other]);
        $this->game->pass($seat);
        $this->game->cast($other, $bounce, 0, ['o:'.$id]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::HAND, $this->zone($id));
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($life - 1, $this->game->players[$other]->life);
    }

    /** @dataProvider cardsAndSeats */
    public function testRemovingLastCounterDoesNotItselfRemoveSource(int $seat, int $index): void
    {
        [$id, , $kind, $count] = $this->prepare($seat, $index);
        $this->game->objects[$id]->addCounters($kind, 1 - $count);
        $this->game->activate($seat, $id, 0, ['p:'.(1 - $seat)]);
        $this->assertSame(0, $this->game->objects[$id]->counter($kind));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
    }

    /** @dataProvider cardsAndSeats */
    public function testPreventedCreatureDamageStillConsumesPaidCosts(int $seat, int $index): void
    {
        [$id, , $kind, $count] = $this->prepare($seat, $index);
        $bear = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $this->game->prevent['o:'.$bear] = 1;
        $this->game->activate($seat, $id, 0, ['o:'.$bear]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($count - 1, $this->game->objects[$id]->counter($kind));
        $this->assertSame(0, $this->game->objects[$bear]->damage);
        $this->assertSame(0, $this->game->prevent['o:'.$bear]);
    }

    public function testAnotherCreaturesPronounRemainsUnsupported(): void
    {
        $card = new CardDefinition(['name' => 'Ambiguous Fixture', 'type' => 'Creature', 'text' => 'Remove a +1/+1 counter from another creature you control: It deals 1 damage to any target.']);
        $this->assertNotSame([], $card->unsupported);
        $this->assertSame([], $card->activated);
        $multiple = new CardDefinition(['name' => 'Ambiguous Fixture', 'type' => 'Creature', 'text' => 'Remove a +1/+1 counter from this creature, Sacrifice another creature: It deals 1 damage to any target.']);
        $this->assertNotSame([], $multiple->unsupported);
        $this->assertSame([], $multiple->activated);
    }

    public static function cardsAndSeats(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }
}
