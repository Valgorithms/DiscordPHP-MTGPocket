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


// Offline only. Each writer runs in its own process after resource clearance.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$writer = $argv[1] ?? 'current';
if (! in_array($writer, ['current', 'main', 'pr331'], true)) {
    throw new InvalidArgumentException('Choose current, main, or pr331.');
}
if ($writer !== 'current') {
    $readerDirectory = dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers';
    $manifest = json_decode(file_get_contents($readerDirectory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR)[$writer];
    foreach ($manifest['files'] as $name => $hashes) {
        if (! hash_equals($hashes['sha256'], hash_file('sha256', $readerDirectory.'/'.$writer.'/'.$name))) {
            throw new RuntimeException('Historical writer source hash mismatch.');
        }
    }
    require dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/'.$writer.'/Game.php';
    require dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/'.$writer.'/TextParser.php';
}

use MTGPocket\Cards\BasicLands;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\Step;

$deck = array_fill(0, 27, BasicLands::card('basic:Forest'));
for ($seed = 0; $seed < 100; $seed++) {
    $game = Game::start('selector-compatibility', [
        ['id' => '1', 'name' => 'Alice', 'cards' => $deck],
        ['id' => '2', 'name' => 'Bob', 'cards' => $deck],
    ], 'selector-compatibility-'.$seed);
    if ($game->startingPlayer === 0) {
        break;
    }
}
if ($game->startingPlayer !== 0) {
    throw new RuntimeException('No deterministic fixture seed found.');
}
$game->setAutoPass(0, false);
$game->setAutoPass(1, false);
$game->keep(0);
$game->keep(1);
for ($passes = 0; $game->step !== Step::PrecombatMain && $passes < 20; $passes++) {
    if ($game->priority === null) {
        throw new RuntimeException('Unexpected fixture decision.');
    }
    $game->pass($game->priority);
}
if ($game->step !== Step::PrecombatMain || $game->priority !== 0) {
    throw new RuntimeException('Fixture did not reach the first main phase.');
}
if ($writer === 'current') {
    $device = $game->addCard(0, [
        'name' => 'Union Device', 'type' => 'Artifact',
        'text' => 'Sacrifice this artifact: Destroy all artifacts, creatures, and lands.',
    ], GameObject::BATTLEFIELD);
    $game->activate(0, $device->id, 0);
} else {
    $device = $game->addCard(0, [
        'name' => 'Delayed Ledger', 'type' => 'Artifact',
        'text' => "Sacrifice this artifact: Draw a card at the beginning of the next turn's upkeep.",
    ], GameObject::BATTLEFIELD);
    $game->activate(0, $device->id, 0);
    $game->pass($game->priority);
    $game->pass($game->priority);
    $pyromancer = $game->addCard(0, [
        'name' => 'Prodigal Pyromancer', 'manaCost' => '{2}{R}', 'type' => 'Creature - Human Wizard',
        'power' => '1', 'toughness' => '1', 'text' => '{T}: This creature deals 1 damage to any target.',
    ], GameObject::BATTLEFIELD);
    $pyromancer->sick = false;
    $game->activate(0, $pyromancer->id, 0, ['p:1']);
}
$saved = $game->toArray();
if ($writer === 'current') {
    if ($saved['schemaVersion'] !== 4 ||
        ($saved['stack'][0]['effects'][0]['all'] ?? null) !== 'all artifacts, creatures, and lands') {
        throw new RuntimeException('Fixture does not contain the new saved contract.');
    }
} elseif ($saved['schemaVersion'] !== 3 || count($saved['stack']) !== 1 || count($saved['delayedDraws']) !== 1) {
    throw new RuntimeException('Historical writer did not create the expected schema-3 fixture.');
}
$json = json_encode($saved, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
$target = dirname(__DIR__, 2).'/var/selector-compatibility'.($writer === 'current' ? '/selector-version4-fixture.json' : '/selector-readers/'.$writer.'/version3-fixture.json');
if (file_put_contents($target, $json) !== strlen($json)) {
    throw new RuntimeException('Could not write complete fixture.');
}
echo json_encode(['writer' => $writer, 'fixtureSha256' => hash('sha256', $json),
    'schemaVersion' => $saved['schemaVersion']], JSON_THROW_ON_ERROR)."\n";
