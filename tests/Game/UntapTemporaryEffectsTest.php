<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameException;
use MTGPocket\Game\Step;

final class UntapTemporaryEffectsTest extends GameTestCase
{
    /** @dataProvider cardsAndSeats */
    public function testPaidUntapAbilityAndExpiration(int $seat, int $index): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/untap-symbol-temporary-effects.json'));
        $this->assertSame('2a96c1bce44c80a21232eb294834eb0ec81110ed8275785e77455cc57a3d017c', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][$index];
        $this->assertSame(['28aa77b1-24ca-56bf-a3a1-28f674bb88e3', '32d2a2e8-6a84-560e-bb57-356b8da657ac', '68205de9-ca1c-5709-a25d-be8baaaef24a'][$index], $card['uuid']);
        $lands = $this->lands($seat, $index === 0 ? 'Plains' : 'Island', 5);
        $source = $game->addCard($seat, $card, GameObject::HAND)->id;
        $target = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $game->cast($seat, $source);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $turn = $seat === 0 ? 3 : 4;
        $this->passUntil(Step::PrecombatMain, $turn);
        $this->game->objects[$source]->tapped = true;
        $this->game->activate($seat, $source, 0, $index === 2 ? ["o:{$target}"] : []);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertSame($index === 0 ? 3 : 2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame($index === 0 ? 4 : (int) $card['toughness'], $this->game->toughness($this->game->objects[$source]));
        $this->assertSame($index === 1, $this->game->hasKeyword($this->game->objects[$source], 'flying'));
        $this->assertSame($index === 2 ? 0 : 2, $this->game->power($this->game->objects[$target]));
        $this->passUntil(Step::PrecombatMain, $turn + 1);
        $this->assertSame((int) $card['toughness'], $this->game->toughness($this->game->objects[$source]));
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$source], 'flying'));
        $this->assertSame(2, $this->game->power($this->game->objects[$target]));
    }

    /** @dataProvider cardsAndSeats */
    public function testStunReplacementAndAtomicInvalidCosts(int $seat, int $index): void
    {
        [$source, $target, $lands] = $this->preparedActivation($seat, $index);
        $targets = $index === 2 ? ["o:{$target}"] : [];
        $this->game->objects[$source]->tapped = false;
        $this->assertRejected($seat, $source, $targets);
        $this->game->objects[$source]->tapped = true;
        $this->game->objects[$source]->sick = true;
        $this->assertRejected($seat, $source, $targets);
        $this->game->objects[$source]->sick = false;
        if ($index === 2) {
            $this->assertRejected($seat, $source, ['p:0']);
        }
        foreach ($lands as $land) {
            $this->game->objects[$land]->tapped = true;
        }
        $this->game->objects[$source]->addCounters('stun', 1);
        $this->assertRejected($seat, $source, $targets);
        foreach ($lands as $land) {
            $this->game->objects[$land]->tapped = false;
        }
        $this->game->activate($seat, $source, 0, $targets);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $this->assertSame(0, $this->game->objects[$source]->counter('stun'));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        if ($index === 0) {
            $this->assertSame(4, $this->game->toughness($this->game->objects[$source]));
        } elseif ($index === 1) {
            $this->assertTrue($this->game->hasKeyword($this->game->objects[$source], 'flying'));
        } else {
            $this->assertSame(0, $this->game->power($this->game->objects[$target]));
        }
    }

    /** @dataProvider cardsAndSeats */
    public function testSourceDepartureDoesNotRefundAndTargetedEffectStillResolves(int $seat, int $index): void
    {
        [$source, $target, $lands] = $this->preparedActivation($seat, $index);
        $other = 1 - $seat;
        $this->lands($other, 'Swamp', 3);
        $murder = $this->hand($other, 'Murder');
        $this->game->activate($seat, $source, 0, $index === 2 ? ["o:{$target}"] : []);
        $this->game->pass($seat);
        $this->game->cast($other, $murder, 0, ["o:{$source}"]);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame($index === 2 ? 0 : 2, $this->game->power($this->game->objects[$target]));
        $this->assertSame([], $this->game->objects[$source]->untilEndOfTurn);
        $this->assertSame($index === 0 ? 3 : 2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
    }

    /** @dataProvider seats */
    public function testDepartedTargetIsNotModifiedAndCostsRemainPaid(int $seat): void
    {
        [$source, $target, $lands] = $this->preparedActivation($seat, 2);
        $other = 1 - $seat;
        $this->lands($other, 'Island', 1);
        $unsummon = $this->hand($other, 'Unsummon');
        $this->game->activate($seat, $source, 0, ["o:{$target}"]);
        $this->game->pass($seat);
        $this->game->cast($other, $unsummon, 0, ["o:{$target}"]);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::HAND, $this->zone($target));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame([], $this->game->objects[$target]->untilEndOfTurn);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertSame(2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }

    /** @return array{int, int, int[]} */
    private function preparedActivation(int $seat, int $index): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/untap-symbol-temporary-effects.json'), true, flags: JSON_THROW_ON_ERROR)['cards'][$index];
        $lands = $this->lands($seat, $index === 0 ? 'Plains' : 'Island', 5);
        $source = $game->addCard($seat, $card, GameObject::HAND)->id;
        $target = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $game->cast($seat, $source);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $this->game->objects[$source]->tapped = true;

        return [$source, $target, $lands];
    }

    private function assertRejected(int $seat, int $source, array $targets): void
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

    public static function cardsAndSeats(): array
    {
        return [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2]];
    }
}
