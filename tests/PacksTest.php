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

namespace MTGPocket\Tests;

use MTGPocket\Cards\CardPool;
use MTGPocket\Packs\DailyPacks;
use MTGPocket\Packs\DailyPackUnavailableException;
use MTGPocket\Packs\PackGenerator;
use MTGPocket\Pocket;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * @covers \MTGPocket\Packs\PackGenerator
 * @covers \MTGPocket\Packs\Pack
 * @covers \MTGPocket\Packs\DailyPacks
 * @covers \MTGPocket\Packs\OpenedPack
 * @covers \MTGPocket\Packs\DailyPackUnavailableException
 * @covers \MTGPocket\Repository\CardPoolRepository
 */
final class PacksTest extends PocketTestCase
{
    public function testEveryPackIsFifteenCardsOfOneColorWithARare(): void
    {
        $pool = $this->importPool('TST', ['R' => self::fullColor(), 'U' => self::fullColor()]);
        $generator = new PackGenerator(new Randomizer(new Xoshiro256StarStar(7)));

        $mythics = 0;
        for ($i = 0; $i < 500; $i++) {
            $pack = $generator->generate($pool, 'R');

            $this->assertCount(PackGenerator::SIZE, $pack->cards);
            $this->assertSame('TST', $pack->setCode);
            foreach ($pack->cards as $card) {
                $this->assertSame(['R'], $card['colors']);
            }
            $this->assertNotEmpty($pack->ofRarity('rare', 'mythic'), 'Every pack holds at least one rare or mythic rare.');
            $this->assertContains($pack->cards[PackGenerator::SIZE - 1]['rarity'], ['rare', 'mythic'], 'The rare slot is revealed last.');
            $this->assertCount(PackGenerator::SIZE, $pack->counts(), 'No card repeats while the color has enough.');
            $mythics += $pack->cards[PackGenerator::SIZE - 1]['rarity'] === 'mythic' ? 1 : 0;
        }

        // 2 mythics against 8 rares printed twice: a mythic in 1 of 9 rare slots.
        $this->assertGreaterThan(25, $mythics);
        $this->assertLessThan(90, $mythics);
    }

    public function testSmallColorsFallBackAndStillGuaranteeARare(): void
    {
        // Colorless with no commons, two uncommons and one mythic.
        $pool = $this->importPool('TST', [CardPool::COLORLESS => ['uncommon' => 2, 'mythic' => 1]]);

        $pack = (new PackGenerator(new Randomizer(new Xoshiro256StarStar(1))))->generate($pool, CardPool::COLORLESS);

        $this->assertCount(PackGenerator::SIZE, $pack->cards);
        $this->assertSame('mythic', $pack->cards[PackGenerator::SIZE - 1]['rarity']);
    }

    public function testTheRareSlotKeepsItsCardWhenTheWildcardRollsRare(): void
    {
        // Exactly enough distinct cards: 14 commons and the only rare.
        $pool = $this->importPool('TST', ['U' => ['common' => 14, 'rare' => 1]]);

        for ($seed = 0; $seed < 200; $seed++) {
            $pack = (new PackGenerator(new Randomizer(new Xoshiro256StarStar($seed))))->generate($pool, 'U');

            $this->assertCount(PackGenerator::SIZE, $pack->counts(), "Seed {$seed} repeated a card.");
            $this->assertSame('TST-U-rare-1', $pack->cards[PackGenerator::SIZE - 1]['uuid']);
        }
    }

    public function testAColorWithoutRaresHasNoPacks(): void
    {
        $pool = $this->importPool('TST', ['G' => ['common' => 20, 'uncommon' => 5]]);

        $this->expectException(\InvalidArgumentException::class);
        (new PackGenerator())->generate($pool, 'G');
    }

    public function testChoicesSkipColorsWithoutRares(): void
    {
        $this->importPool('NEW', ['W' => self::fullColor()], '2024-01-01');
        $this->importPool('OLD', ['G' => ['common' => 10], 'B' => self::fullColor()], '2010-01-01');

        $this->assertSame([
            'OLD' => ['name' => 'Set OLD', 'colors' => ['B']],
            'NEW' => ['name' => 'Set NEW', 'colors' => ['W']],
        ], $this->pocket->dailyPacks->choices());
    }

    public function testOneFreePackPerUtcDay(): void
    {
        $this->importPool('TST', ['R' => self::fullColor()]);
        $packs = $this->pocket->dailyPacks;

        $opened = $packs->open('1', 'Val', 'tst', 'r');

        $this->assertSame('R', $opened->pack->color);
        $this->assertSame(DailyPacks::nextDay($this->now), $opened->nextPackAt);
        $this->assertSame(PackGenerator::SIZE, $this->pocket->inventories->get('1')->cards->total());
        $this->assertSame(PackGenerator::SIZE, count($opened->new));
        $this->assertSame($this->now, $this->pocket->players->find('1')->lastDailyPackAt);

        try {
            $packs->open('1', 'Val');
            $this->fail('A second pack the same day must be refused.');
        } catch (DailyPackUnavailableException $e) {
            $this->assertSame(DailyPacks::nextDay($this->now), $e->availableAt);
            $this->assertStringContainsString('<t:'.$e->availableAt.':R>', $e->getMessage());
        }
        $this->assertSame(DailyPacks::nextDay($this->now), $packs->nextPackAt($this->pocket->players->find('1')));

        // Just after midnight UTC the next one is ready.
        $this->now = DailyPacks::nextDay($this->now);
        $this->assertNull($packs->nextPackAt($this->pocket->players->find('1')));
        $second = $packs->open('1', 'Val');
        $this->assertSame(2 * PackGenerator::SIZE, $this->pocket->inventories->get('1')->cards->total());
        $this->assertLessThan(PackGenerator::SIZE, count($second->new), 'Cards already owned are not new.');
    }

    public function testAPackWhoseCardsCannotBeSavedDoesNotUseUpTheDay(): void
    {
        $this->importPool('TST', ['R' => self::fullColor()]);
        mkdir($this->directory.'/inventories', 0777, true);
        file_put_contents($this->directory.'/inventories/1.json', '{broken');

        try {
            $this->pocket->dailyPacks->open('1', 'Val');
            $this->fail('A broken inventory must stop the pack.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not valid JSON', $e->getMessage());
        }
        $this->assertNull($this->pocket->players->find('1')->lastDailyPackAt, 'The day is still free to claim.');

        unlink($this->directory.'/inventories/1.json');
        $this->assertSame(PackGenerator::SIZE, count($this->pocket->dailyPacks->open('1', 'Val')->pack->cards));
    }

    public function testPacksLeftToChanceComeFromValidChoices(): void
    {
        $this->importPool('AAA', ['W' => self::fullColor(), 'G' => ['common' => 5]]);
        $this->importPool('BBB', ['B' => self::fullColor()]);

        for ($player = 1; $player <= 20; $player++) {
            $pack = $this->pocket->dailyPacks->open((string) $player)->pack;
            $this->assertContains([$pack->setCode, $pack->color], [['AAA', 'W'], ['BBB', 'B']]);
        }

        $pack = $this->pocket->dailyPacks->open('21', '', null, 'B')->pack;
        $this->assertSame(['BBB', 'B'], [$pack->setCode, $pack->color]);
        $pack = $this->pocket->dailyPacks->open('22', '', 'Set AAA')->pack;
        $this->assertSame(['AAA', 'W'], [$pack->setCode, $pack->color]);
    }

    /**
     * @dataProvider unavailablePacks
     */
    public function testRefusesPacksThatDoNotExist(?string $set, ?string $color, string $message): void
    {
        $this->importPool('TST', ['R' => self::fullColor(), 'G' => ['common' => 5]]);

        try {
            $this->pocket->dailyPacks->open('1', 'Val', $set, $color);
            $this->fail('Expected the pack to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertNull($this->pocket->players->find('1')->lastDailyPackAt, 'A refused pack does not use up the day.');
    }

    public static function unavailablePacks(): array
    {
        return [
            'unknown set' => ['NOPE', null, 'no packs of NOPE'],
            'set name with spaces' => ['Not A Set', null, 'no packs of Not A Set'],
            'unknown color' => [null, 'X', 'no color X'],
            'color without rares' => ['TST', 'G', 'has no G packs'],
            'no set has the color' => [null, 'U', 'No set has U packs'],
        ];
    }

    public function testNoPoolsMeansNoPacks(): void
    {
        $this->expectExceptionMessage('no card pools have been imported');
        $this->pocket->dailyPacks->open('1');
    }

    public function testFindsCardsAcrossPools(): void
    {
        $this->importPool('AAA', ['W' => ['common' => 1]]);
        $this->importPool('BBB', ['U' => ['rare' => 1]]);

        $this->assertSame('BBB', $this->pocket->pools->card('BBB-U-rare-1')['setCode']);
        $this->assertSame('Set AAA', $this->pocket->pools->card('AAA-W-common-1')['setName']);
        $this->assertNull($this->pocket->pools->card('nope'));

        // A set imported by another process (the importer) is found without a restart.
        $importer = new Pocket($this->directory);
        $pool = new CardPool('CCC', 'Set CCC', '2025-01-01');
        $pool->add(['uuid' => 'CCC-G-rare-1', 'name' => 'New card', 'rarity' => 'rare', 'colors' => ['G']]);
        $importer->pools->save($pool);
        $this->assertSame('CCC', $this->pocket->pools->card('CCC-G-rare-1')['setCode']);
    }
}
