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

// Rewrites the SHA-256 of every file already listed under `sourcePins` in
// config/coverage-evidence.json, leaving the rest of the manifest byte-for-byte
// alone. It never adds or removes a pin. Hashes normalize CRLF like
// FunctionalCoverageLedger::verifyInputs(). Pass --check to only report drift.
$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$file = $root.'/config/coverage-evidence.json';
$json = (string) file_get_contents($file);
$pins = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['sourcePins'] ?? [];
$start = strpos($json, '"sourcePins"');
$end = $start === false ? false : strpos($json, '}', $start);
if ($pins === [] || $start === false || $end === false) {
    fwrite(STDERR, "No sourcePins block found.\n");
    exit(1);
}

$block = substr($json, $start, $end - $start);
$changed = [];
foreach ($pins as $path => $old) {
    if (! is_file($root.'/'.$path)) {
        fwrite(STDERR, "Pinned file is missing: {$path}\n");
        exit(1);
    }
    $new = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($root.'/'.$path)));
    if ($new === $old) {
        continue;
    }
    $pattern = '/('.preg_quote(json_encode($path, JSON_UNESCAPED_SLASHES), '/').'\s*:\s*")'.$old.'(")/';
    $block = preg_replace($pattern, '${1}'.$new.'${2}', $block, 1, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Could not locate the pin for {$path}\n");
        exit(1);
    }
    $changed[] = $path;
}

if ($changed !== [] && ! $check) {
    file_put_contents($file, substr($json, 0, $start).$block.substr($json, $end));
}
echo $changed === [] ? "All ".count($pins)." source pins match.\n"
    : ($check ? 'Stale' : 'Refreshed').' pins ('.count($changed)."):\n  ".implode("\n  ", $changed)."\n";
exit($check && $changed !== [] ? 1 : 0);
