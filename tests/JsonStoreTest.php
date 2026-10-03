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

use MTGPocket\Storage\JsonStore;

/**
 * @covers \MTGPocket\Storage\JsonStore
 */
final class JsonStoreTest extends StorageTestCase
{
    public function testWritesAndReadsDocuments(): void
    {
        $store = new JsonStore($this->directory);

        $this->assertNull($store->get('players', '1'));
        $store->put('players', '1', ['name' => 'Jace']);

        $this->assertSame(['name' => 'Jace'], $store->get('players', '1'));
        $this->assertTrue($store->has('players', '1'));
        $this->assertFileExists($this->directory.'/players/1.json');
    }

    public function testUpdateSeesTheCurrentDocument(): void
    {
        $store = new JsonStore($this->directory);
        $store->put('counters', 'a', ['n' => 1]);

        $result = $store->update('counters', 'a', fn (?array $data) => ['n' => $data['n'] + 1]);

        $this->assertSame(['n' => 2], $result);
        $this->assertSame(['n' => 2], $store->get('counters', 'a'));
    }

    public function testUpdateReturningNullDeletes(): void
    {
        $store = new JsonStore($this->directory);
        $store->put('decks', 'x', ['a' => 1]);
        $store->delete('decks', 'x');

        $this->assertFalse($store->has('decks', 'x'));
        $store->delete('decks', 'never-existed');
        $this->assertSame([], $store->ids('decks'));
    }

    public function testListsIds(): void
    {
        $store = new JsonStore($this->directory);
        $store->put('pools', 'KTK', []);
        $store->put('pools', 'DMU', []);

        $this->assertSame(['DMU', 'KTK'], $store->ids('pools'));
        $this->assertSame([], $store->ids('nothing'));
    }

    public function testFailedChangeLeavesTheDocument(): void
    {
        $store = new JsonStore($this->directory);
        $store->put('players', '1', ['gold' => 5]);

        try {
            $store->update('players', '1', fn () => throw new \RuntimeException('nope'));
        } catch (\RuntimeException) {
        }

        $this->assertSame(['gold' => 5], $store->get('players', '1'));
        $this->assertSame([], glob($this->directory.'/players/*.tmp'));
    }

    /**
     * @dataProvider badNames
     */
    public function testRejectsPathsOutOfTheDirectory(string $collection, string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonStore($this->directory))->put($collection, $id, []);
    }

    public static function badNames(): array
    {
        return [
            'parent id' => ['players', '../escape'],
            'dot id' => ['players', '.hidden'],
            'slash collection' => ['a/b', '1'],
            'empty id' => ['players', ''],
        ];
    }

    public function testRejectsCorruptFiles(): void
    {
        $store = new JsonStore($this->directory);
        mkdir($this->directory.'/players', 0777, true);
        file_put_contents($this->directory.'/players/1.json', '{not json');

        $this->expectException(\RuntimeException::class);
        $store->get('players', '1');
    }
}
