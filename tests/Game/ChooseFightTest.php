<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;

final class ChooseFightTest extends GameTestCase
{
    private function spell(string $wording = 'do not'): array
    {
        return ['uuid' => 'fight-test', 'name' => 'Choose Fight', 'rarity' => 'common', 'manaValue' => 1.0, 'manaCost' => '{G}', 'type' => 'Instant', 'colors' => ['G'], 'text' => "Choose target creature you control and target creature you {$wording} control. Then those creatures fight each other."];
    }

    public function testBothWordingsFightWithoutABonus(): void
    {
        foreach (['do not', "don't"] as $wording) {
            $game = $this->newGame();
            $card = $this->spell($wording);
            $this->assertSame([], (new CardDefinition($card))->unsupported);
            $mine = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
            $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
            $this->lands(0, 'Forest', 1);
            $spell = $game->addCard(0, $card, GameObject::HAND);
            $game->cast(0, $spell->id, 0, ["o:{$mine->id}", "o:{$theirs->id}"]);
            $game->pass($game->priority);
            $game->pass($game->priority);
            $this->assertSame([GameObject::GRAVEYARD, GameObject::GRAVEYARD], [$mine->zone, $theirs->zone]);
            $this->assertSame(GameObject::GRAVEYARD, $spell->zone);
        }
    }

    public function testCannotChooseTwoFriendlyCreatures(): void
    {
        $game = $this->newGame();
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $other = $this->battlefield(0, 'Grizzly Bears');
        $this->lands(0, 'Forest', 1);
        $spell = $game->addCard(0, $this->spell(), GameObject::HAND);
        $this->expectException(GameException::class);
        $game->cast(0, $spell->id, 0, ["o:{$mine}", "o:{$other}"]);
    }

    public function testFightDoesNothingWhenOpponentTargetLeaves(): void
    {
        $game = $this->newGame();
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Forest', 1);
        $this->lands(1, 'Swamp', 3);
        $spell = $game->addCard(0, $this->spell(), GameObject::HAND);
        $game->cast(0, $spell->id, 0, ["o:{$mine}", "o:{$theirs}"]);
        $game->pass($game->priority);
        $removal = $game->addCard(1, self::card('Murder'), GameObject::HAND);
        $game->cast(1, $removal->id, 0, ["o:{$theirs}"]);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $game->pass($game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$theirs]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$mine]->zone);
        $this->assertSame(0, $game->objects[$mine]->damage);
    }
    public function testCannotCastWithoutPaying(): void
    {
        $game = $this->newGame();
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $spell = $game->addCard(0, $this->spell(), GameObject::HAND);
        $this->expectException(GameException::class);
        $game->cast(0, $spell->id, 0, ["o:{$mine}", "o:{$theirs}"]);
    }
}
