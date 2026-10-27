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

use MTGPocket\Game\DamageBatch;
use MTGPocket\Game\GameException;
use PHPUnit\Framework\TestCase;

/** @covers \MTGPocket\Game\DamageBatch */
final class DamageBatchTest extends TestCase
{
    private function event(int $amount = 3, array $ops = []): array
    {
        return ['source' => 5, 'incarnation' => 1, 'controller' => 0, 'target' => 'p:1',
            'amount' => $amount, 'combat' => false, 'operations' => $ops];
    }

    private function double(int $id = 1): array
    {
        return ['id' => 'double:'.$id, 'kind' => 'double', 'resource' => null];
    }

    private function shield(): array
    {
        return ['id' => 'prevent', 'kind' => 'prevent', 'resource' => 'prevent:p:1'];
    }

    public function testBothLegalOrdersAndReplay(): void
    {
        $batch = DamageBatch::start([$this->event(3, [$this->double(), $this->shield()])], ['prevent:p:1' => 2]);
        $before = $batch->toArray();
        $this->assertSame(['double:1', 'prevent'], $batch->progress()['options']);
        $this->assertSame([], $batch->progress()['results']);
        $this->assertSame(4, $batch->choose('double:1')->progress()['results'][0]['amount']);
        $this->assertSame(2, $batch->choose('prevent')->progress()['results'][0]['amount']);
        $this->assertSame($before, $batch->toArray());
        $restored = DamageBatch::fromArray(json_decode(json_encode($before), true));
        $this->assertSame($batch->choose('prevent')->progress(), $restored->choose('prevent')->progress());
    }

    public function testTwoDoublingsAndSharedShieldExhaustionAcrossBatch(): void
    {
        $events = array_fill(0, 2, $this->event(3, [$this->double(), $this->double(2), $this->shield()]));
        $batch = DamageBatch::start($events, ['prevent:p:1' => 2])->choose('double:1');
        $this->assertSame(['double:2', 'prevent'], $batch->progress()['options']);
        $restored = DamageBatch::fromArray($batch->toArray())->choose('double:2');
        $this->assertSame([10, 12], array_column($restored->progress()['results'], 'amount'));
        $this->assertSame(['prevent:p:1' => 0], $restored->progress()['resources']);
    }

    public function testZeroDamageNeverConsumesReplacementOrShield(): void
    {
        $batch = DamageBatch::start([$this->event(0, [$this->double(), $this->shield()])], ['prevent:p:1' => 2]);
        $this->assertSame([], $batch->progress()['options']);
        $this->assertSame(0, $batch->progress()['results'][0]['amount']);
        $this->assertSame(['prevent:p:1' => 2], $batch->progress()['resources']);
    }

    public function testCompletedAndUnknownChoicesRejectWithoutMutation(): void
    {
        $batch = DamageBatch::start([$this->event(3, [$this->double(), $this->shield()])], ['prevent:p:1' => 2]);
        $before = $batch->toArray();
        foreach (['unknown', 'double:99'] as $option) {
            try {
                $batch->choose($option);
                $this->fail('Illegal option accepted.');
            } catch (GameException) {
                $this->assertSame($before, $batch->toArray());
            }
        }
        $this->expectException(GameException::class);
        $batch->choose('prevent')->choose('prevent');
    }

    /** @dataProvider malformed */
    public function testMalformedSerializedStateRejects(callable $change): void
    {
        $state = DamageBatch::start([$this->event(3, [$this->double(), $this->shield()])], ['prevent:p:1' => 2])->toArray();
        $this->expectException(GameException::class);
        DamageBatch::fromArray($change($state));
    }

    public static function malformed(): array
    {
        return [
            [fn ($s) => ['extra' => true] + $s],
            [function ($s) {
                $s['events'][0]['amount'] = -1;
                return $s;
            }],
            [function ($s) {
                $s['events'][0]['controller'] = 2;
                return $s;
            }],
            [function ($s) {
                $s['events'][0]['operations'][] = $s['events'][0]['operations'][0];
                return $s;
            }],
            [function ($s) {
                $s['selections'] = ['absent'];
                return $s;
            }],
            [function ($s) {
                $s['selections'] = ['prevent', 'prevent'];
                return $s;
            }],
            [function ($s) {
                $s['events'] = array_fill(0, DamageBatch::MAX_EVENTS + 1, $s['events'][0]);
                return $s;
            }],
        ];
    }
}
