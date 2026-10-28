<?php

declare(strict_types=1);

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;

final class DevourExperimentSchemaTest extends GameTestCase
{
    public function testDefaultOffRetainsMainVersionAndEnabledRoundTrips(): void
    {
        $this->newGame();
        $ordinary = $this->game->toArray();
        $this->assertSame(22, $ordinary['schemaVersion']);
        $this->assertArrayNotHasKey('devourChoiceMode', $ordinary);
        $this->game->setDevourChoices(true);
        $state = $this->game->toArray();
        $this->assertSame(23, $state['schemaVersion']);
        $this->assertSame(2, $state['devourChoiceMode']);
        $restored = Game::fromArray(json_decode(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($state, $restored->toArray());
        $restored->setDevourChoices(false);
        $this->assertSame($ordinary, $restored->toArray());
    }

    /** @dataProvider invalidVersions */
    public function testUnrelatedAndStrippedVersionsRefuse(array $changes): void
    {
        $this->newGame();
        $state = array_replace($this->game->toArray(), $changes);
        $this->expectException(GameException::class);
        Game::fromArray($state);
    }

    public static function invalidVersions(): array
    {
        return [
            [['schemaVersion' => 23]],
            [['schemaVersion' => 23, 'devourChoiceMode' => 1]],
            [['schemaVersion' => 16, 'devourChoiceMode' => 1]],
            [['schemaVersion' => 16, 'pendingChoice' => ['type' => 'devour']]],
        ];
    }

    public function testMainAndSchema22ReadersRejectExperiment(): void
    {
        $old16 = getenv('DEVOUR_OLD_SCHEMA16_ROOT');
        $old22 = getenv('DEVOUR_OLD_SCHEMA22_ROOT');
        $old23 = getenv('DEVOUR_OLD_SCHEMA23_ROOT');
        if (! $old16 || ! $old22 || ! $old23) {
            $this->markTestSkipped('Previous-reader checkouts are not configured for this offline harness.');
        }
        $this->newGame();
        $ordinary = $this->game->toArray();
        $this->game->setDevourChoices(true);
        $experimental = $this->game->toArray();
        $this->assertSame('ok', $this->previousReader($old22, $ordinary));
        $this->assertStringStartsWith('reject:', $this->previousReader($old16, $ordinary));
        $this->assertStringStartsWith('reject:', $this->previousReader($old16, $experimental));
        $this->assertStringStartsWith('reject:', $this->previousReader($old22, $experimental));
        $this->assertStringStartsWith('reject:', $this->previousReader($old23, $experimental));
        $this->lands(0, 'Mountain', 4);
        $this->battlefield(0, 'Grizzly Bears');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/thorn-thrash-devour.json'), true, flags: JSON_THROW_ON_ERROR);
        $source = $this->game->addCard(0, $fixture['card'], GameObject::HAND)->id;
        $this->game->cast(0, $source, 0, []);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $pending = $this->game->toArray();
        $this->assertSame('devour', $pending['pendingChoice']['type']);
        $this->assertStringStartsWith('reject:', $this->previousReader($old16, $pending));
        $this->assertStringStartsWith('reject:', $this->previousReader($old22, $pending));
        $this->assertStringStartsWith('reject:', $this->previousReader($old23, $pending));
    }

    private function previousReader(string $root, array $data): string
    {
        $code = 'require $argv[1]."/vendor/autoload.php"; try { \\MTGPocket\\Game\\Game::fromArray(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR)); echo "ok"; } catch (\\MTGPocket\\Game\\GameException $e) { echo "reject:".$e->getMessage(); }';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $code, $root], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($data, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);

        return $out;
    }
}
