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
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

/** @covers \MTGPocket\Game\Game */
final class QuestAncientMergedIdentityTest extends GameTestCase
{
    private function cardData(string $name, bool $quest = false): array
    {
        $path = $quest ? 'quest-ancient-secrets.json' : 'quest-ancient-merged-identity.json';
        $expected = $quest ? 'c120400c2ecf17ab694a6eb15d6eebcf24b41c88abd946bcc28a36b4aacf5005' : '029e06776aad56cbb226dbe28556d5401a3b4e9cfdbd3641b42c412b548b475c';
        $bytes = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../Fixtures/'.$path));
        $this->assertSame($expected, hash('sha256', $bytes));
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

    private function merged(int $actor, bool $over, bool $tokenHost): array
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }
        $quest = $this->game->addCard($actor, $this->cardData('Quest for Ancient Secrets', true), GameObject::HAND)->id;
        $questMana = $this->lands($actor, 'Island', 1)[0];
        $this->game->cast($actor, $quest);
        $this->assertTrue($this->game->objects[$questMana]->tapped);
        $this->resolveOne();
        // Seed the host or its token copy; the mutating non-token spell is actually paid.
        $hostData = $this->cardData($over ? 'Raging Goblin' : 'Centaur Courser');
        if ($tokenHost) {
            $hostData['token'] = true;
        }
        $host = $this->game->addCard($actor, $hostData, GameObject::BATTLEFIELD)->id;
        $octopus = $this->cardData('Sea-Dasher Octopus');
        $this->assertSame([], (new CardDefinition($octopus))->unsupported);
        $part = $this->game->addCard($actor, $octopus, GameObject::HAND)->id;
        $mana = $this->lands($actor, 'Island', 2);
        $this->game->cast($actor, $part, 0, ['o:'.$host], 'mutate');
        foreach ($mana as $id) {
            $this->assertTrue($this->game->objects[$id]->tapped);
        }
        $this->resolveOne();
        $this->assertSame([], $this->game->stack, 'Merging is not a graveyard entry.');
        $object = $this->game->objects[$host];
        $this->assertSame([$part], array_column($object->mutations, 'id'));
        $this->assertSame($over, $object->mutations[0]['over']);
        $this->assertSame($tokenHost && ! $over, $object->definition()->isToken(), 'On the battlefield, only the top component determines merged token status.');
        $this->assertSame($tokenHost, $object->printed()->isToken(), 'The original host component keeps its own physical identity.');
        $this->assertFalse($this->game->objects[$part]->printed()->isToken());

        return [$quest, $host, $part];
    }

    /** @dataProvider componentCases */
    public function testOnlyPhysicalCardsCountAfterMergedDeparture(int $actor, bool $over, bool $tokenHost, bool $stolen): void
    {
        [$quest, $host, $part] = $this->merged($actor, $over, $tokenHost);
        if ($stolen) {
            // Transfer probe: ownership of both components remains with the caster.
            $this->game->objects[$host]->controller = 1 - $actor;
        }
        $this->game = Game::fromArray($this->saved());
        $before = $this->saved();
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$host], GameObject::GRAVEYARD);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame([$tokenHost ? 1 : 2, $tokenHost ? GameObject::GONE : GameObject::GRAVEYARD, GameObject::GRAVEYARD], [count($this->game->stack), $this->zone($host), $this->zone($part)], 'Each physical card, never the original token component, enters the graveyard.');
        $this->assertSame($before['died'] + 1, $this->saved()['died'], 'One merged permanent dies.');
        $this->assertNotContains($part, $this->game->battlefield);
        $this->assertSame([], $this->game->objects[$host]->mutations);
        $this->assertSame($tokenHost ? [$part] : [$host, $part], $this->game->players[$actor]->graveyard);
        $this->assertSame([], $this->game->players[1 - $actor]->graveyard);
        $queued = $this->saved();
        $this->acceptCounters($actor);
        $this->assertSame($tokenHost ? 1 : 2, $this->game->objects[$quest]->counter('quest'));
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->acceptCounters($actor);
        $this->assertSame($resolved, $this->saved());
    }

    private function acceptCounters(int $actor): void
    {
        while ($this->game->stack !== []) {
            $this->resolveOne();
            $choice = $this->game->choiceAwaiting();
            $this->assertSame($actor, $choice['seat']);
            $this->game->chooseOptionalSourceCounter($actor, $choice['id'], 'accept');
        }
    }

    public static function componentCases(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $over) {
                foreach ([false, true] as $tokenHost) {
                    foreach ([false, true] as $stolen) {
                        $cases[] = [$actor, $over, $tokenHost, $stolen];
                    }
                }
            }
        }

        return $cases;
    }

    /** @dataProvider departureGroups */
    public function testMergedGroupUsesSurvivingObserversAndComponentOwners(int $actor, bool $over, bool $tokenHost, bool $sourceFirst, bool $survivor): void
    {
        [$quest, $host, $part] = $this->merged($actor, $over, $tokenHost);
        // Synthetic transfer and selected-group boundaries; no enabling-card claim.
        $this->game->objects[$host]->controller = 1 - $actor;
        $observer = null;
        if ($survivor) {
            $observer = $this->game->addCard(1 - $actor, $this->cardData('Quest for Ancient Secrets', true), GameObject::BATTLEFIELD)->id;
            $this->game->objects[$observer]->controller = $actor;
        }
        $this->game = Game::fromArray($this->saved());
        $doomed = array_map(fn (int $id) => $this->game->objects[$id], $sourceFirst ? [$quest, $host] : [$host, $quest]);
        $move = new \ReflectionMethod(Game::class, 'moveTo');
        (new \ReflectionMethod(Game::class, 'withDeathObservers'))->invoke($this->game, $doomed, fn (GameObject $object) => $move->invoke($this->game, $object, GameObject::GRAVEYARD));
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $expected = $survivor ? ($tokenHost ? 2 : 3) : 0;
        $this->assertCount($expected, $this->game->stack);
        foreach ($this->game->stack as $ability) {
            $this->assertSame([$observer, $actor], [$ability['object'], $ability['controller']]);
        }
        $this->assertSame($tokenHost ? GameObject::GONE : GameObject::GRAVEYARD, $this->zone($host));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($part));
        $this->assertSame([], $this->game->players[1 - $actor]->graveyard);
        $queued = $this->saved();
        $this->acceptCounters($actor);
        $this->assertSame(0, $this->game->objects[$quest]->counter('quest'), 'A from-anywhere observer that leaves does not look back.');
        if ($observer !== null) {
            $this->assertSame($expected, $this->game->objects[$observer]->counter('quest'));
            $this->assertSame([1 - $actor, $actor], [$this->game->objects[$observer]->owner, $this->game->objects[$observer]->controller]);
        }
        $resolved = $this->saved();
        $this->game = Game::fromArray($queued);
        $this->acceptCounters($actor);
        $this->assertSame($resolved, $this->saved());
    }

    public static function departureGroups(): array
    {
        $cases = [];
        foreach ([0, 1] as $actor) {
            foreach ([false, true] as $over) {
                foreach ([false, true] as $tokenHost) {
                    foreach ([false, true] as $first) {
                        foreach ([false, true] as $survivor) {
                            $cases[] = [$actor, $over, $tokenHost, $first, $survivor];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    /** @dataProvider otherDestinations */
    public function testOtherDestinationsPreserveComponentsWithoutBurialTriggers(bool $over, bool $tokenHost, string $zone): void
    {
        [$quest, $host, $part] = $this->merged(0, $over, $tokenHost);
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$host], $zone);
        (new \ReflectionMethod(Game::class, 'settle'))->invoke($this->game);
        $this->assertSame($tokenHost ? GameObject::GONE : $zone, $this->zone($host));
        $this->assertSame($zone, $this->zone($part));
        $this->assertSame([], $this->game->stack);
        $this->assertSame(0, $this->game->objects[$quest]->counter('quest'));
        $this->assertSame([], $this->game->players[0]->graveyard);
    }

    public static function otherDestinations(): array
    {
        $cases = [];
        foreach ([false, true] as $over) {
            foreach ([false, true] as $tokenHost) {
                foreach ([GameObject::HAND, GameObject::LIBRARY, GameObject::EXILE] as $zone) {
                    $cases[] = [$over, $tokenHost, $zone];
                }
            }
        }

        return $cases;
    }
}
