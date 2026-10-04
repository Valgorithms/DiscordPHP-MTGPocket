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

use MTGPocket\Game\Mana\ManaCost;
use MTGPocket\Game\Mana\ManaPayer;
use MTGPocket\Game\Mana\ManaPool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MTGPocket\Game\Mana\ManaCost
 * @covers \MTGPocket\Game\Mana\ManaPool
 * @covers \MTGPocket\Game\Mana\ManaPayer
 */
final class ManaTest extends TestCase
{
    public function testParsesCosts(): void
    {
        $cost = ManaCost::parse('{2}{W}{U/B}{X}');
        $this->assertSame('{2}{W}{U/B}{X}', (string) $cost);
        $this->assertSame(1, $cost->xCount);
        $this->assertSame(4, $cost->manaValue());
        $this->assertSame(7, $cost->manaValue(3));
        $this->assertSame(['W', 'U', 'B'], $cost->colors());

        $this->assertSame(6, ManaCost::parse('{2/W}{2/W}{2/W}')->manaValue(), 'Twobrid counts its larger half (rule 202.3f).');
        $this->assertSame(1, ManaCost::parse('{G/P}')->manaValue());
        $this->assertTrue(ManaCost::parse(null)->isEmpty());
        $this->assertFalse(ManaCost::parse('{0}')->isEmpty());

        $this->expectException(\InvalidArgumentException::class);
        ManaCost::parse('{Q}');
    }

    public function testPaymentsListEveryWayToPay(): void
    {
        $payments = ManaCost::parse('{1}{R/G}{B/P}')->payments();
        $this->assertCount(4, $payments);
        $this->assertSame(0, $payments[0]['life'], 'Paying with mana comes before paying life.');
        $this->assertSame(['generic' => 1, 'R' => 1, 'B' => 1], $payments[0]['mana']);
        $this->assertSame(2, $payments[3]['life']);

        $this->assertSame(['generic' => 3, 'R' => 1], ManaCost::parse('{X}{R}')->payments(3)[0]['mana']);
    }

    public function testPayerSavesDualLandsForTheColorOnlyTheyMake(): void
    {
        // Forest, Mountain-or-Forest dual: {R}{G} needs the dual for red.
        $sources = [10 => ['count' => 1, 'colors' => ['R', 'G']], 11 => ['count' => 1, 'colors' => ['G']]];
        $plan = ManaPayer::plan(['mana' => ['R' => 1, 'G' => 1], 'life' => 0], new ManaPool(), $sources);
        $this->assertNotNull($plan);
        $this->assertEqualsCanonicalizing([10, 11], $plan['tap']);

        $this->assertNull(ManaPayer::plan(['mana' => ['R' => 2], 'life' => 0], new ManaPool(), $sources));
    }

    public function testPayerSpendsFloatingManaFirstAndFloatsTheRest(): void
    {
        $pool = new ManaPool(['G' => 1]);
        $sources = [5 => ['count' => 2, 'colors' => ['C']], 6 => ['count' => 1, 'colors' => ['G']]];

        $plan = ManaPayer::plan(['mana' => ['generic' => 2], 'life' => 0], $pool, $sources);
        $this->assertSame(['G' => 1], $plan['pool']);
        $this->assertCount(1, $plan['tap']);

        // Sol Ring-like source: tapping it for {1} leaves {C} floating.
        $plan = ManaPayer::plan(['mana' => ['generic' => 2], 'life' => 0], new ManaPool(), $sources);
        $this->assertSame([5], $plan['tap'], 'One source that makes two beats two sources.');
        $plan = ManaPayer::plan(['mana' => ['G' => 1, 'generic' => 1], 'life' => 0], new ManaPool(), [5 => $sources[5], 6 => $sources[6]]);
        $this->assertEqualsCanonicalizing([5, 6], $plan['tap']);
        $this->assertSame(['C' => 1], $plan['float']);
    }

    public function testPool(): void
    {
        $pool = new ManaPool(['G' => 2, 'C' => 1]);
        $this->assertSame(3, $pool->total());
        $this->assertSame('{G}{G}{C}', (string) $pool);
        $pool->remove('G');
        $this->assertSame(['G' => 1, 'C' => 1], $pool->toArray());
        $pool->empty();
        $this->assertSame(0, $pool->total());

        $this->expectException(\LogicException::class);
        $pool->remove('W');
    }
}
