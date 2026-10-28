<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Cached spells; creatures and stun states are seeded to isolate mass untap. */
final class MassUntapStunTest extends GameTestCase
{
    /** @dataProvider spellsAndSeats */
    public function testPaidMassUntapConsumesStunOnlyOnTappedControlledCreatures(int $seat, int $index): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/mass-untap-stun.json'));
        $this->assertSame('4d193f862baec509f25e254d1bd5c8139e30fdc92508b45b19aff5b106f19ec6', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'][$index]['card'];
        $this->assertSame($index === 0 ? '9086302a-37e4-59f8-9cc0-cf6fc52171ee' : '5a64edee-23a7-5c0d-8170-0cca6b8270ff', $card['uuid']);
        $one = $this->battlefield($seat, 'Grizzly Bears');
        $two = $this->battlefield($seat, 'Grizzly Bears');
        $plain = $this->battlefield($seat, 'Grizzly Bears');
        $untapped = $this->battlefield($seat, 'Grizzly Bears');
        $enemy = $this->battlefield(1 - $seat, 'Grizzly Bears');
        foreach ([$one, $two, $plain, $enemy] as $id) {
            $game->objects[$id]->tapped = true;
        }
        $game->objects[$one]->addCounters('stun', 1);
        $game->objects[$two]->addCounters('stun', 2);
        $game->objects[$untapped]->addCounters('stun', 1);
        $game->objects[$enemy]->addCounters('stun', 1);
        $game->objects[$plain]->untilEndOfTurn[] = ['power' => 0, 'toughness' => 0, 'keywords' => ["doesn't untap"]];
        $lands = $this->lands($seat, $index === 0 ? 'Forest' : 'Plains', $index === 0 ? 1 : 2);
        $spell = $game->addCard($seat, $card, GameObject::HAND)->id;
        $this->assertSame([], $game->objects[$spell]->definition()->unsupported);
        $game->cast($seat, $spell);
        foreach ($lands as $land) {
            $this->assertTrue($game->objects[$land]->tapped);
        }
        $hand = count($game->players[$seat]->hand);
        $this->game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$one]->tapped);
        $this->assertSame(0, $this->game->objects[$one]->counter('stun'));
        $this->assertTrue($this->game->objects[$two]->tapped);
        $this->assertSame(1, $this->game->objects[$two]->counter('stun'));
        $this->assertFalse($this->game->objects[$plain]->tapped);
        $this->assertFalse($this->game->objects[$untapped]->tapped);
        $this->assertSame(1, $this->game->objects[$untapped]->counter('stun'));
        $this->assertTrue($this->game->objects[$enemy]->tapped);
        $this->assertSame(1, $this->game->objects[$enemy]->counter('stun'));
        $this->assertCount($hand + ($index === 1 ? 1 : 0), $this->game->players[$seat]->hand);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    /** @dataProvider seats */
    public function testMassTapDoesNotConsumeStun(int $seat): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $target = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $game->objects[$target]->addCounters('stun', 1);
        $this->lands($seat, 'Plains', 1);
        $spell = $game->addCard($seat, ['name' => 'Mass Tap Fixture', 'type' => 'Instant', 'manaCost' => '{W}',
            'text' => 'Tap all creatures your opponents control.'], GameObject::HAND)->id;
        $game->cast($seat, $spell);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertTrue($game->objects[$target]->tapped);
        $this->assertSame(1, $game->objects[$target]->counter('stun'));
    }

    /** @dataProvider spellsAndSeats */
    public function testCounteredMassUntapDoesNotChangeStunOrDraw(int $seat, int $index): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/mass-untap-stun.json'));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'][$index]['card'];
        $target = $this->battlefield($seat, 'Grizzly Bears');
        $game->objects[$target]->tapped = true;
        $game->objects[$target]->addCounters('stun', 1);
        $this->lands($seat, $index === 0 ? 'Forest' : 'Plains', $index === 0 ? 1 : 2);
        $this->lands(1 - $seat, 'Island', 2);
        $spell = $game->addCard($seat, $card, GameObject::HAND)->id;
        $counter = $this->hand(1 - $seat, 'Counterspell');
        $game->cast($seat, $spell);
        $hand = count($game->players[$seat]->hand);
        $stackId = $game->stack[0]['id'];
        $game->pass($seat);
        $game->cast(1 - $seat, $counter, 0, ['s:'.$stackId]);
        $this->game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$target]->tapped);
        $this->assertSame(1, $this->game->objects[$target]->counter('stun'));
        $this->assertCount($hand, $this->game->players[$seat]->hand);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }

    public static function spellsAndSeats(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }
}
