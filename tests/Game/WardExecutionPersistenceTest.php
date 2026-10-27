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

/** Closed fixed-Ward saves and explicit migrations; no live match storage is inspected. @covers \MTGPocket\Game\Game */
final class WardExecutionPersistenceTest extends GameTestCase
{
    private function saved(): array
    {
        return json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function choice(): void
    {
        $this->newGame();
        $this->game->setWardExecution(true);
        $ward = $this->game->addCard(1, ['name' => 'Saved Ward Unit Fixture', 'type' => 'Creature', 'power' => '2', 'toughness' => '5', 'text' => 'Ward {2}'], GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Mountain', 1);
        $this->game->cast(0, $this->hand(0, 'Shock'), 0, ['o:'.$ward]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    /** @dataProvider malformed */
    public function testMalformedChoiceRefusesBeforeAnyAction(string $case): void
    {
        $this->choice();
        $state = $this->saved();
        $bad = $state;
        match ($case) {
            'disabled' => $bad['wardExecution']['enabled'] = false,
            'future-choice' => $bad['pendingChoice']['id'] = $bad['nextChoiceId'],
            'wrong-payer' => $bad['pendingChoice']['seat'] = 1,
            'wrong-ref' => $bad['pendingChoice']['offending']['id']++,
            'extra-option' => $bad['pendingChoice']['options'][] = 'cancel',
            'extra-step' => $bad['pendingChoice']['resume']['steps'][] = [['type' => 'draw', 'count' => 10], null],
            'wrong-resume-source' => $bad['pendingChoice']['resume']['item']['object'] = $bad['stack'][0]['object'],
            'targeted-ward' => $bad['pendingChoice']['resume']['item']['targets'] = ['p:0'],
            'future-event' => $bad['pendingChoice']['resume']['item']['effects'][0]['eventId'] = $bad['wardExecution']['nextEventId'],
            'array-object' => $bad['pendingChoice']['resume']['item']['effects'][0]['offending']['object'] = [],
            'array-incarnation' => $bad['pendingChoice']['resume']['item']['effects'][0]['offending']['incarnation'] = [],
            'unsupported-cost' => $bad['pendingChoice']['resume']['item']['effects'][0]['instance']['cost'] = ['kind' => 'discard', 'amount' => 1],
            'huge-label' => $bad['pendingChoice']['label'] = str_repeat('x', 1048577),
            'extra-choice-key' => $bad['pendingChoice']['cancel'] = true,
            'extra-resume-key' => $bad['pendingChoice']['resume']['refunded'] = true,
            'bad-followed' => $bad['pendingChoice']['resume']['followed'] = ['invented' => []],
        };
        try {
            Game::fromArray($bad);
            $this->fail('Malformed fixed Ward choice loaded.');
        } catch (GameException) {
            $this->assertSame($state, $this->saved());
        }
    }

    public static function malformed(): array
    {
        return array_map(fn ($case) => [$case], ['disabled', 'future-choice', 'wrong-payer', 'wrong-ref', 'extra-option', 'extra-step', 'wrong-resume-source',
            'targeted-ward', 'future-event', 'array-object', 'array-incarnation', 'unsupported-cost', 'huge-label', 'extra-choice-key', 'extra-resume-key', 'bad-followed']);
    }

    /** @dataProvider oldVersions */
    public function testOlderIdleSavesLoadWithModeOffAndCannotInjectNewActiveState(int $version): void
    {
        $this->newGame();
        $state = $this->saved();
        $state['schemaVersion'] = $version;
        unset($state['wardExecution']);
        $restored = Game::fromArray($state);
        $this->assertFalse($restored->wardExecutionEnabled());
        $this->assertSame(['enabled' => false, 'nextEventId' => 1, 'nextPendingId' => 1, 'triggerGroup' => null], $restored->toArray()['wardExecution']);
        $state['wardExecution'] = ['enabled' => true, 'nextEventId' => 1, 'nextPendingId' => 1, 'triggerGroup' => null];
        $this->expectException(GameException::class);
        Game::fromArray($state);
    }

    public static function oldVersions(): array
    {
        return [[17], [18], [19]];
    }

    public function testModeCannotBeChangedDuringACommittedStackOrResolutionChoice(): void
    {
        $this->choice();
        $before = $this->saved();
        try {
            $this->game->setWardExecution(false);
            $this->fail('Changed execution contract during payment.');
        } catch (GameException) {
            $this->assertSame($before, $this->saved());
        }
    }
}
