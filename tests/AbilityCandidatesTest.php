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

final class AbilityCandidatesTest extends StorageTestCase
{
    public function testOnlyLatestMatchingNamesAreParsedIncludingBackFacesAndWhitespace(): void
    {
        mkdir($this->directory.'/pools', 0777, true);
        foreach ([
            ['setCode' => 'OLD', 'releaseDate' => '2020-01-01', 'cards' => [
                ['name' => 'Changed', 'text' => 'Unknown mechanic.'],
            ]],
            ['setCode' => 'NEW', 'releaseDate' => '2026-01-01', 'cards' => [
                ['name' => 'Changed', 'text' => 'Draw a card.'],
                ['name' => 'Not a match', 'text' => 'Draw a card.'],
                ['name' => 'Back', 'text' => '', 'back' => ['name' => 'Reverse', 'text' => "Unknown\n  mechanic."]],
                ['name' => 'Front', 'text' => 'Unknown mechanic.'],
            ]],
        ] as $pool) {
            file_put_contents($this->directory.'/pools/'.$pool['setCode'].'.json', json_encode($pool));
        }
        [$exit, $stdout, $stderr] = $this->runCli('unknown mechanic');
        $this->assertSame([0, ''], [$exit, $stderr]);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(4, $result['selectedNames']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(['Back', 'Front'], array_column(array_column($result['candidates'], 'card'), 'name'));
        foreach ($result['candidates'] as $candidate) {
            $this->assertNotEmpty($candidate['unsupported']);
        }
        [$exit, $stdout, $stderr] = $this->runCli('no matching text');
        $this->assertSame([0, ''], [$exit, $stderr]);
        $this->assertSame([], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['candidates']);
    }

    public function testInvalidArgumentsFailWithoutPartialJson(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli('');
        $this->assertSame([1, ''], [$exit, $stdout]);
        $this->assertStringContainsString('Usage:', $stderr);
        [$exit, $stdout, $stderr] = $this->runCli('artifact');
        $this->assertSame([1, ''], [$exit, $stdout]);
        $this->assertStringContainsString('No pool files', $stderr);
    }

    private function runCli(string $fragment): array
    {
        $process = proc_open([
            PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/bin/ability-candidates.php',
            $this->directory.'/pools', $fragment,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
