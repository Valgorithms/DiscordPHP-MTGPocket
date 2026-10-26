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
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

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

    private function death(int $actor, int $victimController): void
    {
        $victim = $this->game->addCard($victimController, self::card('Ornithopter'), GameObject::BATTLEFIELD)->id;
        $mana = $this->lands($actor, 'Swamp', 1)[0];
        $spell = $this->game->addCard($actor, $this->cardData('Disfigure'), GameObject::HAND)->id;
        $this->game->cast($actor, $spell, 0, ["o:{$victim}"]);
        $this->assertTrue($this->game->objects[$mana]->tapped);
        $this->resolveOne();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
        $this->resolveOne();
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
}
