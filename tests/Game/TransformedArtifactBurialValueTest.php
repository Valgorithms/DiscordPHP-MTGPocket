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
final class TransformedArtifactBurialValueTest extends GameTestCase
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

    private function fixture(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/transformed-artifact-mana-value.json'));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];

        return array_values(array_filter($entries, fn (array $entry) => str_starts_with($entry['card']['name'], $name.' //')))[0]['card'];
    }

    private function source(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        $card = array_values(array_filter($entries, fn (array $entry) => $entry['card']['uuid'] === '6e88e450-3e9c-575e-96b2-6b0ac6a9434d'))[0]['card'];
        $this->game->active = $this->game->priority = $seat;
        $this->lands($seat, 'Mountain', 5);
        $source = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $source);
        $this->resolveOne();

        return $source;
    }

    /** @dataProvider controllers */
    public function testPaidCraftAndSmeltUseFrontManaValueOfDepartingTransformedArtifact(int $actor, int $observer, int $owner): void
    {
        $this->newGame();
        $source = $this->source($observer);
        $card = $this->fixture('Clay-Fired Bricks');
        $this->assertSame('32480bfd-5be9-5eb9-989d-6650eb53c16e', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $actor;
        // Seed the front face without its search ETB; transformation itself uses paid craft.
        $artifact = $this->game->addCard($owner, $card, GameObject::BATTLEFIELD)->id;
        $this->game->objects[$artifact]->controller = $actor;
        $this->battlefield($actor, 'Bonesplitter');
        $this->lands($actor, 'Plains', 7);
        $this->game->activate($actor, $artifact, 0);
        while ($this->game->stack !== []) {
            $this->resolveOne();
        }
        $this->assertTrue($this->game->objects[$artifact]->transformed);
        $this->assertSame('Cosmium Kiln', $this->game->objects[$artifact]->name());
        $this->assertSame(0, $this->game->objects[$artifact]->definition()->cost->manaValue(), 'The back face has no printed mana cost.');
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $smelt = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->lands($actor, 'Mountain', 1);
        $spell = $this->game->addCard($actor, $smelt, GameObject::HAND)->id;
        $this->game->cast($actor, $spell, ['o:'.$artifact]);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->game->objects[$artifact]->zone);
        $this->assertFalse($this->game->objects[$artifact]->transformed);
        $this->assertCount(1, $this->game->stack);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->objects[$artifact]->zone === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
    }

    public static function controllers(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([0, 1] as $observer) {
                foreach ([0, 1] as $owner) {
                    $cases[] = [$actor, $observer, $owner];
                }
            }
        }

        return $cases;
    }
}
