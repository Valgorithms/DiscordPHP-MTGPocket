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

use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;

/** @covers \MTGPocket\Game\Game */
final class RecoverDamageOrderingTest extends GameTestCase
{
    private function cached(string $name): array
    {
        $fixture = in_array($name, ['Grim Harvest', 'Underground River'], true) ? 'recover-damage-ordering' : 'quest-pure-flame';
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/'.$fixture.'.json'));
        $this->assertSame($fixture === 'recover-damage-ordering' ? 'c9f0b605834854b60e5415a12424d22b6127faf64d47a5b23c78512fce089d13' : '67ab998793f431650a7a452f4e2a3b546fbc0c587320a8ed0ee51baf7743852b', hash('sha256', $bytes));

        return array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'], fn (array $card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function setupDeath(bool $ordering): array
    {
        $this->newGame();
        if ($ordering) {
            // Paid Quest, with seeded counters: activation isolation only.
            $this->lands(0, 'Mountain', 1);
            $quest = $this->game->addCard(0, $this->cached('Quest for Pure Flame'), GameObject::HAND)->id;
            $this->game->cast(0, $quest);
            $this->resolveOne();
            $this->game->objects[$quest]->addCounters('quest', 4);
            $this->game->activate(0, $quest, 0);
            $this->resolveOne();
        }
        $harvest = $this->game->addCard(0, $this->cached('Grim Harvest'), GameObject::GRAVEYARD)->id;
        // Actual paid creature cast and opponent removal; recover starts in the graveyard.
        $this->lands(0, 'Mountain', 1);
        $goblin = $this->game->addCard(0, $this->cached('Raging Goblin'), GameObject::HAND)->id;
        $this->game->cast(0, $goblin);
        $this->resolveOne();
        $mana = $this->lands(0, $ordering ? 'Island' : 'Swamp', $ordering ? 2 : 3);
        if ($ordering) {
            // Two generic mana + a mandatory painful black source (no painless B).
            $mana[] = $this->game->addCard(0, $this->cached('Underground River'), GameObject::BATTLEFIELD)->id;
            $this->game->prevent['p:0'] = 1;
        }
        $this->lands(1, 'Mountain', 1);
        $bolt = $this->game->addCard(1, $this->cached('Lightning Bolt'), GameObject::HAND)->id;
        $this->game->pass(0);
        $this->game->cast(1, $bolt, 0, ['o:'.$goblin]);

        return [$harvest, $goblin, $bolt, $mana];
    }

    public function testDeathQueuesRecoverBeforeOptionalPayment(): void
    {
        [$harvest, $goblin, $bolt, $mana] = $this->setupDeath(false);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($goblin));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($harvest), 'Recover must wait for its triggered ability, rather than auto-pay during movement.');
        foreach ($mana as $id) {
            $this->assertFalse($this->game->objects[$id]->tapped);
        }
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertSame('optional_recover', $this->game->choiceAwaiting()['type']);
    }

    public function testDeathWithOrderingResourcesStillCompletesBeforeRecoverPayment(): void
    {
        [$harvest, $goblin, $bolt, $mana] = $this->setupDeath(true);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($goblin));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($harvest));
        $this->assertCount(1, $this->game->stack);
        foreach ($mana as $id) {
            $this->assertFalse($this->game->objects[$id]->tapped);
        }
        $this->assertNull($this->game->choiceAwaiting());
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid choice accepted.');
        } catch (\MTGPocket\Game\GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider ordering */
    public function testAcceptedRecoverPaymentResumesExactlyOnceAcrossJson(string $first, int $life): void
    {
        [$harvest, $goblin, $bolt, $mana] = $this->setupDeath(true);
        $this->resolveOne();
        $queued = $this->saved();
        $this->resolveOne();
        $optional = $this->saved();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_recover', 0, '{2}{B}'], [$choice['type'], $choice['seat'], $choice['cost']]);
        $this->assertTrue($this->game->canPayRecover(0));
        $this->assertFalse($this->game->canPayRecover(1));
        $this->reject(fn () => $this->game->chooseRecover(1, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseRecover(0, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseRecover(0, $choice['id'], 'bogus'));
        $expended = $this->game->players[0]->expended;
        $this->game->chooseRecover(0, $choice['id'], 'accept');
        $pending = $this->saved();
        $order = $this->game->choiceAwaiting();
        $this->assertSame('damage_order', $order['type']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($harvest));
        $this->assertSame(20, $this->life(0));
        $this->assertSame($expended + 3, $this->game->players[0]->expended);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->reject(fn () => $this->game->chooseRecover(0, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseDamageOrder(1, $order['id'], $first));
        $this->game->chooseDamageOrder(0, $order['id'], $first);
        $this->assertSame($life, $this->life(0));
        $this->assertSame(GameObject::HAND, $this->zone($harvest));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($goblin));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame([], $this->game->stack);
        $this->assertSame($expended + 3, $this->game->players[0]->expended);
        $done = $this->saved();
        $this->reject(fn () => $this->game->chooseDamageOrder(0, $order['id'], $first));
        foreach ([$queued, $optional, $pending] as $index => $snapshot) {
            $this->game = Game::fromArray($snapshot);
            if ($index === 0) {
                $this->resolveOne();
            }
            if ($index < 2) {
                $this->game->chooseRecover(0, $choice['id'], 'accept');
            }
            $this->game->chooseDamageOrder(0, $order['id'], $first);
            $this->assertSame($done, $this->saved());
        }
    }

    public static function ordering(): array
    {
        return [['double:1', 19], ['prevent', 20]];
    }

    /** @dataProvider payable */
    public function testDeclineExilesOriginalWithoutPayingAndRejectsUnpayableAcceptance(bool $payable): void
    {
        [$harvest, , , $mana] = $this->setupDeath(false);
        if (! $payable) {
            foreach ($mana as $id) {
                $this->game->objects[$id]->tapped = true;
            }
        }
        $this->resolveOne();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($payable, $this->game->canPayRecover(0));
        if (! $payable) {
            $this->reject(fn () => $this->game->chooseRecover(0, $choice['id'], 'accept'));
        }
        $before = $this->game->players[0]->expended;
        $this->game->chooseRecover(0, $choice['id'], 'decline');
        $this->assertSame(GameObject::EXILE, $this->zone($harvest));
        $this->assertSame($before, $this->game->players[0]->expended);
        $this->assertNull($this->game->choiceAwaiting());
        $this->reject(fn () => $this->game->chooseRecover(0, $choice['id'], 'decline'));
    }

    public static function payable(): array
    {
        return [[false], [true]];
    }

    public function testCounteringOriginalTriggerLeavesRecoverInGraveyardWithManaUntouched(): void
    {
        [$harvest, , , $mana] = $this->setupDeath(true);
        $this->resolveOne();
        $item = $this->game->stack[0];
        // Existing counter primitive only: this does not certify an ability-target counter card.
        (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke($this->game, ['type' => 'counter'], $this->game->objects[$harvest], null, 1, 0, 's:'.$item['id']);
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($harvest));
        foreach ($mana as $id) {
            $this->assertFalse($this->game->objects[$id]->tapped);
        }
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider identityTiming */
    public function testOriginalTriggerNeverMovesNewGraveyardIncarnation(string $when, string $option): void
    {
        [$harvest] = $this->setupDeath(true);
        $this->resolveOne();
        if ($when !== 'queued') {
            $this->resolveOne();
        }
        if ($when === 'paid') {
            $choice = $this->game->choiceAwaiting();
            $this->game->chooseRecover(0, $choice['id'], 'accept');
        }
        $source = $this->game->objects[$harvest];
        $old = $source->incarnation;
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        // Isolate zone identity with the engine movement primitive; no blink-card claim.
        $move->invoke($this->game, $source, GameObject::EXILE);
        $move->invoke($this->game, $source, GameObject::GRAVEYARD);
        $this->assertGreaterThan($old, $source->incarnation);
        $before = $source->incarnation;
        if ($when === 'queued') {
            $this->resolveOne();
        }
        $this->game = Game::fromArray($this->saved());
        $choice = $this->game->choiceAwaiting();
        if ($when === 'paid') {
            $this->game->chooseDamageOrder(0, $choice['id'], 'prevent');
        } else {
            $this->game->chooseRecover(0, $choice['id'], $option);
            if ($option === 'accept') {
                $order = $this->game->choiceAwaiting();
                $this->game->chooseDamageOrder(0, $order['id'], 'prevent');
            }
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($harvest));
        $this->assertSame($before, $this->game->objects[$harvest]->incarnation);
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function identityTiming(): array
    {
        return [['queued', 'accept'], ['queued', 'decline'], ['optional', 'accept'], ['optional', 'decline'], ['paid', 'accept']];
    }

    public function testMalformedOptionalRecoverStateAndOlderSchemaCannotInventChoice(): void
    {
        $this->setupDeath(true);
        $this->resolveOne();
        $this->resolveOne();
        $valid = $this->saved();
        foreach ([['seat', 1], ['cost', '{0}'], ['incarnation', 999], ['options', ['accept']], ['id', 0]] as [$key, $value]) {
            $bad = $valid;
            $bad['pendingChoice'][$key] = $value;
            try {
                Game::fromArray($bad);
                $this->fail('Malformed recover choice loaded.');
            } catch (\MTGPocket\Game\GameException) {
                $this->assertSame($valid, $this->saved());
            }
        }
        $bad = $valid;
        $bad['schemaVersion'] = 17;
        $this->expectException(\MTGPocket\Game\GameException::class);
        Game::fromArray($bad);
    }

    public function testSelectedGroupQueuesEachDeathFromPreexistingRecoverOnly(): void
    {
        $this->newGame();
        $harvest = $this->game->addCard(0, $this->cached('Grim Harvest'), GameObject::GRAVEYARD)->id;
        $doomed = [];
        for ($n = 0; $n < 2; $n++) {
            $this->lands(0, 'Mountain', 1);
            $id = $this->game->addCard(0, $this->cached('Raging Goblin'), GameObject::HAND)->id;
            $this->game->cast(0, $id);
            $this->resolveOne();
            $doomed[] = $this->game->objects[$id];
        }
        // Synthetic unit boundary for a recover creature: no card-support claim.
        // Complete Garza's Assassin uses a different, unsupported life cost, deliberately excluded.
        $newRecover = $this->game->addCard(0, ['name' => 'Recover creature boundary probe', 'type' => 'Creature - Wizard',
            'types' => ['Creature'], 'manaCost' => '{B}', 'power' => '1', 'toughness' => '1', 'text' => 'Recover {2}{B}'], GameObject::BATTLEFIELD);
        // Move the newly arriving Recover first to catch post-event graveyard rescans.
        array_unshift($doomed, $newRecover);
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        (new \ReflectionMethod(Game::class, 'withDeathObservers'))->invoke($this->game, $doomed, fn (GameObject $o) => $move->invoke($this->game, $o, GameObject::GRAVEYARD));
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(3, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame($harvest, $item['object']);
        }
        $this->lands(0, 'Swamp', 6);
        $before = $this->game->players[0]->expended;
        $this->resolveOne();
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'accept');
        $incarnation = $this->game->objects[$harvest]->incarnation;
        $this->resolveOne();
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame($before + 6, $this->game->players[0]->expended, 'A later independent trigger still offers payment after the original source left.');
        $this->assertSame($incarnation, $this->game->objects[$harvest]->incarnation, 'The old card returns only once.');
        $this->resolveOne();
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertSame(GameObject::HAND, $this->zone($harvest));
        $this->assertSame(GameObject::GRAVEYARD, $newRecover->zone);
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider deathOwners */
    public function testGraveyardOwnerControlsRecoverRatherThanDyingCreatureController(int $owner, bool $token, bool $replaced): void
    {
        $this->newGame();
        $harvests = [];
        foreach ([0, 1] as $seat) {
            $harvests[$seat] = $this->game->addCard($seat, $this->cached('Grim Harvest'), GameObject::GRAVEYARD)->id;
        }
        $card = $token ? ['token' => true, 'name' => 'Token boundary probe', 'type' => 'Token Creature - Goblin', 'types' => ['Creature'], 'power' => '1', 'toughness' => '1', 'text' => null] : $this->cached('Raging Goblin');
        $victim = $this->game->addCard($owner, $card, GameObject::BATTLEFIELD);
        $victim->controller = 1 - $owner;
        $victim->unearthed = $replaced;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $victim, GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($replaced ? 0 : 1, $this->game->stack);
        if (! $replaced) {
            $item = $this->game->stack[0];
            $this->assertSame([$owner, $harvests[$owner]], [$item['controller'], $item['object']]);
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame($owner, $choice['seat']);
            $this->game->chooseRecover($owner, $choice['id'], 'decline');
            $this->assertSame(GameObject::EXILE, $this->zone($harvests[$owner]));
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($harvests[1 - $owner]));
        $this->assertSame($token ? GameObject::GONE : ($replaced ? GameObject::EXILE : GameObject::GRAVEYARD), $victim->zone);
    }

    public static function deathOwners(): array
    {
        return [[0, false, false], [1, false, false], [0, true, false], [1, true, false], [0, false, true], [1, false, true]];
    }

    /** @dataProvider payable */
    public function testPracticePolicyFinishesOptionalRecover(bool $payable): void
    {
        [$harvest, , , $mana] = $this->setupDeath(false);
        if (! $payable) {
            foreach ($mana as $id) {
                $this->game->objects[$id]->tapped = true;
            }
        }
        $this->resolveOne();
        $this->resolveOne();
        $this->assertTrue(\MTGPocket\Tutorial\PracticeBot::act($this->game, 0));
        $this->assertSame($payable ? GameObject::HAND : GameObject::EXILE, $this->zone($harvest));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testUnchangedSchema17StateMigratesWithoutInventingRecoverChoice(): void
    {
        $this->setupDeath(true);
        $old = $this->saved();
        $old['schemaVersion'] = 17;
        $this->game = Game::fromArray($old);
        $this->assertSame(Game::STATE_VERSION, $this->saved()['schemaVersion']);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($old['damageEffects'], $this->saved()['damageEffects']);
        $this->resolveOne();
        $this->resolveOne();
        $this->assertSame('optional_recover', $this->game->choiceAwaiting()['type']);
    }

    public function testMalformedPaidRecoverCannotReplayOrSkipItsReturnInstruction(): void
    {
        $this->setupDeath(true);
        $this->resolveOne();
        $this->resolveOne();
        $this->game->chooseRecover(0, $this->game->choiceAwaiting()['id'], 'accept');
        $valid = $this->saved();
        foreach ([[], [[['type' => 'optional_recover'], null]], [[['type' => 'recover_return'], null], [['type' => 'recover_return'], null]]] as $steps) {
            $bad = $valid;
            $bad['pendingChoice']['resume']['steps'] = $steps;
            try {
                Game::fromArray($bad);
                $this->fail('Malformed paid Recover loaded.');
            } catch (\MTGPocket\Game\GameException) {
                $this->assertSame($valid, $this->saved());
            }
        }
    }
}
