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
final class QuestGravelordDeathCountersTest extends GameTestCase
{
    private function cardData(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-gravelord-death-counters.json'));
        $this->assertSame('c26e0bf5385b4bbf8ad5178f31263bfaf6f03f9391ddcbdc13c5cb3222924300', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'];

        return array_values(array_filter($cards, fn (array $card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function quest(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $mana = $this->lands($actor, 'Swamp', 1)[0];
        $card = $this->cardData('Quest for the Gravelord');
        $this->assertSame('3c15b10c-d09c-5c93-be42-98ff3515262a', $card['uuid']);
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function death(int $actor, int $victimController, bool $resolveCounter = true): void
    {
        $victim = $this->game->addCard($victimController, self::card('Ornithopter'), GameObject::BATTLEFIELD)->id;
        $mana = $this->lands($actor, 'Swamp', 1)[0];
        $spell = $this->game->addCard($actor, $this->cardData('Disfigure'), GameObject::HAND)->id;
        $this->game->cast($actor, $spell, 0, ["o:{$victim}"]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        if ($resolveCounter) {
            $this->resolveOne();
        }
    }

    /** @dataProvider deaths */
    public function testPaidCreatureDeathOffersOptionalQuestCounter(int $actor, int $victimController): void
    {
        $source = $this->quest($actor);
        $this->death($actor, $victimController);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'), 'A may-counter must wait for its controller to accept.');
        $choice = $this->game->choiceAwaiting();
        $this->assertNotNull($choice, 'A real paid creature death must offer the optional quest counter.');
        $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'decline');
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
    }

    public static function deaths(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('An invalid action must reject atomically.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider actors */
    public function testThreeEarnedCountersPayForZombieAndReplayActivation(int $actor): void
    {
        $source = $this->quest($actor);
        for ($n = 1; $n <= 3; $n++) {
            $this->reject(fn () => $this->game->activate($actor, $source, 0));
            $this->death($actor, $n % 2);
            $choice = $this->game->choiceAwaiting();
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
            $this->assertSame($n, $this->game->objects[$source]->counter('quest'));
            $this->assertSame(0, $this->game->objects[$source]->counter('+1/+1'));
        }
        $before = $this->game->battlefield;
        $this->game->activate($actor, $source, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $queued = $this->saved();
        $this->resolveOne();
        $expected = $this->saved();
        $made = array_values(array_diff($this->game->battlefield, $before));
        $this->assertCount(1, $made);
        $token = $this->game->objects[$made[0]];
        $this->assertTrue($token->definition()->isToken());
        $this->assertSame([$actor, $actor], [$token->owner, $token->controller]);
        $this->assertSame([5, 5], [$this->game->power($token), $this->game->toughness($token)]);
        $this->assertSame(['Zombie', 'Giant'], $token->definition()->subtypes);
        $this->assertSame(['B'], $token->definition()->colors);
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($expected, $this->saved());
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
    }

    /** @dataProvider deaths */
    public function testSavedChoiceKeepsOriginalControllerAndRejectsInvalidAnswers(int $actor, int $victimController): void
    {
        $source = $this->quest($actor);
        $this->death($actor, $victimController, false);
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($actor, $choice['seat']);
        $this->game = Game::fromArray($this->saved());
        $pending = $this->saved();
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter(1 - $actor, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'invalid'));
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
        $this->assertSame(1 - $actor, $this->game->objects[$source]->controller);
        $expected = $this->saved();
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept'));
        $this->game = Game::fromArray($pending);
        $this->assertTrue(PracticeBot::act($this->game, $actor));
        $this->assertSame($expected, $this->saved());
    }

    /** @dataProvider incarnations */
    public function testDepartedOrReturnedSourceCannotReceiveAnOldCounter(int $actor, bool $pending, bool $returns): void
    {
        $source = $this->quest($actor);
        $this->death($actor, 1 - $actor, $pending);
        $object = $this->game->objects[$source];
        $incarnation = $object->incarnation;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::EXILE);
        if ($returns) {
            (new \ReflectionMethod(Game::class, 'putOntoBattlefield'))->invoke($this->game, $object, $actor);
        }
        $this->assertGreaterThan($incarnation, $object->incarnation);
        $this->game = Game::fromArray($this->saved());
        if (! $pending) {
            $this->resolveOne();
        }
        $choice = $this->game->choiceAwaiting();
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertSame($returns ? GameObject::BATTLEFIELD : GameObject::EXILE, $this->zone($source));
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    /** @dataProvider movements */
    public function testOnlyActualCreatureDeathsOfferQuestCounters(string $type, string $from, string $to, bool $token, bool $unearthed, int $count): void
    {
        $source = $this->quest(0);
        $object = $this->game->addCard(1, ['name' => 'Death Movement Unit Fixture', 'type' => $type, 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->unearthed = $unearthed;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        if ($count === 1) {
            $this->assertSame($token ? GameObject::GONE : GameObject::GRAVEYARD, $object->zone);
            $this->resolveOne();
            $this->game->chooseOptionalSourceCounter(0, $this->game->choiceAwaiting()['id'], 'accept');
        }
        $this->assertSame($count, $this->game->objects[$source]->counter('quest'));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function movements(): array
    {
        return [
            ['Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 1],
            ['Token Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, true, false, 1],
            ['Artifact Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 1],
            ['Creature', GameObject::HAND, GameObject::GRAVEYARD, false, false, 0],
            ['Creature', GameObject::BATTLEFIELD, GameObject::EXILE, false, false, 0],
            ['Creature', GameObject::BATTLEFIELD, GameObject::HAND, false, false, 0],
            ['Artifact', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 0],
            ['Land', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 0],
            ['Creature', GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, true, 0],
        ];
    }

    public static function incarnations(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $pending) {
                foreach ([false, true] as $returns) {
                    $cases[] = [$actor, $pending, $returns];
                }
            }
        }

        return $cases;
    }
}
