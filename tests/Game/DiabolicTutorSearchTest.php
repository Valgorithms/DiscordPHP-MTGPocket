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
use MTGPocket\Game\TextParser;
use MTGPocket\Tutorial\PracticeBot;

/** @covers \MTGPocket\Game\Game */
final class DiabolicTutorSearchTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fixture(): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/diabolic-tutor-unrestricted-search.json'));
        $this->assertSame('1b57f697a358559f870e1690fbfef69bc0d6ee50cdbf5f659bbd19551abdd1bb', hash('sha256', $bytes));

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

    private function castTutor(int $actor, bool $laterEffect = false): int
    {
        $mana = $this->lands($actor, 'Swamp', 4);
        $card = $this->fixture();
        if ($laterEffect) {
            $card['text'] .= ' You gain 2 life.';
        }
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
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
            $this->fail('An invalid unrestricted-search action was accepted.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider selections */
    public function testPaidUnrestrictedSearchAcceptsEveryCardTypeAndRequiresOneCard(int $actor, string $name): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $cards = [];
        foreach (['Island', 'Grizzly Bears', 'Lightning Bolt', 'Divination', 'Bonesplitter', 'Chandra, Pyrogenius'] as $candidate) {
            $cards[$candidate] = $this->game->addCard($actor, self::card($candidate), GameObject::LIBRARY)->id;
        }
        $source = $this->castTutor($actor);
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame('look', $choice['type']);
        $this->assertSame($actor, $choice['seat']);
        $this->assertFalse($choice['may']);
        $this->assertFalse($choice['reveal']);
        $this->assertSame(1, $choice['take']);
        $this->assertEqualsCanonicalizing(array_values($cards), $choice['eligible']);
        $this->reject(fn () => $this->game->take($actor, []));
        $this->reject(fn () => $this->game->take(1 - $actor, [$cards[$name]]));
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$cards[$name]]);
        $this->assertSame(GameObject::HAND, $this->zone($cards[$name]));
        $this->assertSame($actor, $this->game->objects[$cards[$name]]->owner);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertEqualsCanonicalizing(array_values(array_diff(array_values($cards), [$cards[$name]])), $this->game->players[$actor]->library);
        $this->assertSame($before['players'][1 - $actor]['library'], $this->game->players[1 - $actor]->library);
        $this->assertNull($this->game->choiceAwaiting());
        $after = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$cards[$name]]);
        $this->assertSame($after, $this->saved());
        $this->reject(fn () => $this->game->take($actor, [$cards[$name]]));
    }

    public static function selections(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach (['Island', 'Grizzly Bears', 'Lightning Bolt', 'Divination', 'Bonesplitter', 'Chandra, Pyrogenius'] as $name) {
                $cases[] = [$actor, $name];
            }
        }

        return $cases;
    }

    /** @dataProvider actors */
    public function testUnrevealedFindStaysOffThePublicBoardButRemainsInTheSeatAttributedRecord(int $actor): void
    {
        $this->prepare($actor);
        $selected = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $this->castTutor($actor);
        $this->resolveOne();
        $this->game = Game::fromArray($this->saved());
        $this->game->take($actor, [$selected]);
        $this->assertStringNotContainsString('Grizzly Bears', implode("\n", $this->game->log));
        $details = array_values(array_filter($this->saved()['record'], fn (array $entry) => str_contains($entry['x'] ?? '', 'finds Grizzly Bears.')));
        $this->assertCount(1, $details);
        $this->assertSame($actor, $details[0]['p']);
        $after = $this->saved();
        $this->game = Game::fromArray($after);
        $this->assertSame($after, $this->saved());
    }

    /** @dataProvider actors */
    public function testWrongZonesOverselectionAndOtherActionsAreAtomic(int $actor): void
    {
        $this->prepare($actor);
        $a = $this->game->addCard($actor, self::card('Island'), GameObject::LIBRARY)->id;
        $b = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $opposing = $this->game->addCard(1 - $actor, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $hand = $this->hand($actor, 'Grizzly Bears');
        $this->castTutor($actor);
        $this->resolveOne();
        foreach ([[], [$a, $b], [$opposing], [$hand], [999999]] as $ids) {
            $this->reject(fn () => $this->game->take($actor, $ids));
        }
        $this->reject(fn () => $this->game->pass($actor));
        $this->reject(fn () => $this->game->chooseOptionalBasicLandSearch($actor, 1, 'decline'));
        $this->game->take($actor, [$a]);
        $this->assertSame(GameObject::HAND, $this->zone($a));
        $this->assertSame(GameObject::LIBRARY, $this->zone($b));
    }

    /** @dataProvider actors */
    public function testEmptyLibraryStillShufflesAndLaterEffectsKeepTheOriginalController(int $actor): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $source = $this->castTutor($actor, true);
        $this->game->objects[$source]->controller = 1 - $actor;
        $before = $this->saved();
        $this->resolveOne();
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame([], $this->game->players[$actor]->library);
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
    }

    /** @dataProvider actors */
    public function testOnlyCardAndContinuationWaitForASelectionAcrossSaveLoad(int $actor): void
    {
        $this->prepare($actor);
        $this->emptyLibrary($actor);
        $selected = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $source = $this->castTutor($actor, true);
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->resolveOne();
        $this->assertSame(20, $this->life($actor));
        $this->assertSame([$selected], $this->game->choiceAwaiting()['eligible']);
        $this->assertSame($actor, $this->game->choiceAwaiting()['seat']);
        $this->game = Game::fromArray($this->saved());
        $this->reject(fn () => $this->game->take($actor, []));
        $this->game->take($actor, [$selected]);
        $this->assertSame(22, $this->life($actor));
        $this->assertSame(20, $this->life(1 - $actor));
        $this->assertSame(GameObject::HAND, $this->zone($selected));
    }

    /** @dataProvider actors */
    public function testUnpaidWrongColorOpposingAndCounteredCastsNeverSearch(int $actor): void
    {
        $this->prepare($actor);
        $source = $this->game->addCard($actor, $this->fixture(), GameObject::HAND)->id;
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Forest', 4);
        $this->reject(fn () => $this->game->cast($actor, $source));
        $this->lands($actor, 'Swamp', 4);
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

    public function testPracticeAndDuplicateNamesUseTheExistingSingleCardRoute(): void
    {
        $this->prepare(0);
        $this->emptyLibrary(0);
        for ($i = 0; $i < 30; $i++) {
            $this->game->addCard(0, self::card('Forest'), GameObject::LIBRARY);
        }
        $this->game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY);
        $this->castTutor(0);
        $this->resolveOne();
        $this->assertCount(2, $this->game->choiceAwaiting()['eligible']);
        $before = $this->saved();
        $this->assertFalse(PracticeBot::act($this->game, 1));
        $this->assertSame($before, $this->saved());
        $this->assertTrue(PracticeBot::act($this->game, 0));
        $this->assertCount(1, $this->game->players[0]->hand);
        $this->assertNull($this->game->choiceAwaiting());
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
    }

    /** @dataProvider actors */
    public function testExplicitRevealToTopStillAnnouncesTheFoundCard(int $actor): void
    {
        $this->prepare($actor);
        $selected = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $card = $this->fixture();
        $card['text'] = 'Search your library for a creature card, reveal it, then shuffle and put that card on top.';
        $this->lands($actor, 'Swamp', 4);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->resolveOne();
        $this->assertTrue($this->game->choiceAwaiting()['reveal']);
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->game->take($actor, [$selected]);
        $this->assertSame(GameObject::LIBRARY, $this->zone($selected));
        $this->assertSame($selected, $this->game->players[$actor]->library[array_key_last($this->game->players[$actor]->library)]);
        $this->assertSame($before['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertStringContainsString('searches their library for Grizzly Bears.', implode("\n", $this->game->log));
    }

    public function testRevealAndLegacySearchControlsKeepTheirPolicyAndRejectMalformedFlags(): void
    {
        foreach ([false, true] as $reveal) {
            $this->prepare(0);
            $selected = $this->game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
            $card = $this->fixture();
            $card['text'] = str_replace('a card,', 'a creature card,'.($reveal ? ' reveal it,' : ''), $card['text']);
            $definition = new CardDefinition($card);
            $this->assertSame($reveal, $definition->spellEffects()[0]['reveal']);
            $this->lands(0, 'Swamp', 4);
            $source = $this->game->addCard(0, $card, GameObject::HAND)->id;
            $this->game->cast(0, $source);
            $this->resolveOne();
            $this->assertTrue($this->game->choiceAwaiting()['may']);
            $this->assertSame($reveal, $this->game->choiceAwaiting()['reveal']);
            $before = $this->saved();
            foreach (['false', 0, null, []] as $bad) {
                $forged = $before;
                $forged['pendingChoice']['reveal'] = $bad;
                try {
                    Game::fromArray($forged);
                    $this->fail('Malformed reveal policy was accepted.');
                } catch (GameException $error) {
                    $this->assertStringContainsString('reveal policy', $error->getMessage());
                }
            }
            $this->game->take(0, [$selected]);
            $this->assertSame($reveal, str_contains(implode("\n", $this->game->log), 'searches their library for Grizzly Bears.'));
            $legacy = $before;
            unset($legacy['pendingChoice']['reveal']);
            foreach ([13, 14, 15] as $version) {
                $legacy['schemaVersion'] = $version;
                $this->game = Game::fromArray($legacy);
                $this->game->take(0, [$selected]);
                $this->assertStringContainsString('searches their library for Grizzly Bears.', implode("\n", $this->game->log));
            }
        }
        $harbinger = TextParser::effect('Search your library for a Goblin card, reveal it, then shuffle and put that card on top');
        $this->assertSame('top', $harbinger['to']);
        $this->assertTrue($harbinger['reveal']);
    }
}
