<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameException;
use MTGPocket\Game\Step;

/** Cached Emerge card; transforming source is seeded to isolate its mana value. */
final class EmergeTransformedManaValueTest extends GameTestCase
{
    private function prepare(int $seat, int $islands): array
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/emerge-transformed-value.json'));
        $this->assertSame('69ca7b2b6e88b16e86b944ad790e2cbd9d3d0d81ed8a0606147a4a6da7e1ed18', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'];
        $this->assertSame('9fde7c4e-1ca6-5fff-a266-3e4c8871ca09', $cards[0]['card']['uuid']);
        $this->assertSame('d7eb7756-fc8f-5e70-b86d-db6446361748', $cards[1]['card']['uuid']);
        $fodder = $game->addCard($seat, $cards[1]['card'], GameObject::BATTLEFIELD)->id;
        $game->objects[$fodder]->transformed = true;
        $this->assertSame(4, $game->objects[$fodder]->battlefieldManaValue());
        $this->assertSame(0, $game->objects[$fodder]->definition()->cost->manaValue());
        $lands = $islands > 0 ? $this->lands($seat, 'Island', $islands) : [];
        $gryff = $game->addCard($seat, $cards[0]['card'], GameObject::HAND)->id;

        return [$fodder, $lands, $gryff];
    }

    /** @dataProvider seats */
    public function testPaidEmergeDiscountUsesTransformedFrontValue(int $seat): void
    {
        [$fodder, $lands, $gryff] = $this->prepare($seat, 2);
        $priorHand = count($this->game->players[$seat]->hand) - 1;
        $this->assertContains(['id' => $gryff, 'how' => 'emerge'], $this->game->plays($seat));
        $this->game->cast($seat, $gryff, 0, [], 'emerge');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->assertSame(GameObject::STACK, $this->zone($gryff));
        $saved = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($saved);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        $this->assertSame(GameObject::STACK, $this->zone($gryff));
        $this->assertCount(2, $this->game->stack);
        for ($i = 0; $i < 4; $i++) {
            $this->game->pass($this->game->priority);
        }
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($gryff));
        $this->assertCount($priorHand + 1, $this->game->players[$seat]->hand);
    }

    /** @dataProvider seats */
    public function testAutomaticFodderPolicyUsesTransformedFrontValue(int $seat): void
    {
        [$fodder, , $gryff] = $this->prepare($seat, 6);
        $bear = $this->battlefield($seat, 'Grizzly Bears');
        $this->game->cast($seat, $gryff, 0, [], 'emerge');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bear));
    }

    /** @dataProvider seats */
    public function testFaceDownSourceHasZeroDiscountAndFailedPaymentIsAtomic(int $seat): void
    {
        [$fodder, , $gryff] = $this->prepare($seat, 2);
        $this->game->objects[$fodder]->faceDown = true;
        $this->assertSame(0, $this->game->objects[$fodder]->battlefieldManaValue());
        $before = $this->game->toArray();
        try {
            $this->game->cast($seat, $gryff, 0, [], 'emerge');
            $this->fail('The zero-value creature cannot reduce the cost enough.');
        } catch (GameException $e) {
            $this->assertStringContainsString('cannot pay', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    /** @dataProvider seats */
    public function testColoredEmergeCostCannotBeReducedAway(int $seat): void
    {
        [$fodder, , $gryff] = $this->prepare($seat, 0);
        $this->lands($seat, 'Forest', 6);
        $before = $this->game->toArray();
        try {
            $this->game->cast($seat, $gryff, 0, [], 'emerge');
            $this->fail('The blue cost must still be paid.');
        } catch (GameException $e) {
            $this->assertStringContainsString('cannot pay', $e->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($fodder));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
