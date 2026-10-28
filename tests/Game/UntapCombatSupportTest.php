<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameException;
use MTGPocket\Game\Step;

final class UntapCombatSupportTest extends GameTestCase
{
    /** @dataProvider seats */
    public function testPaidPatrolSignalerCreatesKithkinSoldierAfterRestore(int $seat): void
    {
        [$source, $lands] = $this->prepared($seat, 0);
        $this->game->activate($seat, $source, 0);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertSame(2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $tokens = array_values(array_filter($this->game->permanents($seat), fn (GameObject $o) => $o->definition()->isToken()));
        $this->assertCount(1, $tokens);
        $this->assertSame(['Kithkin', 'Soldier'], $tokens[0]->definition()->subtypes);
        $this->assertSame(['W'], $tokens[0]->definition()->colors);
        $this->assertSame([1, 1, $seat], [$this->game->power($tokens[0]), $this->game->toughness($tokens[0]), $tokens[0]->controller]);
    }

    /** @dataProvider seatsAndTurn */
    public function testPaidMineCaptainBoostsCurrentAttackersOnEitherTurn(int $seat, bool $opponentTurn): void
    {
        [$source, $lands] = $this->prepared($seat, 1);
        $active = $opponentTurn ? 1 - $seat : $seat;
        $turn = ($seat === 0 ? 3 : 4) + ($opponentTurn ? 1 : 0);
        $attacker = $this->battlefield($active, 'Grizzly Bears');
        $idle = $this->battlefield($active, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, $turn);
        $this->game->declareAttackers($active, [$attacker]);
        if ($opponentTurn) {
            $this->game->pass($active);
        }
        $this->game->activate($seat, $source, 0);
        $this->assertFalse($this->game->objects[$source]->tapped);
        $this->assertSame(2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
        $this->assertSame(2, $this->game->power($this->game->objects[$attacker]));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(3, $this->game->power($this->game->objects[$attacker]));
        $this->assertSame(2, $this->game->power($this->game->objects[$idle]));
        $this->passUntil(Step::PrecombatMain, $turn + 1);
        $this->assertSame(2, $this->game->power($this->game->objects[$attacker]));
    }

    /** @dataProvider seats */
    public function testMineCaptainDoesNotBoostCreaturesThatAttackAfterResolution(int $seat): void
    {
        [$source] = $this->prepared($seat, 1);
        $attacker = $this->battlefield($seat, 'Grizzly Bears');
        $this->game->activate($seat, $source, 0);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->passUntil(Step::DeclareAttackers, $seat === 0 ? 3 : 4);
        $this->game->declareAttackers($seat, [$attacker]);
        $this->assertSame(2, $this->game->power($this->game->objects[$attacker]));
    }

    /** @dataProvider seatsAndCards */
    public function testStunReplacementAndInvalidCosts(int $seat, int $index): void
    {
        [$source, $lands] = $this->prepared($seat, $index);
        $this->game->objects[$source]->tapped = false;
        $this->assertRejected($seat, $source);
        $this->game->objects[$source]->tapped = true;
        $this->game->objects[$source]->sick = true;
        $this->assertRejected($seat, $source);
        $this->game->objects[$source]->sick = false;
        foreach ($lands as $land) {
            $this->game->objects[$land]->tapped = true;
        }
        $this->game->objects[$source]->addCounters('stun', 1);
        $this->assertRejected($seat, $source);
        foreach ($lands as $land) {
            $this->game->objects[$land]->tapped = false;
        }
        $this->game->activate($seat, $source, 0);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $this->assertSame(0, $this->game->objects[$source]->counter('stun'));
        $this->assertCount(1, $this->game->stack);
    }

    /** @dataProvider seatsAndCards */
    public function testSourceRemovalDoesNotCancelEffectOrRefundCost(int $seat, int $index): void
    {
        [$source, $lands] = $this->prepared($seat, $index);
        $attacker = $this->battlefield($seat, 'Grizzly Bears');
        if ($index === 1) {
            $this->passUntil(Step::DeclareAttackers, $seat === 0 ? 3 : 4);
            $this->game->declareAttackers($seat, [$attacker]);
        }
        $other = 1 - $seat;
        $this->lands($other, 'Swamp', 3);
        $murder = $this->hand($other, 'Murder');
        $this->game->activate($seat, $source, 0);
        $this->game->pass($seat);
        $this->game->cast($other, $murder, 0, ["o:{$source}"]);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        if ($index === 0) {
            $tokens = array_filter($this->game->permanents($seat), fn (GameObject $o) => $o->definition()->isToken());
            $this->assertCount(1, $tokens);
        } else {
            $this->assertSame(3, $this->game->power($this->game->objects[$attacker]));
        }
        $this->assertSame(2, count(array_filter($lands, fn (int $id) => $this->game->objects[$id]->tapped)));
    }

    private function assertRejected(int $seat, int $source): void
    {
        $before = $this->game->toArray();
        try {
            $this->game->activate($seat, $source, 0);
            $this->fail('Invalid activation must be rejected.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @return array{int, int[]} */
    private function prepared(int $seat, int $index): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/untap-symbol-combat-support.json'));
        $this->assertSame('c40901e5e5e98add7d9be47aaf210cc4db27de3fd5e577de2bb56e9e3a2e5ca9', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][$index];
        $this->assertSame(['f622649f-9bdc-505a-9c29-6f7abf1dec8f', 'd55259b7-19b1-5cde-ad49-9098a8d78779'][$index], $card['uuid']);
        $lands = $this->lands($seat, 'Plains', 3);
        $source = $game->addCard($seat, $card, GameObject::HAND)->id;
        $game->cast($seat, $source);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->passUntil(Step::PrecombatMain, $seat === 0 ? 3 : 4);
        $this->game->objects[$source]->tapped = true;

        return [$source, $lands];
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }

    public static function seatsAndCards(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    public static function seatsAndTurn(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }
}
