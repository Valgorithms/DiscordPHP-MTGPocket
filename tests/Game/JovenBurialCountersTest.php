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
final class JovenBurialCountersTest extends GameTestCase
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
        $entry = array_values(array_filter($entries, fn (array $entry) => $entry['card']['name'] === 'Joven and Chandler'))[0];
        $this->assertSame('MBC', $entry['setCode']);
        $this->assertSame('6e88e450-3e9c-575e-96b2-6b0ac6a9434d', $entry['card']['uuid']);

        return $entry['card'];
    }

    private function source(int $seat): int
    {
        $card = $this->selected();
        $definition = new CardDefinition($card);
        $this->assertSame([], $definition->unsupported);
        $this->assertCount(1, $definition->triggersOn('artifact_to_graveyard_any'));
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 5);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function smelt(int $seat, int $target): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, 0, ['o:'.$target]);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    /** @dataProvider controllers */
    public function testPaidSourceAndSmeltUseBuriedManaValueAcrossControllersAndJson(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->source(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $artifact = $this->battlefield(1 - $artifactController, 'Bottle Gnomes');
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
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(6, $this->game->power($this->game->objects[$source]));
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
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function controllers(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([0, 1] as $artifactController) {
                    $cases[] = [$active, $controller, $artifactController];
                }
            }
        }

        return $cases;
    }

    private function move(int $id, string $zone): void
    {
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$id], $zone);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
    }

    private function artifact(int $seat, string $cost, bool $token = false): int
    {
        $card = ['name' => 'Mana Value Artifact Unit Fixture', 'type' => 'Artifact', 'manaCost' => $cost, 'text' => null];

        return $token
            ? (new \ReflectionMethod(Game::class, 'createToken'))->invoke($this->game, $card, $seat)->id
            : $this->game->addCard($seat, $card, GameObject::BATTLEFIELD)->id;
    }

    /** @dataProvider manaValues */
    public function testPaidSmeltBindsBattlefieldCostIncludingTokensAndX(string $cost, bool $token, int $expected): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->artifact(1, $cost, $token);
        $this->smelt(0, $artifact);
        $this->resolveOne();
        $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertCount(1, $this->game->stack, 'A zero-mana artifact still triggers.');
        $this->assertSame($expected, $this->game->stack[0]['effects'][0]['amount']);
        if (! $token) {
            // Returning the event object before resolution cannot change the bound value.
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$artifact], 0, false, false);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($expected, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function manaValues(): array
    {
        return [['', true, 0], ['', false, 0], ['{0}', false, 0], ['{3}', false, 3], ['{3}', true, 3], ['{X}', false, 0], ['{X}{X}{2}', false, 2], ['{2/W}{U/P}{3}', false, 6]];
    }

    public function testPaidXArtifactUsesZeroForXOffStack(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $card = ['name' => 'X Cost Artifact Unit Fixture', 'type' => 'Artifact', 'manaCost' => '{X}{2}', 'text' => null];
        $lands = $this->lands(0, 'Mountain', 6);
        $artifact = $this->game->addCard(0, $card, GameObject::HAND)->id;
        $this->game->cast(0, $artifact, 4);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->smelt(0, $artifact);
        $this->resolveOne();
        $this->assertSame(2, $this->game->stack[0]['effects'][0]['amount']);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(2, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testPaidTokenCopyRetainsCopiedManaCostWhenItCeasesToExist(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $original = $this->battlefield(0, 'Bottle Gnomes');
        $copySpell = ['name' => 'Token Copy Unit Fixture', 'type' => 'Instant', 'manaCost' => '{U}', 'text' => "Create a token that's a copy of target creature you control."];
        $this->assertSame([], (new CardDefinition($copySpell))->unsupported);
        $land = $this->lands(0, 'Island', 1)[0];
        $this->game->cast(0, $this->game->addCard(0, $copySpell, GameObject::HAND)->id, 0, ['o:'.$original]);
        $this->assertTrue($this->game->objects[$land]->tapped);
        $this->resolveOne();
        $copies = array_values(array_filter($this->game->objects, fn (GameObject $object) => $object->zone === GameObject::BATTLEFIELD && $object->definition()->isToken()));
        $this->assertCount(1, $copies);
        $copy = $copies[0]->id;
        $this->assertSame(3, $copies[0]->definition()->cost->manaValue());
        $this->smelt(0, $copy);
        $this->resolveOne();
        $this->assertSame(GameObject::GONE, $this->zone($copy));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($original));
        $this->assertSame(3, $this->game->stack[0]['effects'][0]['amount']);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testDepartingMergedDefinitionWinsOverPrintedAndLaterResetDefinition(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->artifact(1, '{9}');
        $top = $this->game->addCard(1, ['name' => 'Merged Artifact Unit Fixture', 'type' => 'Artifact', 'manaCost' => '{4}', 'text' => null], GameObject::EXILE);
        $this->game->objects[$artifact]->addMutation($top->id, $top->card, true);
        $this->assertSame(4, $this->game->objects[$artifact]->definition()->cost->manaValue());
        $this->assertSame(9, $this->game->objects[$artifact]->printed()->cost->manaValue());
        $this->smelt(0, $artifact);
        $this->resolveOne();
        $this->assertSame(9, $this->game->objects[$artifact]->definition()->cost->manaValue());
        $this->assertCount(1, $this->game->stack);
        $this->assertSame(4, $this->game->stack[0]['effects'][0]['amount']);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(4, $this->game->objects[$source]->counter('+1/+1'));
    }

    /** @dataProvider sourceMoves */
    public function testOldSourceTriggerCannotCounterDepartedOrReturnedIncarnation(string $zone, bool $returns): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->artifact(1, '{3}');
        $this->smelt(0, $artifact);
        $this->resolveOne();
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->move($source, $zone);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
        }
        $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame([], $this->game->stack);
    }

    public static function sourceMoves(): array
    {
        return [[GameObject::GRAVEYARD, false], [GameObject::GRAVEYARD, true], [GameObject::HAND, false], [GameObject::HAND, true], [GameObject::EXILE, false], [GameObject::EXILE, true]];
    }

    public function testSurvivingSourceReceivesCountersAfterControlChanges(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->artifact(1, '{3}');
        $this->smelt(0, $artifact);
        $this->resolveOne();
        $this->assertSame(0, $this->game->stack[0]['controller']);
        $this->game->objects[$source]->controller = 1;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(3, $this->game->objects[$source]->counter('+1/+1'));
        $this->assertSame(1, $this->game->objects[$source]->controller);
        $this->assertStringContainsString(' 6/6', MatchMessageBuilder::permanent($this->game, $this->game->objects[$source]));
    }

    /** @dataProvider movements */
    public function testOnlyActualArtifactBurialBindsManaValue(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $source = $this->source(0);
        $object = $this->game->addCard(1, ['name' => 'Burial Movement Unit Fixture', 'type' => $type, 'manaCost' => '{3}', 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        $this->move($object->id, $to);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
        }
        $this->assertSame(3 * $count, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function movements(): array
    {
        return MolderBurialBuffTest::movements();
    }

    /** @dataProvider deathOrders */
    public function testPaidSelectedArtifactGroupsBindIndependentValuesAndPersist(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->source(1 - $controller) : null;
        $one = $this->battlefield(0, 'Bonesplitter');
        $three = $this->battlefield(1, 'Bottle Gnomes');
        $zero = $this->battlefield(0, 'Ornithopter');
        $token = $this->artifact(1, '', true);
        $source ??= $this->source(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $other = $this->source(1 - $controller);
        $this->game->active = $this->game->priority = $active;
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-shatterstorm.json'));
        $this->assertSame('9bfd9ecb99db8022a5bc52b775f1e66c8d06ed65c849f8fd202d41a6d8c9bcae', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $lands = $this->lands($active, 'Mountain', 4);
        $this->game->cast($active, $this->game->addCard($active, $card, GameObject::HAND)->id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::GRAVEYARD, GameObject::GRAVEYARD, GameObject::GONE], array_map(fn (int $id) => $this->zone($id), [$one, $three, $zero, $token]));
        $this->assertCount(8, $this->game->stack);
        foreach ([$source, $other] as $id) {
            $amounts = array_column(array_column(array_filter($this->game->stack, fn (array $item) => $item['object'] === $id), 'effects'), 0);
            $amounts = array_column($amounts, 'amount');
            sort($amounts);
            $this->assertSame([0, 0, 1, 3], $amounts);
        }
        $queued = $this->saved();
        for ($i = 0; $i < 8; $i++) {
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
        }
        $this->assertSame([4, 4], array_map(fn (int $id) => $this->game->objects[$id]->counter('+1/+1'), [$source, $other]));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($one) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            for ($i = 0; $i < 8; $i++) {
                $this->resolveOne();
            }
            $this->assertSame($expected, $this->saved());
        }
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame([4, 4], array_map(fn (int $id) => $this->game->objects[$id]->counter('+1/+1'), [$source, $other]));
    }

    public static function deathOrders(): array
    {
        return MolderBurialBuffTest::deathOrders();
    }

    /** @dataProvider deathOrders */
    public function testPaidSimultaneousSourceDeathRetainsValuesButCannotCounterReturnedSource(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->source(1 - $controller) : null;
        $this->battlefield(0, 'Bottle Gnomes');
        $this->battlefield(1, 'Ornithopter');
        $source ??= $this->source(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
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
        $this->assertCount(2, $this->game->stack);
        $amounts = [];
        foreach ($this->game->stack as $item) {
            $this->assertSame([$source, $incarnation, $controller, []], [$item['object'], $item['incarnation'], $item['controller'], $item['targets']]);
            $amounts[] = $item['effects'][0]['amount'];
        }
        sort($amounts);
        $this->assertSame([0, 3], $amounts);
        $queued = $this->saved();
        foreach ([$before, $queued] as $saved) {
            foreach ([false, true] as $returns) {
                $this->game = Game::fromArray($saved);
                if ($this->zone($source) === GameObject::BATTLEFIELD) {
                    $this->resolveOne();
                }
                if ($returns) {
                    (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1 - $controller, false, false);
                }
                $this->game = Game::fromArray($this->saved());
                $this->resolveOne();
                $this->game = Game::fromArray($this->saved());
                $this->resolveOne();
                $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
                $this->assertSame([], $this->game->stack);
            }
        }
    }

    public function testSuccessiveBurialsOfDifferentArtifactIncarnationsKeepIndependentValues(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->artifact(1, '{9}');
        $top = $this->game->addCard(1, ['name' => 'Merged Artifact Unit Fixture', 'type' => 'Artifact', 'manaCost' => '{4}', 'text' => null], GameObject::EXILE);
        $this->game->objects[$artifact]->addMutation($top->id, $top->card, true);
        $this->move($artifact, GameObject::GRAVEYARD);
        $this->assertSame(4, $this->game->stack[0]['effects'][0]['amount']);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$artifact], 0, false, false);
        $this->assertSame(9, $this->game->objects[$artifact]->definition()->cost->manaValue());
        $this->move($artifact, GameObject::GRAVEYARD);
        $this->assertCount(2, $this->game->stack);
        $this->assertSame([4, 9], array_map(fn (array $item) => $item['effects'][0]['amount'], $this->game->stack));
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(9, $this->game->objects[$source]->counter('+1/+1'));
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(13, $this->game->objects[$source]->counter('+1/+1'));
    }

    public function testFaceDownPrintedArtifactIsNotAnArtifactAtDeparture(): void
    {
        $this->newGame();
        $source = $this->source(0);
        $artifact = $this->artifact(1, '{9}');
        $this->game->objects[$artifact]->faceDown = true;
        $this->assertFalse($this->game->objects[$artifact]->definition()->is('Artifact'));
        $this->move($artifact, GameObject::GRAVEYARD);
        $this->assertTrue($this->game->objects[$artifact]->definition()->is('Artifact'));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
    }

    /** @dataProvider unreadWordings */
    public function testNearbyWordingRemainsExplicitlyUnread(string $text): void
    {
        $card = ['name' => 'Counter Wording Unit Fixture', 'type' => 'Creature — Human', 'power' => '3', 'toughness' => '3', 'text' => $text];
        $definition = new CardDefinition($card);
        $this->assertNotSame([], $definition->unsupported);
        $this->assertSame([], $definition->triggersOn('artifact_to_graveyard_any'));
    }

    public static function unreadWordings(): array
    {
        return [
            ["Whenever an artifact is put into a graveyard from the battlefield, put a number of +1/+1 counters equal to that artifact's power on Counter Wording Unit Fixture."],
            ["Whenever an artifact is exiled from the battlefield, put a number of +1/+1 counters equal to that artifact's mana value on Counter Wording Unit Fixture."],
            ["Whenever an artifact is put into a graveyard from the battlefield, you may put a number of +1/+1 counters equal to that artifact's mana value on Counter Wording Unit Fixture."],
            ["Whenever an artifact is put into a graveyard from the battlefield, put a number of +1/+1 counters equal to that artifact's mana value on target creature."],
        ];
    }
}
