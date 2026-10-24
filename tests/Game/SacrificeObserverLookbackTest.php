<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-MTGPocket project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;

/** @covers \MTGPocket\Game\Game */
final class SacrificeObserverLookbackTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function resolveAll(): void
    {
        for ($n = 0; $this->game->stack !== [] && $n < 20; $n++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
    }

    private function resolveTriggers(int $controller): int
    {
        $count = 0;
        while (($trigger = $this->game->triggerAwaitingTargets()) !== null && $count < 10) {
            $this->assertSame($controller, $trigger['controller']);
            $this->game->chooseTriggerTargets($controller, ['p:'.(1 - $controller)]);
            $count++;
        }
        $this->assertNull($this->game->triggerAwaitingTargets());
        $this->assertCount($count, $this->game->stack);
        for ($n = 0; $this->game->stack !== [] && $n < 10; $n++) {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);

        return $count;
    }

    private function barter(int $seat): int
    {
        $this->lands($seat, 'Swamp', 4);
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-sacrifices.json'), true, flags: JSON_THROW_ON_ERROR);
        $card = $fixture['cards']['Barter in Blood']['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, GameObject::HAND)->id;
    }

    public function testExactMayhemDevilFixtureHasNotDrifted(): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/sacrifice-observer-mayhem-devil.json'));
        $this->assertSame('b48ef22ac84c30437cadda37ca93e8ef1cabbedf794cd25461c5908405a6d92a', hash('sha256', $bytes));
    }

    /** @dataProvider orders */
    public function testPaidBarterRetainsAllMayhemDevilSacrificeTriggers(int $active, int $controller, bool $first, bool $exile): void
    {
        $this->newGame();
        $this->game->active = $active;
        $this->game->priority = $active;
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/sacrifice-observer-mayhem-devil.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], (new CardDefinition($fixture['card']))->unsupported);
        // A stolen observer must keep its battlefield controller, even after it leaves.
        $addObserver = fn () => $this->game->addCard(1 - $controller, $fixture['card'], GameObject::BATTLEFIELD)->id;
        $observer = $first ? $addObserver() : null;
        $own = $this->battlefield($controller, 'Grizzly Bears');
        $other = [$this->battlefield(1 - $controller, 'Grizzly Bears'), $this->battlefield(1 - $controller, 'Grizzly Bears')];
        $observer ??= $addObserver();
        $this->game->objects[$observer]->controller = $controller;
        $incarnation = $this->game->objects[$observer]->incarnation;
        if ($exile) {
            foreach (array_merge([$observer, $own], $other) as $id) {
                $this->game->objects[$id]->exileIfDies = $this->game->turn;
            }
        }
        $this->game->cast($active, $this->barter($active));
        $before = $this->saved();
        $this->resolveOne();
        $waiting = $this->saved();
        $this->assertCount(4, $waiting['pendingTriggers'], 'The pre-sacrifice observer sees all four simultaneous sacrifices.');
        $this->assertSame($incarnation, $this->game->triggerAwaitingTargets()['incarnation']);
        $this->assertSame(4, $this->resolveTriggers($controller), 'Sacrificing all four permanents is four events, even if the observer leaves first or burial becomes exile.');
        $this->assertSame(16, $this->life(1 - $controller));
        $this->assertSame(20, $this->life($controller));
        foreach (array_merge([$observer, $own], $other) as $id) {
            $this->assertSame($exile ? GameObject::EXILE : GameObject::GRAVEYARD, $this->zone($id));
        }
        $expected = $this->saved();
        $this->game = Game::fromArray($waiting);
        $this->assertSame(4, $this->resolveTriggers($controller));
        $this->assertSame($expected, $this->saved());
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->assertSame(4, $this->resolveTriggers($controller));
        $this->assertSame($expected, $this->saved());
    }

    public static function orders(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $first) {
                    foreach ([false, true] as $exile) {
                        $cases[] = [$active, $controller, $first, $exile];
                    }
                }
            }
        }

        return $cases;
    }

    /** @dataProvider eventKinds */
    public function testSacrificeKindsControllersOtherAndTokensRemainDistinct(string $event, bool $token, int $gain): void
    {
        $this->newGame();
        $card = ['name' => 'Sacrifice Kind Observer', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => "Whenever {$event}, you gain 1 life."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $observer = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $observer->controller = 0;
        $partner = $this->game->addCard(0, ['name' => 'Sacrifice Partner', 'type' => 'Creature - Bear', 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], GameObject::BATTLEFIELD);
        $this->battlefield(1, 'Grizzly Bears');
        $this->battlefield(1, 'Grizzly Bears');
        $this->game->cast(0, $this->barter(0));
        $this->resolveOne();
        $this->assertCount($gain, $this->game->stack);
        $queued = $this->saved();
        $this->resolveAll();
        $this->assertSame([20 + $gain, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $partner->zone);
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function eventKinds(): array
    {
        return [
            ['you sacrifice a permanent', false, 2],
            ['an opponent sacrifices a permanent', false, 2],
            ['a player sacrifices a permanent', false, 4],
            ['a player sacrifices another creature', false, 3],
            ['you sacrifice another creature', false, 1],
            ['an opponent sacrifices another creature', false, 2],
            ['a player sacrifices a token', true, 1],
            ['a player sacrifices a nontoken creature', true, 3],
            ['a player sacrifices a token', false, 0],
            ['a player sacrifices an artifact', false, 0],
        ];
    }

    /** @dataProvider changingKinds */
    public function testSacrificedObjectUsesItsBattlefieldCharacteristics(string $mode, string $kind, int $gain): void
    {
        $this->newGame();
        $observer = ['name' => 'Changing Sacrifice Observer', 'type' => 'Enchantment', 'text' => "Whenever you sacrifice a {$kind}, you gain 1 life."];
        $this->assertSame([], (new CardDefinition($observer))->unsupported);
        $this->game->addCard(0, $observer, GameObject::BATTLEFIELD);
        $card = $mode === 'back'
            ? ['name' => 'Sacrifice Front', 'type' => 'Artifact', 'text' => '{1}: Transform Sacrifice Front.',
                'back' => ['name' => 'Sacrifice Back', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => null]]
            : ['name' => 'Sacrifice Animation', 'type' => 'Land', 'text' => "{1}: Sacrifice Animation becomes a 2/2 green Elemental creature until end of turn. It's still a land."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $this->game->activate(0, $object->id, 0);
        $this->resolveAll();
        $this->assertTrue($this->game->isCreature($object));
        $this->game->cast(0, $this->barter(0));
        $before = $this->saved();
        $this->resolveAll();
        $this->assertSame(20 + $gain, $this->life(0));
        $this->assertSame(GameObject::GRAVEYARD, $object->zone);
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function changingKinds(): array
    {
        return [['back', 'creature', 1], ['back', 'Human', 1], ['back', 'artifact', 0], ['animated', 'creature', 1], ['animated', 'land', 1], ['animated', 'artifact', 0]];
    }

    public function testDepartingObserverUsesItsBackFaceAbility(): void
    {
        $this->newGame();
        $card = ['name' => 'Sacrifice Observer Front', 'type' => 'Artifact', 'text' => '{1}: Transform Sacrifice Observer Front.',
            'back' => ['name' => 'Sacrifice Observer Back', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => 'Whenever a player sacrifices a permanent, you gain 1 life.']];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $object->controller = 0;
        $this->lands(0, 'Forest', 1);
        $this->game->activate(0, $object->id, 0);
        $this->resolveAll();
        $this->game->cast(0, $this->barter(0));
        $before = $this->saved();
        $this->resolveAll();
        $this->assertSame([21, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame(GameObject::GRAVEYARD, $object->zone);
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public function testFaceDownSourceDoesNotRevealItsPrintedSacrificeAbility(): void
    {
        $this->newGame();
        $card = ['name' => 'Hidden Sacrifice Observer', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => "Morph {1}\nWhenever a player sacrifices a permanent, you gain 1 life."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->lands(0, 'Forest', 3);
        $id = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $this->game->cast(0, $id, 0, [], 'morph');
        $this->resolveAll();
        $this->game->cast(0, $this->barter(0));
        $this->resolveAll();
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    public function testSingleSelfSacrificeCostRetainsControllerAndQueuesOnce(): void
    {
        $this->newGame();
        $card = ['name' => 'Single Sacrifice Observer', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => "Whenever you sacrifice a creature, you gain 1 life.\nSacrifice this creature: You gain 3 life."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $object = $this->game->addCard(1, $card, GameObject::BATTLEFIELD);
        $object->controller = 0;
        $this->game->activate(0, $object->id, 0);
        $queued = $this->saved();
        $this->assertCount(2, $this->game->stack);
        $this->resolveAll();
        $this->assertSame([24, 20], [$this->life(0), $this->life(1)]);
        $expected = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public function testSurvivingOncePerTurnObserverSharesMarksAcrossGroups(): void
    {
        $this->newGame();
        $card = ['name' => 'Limited Sacrifice Observer', 'type' => 'Enchantment', 'text' => 'Whenever a player sacrifices a permanent, you gain 1 life. This ability triggers only once each turn.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        for ($group = 0; $group < 2; $group++) {
            foreach ([0, 0, 1, 1] as $seat) {
                $this->battlefield($seat, 'Grizzly Bears');
            }
            $this->game->cast(0, $this->barter(0));
            $this->resolveAll();
            $this->assertSame([21, 20], [$this->life(0), $this->life(1)]);
            $this->game = Game::fromArray($this->saved());
        }
    }

    /** @dataProvider otherDepartures */
    public function testOtherZoneMovesDoNotBecomeSacrificeEvents(string $text, string $zone): void
    {
        $this->newGame();
        $card = ['name' => 'Non Sacrifice Observer', 'type' => 'Creature - Human', 'power' => '2', 'toughness' => '2', 'text' => 'Whenever a player sacrifices a permanent, you gain 1 life.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $observer = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $spell = ['name' => 'Non Sacrifice Removal', 'type' => 'Instant', 'manaCost' => '{G}', 'text' => $text];
        $this->assertSame([], (new CardDefinition($spell))->unsupported);
        $this->game->cast(0, $this->game->addCard(0, $spell, GameObject::HAND)->id, 0, ['o:'.$observer->id]);
        $before = $this->saved();
        $this->resolveAll();
        $this->assertSame($zone, $observer->zone);
        $this->assertSame([20, 20], [$this->life(0), $this->life(1)]);
        $expected = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveAll();
        $this->assertSame($expected, $this->saved());
    }

    public static function otherDepartures(): array
    {
        return [['Destroy target creature.', GameObject::GRAVEYARD], ['Exile target creature.', GameObject::EXILE], ["Return target creature to its owner's hand.", GameObject::HAND]];
    }
}
