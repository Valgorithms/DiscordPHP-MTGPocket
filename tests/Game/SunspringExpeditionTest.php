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

/** @covers \MTGPocket\Game\Game */
final class SunspringExpeditionTest extends GameTestCase
{
    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function expedition(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/sunspring-expedition.json'));
        $this->assertSame('c4853ecb3ba472b60331e76b54df44cb7d00953d3f19782e2e2efae74291a392', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'][0];
        $this->assertSame('46769dfe-05a9-55d4-b43e-7b3e7f1bc789', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $mana = $this->lands($actor, 'Plains', 1);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function landfall(int $actor, string $answer = 'accept'): void
    {
        if ($this->game->active !== $actor) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        if ($this->game->players[$actor]->landsPlayed > 0) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 2);
        }
        $this->game->playLand($actor, $this->hand($actor, 'Plains'));
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], $answer);
    }

    private function charge(int $actor, int $source): void
    {
        for ($n = 1; $n <= 3; $n++) {
            $this->landfall($actor);
            $this->assertSame($n, $this->game->objects[$source]->counter('quest'));
        }
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid Sunspring action must reject.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider actors */
    public function testPaidActivationGainsEightAtResolutionAndReplays(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->game->activate($actor, $source, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertSame([20, 20], [$this->life($actor), $this->life(1 - $actor)], 'Costs are paid before life is gained.');
        $this->assertCount(1, $this->game->stack);
        $ability = $this->game->stack[0];
        $this->assertSame([$source, $incarnation, $actor, []], [$ability['object'], $ability['incarnation'], $ability['controller'], $ability['targets']]);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame([28, 20], [$this->life($actor), $this->life(1 - $actor)]);
        $this->assertNull($this->game->winner);
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
    }

    /** @dataProvider actors */
    public function testOpponentLandDeclineAndInsufficientCountersDoNotPayCosts(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->game->playLand(1 - $actor, $this->hand(1 - $actor, 'Plains'));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->landfall($actor);
        $this->landfall($actor);
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->landfall($actor, 'decline');
        $this->assertSame(2, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
    }

    /** @dataProvider actors */
    public function testReturnedSourceCannotRedirectThePaidLifeGain(int $actor): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0));
        $this->game->activate($actor, $source, 0);
        // Isolate source identity; no enabling blink/reanimation card claim.
        $object = $this->game->objects[$source];
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $object, 1 - $actor);
        $this->assertGreaterThan($incarnation, $object->incarnation);
        $this->game = Game::fromArray($this->saved());
        $ability = $this->game->stack[0];
        $this->assertSame([$source, $incarnation, $actor], [$ability['object'], $ability['incarnation'], $ability['controller']]);
        $this->resolveOne();
        $this->assertSame([28, 20], [$this->life($actor), $this->life(1 - $actor)]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->assertSame(1 - $actor, $this->game->objects[$source]->controller);
    }

    /** @dataProvider gainRuleCases */
    public function testPaidGainUsesExistingPreventionAndModification(int $actor, string $text, bool $opponentRule, int $life): void
    {
        $source = $this->expedition($actor);
        $this->charge($actor, $source);
        $this->game->activate($actor, $source, 0);
        // Synthetic rule fixture isolates shared life-gain behavior, not its enabling card.
        $rule = ['name' => 'Life Rule Unit Fixture', 'type' => 'Enchantment', 'text' => $text];
        $this->assertSame([], (new CardDefinition($rule))->unsupported);
        $this->game->addCard($opponentRule ? 1 - $actor : $actor, $rule, GameObject::BATTLEFIELD);
        $queued = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->assertSame([20, 20], [$this->life($actor), $this->life(1 - $actor)]);
        $this->resolveOne();
        $this->assertSame([$life, 20], [$this->life($actor), $this->life(1 - $actor)]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'), 'Prevented life gain does not refund activation costs.');
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
    }

    public static function gainRuleCases(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([
                ["Players can't gain life.", true, 20],
                ["Your opponents can't gain life.", true, 20],
                ["Your opponents can't gain life.", false, 28],
                ['If you would gain life, you gain that much life plus 1 instead.', false, 29],
                ['If you would gain life, you gain that much life plus 1 instead.', true, 28],
            ] as [$text, $opponentRule, $life]) {
                $cases[] = [$actor, $text, $opponentRule, $life];
            }
        }

        return $cases;
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }
}
