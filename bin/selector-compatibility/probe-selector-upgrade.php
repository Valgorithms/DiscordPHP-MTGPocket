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


require dirname(__DIR__, 2).'/vendor/autoload.php';

use MTGPocket\Game\Game;
use MTGPocket\Game\Step;

$label = $argv[1] ?? '';
if (! in_array($label, ['main', 'pr331'], true)) {
    throw new InvalidArgumentException('Choose main or pr331.');
}
$fixture = dirname(__DIR__, 2).'/var/selector-compatibility/selector-readers/'.$label.'/version3-fixture.json';
$saved = json_decode(file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);
if (Game::STATE_VERSION !== 4 || $saved['schemaVersion'] !== 3) {
    throw new RuntimeException('Unexpected current reader or historical writer.');
}
$game = Game::fromArray($saved);
$expected = $saved;
$expected['schemaVersion'] = 4;
if ($game->toArray() !== $expected) {
    throw new RuntimeException('Migration changed historical state beyond the schema label.');
}
$game->pass($game->priority);
$game->pass($game->priority);
if ($game->players[1]->life !== 19 || count($game->delayedDraws) !== 1 || $game->stack !== []) {
    throw new RuntimeException('Pending historical ability did not preserve its meaning.');
}
$hand = count($game->players[0]->hand);
for ($passes = 0; ! ($game->step === Step::Upkeep && $game->turn === 2) && $passes < 100; $passes++) {
    if ($game->decision($game->active) === 'attack') {
        $game->declareAttackers($game->active, []);
    } elseif ($game->decision($game->defender()) === 'block') {
        $game->declareBlockers($game->defender(), []);
    } elseif ($game->priority !== null) {
        $game->pass($game->priority);
    } else {
        throw new RuntimeException('Unexpected migrated-fixture decision.');
    }
}
if ($game->step !== Step::Upkeep || $game->turn !== 2 || count($game->stack) !== 1 || $game->delayedDraws !== []) {
    throw new RuntimeException('Historical delayed draw was not queued exactly once.');
}
$game->pass($game->priority);
$game->pass($game->priority);
if (count($game->players[0]->hand) !== $hand + 1 || $game->stack !== [] || $game->delayedDraws !== []) {
    throw new RuntimeException('Historical delayed draw did not resolve exactly once.');
}
echo json_encode(['historicalWriter' => $label, 'fixtureSha256' => hash_file('sha256', $fixture),
    'result' => 'migrated-without-semantic-change', 'damage' => 1, 'delayedDraw' => 1], JSON_THROW_ON_ERROR)."\n";
