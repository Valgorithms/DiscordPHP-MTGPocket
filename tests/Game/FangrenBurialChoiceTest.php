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
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class FangrenBurialChoiceTest extends GameTestCase
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

    private function marauder(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $entry = array_values(array_filter(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['candidates'], fn (array $entry) => $entry['card']['name'] === 'Fangren Marauder'))[0];
        $this->assertSame('PLST', $entry['setCode']);
        $card = $entry['card'];
        $this->assertSame('4eb39ba0-12a9-5050-a83a-ca18a2b37b1f', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $lands = $this->lands($seat, 'Forest', 6);
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));

        return $id;
    }

    private function damnation(int $seat): void
    {
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/simultaneous-deaths.json'), true, flags: JSON_THROW_ON_ERROR)['cards']['Damnation']['card'];
        $this->game->active = $this->game->priority = $seat;
        $this->lands($seat, 'Swamp', 4);
        $this->game->cast($seat, $this->game->addCard($seat, $card, GameObject::HAND)->id);
    }

    /** @dataProvider orders */
    public function testPaidMarauderSeesBothPlayersArtifactsAndMakesIndependentOptionalChoices(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->marauder(1 - $controller) : null;
        foreach ([0, 1, 0, 1] as $seat) {
            $this->battlefield($seat, 'Ornithopter');
        }
        $source ??= $this->marauder(1 - $controller);
        $this->game->objects[$source]->controller = $controller;
        $incarnation = $this->game->objects[$source]->incarnation;
        $this->damnation($active);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertCount(4, $this->game->stack);
        foreach ($this->game->stack as $item) {
            $this->assertSame($controller, $item['controller']);
            $this->assertSame($incarnation, $item['incarnation']);
            $this->assertSame([], $item['targets'], 'Life gain does not target a player.');
        }
        $queued = $this->saved();
        $this->answerFour($controller);
        $this->assertSame(20, $this->life(1 - $controller));
        $this->assertSame(30, $this->life($controller));
        $expected = $this->saved();
        foreach ([$before, $queued] as $saved) {
            $this->game = Game::fromArray($saved);
            if ($this->zone($source) === GameObject::BATTLEFIELD) {
                $this->resolveOne();
            }
            $this->answerFour($controller);
            $this->assertSame($expected, $this->saved());
        }
    }

    private function answerFour(int $seat): void
    {
        $last = 0;
        foreach (['accept', 'decline', 'accept', 'decline'] as $option) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame('optional_life_gain', $this->game->decision($seat));
            $this->assertSame(['accept', 'decline'], $choice['options']);
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalLifeGain($seat, $choice['id'], $option);
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
    }

    private function buryOne(): int
    {
        $this->newGame();
        $source = $this->marauder(0);
        $artifact = $this->battlefield(1, 'Ornithopter');
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        $move->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);

        return $source;
    }

    public function testWrongStaleInvalidAndRepeatedAnswersPreserveState(): void
    {
        $this->buryOne();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        foreach ([[1, $choice['id'], 'accept'], [0, $choice['id'] + 1, 'accept'], [0, $choice['id'], 'invalid']] as [$seat, $id, $option]) {
            $before = $this->saved();
            try {
                $this->game->chooseOptionalLifeGain($seat, $id, $option);
                $this->fail('An illegal answer was accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->game->chooseOptionalLifeGain(0, $choice['id'], 'decline');
        $this->assertSame(20, $this->life(1));
        $before = $this->saved();
        try {
            $this->game->chooseOptionalLifeGain(0, $choice['id'], 'accept');
            $this->fail('The old answer was accepted twice.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    public function testOriginalTriggerControllerCanAnswerAfterSourceReturnsUnderAnotherController(): void
    {
        $source = $this->buryOne();
        $incarnation = $this->game->objects[$source]->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $this->game->objects[$source], 1, false, false);
        $this->assertNotSame($incarnation, $this->game->objects[$source]->incarnation);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame([0, $incarnation], [$choice['seat'], $choice['incarnation']]);
        $this->assertArrayNotHasKey('target', $choice);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalLifeGain(0, $choice['id'], 'accept');
        $this->assertSame([25, 20], [$this->life(0), $this->life(1)]);
    }

    /** @dataProvider moves */
    public function testOnlyActualBattlefieldArtifactBurialsTrigger(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $this->marauder(0);
        $object = $this->game->addCard(1, ['name' => 'Burial Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $object->zone);
            $this->resolveOne();
            $this->assertSame('optional_life_gain', $this->game->decision(0));
            $this->game->chooseOptionalLifeGain(0, $this->game->choiceAwaiting()['id'], 'accept');
        }
        $this->assertSame([20 + 5 * $count, 20], [$this->life(0), $this->life(1)]);
    }

    public static function moves(): array
    {
        return [
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 1],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, true, false, 1],
            ['Artifact Creature', GameObject::HAND, GameObject::GRAVEYARD, false, false, 0],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::EXILE, false, false, 0],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::HAND, false, false, 0],
            ['Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 0],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, true, 0],
        ];
    }

    public function testPracticePolicyUsesTheSamePersistedChoice(): void
    {
        $this->buryOne();
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->assertTrue((new PracticeBot())->act($this->game, 0));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame([25, 20], [$this->life(0), $this->life(1)]);
    }

    /** @dataProvider options */
    public function testRemainingEffectsResumeExactlyOnceAfterJsonChoice(string $option): void
    {
        $this->buryOne();
        $top = array_key_last($this->game->stack);
        $this->game->stack[$top]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $this->resolveOne();
        $this->assertSame(20, $this->life(0));
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalLifeGain(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame([$option === 'accept' ? 27 : 22, 20], [$this->life(0), $this->life(1)]);
        $this->assertNull($this->game->choiceAwaiting());
        $this->game = Game::fromArray($this->saved());
        $this->assertSame($option === 'accept' ? 27 : 22, $this->life(0));
    }

    public static function options(): array
    {
        return [['accept'], ['decline']];
    }

    /** @dataProvider malformed */
    public function testMalformedSavedDecisionsAreRejected(string $key, mixed $value): void
    {
        $this->buryOne();
        $this->resolveOne();
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
        return [
            ['schemaVersion', 8], ['nextChoiceId', 1], ['nextChoiceId', '2'], ['pendingChoice.id', 0],
            ['pendingChoice.seat', 9], ['pendingChoice.options', ['accept']], ['pendingChoice.target', 'p:0'], ['pendingChoice.object', -1],
            ['pendingChoice.incarnation', -1], ['pendingChoice.label', null], ['pendingChoice.resume', null],
            ['pendingChoice.resume.item', []], ['pendingChoice.resume.item.controller', 1],
            ['pendingChoice.resume.item.incarnation', 99], ['pendingChoice.resume.item.targets', ['p:0']],
            ['pendingChoice.resume.self', [1, 99]], ['pendingChoice.resume.steps', 'bad'],
            ['pendingChoice.resume.steps', [[[], null]]], ['pendingChoice.resume.followed', [0 => 1]],
        ];
    }

    public function testVersionEightWithoutTheNewChoiceStillLoads(): void
    {
        $this->newGame();
        $saved = $this->saved();
        $saved['schemaVersion'] = 8;
        $this->assertSame(Game::STATE_VERSION, Game::fromArray($saved)->toArray()['schemaVersion']);
    }

    /** @dataProvider hexproofTiming */
    public function testPlayerHexproofDoesNotSuppressAnUntargetedGain(bool $before): void
    {
        if ($before) {
            $this->newGame();
            $this->marauder(0);
        } else {
            $this->buryOne();
        }
        foreach ([0, 1] as $seat) {
            $shield = ['name' => 'Hexproof Unit Fixture '.$seat, 'type' => 'Enchantment', 'text' => 'You have hexproof.'];
            $this->assertSame([], (new CardDefinition($shield))->unsupported);
            $this->game->addCard($seat, $shield, GameObject::BATTLEFIELD);
        }
        if ($before) {
            $artifact = $this->battlefield(1, 'Ornithopter');
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
            (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        }
        $this->assertCount(1, $this->game->stack);
        $this->assertSame([], $this->game->stack[0]['targets']);
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalLifeGain(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame([25, 20], [$this->life(0), $this->life(1)]);
    }

    public static function hexproofTiming(): array
    {
        return [[true], [false]];
    }

    /** @dataProvider gainRules */
    public function testOptionalGainUsesExistingLifePreventionAndModification(string $text, int $seat, string $option, int $life): void
    {
        $this->buryOne();
        $this->resolveOne();
        $rule = ['name' => 'Life Rule Unit Fixture', 'type' => 'Enchantment', 'text' => $text];
        $this->assertSame([], (new CardDefinition($rule))->unsupported);
        $this->game->addCard($seat, $rule, GameObject::BATTLEFIELD);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalLifeGain(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame([$life, 20], [$this->life(0), $this->life(1)]);
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function gainRules(): array
    {
        return [
            ["Players can't gain life.", 1, 'accept', 20],
            ["Your opponents can't gain life.", 1, 'accept', 20],
            ["Your opponents can't gain life.", 0, 'accept', 25],
            ['If you would gain life, you gain that much life plus 1 instead.', 0, 'accept', 26],
            ['If you would gain life, you gain that much life plus 1 instead.', 1, 'accept', 25],
            ['If you would gain life, you gain that much life plus 1 instead.', 0, 'decline', 20],
        ];
    }

    /** @dataProvider options */
    public function testOnlyAcceptedGainNotifiesExistingLifeGainObservers(string $option): void
    {
        $this->buryOne();
        $observer = ['name' => 'Life Observer Unit Fixture', 'type' => 'Enchantment', 'text' => 'Whenever you gain life, draw a card.'];
        $this->assertSame([], (new CardDefinition($observer))->unsupported);
        $this->game->addCard(0, $observer, GameObject::BATTLEFIELD);
        $this->resolveOne();
        $hand = count($this->game->players[0]->hand);
        $this->game->chooseOptionalLifeGain(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertCount($option === 'accept' ? 1 : 0, $this->game->stack);
        if ($option === 'accept') {
            $this->game = Game::fromArray($this->saved());
            $this->resolveOne();
        }
        $this->assertCount($hand + ($option === 'accept' ? 1 : 0), $this->game->players[0]->hand);
    }

    public function testDiscipleAndFangrenKeepSeparateTargetedAndUntargetedChoices(): void
    {
        $this->newGame();
        $this->marauder(0);
        $disciple = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-observer-disciple-of-the-vault.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->addCard(0, $disciple, GameObject::BATTLEFIELD);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(2, $this->game->stack);
        $types = [];
        for ($n = 0; $n < 2; $n++) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $types[] = $choice['type'];
            $saved = $this->saved();
            try {
                if ($choice['type'] === 'optional_life_loss') {
                    $this->game->chooseOptionalLifeGain(0, $choice['id'], 'accept');
                } else {
                    $this->game->chooseOptionalLifeLoss(0, $choice['id'], 'accept');
                }
                $this->fail('A different optional effect answered this choice.');
            } catch (GameException) {
                $this->assertSame($saved, $this->saved());
            }
            if ($choice['type'] === 'optional_life_loss') {
                $this->assertSame('p:1', $choice['target']);
                $this->game = Game::fromArray($saved);
                $this->game->chooseOptionalLifeLoss(0, $choice['id'], 'accept');
            } else {
                $this->assertSame('optional_life_gain', $choice['type']);
                $this->assertArrayNotHasKey('target', $choice);
                $this->game = Game::fromArray($saved);
                $this->game->chooseOptionalLifeGain(0, $choice['id'], 'accept');
            }
        }
        sort($types);
        $this->assertSame(['optional_life_gain', 'optional_life_loss'], $types);
        $this->assertSame([25, 19], [$this->life(0), $this->life(1)]);
    }

    public function testVersionEightPendingDiscipleChoiceStillResumes(): void
    {
        $this->newGame();
        $disciple = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-observer-disciple-of-the-vault.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->addCard(0, $disciple, GameObject::BATTLEFIELD);
        $artifact = $this->battlefield(1, 'Ornithopter');
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->resolveOne();
        $saved = $this->saved();
        $saved['schemaVersion'] = 8;
        $this->game = Game::fromArray($saved);
        $this->game->chooseOptionalLifeLoss(0, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame([20, 19], [$this->life(0), $this->life(1)]);
        $this->assertSame(9, $this->saved()['schemaVersion']);
    }

    public static function orders(): array
    {
        $cases = [];
        foreach ([0, 1] as $active) {
            foreach ([0, 1] as $controller) {
                foreach ([false, true] as $first) {
                    $cases[] = [$active, $controller, $first];
                }
            }
        }

        return $cases;
    }
}
