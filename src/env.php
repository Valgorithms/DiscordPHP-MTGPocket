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

/**
 * Minimal `KEY=value` .env loader, as in DiscordPHP-MTG. The real
 * environment wins over the file.
 *
 * @param string $filePath
 * @param bool   $required Throw when the file is missing.
 */
function loadEnv(string $filePath, bool $required = true): void
{
    if (! file_exists($filePath)) {
        if ($required) {
            throw new \RuntimeException("The .env file does not exist at {$filePath}.");
        }

        return;
    }

    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (! array_key_exists($name, $_ENV) && getenv($name) === false) {
            putenv("{$name}={$value}");
        }
    }
}
