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
final class DiscipleBurialChoiceTest extends GameTestCase
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

    private function disciple(int $seat): int
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/artifact-observer-disciple-of-the-vault.json'));
        $this->assertSame('b33eed918ceedab68166974331934d0653d67a4764ae58e672b2b5d34acc0913', hash('sha256', $bytes));
        $card = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->active = $this->game->priority = $seat;
        $land = $this->lands($seat, 'Swamp', 1)[0];
        $id = $this->game->addCard($seat, $card, GameObject::HAND)->id;
        $this->game->cast($seat, $id);
        $this->assertTrue($this->game->objects[$land]->tapped);
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
    public function testPaidDiscipleSeesBothPlayersArtifactsAndMakesIndependentOptionalChoices(int $active, int $controller, bool $first): void
    {
        $this->newGame();
        $source = $first ? $this->disciple(1 - $controller) : null;
        foreach ([0, 1, 0, 1] as $seat) {
            $this->battlefield($seat, 'Ornithopter');
        }
        $source ??= $this->disciple(1 - $controller);
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
            $this->assertSame(['p:'.(1 - $controller)], $item['targets'], 'Target is chosen before resolution, including when the later answer will decline.');
        }
        $queued = $this->saved();
        $this->answerFour($controller);
        $this->assertSame(18, $this->life(1 - $controller));
        $this->assertSame(20, $this->life($controller));
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
            $this->assertSame('optional_life_loss', $this->game->decision($seat));
            $this->assertSame(['accept', 'decline'], $choice['options']);
            $this->assertGreaterThan($last, $choice['id']);
            $last = $choice['id'];
            $this->game = Game::fromArray($this->saved());
            $this->game->chooseOptionalLifeLoss($seat, $choice['id'], $option);
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
    }

    private function buryOne(): int
    {
        $this->newGame();
        $source = $this->disciple(0);
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
                $this->game->chooseOptionalLifeLoss($seat, $id, $option);
                $this->fail('An illegal answer was accepted.');
            } catch (GameException) {
                $this->assertSame($before, $this->saved());
            }
        }
        $this->game->chooseOptionalLifeLoss(0, $choice['id'], 'decline');
        $this->assertSame(20, $this->life(1));
        $before = $this->saved();
        try {
            $this->game->chooseOptionalLifeLoss(0, $choice['id'], 'accept');
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
        $this->assertSame([0, $incarnation, 'p:1'], [$choice['seat'], $choice['incarnation'], $choice['target']]);
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalLifeLoss(0, $choice['id'], 'accept');
        $this->assertSame([20, 19], [$this->life(0), $this->life(1)]);
    }

    /** @dataProvider moves */
    public function testOnlyActualBattlefieldArtifactBurialsTrigger(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $this->newGame();
        $this->disciple(0);
        $object = $this->game->addCard(1, ['name' => 'Burial Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $object->zone);
            $this->resolveOne();
            $this->assertSame('optional_life_loss', $this->game->decision(0));
            $this->game->chooseOptionalLifeLoss(0, $this->game->choiceAwaiting()['id'], 'accept');
        }
        $this->assertSame(20 - $count, $this->life(1));
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

    /** @dataProvider hexproofTiming */
    public function testOpponentHexproofPreventsTargetOrCountersQueuedAbility(bool $before): void
    {
        if ($before) {
            $this->newGame();
            $this->disciple(0);
        } else {
            $this->buryOne();
        }
        $shield = ['name' => 'Player Hexproof Unit Fixture', 'type' => 'Enchantment', 'text' => 'You have hexproof.'];
        $this->assertSame([], (new CardDefinition($shield))->unsupported);
        $this->game->addCard(1, $shield, GameObject::BATTLEFIELD);
        if ($before) {
            $artifact = $this->battlefield(1, 'Ornithopter');
            (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$artifact], GameObject::GRAVEYARD);
            (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        } else {
            $this->resolveOne();
        }
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(20, $this->life(1));
    }

    public static function hexproofTiming(): array
    {
        return [[true], [false]];
    }

    public function testPracticePolicyUsesTheSamePersistedChoice(): void
    {
        $this->buryOne();
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->assertTrue((new PracticeBot())->act($this->game, 0));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame(19, $this->life(1));
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
        $this->game->chooseOptionalLifeLoss(0, $this->game->choiceAwaiting()['id'], $option);
        $this->assertSame([22, $option === 'accept' ? 19 : 20], [$this->life(0), $this->life(1)]);
        $this->assertNull($this->game->choiceAwaiting());
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(22, $this->life(0));
    }

    public static function options(): array
    {
        return [['accept'], ['decline']];
    }

    public function testAStolenArtifactObserverUsesItsPreDepartureController(): void
    {
        $this->newGame();
        $card = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-observer-disciple-of-the-vault.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $card['name'] = 'Artifact Disciple Unit Fixture';
        $card['type'] = 'Artifact Creature — Human Cleric';
        $card['types'] = ['Artifact', 'Creature'];
        $object = $this->game->addCard(0, $card, GameObject::BATTLEFIELD);
        $object->controller = 1;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount(1, $this->game->stack);
        $this->assertSame(1, $this->game->stack[0]['controller']);
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->game->chooseOptionalLifeLoss(1, $this->game->choiceAwaiting()['id'], 'accept');
        $this->assertSame([19, 20], [$this->life(0), $this->life(1)]);
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
            ['schemaVersion', 7], ['nextChoiceId', 1], ['nextChoiceId', '2'], ['pendingChoice.id', 0],
            ['pendingChoice.seat', 9], ['pendingChoice.options', ['accept']], ['pendingChoice.target', 'p:0'],
            ['pendingChoice.target', 'p:9'], ['pendingChoice.target', 'p:01'], ['pendingChoice.object', -1],
            ['pendingChoice.incarnation', -1], ['pendingChoice.label', null], ['pendingChoice.resume', null],
            ['pendingChoice.resume.item', []], ['pendingChoice.resume.item.controller', 1],
            ['pendingChoice.resume.item.incarnation', 99], ['pendingChoice.resume.item.targets', ['p:0']],
            ['pendingChoice.resume.self', [1, 99]], ['pendingChoice.resume.steps', 'bad'],
            ['pendingChoice.resume.steps', [[[], null]]], ['pendingChoice.resume.followed', [0 => 1]],
        ];
    }

    public function testVersionSevenWithoutTheNewChoiceStillLoads(): void
    {
        $this->newGame();
        $saved = $this->saved();
        $saved['schemaVersion'] = 7;
        $this->assertSame(Game::STATE_VERSION, Game::fromArray($saved)->toArray()['schemaVersion']);
    }

    public function testPinnedCandidateSupersetDoesNotAdmitOtherAllPlayerBurialAbilities(): void
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/all-player-artifact-burial-candidates.json'));
        $this->assertSame('5b4683db36f12c404e3b314b1d5d5fa85246c43582d95bd624d5b8917133f20b', hash('sha256', $bytes));
        $fixture = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(8, $fixture['candidates']);
        $recognized = [];
        foreach ($fixture['candidates'] as $entry) {
            $definition = new CardDefinition($entry['card']);
            if ($definition->unsupported === []) {
                $recognized[] = $entry['card']['name'];
            } else {
                $this->assertSame([], $definition->triggersOn('artifact_to_graveyard_any'), $entry['card']['name']);
            }
        }
        $this->assertSame(['Disciple of the Vault', 'Fangren Marauder', 'Krenko, Baron of Tin Street', 'Molder Beast', 'Moriok Rigger', 'Summoning Station'], $recognized);
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
