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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class JovenArtifactControlTest extends GameTestCase
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

    private function selected(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entries = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'];
        $entry = array_values(array_filter($entries, fn (array $entry) => $entry['card']['uuid'] === '6e88e450-3e9c-575e-96b2-6b0ac6a9434d'))[0];
        $this->assertSame('MBC', $entry['setCode']);

        return $entry['card'];
    }

    private function source(int $seat, bool $ready = true): int
    {
        $card = $this->selected();
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertCount(1, $definition->activated);
        $this->assertSame(['control', 'untap', 'pump'], array_column($definition->activated[0]['effects'], 'type'));
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        if ($ready) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
            $this->assertSame($seat, $this->game->active);
            $this->assertFalse($this->game->objects[$id]->sick);
        }

        return $id;
    }

    /** @dataProvider subjects */
    public function testPaidActivationControlsUntapsAndGrantsTemporaryHasteWithJson(int $actor, int $controller, bool $creature): void
    {
        $this->newGame();
        $source = $this->source($actor);
        $target = $this->battlefield(1 - $controller, $creature ? 'Bottle Gnomes' : 'Bonesplitter');
        $this->game->objects[$target]->controller = $controller;
        $this->game->objects[$target]->tapped = true;
        $before = $this->saved();
        $this->game->activate($actor, $source, 0, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $tapped = array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->controller === $actor && $o->name() === 'Mountain' && $o->tapped);
        $this->assertCount(3, $tapped);
        $queued = $this->saved();
        $this->resolveOne();
        $object = $this->game->objects[$target];
        $this->assertSame($actor, $object->controller);
        $this->assertSame($controller === $actor ? null : $controller, $object->borrowedFrom);
        $this->assertFalse($object->tapped);
        $this->assertTrue($this->game->hasKeyword($object, 'haste'));
        $this->assertSame($creature, in_array($target, $this->game->attackCandidates(), true));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->stack === []) {
                $this->game->activate($actor, $source, 0, ['o:'.$target]);
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame($controller, $this->game->objects[$target]->controller);
        $this->assertNull($this->game->objects[$target]->borrowedFrom);
        $this->assertFalse($this->game->hasKeyword($this->game->objects[$target], 'haste'));
    }

    public static function subjects(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $creature) {
                    $cases[] = [$actor, $controller, $creature];
                }
            }
        }

        return $cases;
    }
}
