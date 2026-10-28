<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameException;
use MTGPocket\Game\Step;

final class UntapSymbolCostTest extends GameTestCase
{
    /** @dataProvider seats */
    public function testPaidSilkbindUntapsAsCostAndTapsTarget(int $seat): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/silkbind-faerie.json'));
        $this->assertSame('c560ba8c8adf9852029db2c2415be1f777b42cae899c219d2b7a9661f4df86f6', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('d4585183-1e13-5208-9f5e-6e166bb67f09', $card['uuid']);
        $lands = $this->lands($seat, 'Plains', 3);
        $source = $game->addCard($seat, $card, GameObject::HAND)->id;
        $target = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $game->cast($seat, $source);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $this->game->objects[$source]->tapped = true;
        $this->game->activate($seat, $source, 0, ["o:{$target}"]);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertFalse($this->game->objects[$target]->tapped);
        $this->assertSame(2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$target]->tapped);
    }

    /** @dataProvider seats */
    public function testStunReplacesUntapCostAndAbilityStillResolves(int $seat): void
    {
        [$source, $target, $lands] = $this->preparedActivation($seat);
        $this->game->objects[$source]->addCounters('stun', 1);
        $this->game->activate($seat, $source, 0, ["o:{$target}"]);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $this->assertSame(0, $this->game->objects[$source]->counter('stun'));
        $this->assertSame(2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
        $this->assertFalse($this->game->objects[$target]->tapped);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$target]->tapped);
        $this->assertTrue($this->game->objects[$source]->tapped);
    }

    /** @dataProvider seats */
    public function testRepeatedStunPaymentsThenUntapAndRejectReplay(int $seat): void
    {
        [$source, $target] = $this->preparedActivation($seat);
        $this->lands($seat, 'Plains', 3);
        $this->game->objects[$source]->addCounters('stun', 2);
        foreach ([1, 0] as $remaining) {
            $this->game->activate($seat, $source, 0, ["o:{$target}"]);
            $this->assertTrue($this->game->objects[$source]->tapped);
            $this->assertSame($remaining, $this->game->objects[$source]->counter('stun'));
        }
        $this->game->activate($seat, $source, 0, ["o:{$target}"]);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertCount(3, $this->game->stack);
        $this->assertRejectedWithoutMutation($seat, $source, ["o:{$target}"]);
    }

    /** @dataProvider seats */
    public function testTargetDepartureDoesNotRefundPaidCost(int $seat): void
    {
        [$source, $target] = $this->preparedActivation($seat);
        $this->lands(1 - $seat, 'Island', 1);
        $unsummon = $this->hand(1 - $seat, 'Unsummon');
        $this->game->activate($seat, $source, 0, ["o:{$target}"]);
        $this->game->pass($seat);
        $this->game->cast(1 - $seat, $unsummon, 0, ["o:{$target}"]);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::HAND, $this->game->objects[$target]->zone);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertSame(GameObject::HAND, $this->game->objects[$target]->zone);
    }

    /** @dataProvider seats */
    public function testHasteAndStepRestrictionAllowSelfTargetAfterRestore(int $seat): void
    {
        [$source] = $this->preparedActivation($seat);
        $this->game->objects[$source]->sick = true;
        $this->game->objects[$source]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ['haste', "doesn't untap"]];
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->activate($seat, $source, 0, ["o:{$source}"]);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$source]->tapped);
    }

    /** @dataProvider seats */
    public function testInvalidActivationsDoNotPayOrChangeState(int $seat): void
    {
        [$source, $target, $lands] = $this->preparedActivation($seat);
        $this->game->objects[$source]->tapped = false;
        $this->assertRejectedWithoutMutation($seat, $source, ["o:{$target}"]);
        $this->game->objects[$source]->tapped = true;
        $this->game->objects[$source]->sick = true;
        $this->assertRejectedWithoutMutation($seat, $source, ["o:{$target}"]);
        $this->game->objects[$source]->sick = false;
        $this->assertRejectedWithoutMutation($seat, $source, ['p:0']);
        foreach ($lands as $land) {
            $this->game->objects[$land]->tapped = true;
        }
        $this->assertRejectedWithoutMutation($seat, $source, ["o:{$target}"]);
    }

    /** @return array{int, int, int[]} */
    private function preparedActivation(int $seat): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/silkbind-faerie.json'), true, flags: JSON_THROW_ON_ERROR);
        $lands = $this->lands($seat, 'Plains', 3);
        $source = $game->addCard($seat, $card, GameObject::HAND)->id;
        $target = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $game->cast($seat, $source);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $this->game->objects[$source]->tapped = true;

        return [$source, $target, $lands];
    }

    private function assertRejectedWithoutMutation(int $seat, int $source, array $targets): void
    {
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $source, 0, $targets);
            $this->fail('Invalid activation must be rejected.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
