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

use MTGPocket\Cards\FunctionalCoverageLedger;

final class FunctionalCoverageLedgerTest extends StorageTestCase
{
    private function pools(): array
    {
        return [
            ['setCode' => 'OLD', 'releaseDate' => '2020-01-01', 'mtgjsonVersion' => 'fixture', 'cards' => [
                ['name' => 'Known', 'text' => 'Old wording.'],
            ]],
            ['setCode' => 'NEW', 'releaseDate' => '2026-01-01', 'mtgjsonVersion' => 'fixture', 'cards' => [
                ['name' => 'Known', 'text' => 'Draw a card.', 'back' => ['text' => 'Back wording.']],
                ['name' => 'Unread', 'text' => 'Unknown mechanic.'],
                ['name' => 'Plain', 'text' => null],
            ]],
        ];
    }

    private function inputs(): array
    {
        $selected = FunctionalCoverageLedger::select($this->pools());
        $recognition = [
            'schemaVersion' => 1, 'corpus' => $selected['corpus'],
            'recognition' => ['total' => 3, 'whole' => 2, 'withUnreadText' => 1, 'unreadClauses' => 1],
            'recognizedNames' => ['Known', 'Plain'],
        ];
        $manifest = [
            'schemaVersion' => 1, 'corpus' => $selected['corpus'],
            'recognitionReportSha256' => hash('sha256', json_encode($recognition)),
            'sourcePins' => ['tests/Fixture.php' => hash('sha256', "fixture\n")],
            'scopedEvidence' => [
                ['id' => 'draw', 'scope' => 'Draw only', 'cards' => ['Known'], 'tests' => ['tests/Fixture.php::testDraw'], 'limits' => 'Other abilities unverified'],
                ['id' => 'draw-repeat', 'scope' => 'Draw replay', 'cards' => ['Known'], 'tests' => ['tests/Fixture.php::testDraw'], 'limits' => 'Other abilities unverified'],
            ],
            'explicitUnsupported' => [
                ['id' => 'known-gap', 'scope' => 'Known interaction gap', 'cards' => ['Known']],
                ['id' => 'unread-gap', 'scope' => 'Unknown mechanic', 'cards' => ['Unread']],
            ],
            'nextFamily' => ['cards' => ['Known'], 'acceptance' => ['Two-controller positive and negative controls']],
        ];

        return [$selected, $recognition, $manifest];
    }

    public function testPartialEvidenceAndRecognitionNeverIssueCertificates(): void
    {
        [$selection, $recognition, $manifest] = $this->inputs();
        $report = FunctionalCoverageLedger::report($selection, $recognition, $manifest);
        $this->assertSame(2, $report['assessment']['recognizedWithoutWholeCardCertificate']);
        $this->assertSame(1, $report['assessment']['cardsWithScopedEvidence'], 'Two overlapping tests count one card.');
        $this->assertSame(2, $report['assessment']['cardsWithExplicitUnsupportedGap']);
        $this->assertSame(1, $report['assessment']['cardsWithBothEvidenceAndGap']);
        $this->assertSame(0, $report['assessment']['wholeCardCertificates']);
        $this->assertSame(3, $report['assessment']['remainingWholeCardReviews']);
        $this->assertSame('unread_text', $report['cards']['Unread']['recognition']);
        $this->assertNull($report['cards']['Known']['wholeCardCertificate']);
        $this->assertCount(2, $report['cards']['Known']['scopedEvidence']);
    }

    public function testNewestPrintingAndInputOrderHaveStableIdentityIncludingBackFaces(): void
    {
        $pools = $this->pools();
        $selection = FunctionalCoverageLedger::select($pools);
        $this->assertSame($selection, FunctionalCoverageLedger::select(array_reverse($pools)));
        $this->assertSame('NEW', $selection['cards']['Known']['setCode']);
        $pools[1]['cards'][0]['back']['text'] = 'Changed back face.';
        $this->assertNotSame($selection['corpus'], FunctionalCoverageLedger::select($pools)['corpus']);
    }

    public function testSourcePinsNormalizeWindowsLineEndings(): void
    {
        [, $recognition, $manifest] = $this->inputs();
        $this->assertSame($recognition, FunctionalCoverageLedger::verifyInputs(json_encode($recognition), $manifest, ['tests/Fixture.php' => "fixture\r\n"]));
    }

    /** @dataProvider invalidCases */
    public function testRejectsDriftAndInflatedEvidence(string $case): void
    {
        [$selection, $recognition, $manifest] = $this->inputs();
        switch ($case) {
            case 'corpus':
                $selection['corpus']['pools']++;
                break;
            case 'count':
                $recognition['recognition']['whole']++;
                break;
            case 'unknown-recognized':
                $recognition['recognizedNames'][0] = 'Missing';
                break;
            case 'duplicate-recognized':
                $recognition['recognizedNames'][1] = 'Known';
                break;
            case 'unknown-evidence':
                $manifest['scopedEvidence'][0]['cards'] = ['Missing'];
                break;
            case 'duplicate-id':
                $manifest['explicitUnsupported'][0]['id'] = 'draw';
                break;
            case 'unpinned-test':
                $manifest['scopedEvidence'][0]['tests'] = ['tests/Missing.php::testDraw'];
                break;
            case 'missing-limits':
                $manifest['scopedEvidence'][0]['limits'] = '';
                break;
            case 'certificate':
                $manifest['wholeCardCertificates'] = ['Known'];
                break;
            case 'unknown-next-family':
                $manifest['nextFamily']['cards'] = ['Missing'];
                break;
        }
        $this->expectException(\InvalidArgumentException::class);
        FunctionalCoverageLedger::report($selection, $recognition, $manifest);
    }

    public static function invalidCases(): array
    {
        return array_map(fn (string $case) => [$case], ['corpus', 'count', 'unknown-recognized', 'duplicate-recognized', 'unknown-evidence', 'duplicate-id', 'unpinned-test', 'missing-limits', 'certificate', 'unknown-next-family']);
    }

    /** @dataProvider inputDrift */
    public function testRejectsChangedReportOrSource(bool $reportDrift): void
    {
        [, $recognition, $manifest] = $this->inputs();
        $this->expectException(\InvalidArgumentException::class);
        FunctionalCoverageLedger::verifyInputs(json_encode($recognition).($reportDrift ? ' ' : ''), $manifest, ['tests/Fixture.php' => $reportDrift ? "fixture\n" : "changed\n"]);
    }

    public static function inputDrift(): array
    {
        return [[true], [false]];
    }

    public function testVersionedRecognitionIndexAndEvidenceSourcesArePinned(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode(file_get_contents($root.'/config/coverage-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
        $sources = [];
        foreach ($manifest['sourcePins'] as $path => $hash) {
            $sources[$path] = file_get_contents($root.'/'.$path);
        }
        $recognition = FunctionalCoverageLedger::verifyInputs(file_get_contents($root.'/config/coverage-recognition-2026-10-22.json'), $manifest, $sources);
        $this->assertCount(11282, array_unique($recognition['recognizedNames']));
        $this->assertSame(28729, $recognition['recognition']['total']);
        $this->assertSame(17447, $recognition['recognition']['withUnreadText']);
        $this->assertSame($recognition['corpus'], $manifest['corpus']);
        $this->assertSame($recognition['derivedFrom']['originalReportSha256'], $manifest['originalReportSha256']);
    }

    public function testCliUsesExplicitFixturesAndRejectsChangedInputWithoutPartialOutput(): void
    {
        mkdir($this->directory.'/pools', 0777, true);
        foreach ($this->pools() as $pool) {
            file_put_contents($this->directory.'/pools/'.$pool['setCode'].'.json', json_encode($pool));
        }
        [, $recognition, $manifest] = $this->inputs();
        $manifest['sourcePins'] = ['tests/FunctionalCoverageLedgerTest.php' => hash('sha256', str_replace("\r\n", "\n", file_get_contents(__FILE__)))];
        foreach ($manifest['scopedEvidence'] as &$claim) {
            $claim['tests'] = ['tests/FunctionalCoverageLedgerTest.php::testPartialEvidenceAndRecognitionNeverIssueCertificates'];
        }
        unset($claim);
        file_put_contents($this->directory.'/report.json', json_encode($recognition));
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest));
        [$exit, $stdout, $stderr] = $this->runCli();
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stderr);
        $this->assertSame(3, json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['assessment']['targetWholeCardReviews']);
        file_put_contents($this->directory.'/report.json', ' ', FILE_APPEND);
        [$exit, $stdout, $stderr] = $this->runCli();
        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('changed recognition report', $stderr);
    }

    private function runCli(): array
    {
        $process = proc_open([
            PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/bin/functional-coverage.php',
            $this->directory.'/pools', $this->directory.'/report.json', $this->directory.'/manifest.json',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
