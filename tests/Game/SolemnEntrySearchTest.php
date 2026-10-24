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
final class SolemnEntrySearchTest extends GameTestCase
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

    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/solemn-simulacrum-optional-draw.json'));
        $this->assertSame('9a79f3ccf7bd725f6d3c326e166ab68872235caa5bd4df8b47e95b1017b940e0', hash('sha256', $bytes));

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
    }

    private function prepare(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
    }

    private function castSolemn(int $actor): int
    {
        $lands = $this->lands($actor, 'Plains', 4);
        $id = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->game->cast($actor, $id);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertCount(1, $this->game->stack, 'The paid complete card must queue its entry trigger.');

        return $id;
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('An invalid search action was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    private function emptyLibrary(int $seat): void
    {
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        foreach ($this->game->players[$seat]->library as $id) {
            $move->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
    }

    private function answer(int $actor, string $option): void
    {
        $this->game->chooseOptionalBasicLandSearch($actor, $this->game->choiceAwaiting()['id'], $option);
    }

    /** @dataProvider options */
    public function testPaidEntryCanSearchOrDeclineWithoutShuffling(int $actor, string $option): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $this->castSolemn($actor);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('optional_basic_land_search', $choice['type'] ?? null);
        $this->assertSame($actor, $choice['seat']);
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], $option);
        if ($option === 'accept') {
            $this->assertSame('look', $this->game->choiceAwaiting()['type']);
            $this->game->take($actor, [$land]);
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
            $this->assertTrue($this->game->objects[$land]->tapped);
            $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        } else {
            $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
            $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        }
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider actors */
    public function testEligibilityTappedEntryAndReplayUseTheExistingTutor(int $actor): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $eligible = [];
        foreach (['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'] as $name) {
            $eligible[] = $this->game->addCard($actor, self::card($name), GameObject::LIBRARY)->id;
        }
        $snow = $this->game->addCard($actor, array_replace(self::card('Island'), ['name' => 'Snow-Covered Island', 'supertypes' => ['Basic', 'Snow'], 'type' => 'Basic Snow Land — Island']), GameObject::LIBRARY)->id;
        $eligible[] = $snow;
        $wastes = $this->game->addCard($actor, ['name' => 'Wastes', 'type' => 'Basic Land', 'types' => ['Land'], 'supertypes' => ['Basic'], 'text' => '{T}: Add {C}.'], GameObject::LIBRARY)->id;
        $eligible[] = $wastes;
        $bad = [];
        $bad[] = $this->game->addCard($actor, ['name' => 'Nonbasic Island Fixture', 'type' => 'Land — Island', 'types' => ['Land'], 'subtypes' => ['Island'], 'supertypes' => [], 'text' => ''], GameObject::LIBRARY)->id;
        $bad[] = $this->game->addCard($actor, ['name' => 'Basic Nonland Fixture', 'type' => 'Basic Creature — Island', 'types' => ['Creature'], 'supertypes' => ['Basic'], 'subtypes' => ['Island'], 'power' => '1', 'toughness' => '1', 'text' => ''], GameObject::LIBRARY)->id;
        $bad[] = $this->game->addCard(1 - $actor, self::card('Island'), GameObject::LIBRARY)->id;
        $bad[] = $this->hand($actor, 'Island');
        $this->castSolemn($actor);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch(1 - $actor, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'] + 1, 'accept'));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'] - 1, 'decline'));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], 'both'));
        $this->reject(fn () => $this->game->chooseOptionalDeathDraw($actor, $choice['id'], 'accept'));
        $this->reject(fn () => $this->game->pass($actor));
        $this->answer($actor, 'accept');
        $this->assertEqualsCanonicalizing($eligible, $this->game->choiceAwaiting()['eligible']);
        $this->assertSame([], (new CardDefinition($this->fixture()))->unsupported);
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], 'accept'));
        foreach ($bad as $id) {
            $this->reject(fn () => $this->game->take($actor, [$id]));
        }
        $this->reject(fn () => $this->game->take(1 - $actor, [$snow]));
        $this->reject(fn () => $this->game->take($actor, [$snow, $wastes]));
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$snow]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($snow));
        $this->assertTrue($this->game->objects[$snow]->tapped);
        $this->assertSame($actor, $this->game->objects[$snow]->controller);
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame($before['players'][1 - $actor]['library'], $this->game->players[1 - $actor]->library);
        $this->assertEqualsCanonicalizing(array_values(array_diff($before['players'][$actor]['library'], [$snow])), $this->game->players[$actor]->library);
        $after = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$snow]);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, [$snow]));
    }

    /** @dataProvider options */
    public function testDeclineAndFailingToFindHaveDifferentShuffleSemantics(int $actor, string $option): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->castSolemn($actor);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $before = $this->saved();
        $this->answer($actor, $option);
        if ($option === 'accept') {
            $this->game = Game::fromArray($this->saved());
            $this->game->take($actor, []);
        }
        $after = $this->saved();
        $this->assertSame(GameObject::LIBRARY, $this->zone($land));
        $this->assertSame($before['shuffles'] + ($option === 'accept' ? 1 : 0), $after['shuffles']);
        $this->assertEqualsCanonicalizing($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        if ($option === 'decline') {
            $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
            $this->assertSame(array_values(array_filter($before['log'], fn (string $line) => str_contains($line, 'searches their library'))), array_values(array_filter($after['log'], fn (string $line) => str_contains($line, 'searches their library'))));
        }
        $this->game = Game::fromArray($before);
        $this->answer($actor, $option);
        if ($option === 'accept') {
            $this->game->take($actor, []);
        }
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, $choice['id'], $option));
    }

    /** @dataProvider libraries */
    public function testEmptyOrNoEligibleSearchStillShuffles(int $actor, bool $empty): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        if (! $empty) {
            $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY);
        }
        $this->castSolemn($actor);
        $this->resolveOne();
        $before = $this->saved();
        $this->answer($actor, 'accept');
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertFalse($this->game->players[$actor]->drewFromEmpty);
    }

    /** @dataProvider actors */
    public function testPaidSourceDestructionDoesNotCancelEntrySearch(int $actor): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $source = $this->castSolemn($actor);
        $red = $this->lands($actor, 'Mountain', 1)[0];
        $smelt = json_decode(file_get_contents(__DIR__.'/../Fixtures/artifact-burial-smelt.json'), true, flags: JSON_THROW_ON_ERROR)['card'];
        $this->game->cast($actor, $this->game->addCard($actor, $smelt, GameObject::HAND)->id, 0, ['o:'.$source]);
        $this->assertTrue($this->game->objects[$red]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->resolveOne();
        $this->game->chooseOptionalDeathDraw($actor, $this->game->choiceAwaiting()['id'], 'decline');
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $this->answer($actor, 'accept');
        $this->game->take($actor, [$land]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    /** @dataProvider options */
    public function testOriginalControllerAndLaterEffectsSurviveSourceRelocation(int $actor, string $option): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $source = $this->castSolemn($actor);
        $this->game->stack[array_key_last($this->game->stack)]['effects'][] = ['type' => 'gain_life', 'amount' => 2];
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->resolveOne();
        $this->assertSame(20, $this->life($actor));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$source], GameObject::EXILE);
        $this->game = Game::fromArray($this->saved());
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $this->answer($actor, $option);
        if ($option === 'accept') {
            $this->assertSame(20, $this->life($actor));
            $this->game = Game::fromArray($this->saved());
            $this->game->take($actor, [$land]);
            $this->assertSame($actor, $this->game->objects[$land]->controller);
        }
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $this->game = Game::fromArray($this->saved());
        $this->assertSame(22, $this->life($actor));
    }

    /** @dataProvider actors */
    public function testCounteredCastAndUnpaidOrOpposingCastNeverSearch(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Plains', 4);
        $this->reject(fn () => $this->game->cast(1 - $actor, $source));
        $this->game->cast($actor, $source);
        $this->game->pass($actor);
        $this->lands(1 - $actor, 'Island', 2);
        $this->game->cast(1 - $actor, $this->hand(1 - $actor, 'Counterspell'), 0, ['s:'.$this->game->stack[0]['id']]);
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame([], $this->game->stack);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
    }

    public function testPracticeSearchesThenUsesExistingLegalSelection(): void
    {
        $this->prepare(0);
        $this->emptyLibrary(0);
        $land = $this->game->addCard(0, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->castSolemn(0);
        $this->resolveOne();
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame('look', $this->game->choiceAwaiting()['type']);
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
        $this->assertTrue($this->game->objects[$land]->tapped);
    }

    /** @dataProvider malformed */
    public function testMalformedSavedSearchesAreRejected(string $key, mixed $value): void
    {
        $this->prepare(0);
        $this->castSolemn(0);
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
        $cases = SummoningStationChoiceTest::malformed();
        $cases[0] = ['schemaVersion', 13];
        $cases[] = ['schemaVersion', Game::STATE_VERSION + 1];

        return $cases;
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    /** @dataProvider nearbyWording */
    public function testNearbyUntestedWordingRemainsUnread(string $from, string $to): void
    {
        $card = $this->fixture();
        $card['text'] = str_replace($from, $to, $card['text']);
        $definition = new CardDefinition($card);
        $this->assertNotEmpty($definition->unsupported);
        $this->assertSame([], $definition->triggersOn('enters'));
    }

    public static function nearbyWording(): array
    {
        return [['basic land', 'basic Island'], ['basic land', 'basic snow land'], ['battlefield tapped', 'battlefield untapped']];
    }

    public static function libraries(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
    }

    public static function options(): array
    {
        return [[0, 'accept'], [0, 'decline'], [1, 'accept'], [1, 'decline']];
    }
}
