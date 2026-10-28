<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** Integration probe, not a whole-card coverage claim. */
final class DevourRecoverIntegrationTest extends GameTestCase
{
    /** @dataProvider seats */
    public function testRecoverWaitsForDevourAndEnclosingResolution(int $seat): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $records = json_decode(file_get_contents(__DIR__.'/../Fixtures/recover-damage-ordering.json'), true, flags: JSON_THROW_ON_ERROR)['cards'];
        $harvestCard = array_values(array_filter($records, fn (array $card) => $card['name'] === 'Grim Harvest'))[0];
        $harvest = $this->game->addCard($seat, $harvestCard, GameObject::GRAVEYARD)->id;
        $this->lands($seat, 'Mountain', 1);
        $this->lands($seat, 'Swamp', 3);
        $food = $this->battlefield($seat, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::GRAVEYARD)->id;
        $spell = $this->game->addCard($seat, ['name' => 'Reanimate and Gain Fixture', 'type' => 'Sorcery', 'manaCost' => '{R}',
            'text' => 'Return target creature card from your graveyard to the battlefield. You gain 3 life.'], GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$source]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('devour', $choice['type']);
        $this->assertSame(20, $this->life($seat));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseDevour($seat, $choice['id'], [$food]);
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$food]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(23, $this->life($seat));
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$spell]->zone);
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$harvest]->zone);
        $this->assertCount(1, $this->game->stack);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $recover = $this->game->choiceAwaiting();
        $this->assertSame('optional_recover', $recover['type']);
        $this->assertSame($seat, $recover['seat']);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseRecover($seat, $recover['id'], 'accept');
        $this->assertSame(GameObject::HAND, $this->game->objects[$harvest]->zone);
    }

    /** @dataProvider seats */
    public function testPaidDamageSuspensionThenDevourSurvivesJson(int $seat): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $state = $this->game->toArray();
        $state['damageEffects'] = [['id' => 1, 'controller' => $seat, 'expiresTurn' => $this->game->turn, 'factor' => 2]];
        $state['nextDamageEffectId'] = 2;
        $this->game = Game::fromArray($state);
        $this->lands($seat, 'Island', 3);
        $painer = $this->game->addCard($seat, ['uuid' => 'devour-painer', 'name' => 'Painer', 'type' => 'Land',
            'text' => '{T}: Add {R}. Painer deals 1 damage to you.'], GameObject::BATTLEFIELD)->id;
        $food = $this->battlefield($seat, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::HAND)->id;
        $this->game->prevent['p:'.$seat] = 1;
        $this->game->cast($seat, $source);
        $damage = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $damage['type']);
        $this->assertTrue($this->game->objects[$painer]->tapped);
        $this->assertSame(GameObject::HAND, $this->game->objects[$source]->zone);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseDamageOrder($seat, $damage['id'], 'prevent');
        $this->assertSame(20, $this->life($seat));
        $this->assertSame(4, $this->game->players[$seat]->expended);
        $this->assertSame(GameObject::STACK, $this->game->objects[$source]->zone);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('devour', $choice['type']);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseDevour($seat, $choice['id'], [$food]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(4, $this->game->players[$seat]->expended);
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
