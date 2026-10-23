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
use MTGPocket\Game\Step;
use MTGPocket\Builders\MatchMessageBuilder;

/** @covers \MTGPocket\Game\Game */
final class IchTekikBurialCountersTest extends GameTestCase
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
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Ich-Tekik, Salvage Splicer'))[0];
        $this->assertSame('CMR', $entry['setCode']);
        $this->assertSame('9767c0a3-2584-5383-8eeb-6873f4f20ba6', $entry['card']['uuid']);

        return $entry['card'];
    }

    private function splicer(int $seat): array
    {
        $card = $this->selected();
        $definition = new CardDefinition($card);
        $this->assertSame(['Partner'], $definition->unsupported);
        $this->assertCount(1, $definition->triggersOn('artifact_to_graveyard_any'));
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame([], $this->game->stack[0]['targets']);
        $this->resolveOne();
        $golems = array_values(array_filter($this->game->objects, fn (GameObject $o) => $o->zone === GameObject::BATTLEFIELD && $o->controller === $seat && in_array('Golem', $o->definition()->subtypes, true)));
        $this->assertCount(1, $golems);
        $token = $golems[0];
        $this->assertTrue($token->token);
        $this->assertSame($seat, $token->owner);
        $this->assertSame([], $token->definition()->colors);
        $this->assertTrue($token->definition()->isArtifact());
        $this->assertContains('Phyrexian', $token->definition()->subtypes);
        $this->assertSame(3, $this->game->power($token));
        $this->assertSame(3, $this->game->toughness($token));

        return [$id, $token->id];
    }

    private function smelt(int $seat, int $target): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 1);
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$lands[0]]->tapped);
    }

    /** @dataProvider controllers */
    public function testPaidSplicerEntryAndSmeltGrowOriginalControllersGolemsWithJsonReplay(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        [$source, $token] = $this->splicer(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->game->objects[$token]->controller = $controller;
        $opposing = $this->game->addCard(1 - $controller, ['name' => 'Opposing Golem Unit Fixture', 'type' => 'Artifact Creature — Golem', 'power' => '3', 'toughness' => '3', 'text' => null], GameObject::BATTLEFIELD)->id;
        $nongolem = $this->battlefield($controller, 'Grizzly Bears');
        $artifact = $this->battlefield(1 - $artifactController, 'Ornithopter');
        $this->game->objects[$artifact]->controller = $artifactController;
        $this->smelt($active, $artifact);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame($controller, $this->game->stack[0]['controller']);
        $this->assertSame([], $this->game->stack[0]['targets']);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame([1, 1, 0, 0], array_map(fn (int $id) => $this->game->objects[$id]->counter('+1/+1'), [$source, $token, $opposing, $nongolem]));
        $this->assertSame(2, $this->game->power($this->game->objects[$source]));
        $this->assertSame(4, $this->game->power($this->game->objects[$token]));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($artifact) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->resolveOne();
            $this->assertSame($expected, $this->saved());
        }
        $this->passUntil(Step::Untap);
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(1, $this->game->objects[$token]->counter('+1/+1'));
    }

    public static function controllers(): array
    {
        return SummoningStationChoiceTest::controllers();
    }
}
