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
final class HarrowSearchTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/harrow-land-cost-search.json'));
        $this->assertSame('bf53c1c1adcaf45decf15aea7f8f6e3deacad70e492e36adf36bbc45dea81a53', hash('sha256', $bytes));

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

    private function castHarrow(int $actor, bool $laterEffect = false): int
    {
        $mana = $this->lands($actor, 'Forest', 3);
        $opposing = $this->lands(1 - $actor, 'Forest', 1)[0];
        $card = $this->fixture();
        if ($laterEffect) {
            $card['text'] .= ' You gain 2 life.';
        }
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            // Moving the sacrificed land clears its tapped status.
            $this->assertSame($this->zone($id) === GameObject::BATTLEFIELD, $this->game->objects[$id]->tapped);
        }
        $this->assertCount(1, array_filter($mana, fn (int $id) => $this->zone($id) === GameObject::GRAVEYARD));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($opposing));
        $this->assertFalse($this->game->objects[$opposing]->tapped);
        $this->assertSame(GameObject::STACK, $this->zone($source));
        $this->game = Game::fromArray($this->saved());

        return $source;
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
            $this->fail('An invalid Harrow action was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider selections */
    public function testPaidCastAndLandCostAllowZeroOneOrTwoUntappedFindsAndOneShuffle(int $actor, int $count): void
    {
        $this->prepare($actor);
        $a = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $b = $this->game->addCard($actor, self::card('Forest'), GameObject::LIBRARY)->id;
        $source = $this->castHarrow($actor);
        $before = $this->saved();
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('look', $choice['type']);
        $this->assertSame($actor, $choice['seat']);
        $this->assertSame(2, $choice['take']);
        $this->assertTrue($choice['may']);
        $this->assertSame('battlefield', $choice['to']);
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
                $this->assertFalse($this->game->objects[$id]->tapped);
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
        $source = $this->castHarrow($actor);
        $this->resolveOne();
        $this->assertEqualsCanonicalizing([$a, $b, $c], $this->game->choiceAwaiting()['eligible']);
        foreach ([[$opposing], [$hand], [$nonbasic], [$nonland], [$source], [999999], [$a, $b, $c]] as $ids) {
            $this->reject(fn () => $this->game->take($actor, $ids));
        }
        $this->reject(fn () => $this->game->take(1 - $actor, [$a]));
        $this->reject(fn () => $this->game->pass($actor));
        $this->reject(fn () => $this->game->chooseMode($actor, 0));
        $this->game->take($actor, [$a, $b]);
        $this->assertFalse($this->game->objects[$a]->tapped);
        $this->assertFalse($this->game->objects[$b]->tapped);
        $this->assertSame(GameObject::LIBRARY, $this->zone($c));
    }

    /** @dataProvider libraryCases */
    public function testEmptyIneligibleAndSingleEligibleLibrariesStillShuffleOnce(int $actor, int $eligible): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $id = $eligible < 0 ? null : $this->game->addCard($actor, self::card($eligible === 1 ? 'Island' : 'Grizzly Bears'), GameObject::LIBRARY)->id;
        $source = $this->castHarrow($actor);
        $before = $this->saved();
        $this->resolveOne();
        if ($eligible === 1) {
            $this->assertSame(1, $this->game->choiceAwaiting()['take']);
            $this->assertSame([$id], $this->game->choiceAwaiting()['eligible']);
            $this->game->take($actor, [$id]);
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
            $this->assertFalse($this->game->objects[$id]->tapped);
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
    public function testUnpaidWrongColorOpposingAndRepeatedCastsLeaveStateUnchanged(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->reject(fn () => $this->game->cast(1 - $actor, $source));
        $this->lands($actor, 'Island', 3);
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Forest', 1);
        $this->game->cast($actor, $source);
        $this->assertSame(GameObject::STACK, $this->zone($source));
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->reject(fn () => $this->game->cast(1 - $actor, $source));
        $this->assertNull($this->game->choiceAwaiting());
    }

    /** @dataProvider actors */
    public function testFloatingManaCannotReplaceControlledLandAndBorrowedLandPaysItsOwner(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $opposing = $this->lands(1 - $actor, 'Forest', 1)[0];
        $this->hand($actor, 'Forest');
        $this->game->addCard($actor, self::card('Forest'), GameObject::GRAVEYARD);
        $nonland = $this->game->addCard($actor, ['name' => 'Nonland cost fixture', 'type' => 'Artifact', 'types' => ['Artifact']], GameObject::BATTLEFIELD)->id;
        $this->game->players[$actor]->manaPool->add('G', 3);
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->game->objects[$opposing]->controller = $actor;
        $this->game->cast($actor, $source);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($opposing));
        $this->assertContains($opposing, $this->game->players[1 - $actor]->graveyard);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($nonland));
        $this->assertSame(0, $this->game->players[$actor]->manaPool->total());
        $this->assertSame(GameObject::STACK, $this->zone($source));
    }

    /** @dataProvider actors */
    public function testQueuedSpellKeepsOriginalControllerAndLaterEffectWaitsForSelection(int $actor): void
    {
        $this->prepare($actor);
        $island = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $source = $this->castHarrow($actor, true);
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
    public function testCounteredHarrowDoesNotRefundLandOrOfferSearch(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->castHarrow($actor);
        $sacrificed = $this->game->players[$actor]->graveyard;
        $this->game->pass($actor);
        $this->lands(1 - $actor, 'Island', 2);
        $this->game->cast(1 - $actor, $this->hand(1 - $actor, 'Counterspell'), 0, ['s:'.$this->game->stack[0]['id']]);
        $before = $this->saved();
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        foreach ($sacrificed as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->reject(fn () => $this->game->cast($actor, $source));
    }

    /** @dataProvider actors */
    public function testSyntheticAlternativeManaCostIsUsedOnlyWithoutAControlledLand(int $actor): void
    {
        foreach ([false, true] as $hasLand) {
            $this->prepare($actor);
            $card = $this->fixture();
            $card['text'] = str_replace('sacrifice a land.', 'sacrifice a land or pay {3}.', $card['text']);
            $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
            $this->game->players[$actor]->manaPool->add('G', 3);
            if ($hasLand) {
                $land = $this->lands($actor, 'Forest', 1)[0];
                $this->game->objects[$land]->tapped = true;
            } else {
                $this->reject(fn () => $this->game->cast($actor, $source));
                $this->game->players[$actor]->manaPool->add('G', 3);
            }
            $this->game->cast($actor, $source);
            $this->assertSame(GameObject::STACK, $this->zone($source));
            $this->assertCount($hasLand ? 1 : 0, $this->game->players[$actor]->graveyard);
            $this->assertSame(0, $this->game->players[$actor]->manaPool->total());
            if ($hasLand) {
                $this->assertSame(GameObject::GRAVEYARD, $this->zone($land));
            }
        }
    }

    /** @dataProvider actors */
    public function testSnowAndWastesAreBasicLands(int $actor): void
    {
        $this->prepare($actor);
        $ids = [];
        foreach ([['name' => 'Snow Island fixture', 'type' => 'Basic Snow Land — Island', 'types' => ['Land'], 'supertypes' => ['Basic', 'Snow']], ['name' => 'Wastes', 'type' => 'Basic Land', 'types' => ['Land'], 'supertypes' => ['Basic']]] as $card) {
            $ids[] = $this->game->addCard($actor, $card, GameObject::LIBRARY)->id;
        }
        $source = $this->castHarrow($actor);
        $this->resolveOne();
        $this->game->take($actor, $ids);
        foreach ($ids as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
            $this->assertFalse($this->game->objects[$id]->tapped);
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
        $source = $this->castHarrow(0);
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
        $text = 'Search your library for up to two basic land cards, put them onto the battlefield, then shuffle';
        $this->assertSame(['type' => 'tutor', 'filter' => 'basic_land', 'count' => 2, 'to' => 'battlefield', 'reveal' => false], TextParser::effect($text));
        foreach (['You may '.$text, str_replace('two', 'three', $text), str_replace('onto the battlefield', 'into your hand', $text), str_replace('put them', 'reveal them, put them', $text)] as $legacy) {
            $this->assertSame('search', TextParser::effect($legacy)['type']);
        }
    }
}
