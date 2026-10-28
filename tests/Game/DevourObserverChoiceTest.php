<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

final class DevourObserverChoiceTest extends GameTestCase
{
    /** @dataProvider observerCases */
    public function testSelectedSacrificeQueuesObserverOnlyAfterEntry(int $seat, bool $token, bool $exile): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $this->lands($seat, 'Mountain', 4);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $observerFixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/sacrifice-observer-mayhem-devil.json'), true, flags: JSON_THROW_ON_ERROR);
        $observer = $this->game->addCard(1 - $seat, $observerFixture['card'], GameObject::BATTLEFIELD)->id;
        $food = $this->game->addCard($seat, ['name' => 'Selected Food', 'token' => $token, 'type' => 'Creature — Goblin', 'power' => '1', 'toughness' => '1'], GameObject::BATTLEFIELD)->id;
        if ($exile) {
            $this->game->objects[$food]->exileIfDies = $this->game->turn;
        }
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('devour', $choice['type']);
        $this->assertNull($this->game->triggerAwaitingTargets());
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
        $this->game->chooseDevour($seat, $choice['id'], [$food]);
        $this->assertSame($token ? GameObject::GONE : ($exile ? GameObject::EXILE : GameObject::GRAVEYARD), $this->game->objects[$food]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(20, $this->life($seat));
        $trigger = $this->game->triggerAwaitingTargets();
        $this->assertNotNull($trigger);
        $this->assertSame($observer, $trigger['source']);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseTriggerTargets(1 - $seat, ['p:'.$seat]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(19, $this->life($seat));
    }

    public static function observerCases(): array
    {
        $cases = [];
        foreach ([0, 1] as $seat) {
            foreach ([[true, false], [false, false], [false, true]] as [$token, $exile]) {
                $cases[] = [$seat, $token, $exile];
            }
        }

        return $cases;
    }

    /** @dataProvider entryCases */
    public function testEntryObserverSeesDevourCounters(int $seat, bool $sacrifice): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $this->lands($seat, 'Mountain', 4);
        $watcher = ['name' => 'Fierce Eye Fixture', 'type' => 'Enchantment',
            'text' => 'Whenever a creature you control with power 4 or greater enters, draw a card.'];
        $this->assertSame([], (new CardDefinition($watcher))->unsupported);
        $this->game->addCard($seat, $watcher, GameObject::BATTLEFIELD);
        $food = $this->battlefield($seat, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $handBefore = count($this->game->players[$seat]->hand);
        $this->game->chooseDevour($seat, $this->game->choiceAwaiting()['id'], $sacrifice ? [$food] : []);
        $this->assertSame($sacrifice ? 4 : 2, $this->game->power($this->game->objects[$source]));
        $this->assertCount($sacrifice ? 1 : 0, $this->game->stack);
        if ($sacrifice) {
            $this->game->pass($this->game->priority);
            $this->game->pass($this->game->priority);
            $this->assertCount($handBefore + 1, $this->game->players[$seat]->hand);
        }
    }

    public static function entryCases(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    /** @dataProvider groupCases */
    public function testSimultaneousSelectedGroupRetainsDepartingObserver(int $seat, bool $observerFirst): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $this->lands($seat, 'Mountain', 4);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $mayhem = json_decode(file_get_contents(__DIR__.'/../Fixtures/sacrifice-observer-mayhem-devil.json'), true, flags: JSON_THROW_ON_ERROR);
        $observer = $this->game->addCard($seat, $mayhem['card'], GameObject::BATTLEFIELD)->id;
        $incarnation = $this->game->objects[$observer]->incarnation;
        $food = $this->game->addCard($seat, ['name' => 'Selected Token', 'token' => true, 'type' => 'Creature — Goblin', 'power' => '1', 'toughness' => '1'], GameObject::BATTLEFIELD)->id;
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->game->chooseDevour($seat, $this->game->choiceAwaiting()['id'], $observerFirst ? [$observer, $food] : [$food, $observer]);
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$observer]->zone);
        $this->assertSame(GameObject::GONE, $this->game->objects[$food]->zone);
        $this->assertSame(4, $this->game->objects[$source]->counter('+1/+1'));
        for ($i = 0; $i < 2; $i++) {
            $trigger = $this->game->triggerAwaitingTargets();
            $this->assertNotNull($trigger);
            $this->assertSame($observer, $trigger['source']);
            $this->assertSame($incarnation, $trigger['incarnation']);
            $this->game->chooseTriggerTargets($seat, ['p:'.(1 - $seat)]);
        }
        $this->assertNull($this->game->triggerAwaitingTargets());
        $this->assertCount(2, $this->game->stack);
    }

    public static function groupCases(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    /** @dataProvider seats */
    public function testReanimationFinishesBeforeSacrificeTriggerGetsPriority(int $seat): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $this->lands($seat, 'Mountain', 1);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $mayhem = json_decode(file_get_contents(__DIR__.'/../Fixtures/sacrifice-observer-mayhem-devil.json'), true, flags: JSON_THROW_ON_ERROR);
        $observer = $this->game->addCard(1 - $seat, $mayhem['card'], GameObject::BATTLEFIELD)->id;
        $food = $this->battlefield($seat, 'Grizzly Bears');
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::GRAVEYARD)->id;
        $spell = $this->game->addCard($seat, ['name' => 'Reanimate and Gain Fixture', 'type' => 'Sorcery', 'manaCost' => '{R}',
            'text' => 'Return target creature card from your graveyard to the battlefield. You gain 3 life.'], GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$source]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(20, $this->life($seat));
        $this->assertNull($this->game->triggerAwaitingTargets());
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseDevour($seat, $choice['id'], [$food]);
        $this->assertSame(23, $this->life($seat));
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$spell]->zone);
        $this->assertSame($observer, $this->game->triggerAwaitingTargets()['source']);
        $this->game->chooseTriggerTargets(1 - $seat, ['p:'.$seat]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(22, $this->life($seat));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
