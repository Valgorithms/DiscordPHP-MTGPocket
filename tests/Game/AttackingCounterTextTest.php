<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/**
 * @covers \MTGPocket\Game\TextParser
 * @covers \MTGPocket\Game\Game
 */
final class AttackingCounterTextTest extends GameTestCase
{
    public function testCountersOnEachAttackerIncludeTheSource(): void
    {
        $game = $this->newGame();
        $card = ['uuid' => 'fangren-firstborn', 'name' => 'Fangren Firstborn', 'manaCost' => '{1}{G}{G}{G}', 'type' => 'Creature — Beast', 'power' => '4', 'toughness' => '2', 'text' => 'Whenever this creature attacks, put a +1/+1 counter on each attacking creature.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $attacker = $this->battlefield(0, 'Grizzly Bears');
        $idle = $this->battlefield(0, 'Hill Giant');
        $opponent = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$source, $attacker]);
        $this->assertCount(1, $game->stack);
        $this->game = $game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame([1, 1, 0, 0], array_map(fn (int $id) => $game->objects[$id]->counter('+1/+1'), [$source, $attacker, $idle, $opponent]));
    }
}
