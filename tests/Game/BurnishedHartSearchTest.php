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

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;
use MTGPocket\Game\TextParser;
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class BurnishedHartSearchTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/burnished-hart-land-search.json'));
        $this->assertSame('46b9c6b35fc83b400df52913d4aeeddaed85750df15f19f1a398abd1c283ec46', hash('sha256', $bytes));

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['card'];
    }

    private function prepare(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function castHart(int $actor, bool $laterEffect = false): int
    {
        $mana = $this->lands($actor, 'Forest', 3);
        $card = $this->fixture();
        if ($laterEffect) {
            $card['text'] .= ' You gain 2 life.';
        }
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function activateHart(int $actor, int $source): void
    {
        $mana = $this->lands($actor, 'Forest', 3);
        $this->game->activate($actor, $source, 0);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->game = Game::fromArray($this->saved());
    }

    private function emptyLibrary(int $actor): void
    {
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        foreach ($this->game->players[$actor]->library as $id) {
            $move->invoke($this->game, $this->game->objects[$id], GameObject::EXILE);
        }
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('An invalid Burnished Hart action was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider selections */
    public function testPaidCastAndSacrificeAllowZeroOneOrTwoFindsAndOneShuffle(int $actor, int $count): void
    {
        $this->prepare($actor);
        $a = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $b = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $source = $this->castHart($actor);
        $this->activateHart($actor, $source);
        $before = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('look', $choice['type']);
        $this->assertSame($actor, $choice['seat']);
        $this->assertSame(2, $choice['take']);
        $this->assertTrue($choice['may']);
        $this->assertSame('tapped', $choice['to']);
        $this->assertSame($before['players'][$actor]['library'], $this->game->players[$actor]->library);
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->game = Game::fromArray($this->saved());
        $pending = $this->saved();
        $selected = array_slice([$a, $b], 0, $count);
        $this->game->take($actor, $selected);
        foreach ([$a, $b] as $id) {
            $found = in_array($id, $selected, true);
            $this->assertSame($found ? GameObject::BATTLEFIELD : GameObject::LIBRARY, $this->zone($id));
            if ($found) {
                $this->assertTrue($this->game->objects[$id]->tapped);
                $this->assertSame($actor, $this->game->objects[$id]->controller);
            }
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $after = $this->saved();
        $this->game = Game::fromArray($after);
        $this->assertSame($after, $this->saved());
        $this->game = Game::fromArray($pending);
        $this->game->take($actor, $selected);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, $selected));
    }

    public static function selections(): array
    {
        return [[0, 0], [1, 0], [0, 1], [1, 1], [0, 2], [1, 2]];
    }

    /** @dataProvider actors */
    public function testTwoCopiesAreSelectableAndWrongSeatZoneTypeAndOverselectionAreAtomic(int $actor): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $a = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $b = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $c = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $opposing = $this->game->addCard(1 - $actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $hand = $this->hand($actor, 'Forest');
        $nonbasic = $this->game->addCard($actor, ['name' => 'Nonbasic Forest fixture', 'type' => 'Land — Forest', 'types' => ['Land'], 'subtypes' => ['Forest']], GameObject::LIBRARY)->id;
        $nonland = $this->game->addCard($actor, ['name' => 'Basic nonland fixture', 'type' => 'Basic Creature', 'supertypes' => ['Basic'], 'types' => ['Creature'], 'power' => '1', 'toughness' => '1'], GameObject::LIBRARY)->id;
        $source = $this->castHart($actor);
        $this->activateHart($actor, $source);
        $this->resolveOne();
        $this->assertEqualsCanonicalizing([$a, $b, $c], $this->game->choiceAwaiting()['eligible']);
        foreach ([[$opposing], [$hand], [$nonbasic], [$nonland], [$source], [999999], [$a, $b, $c]] as $ids) {
            $this->reject(fn () => $this->game->take($actor, $ids));
        }
        $this->reject(fn () => $this->game->take(1 - $actor, [$a]));
        $this->reject(fn () => $this->game->pass($actor));
        $this->reject(fn () => $this->game->chooseMode($actor, 0));
        $this->game->take($actor, [$a, $b]);
        $this->assertTrue($this->game->objects[$a]->tapped);
        $this->assertTrue($this->game->objects[$b]->tapped);
        $this->assertSame(GameObject::LIBRARY, $this->zone($c));
    }

    /** @dataProvider libraryCases */
    public function testEmptyIneligibleAndSingleEligibleLibrariesStillShuffleOnce(int $actor, int $eligible): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $id = $eligible < 0 ? null : $this->game->addCard($actor, self::card($eligible === 1 ? 'Island' : 'Grizzly Bears'), GameObject::LIBRARY)->id;
        $source = $this->castHart($actor);
        $this->activateHart($actor, $source);
        $before = $this->saved();
        $this->resolveOne();
        if ($eligible === 1) {
            $this->assertSame(1, $this->game->choiceAwaiting()['take']);
            $this->assertSame([$id], $this->game->choiceAwaiting()['eligible']);
            $this->game->take($actor, [$id]);
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
    }

    public static function libraryCases(): array
    {
        return [[0, -1], [1, -1], [0, 0], [1, 0], [0, 1], [1, 1]];
    }

    /** @dataProvider actors */
    public function testUnpaidOpposingAndRepeatedActivationsLeaveStateUnchanged(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->reject(fn () => $this->game->cast(1 - $actor, $source));
        $this->lands($actor, 'Forest', 3);
        $this->game->cast($actor, $source);
        $this->resolveOne();
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));
        $this->activateHart($actor, $source);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
        $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0));
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider actors */
    public function testQueuedAbilityKeepsOriginalControllerAndLaterEffectWaitsForSelection(int $actor): void
    {
        $this->prepare($actor);
        $island = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $source = $this->castHart($actor, true);
        $this->activateHart($actor, $source);
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->resolveOne();
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $this->assertSame(20, $this->life($actor));
        $this->game = Game::fromArray($this->saved());
        $this->game->take($actor, [$island]);
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame($actor, $this->game->objects[$island]->controller);
    }

    /** @dataProvider actors */
    public function testCounteredCreatureNeverOffersAnActivationOrSearch(int $actor): void
    {
        $this->prepare($actor);
        $this->lands($actor, 'Forest', 3);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->game->pass($actor);
        $this->lands(1 - $actor, 'Island', 2);
        $this->game->cast(1 - $actor, $this->hand(1 - $actor, 'Counterspell'), 0, ['s:'.$this->game->stack[0]['id']]);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->reject(fn () => $this->game->activate($actor, $source, 0));
    }

    /** @dataProvider actors */
    public function testSnowAndWastesAreBasicLands(int $actor): void
    {
        $this->prepare($actor);
        $ids = [];
        foreach ([['name' => 'Snow Island fixture', 'type' => 'Basic Snow Land — Island', 'types' => ['Land'], 'supertypes' => ['Basic', 'Snow']], ['name' => 'Wastes', 'type' => 'Basic Land', 'types' => ['Land'], 'supertypes' => ['Basic']]] as $card) {
            $ids[] = $this->game->addCard($actor, $card, GameObject::LIBRARY)->id;
        }
        $source = $this->castHart($actor);
        $this->activateHart($actor, $source);
        $this->resolveOne();
        $this->game->take($actor, $ids);
        foreach ($ids as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public function testDuplicateNamesDoNotCrowdOutOtherChoicesAndPracticeUsesExistingRoute(): void
    {
        $this->prepare(0);
        $this->emptyLibrary(0);
        $island = $this->game->addCard(0, self::card('Island'), GameObject::LIBRARY)->id;
        for ($n = 0; $n < 30; $n++) {
            $this->game->addCard(0, self::card('Forest'), GameObject::LIBRARY);
        }
        $source = $this->castHart(0);
        $this->activateHart(0, $source);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertCount(3, $choice['eligible']);
        $this->assertContains($island, $choice['eligible']);
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(2, count(array_filter($choice['eligible'], fn (int $id) => $this->zone($id) === GameObject::BATTLEFIELD)));
    }

    public function testOptionalAndOtherLegacyWordingsKeepTheirPolicy(): void
    {
        $text = 'Search your library for up to two basic land cards, put them onto the battlefield tapped, then shuffle';
        $this->assertSame(['type' => 'tutor', 'filter' => 'basic_land', 'count' => 2, 'to' => 'tapped', 'reveal' => false], TextParser::effect($text));
        foreach (['You may '.$text, str_replace('two', 'three', $text), str_replace('onto the battlefield tapped', 'into your hand', $text), str_replace('put them', 'reveal them, put them', $text)] as $legacy) {
            $this->assertSame('search', TextParser::effect($legacy)['type']);
        }
    }
}
