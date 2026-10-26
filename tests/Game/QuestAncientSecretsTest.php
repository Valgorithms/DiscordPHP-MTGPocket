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

/** @covers \MTGPocket\Game\Game */
final class QuestAncientSecretsTest extends GameTestCase
{
    private function cardData(string $name): array
    {
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/quest-ancient-secrets.json'));
        $this->assertSame('c120400c2ecf17ab694a6eb15d6eebcf24b41c88abd946bcc28a36b4aacf5005', hash('sha256', $bytes));
        $cards = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['cards'];

        return array_values(array_filter($cards, fn (array $card) => $card['name'] === $name))[0];
    }

    private function resolveOne(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function quest(int $actor, bool $seedGraveyards = false): int
    {
        $this->newGame();
        if ($seedGraveyards) {
            foreach ([0, 1] as $seat) {
                $this->game->addCard($seat, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
            }
        }
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $mana = $this->lands($actor, 'Island', 1)[0];
        $card = $this->cardData('Quest for Ancient Secrets');
        $this->assertSame('8defd487-1060-59ca-ba4f-243a2eadb391', $card['uuid']);
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $source);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($source));

        return $source;
    }

    private function scour(int $actor, int $target): void
    {
        $mana = $this->lands($actor, 'Island', 1)[0];
        $card = $this->cardData('Tome Scour');
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $spell, 0, ['p:'.$target]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
    }

    /** @dataProvider actors */
    public function testPaidSelfMillOffersOneOptionalCounterPerCard(int $actor): void
    {
        $source = $this->quest($actor);
        $this->scour($actor, $actor);
        $this->assertCount(6, $this->game->stack, 'Five milled cards and the resolved spell each enter your graveyard.');
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        for ($n = 1; $n <= 6; $n++) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], $n === 6 ? 'decline' : 'accept');
            $this->assertSame(min($n, 5), $this->game->objects[$source]->counter('quest'));
        }
    }

    /** @dataProvider targetCases */
    public function testFiveCounterActivationShufflesTheSelectedPlayer(int $actor, int $target): void
    {
        $source = $this->quest($actor, true);
        // Isolate activation: graveyard fixtures precede the source; counters are seeded.
        $this->game->objects[$source]->addCounters('quest', 5);
        $buried = [];
        foreach ([0, 1] as $seat) {
            $buried[$seat] = $this->game->players[$seat]->graveyard[0];
        }
        $library = $this->game->players[$target]->library;
        $otherLibrary = $this->game->players[1 - $target]->library;
        $this->game->activate($actor, $source, 0, ['p:'.$target]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertCount(1, $this->game->stack);
        $this->assertSame(['p:'.$target], $this->game->stack[0]['targets']);
        $expected = array_merge($library, [$buried[$target]], $target === $actor ? [$source] : []);
        $queued = $this->saved();
        $this->resolveOne();
        $actual = $this->game->players[$target]->library;
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([], $this->game->players[$target]->graveyard);
        $this->assertSame($otherLibrary, $this->game->players[1 - $target]->library);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($buried[1 - $target]));
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    public static function targetCases(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1]];
    }

    private function answerCounters(int $actor, int $accept): void
    {
        $n = 0;
        while ($this->game->stack !== []) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame(['optional_source_counter', 'quest', $actor], [$choice['type'], $choice['counterKind'], $choice['seat']]);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], ++$n <= $accept ? 'accept' : 'decline');
        }
    }

    private function reject(callable $action): void
    {
        $before = $this->saved();
        try {
            $action();
            $this->fail('Invalid Quest action must reject.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }

    /** @dataProvider targetCases */
    public function testEarnedCountersPayForEitherPlayersWholeGraveyard(int $actor, int $target): void
    {
        $source = $this->quest($actor, true);
        $this->assertSame([], $this->game->objects[$source]->definition()->unsupported);
        $this->scour($actor, $actor);
        $this->answerCounters($actor, 5);
        $this->assertSame(5, $this->game->objects[$source]->counter('quest'));
        $otherLibrary = $this->game->players[1 - $target]->library;
        $library = $this->game->players[$target]->library;
        $graveyard = $this->game->players[$target]->graveyard;
        $this->game->activate($actor, $source, 0, ['p:'.$target]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        $this->assertCount(1, $this->game->stack, 'The sacrificed Quest does not see its own burial.');
        $this->assertSame($library, $this->game->players[$target]->library);
        $queued = $this->saved();
        $this->resolveOne();
        $expected = array_merge($library, $graveyard, $actor === $target ? [$source] : []);
        $actual = $this->game->players[$target]->library;
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([], $this->game->players[$target]->graveyard);
        $this->assertSame($otherLibrary, $this->game->players[1 - $target]->library);
        $this->assertSame($queued['shuffles'] + 1, $this->saved()['shuffles']);
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->resolveOne();
        $this->assertSame($resolved, $this->saved());
    }

    /** @dataProvider actors */
    public function testQueuedAndPendingCounterChoicesKeepOriginalController(int $actor): void
    {
        $source = $this->quest($actor);
        $this->scour($actor, $actor);
        // Synthetic transfer isolates controller identity, not an enabling theft card.
        $this->game->objects[$source]->controller = 1 - $actor;
        $this->game = Game::fromArray($this->saved());
        $this->resolveOne();
        $choice = $this->game->choiceAwaiting();
        $this->assertSame($actor, $choice['seat']);
        $pending = $this->saved();
        $this->game = Game::fromArray($pending);
        $this->reject(fn () => $this->game->chooseOptionalSourceCounter(1 - $actor, $choice['id'], 'accept'));
        $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        $this->assertSame(1, $this->game->objects[$source]->counter('quest'));
        $this->answerCounters($actor, 5);
        $this->assertSame(6, $this->game->objects[$source]->counter('quest'));
        $this->assertSame(1 - $actor, $this->game->objects[$source]->controller);
    }

    /** @dataProvider actors */
    public function testEmptyTargetGraveyardStillShufflesOnce(int $actor): void
    {
        $source = $this->quest($actor);
        $this->scour($actor, $actor);
        $this->answerCounters($actor, 5);
        $target = 1 - $actor;
        $this->assertSame([], $this->game->players[$target]->graveyard);
        $expected = $this->game->players[$target]->library;
        $other = $this->game->players[$actor]->library;
        $this->game->activate($actor, $source, 0, ['p:'.$target]);
        $queued = $this->saved();
        $this->resolveOne();
        $actual = $this->game->players[$target]->library;
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame($other, $this->game->players[$actor]->library);
        $this->assertSame($queued['shuffles'] + 1, $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
    }

    /** @dataProvider actors */
    public function testPaidDiscardAndSpellBurialEachOfferCounters(int $actor): void
    {
        $source = $this->quest($actor);
        $this->hand($actor, 'Grizzly Bears');
        $this->hand($actor, 'Grizzly Bears');
        $handCount = count($this->game->players[$actor]->hand);
        $mana = $this->lands($actor, 'Swamp', 3);
        $card = $this->cardData('Mind Rot');
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->game->cast($actor, $spell, 0, ['p:'.$actor]);
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        if ($handCount > 2) {
            $this->assertSame('discard', $this->game->decision($actor));
            $this->game = Game::fromArray($this->saved());
            $this->game->discard($actor, array_slice($this->game->players[$actor]->hand, 0, 2));
        }
        $this->assertCount($handCount - 2, $this->game->players[$actor]->hand);
        $this->assertCount(3, $this->game->stack);
        $this->answerCounters($actor, 3);
        $this->assertSame(3, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testUntargetedShuffleFollowupKeepsControllerBoundPolicy(int $actor): void
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $buried = $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $card = ['name' => 'Legacy Shuffle Unit Fixture', 'type' => 'Sorcery', 'manaCost' => '{U}', 'text' => 'Target player mills one card. Shuffle your graveyard into your library.'];
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $mana = $this->lands($actor, 'Island', 1)[0];
        $spell = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $own = $this->game->players[$actor]->library;
        $other = $this->game->players[1 - $actor]->library;
        $milled = array_pop($other);
        $this->game->cast($actor, $spell, 0, ['p:'.(1 - $actor)]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $expected = [...$own, $buried];
        $actual = $this->game->players[$actor]->library;
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame($other, $this->game->players[1 - $actor]->library);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($milled));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell), 'The resolving spell is buried after its own shuffle.');
    }

    /** @dataProvider actors */
    public function testOpponentMillDoesNotCountTheirCards(int $actor): void
    {
        $source = $this->quest($actor);
        $this->scour($actor, 1 - $actor);
        $this->assertCount(1, $this->game->stack, 'Only your resolved Tome Scour goes to your graveyard.');
        $this->answerCounters($actor, 0);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider actors */
    public function testActivationRejectsInvalidCostsAndTargetsAtomically(int $actor): void
    {
        $source = $this->quest($actor);
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['p:'.$actor]));
        $this->scour($actor, $actor);
        $this->answerCounters($actor, 4);
        $this->assertSame(4, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['p:'.$actor]));
        $mana = $this->lands($actor, 'Mountain', 1)[0];
        $spell = $this->hand($actor, 'Shock');
        $this->game->cast($actor, $spell, 0, ['p:'.(1 - $actor)]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->answerCounters($actor, 1);
        $this->assertSame(5, $this->game->objects[$source]->counter('quest'));
        $this->reject(fn () => $this->game->activate(1 - $actor, $source, 0, ['p:'.$actor]));
        $this->reject(fn () => $this->game->activate($actor, $source, 0, []));
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['p:9']));
        $this->game->activate($actor, $source, 0, ['p:'.$actor]);
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['p:'.$actor]));
    }

    /** @dataProvider actors */
    public function testPlayerHexproofRejectsBeforeCostsAndCanInvalidateQueuedAbility(int $actor): void
    {
        $source = $this->quest($actor);
        $this->scour($actor, $actor);
        $this->answerCounters($actor, 5);
        $shield = ['name' => 'Player Hexproof Unit Fixture', 'type' => 'Enchantment', 'text' => 'You have hexproof.'];
        $object = $this->game->addCard(1 - $actor, $shield, GameObject::BATTLEFIELD);
        $this->reject(fn () => $this->game->activate($actor, $source, 0, ['p:'.(1 - $actor)]));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, GameObject::EXILE);
        $this->game->activate($actor, $source, 0, ['p:'.(1 - $actor)]);
        $this->game->addCard(1 - $actor, $shield, GameObject::BATTLEFIELD);
        $before = $this->saved();
        $this->game = Game::fromArray($before);
        $this->resolveOne();
        $this->assertSame($before['shuffles'], $this->saved()['shuffles']);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
    }

    /** @dataProvider movements */
    public function testOnlyActualOwnedCardBurialOffersCounter(string $from, string $to, bool $token, bool $replace, int $owner, int $controller, int $count): void
    {
        $source = $this->quest(0);
        $object = $this->game->addCard($owner, ['name' => 'Burial Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '2', 'token' => $token, 'text' => null], $from);
        $object->controller = $controller;
        $object->unearthed = $replace;
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $object, $to);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($count, $this->game->stack);
        $this->answerCounters(0, $count);
        $this->assertSame($count, $this->game->objects[$source]->counter('quest'));
    }

    public static function movements(): array
    {
        return [
            [GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 0, 0, 1],
            [GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 0, 1, 1],
            [GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, false, 1, 0, 0],
            [GameObject::BATTLEFIELD, GameObject::GRAVEYARD, true, false, 0, 0, 0],
            [GameObject::BATTLEFIELD, GameObject::GRAVEYARD, false, true, 0, 0, 0],
            [GameObject::HAND, GameObject::GRAVEYARD, false, false, 0, 0, 1],
            [GameObject::LIBRARY, GameObject::GRAVEYARD, false, false, 0, 0, 1],
            [GameObject::EXILE, GameObject::GRAVEYARD, false, false, 0, 0, 1],
            [GameObject::BATTLEFIELD, GameObject::EXILE, false, false, 0, 0, 0],
            [GameObject::BATTLEFIELD, GameObject::HAND, false, false, 0, 0, 0],
        ];
    }

    /** @dataProvider selectedGroups */
    public function testFromAnywhereObserverDoesNotUseDepartingGroupLookback(int $actor, bool $sourceFirst, bool $survivor): void
    {
        $source = $this->quest($actor);
        // Synthetic selected-group boundary; no mass-removal enabling-card claim.
        $other = $survivor ? $this->game->addCard($actor, $this->cardData('Quest for Ancient Secrets'), GameObject::BATTLEFIELD)->id : null;
        $victim = $this->battlefield($actor, 'Grizzly Bears');
        $doomed = array_map(fn (int $id) => $this->game->objects[$id], $sourceFirst ? [$source, $victim] : [$victim, $source]);
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        (new \ReflectionMethod(Game::class, 'withDeathObservers'))->invoke($this->game, $doomed, fn (GameObject $object) => $move->invoke($this->game, $object, GameObject::GRAVEYARD));
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertCount($survivor ? 2 : 0, $this->game->stack);
        foreach ($this->game->stack as $ability) {
            $this->assertSame($other, $ability['object']);
        }
        $this->answerCounters($actor, 2);
        $this->assertSame(0, $this->game->objects[$source]->counter('quest'));
        if ($other !== null) {
            $this->assertSame(2, $this->game->objects[$other]->counter('quest'));
        }
    }

    public static function selectedGroups(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $first) {
                foreach ([false, true] as $survivor) {
                    $cases[] = [$actor, $first, $survivor];
                }
            }
        }

        return $cases;
    }
}
