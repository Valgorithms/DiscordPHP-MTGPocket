<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Cached paid-entry regressions; no whole-card support is claimed. */
final class ThornThrashDevourBaselineTest extends GameTestCase
{
    private function setupPaidCast(int $seat, bool $token): array
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $raw = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'));
        $this->assertSame('fcdd4ce84b1b790175e38defe0b52b95e620da3d439b6caf9dfae23560133157', hash('sha256', $raw));
        $card = json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame('a0a76682-f9b2-516e-b6de-d64f22591ea5', $card['uuid']);
        $this->lands($seat, 'Mountain', 4);
        $food = $token
            ? $this->game->addCard($seat, ['name' => 'Food Creature Fixture', 'token' => true, 'type' => 'Creature — Goblin', 'power' => '1', 'toughness' => '1'], GameObject::BATTLEFIELD)->id
            : $this->battlefield($seat, 'Grizzly Bears');
        $source = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $source, 0, []);
        $this->assertSame(GameObject::STACK, $this->game->objects[$source]->zone);
        $this->assertSame(4, $this->game->players[$seat]->expended);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);

        return [$source, $food];
    }

    /** @dataProvider seats */
    public function testPaidEntryMustOfferChoosingNoneBeforeSacrificingToken(int $seat): void
    {
        [$source, $food] = $this->setupPaidCast($seat, true);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone, 'Devour may sacrifice zero; no token may be consumed before the controller chooses.');
        $this->assertNotNull($this->game->choiceAwaiting(), 'An entry choice must be offered while a controlled creature is available.');
        $this->assertNotSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->game->chooseDevour($seat, $this->game->choiceAwaiting()['id'], []);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
    }

    /** @dataProvider seats */
    public function testPaidEntryMustOfferNonTokenCreature(int $seat): void
    {
        [$source, $food] = $this->setupPaidCast($seat, false);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
        $this->assertNotNull($this->game->choiceAwaiting(), 'Devour may sacrifice a controlled non-token creature.');
        $this->assertNotSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->game->chooseDevour($seat, $this->game->choiceAwaiting()['id'], [$food]);
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$food]->zone);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
