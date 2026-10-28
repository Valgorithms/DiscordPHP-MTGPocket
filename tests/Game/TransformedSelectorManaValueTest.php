<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Tests the selector with a seeded transformed state, not Huntmaster's abilities. */
final class TransformedSelectorManaValueTest extends GameTestCase
{
    /** @dataProvider seats */
    public function testPaidCullingSunUsesFrontManaValueForTransformedPermanent(int $seat): void
    {
        $game = $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/transform-selector.json'));
        $this->assertSame('6c7eb1d6a91a5c867f6dc08514d1264fdf634df77cd916a8bff1d09697e46b46', hash('sha256', $bytes));
        $records = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['records'];
        $sun = $records[0]['card'];
        $huntmaster = $records[1]['card'];
        $this->assertSame('c3f254ed-45ba-58b5-8079-ee425eae4e6f', $sun['uuid']);
        $this->assertSame('d7eb7756-fc8f-5e70-b86d-db6446361748', $huntmaster['uuid']);
        $back = $game->addCard(1 - $seat, $huntmaster, GameObject::BATTLEFIELD)->id;
        $game->objects[$back]->transformed = true;
        $front = $game->addCard($seat, $huntmaster, GameObject::BATTLEFIELD)->id;
        $facedown = $game->addCard($seat, $huntmaster, GameObject::BATTLEFIELD)->id;
        $game->objects[$facedown]->faceDown = true;
        // Seed two copy shapes already supported by the battlefield-value helper.
        $singleFace = $game->addCard($seat, ['token' => true] + $huntmaster['back'], GameObject::BATTLEFIELD)->id;
        $transformingToken = $huntmaster;
        $transformingToken['token'] = $transformingToken['back']['token'] = true;
        $token = $game->addCard(1 - $seat, $transformingToken, GameObject::BATTLEFIELD)->id;
        $game->objects[$token]->transformed = true;
        $bear = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $lands = [...$this->lands($seat, 'Swamp', 3), ...$this->lands($seat, 'Plains', 2)];
        $spell = $game->addCard($seat, $sun, GameObject::HAND)->id;
        $this->assertSame(4, $game->objects[$back]->battlefieldManaValue());
        $this->assertSame(0, $game->objects[$back]->definition()->cost->manaValue());
        $this->assertSame(0, $game->objects[$facedown]->battlefieldManaValue());
        $this->assertSame(0, $game->objects[$singleFace]->battlefieldManaValue());
        $this->assertSame(4, $game->objects[$token]->battlefieldManaValue());
        $game->cast($seat, $spell);
        foreach ($lands as $land) {
            $this->assertTrue($game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($back));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($front));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($facedown));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bear));
        $this->assertSame(GameObject::GONE, $this->zone($singleFace));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($token));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
