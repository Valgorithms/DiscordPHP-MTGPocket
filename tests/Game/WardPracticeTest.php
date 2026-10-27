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
use MTGPocket\Tutorial\PracticeBot;

/** Explicit policies for a privately enabled game; the bot never enables the mode. @covers \MTGPocket\Tutorial\PracticeBot */
final class WardPracticeTest extends GameTestCase
{
    private function fixture(int $seat, array $card, string $zone = GameObject::BATTLEFIELD): int
    {
        $this->assertSame([], (new CardDefinition($card))->unsupported);

        return $this->game->addCard($seat, $card, $zone)->id;
    }

    /** @dataProvider payable */
    public function testPracticeAcceptsAffordableFixedWardOtherwiseDeclines(bool $affordable): void
    {
        $this->newGame();
        $this->assertFalse($this->game->wardExecutionEnabled());
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, ['name' => 'Practice Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward {2}']);
        $this->lands(0, 'Mountain', $affordable ? 3 : 1);
        $spell = $this->hand(0, 'Shock');
        $this->game->cast(0, $spell, 0, ['o:'.$ward]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame('ward_payment', $this->game->decision(0));
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame($affordable ? 3 : 1, $this->game->players[0]->expended);
        $this->assertSame($affordable ? GameObject::STACK : GameObject::GRAVEYARD, $this->zone($spell));
    }

    public static function payable(): array
    {
        return [[false], [true]];
    }

    public function testPracticeOrdersEntireGroupByItsExplicitSimplePolicy(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->fixture(1, ['name' => 'Practice Group Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5',
            'text' => "Ward {1}\nWhenever this creature becomes the target of a spell or ability, you gain 1 life."]);
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $queue = $this->game->pendingTriggers;
        $this->assertSame('trigger_order', $this->game->decision(1));
        $this->assertTrue(PracticeBot::act($this->game, 1));
        $this->assertSame(array_column($queue, 'effects'), array_column(array_slice($this->game->stack, 1), 'effects'));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testAutomaticUnsupportedTargetIsHeldPersistentlyAndPracticeDoesNotSpinOrConcede(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $this->fixture(1, ['name' => 'Unsupported Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward—Discard a card.']);
        $source = $this->fixture(0, ['name' => 'Automatic Target Unit Fixture', 'type' => 'Enchantment', 'manaCost' => '{G}',
            'text' => 'When CARDNAME enters, target creature gets +1/+1 until end of turn.'], GameObject::HAND);
        $this->lands(0, 'Forest', 1);
        $this->game->cast(0, $source);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame(1, $this->game->players[0]->expended);
        $this->assertSame('ward_unsupported', $this->game->decision(0));
        $this->assertCount(1, $this->game->pendingTriggers);
        $this->assertSame([], $this->game->stack);
        $saved = json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->game = Game::fromArray($saved);
        $this->assertFalse(PracticeBot::act($this->game, 0));
        PracticeBot::play($this->game, 0);
        $this->assertSame($saved, $this->game->toArray());
        $this->assertSame(Game::PLAYING, $this->game->stage);
    }
}
