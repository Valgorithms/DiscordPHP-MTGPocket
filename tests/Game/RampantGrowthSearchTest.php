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
use MTGPocket\Tutorial\PracticeBot;
use MTGPocket\Game\TextParser;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** @covers \MTGPocket\Game\Game */
final class RampantGrowthSearchTest extends GameTestCase
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
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/rampant-growth-basic-search.json'));
        $this->assertSame('832ad0e5a3f2ae3abd5bf678974c26f216e385d5ee2836e99df553637ec2aba3', hash('sha256', $bytes));

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
    }

    private function prepare(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
    }

    private function castGrowth(int $actor, bool $laterEffect = false): int
    {
        $lands = $this->lands($actor, 'Forest', 2);
        $card = $this->fixture();
        if ($laterEffect) {
            $card['text'] .= ' You gain 2 life.';
        }
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($lands as $land) {
            $this->assertTrue($this->game->objects[$land]->tapped);
        }
        $this->game = Game::fromArray($this->saved());

        return $source;
    }

    /** @dataProvider selections */
    public function testPaidSpellRequiresLegalSelectionAndShufflesOnFailureToFind(int $actor, bool $find): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $source = $this->castGrowth($actor);
        $before = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('look', $choice['type'] ?? null);
        $this->assertSame($actor, $choice['seat']);
        $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->game = Game::fromArray($this->saved());
        $this->game->take($actor, $find ? [$land] : []);
        if ($find) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
            $this->assertTrue($this->game->objects[$land]->tapped);
            $this->assertSame($actor, $this->game->objects[$land]->controller);
        } else {
            $this->assertEqualsCanonicalizing($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        }
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function selections(): array
    {
        return [[0, false], [0, true], [1, false], [1, true]];
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

    private function emptyLibrary(int $actor): void
    {
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        foreach ($this->game->players[$actor]->library as $id) {
            $move->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
    }

    /** @dataProvider landSelections */
    public function testSelectionEligibilityAndInvalidAnswersRemainAtomic(int $actor, string $selected): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $eligible = [];
        foreach (['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'] as $name) {
            $eligible[$name] = $this->game->addCard($actor, self::card($name), GameObject::LIBRARY)->id;
        }
        $eligible['Snow-Covered Island'] = $this->game->addCard($actor, array_replace(self::card('Island'), ['name' => 'Snow-Covered Island', 'supertypes' => ['Basic', 'Snow'], 'type' => 'Basic Snow Land — Island']), GameObject::LIBRARY)->id;
        $eligible['Wastes'] = $this->game->addCard($actor, ['name' => 'Wastes', 'type' => 'Basic Land', 'types' => ['Land'], 'supertypes' => ['Basic'], 'text' => '{T}: Add {C}.'], GameObject::LIBRARY)->id;
        $bad = [];
        $bad[] = $this->game->addCard($actor, ['name' => 'Nonbasic Island Fixture', 'type' => 'Land — Island', 'types' => ['Land'], 'subtypes' => ['Island'], 'text' => ''], GameObject::LIBRARY)->id;
        $bad[] = $this->game->addCard($actor, ['name' => 'Basic Nonland Fixture', 'type' => 'Basic Creature — Island', 'types' => ['Creature'], 'supertypes' => ['Basic'], 'power' => '1', 'toughness' => '1', 'text' => ''], GameObject::LIBRARY)->id;
        $bad[] = $this->game->addCard(1 - $actor, self::card('Island'), GameObject::LIBRARY)->id;
        $bad[] = $this->hand($actor, 'Island');
        $this->castGrowth($actor);
        $this->resolveOne();
        $this->assertEqualsCanonicalizing(array_values($eligible), $this->game->choiceAwaiting()['eligible']);
        $this->assertSame([], (new CardDefinition($this->fixture()))->unsupported);
        foreach ($bad as $id) {
            $this->reject(fn () => $this->game->take($actor, [$id]));
        }
        $land = $eligible[$selected];
        $this->reject(fn () => $this->game->take(1 - $actor, [$land]));
        $this->reject(fn () => $this->game->take($actor, array_values($eligible)));
        $this->reject(fn () => $this->game->pass($actor));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, 1, 'decline'));
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$land]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
        $this->assertTrue($this->game->objects[$land]->tapped);
        $this->assertSame($actor, $this->game->objects[$land]->controller);
        $this->assertSame($before['players'][1 - $actor]['library'], $this->game->players[1 - $actor]->library);
        $this->assertEqualsCanonicalizing(array_values(array_diff($before['players'][$actor]['library'], [$land])), $this->game->players[$actor]->library);
        $after = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$land]);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, [$land]));
    }

    public static function landSelections(): array
    {
        return [[0, 'Island'], [1, 'Island'], [0, 'Snow-Covered Island'], [1, 'Snow-Covered Island'], [0, 'Wastes'], [1, 'Wastes']];
    }

    /** @dataProvider selections */
    public function testEmptyAndIneligibleLibrariesStillShuffle(int $actor, bool $empty): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        if (! $empty) {
            $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY);
        }
        $source = $this->castGrowth($actor);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertEqualsCanonicalizing($before['players'][$actor]['library'], $this->game->players[$actor]->library);
    }

    /** @dataProvider selections */
    public function testOriginalControllerAndLaterEffectsSurviveSaveLoad(int $actor, bool $find): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $source = $this->castGrowth($actor, true);
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->resolveOne();
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $this->assertSame(20, $this->life($actor));
        $this->game = Game::fromArray($this->saved());
        $this->game->take($actor, $find ? [$land] : []);
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $after = $this->saved();
        $this->game = Game::fromArray($after);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, []));
    }

    /** @dataProvider actors */
    public function testUnpaidOpposingAndCounteredCastsNeverSearch(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Forest', 2);
        $this->reject(fn () => $this->game->cast(1 - $actor, $source));
        $this->game->cast($actor, $source);
        $this->game->pass($actor);
        $this->lands(1 - $actor, 'Island', 2);
        $this->game->cast(1 - $actor, $this->hand(1 - $actor, 'Counterspell'), 0, ['s:'.$this->game->stack[0]['id']]);
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public function testPracticeUsesExistingSelectionAndExactWordingDoesNotBroadenLegacySearches(): void
    {
        $this->prepare(0);
        $this->emptyLibrary(0);
        $land = $this->game->addCard(0, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->castGrowth(0);
        $this->resolveOne();
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($land));
        $this->assertTrue($this->game->objects[$land]->tapped);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertNull(TextParser::effect('You may search your library for a basic land card, put that card onto the battlefield tapped, then shuffle'));
        $this->assertNull(TextParser::effect('Search your library for a basic land card, put that card onto the battlefield, then shuffle'));
        $legacy = TextParser::effect('You may search your library for a basic land card, put it onto the battlefield tapped, then shuffle');
        $this->assertSame('search', $legacy['type']);
    }
}
