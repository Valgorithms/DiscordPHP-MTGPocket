<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

final class DevourQueueValidationTest extends GameTestCase
{
    private function malformed(string $case): array
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $this->lands(0, 'Mountain', 4);
        $food = $this->battlefield(0, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard(0, $fixture['card'], GameObject::HAND)->id;
        $land = $this->game->addCard(0, ['name' => 'Breeding Pool', 'type' => 'Land — Forest Island',
            'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {G} or {U}."], GameObject::HAND)->id;
        $this->game->cast(0, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        if (str_starts_with($case, 'later-')) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$land], 0);
        }
        $property = new \ReflectionProperty(Game::class, 'pendingEntries');
        $entries = $property->getValue($this->game);
        switch ($case) {
            case 'counter-array': $entries[0]['entryState']['counters']['+1/+1'] = [];
                break;
            case 'counters-container': $entries[0]['entryState']['counters'] = 'bad';
                break;
            case 'counter-key': $entries[0]['entryState']['counters'] = [0 => 1];
                break;
            case 'counter-negative': $entries[0]['entryState']['counters']['+1/+1'] = -1;
                break;
            case 'counter-overflow': $entries[0]['entryState']['counters']['+1/+1'] = 2147483648;
                break;
            case 'alt': $entries[0]['entryState']['alt'] = [];
                break;
            case 'cast': $entries[0]['entryState']['cast'] = [];
                break;
            case 'unearthed': $entries[0]['entryState']['unearthed'] = 'true';
                break;
            case 'haste': $entries[0]['entryState']['haste'] = 1;
                break;
            case 'attacking': $entries[0]['entryState']['attacking'] = [];
                break;
            case 'return-log': $entries[0]['entryState']['devourReturnLog'] = 'yes';
                break;
            case 'exile-context': $entries[0]['entryState']['endExileContext'] = ['source' => 999999, 'incarnation' => 0, 'controller' => 0];
                break;
            case 'unknown-state': $entries[0]['entryState']['unknown'] = true;
                break;
            case 'later-entry': $entries[1]['object'] = 999999;
                break;
            case 'later-incarnation': $entries[1]['incarnation']++;
                break;
            case 'later-controller': $entries[1]['controller'] = 3;
                break;
            case 'later-flag': $entries[1]['tapped'] = 'yes';
                break;
            case 'later-x': $entries[1]['x'] = [];
                break;
            case 'later-state': $entries[1]['entryState']['counters']['+1/+1'] = [];
                break;
            case 'later-duplicate': $entries[1]['object'] = $entries[0]['object'];
                break;
        }
        $property->setValue($this->game, $entries);

        return [$food, $source, $this->game->choiceAwaiting()['id']];
    }

    /** @dataProvider invalidQueues */
    public function testMalformedQueueRefusesDirectChoiceWithoutMutation(string $case): void
    {
        [$food, $source, $id] = $this->malformed($case);
        $before = hash('sha256', json_encode($this->game->toArray(), JSON_THROW_ON_ERROR));
        try {
            $this->game->chooseDevour(0, $id, [$food]);
            $this->fail('Malformed Devour queue was accepted.');
        } catch (\Throwable $error) {
            $this->assertSame($before, hash('sha256', json_encode($this->game->toArray(), JSON_THROW_ON_ERROR)), 'The choice must fail before any sacrifice or entry.');
            $this->assertInstanceOf(GameException::class, $error);
            $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
            $this->assertSame(GameObject::STACK, $this->game->objects[$source]->zone);
        }
    }

    /** @dataProvider invalidQueues */
    public function testMalformedQueueRefusesRestoreWithoutChangingOriginal(string $case): void
    {
        $this->malformed($case);
        $state = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        try {
            Game::fromArray($state);
            $this->fail('Malformed Devour queue was restored.');
        } catch (GameException) {
            $this->assertSame($state, json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public static function invalidQueues(): array
    {
        return array_map(fn (string $case) => [$case], ['counter-array', 'counters-container', 'counter-key', 'counter-negative', 'counter-overflow',
            'alt', 'cast', 'unearthed', 'haste', 'attacking', 'return-log', 'exile-context', 'unknown-state', 'later-entry',
            'later-incarnation', 'later-controller', 'later-flag', 'later-x', 'later-state', 'later-duplicate']);
    }

    public function testCounterSumOverflowRefusesBeforeSacrifice(): void
    {
        [$food, $source, $id] = $this->malformed('counter-array');
        $property = new \ReflectionProperty(Game::class, 'pendingEntries');
        $entries = $property->getValue($this->game);
        $entries[0]['entryState']['counters']['+1/+1'] = 2147483647;
        $property->setValue($this->game, $entries);
        $before = $this->game->toArray();
        try {
            $this->game->chooseDevour(0, $id, [$food]);
            $this->fail('The counter sum exceeded its bound.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
            $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
            $this->assertSame(GameObject::STACK, $this->game->objects[$source]->zone);
        }
        $this->game->chooseDevour(0, $id, []);
        $this->assertSame(2147483647, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testLandChoiceAlsoValidatesLaterEntryBeforePayment(): void
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $card = ['name' => 'Breeding Pool', 'type' => 'Land — Forest Island',
            'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {G} or {U}."];
        $first = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $second = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $this->game->playLand(0, $first);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$second], 0);
        $id = $this->game->choiceAwaiting()['id'];
        $property = new \ReflectionProperty(Game::class, 'pendingEntries');
        $queue = $property->getValue($this->game);
        $queue[1]['object'] = 999999;
        $property->setValue($this->game, $queue);
        $before = $this->game->toArray();
        try {
            $this->game->chooseEntryLife(0, $id, 'pay');
            $this->fail('A land choice accepted an invalid later entry.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
            $this->assertSame(20, $this->game->players[0]->life);
            $this->assertSame(GameObject::HAND, $this->game->objects[$first]->zone);
        }
    }

    /** @dataProvider seats */
    public function testValidDelayedExileEntryContextSurvivesRestore(int $seat): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $this->lands($seat, 'Mountain', 1);
        $food = $this->battlefield($seat, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard($seat, $fixture['card'], GameObject::GRAVEYARD)->id;
        $revive = ['name' => 'Temporary Reanimate Fixture', 'type' => 'Sorcery', 'manaCost' => '{R}',
            'text' => 'Return target creature card from your graveyard to the battlefield. It gains haste. Exile it at the beginning of the next end step.'];
        $this->assertSame([], (new CardDefinition($revive))->unsupported);
        $spell = $this->game->addCard($seat, $revive, GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$source]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('devour', $choice['type']);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseDevour($seat, $choice['id'], [$food]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame('end_exile', $this->game->objects[$source]->alt);
        $this->assertSame($spell, $this->game->objects[$source]->endExileContext['source']);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('end_exile', $this->game->objects[$source]->alt);
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
