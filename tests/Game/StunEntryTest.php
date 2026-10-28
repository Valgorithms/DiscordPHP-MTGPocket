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

    private function restrictUntap(int $seat, int $id): int
    {
        $this->lands($seat, 'Island', 1);
        $aura = $this->game->addCard($seat, ['name' => 'Untap Restriction Fixture', 'type' => 'Enchantment - Aura', 'manaCost' => '{U}',
            'text' => "Enchant creature\nEnchanted creature doesn't untap during its controller's untap step."], GameObject::HAND)->id;
        $this->game->cast($seat, $aura, 0, ['o:'.$id]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($aura));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], "doesn't untap"));

        return $aura;
    }

    /** @dataProvider restrictedCases */
    public function testUntapRestrictionPreservesStunUntilItIsRemoved(int $seat, int $index, bool $frozen): void
    {
        $id = $this->prepare($seat, $index);
        $aura = $this->restrictUntap($seat, $id);
        $initial = $index === 0 ? 1 : 3;
        $this->game->objects[$id]->frozen = $frozen;
        $this->restore();
        $firstUpkeep = $seat === 0 ? 3 : 4;
        $this->passUntil(Step::Upkeep, $firstUpkeep);
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertFalse($this->game->objects[$id]->frozen);
        $this->assertSame($initial, $this->game->objects[$id]->counter('stun'));
        $this->restore();
        $this->lands($seat, 'Forest', 2);
        $removal = $this->game->addCard($seat, ['name' => 'Aura Removal Fixture', 'type' => 'Instant', 'manaCost' => '{1}{G}',
            'text' => 'Destroy target artifact or enchantment.'], GameObject::HAND)->id;
        $this->game->cast($seat, $removal, 0, ['o:'.$aura]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($aura));
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$id], "doesn't untap"));
        for ($i = 1; $i <= $initial; $i++) {
            $this->passUntil(Step::Upkeep, $firstUpkeep + 2 * $i);
            $this->assertTrue($this->game->objects[$id]->tapped);
            $this->assertSame($initial - $i, $this->game->objects[$id]->counter('stun'));
            $this->restore();
        }
        $this->passUntil(Step::Upkeep, $firstUpkeep + 2 * ($initial + 1));
        $this->assertFalse($this->game->objects[$id]->tapped);
    }

    /** @dataProvider seats */
    public function testFaeriePaidUntapStillConsumesStunUnderRestriction(int $seat): void
    {
        $id = $this->prepare($seat, 1);
        $this->restrictUntap($seat, $id);
        $this->lands($seat, 'Island', 2);
        $this->game->activate($seat, $id, 0);
        $this->restore();
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], "doesn't untap"));
        $this->assertTrue($this->game->objects[$id]->tapped);
        $this->assertSame(2, $this->game->objects[$id]->counter('stun'));
    }

    public static function restrictedCases(): array
    {
        $out = [];
        foreach ([0, 1] as $seat) {
            foreach ([0, 1] as $index) {
                foreach ([false, true] as $frozen) {
                    $out[] = [$seat, $index, $frozen];
                }
            }
        }

        return $out;
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
