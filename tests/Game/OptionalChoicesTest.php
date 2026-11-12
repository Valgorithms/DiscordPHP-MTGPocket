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
use MTGPocket\Game\OptionalChoices;
use PHPUnit\Framework\TestCase;

/** @covers \MTGPocket\Game\OptionalChoices */
final class OptionalChoicesTest extends TestCase
{
    public function testEveryKindIsCompleteAndItsPanelActionIsUnique(): void
    {
        $actions = [];
        foreach (OptionalChoices::KINDS as $type => $kind) {
            foreach (['action', 'since', 'name', 'unavailable', 'targeted', 'cost', 'sourceOnly', 'status', 'prompt', 'accept', 'decline'] as $field) {
                $this->assertArrayHasKey($field, $kind, "{$type}.{$field}");
            }
            $this->assertStringStartsWith('optional_', $type);
            $this->assertTrue(isset($kind['effects']) xor isset($kind['steps']), "{$type} applies effects or queues steps, not both.");
            $this->assertSame($kind['cost'] !== null, isset($kind['unpayable']), "{$type} needs an error exactly when it has a cost.");
            $this->assertGreaterThanOrEqual(1, $kind['since']);
            $this->assertLessThanOrEqual(Game::STATE_VERSION, $kind['since']);
            $this->assertSame($type, OptionalChoices::kindForAction($kind['action']));
            $actions[] = $kind['action'];
        }
        $this->assertSame($actions, array_unique($actions));
        $this->assertNull(OptionalChoices::kindForAction('pass'));
        $this->assertFalse(OptionalChoices::has('sacrifice'));
        $this->assertFalse(OptionalChoices::has(null));
    }

    public function testTextAndEffectsFillInTheChoice(): void
    {
        $choice = ['type' => 'optional_source_counter', 'label' => 'Quest for the Gravelord', 'counterKind' => 'quest'];
        $this->assertSame('choose whether to put a quest counter on Quest for the Gravelord', OptionalChoices::text($choice, 'status'));
        $this->assertSame('Put a quest counter', OptionalChoices::text($choice, 'accept'));
        $this->assertSame([['type' => 'counters', 'amount' => 1, 'self' => true, 'kind' => 'quest']], OptionalChoices::effects($choice));
        unset($choice['counterKind']);
        $this->assertSame('+1/+1', OptionalChoices::effects($choice)[0]['kind']);
        $this->assertSame('**Disciple**: you may have **Val** lose 1 life.', OptionalChoices::text(['type' => 'optional_life_loss', 'label' => 'Disciple'], 'prompt', 'Val'));
        $this->assertSame([], OptionalChoices::effects(['type' => 'optional_basic_land_search']));
    }
}
