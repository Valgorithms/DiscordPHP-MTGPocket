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

use MTGPocket\Cards\CoverageAudit;
use PHPUnit\Framework\TestCase;

/** @covers \MTGPocket\Cards\CoverageAudit */
final class CoverageAuditTest extends TestCase
{
    private function pool(string $code, string $date, array $cards): array
    {
        return ['setCode' => $code, 'releaseDate' => $date, 'mtgjsonVersion' => 'fixture', 'cards' => $cards];
    }

    public function testRecognitionDoesNotBecomeGameplayEvidence(): void
    {
        $report = CoverageAudit::report([$this->pool('NEW', '2026-01-01', [
            ['name' => 'Blank', 'type' => 'Creature'],
            ['name' => 'Readable', 'type' => 'Creature', 'text' => 'Flying'],
            ['name' => 'Partial', 'type' => 'Creature', 'text' => "Flying\nUnimplemented action."],
            ['name' => 'Double', 'type' => 'Creature', 'back' => ['name' => 'Back', 'type' => 'Creature', 'text' => 'Unimplemented back.']],
        ])]);
        $this->assertSame(['total' => 4, 'whole' => 2, 'withUnreadText' => 2, 'unreadClauses' => 2], $report['recognition']);
        $this->assertSame(['Blank', 'Readable'], $report['recognizedNames']);
        foreach (['implementedRules', 'supportedWholeCards', 'gameplayTestedWholeCards'] as $tier) {
            $this->assertNull($report[$tier]['count']);
            $this->assertNotEmpty($report[$tier]['reason']);
        }
    }

    public function testNewestPrintingDedupAndLegalityAreDeterministic(): void
    {
        $old = $this->pool('OLD', '2025-01-01', [['name' => 'Reprint', 'text' => 'Unknown.']]);
        $new = $this->pool('NEW', '2026-01-01', [['name' => 'Reprint', 'text' => 'Flying'], ['name' => 'Other', 'text' => 'Unknown.']]);
        $a = CoverageAudit::report([$old, $new], ['Reprint']);
        $b = CoverageAudit::report([$new, $old], ['Reprint']);
        $this->assertSame($a, $b);
        $this->assertSame(1, $a['recognition']['whole']);
        $this->assertSame(1, $a['recognition']['total']);
        $this->assertSame(0, CoverageAudit::report([$new], [])['recognition']['total']);
        $tie = $this->pool('ZZZ', '2026-01-01', [['name' => 'Reprint', 'text' => 'Unknown.']]);
        $this->assertSame(0, CoverageAudit::report([$new, $tie], ['Reprint'])['recognition']['whole']);
    }

    public function testFingerprintChangesWithCardDataButNotImportTimeAndCountsPatternsPerCard(): void
    {
        $pool = $this->pool('SET', '2026-01-01', [['name' => 'A', 'text' => "Unknown 1.\nUnknown 2."], ['name' => 'B', 'text' => 'Unknown 3.']]);
        $a = CoverageAudit::report([$pool]);
        $pool['importedAt'] = 123;
        $this->assertSame($a, CoverageAudit::report([$pool]));
        $this->assertSame(2, $a['unreadPatterns'][0]['cards']);
        $this->assertSame(3, $a['recognition']['unreadClauses']);
        $pool['cards'][0]['back'] = ['name' => 'Back', 'type' => 'Creature', 'text' => 'Flying'];
        $this->assertNotSame($a['corpus']['selectedCardsSha256'], CoverageAudit::report([$pool])['corpus']['selectedCardsSha256']);
    }

    public function testMalformedPoolIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CoverageAudit::report([['cards' => []]]);
    }
}
