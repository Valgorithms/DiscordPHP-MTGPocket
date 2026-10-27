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
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;

/** @covers \MTGPocket\Game\Game */
final class StackEntryCounterTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);
    }

    private function directCounter(int $stackId, bool $exile = false): ?array
    {
        return (new \ReflectionMethod(Game::class, 'counterStackItem'))->invoke($this->game, $stackId, $exile);
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function counter(int $stackId, bool $exile = false): void
    {
        // Existing engine primitive only; no Stifle/card parsing certificate.
        $source = $this->game->objects[$this->battlefield(0, 'Grizzly Bears')];
        (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke(
            $this->game,
            ['type' => 'counter', 'exileCountered' => $exile],
            $source,
            null,
            0,
            0,
            's:'.$stackId
        );
    }

    private function activated(): int
    {
        $this->newGame();
        $id = $this->battlefield(0, 'Prodigal Pyromancer');
        $this->game->activate(0, $id, 0, ['p:1']);
        $this->game = Game::fromArray($this->saved());

        return $id;
    }

    /** @dataProvider exileOptions */
    public function testCounteringAnActivatedAbilityDoesNotMoveItsSource(bool $exile): void
    {
        $id = $this->activated();
        $before = $this->game->objects[$id]->toArray();
        $this->counter($this->game->stack[0]['id'], $exile);
        $this->assertSame($before, $this->game->objects[$id]->toArray(), 'Only the ability entry is removed.');
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider exileOptions */
    public function testCounteringASpellCopyDoesNotMoveTheOriginalSpell(bool $exile): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $id = $this->hand(0, 'Shock');
        $this->game->cast(0, $id, 0, ['p:1']);
        $original = $this->game->stack[0];
        (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $original, 0, 'counter foundation probe');
        $this->assertCount(2, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $this->counter($this->game->stack[1]['id'], $exile);
        $this->assertSame(GameObject::STACK, $this->zone($id), 'The original spell must stay on the stack.');
        $this->assertSame([$original], $this->game->stack);
        $this->resolveOne();
        $this->assertSame(18, $this->life(1), 'Only the original Shock resolves.');
    }

    /** @dataProvider exileOptions */
    public function testCounteringACardSpellMovesOnlyItsExactObject(bool $exile): void
    {
        $this->newGame();
        $paid = $this->lands(0, 'Mountain', 1)[0];
        $id = $this->hand(0, 'Shock');
        $this->game->cast(0, $id, 0, ['p:1']);
        $item = $this->game->stack[0];
        $this->assertSame($item, $this->directCounter($item['id'], $exile));
        $this->assertSame($exile ? GameObject::EXILE : GameObject::GRAVEYARD, $this->zone($id));
        $this->assertTrue($this->game->objects[$paid]->tapped, 'Casting costs stay paid.');
        $this->assertSame([], $this->game->stack);
        $before = $this->saved();
        $this->assertNull($this->directCounter($item['id'], $exile));
        $this->assertSame($before, $this->saved(), 'Replaying the counter is a no-op.');
    }

    /** @dataProvider departureOptions */
    public function testCounteringATriggerNeverMovesItsCurrentOrDepartedSource(bool $departed, bool $exile): void
    {
        $this->newGame();
        $paid = $this->lands(0, 'Forest', 2);
        $id = $this->hand(0, 'Elvish Visionary');
        $this->game->cast(0, $id);
        $this->resolveOne();
        $this->assertCount(1, $this->game->stack);
        $item = $this->game->stack[0];
        $this->assertTrue(Game::isAbility($item));
        if ($departed) {
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::HAND);
            $this->assertNotSame($item['incarnation'], $this->game->objects[$id]->incarnation);
        }
        $this->game = Game::fromArray($this->saved());
        $before = $this->game->objects[$id]->toArray();
        $cards = count($this->game->players[0]->hand);
        $this->assertSame($item, $this->directCounter($item['id'], $exile));
        $this->assertSame($before, $this->game->objects[$id]->toArray());
        $this->assertSame($cards, count($this->game->players[0]->hand), 'The draw trigger never resolves.');
        foreach ($paid as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
    }

    public function testCounteringASacrificedSourceActivationKeepsItsGraveyardIdentity(): void
    {
        $this->newGame();
        $id = $this->battlefield(0, 'Bottle Gnomes');
        $this->game->activate(0, $id, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $before = $this->game->objects[$id]->toArray();
        $this->assertNotNull($this->directCounter($this->game->stack[0]['id'], true));
        $this->assertSame($before, $this->game->objects[$id]->toArray());
        $this->assertSame(20, $this->life(0));
    }

    public function testCounteringACopyAfterTheOriginalWasCounteredNeverMovesItAgain(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $id = $this->hand(0, 'Shock');
        $this->game->cast(0, $id, 0, ['p:1']);
        $original = $this->game->stack[0];
        (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $original, 0, 'counter foundation probe');
        $copy = $this->game->stack[1];
        $this->assertNotNull($this->directCounter($original['id']));
        $before = $this->game->objects[$id]->toArray();
        $this->game = Game::fromArray($this->saved());
        $this->assertSame($copy, $this->directCounter($copy['id'], true));
        $this->assertSame($before, $this->game->objects[$id]->toArray());
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider exileOptions */
    public function testUncounterableSpellAndItsCopyRemainOnTheStack(bool $copy): void
    {
        $this->newGame();
        $card = ['name' => 'Uncounterable Bolt Unit Fixture', 'type' => 'Instant', 'manaCost' => '{R}',
            'text' => "This spell can't be countered.\nUncounterable Bolt Unit Fixture deals 2 damage to any target."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->lands(0, 'Mountain', 1);
        $id = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $this->game->cast(0, $id, 0, ['p:1']);
        $item = $this->game->stack[0];
        if ($copy) {
            (new \ReflectionMethod(Game::class, 'copySpell'))->invoke($this->game, $item, 0, 'counter foundation probe');
            $item = $this->game->stack[1];
            $this->assertTrue($item['uncounterable']);
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        $this->assertNull($this->directCounter($item['id'], true));
        $this->assertSame($before, $this->saved());
    }

    public function testAnUncounterablePermanentDoesNotMakeItsAbilityUncounterable(): void
    {
        $this->newGame();
        $card = ['name' => 'Uncounterable Pinger Unit Fixture', 'type' => 'Creature', 'manaCost' => '{R}', 'power' => '1', 'toughness' => '1',
            'text' => "This spell can't be countered.\n{T}: This creature deals 1 damage to any target."];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $this->game->activate(0, $id, 0, ['p:1']);
        $before = $this->game->objects[$id]->toArray();
        $this->assertNotNull($this->directCounter($this->game->stack[0]['id']));
        $this->assertSame($before, $this->game->objects[$id]->toArray());
    }

    public function testMissingAndStaleSpellEntriesCannotMoveANewerIncarnation(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $id = $this->hand(0, 'Shock');
        $this->game->cast(0, $id, 0, ['p:1']);
        $old = $this->game->stack[0];
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], GameObject::HAND);
        $this->game->cast(0, $id, 0, ['p:1']);
        $new = $this->game->stack[0];
        // Movement normally removes the old entry. Reinsert it only to probe stale identity handling.
        array_unshift($this->game->stack, $old);
        $this->assertNotSame($old['incarnation'], $new['incarnation']);
        $before = $this->saved();
        $this->assertNull($this->directCounter(999999));
        $this->assertNull($this->directCounter($old['id'], true));
        $this->assertSame($before, $this->saved());
        $this->assertSame($new, $this->directCounter($new['id']));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
    }

    /** @dataProvider exileOptions */
    public function testExistingUnlessPaymentPolicyIsUnchanged(bool $canPay): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $id = $this->hand(0, 'Shock');
        $this->game->cast(0, $id, 0, ['p:1']);
        $extra = $canPay ? $this->lands(0, 'Forest', 1)[0] : null;
        $source = $this->game->objects[$this->battlefield(1, 'Grizzly Bears')];
        (new \ReflectionMethod(Game::class, 'applyEffect'))->invoke(
            $this->game,
            ['type' => 'counter', 'unless' => '{1}'],
            $source,
            null,
            1,
            0,
            's:'.$this->game->stack[0]['id']
        );
        $this->assertSame($canPay ? GameObject::STACK : GameObject::GRAVEYARD, $this->zone($id));
        $this->assertCount($canPay ? 1 : 0, $this->game->stack);
        if ($canPay) {
            $this->assertTrue($this->game->objects[$extra]->tapped);
        }
    }

    public function testCounteredFlashbackStillUsesTheExistingExileRoute(): void
    {
        $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $card = ['name' => 'Flashback Bolt Unit Fixture', 'type' => 'Instant', 'manaCost' => '{R}',
            'text' => "Flashback Bolt Unit Fixture deals 2 damage to any target.\nFlashback {R}"];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $id = $this->game->addCard(0, $card, GameObject::GRAVEYARD)->id;
        $this->game->cast(0, $id, 0, ['p:1'], 'fb');
        $this->assertNotNull($this->directCounter($this->game->stack[0]['id']));
        $this->assertSame(GameObject::EXILE, $this->zone($id));
    }

    public function testCopyCounterCharacteristicRejectsMalformedSavedValues(): void
    {
        $id = $this->activated();
        foreach ([1, 'yes', []] as $bad) {
            $saved = $this->saved();
            $saved['stack'][0]['uncounterable'] = $bad;
            try {
                Game::fromArray($saved);
                $this->fail('Malformed stack characteristic accepted.');
            } catch (GameException $e) {
                $this->assertStringContainsString('counter characteristic', $e->getMessage());
            }
        }
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
    }

    public static function exileOptions(): array
    {
        return [[false], [true]];
    }

    public static function departureOptions(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }
}
