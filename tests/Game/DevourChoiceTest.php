<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use MTGPocket\Tutorial\PracticeBot;

final class DevourChoiceTest extends GameTestCase
{
    /** @dataProvider scenarios */
    public function testPaidDevourChoicePersistsAndFinishesOnce(int $seat, string $selection): void
    {
        $this->newGame();
        if ($seat === 1) {
            $this->passUntil(Step::PrecombatMain, 2);
        }
        $this->game->setDevourChoices(true);
        $raw = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'));
        $this->assertSame('fcdd4ce84b1b790175e38defe0b52b95e620da3d439b6caf9dfae23560133157', hash('sha256', $raw));
        $card = json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->lands($seat, 'Mountain', 4);
        $token = $this->game->addCard($seat, ['name' => 'Food Token', 'token' => true, 'type' => 'Creature — Goblin', 'power' => '1', 'toughness' => '1'], GameObject::BATTLEFIELD)->id;
        $bear = $this->battlefield($seat, 'Grizzly Bears');
        $opponent = $this->battlefield(1 - $seat, 'Grizzly Bears');
        $source = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('devour', $this->game->decision($seat));
        $this->assertSame(GameObject::STACK, $this->game->objects[$source]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$token]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$bear]->zone);
        $before = $this->game->toArray();
        try {
            $this->game->setDevourChoices(false);
            $this->fail('A pending Devour choice allowed the experiment to be disabled.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
        }
        $state = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
        foreach (['missing-tail', 'wrong-entrant', 'wrong-number', 'stale-food', 'old-schema'] as $invalid) {
            $bad = $state;
            if ($invalid === 'missing-tail') {
                unset($bad['pendingChoice']['spellTail']);
            } elseif ($invalid === 'wrong-entrant') {
                $bad['pendingChoice']['object'] = $opponent;
            } elseif ($invalid === 'wrong-number') {
                $bad['pendingChoice']['number']++;
            } elseif ($invalid === 'stale-food') {
                $bad['pendingChoice']['incarnations'][$bear]++;
            } else {
                $bad['schemaVersion'] = 16;
            }
            try {
                Game::fromArray($bad);
                $this->fail('Invalid saved Devour choice was accepted: '.$invalid);
            } catch (GameException) {
                $this->assertSame($state, json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR));
            }
        }
        $this->game = Game::fromArray($state);
        foreach ([[1 - $seat, $choice['id'], []], [$seat, $choice['id'] + 1, []], [$seat, $choice['id'], [$opponent]], [$seat, $choice['id'], [$bear, $bear]]] as $bad) {
            $before = $this->game->toArray();
            try {
                $this->game->chooseDevour(...$bad);
                $this->fail('Invalid Devour selection was accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->game->toArray());
            }
        }
        $chosen = match ($selection) {
            'none' => [], 'token' => [$token], 'bear' => [$bear], 'all' => [$token, $bear]
        };
        $this->game->chooseDevour($seat, $choice['id'], $chosen);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame(2 * count($chosen), $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(in_array($token, $chosen, true) ? GameObject::GONE : GameObject::BATTLEFIELD, $this->game->objects[$token]->zone);
        $this->assertSame(in_array($bear, $chosen, true) ? GameObject::GRAVEYARD : GameObject::BATTLEFIELD, $this->game->objects[$bear]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$opponent]->zone);
        $before = $this->game->toArray();
        try {
            $this->game->chooseDevour($seat, $choice['id'], $chosen);
            $this->fail('Repeated Devour selection was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
        }
    }

    public static function scenarios(): array
    {
        $cases = [];
        foreach ([0, 1] as $seat) {
            foreach (['none', 'token', 'bear', 'all'] as $selection) {
                $cases[] = [$seat, $selection];
            }
        }

        return $cases;
    }

    /** @dataProvider seats */
    public function testReanimationSuspendsRemainingResolutionUntilChoice(int $seat): void
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
        $spell = $this->game->addCard($seat, ['name' => 'Reanimate and Gain Fixture', 'type' => 'Sorcery', 'manaCost' => '{R}',
            'text' => 'Return target creature card from your graveyard to the battlefield. You gain 3 life.'], GameObject::HAND)->id;
        $this->game->cast($seat, $spell, 0, ['o:'.$source]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('devour', $choice['type']);
        $this->assertSame(20, $this->life($seat));
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$source]->zone);
        $this->assertSame(GameObject::STACK, $this->game->objects[$spell]->zone);
        $this->assertNotContains('Thorn-Thrash Viashino returns to the battlefield.', $this->game->log);
        $state = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        foreach (['missing-resume', 'wrong-source', 'bad-step'] as $case) {
            $bad = $state;
            if ($case === 'missing-resume') {
                unset($bad['pendingChoice']['resume']);
            } elseif ($case === 'wrong-source') {
                $bad['pendingChoice']['resume']['item']['object'] = 99999999;
            } else {
                $bad['pendingChoice']['resume']['steps'][0] = ['garbage'];
            }
            try {
                Game::fromArray($bad);
                $this->fail('Invalid saved reanimation continuation was accepted: '.$case);
            } catch (GameException) {
                $this->assertSame($state, json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
            }
        }
        $this->game = Game::fromArray($state);
        $this->game->chooseDevour($seat, $choice['id'], [$food]);
        $this->assertSame(23, $this->life($seat));
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$spell]->zone);
        $this->assertSame(1, count(array_filter($this->game->log, fn (string $line) => $line === 'Thorn-Thrash Viashino returns to the battlefield.')));
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }

    public function testPracticeUsesTheSameExplicitZeroChoice(): void
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $this->lands(0, 'Mountain', 4);
        $food = $this->battlefield(0, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard(0, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast(0, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame('devour', $this->game->decision(0));
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testReturnedCreatureCannotSatisfyItsOriginalSelection(): void
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $this->lands(0, 'Mountain', 4);
        $food = $this->battlefield(0, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard(0, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast(0, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        // Unit boundary: simulate the original permanent leaving and returning while suspended.
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$food], GameObject::HAND);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$food], 0, true, false, false, 0, false, false, false, []);
        $before = $this->game->toArray();
        try {
            $this->game->chooseDevour(0, $choice['id'], [$food]);
            $this->fail('A returned creature satisfied an old Devour selection.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
            $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$food]->zone);
            $this->assertSame(GameObject::STACK, $this->game->objects[$source]->zone);
        }
    }

    public function testLegacyLandChoiceRemainsResumableWithDevourModeEnabled(): void
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $land = $this->game->addCard(0, ['name' => 'Breeding Pool', 'type' => 'Land — Forest Island',
            'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {G} or {U}."], GameObject::HAND)->id;
        $this->game->playLand(0, $land);
        $this->assertSame('entry_life', $this->game->decision(0));
        $id = $this->game->choiceAwaiting()['id'];
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseEntryLife(0, $id, 'decline');
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$land]->zone);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    public function testQueuedLandEntryKeepsDevourParentResolutionAcrossBothChoices(): void
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $this->lands(0, 'Mountain', 1);
        $food = $this->battlefield(0, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard(0, $fixture['card'], GameObject::GRAVEYARD)->id;
        $land = $this->game->addCard(0, ['name' => 'Breeding Pool', 'type' => 'Land — Forest Island',
            'text' => "As this land enters, you may pay 2 life. If you don't, it enters tapped.\n{T}: Add {G} or {U}."], GameObject::HAND)->id;
        $spell = $this->game->addCard(0, ['name' => 'Reanimate and Gain Fixture', 'type' => 'Sorcery', 'manaCost' => '{R}',
            'text' => 'Return target creature card from your graveyard to the battlefield. You gain 3 life.'], GameObject::HAND)->id;
        $this->game->cast(0, $spell, 0, ['o:'.$source]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $choice = $this->game->choiceAwaiting();
        // Unit boundary: a later entry is queued while the first entry is paused.
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$land], 0);
        $this->game->chooseDevour(0, $choice['id'], [$food]);
        $this->assertSame('entry_life', $this->game->decision(0));
        $this->assertSame(GameObject::STACK, $this->game->objects[$spell]->zone);
        $this->assertSame(20, $this->life(0));
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $landChoice = $this->game->choiceAwaiting();
        $state = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $invalid = $state;
        unset($invalid['pendingChoice']['resume']);
        try {
            Game::fromArray($invalid);
            $this->fail('A queued land decision lost its parent effect.');
        } catch (GameException) {
            $this->assertSame('effect', $state['pendingChoice']['devourParentKind']);
        }
        $this->game = Game::fromArray($state);
        $this->game->chooseEntryLife(0, $landChoice['id'], 'decline');
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$land]->zone);
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$spell]->zone);
        $this->assertSame(23, $this->life(0));
    }

    public function testQueuedLandEntryPreservesPermanentSpellTailUntilBothChoicesFinish(): void
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
        $choice = $this->game->choiceAwaiting();
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$land], 0);
        $this->game->chooseDevour(0, $choice['id'], [$food]);
        $this->assertSame('entry_life', $this->game->decision(0));
        $this->assertSame('', $this->game->objects[$source]->spent);
        $this->assertNotContains('Thorn-Thrash Viashino enters the battlefield.', $this->game->log);
        $id = $this->game->choiceAwaiting()['id'];
        $state = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $invalid = $state;
        unset($invalid['pendingChoice']['spellTail']);
        try {
            Game::fromArray($invalid);
            $this->fail('A queued land decision lost its permanent-spell tail.');
        } catch (GameException) {
            $this->assertSame('spell', $state['pendingChoice']['devourParentKind']);
        }
        $this->game = Game::fromArray($state);
        $this->game->chooseEntryLife(0, $id, 'decline');
        $this->assertNotSame('', $this->game->objects[$source]->spent);
        $this->assertSame(1, count(array_filter($this->game->log, fn (string $line) => $line === 'Thorn-Thrash Viashino enters the battlefield.')));
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testSeededPermanentSpellTailRunsOnceAfterRestoredChoice(): void
    {
        $this->newGame();
        $this->game->setDevourChoices(true);
        $this->lands(0, 'Mountain', 4);
        $food = $this->battlefield(0, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard(0, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast(0, $source, 0, []);
        // Unit boundary: seed alternate-resolution and spent-mana metadata on the already-paid entry.
        $this->game->stack[0]['alt'] = 'evoke';
        $this->game->stack[0]['sunburst'] = 1;
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $id = $this->game->choiceAwaiting()['id'];
        $this->assertSame('', $this->game->objects[$source]->spent);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->game->chooseDevour(0, $id, [$food]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->game->objects[$source]->zone);
        $this->assertSame('evoke', $this->game->objects[$source]->alt);
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertNotSame('', $this->game->objects[$source]->spent);
        $this->assertCount(1, $this->game->stack);
        $this->assertStringContainsString('evoke', $this->game->stack[0]['label']);
        $this->assertSame(1, count(array_filter($this->game->log, fn (string $line) => $line === 'Thorn-Thrash Viashino enters the battlefield.')));
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$source]->zone);
    }
}
