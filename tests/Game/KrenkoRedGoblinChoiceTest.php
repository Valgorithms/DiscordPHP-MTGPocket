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
final class KrenkoRedGoblinChoiceTest extends GameTestCase
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

    private function krenko(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Krenko, Baron of Tin Street'))[0];
        $this->assertSame('MKM', $entry['setCode']);
        $this->assertSame('026a8fcb-c5db-50c9-9c9c-13db04701f21', $entry['card']['uuid']);
        $this->assertSame([], (new CardDefinition($entry['card']))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Mountain', 3);
        $id = $this->game->addCard($seat, $entry['card'], GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertTrue($this->game->hasKeyword($this->game->objects[$id], 'haste'));

        return $id;
    }

    private function smelt(int $seat, int $artifact): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'));
        $this->assertSame('6e61add3b89d3f4109d8831f4787f855bd0ac5e05b280c1c936253af75936e15', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id, 0, ['o:'.$artifact]);
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    private function goblins(int $seat): array
    {
        return array_values(array_filter($this->game->objects, fn (GameObject $object) => $object->zone === GameObject::BATTLEFIELD && $object->controller === $seat && $object->definition()->isToken() && in_array('Goblin', $object->definition()->subtypes, true)));
    }

    /** @dataProvider controllers */
    public function testPaidKrenkoAndSmeltOfferOriginalControllerRedPaymentAndTemporaryHaste(int $active, int $controller, int $artifactController): void
    {
        $this->newGame();
        $source = $this->krenko(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $artifact = $this->battlefield(1 - $artifactController, 'Ornithopter');
        $this->game->objects[$artifact]->controller = $artifactController;
        $this->smelt($active, $artifact);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($artifact));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame([$controller, []], [$this->game->stack[0]['controller'], $this->game->stack[0]['targets']]);
        $queued = $this->saved();
        $this->resolveOne();
        $this->assertSame('optional_red_goblin', $this->game->decision($controller));
        $this->assertSame([], $this->goblins($controller));
        $pending = $this->saved();
        $this->payAndAssertToken($controller);
        $expected = $this->saved();
        foreach ([$before, $queued, $pending] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->game->choiceAwaiting() === null) {
                if (($this->game->stack[0]['kind'] ?? null) !== 'ability') {
                    $this->resolveOne();
                }
                $this->resolveOne();
            }
            $this->payAndAssertToken($controller);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function payAndAssertToken(int $seat): void
    {
        $land = $this->lands($seat, 'Mountain', 1)[0];
        $choice = $this->game->choiceAwaiting();
        $this->assertTrue($this->game->canPayOptionalRedGoblin($seat));
        $this->game->chooseOptionalRedGoblin($seat, $choice['id'], 'accept');
        $this->assertTrue($this->game->objects[$land]->tapped);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount(1, $tokens = $this->goblins($seat));
        $token = $tokens[0];
        $this->assertSame($seat, $token->owner);
        $this->assertSame(['R'], $token->definition()->colors);
        $this->assertSame(['Goblin'], $token->definition()->subtypes);
        $this->assertFalse($token->definition()->is('Artifact'));
        $this->assertSame([1, 1], [$this->game->power($token), $this->game->toughness($token)]);
        $this->assertTrue($token->sick);
        $this->assertTrue($this->game->hasKeyword($token, 'haste'));
        $this->game->active = $seat;
        $this->passUntil(Step::DeclareAttackers);
        $this->game->declareAttackers($seat, [$token->id]);
        $this->assertArrayHasKey($token->id, $this->game->attackers);
        $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        $this->assertSame(GameObject::BATTLEFIELD, $token->zone);
        $this->assertFalse($this->game->hasKeyword($token, 'haste'));
        $this->assertSame([1, 1], [$this->game->power($token), $this->game->toughness($token)]);
    }

    private function burial(bool $pending = true): int
    {
        $this->newGame();
        $source = $this->krenko(0);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);
        if ($pending) {
            $this->resolveOne();
        }

        return $source;
    }

    /** @dataProvider payments */
    public function testPaymentIsOptionalColoredAndUsesOnlyOriginalControllersResources(string $resource, string $option, bool $payable): void
    {
        $this->burial();
        $land = null;
        if (in_array($resource, ['Mountain', 'Forest', 'opponent', 'tapped'], true)) {
            $land = $this->lands($resource === 'opponent' ? 1 : 0, $resource === 'Forest' ? 'Forest' : 'Mountain', 1)[0];
            $this->game->objects[$land]->tapped = $resource === 'tapped';
        } else {
            $this->game->players[0]->manaPool->add($resource === 'poolR' ? 'R' : 'G', 2);
        }
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        $this->assertSame($payable, $this->game->canPayOptionalRedGoblin(0));
        $this->assertFalse($this->game->canPayOptionalRedGoblin(1));
        $this->assertSame($before, $this->saved());
        $choice = $this->game->choiceAwaiting();
        if ($option === 'accept' && ! $payable) {
            try {
                $this->game->chooseOptionalRedGoblin(0, $choice['id'], $option);
                $this->fail('Unaffordable payment accepted.');
            } catch (GameException $error) {
                $this->assertStringContainsString('cannot pay', $error->getMessage());
                $this->assertSame($before, $this->saved());
            }
            $option = 'decline';
        }
        $this->game->chooseOptionalRedGoblin(0, $choice['id'], $option);
        $this->assertCount($option === 'accept' ? 1 : 0, $this->goblins(0));
        $this->assertNull($this->game->choiceAwaiting());
        if ($land !== null) {
            $this->assertSame($resource === 'tapped' || $option === 'accept', $this->game->objects[$land]->tapped);
        } else {
            $this->assertSame($option === 'accept' ? 1 : 2, $this->game->players[0]->manaPool->total());
        }
    }

    public static function payments(): array
    {
        $cases = [];
        foreach (['Mountain', 'Forest', 'opponent', 'tapped', 'poolR', 'poolG'] as $resource) {
            foreach (['accept', 'decline'] as $option) {
                $cases[] = [$resource, $option, in_array($resource, ['Mountain', 'poolR'], true)];
            }
        }

        return $cases;
    }

    public function testTreasurePaymentCreatesANewIndependentBurialTriggerWithoutRecursivelyBuyingTokens(): void
    {
        $this->burial();
        $treasure = $this->game->addCard(0, ['name' => 'Treasure Payment Unit Fixture', 'type' => 'Token Artifact — Treasure', 'token' => true,
            'text' => '{T}, Sacrifice this artifact: Add one mana of any color.'], GameObject::BATTLEFIELD);
        $this->assertSame([], $treasure->definition()->unsupported);
        $this->assertTrue($this->game->canPayOptionalRedGoblin(0));
        $first = $this->game->choiceAwaiting()['id'];
        $this->game->chooseOptionalRedGoblin(0, $first, 'accept');
        $this->assertSame(GameObject::GONE, $treasure->zone);
        $this->assertCount(1, $this->goblins(0));
        $this->assertCount(1, $this->game->stack);
        $this->resolveOne();
        $this->assertGreaterThan($first, $this->game->choiceAwaiting()['id']);
        $this->assertFalse($this->game->canPayOptionalRedGoblin(0));
        $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertCount(1, $this->goblins(0));
        $this->assertSame([], $this->game->stack);
    }

    public function testPaidCastAndTapSacrificeActivationPumpsOnlyControlledGoblinsIncludingTheNewToken(): void
    {
        $this->newGame();
        $source = $this->krenko(0);
        $fodder = $this->battlefield(0, 'Ornithopter');
        $goblin = ['name' => 'Goblin Counter Unit Fixture', 'type' => 'Creature — Goblin', 'subtypes' => ['Goblin'], 'power' => '1', 'toughness' => '1', 'text' => null];
        $friendly = $this->game->addCard(0, $goblin, GameObject::BATTLEFIELD);
        $enemy = $this->game->addCard(1, $goblin, GameObject::BATTLEFIELD);
        $nongoblin = $this->battlefield(0, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 1);
        $this->assertTrue($this->game->objects[$source]->sick);
        $this->assertTrue($this->game->canActivate(0, $source, 0));
        $this->game->activate(0, $source, 0);
        $this->assertTrue($this->game->objects[$source]->tapped);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        $this->assertCount(2, $this->game->stack);
        $this->assertFalse($this->game->canActivate(0, $source, 0));
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        $this->resolveOne();
        foreach ([$this->game->objects[$source], $this->game->objects[$friendly->id], $this->goblins(0)[0]] as $object) {
            $this->assertSame(1, $object->counter('+1/+1'));
        }
        $this->assertSame(0, $this->game->objects[$enemy->id]->counter('+1/+1'));
        $this->assertSame(0, $this->game->objects[$nongoblin]->counter('+1/+1'));
        $this->assertTrue($this->game->hasKeyword($this->goblins(0)[0], 'haste'));
    }

    /** @dataProvider sourceMoves */
    public function testOriginalControllerStillPaysAndCreatesTokenAfterSourceDepartsOrReturns(string $zone, bool $returns, bool $pending): void
    {
        $source = $this->burial($pending);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], $zone);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
        }
        $this->game = Game::fromArray($this->saved());
        if (! $pending) {
            $this->resolveOne();
        }
        $this->assertSame(0, $this->game->choiceAwaiting()['seat']);
        $this->lands(0, 'Mountain', 1);
        $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertCount(1, $this->goblins(0));
        $this->assertCount(0, $this->goblins(1));
        $this->assertSame($returns ? GameObject::BATTLEFIELD : $zone, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
    }

    public static function sourceMoves(): array
    {
        return MoriokBurialChoiceTest::sourceMoves();
    }

    /** @dataProvider sourceOrders */
    public function testPaidDamnationRetainsEachArtifactTriggerAfterKrenkoDies(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->krenko(1 - $controller) : null;
        $this->battlefield(0, 'Ornithopter');
        $this->battlefield(1, 'Ornithopter');
        $source ??= $this->krenko(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $this->game->active = $this->game->priority = $active;
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'));
        $this->assertSame('44e9b312a983d7cc1e477ba44d6049c961fcb2e7c6096352836552e2aa8c0cb6', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards']['Damnation']['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $lands = $this->lands($active, 'Swamp', 4);
        $this->game->cast($active, $this->game->addCard($active, $card, GameObject::HAND)->id);
        foreach ($lands as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $before = $this->saved();
        $this->resolveOne();
        $queued = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->assertSame($queued, $this->saved());
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(2, $this->game->stack);
        $this->game = Game::fromArray($this->saved());
        $land = $this->lands($controller, 'Mountain', 1)[0];
        $last = 0;
        foreach (['decline', 'accept'] as $option) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame($controller, $choice['seat']);
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalRedGoblin($controller, $choice['id'], $option);
            $this->assertSame($option === 'accept', $this->game->objects[$land]->tapped);
        }
        $this->assertCount(1, $this->goblins($controller));
        $this->assertSame([], $this->game->stack);
    }

    public static function sourceOrders(): array
    {
        return MoriokBurialChoiceTest::orders();
    }

    /** @dataProvider movements */
    public function testOnlyActualArtifactBurialOffersThePayment(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $this->krenko(0);
        $object = $this->game->addCard(1, ['name' => 'Goblin Movement Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->resolveOne();
            $this->assertSame('optional_red_goblin', $this->game->decision(0));
            $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], 'decline');
        }
        $this->assertCount(0, $this->goblins(0));
    }

    public static function movements(): array
    {
        return MoriokBurialChoiceTest::movements();
    }

    /** @dataProvider options */
    public function testLaterEffectsResumeExactlyOnceEvenAfterSourceLeaves(string $option): void
    {
        $source = $this->burial(false);
        $this->game->stack[array_key_last($this->game->stack)]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $this->resolveOne();
        $this->assertSame(20, $this->life(0));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->lands(0, 'Mountain', 1);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame(22, $this->life(0));
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(22, $this->life(0));
        $this->assertCount($option === 'accept' ? 1 : 0, $this->goblins(0));
    }

    public static function options(): array
    {
        return [['accept'], ['decline']];
    }

    public function testWrongStaleCrossEffectAndRepeatedAnswersPreserveState(): void
    {
        $this->burial();
        $this->lands(0, 'Mountain', 1);
        $choice = $this->game->choiceAwaiting();
        foreach ([[1, $choice['id'], 'accept'], [0, $choice['id'] + 1, 'accept'], [0, $choice['id'], 'bad']] as [$seat, $id, $option]) {
            $before = $this->saved();
            try {
                $this->game->chooseOptionalRedGoblin($seat, $id, $option);
                $this->fail('Invalid answer accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        foreach (['chooseOptionalSourceCounter', 'chooseOptionalSourceUntap', 'chooseOptionalLifeGain', 'chooseOptionalLifeLoss'] as $method) {
            $before = $this->saved();
            try {
                $this->game->$method(0, $choice['id'], 'accept');
                $this->fail('Another effect answered a red-payment decision.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->game->chooseOptionalRedGoblin(0, $choice['id'], 'accept');
        $before = $this->saved();
        try {
            $this->game->chooseOptionalRedGoblin(0, $choice['id'], 'accept');
            $this->fail('Repeated answer accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider practiceMana */
    public function testPracticeExplicitlyBuysOnlyAffordableGoblinsAfterControlChanges(bool $payable): void
    {
        $source = $this->burial();
        $this->game->objects[$source]->controller = 1;
        if ($payable) {
            $this->lands(0, 'Mountain', 1);
        }
        $this->game = Game::fromArray($this->saved());
        $this->assertTrue((new PracticeBot())->act($this->game, 0));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertCount($payable ? 1 : 0, $this->goblins(0));
        $this->assertCount(0, $this->goblins(1));
    }

    public static function practiceMana(): array
    {
        return [[true], [false]];
    }

    public function testManaRestrictedToCreatureSpellsCannotPayForThisResolvingAbility(): void
    {
        $this->burial();
        $land = $this->game->addCard(0, ['name' => 'Restricted Red Unit Fixture', 'type' => 'Land',
            'text' => '{T}: Add {R}. Spend this mana only to cast creature spells.'], GameObject::BATTLEFIELD);
        $this->assertSame([], $land->definition()->unsupported);
        $this->assertFalse($this->game->canPayOptionalRedGoblin(0));
        $this->assertFalse($land->tapped);
        $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], 'decline');
        $this->assertCount(0, $this->goblins(0));
    }

    /** @dataProvider differentWording */
    public function testNearbyUntestedWordingIsNotAdmitted(string $from, string $to): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'), true, flags: JSON_THROW_ON_ERROR);
        $card = array_values(array_filter($fixture['candidates'], fn (array $entry) => $entry['card']['name'] === 'Krenko, Baron of Tin Street'))[0]['card'];
        $card['text'] = str_replace($from, $to, $card['text']);
        $definition = new CardDefinition($card);
        $this->assertNotEmpty($definition->unsupported);
        $this->assertSame([], $definition->triggersOn('artifact_to_graveyard_any'));
    }

    public static function differentWording(): array
    {
        return [['you may pay {R}', 'you may pay {U}'], ['a 1/1 red Goblin', 'a 2/2 red Goblin'],
            ['Whenever an artifact is', 'Whenever a permanent is'], ['It gains haste until end of turn.', 'It gains haste.']];
    }

    /** @dataProvider malformed */
    public function testMalformedSavedChoicesAreRejected(string $key, mixed $value): void
    {
        $this->burial();
        $saved = $this->saved();
        $field = &$saved;
        foreach (explode('.', $key) as $part) {
            $field = &$field[$part];
        }
        $field = $value;
        unset($field);
        $this->expectException(GameException::class);
        Game::fromArray($saved);
    }

    public static function malformed(): array
    {
        $cases = SummoningStationChoiceTest::malformed();
        $cases[0] = ['schemaVersion', 11];

        return $cases;
    }

    public function testVersionElevenUntapChoiceStillResumesAndCannotBeAnsweredAsPayment(): void
    {
        $this->newGame();
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'), true, flags: JSON_THROW_ON_ERROR);
        $entry = array_values(array_filter($fixture['candidates'], fn (array $entry) => $entry['card']['name'] === 'Summoning Station'))[0];
        $source = $this->game->addCard(0, $entry['card'], GameObject::BATTLEFIELD);
        $source->tapped = true;
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->resolveOne();
        $saved = $this->saved();
        $saved['schemaVersion'] = 11;
        $this->game = Game::fromArray($saved);
        $this->assertSame(Game::STATE_VERSION, $this->saved()['schemaVersion']);
        $before = $this->saved();
        $this->assertFalse($this->game->canPayOptionalRedGoblin(0));
        try {
            $this->game->chooseOptionalRedGoblin(0, $this->game->choiceAwaiting()['id'], 'accept');
            $this->fail('Payment answered an untap decision.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
        $this->game->chooseOptionalSourceUntap(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertFalse($this->game->objects[$source->id]->tapped);
    }

    public static function controllers(): array
    {
        return SummoningStationChoiceTest::controllers();
    }
}
