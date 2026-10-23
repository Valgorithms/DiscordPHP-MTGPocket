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
        $this->assertTrue($token->definition()->isToken());
        $this->assertSame($seat, $token->owner);
        $this->assertSame([], $token->definition()->colors);
        $this->assertTrue($token->definition()->is('Artifact'));
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
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, 0, ['o:'.$target]);
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
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(1, $this->game->objects[$token]->counter('+1/+1'));
    }

    private function bury(int $id, string $to = GameObject::GRAVEYARD): void
    {
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
    }

    private function golem(int $seat, string $text = '', string $type = 'Creature — Golem'): int
    {
        return $this->game->addCard($seat, ['name' => 'Golem Characteristics Unit Fixture', 'type' => $type, 'power' => '3', 'toughness' => '3', 'text' => $text], GameObject::BATTLEFIELD)->id;
    }

    /** @dataProvider sourceMoves */
    public function testDepartedSourcesDoNotReceiveCountersButOriginalControllersGolemsDo(string $zone, bool $returns): void
    {
        $this->newGame();
        [$source, $token] = $this->splicer(0);
        $artifact = $this->battlefield(1, 'Ornithopter');
        $this->bury($artifact);
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->bury($source, $zone);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
            $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
            $this->resolveOne(); // New entry creates an opposing Golem; the older trigger still belongs to seat 0.
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(1, $this->game->objects[$token]->counter('+1/+1'));
        foreach ($this->game->objects as $object) {
            if ($object->controller === 1 && $object->zone === GameObject::BATTLEFIELD) {
                $this->assertSame(0, $object->counter('+1/+1'));
            }
        }
        $this->assertSame([], $this->game->stack);
    }

    public static function sourceMoves(): array
    {
        return [[GameObject::GRAVEYARD, false], [GameObject::HAND, false], [GameObject::EXILE, false],
            [GameObject::GRAVEYARD, true], [GameObject::HAND, true], [GameObject::EXILE, true]];
    }

    public function testSourceControlChangeDoesNotChangeTriggerControllerAndGolemsAreChosenAtResolution(): void
    {
        $this->newGame();
        [$source, $token] = $this->splicer(0);
        $stolen = $this->golem(1);
        $opposing = $this->golem(1);
        $changeling = $this->golem(0, 'Changeling', 'Creature — Shapeshifter');
        $notCreature = $this->golem(0, '', 'Artifact');
        $kindred = $this->golem(0, '', 'Kindred Enchantment — Golem');
        $this->bury($this->battlefield(1, 'Ornithopter'));
        $this->game->objects[$source]->controller = 1;
        $this->game->objects[$token]->controller = 1;
        $this->game->objects[$stolen]->controller = 0;
        $late = $this->golem(0);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame([1, 0, 1, 0, 1, 0, 1, 1], array_map(fn (int $id) => $this->game->objects[$id]->counter('+1/+1'), [$source, $token, $stolen, $opposing, $changeling, $notCreature, $late, $kindred]));
        $this->assertStringContainsString(' 2/2', MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
        $this->assertStringContainsString(' 4/4', MatchMessageBuilder::permanent($this->game, $this->game->objects[$stolen]));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public function testGolemSourceReceivesBothCountersAndReturnedGolemOnlyReceivesTheTeamCounter(): void
    {
        $this->newGame();
        $card = $this->selected();
        $card['type'] .= ' Golem';
        $card['subtypes'][] = 'Golem'; // Synthetic type-change control; not another complete-card certificate.
        $source = $this->game->addCard(0, $card, GameObject::BATTLEFIELD)->id;
        $team = $this->golem(0);
        $this->bury($this->battlefield(1, 'Ornithopter'));
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(1, $this->game->objects[$team]->counter('+1/+1'));
        $this->bury($this->battlefield(1, 'Ornithopter'));
        $this->bury($source, GameObject::HAND);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 0, false, false);
        $this->resolveOne(); // Entry token first.
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(1, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(2, $this->game->objects[$team]->counter('+1/+1'));
        $this->assertSame([], $this->game->stack);
    }

    /** @dataProvider movements */
    public function testOnlyActualArtifactBurialsProduceCounters(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        [$source, $golem] = $this->splicer(0);
        $object = $this->game->addCard(1, ['name' => 'Burial Movement Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        $this->bury($object->id, $to);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $object->zone);
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
        }
        $this->assertSame([$count, $count], [$this->game->objects[$source]->counter('+1/+1'), $this->game->objects[$golem]->counter('+1/+1')]);
    }

    public static function movements(): array
    {
        return MolderBurialBuffTest::movements();
    }

    /** @dataProvider deathOrders */
    public function testPaidSimultaneousDeathsKeepOldTriggersForSurvivingGolems(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        if (! $first) {
            $this->battlefield(0, 'Ornithopter');
        }
        [$source, $token] = $this->splicer(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->game->objects[$token]->controller = $controller;
        if ($first) {
            $this->battlefield(0, 'Ornithopter');
        }
        $this->battlefield(1, 'Ornithopter');
        $survivor = $this->golem($controller, 'Indestructible');
        $opposing = $this->golem(1 - $controller, 'Indestructible');
        $incarnation = $this->game->objects[$source]->incarnation;
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'));
        $this->assertSame('44e9b312a983d7cc1e477ba44d6049c961fcb2e7c6096352836552e2aa8c0cb6', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards']['Damnation']['card'];
        $this->game->active = $this->game->priority = $active;
        $lands = $this->lands($active, 'Swamp', 4);
        $this->game->cast($active, $this->game->addCard($active, $card, GameObject::HAND)->id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(GameObject::GONE, $this->zone($token));
        $this->assertCount(3, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame([$source, $incarnation, $controller, []], [$item['object'], $item['incarnation'], $item['controller'], $item['targets']]);
        }
        $queued = $this->saved();
        foreach ([1, 2, 3] as $count) {
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
            $this->assertSame($count, $this->game->objects[$survivor]->counter('+1/+1'));
            $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
            $this->assertSame(0, $this->game->objects[$opposing]->counter('+1/+1'));
        }
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($source) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            for ($i = 0; $i < 3; $i++) {
                $this->resolveOne();
            }
            $this->assertSame($expected, $this->saved());
        }
    }

    public static function deathOrders(): array
    {
        return MolderBurialBuffTest::deathOrders();
    }

    public function testPaidShatterstormCountsTokenAndArtifactsSeparatelyAndCountersPersist(): void
    {
        $this->newGame();
        [$source, $token] = $this->splicer(0);
        $team = $this->golem(0);
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(1, 'Ornithopter');
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->lands(0, 'Mountain', 4);
        $this->game->cast(0, $this->game->addCard(0, $card, GameObject::HAND)->id);
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($token));
        $this->assertCount(3, $this->game->stack);
        foreach ([1, 2, 3] as $count) {
            $this->resolveOne();
            $this->assertSame([$count, $count], [$this->game->objects[$source]->counter('+1/+1'), $this->game->objects[$team]->counter('+1/+1')]);
        }
        $this->game = Game::fromArray($this->saved());
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame([3, 3], [$this->game->objects[$source]->counter('+1/+1'), $this->game->objects[$team]->counter('+1/+1')]);
        $this->assertStringContainsString(' 4/4', MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
        $this->assertStringContainsString(' 6/6', MatchMessageBuilder::permanent($this->game, $this->game->objects[$team]));
    }

    /** @dataProvider counterSubjects */
    public function testSubtypeCountersIncludeKindredWhileCreatureCountersStayLimited(string $subject, array $expected): void
    {
        $this->newGame();
        $source = $this->game->addCard(0, ['name' => 'Counter Scope Unit Fixture', 'type' => 'Kindred Artifact — Golem',
            'text' => '{1}: Put a +1/+1 counter on each '.$subject.' you control.'], GameObject::BATTLEFIELD)->id;
        $this->assertSame([], $this->game->objects[$source]->definition()->unsupported);
        $golem = $this->golem(0);
        $kindred = $this->golem(0, '', 'Kindred Enchantment — Golem');
        $goblin = $this->golem(0, '', 'Kindred Enchantment — Goblin');
        $changeling = $this->golem(0, 'Changeling', 'Kindred Enchantment — Shapeshifter');
        $opposing = $this->golem(1, 'Changeling', 'Kindred Enchantment — Shapeshifter');
        $nongolem = $this->battlefield(0, 'Grizzly Bears');
        $artifact = $this->golem(0, '', 'Artifact');
        $this->lands(0, 'Forest', 1);
        $this->game->activate(0, $source, 0);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($expected, array_map(fn (int $id) => $this->game->objects[$id]->counter('+1/+1'), [$source, $golem, $kindred, $goblin, $changeling, $opposing, $nongolem, $artifact]));
    }

    public static function counterSubjects(): array
    {
        return [['Golem', [1, 1, 1, 0, 1, 0, 0, 0]], ['other Golem', [0, 1, 1, 0, 1, 0, 0, 0]],
            ['Goblin', [0, 0, 0, 1, 1, 0, 0, 0]], ['creature', [0, 1, 0, 0, 0, 0, 1, 0]]];
    }

    /** @dataProvider otherWording */
    public function testNearbyUntestedClausesStayUnread(string $from, string $to): void
    {
        $card = $this->selected();
        $card['text'] = str_replace($from, $to, $card['text']);
        $definition = new CardDefinition($card);
        $this->assertContains('Partner', $definition->unsupported);
        $this->assertCount(2, $definition->unsupported);
        $this->assertSame([], $definition->triggersOn('artifact_to_graveyard_any'));
    }

    public static function otherWording(): array
    {
        return [['Whenever an artifact is', 'Whenever a permanent is'], ['each Golem you control.', 'each Golem.'],
            ['a +1/+1 counter on Ich-Tekik and', 'two +1/+1 counters on Ich-Tekik and'], ['each Golem you control.', 'each other Golem you control.']];
    }

    public static function controllers(): array
    {
        return SummoningStationChoiceTest::controllers();
    }
}
