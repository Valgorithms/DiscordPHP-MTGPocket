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
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** @covers \MTGPocket\Game\Game @covers \MTGPocket\Game\TextParser */
final class ControllerDamageTest extends GameTestCase
{
    private const array MORE = [
        'Serendib Efreet' => ['type' => 'Creature - Djinn', 'manaCost' => '{2}{U}', 'colors' => ['U'], 'power' => '3', 'toughness' => '4', 'text' => "Flying\nAt the beginning of your upkeep, this creature deals 1 damage to you."],
        'Ravenous Giant' => ['type' => 'Creature - Giant', 'manaCost' => '{2}{R}{R}', 'colors' => ['R'], 'power' => '5', 'toughness' => '5', 'text' => 'At the beginning of your upkeep, this creature deals 1 damage to you.'],
        'Smoldering Efreet' => ['type' => 'Creature - Efreet Monk', 'manaCost' => '{1}{R}', 'colors' => ['R'], 'power' => '2', 'toughness' => '2', 'text' => 'When this creature dies, it deals 2 damage to you.'],
        'Lava Hounds' => ['type' => 'Creature - Dog', 'manaCost' => '{2}{R}{R}', 'colors' => ['R'], 'power' => '4', 'toughness' => '4', 'text' => "Haste\nWhen this creature enters, it deals 4 damage to you."],
        'City of Brass' => ['type' => 'Land', 'manaCost' => null, 'colors' => [], 'text' => "Whenever this land becomes tapped, it deals 1 damage to you.\n{T}: Add one mana of any color."],
        'Jackal Pup' => ['type' => 'Creature - Jackal', 'manaCost' => '{R}', 'colors' => ['R'], 'power' => '2', 'toughness' => '1', 'text' => 'Whenever this creature is dealt damage, it deals that much damage to you.'],
        'Dark Bargain' => ['type' => 'Instant', 'manaCost' => '{3}{B}', 'colors' => ['B'], 'text' => 'Look at the top three cards of your library. Put two of them into your hand and the other into your graveyard. Dark Bargain deals 2 damage to you.'],
        'Black Market Tycoon' => ['type' => 'Creature - Cat Rogue', 'manaCost' => '{R}{G}', 'colors' => ['R', 'G'], 'power' => '3', 'toughness' => '2', 'text' => "At the beginning of your upkeep, this creature deals 2 damage to you for each Treasure you control.\n{T}: Create a Treasure token."],
        'Leyline of Sanctity' => ['type' => 'Enchantment', 'manaCost' => '{2}{W}{W}', 'colors' => ['W'], 'text' => 'You have hexproof.'],
        'Healing Salve' => ['type' => 'Instant', 'manaCost' => '{W}', 'colors' => ['W'], 'text' => 'Prevent the next 3 damage that would be dealt to any target this turn.'],
        'Controller Burn' => ['type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'Controller Burn deals 2 damage to you.'],
        'Targeted Burn' => ['type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'Targeted Burn deals 2 damage to target creature. Targeted Burn deals 1 damage to you.'],
        'Controller Pinger' => ['type' => 'Creature - Shaman', 'manaCost' => '{R}', 'colors' => ['R'], 'power' => '1', 'toughness' => '2', 'text' => '{R}: Controller Pinger deals 2 damage to you.'],
        'Infect Fixture' => ['type' => 'Creature - Horror', 'manaCost' => '{B}', 'colors' => ['B'], 'power' => '1', 'toughness' => '2', 'text' => "Infect\nAt the beginning of your upkeep, Infect Fixture deals 2 damage to you."],
        'Lifelink Fixture' => ['type' => 'Creature - Cleric', 'manaCost' => '{W}', 'colors' => ['W'], 'power' => '1', 'toughness' => '2', 'text' => "Lifelink\nAt the beginning of your upkeep, Lifelink Fixture deals 2 damage to you."],
    ];

    private static function more(string $name): array
    {
        return ['uuid' => 'controller-damage-'.$name, 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private function put(int $seat, string $name, string $zone = GameObject::HAND): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function reload(): Game
    {
        return $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    private function rejected(callable $action): void
    {
        $before = $this->game->toArray();
        try {
            $action();
            $this->fail('Expected an invalid action.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    public function testRealWordingAndFixedRecipientHaveNoTarget(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $this->assertSame([], (new CardDefinition(self::more($name)))->unsupported, $name);
        }
        $effects = (new CardDefinition(self::more('Serendib Efreet')))->triggered[0]['effects'];
        $this->assertSame([['type' => 'damage', 'amount' => 1, 'you' => true]], $effects);
    }

    public function testUpkeepWaitsForResolutionAndUsesOriginalControllerAfterSave(): void
    {
        $game = $this->newGame();
        $id = $this->put(0, 'Serendib Efreet', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame([], $game->stack, 'Opponent upkeep does not trigger.');
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(1, $game->stack);
        $this->assertSame(20, $this->life(0));
        $game->objects[$id]->controller = 1; // A control-changing response has resolved.
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(19, $this->life(0));
        $this->assertSame(20, $this->life(1));
        $this->passUntil(Step::Upkeep, 4);
        $this->assertCount(1, $game->stack, 'Future upkeep belongs to the new controller.');
        $this->resolve();
        $this->assertSame(19, $this->life(1));
    }

    public function testRemovingSourceDoesNotCancelSavedTrigger(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 3);
        $id = $this->put(0, 'Serendib Efreet', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 3);
        $game->cast(0, $this->hand(0, 'Unsummon'), 0, ["o:{$id}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($id));
        $this->reload();
        $this->resolve();
        $this->assertSame(19, $this->life(0));
        $this->assertSame([], $this->game->stack);
    }

    public function testEnterTriggerSurvivesRestartAndPlayerHexproof(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Leyline of Sanctity', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 4);
        $game->cast(0, $this->put(0, 'Lava Hounds'));
        $this->resolve();
        $this->assertSame(20, $this->life(0));
        $this->assertCount(1, $game->stack);
        $this->reload();
        $this->resolve();
        $this->assertSame(16, $this->life(0));
    }

    public function testDamageUsesPreventionRatherThanLifeLoss(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 1);
        $this->put(0, 'Ravenous Giant', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 3);
        $game->cast(0, $this->put(0, 'Healing Salve'), 0, ['p:0']);
        $this->resolve();
        $this->reload();
        $this->resolve();
        $this->assertSame(20, $this->life(0));
        $this->assertSame(2, $this->game->prevent['p:0']);
    }

    public function testDamagePreservesInfectAndLifelink(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Infect Fixture', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 3);
        $this->resolve();
        $this->assertSame(20, $this->life(0));
        $this->assertSame(2, $game->players[0]->poison);
        $this->newGame();
        $this->put(0, 'Lifelink Fixture', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 3);
        $this->reload();
        $this->resolve();
        $this->assertSame(20, $this->life(0));
        $this->assertSame(2, $this->game->players[0]->gained);
    }

    public function testDeathTriggerUsesControllerAtDeathAfterSourceChangesZone(): void
    {
        $game = $this->newGame();
        $id = $this->put(1, 'Smoldering Efreet', GameObject::BATTLEFIELD);
        $game->objects[$id]->controller = 0;
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->hand(0, 'Murder'), 0, ["o:{$id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        $this->assertCount(1, $game->stack);
        $this->reload();
        $this->resolve();
        $this->assertSame(18, $this->life(0));
        $this->assertSame(20, $this->life(1));
    }

    public function testSpellAndActivationRejectPaymentPlayerAndExtraTargetsWithoutMutation(): void
    {
        $game = $this->newGame();
        $spell = $this->put(0, 'Controller Burn');
        $this->rejected(fn () => $game->cast(0, $spell));
        $this->lands(0, 'Mountain', 2);
        $this->rejected(fn () => $game->cast(1, $spell));
        $this->rejected(fn () => $game->cast(0, $spell, 0, ['p:1']));
        $game->cast(0, $spell);
        $this->reload();
        $this->resolve();
        $this->assertSame(18, $this->life(0));
        $game = $this->game;
        $pinger = $this->put(0, 'Controller Pinger', GameObject::BATTLEFIELD);
        $this->rejected(fn () => $game->activate(0, $pinger, 0, ['p:1']));
        $game->activate(0, $pinger, 0);
        $this->reload();
        $this->resolve();
        $this->assertSame(16, $this->life(0));
        $this->assertSame(20, $this->life(1));
    }

    public function testDealtDamageAmountAndCityManaTriggerRemainNormalTriggers(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $pup = $this->put(0, 'Jackal Pup', GameObject::BATTLEFIELD);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ["o:{$pup}"]);
        $this->resolve();
        $this->reload();
        $this->resolve();
        $this->assertSame(17, $this->life(0));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($pup));
        $this->newGame();
        $city = $this->put(0, 'City of Brass', GameObject::BATTLEFIELD);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['p:1']);
        $this->assertTrue($this->game->objects[$city]->tapped);
        $this->assertSame(20, $this->life(0));
        $this->assertCount(2, $this->game->stack);
        $this->reload();
        $this->resolve();
        $this->assertSame(19, $this->life(0));
        $this->assertSame(20, $this->life(1));
        $this->resolve();
        $this->assertSame(18, $this->life(1));
    }

    public function testUnimplementedVariantsStayVisible(): void
    {
        foreach ([
            'At the beginning of your upkeep, this creature deals 2 damage to you unless you pay {B}{B}.',
            'At the beginning of your upkeep, if your opponents control no creatures, this creature deals 4 damage to you.',
            'This creature deals X damage to you.',
            'This creature deals damage to you equal to the number of cards in your hand.',
            'This creature deals 1 damage to you and each creature you control.',
        ] as $text) {
            $this->assertNotSame([], (new CardDefinition(['name' => 'Unknown Fixture', 'type' => 'Creature', 'text' => $text]))->unsupported, $text);
        }
    }

    public function testCounteredAndEntirelyIllegalTargetedSpellsDoNotDealControllerDamage(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Island', 2);
        $burn = $this->put(0, 'Controller Burn');
        $game->cast(0, $burn);
        $game->pass(0);
        $game->cast(1, $this->hand(1, 'Counterspell'), 0, ['s:'.$game->stack[0]['id']]);
        $this->reload();
        $this->resolve();
        $this->assertSame(20, $this->life(0));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($burn));

        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Island', 1);
        $creature = $this->battlefield(1, 'Grizzly Bears');
        $game->cast(0, $this->put(0, 'Targeted Burn'), 0, ["o:{$creature}"]);
        $game->pass(0);
        $game->cast(1, $this->hand(1, 'Unsummon'), 0, ["o:{$creature}"]);
        $this->resolve();
        $this->reload();
        $this->resolve();
        $this->assertSame(20, $this->life(0));
        $this->assertSame(20, $this->life(1));
        $this->assertSame(GameObject::HAND, $this->zone($creature));
    }

    public function testSameSavedSpellProducesTheSameDamageAndState(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->put(0, 'Controller Burn'));
        $saved = $game->toArray();
        $this->resolve();
        $expected = $game->toArray();
        $this->game = Game::fromArray($saved);
        $this->resolve();
        $this->assertSame($expected, $this->game->toArray());
    }

    public function testDarkBargainResumesDamageOnlyAfterTheSavedSelection(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $spell = $this->put(0, 'Dark Bargain');
        $game->cast(0, $spell);
        $this->resolve();
        $this->assertSame('look', $game->decision(0));
        $this->assertSame(20, $this->life(0));
        $game = $this->reload();
        $cards = $game->choiceAwaiting()['cards'];
        $this->rejected(fn () => $game->take(1, array_slice($cards, 0, 2)));
        $this->rejected(fn () => $game->take(0, [$cards[0]]));
        $game->take(0, array_slice($cards, 0, 2));
        $this->assertSame(18, $this->life(0));
        $this->assertSame(GameObject::HAND, $this->zone($cards[0]));
        $this->assertSame(GameObject::HAND, $this->zone($cards[1]));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($cards[2]));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertNull($game->choiceAwaiting());
        $this->reload();
        $this->assertSame(18, $this->life(0), 'Resuming the completed selection does not repeat the damage.');
    }

    public function testExistingPerTreasureCompositionCountsAtResolution(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Black Market Tycoon', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 3);
        $this->assertCount(1, $game->stack);
        foreach ([0, 0, 1] as $seat) {
            $game->addCard($seat, ['name' => 'Treasure', 'type' => 'Artifact - Treasure'], GameObject::BATTLEFIELD);
        }
        $this->reload();
        $this->resolve();
        $this->assertSame(16, $this->life(0));
        $this->assertSame(20, $this->life(1));
    }
}
