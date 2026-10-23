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
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;

/** @covers \MTGPocket\Game\Game */
final class SelectorStateCompatibilityTest extends GameTestCase
{
    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    public function testNewSavesAlwaysRequireVersionFourEvenBeforeANewSelectorIsUsed(): void
    {
        $this->assertSame(4, $this->newGame()->toArray()['schemaVersion']);
    }

    public function testPendingUnionAbilityRetainsItsVersionAndSelectionAfterReload(): void
    {
        $game = $this->newGame();
        $device = $game->addCard(0, [
            'name' => 'Union Device', 'type' => 'Artifact',
            'text' => 'Sacrifice this artifact: Destroy all artifacts, creatures, and lands.',
        ], GameObject::BATTLEFIELD)->id;
        $land = $this->battlefield(1, 'Forest');
        $bear = $this->battlefield(0, 'Grizzly Bears');
        $enchantment = $game->addCard(1, ['name' => 'Enchantment Fixture', 'type' => 'Enchantment'], GameObject::BATTLEFIELD)->id;
        $walker = $this->battlefield(1, 'Chandra, Pyrogenius');
        $game->activate(0, $device, 0);
        // Compare the persisted JSON contract, including JSON's numeric normalization.
        $saved = json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(4, $saved['schemaVersion']);
        $this->assertSame('all artifacts, creatures, and lands', $saved['stack'][0]['effects'][0]['all']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($device));
        $this->game = Game::fromArray($saved);
        $this->assertSame($saved, $this->game->toArray());
        $this->resolve();
        foreach ([$land, $bear] as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        foreach ([$enchantment, $walker] as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
    }

    public function testVersionThreePendingAbilityAndDelayedQueueMigrateWithoutChangingTheirMeaning(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $draw = $game->addCard(0, [
            'name' => 'Delayed Draw Fixture', 'type' => 'Instant', 'manaCost' => '{U}',
            'text' => "Draw a card at the beginning of the next turn's upkeep.",
        ], GameObject::HAND)->id;
        $game->cast(0, $draw);
        $this->resolve();
        $pyromancer = $this->battlefield(0, 'Prodigal Pyromancer');
        $game->objects[$pyromancer]->sick = false;
        $game->activate(0, $pyromancer, 0, ['p:1']);
        $expected = $game->toArray();
        $legacy = $expected;
        $legacy['schemaVersion'] = 3;
        $this->assertCount(1, $legacy['stack']);
        $this->assertCount(1, $legacy['delayedDraws']);
        $this->game = Game::fromArray($legacy);
        $this->assertSame($expected, $this->game->toArray(), 'Only the schema label changes when loading an existing version-3 contract.');
        $this->resolve();
        $this->assertSame(19, $this->life(1));
        $this->assertCount(1, $this->game->delayedDraws);
    }

    public function testBothVersionThreeAndVersionFourRequireExistingDelayedDrawState(): void
    {
        $saved = $this->newGame()->toArray();
        unset($saved['delayedDraws']);
        foreach ([3, 4] as $version) {
            $saved['schemaVersion'] = $version;
            try {
                Game::fromArray($saved);
                $this->fail('Existing delayed-draw state cannot silently disappear.');
            } catch (GameException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testFutureVersionIsRejectedBeforeAnyGamePayloadIsRead(): void
    {
        $this->expectException(GameException::class);
        $this->expectExceptionMessage('Unsupported saved game schema version.');
        Game::fromArray(['schemaVersion' => Game::STATE_VERSION + 1]);
    }
}
