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
final class CultivateSearchTest extends GameTestCase
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
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/cultivate-split-search.json'));
        $this->assertSame('94b6e1c982251718ab0e753b51e460a6be85ab5fb75ab89054109fb5b7841cdf', hash('sha256', $bytes));

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
    }

    private function prepare(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
    }

    private function castCultivate(int $actor, bool $laterEffect = false): int
    {
        $lands = $this->lands($actor, 'Forest', 3);
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
    public function testPaidSpellRequiresLegalSelectionAndShufflesOnFailureToFind(int $actor, int $count, int $battle): void
    {
        $this->prepare($actor);
        $land = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $second = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $source = $this->castCultivate($actor);
        $before = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('look', $choice['type'] ?? null);
        $this->assertSame($actor, $choice['seat']);
        $this->assertSame(2, $choice['take']);
        $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->game = Game::fromArray($this->saved());
        $selected = array_slice([$land, $second], 0, $count);
        $this->game->take($actor, $selected);
        if ($count === 2) {
            $this->assertSame('split', $this->game->choiceAwaiting()['rest']);
            $this->assertEqualsCanonicalizing($selected, $this->game->choiceAwaiting()['eligible']);
            $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
            $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
            $this->game = Game::fromArray($this->saved());
            $this->game->take($actor, [$selected[$battle]]);
            $this->assertSame(GameObject::HAND, $this->zone($selected[1 - $battle]));
        }
        if ($count > 0) {
            $chosen = $selected[$count === 1 ? 0 : $battle];
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($chosen));
            $this->assertTrue($this->game->objects[$chosen]->tapped);
            $this->assertSame($actor, $this->game->objects[$chosen]->controller);
        } else {
            $this->assertEqualsCanonicalizing($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        }
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNull($this->game->choiceAwaiting());
    }

    public static function selections(): array
    {
        return [[0, 0, 0], [1, 0, 0], [0, 1, 0], [1, 1, 0], [0, 2, 0], [1, 2, 0], [0, 2, 1], [1, 2, 1]];
    }
    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid split-search action accepted.');
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

    /** @dataProvider actors */
    public function testBothChoicesRejectInvalidCardsAndCanFindTwoOfTheSameName(int $actor): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $lands = [];
        foreach (['Plains', 'Island', 'Swamp', 'Mountain', 'Forest', 'Forest'] as $name) {
            $lands[] = $this->game->addCard($actor, self::card($name), GameObject::LIBRARY)->id;
        }
        $lands[] = $this->game->addCard($actor, ['name' => 'Snow Island Fixture', 'type' => 'Basic Snow Land — Island', 'types' => ['Land'], 'supertypes' => ['Basic', 'Snow']], GameObject::LIBRARY)->id;
        $lands[] = $this->game->addCard($actor, ['name' => 'Wastes', 'type' => 'Basic Land', 'types' => ['Land'], 'supertypes' => ['Basic']], GameObject::LIBRARY)->id;
        $bad = [];
        $bad[] = $this->game->addCard($actor, ['name' => 'Nonbasic Island Fixture', 'type' => 'Land — Island', 'types' => ['Land']], GameObject::LIBRARY)->id;
        $bad[] = $this->game->addCard($actor, ['name' => 'Basic Nonland Fixture', 'type' => 'Basic Creature', 'types' => ['Creature'], 'supertypes' => ['Basic'], 'power' => '1', 'toughness' => '1'], GameObject::LIBRARY)->id;
        $bad[] = $this->game->addCard(1 - $actor, self::card('Island'), GameObject::LIBRARY)->id;
        $bad[] = $this->hand($actor, 'Island');
        $this->castCultivate($actor);
        $this->resolveOne();
        $this->assertSame([], (new CardDefinition($this->fixture()))->unsupported);
        $this->assertEqualsCanonicalizing($lands, $this->game->choiceAwaiting()['eligible']);
        foreach ($bad as $id) {
            $this->reject(fn () => $this->game->take($actor, [$id]));
        }
        $this->reject(fn () => $this->game->take(1 - $actor, [$lands[4], $lands[5]]));
        $this->reject(fn () => $this->game->take($actor, array_slice($lands, 0, 3)));
        $this->reject(fn () => $this->game->pass($actor));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, 1, 'decline'));
        $this->game->take($actor, [$lands[4], $lands[5]]);
        $this->reject(fn () => $this->game->take($actor, []));
        $this->reject(fn () => $this->game->take($actor, [$lands[0]]));
        $this->reject(fn () => $this->game->take($actor, [$lands[4], $lands[5]]));
        $this->reject(fn () => $this->game->take(1 - $actor, [$lands[4]]));
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$lands[5]]);
        $after = $this->saved();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($lands[5]));
        $this->assertTrue($this->game->objects[$lands[5]]->tapped);
        $this->assertSame(GameObject::HAND, $this->zone($lands[4]));
        $this->assertSame($before['shuffles'] + 1, $after['shuffles']);
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$lands[5]]);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, []));
    }

    /** @dataProvider libraryCases */
    public function testEmptyIneligibleAndSingleEligibleLibrariesShuffleOnce(int $actor, int $eligible): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $id = $eligible > 0 ? $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id : null;
        if ($eligible === -1) {
            $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY);
        }
        $this->castCultivate($actor);
        $before = $this->saved();
        $this->resolveOne();
        if ($id !== null) {
            $this->assertSame(1, $this->game->choiceAwaiting()['take']);
            $this->game->take($actor, [$id]);
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
    }

    public static function libraryCases(): array
    {
        return [[0, -1], [1, -1], [0, 0], [1, 0], [0, 1], [1, 1]];
    }

    /** @dataProvider actors */
    public function testOriginalControllerRevealAndLaterEffectWaitForTheDestination(int $actor): void
    {
        $this->prepare($actor);
        $first = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $second = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $source = $this->castCultivate($actor, true);
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->resolveOne();
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $this->game = Game::fromArray($this->saved());
        $this->game->take($actor, [$first, $second]);
        $this->assertSame(20, $this->life($actor));
        $this->assertStringContainsString('reveals Island and Forest.', json_encode($this->saved(), JSON_THROW_ON_ERROR));
        $this->game = Game::fromArray($this->saved());
        $this->game->take($actor, [$second]);
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame($actor, $this->game->objects[$second]->controller);
        $this->assertSame(GameObject::HAND, $this->zone($first));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->reject(fn () => $this->game->take($actor, [$second]));
    }

    /** @dataProvider actors */
    public function testUnpaidOpposingAndCounteredSpellsNeverSearch(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Forest', 3);
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

    public function testPracticeCompletesBothChoicesAndOptionalWordingKeepsItsPolicy(): void
    {
        $this->prepare(0);
        $this->castCultivate(0);
        $this->resolveOne();
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertSame('split', $this->game->choiceAwaiting()['rest']);
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame('search', TextParser::effect('You may '.rtrim($this->fixture()['text'], '.'))['type']);
    }

    /** @dataProvider specialLands */
    public function testSnowAndWastesCanBeSelectedOntoTheBattlefield(int $actor, bool $snow): void
    {
        $this->prepare($actor);
        $card = ['name' => $snow ? 'Snow Island Fixture' : 'Wastes', 'types' => ['Land'], 'supertypes' => $snow ? ['Basic', 'Snow'] : ['Basic'], 'type' => $snow ? 'Basic Snow Land — Island' : 'Basic Land'];
        $id = $this->game->addCard($actor, $card, GameObject::LIBRARY)->id;
        $this->castCultivate($actor);
        $this->resolveOne();
        $this->game->take($actor, [$id]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        $this->assertTrue($this->game->objects[$id]->tapped);
    }

    public static function specialLands(): array
    {
        return [[0, false], [1, false], [0, true], [1, true]];
    }

    public function testDuplicateBasicCopiesDoNotHideOtherNamesFromTheSelector(): void
    {
        $this->prepare(0);
        $this->emptyLibrary(0);
        $island = $this->game->addCard(0, self::card('Island'), GameObject::LIBRARY)->id;
        for ($n = 0; $n < 30; $n++) {
            $this->game->addCard(0, self::card('Forest'), GameObject::LIBRARY);
        }
        $this->castCultivate(0);
        $this->resolveOne();
        $this->assertCount(3, $this->game->choiceAwaiting()['eligible']);
        $this->assertContains($island, $this->game->choiceAwaiting()['eligible']);
        $this->game->take(0, [$island]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($island));
    }

    /** @dataProvider actors */
    public function testSplitSearchSaveValidationAndLegacyLookCompatibility(int $actor): void
    {
        $this->prepare($actor);
        $first = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $second = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->castCultivate($actor);
        $this->resolveOne();
        $finding = $this->saved();
        $this->game->take($actor, [$first, $second]);
        $destination = $this->saved();
        foreach ([$finding, $destination] as $saved) {
            $this->assertSame(15, $saved['schemaVersion']);
            $this->assertSame($saved, Game::fromArray($saved)->toArray());
            $changes = [
                fn (array &$s) => $s['schemaVersion'] = 14,
                fn (array &$s) => $s['pendingChoice']['seat'] = 1 - $actor,
                fn (array &$s) => $s['pendingChoice']['take'] = 3,
                fn (array &$s) => $s['pendingChoice']['may'] = ! $s['pendingChoice']['may'],
                fn (array &$s) => $s['pendingChoice']['eligible'] = [],
                fn (array &$s) => $s['pendingChoice']['cards'] = [$first, $first],
                fn (array &$s) => $s['pendingChoice']['cards'][0] = 'invalid',
                fn (array &$s) => $s['pendingChoice']['resume']['steps'] = [['invalid']],
                fn (array &$s) => $s['pendingChoice']['resume']['item']['controller'] = 1 - $actor,
                fn (array &$s) => $s['pendingChoice']['resume']['self'] = [999999, 0],
                fn (array &$s) => $s['pendingChoice']['resume'] = null,
                fn (array &$s) => $s['objects'][$first]['zone'] = GameObject::HAND,
            ];
            foreach ($changes as $change) {
                $bad = $saved;
                $change($bad);
                try {
                    Game::fromArray($bad);
                    $this->fail('Malformed split-search state accepted.');
                } catch (GameException $exception) {
                    $this->assertStringContainsString('split land search', $exception->getMessage());
                }
            }
        }
        $legacy = $finding;
        $legacy['pendingChoice']['to'] = 'tapped';
        $legacy['pendingChoice']['take'] = 1;
        foreach ([13, 14] as $version) {
            $legacy['schemaVersion'] = $version;
            $loaded = Game::fromArray($legacy);
            $this->assertSame(15, $loaded->toArray()['schemaVersion']);
            $loaded->take($actor, [$first]);
            $this->assertSame(GameObject::BATTLEFIELD, $loaded->objects[$first]->zone);
            $this->assertTrue($loaded->objects[$first]->tapped);
        }
    }
}
