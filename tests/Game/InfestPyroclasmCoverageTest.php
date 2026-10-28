<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Bounded paid effect evidence; synthetic permanents are test controls. */
final class InfestPyroclasmCoverageTest extends GameTestCase
{
    private function cached(string $name, string $uuid): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/state-based-deaths.json'));
        $this->assertSame('a2b90b3b251a714c4b86c5f94c92e54ef497f01572ae951afe679d2e302879d3', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][$name]['card'];
        $this->assertSame($uuid, $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $card;
    }

    private function setupSeat(int $seat): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function restore(): void
    {
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @dataProvider seats */
    public function testPaidInfestReducesBothSidesWithoutDamageAndExpires(int $seat): void
    {
        $this->setupSeat($seat);
        $mana = $this->lands($seat, 'Swamp', 3);
        $own = $this->battlefield($seat, 'Grizzly Bears');
        $indestructible = $this->game->addCard(1 - $seat, ['name' => 'Indestructible Probe', 'type' => 'Creature - Construct',
            'power' => '2', 'toughness' => '2', 'text' => 'Indestructible'], GameObject::BATTLEFIELD)->id;
        $survivor = $this->game->addCard(1 - $seat, ['name' => 'Toughness Probe', 'type' => 'Creature - Wall',
            'power' => '2', 'toughness' => '4'], GameObject::BATTLEFIELD)->id;
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$indestructible], 'indestructible'));
        $this->game->prevent['o:'.$indestructible] = 2;
        $spell = $this->game->addCard($seat, $this->cached('Infest', 'a4683dd0-caf1-50aa-926c-8d09b1d0bded'), GameObject::HAND)->id;
        $this->game->cast($seat, $spell);
        $this->assertSame(3, $this->game->players[$seat]->expended);
        $this->assertCount(3, array_filter($mana, fn (int $id) => $this->game->objects[$id]->tapped));
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($own));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($indestructible));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($survivor));
        $this->assertSame([0, 2, 0], [$this->game->power($this->game->objects[$survivor]), $this->game->toughness($this->game->objects[$survivor]), $this->game->objects[$survivor]->damage]);
        $this->assertSame(2, $this->game->prevent['o:'.$indestructible]);
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $this->restore();
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame([2, 4], [$this->game->power($this->game->objects[$survivor]), $this->game->toughness($this->game->objects[$survivor])]);
    }

    /** @dataProvider seats */
    public function testPaidPyroclasmDealsDamageUsesPreventionAndRespectsIndestructible(int $seat): void
    {
        $this->setupSeat($seat);
        $mana = $this->lands($seat, 'Mountain', 2);
        $own = $this->battlefield($seat, 'Grizzly Bears');
        $indestructible = $this->game->addCard(1 - $seat, ['name' => 'Indestructible Probe', 'type' => 'Creature - Construct',
            'power' => '2', 'toughness' => '2', 'text' => 'Indestructible'], GameObject::BATTLEFIELD)->id;
        $shielded = $this->game->addCard(1 - $seat, ['name' => 'Shield Probe', 'type' => 'Creature - Wall',
            'power' => '2', 'toughness' => '4'], GameObject::BATTLEFIELD)->id;
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$indestructible], 'indestructible'));
        $this->game->prevent['o:'.$shielded] = 2;
        $spell = $this->game->addCard($seat, $this->cached('Pyroclasm', 'aa3cdc04-b8f4-52a6-867c-81478d8accd4'), GameObject::HAND)->id;
        $this->game->cast($seat, $spell);
        $this->assertSame(2, $this->game->players[$seat]->expended);
        $this->assertCount(2, array_filter($mana, fn (int $id) => $this->game->objects[$id]->tapped));
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($own));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($indestructible));
        $this->assertSame(2, $this->game->objects[$indestructible]->damage);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($shielded));
        $this->assertSame(0, $this->game->objects[$shielded]->damage);
        $this->assertSame(0, $this->game->prevent['o:'.$shielded]);
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $this->restore();
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(0, $this->game->objects[$indestructible]->damage);
    }

    /** @dataProvider spellsAndSeats */
    public function testPaidCounterspellStopsEitherSweeper(string $name, string $uuid, int $seat): void
    {
        $this->setupSeat($seat);
        $this->lands($seat, $name === 'Infest' ? 'Swamp' : 'Mountain', $name === 'Infest' ? 3 : 2);
        $this->lands(1 - $seat, 'Island', 2);
        $own = $this->battlefield($seat, 'Grizzly Bears');
        $opponent = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $spell = $this->game->addCard($seat, $this->cached($name, $uuid), GameObject::HAND)->id;
        $counter = $this->hand(1 - $seat, 'Counterspell');
        $this->game->cast($seat, $spell);
        $id = $this->game->stack[0]['id'];
        $this->game->pass($seat);
        $this->game->cast(1 - $seat, $counter, 0, ['s:'.$id]);
        $this->restore();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($counter));
        foreach ([$own, $opponent] as $victim) {
            $object = $this->game->objects[$victim];
            $this->assertSame(GameObject::BATTLEFIELD, $object->zone);
            $this->assertSame([2, 2, 0], [$this->game->power($object), $this->game->toughness($object), $object->damage]);
        }
    }

    public static function spellsAndSeats(): array
    {
        return [
            ['Infest', 'a4683dd0-caf1-50aa-926c-8d09b1d0bded', 0],
            ['Infest', 'a4683dd0-caf1-50aa-926c-8d09b1d0bded', 1],
            ['Pyroclasm', 'aa3cdc04-b8f4-52a6-867c-81478d8accd4', 0],
            ['Pyroclasm', 'aa3cdc04-b8f4-52a6-867c-81478d8accd4', 1],
        ];
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
