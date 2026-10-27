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

/** Frozen gameplay target identity only; synthetic enabling fixtures are not card certificates. @covers \MTGPocket\Game\Game */
final class FrozenTargetPinsTest extends GameTestCase
{
    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function priority(int $seat): void
    {
        if ($this->game->priority !== $seat) {
            $this->game->pass($this->game->priority);
        }
    }

    private function fixture(int $seat, array $card, string $zone = GameObject::BATTLEFIELD): int
    {
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

    private function cantor(int $seat): int
    {
        // Existing ModernCardTextTest mana-cost fixture, not a printed-card claim.
        return $this->fixture($seat, ['name' => 'Cantor Lite', 'manaCost' => '{R/G}', 'type' => 'Creature - Human Druid',
            'power' => '1', 'toughness' => '1', 'text' => 'Sacrifice CARDNAME: Add one mana of any color.', 'colors' => ['R', 'G']]);
    }

    /** @dataProvider pinInputs */
    public function testTargetSacrificedAsCostKeepsItsChosenIncarnation(bool $pinnedInput): void
    {
        $this->newGame();
        $victim = $this->battlefield(0, 'Ornithopter');
        $old = $this->game->objects[$victim]->incarnation;
        $card = ['name' => 'Sacrifice Pump Unit Fixture', 'type' => 'Enchantment',
            'text' => 'Sacrifice a creature: Target creature gets +1/+1 until end of turn.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$victim.($pinnedInput ? ':'.$old : '')]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        $this->assertNotSame($old, $this->game->objects[$victim]->incarnation);
        $this->assertSame(["o:{$victim}:{$old}"], $receipt['targets'], 'Cost payment cannot replace the chosen identity.');
    }

    /** @dataProvider seats */
    public function testPaidCopyAfterPaidBlinkDoesNotPumpTheReturnedPermanent(int $seat): void
    {
        $this->newGame();
        $bear = $this->battlefield($seat, 'Grizzly Bears');
        $old = $this->game->objects[$bear]->incarnation;
        $this->lands($seat, 'Forest', 1);
        $this->lands($seat, 'Plains', 1);
        $this->lands($seat, 'Mountain', 2);
        $this->priority($seat);
        $growth = $this->game->cast($seat, $this->hand($seat, 'Giant Growth'), 0, ['o:'.$bear]);
        // Reuse the existing ModernCardTextTest unit fixtures, without enabling-card certification.
        $blink = ['name' => 'Flicker Lite', 'manaCost' => '{W}', 'type' => 'Instant',
            'text' => "Exile target creature you control, then return that card to the battlefield under its owner's control.", 'colors' => ['W']];
        $this->assertSame([], (new CardDefinition($blink))->unsupported);
        $this->game->cast($seat, $this->game->addCard($seat, $blink, GameObject::HAND)->id, 0, ['o:'.$bear]);
        $this->resolveOne();
        $this->assertNotSame($old, $this->game->objects[$bear]->incarnation);
        $copyCard = ['name' => 'Reverberate', 'manaCost' => '{R}{R}', 'type' => 'Instant',
            'text' => 'Copy target instant or sorcery spell. You may choose new targets for the copy.', 'colors' => ['R']];
        $this->assertSame([], (new CardDefinition($copyCard))->unsupported);
        $this->priority($seat);
        $this->game->cast($seat, $this->game->addCard($seat, $copyCard, GameObject::HAND)->id, 0, ['s:'.$growth['id']]);
        $this->resolveOne();
        $this->assertCount(2, $this->game->stack);
        $copy = $this->game->stack[1];
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($copy, $this->game->stack[1], 'Save/load cannot revive the stale copied pin.');
        $this->resolveOne();
        $this->assertSame(2, $this->game->power($this->game->objects[$bear]), 'The copy cannot rebind to the returned 2/2.');
        $this->assertSame(["o:{$bear}:{$old}"], $copy['targets']);
        $this->resolveOne();
        $this->assertSame(2, $this->game->power($this->game->objects[$bear]));
    }

    public function testPaidReanimationCannotMakeACostRemovedTargetLegalAgain(): void
    {
        $this->newGame();
        $bear = $this->battlefield(0, 'Grizzly Bears');
        $old = $this->game->objects[$bear]->incarnation;
        $source = $this->fixture(0, ['name' => 'Sacrifice Pump Unit Fixture', 'type' => 'Enchantment',
            'text' => 'Sacrifice a creature: Target creature gets +1/+1 until end of turn.']);
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$bear]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bear));
        $this->lands(0, 'Swamp', 1);
        $return = $this->fixture(0, ['name' => 'Instant Reanimation Unit Fixture', 'type' => 'Instant', 'manaCost' => '{B}',
            'text' => 'Return target creature card from your graveyard to the battlefield.'], GameObject::HAND);
        $this->game->cast(0, $return, 0, ['o:'.$bear]);
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bear));
        $this->assertNotSame($old, $this->game->objects[$bear]->incarnation);
        $this->assertSame(["o:{$bear}:{$old}"], $receipt['targets']);
        $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($receipt['targets'], $this->game->stack[0]['targets']);
        $this->resolveOne();
        $this->assertSame(2, $this->game->power($this->game->objects[$bear]));
        $this->assertSame([], $this->game->stack);

        $before = $this->game->toArray();
        try {
            $this->game->activate(0, $source, 0, ["o:{$bear}:{$old}"]);
            $this->fail('A saved stale selection cannot be rebound to the returned creature.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
        }
    }

    public function testSourceSacrificeKeepsRepeatedChosenSlotsAndIndependentAbility(): void
    {
        $this->newGame();
        $bear = $this->battlefield(0, 'Grizzly Bears');
        $pin = 'o:'.$bear.':'.$this->game->objects[$bear]->incarnation;
        $source = $this->fixture(0, ['name' => 'Self Sacrifice Pump Unit Fixture', 'type' => 'Creature', 'power' => '1', 'toughness' => '1',
            'text' => '{1}, Sacrifice this creature: Target creature gets +1/+1 until end of turn. Target creature gets +2/+2 until end of turn.']);
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->lands(0, 'Forest', 1);
        $receipt = $this->game->activate(0, $source, 0, ['o:'.$bear, $pin]);
        $this->assertSame([$pin, $pin], $receipt['targets'], 'Separate clauses retain both slots; do not deduplicate gameplay targets.');
        $this->assertSame($incarnation, $receipt['incarnation']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        $this->resolveOne();
        $this->assertSame(5, $this->game->power($this->game->objects[$bear]));
    }

    /** @dataProvider paymentOutcomes */
    public function testTargetedTriggerFreezesBeforeLegacyWardManaSacrifice(bool $affordable): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $mana = $this->cantor(0);
        $old = $this->game->objects[$mana]->incarnation;
        $ward = $this->fixture(1, ['name' => 'Ward Target Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '2',
            'text' => $affordable ? 'Ward {1}' : 'Ward {2}']);
        $source = $this->fixture(0, ['name' => 'Two Target Entry Unit Fixture', 'type' => 'Creature', 'manaCost' => '{0}', 'power' => '1', 'toughness' => '1',
            'text' => 'When this creature enters, target creature gets +1/+1 until end of turn. Target creature gets +2/+2 until end of turn.'], GameObject::HAND);
        $this->game->cast(0, $source);
        $this->resolveOne();
        $this->assertSame('trigger', $this->game->decision(0));
        $receipt = $this->game->chooseTriggerTargets(0, ['o:'.$mana, 'o:'.$ward]);
        if (! $affordable) {
            $this->assertNull($receipt);
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($mana));
            $this->assertSame($old, $this->game->objects[$mana]->incarnation);
            $this->assertSame([], $this->game->stack);
            $this->assertSame([], $this->game->wardObservations());

            return;
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($mana));
        $this->assertSame("o:{$mana}:{$old}", $receipt['targets'][0]);
        $this->assertSame('observed', $this->game->wardObservations()[0]['status']);
        $this->assertSame($receipt['id'], $this->game->wardObservations()[0]['event']['offending']['id']);
        $this->resolveOne();
        $this->assertSame(4, $this->game->power($this->game->objects[$ward]), 'The other, still legal target resolves normally.');
    }

    public function testFailedActivationPaymentDoesNotSpendSacrificeOrPublishAnObservation(): void
    {
        $this->newGame();
        $this->game->setWardObservation(true);
        $mana = $this->cantor(0);
        $source = $this->fixture(0, ['name' => 'Unaffordable Pump Unit Fixture', 'type' => 'Enchantment',
            'text' => '{2}, Sacrifice a creature: Target creature gets +1/+1 until end of turn.']);
        $before = $this->game->toArray();
        try {
            $this->game->activate(0, $source, 0, ['o:'.$mana]);
            $this->fail('The combined costs are unaffordable.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
            $this->assertSame([], $this->game->wardObservations());
        }
    }

    /** @dataProvider paymentOutcomes */
    public function testBloodrushFreezesSelectedAttackerBeforeManaCosts(bool $sacrificeAttacker): void
    {
        $this->newGame();
        $attacker = $sacrificeAttacker ? $this->cantor(0) : $this->battlefield(0, 'Grizzly Bears');
        $rush = $this->fixture(0, ['name' => 'Bloodrush Lite', 'manaCost' => '{2}{R}', 'type' => 'Creature - Ogre Warrior', 'power' => '3', 'toughness' => '3',
            'text' => 'Bloodrush — {R}, Discard Bloodrush Lite: Target attacking creature gets +3/+3 until end of turn.', 'colors' => ['R']], GameObject::HAND);
        $this->passUntil(Step::DeclareAttackers, 3);
        $this->game->declareAttackers(0, [$attacker]);
        $old = $this->game->objects[$attacker]->incarnation;
        if (! $sacrificeAttacker) {
            $before = $this->game->toArray();
            try {
                $this->game->bloodrush(0, $rush);
                $this->fail('No mana can pay the Bloodrush cost yet.');
            } catch (GameException) {
                $this->assertSame($before, $this->game->toArray());
            }
            $this->lands(0, 'Mountain', 1);
        }
        $this->game->bloodrush(0, $rush);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($rush), 'The discarded source does not take the ability off the stack.');
        $this->assertSame(["o:{$attacker}:{$old}"], $this->game->stack[0]['targets']);
        if ($sacrificeAttacker) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($attacker));
            $this->assertNotSame($old, $this->game->objects[$attacker]->incarnation);
        }
        $this->resolveOne();
        $this->assertSame([], $this->game->stack);
        if (! $sacrificeAttacker) {
            $this->assertSame(5, $this->game->power($this->game->objects[$attacker]));
        }
    }

    public static function paymentOutcomes(): array
    {
        return [[false], [true]];
    }

    public static function pinInputs(): array
    {
        return [[false], [true]];
    }

    public static function seats(): array
    {
        return [[0], [1]];
    }
}
