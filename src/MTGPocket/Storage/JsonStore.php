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

namespace MTGPocket\Storage;

/**
 * Documents kept as JSON files, one file per document:
 * `{directory}/{collection}/{id}.json`.
 *
 * Writes go to a temporary file that is renamed over the old one, so a crash
 * never leaves half a document behind. {@see update()} holds an exclusive
 * lock on the document for the whole read-modify-write, so two writers (two
 * bot processes, or the bot and the importer) never lose each other's
 * changes.
 *
 * @since 0.1.0
 */
class JsonStore
{
    /**
     * Collection names and document ids: letters, digits, `_`, `-` and `.`,
     * not starting with a dot, so neither can climb out of the directory.
     *
     * @var string
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9_\-][A-Za-z0-9_\-.]{0,127}$/';

    /**
     * @param string $directory Where the collections live, e.g. `var/data`.
     */
    public function __construct(protected string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * The base directory.
     *
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Reads a document.
     *
     * @param string $collection
     * @param string $id
     *
     * @throws \RuntimeException When the file exists but is not valid JSON.
     *
     * @return array|null Null when there is no such document.
     */
    public function get(string $collection, string $id): ?array
    {
        $path = $this->path($collection, $id);
        if (! is_file($path)) {
            return null;
        }

        // Writers rename a whole new file into place, so a plain read sees
        // either the old document or the new one, never a mix.
        $json = @file_get_contents($path);

        return $json === false ? null : $this->decode($json, $path);
    }

    /**
     * Writes a document, replacing any old one.
     *
     * @param string $collection
     * @param string $id
     * @param array  $data
     */
    public function put(string $collection, string $id, array $data): void
    {
        $this->update($collection, $id, fn () => $data);
    }

    /**
     * Reads, changes and writes a document under an exclusive lock.
     *
     * @param string                    $collection
     * @param string                    $id
     * @param callable(?array): ?array  $change Gets the current document (null when there is none) and returns the new one; returning null deletes it.
     *
     * @return array|null The document as written.
     */
    public function update(string $collection, string $id, callable $change): ?array
    {
        $path = $this->path($collection, $id);
        $this->ensureDirectory(dirname($path));

        $lock = $this->open($path.'.lock', 'c');
        try {
            flock($lock, LOCK_EX);

            $current = is_file($path) ? $this->decode((string) file_get_contents($path), $path) : null;
            $next = $change($current);

            if ($next === null) {
                if (is_file($path)) {
                    unlink($path);
                }
            } else {
                $this->write($path, $next);
            }

            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Deletes a document. Deleting one that does not exist is not an error.
     *
     * @param string $collection
     * @param string $id
     */
    public function delete(string $collection, string $id): void
    {
        $this->update($collection, $id, fn () => null);
    }

    /**
     * Whether a document exists.
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function has(string $collection, string $id): bool
    {
        return is_file($this->path($collection, $id));
    }

    /**
     * The ids of every document in a collection, sorted.
     *
     * @param string $collection
     *
     * @return string[]
     */
    public function ids(string $collection): array
    {
        $directory = $this->directory.DIRECTORY_SEPARATOR.$this->name($collection);
        if (! is_dir($directory)) {
            return [];
        }

        $ids = array_map(fn (string $file) => basename($file, '.json'), glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: []);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * The file a document lives in.
     *
     * @param string $collection
     * @param string $id
     *
     * @throws \InvalidArgumentException When either name is not allowed.
     *
     * @return string
     */
    public function path(string $collection, string $id): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$this->name($collection).DIRECTORY_SEPARATOR.$this->name($id).'.json';
    }

    /**
     * @param string $name
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    protected function name(string $name): string
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException("Not a valid storage name: \"{$name}\".");
        }

        return $name;
    }

    /**
     * Writes next to the target and renames over it.
     *
     * @param string $path
     * @param array  $data
     */
    protected function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $json."\n") === false) {
            throw new \RuntimeException("Cannot write {$temporary}.");
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException("Cannot move {$temporary} into place at {$path}.");
        }
    }

    /**
     * @param string $json
     * @param string $path For the error message.
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    protected function decode(string $json, string $path): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$path} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($data)) {
            throw new \RuntimeException("{$path} does not hold a JSON object or array.");
        }

        return $data;
    }

    /**
     * @param string $path
     * @param string $mode
     *
     * @throws \RuntimeException
     *
     * @return resource
     */
    protected function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }

    /**
     * @param string $directory
     *
     * @throws \RuntimeException
     */
    protected function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
    }
}
