<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameException;
use MTGPocket\Game\Step;

/** Cached cards; Faerie's Ward and Tonberry's combat text are outside this slice. */
final class StunEntryTest extends GameTestCase
{
    private function prepare(int $seat, int $index): int
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/stun-entry-counters.json'));
        $this->assertSame('bec03f1b43174c16835a6978c4337c641a8108c3701bf1dab5c1d3a88d8ecf17', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'][$index]['card'];
        $this->assertSame($index === 0 ? '2dfac2a6-15a3-5f36-9adc-eb005e756cf1' : 'b7cf0005-fc24-5821-8cec-e57e57519f84', $card['uuid']);
        $this->lands($seat, $index === 0 ? 'Swamp' : 'Island', $index === 0 ? 1 : 3);
        $id = $game->addCard($seat, $card, GameObject::HAND)->id;
        $game->cast($seat, $id);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider cardsAndSeats */
    public function testPaidCastEntersTappedWithStunCountersAfterRestore(int $seat, int $index): void
    {
        $id = $this->prepare($seat, $index);
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertSame($index === 0 ? 1 : 3, $this->game->objects[$id]->counter('stun'));
        $this->restore();
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertSame($index === 0 ? 1 : 3, $this->game->objects[$id]->counter('stun'));
    }

    /** @dataProvider seats */
    public function testTonberryStunIsConsumedBeforeItCanUntap(int $seat): void
    {
        $id = $this->prepare($seat, 0);
        $this->passUntil(Step::Upkeep, $seat === 0 ? 3 : 4);
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertSame(0, $this->game->objects[$id]->counter('stun'));
        $this->restore();
        $this->passUntil(Step::Upkeep, $seat === 0 ? 5 : 6);
        $this->assertFalse($this->game->objects[$id]->tapped);
    }

    /** @dataProvider seats */
    public function testPaidFaerieUntapRemovesOneStunInsteadAfterRestore(int $seat): void
    {
        $id = $this->prepare($seat, 1);
        $this->game->activate($seat, $id, 0);
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertSame(2, $this->game->objects[$id]->counter('stun'));
        $this->restore();
        foreach ([[$seat === 0 ? 3 : 4, 1], [$seat === 0 ? 5 : 6, 0]] as [$turn, $remaining]) {
            $this->passUntil(Step::Upkeep, $turn);
            $this->assertTrue($this->game->objects[$id]->tapped);
            $this->assertSame($remaining, $this->game->objects[$id]->counter('stun'));
        }
        $this->restore();
        $this->passUntil(Step::Upkeep, $seat === 0 ? 7 : 8);
        $this->assertFalse($this->game->objects[$id]->tapped);
    }

    /** @dataProvider seats */
    public function testUnpaidFaerieActivationRejectsWithoutMutation(int $seat): void
    {
        $id = $this->prepare($seat, 1);
        foreach ($this->game->objects as $object) {
            if ($object->zone === GameObject::BATTLEFIELD && $object->controller === $seat && $object->definition()->isLand()) {
                $object->tapped = true;
            }
        }
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $id, 0);
            $this->fail('The untap activation requires mana.');
        } catch (GameException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @dataProvider seats */
    public function testTonberryCombatClauseRemainsExplicitlyUnread(int $seat): void
    {
        $id = $this->prepare($seat, 0);
        $unsupported = $this->game->objects[$id]->definition()->unsupported;
        $this->assertCount(1, $unsupported);
        $this->assertStringContainsString("Chef's Knife", $unsupported[0]);
    }

    public static function cardsAndSeats(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
