<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Complete cached cards and their distinct named entry counters. */
final class JavelinBrickEntryTest extends GameTestCase
{
    private function prepare(int $seat, int $index): int
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/javelin-brick-entry-counters.json'));
        $this->assertSame('14800709901a22acea9e15e65c1285537d46be7d2c8c1198b56235d961940ba1', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'][$index]['card'];
        $this->assertSame($index === 0 ? 'c0a52805-c0e7-52d5-be95-51d95e3f9168' : '3bfe1300-4f02-5fd5-83c3-42257c2e54b2', $card['uuid']);
        $this->lands($seat, 'Plains', $index === 0 ? 1 : 4);
        $id = $game->addCard($seat, $card, GameObject::HAND)->id;
        $game->cast($seat, $id);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertSame([], $game->objects[$id]->definition()->unsupported);

        return $id;
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider cardsAndSeats */
    public function testPaidCastEntersWithNamedCountersAndSurvivesRestore(int $seat, int $index): void
    {
        $id = $this->prepare($seat, $index);
        $kind = $index === 0 ? 'javelin' : 'brick';
        $expected = $index === 0 ? 1 : 3;
        $this->assertSame($expected, $this->game->objects[$id]->counter($kind));
        $this->restore();
        $this->assertSame($expected, $this->game->objects[$id]->counter($kind));
    }

    /** @dataProvider cardsAndSeats */
    public function testPaidCounterActivationResolvesAfterRestore(int $seat, int $index): void
    {
        $id = $this->prepare($seat, $index);
        if ($index === 0) {
            $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        }
        $kind = $index === 0 ? 'javelin' : 'brick';
        $life = $this->game->players[1 - $seat]->life;
        $hand = count($this->game->players[$seat]->hand);
        $this->game->activate($seat, $id, 0, $index === 0 ? ['p:'.(1 - $seat)] : []);
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertSame($index === 0 ? 0 : 2, $this->game->objects[$id]->counter($kind));
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($life - ($index === 0 ? 1 : 0), $this->game->players[1 - $seat]->life);
        $this->assertCount($hand + ($index === 1 ? 1 : 0), $this->game->players[$seat]->hand);
    }

    /** @dataProvider cardsAndSeats */
    public function testMissingCounterRejectsWithoutPaying(int $seat, int $index): void
    {
        $id = $this->prepare($seat, $index);
        if ($index === 0) {
            $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        }
        $kind = $index === 0 ? 'javelin' : 'brick';
        $this->game->objects[$id]->addCounters($kind, -($index === 0 ? 1 : 3));
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0, $index === 0 ? ['p:'.(1 - $seat)] : []);
            $this->fail('The named counter is required.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @dataProvider seats */
    public function testSummoningSicknessAndIllegalTargetRejectBeforeCosts(int $seat): void
    {
        $id = $this->prepare($seat, 0);
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0, ['p:'.(1 - $seat)]);
            $this->fail('The creature cannot tap yet.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $land = $this->lands($seat, 'Plains', 1)[0];
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0, ['o:'.$land]);
            $this->fail('A land is not any target.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @dataProvider cardsAndSeats */
    public function testReturnedPermanentGetsFreshCountersOnRecast(int $seat, int $index): void
    {
        $id = $this->prepare($seat, $index);
        $kind = $index === 0 ? 'javelin' : 'brick';
        $expected = $index === 0 ? 1 : 3;
        $this->game->objects[$id]->addCounters($kind, -1);
        $other = 1 - $seat;
        $this->lands($other, 'Island', 2);
        $bounce = $this->game->addCard($other, ['name' => 'Bounce Fixture', 'type' => 'Instant', 'manaCost' => '{1}{U}', 'text' => "Return target nonland permanent to its owner's hand."], GameObject::HAND)->id;
        $this->game->pass($seat);
        $this->game->cast($other, $bounce, 0, ['o:'.$id]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::HAND, $this->zone($id));
        $this->assertSame(0, $this->game->objects[$id]->counter($kind));
        $this->restore();
        $this->lands($seat, 'Plains', 2);
        $this->game->cast($seat, $id);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($expected, $this->game->objects[$id]->counter($kind));
    }

    public function testUnknownNamedCounterStillStaysUnread(): void
    {
        $card = new CardDefinition(['name' => 'Unknown Counter Fixture', 'type' => 'Artifact', 'text' => 'This artifact enters with a mystery counter on it.']);
        $this->assertNotSame([], $card->unsupported);
        $this->assertSame([], $card->entersWithOther);
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }

    public static function cardsAndSeats(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }
}
