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

namespace MTGPocket\Builders;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Game\Game;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Game\GameObject;
use MTGPocket\Game\GameRecord;
use MTGPocket\Matches\Ladder;
use MTGPocket\Matches\MatchRecord;
use MTGPocket\Matches\PanelActions;
use MTGPocket\Modes\GameMode;
use MTGPocket\Modes\GameModes;
use MTGPocket\Tutorial\Tutorial;

/**
 * The messages of a match:
 *
 * - the challenge, with **Accept** and **Decline**
 * - matchmaking: waiting in a queue, the game modes and each mode's ladder
 * - the board everyone sees: life, cards in each zone, the battlefield,
 *   the stack and what just happened
 * - each player's own action panel, {@see actions()} (only they see it): their hand and the
 *   choice in front of them, from keeping a hand to choosing blockers,
 *   with the abilities they can activate and targets for their triggers
 * - a game's full record for review, {@see log()}, as a text file
 *
 * Custom ids: `pocket:m:<matchId>:<action>[:<arg>]`.
 *
 * @since 0.3.0
 */
class MatchMessageBuilder extends PocketMessageBuilder
{
    /**
     * Attackers shown in the block panel, one picker each.
     */
    public const int MAX_BLOCK_PICKERS = 8;

    /**
     * A custom id for a match action.
     *
     * @param string $matchId
     * @param string $action
     * @param string ...$args
     *
     * @return string
     */
    public static function id(string $matchId, string $action, string ...$args): string
    {
        return implode(':', [self::PREFIX, 'm', $matchId, $action, ...$args]);
    }

    /**
     * An open challenge.
     *
     * @param MatchRecord $match
     *
     * @return static
     */
    public static function challenge(MatchRecord $match): static
    {
        $challenger = $match->challenger();
        $opponent = $match->opponent();

        return static::panel()
            ->setAllowedMentions(AllowedMentions::none()->addUser($opponent['id']))
            ->addComponent(Container::new()
                ->setAccentColor(CardMessageBuilder::ACCENTS['R'])
                ->addComponent(TextDisplay::new(sprintf(
                    "### ⚔️ A challenge!\n<@%s>, **%s** challenges you to a %s game of Magic with **%s**.\n-# You play your active deck (`/decks use` to change it); it has to meet the %s rules. Matches use full Magic rules; spells the engine cannot read yet still resolve without those parts.",
                    $opponent['id'],
                    $challenger['name'],
                    self::modeLabel($match),
                    $challenger['deckName'],
                    self::modeLabel($match),
                ))))
            ->addComponent(ActionRow::new()
                ->addComponent(Button::new(Button::STYLE_SUCCESS, self::id($match->id, 'accept'))->setLabel('Accept'))
                ->addComponent(Button::new(Button::STYLE_DANGER, self::id($match->id, 'decline'))->setLabel('Decline')));
    }

    /**
     * The name of a match's mode.
     *
     * @param MatchRecord $match
     *
     * @return string
     */
    private static function modeLabel(MatchRecord $match): string
    {
        return DeckBuilder::FORMATS[$match->mode] ?? ucfirst($match->mode);
    }

    /**
     * Waiting in a queue for an opponent.
     *
     * @param GameMode $mode
     * @param string   $deckName
     * @param int      $rating
     * @param int      $minutes  How long the spot lasts.
     *
     * @return static
     */
    public static function queued(GameMode $mode, string $deckName, int $rating, int $minutes): static
    {
        return static::notice(sprintf(
            "### 🔎 Looking for a %s opponent\nYou are in the queue with **%s** (rating %d). The next %s player close to your rating plays you, and you are pinged where they queue. The longer you wait, the wider the range of ratings.\n-# Your spot lasts %d minutes. `/match leave` takes you out.",
            $mode->label,
            $deckName,
            $rating,
            $mode->label,
            $minutes,
        ));
    }

    /**
     * The game modes, their deck rules and who is waiting in each.
     *
     * @param GameModes          $modes
     * @param array<string, int> $waiting Mode => players in its queue.
     *
     * @return static
     */
    public static function modes(GameModes $modes, array $waiting): static
    {
        $lines = [];
        foreach ($modes->all() as $id => $mode) {
            $status = $mode->playable ? Text::plural($waiting[$id] ?? 0, 'player').' waiting' : 'games coming soon';
            $lines[] = "**{$mode->label}** · {$status}\n-# {$mode->summary()}";
        }

        return static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['colorless'])
            ->addComponent(TextDisplay::new("### 🎲 Game modes\n-# A deck is built for one mode (`/decks format`). `/match queue` finds you an opponent; `/match challenge` plays someone you pick."))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(implode("\n", $lines))));
    }

    /**
     * A mode's ratings ladder.
     *
     * @param GameMode $mode
     * @param array[]  $standings Best first; see {@see Ladder::standings()}.
     * @param string   $playerId  Who asked, to show their place.
     * @param int      $top       How many to list.
     *
     * @return static
     */
    public static function ladder(GameMode $mode, array $standings, string $playerId, int $top = 10): static
    {
        $line = fn (int $rank, array $entry) => sprintf('%d. **%s** · %d · %d–%d%s', $rank, $entry['name'] !== '' ? $entry['name'] : 'Unknown', $entry['rating'], $entry['wins'], $entry['losses'], $entry['draws'] > 0 ? "–{$entry['draws']}" : '');
        $lines = [];
        $mine = null;
        foreach (array_values($standings) as $index => $entry) {
            if ($index < $top) {
                $lines[] = $line($index + 1, $entry);
            }
            if ($entry['id'] === $playerId) {
                $mine = [$index + 1, $entry];
            }
        }
        $text = $lines === [] ? 'No ranked games yet. Be the first: `/match queue`.' : implode("\n", $lines);
        $text .= "\n\n".($mine === null
            ? '-# You have no ranked games in '.$mode->label.' yet; everyone starts at '.Ladder::START.'.'
            : ($mine[0] > $top ? $line(...$mine)."\n" : '').'-# You are #'.$mine[0].' of '.count($standings).'.');

        return static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['multicolor'])
            ->addComponent(TextDisplay::new("### 🏆 {$mode->label} ladder\n-# Rating · wins–losses(–draws). Ranked games are the ones `/match queue` pairs."))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(Text::clip($text, 3500))));
    }

    /**
     * A match that ended before it started.
     *
     * @param MatchRecord $match
     *
     * @return static
     */
    public static function closed(MatchRecord $match): static
    {
        $text = $match->status === MatchRecord::CANCELLED
            ? "**{$match->challenger()['name']}** called off the challenge."
            : "**{$match->opponent()['name']}** declined the challenge.";

        return static::notice($text);
    }

    /**
     * The board everyone sees.
     *
     * @param MatchRecord $match
     * @param bool        $ping  Mention the players the game is waiting on.
     *
     * @return static
     */
    public static function board(MatchRecord $match, bool $ping = false): static
    {
        $game = $match->game;
        $message = static::panel();
        if ($game === null) {
            return $message->addComponent(Container::new()->addComponent(TextDisplay::new('This game has not started.')));
        }

        $names = array_map(fn ($player) => $player->name, $game->players);
        $heading = "### ⚔️ {$names[0]} vs {$names[1]}\n-# ".match ($game->stage) {
            Game::MULLIGAN => 'Opening hands',
            Game::OVER => "Game over after turn {$game->turn}",
            default => "Turn {$game->turn} · {$names[$game->active]}'s turn · {$game->step->label()}",
        }.' · '.self::modeLabel($match).($match->kind() === 'friendly' ? '' : ' ('.$match->kind().')');

        $container = Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS[$game->stage === Game::OVER ? 'multicolor' : 'colorless'])
            ->addComponent(TextDisplay::new($heading))
            ->addComponent(Separator::new());

        // Each player's side, the opponent of the active player first.
        foreach ([$game->opponent($game->active), $game->active] as $seat) {
            $container->addComponent(TextDisplay::new(Text::clip(self::side($game, $seat), 1800)));
        }

        if ($game->stack !== []) {
            $lines = [];
            foreach (array_reverse($game->stack) as $index => $item) {
                $name = Game::isAbility($item) ? $item['label'] : $game->objects[$item['object']]->name();
                $targets = array_map(fn (string $target) => $game->describeTarget($target), $item['targets']);
                $lines[] = ($index + 1).'. **'.$name.'**'.($item['x'] > 0 ? " (X = {$item['x']})" : '').($targets === [] ? '' : ' → '.implode(', ', $targets))." · {$names[$item['controller']]}";
            }
            $container->addComponent(Separator::new())->addComponent(TextDisplay::new("**Stack** (top first)\n".implode("\n", $lines)));
        }

        $container->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(Text::clip(implode("\n", array_map(fn ($line) => "-# {$line}", array_slice($game->log, -6))), 1200)));

        $waiting = self::waiting($match);
        $container->addComponent(Separator::new())->addComponent(TextDisplay::new($waiting['text']));
        if ($ping && $waiting['ids'] !== []) {
            $mentions = AllowedMentions::none();
            foreach ($waiting['ids'] as $id) {
                $mentions->addUser($id);
            }
            $message->setAllowedMentions($mentions);
        }
        $message->addComponent($container);

        if ($game->stage === Game::OVER) {
            return $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, self::id($match->id, 'log'))->setLabel('📜 Game record')));
        }

        $row = ActionRow::new()->addComponent(Button::new(Button::STYLE_PRIMARY, self::id($match->id, 'hand'))->setLabel('🃏 Your hand & actions'));
        if ($game->stage === Game::PLAYING) {
            $row->addComponent(Button::new(Button::STYLE_SECONDARY, self::id($match->id, 'bpass'))->setLabel('Pass priority')->setDisabled($game->priority === null));
        }
        $row->addComponent(Button::new(Button::STYLE_SECONDARY, self::id($match->id, 'refresh'))->setLabel('Refresh'));

        return $message->addComponent($row);
    }

    /**
     * A game's full record, turn by turn, as an attached text file.
     *
     * @param MatchRecord $match A match whose game has started.
     *
     * @return static
     */
    public static function log(MatchRecord $match): static
    {
        $game = $match->game;
        $names = array_map(fn ($player) => $player->name, $game->players);
        $state = $game->stage === Game::OVER
            ? GameRecord::result($game)." after {$game->turn} turn".($game->turn === 1 ? '' : 's').($game->winner === null ? ', a draw' : ", won by **{$names[$game->winner]}**")
            : ($game->stage === Game::MULLIGAN ? 'opening hands' : "in progress, turn {$game->turn}");

        return static::new()
            ->setAllowedMentions(AllowedMentions::none())
            ->setContent("📜 **{$names[0]} vs {$names[1]}** · ".self::modeLabel($match).($match->kind() === 'friendly' ? '' : ' ('.$match->kind().')')." · {$state}\n-# Match `{$match->id}`. The record has a score sheet with every move by turn, then the full log with each player's position at the end of every turn.")
            ->addFileFromContent("match-{$match->id}.txt", (string) $match->transcript());
    }

    /**
     * A finished game as a short line, for picking it from a list.
     *
     * @param MatchRecord $match
     * @param string      $playerId Whose point of view: won or lost.
     *
     * @return string
     */
    public static function historyLine(MatchRecord $match, string $playerId): string
    {
        $game = $match->game;
        $seat = $game->seatOf($playerId);
        $opponent = $seat === null ? "{$game->players[0]->name} vs {$game->players[1]->name}" : 'vs '.$game->players[$game->opponent($seat)]->name;
        $result = match (true) {
            $game->stage !== Game::OVER => 'in progress',
            $game->winner === null => 'draw',
            $game->winner === $seat => 'won',
            $seat === null => "{$game->players[$game->winner]->name} won",
            default => 'lost',
        };

        return "{$opponent} · {$result} · {$game->turn} turn".($game->turn === 1 ? '' : 's').' · '.self::modeLabel($match).($match->createdAt > 0 ? ' · '.gmdate('M j', $match->createdAt) : '');
    }

    /**
     * Who the game waits on, as a line for the board.
     *
     * @param MatchRecord $match
     *
     * @return array{text: string, ids: string[]}
     */
    public static function waiting(MatchRecord $match): array
    {
        $game = $match->game;
        if ($game->stage === Game::OVER) {
            $text = $game->winner === null ? '🤝 The game is a draw.' : "🏆 **{$game->players[$game->winner]->name}** wins!";
            foreach ($match->rewards as $reward) {
                $name = $game->players[$game->seatOf((string) $reward['id']) ?? 0]->name;
                if (($reward['points'] ?? 0) > 0) {
                    $text .= "\n-# {$name} earned ".PocketMessageBuilder::points((int) $reward['points']).'.';
                }
                foreach ($reward['quests'] ?? [] as $quest) {
                    $text .= "\n-# {$name} completed the quest **{$quest['label']}**: +".PocketMessageBuilder::points((int) $quest['points']).'.';
                }
            }

            return ['text' => $text, 'ids' => []];
        }

        $lines = [];
        $ids = [];
        foreach ($game->waitingOn() as $seat) {
            $player = $game->players[$seat];
            $what = match ($game->decision($seat)) {
                'mulligan' => 'keep or mulligan',
                'bottom' => 'put cards on the bottom',
                'trigger' => 'choose targets for '.$game->triggerAwaitingTargets()['label'],
                'scry', 'surveil' => $game->decision($seat),
                'look' => 'choose from the top of their library',
                'ward_payment' => 'choose whether to pay '.$game->choiceAwaiting()['label'],
                'trigger_order' => 'choose the order for their simultaneous triggered abilities',
                'ward_unsupported' => 'wait for review of an unsupported Ward interaction',
                'damage_order' => 'choose the next replacement or prevention for damage to '.$game->describeTarget($game->choiceAwaiting()['target']),
                'entry_life' => 'choose whether to pay life for '.$game->choiceAwaiting()['label'],
                'fabricate' => 'choose counters or Servos for '.$game->choiceAwaiting()['label'],
                'optional_recover' => 'choose whether to pay recover '.$game->choiceAwaiting()['cost'].' for '.$game->choiceAwaiting()['label'],
                'optional_madness' => 'choose whether to cast '.$game->objects[$game->choiceAwaiting()['object']]->name().' with Madness',
                'optional_red_goblin' => 'choose whether to pay {R} for a Goblin from '.$game->choiceAwaiting()['label'],
                'optional_source_untap' => 'choose whether to untap '.$game->choiceAwaiting()['label'],
                'optional_source_counter' => 'choose whether to put a '.($game->choiceAwaiting()['counterKind'] ?? '+1/+1').' counter on '.$game->choiceAwaiting()['label'],
                'optional_death_draw' => 'choose whether to draw a card',
                'optional_basic_land_search' => 'choose whether to search for a basic land',
                'optional_life_gain' => 'choose whether to gain 5 life',
                'optional_life_loss' => 'choose whether the targeted opponent loses 1 life',
                'sacrifice' => 'choose creatures to sacrifice',
                'devour' => 'choose creatures to devour, or choose none',
                'mode' => 'choose modes for '.$game->choiceAwaiting()['label'],
                'attack' => 'declare attackers',
                'block' => 'declare blockers',
                'discard' => $game->choiceAwaiting() === null ? 'discard down to seven' : 'discard',
                default => $game->stack === [] ? 'act or pass' : 'respond or pass',
            };
            $lines[] = "<@{$player->id}> to {$what}";
            $ids[] = $player->id;
        }

        return ['text' => '⏳ Waiting on '.implode(' and ', $lines).'.', 'ids' => $ids];
    }

    /**
     * One player's side of the board.
     *
     * @param Game $game
     * @param int  $seat
     *
     * @return string
     */
    private static function side(Game $game, int $seat): string
    {
        $player = $game->players[$seat];
        $line = sprintf(
            '**%s** · ❤️ %d · ✋ %d · 📚 %d · 🪦 %d',
            $player->name,
            $player->life,
            count($player->hand),
            count($player->library),
            count($player->graveyard),
        );
        if ($player->speed > 0) {
            $line .= " · 🏁 speed {$player->speed}";
        }
        if ($player->energy > 0) {
            $line .= " · ⚡ {$player->energy}";
        }
        if ($player->ring > 0) {
            $line .= " · 💍 {$player->ring}";
        }
        if ($player->poison > 0) {
            $line .= " · ☠️ {$player->poison}";
        }
        if ($player->manaPool->total() > 0) {
            $line .= " · floating {$player->manaPool}";
        }
        if ($player->lost) {
            $line .= ' · ❌ '.$player->lossReason;
        }
        foreach ($game->commandCards($seat) as $id) {
            $tax = $game->commanderTax($id);
            $line .= "\n👑 Command zone: {$game->objects[$id]->name()}".($tax > 0 ? " (tax {{$tax}})" : '');
        }
        foreach ($player->commanderDamage as $id => $damage) {
            $line .= "\n⚔️ {$damage}/".Game::COMMANDER_DAMAGE." commander damage from {$game->objects[$id]->name()}";
        }
        // `Play with the top card of your library revealed.`
        $top = end($player->library);
        if ($top !== false && array_filter($game->permanents($seat), fn (GameObject $o) => in_array('top revealed', $o->definition()->keywords, true)) !== []) {
            $line .= "\n🔝 Top of library (revealed): {$game->objects[$top]->name()}";
        }

        $lands = [];
        $others = [];
        foreach ($game->permanents($seat) as $object) {
            if ($object->definition()->isLand() && ! $game->isCreature($object)) {
                $name = $object->name();
                $lands[$name] ??= [0, 0];
                $lands[$name][0]++;
                $lands[$name][1] += $object->tapped ? 1 : 0;
            } elseif ($object->attachedTo !== null && $object->definition()->attachmentBonus() !== null) {
                continue; // Shown with what it is attached to.
            } else {
                $others[] = '• '.self::permanent($game, $object);
            }
        }

        $parts = [$line];
        if ($lands !== []) {
            $parts[] = 'Lands: '.implode(', ', array_map(
                fn (string $name, array $count) => $name.($count[0] > 1 ? " ×{$count[0]}" : '').($count[1] > 0 ? " ({$count[1]} tapped)" : ''),
                array_keys($lands),
                $lands,
            ));
        }
        array_push($parts, ...$others);

        return implode("\n", $parts);
    }

    /**
     * A permanent on the board, with its state.
     *
     * @param Game       $game
     * @param GameObject $object
     *
     * @return string
     */
    public static function permanent(Game $game, GameObject $object): string
    {
        $card = $object->definition();
        $text = ($game->isCommander($object) ? '👑 ' : '')."**{$object->name()}**";
        if ($game->isCreature($object)) {
            $text .= " {$game->power($object)}/{$game->toughness($object)}";
        }
        if ($card->isPlaneswalker()) {
            $text .= ' ◇'.$object->counter('loyalty');
        }

        $notes = [];
        if ($object->chosen !== null) {
            $notes[] = 'chose '.$object->chosen;
        }
        if ($object->enchantedPlayer !== null) {
            $notes[] = 'enchanting '.$game->players[$object->enchantedPlayer]->name;
        }
        $keywords = $game->keywords($object);
        if ($keywords !== []) {
            $notes[] = implode(', ', $keywords);
        }
        foreach ($object->counters as $kind => $count) {
            if ($kind !== 'loyalty') {
                $notes[] = "{$kind} ×{$count}";
            }
        }
        if ($object->tapped) {
            $notes[] = 'tapped';
        }
        if ($object->prepared) {
            $notes[] = 'prepared: '.$object->definition()->prepare?->name;
        }
        if ($object->sick && $game->isCreature($object) && ! $game->hasKeyword($object, 'haste')) {
            $notes[] = 'summoning sick';
        }
        if ($object->damage > 0) {
            $notes[] = "{$object->damage} damage";
        }
        if (isset($game->attackers[$object->id])) {
            $notes[] = '⚔️ attacking';
        }
        if (isset($game->blockers[$object->id])) {
            $notes[] = '🛡️ blocking '.implode(' and ', array_map(fn (int $id) => $game->objects[$id]->name(), [$game->blockers[$object->id], ...$game->extraBlocks[$object->id] ?? []]));
        }
        foreach ($game->attachments($object) as $attached) {
            $notes[] = ($attached->definition()->isAura() ? 'enchanted by ' : 'equipped with ').$attached->name();
        }

        return $text.($notes === [] ? '' : ' · '.implode(' · ', $notes));
    }

    /**
     * A player's own action panel.
     *
     * @param MatchRecord $match
     * @param string      $playerId
     * @param string|null $note     What just happened, or why an action failed.
     *
     * @return static
     */
    public static function actions(MatchRecord $match, string $playerId, ?string $note = null): static
    {
        $message = static::panel();
        $game = $match->game;
        $seat = $game?->seatOf($playerId);
        if ($game === null || $seat === null) {
            return $message->addComponent(Container::new()->addComponent(TextDisplay::new('You are not playing in this game.')));
        }

        $player = $game->players[$seat];
        $decision = $game->decision($seat);
        $choice = $match->choice($playerId);
        if ($decision === 'sacrifice' && ($choice['sacrifice']['id'] ?? null) !== $game->choiceAwaiting()['id']) {
            $choice['sacrifice'] = ['id' => $game->choiceAwaiting()['id'], 'page' => 0, 'selected' => []];
        }
        if ($decision === 'devour' && ($choice['devour']['id'] ?? null) !== $game->choiceAwaiting()['id']) {
            $choice['devour'] = ['id' => $game->choiceAwaiting()['id'], 'page' => 0, 'selected' => []];
        }
        $playable = $decision === 'priority' ? $game->playableCards($seat) : [];

        $container = Container::new()->setAccentColor(CardMessageBuilder::ACCENTS['colorless']);
        if ($note !== null) {
            $container->addComponent(TextDisplay::new($note))->addComponent(Separator::new());
        }
        $sources = count($game->manaSources($seat));
        $container->addComponent(TextDisplay::new(sprintf(
            "### 🃏 Your hand (%d)\n-# ❤️ %d · %s%s",
            count($player->hand),
            $player->life,
            Text::plural($sources, 'untapped mana source'),
            $player->manaPool->total() > 0 ? " · floating {$player->manaPool}" : '',
        )));
        $hand = [];
        foreach ($player->hand as $id) {
            $card = $game->objects[$id]->definition();
            $hand[] = (in_array($id, $playable, true) ? '✅ ' : '▫️ ').self::cardLabel($game->objects[$id]);
            foreach ($card->unsupported as $text) {
                $hand[] = '-# ⚠️ Not applied yet: '.Text::clip($text, 120);
            }
        }
        foreach ($game->commandCards($seat) as $id) {
            $tax = $game->commanderTax($id);
            $hand[] = (in_array($id, $playable, true) ? '✅ ' : '▫️ ').'👑 '.self::cardLabel($game->objects[$id]).' · command zone'.($tax > 0 ? " · tax {{$tax}}" : '');
        }
        foreach ($game->permanents($seat) as $object) {
            if ($object->faceDown) {
                $hand[] = '-# 🂠 Your face-down '.$object->printed()->name.' turns face up for '.$object->printed()->morph['cost'].'.';
            }
        }
        // `You may look at the top card of your library any time.`
        $top = end($player->library);
        if ($top !== false && array_filter($game->permanents($seat), fn (GameObject $o) => in_array('look at top any time', $o->definition()->keywords, true)) !== []) {
            $hand[] = '-# 🔝 Top of your library: '.self::cardLabel($game->objects[$top]);
        }
        foreach ($player->graveyard as $id) {
            if (in_array($id, $playable, true)) {
                $hand[] = '✅ 🪦 '.self::cardLabel($game->objects[$id]).' · graveyard · flashback '.$game->objects[$id]->printed()->flashback;
            }
        }
        $container->addComponent(TextDisplay::new($hand === [] ? '*Your hand is empty.*' : Text::clip(implode("\n", $hand), 2500)));
        $prompt = self::prompt($game, $seat, $decision, $choice);
        if ($match->isTutorial() && ($tip = Tutorial::tip($game, $seat, $decision, $choice)) !== null) {
            $prompt .= "\n-# 💡 {$tip}";
        }
        $container->addComponent(Separator::new())->addComponent(TextDisplay::new($prompt));
        $message->addComponent($container);

        $id = fn (string $action, string ...$args) => self::id($match->id, $action, ...$args);
        $cardOption = fn (int $objectId) => Option::new(Text::clip($game->objects[$objectId]->name(), 100), (string) $objectId)
            ->setDescription(Text::clip(self::cardDescription($game->objects[$objectId]), 100));

        switch ($decision) {
            case 'mulligan':
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SUCCESS, $id('keep'))->setLabel('Keep'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('mull'))->setLabel('Mulligan')->setDisabled($player->mulligans >= Game::OPENING_HAND)));
                break;

            case 'bottom':
                $message->addComponent(self::cardSelect($id('bottom'), "Put {$player->toBottom} on the bottom", $player->hand, $cardOption, $player->toBottom, $player->toBottom));
                break;

            case 'discard':
                $count = $game->discardCount();
                $message->addComponent(self::cardSelect($id('disc'), "Discard {$count}", $game->choiceAwaiting()['cards'] ?? $player->hand, $cardOption, $count, $count));
                if ($game->choiceAwaiting()['may'] ?? false) {
                    $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, $id('nodisc'))->setLabel("Don't discard")));
                }
                break;

            case 'attack':
                $candidates = $game->attackCandidates();
                $chosen = array_values(array_intersect(array_map('intval', $choice['attack'] ?? []), $candidates));
                $select = self::cardSelect($id('atk'), 'Choose attackers', $candidates, fn (int $object) => self::permanentOption($game, $object)->setDefault(in_array($object, $chosen, true)), 0, count($candidates));
                $message->addComponent($select);
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_DANGER, $id('atkgo'))->setLabel($chosen === [] ? 'Attack' : 'Attack with '.count($chosen))->setDisabled($chosen === []))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('noatk'))->setLabel('No attack')));
                break;

            case 'block':
                $blocks = (array) ($choice['blocks'] ?? []);
                foreach (array_slice(array_keys($game->attackers), 0, self::MAX_BLOCK_PICKERS) as $attackerId) {
                    $attacker = $game->objects[$attackerId];
                    $able = array_values(array_filter($game->blockCandidates(), fn (int $blocker) => $game->canBlock($game->objects[$blocker], $attacker)));
                    if ($able === []) {
                        continue;
                    }
                    $chosen = array_map('intval', (array) ($blocks[$attackerId] ?? []));
                    $message->addComponent(self::cardSelect(
                        $id('blk', (string) $attackerId),
                        Text::clip("Block {$attacker->name()} {$game->power($attacker)}/{$game->toughness($attacker)} with…", 100),
                        $able,
                        fn (int $object) => self::permanentOption($game, $object)->setDefault(in_array($object, $chosen, true)),
                        0,
                        count($able),
                    ));
                }
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_PRIMARY, $id('blkgo'))->setLabel('Confirm blocks'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('noblk'))->setLabel('No blocks')));
                break;

            case 'scry':
            case 'surveil':
                $cards = $game->choiceAwaiting()['cards'];
                $where = $decision === 'scry' ? 'on the bottom' : 'into your graveyard';
                $message->addComponent(self::cardSelect($id('away'), "Put {$where}…", $cards, $cardOption, 1, count($cards)));
                $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_PRIMARY, $id('keepall'))->setLabel(count($cards) === 1 ? 'Keep it on top' : 'Keep them all on top')));
                break;

            case 'look':
                $look = $game->choiceAwaiting();
                if ($look['eligible'] !== [] && $look['take'] > 0) {
                    $placeholder = match ($look['to'] ?? 'hand') {
                        'split' => 'Find up to two basic lands…',
                        'hand' => 'Put into your hand…',
                        default => 'Put onto the battlefield…',
                    };
                    $message->addComponent(self::cardSelect($id('take'), $placeholder, $look['eligible'], $cardOption, $look['may'] ? 1 : $look['take'], $look['take']));
                }
                if ($look['may'] || $look['take'] === 0) {
                    $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, $id('takenone'))->setLabel($look['take'] === 0 ? 'Continue' : 'Take none')));
                }
                break;

            case 'devour':
                $devour = $game->choiceAwaiting();
                $picks = $choice['devour'];
                $page = $picks['page'];
                $pages = (int) ceil(count($devour['cards']) / 25);
                $visible = array_slice($devour['cards'], $page * 25, 25);
                $message->addComponent(self::cardSelect(
                    $id('devpick', (string) $devour['id'], (string) $page),
                    'Choose creatures to devour (page '.($page + 1)."/{$pages})",
                    $visible,
                    fn (int $object) => $cardOption($object)->setDefault(in_array($object, $picks['selected'], true)),
                    0,
                    count($visible)
                ));
                $row = ActionRow::new();
                if ($page > 0) {
                    $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('devpage', (string) $devour['id'], (string) ($page - 1)))->setLabel('Previous page'));
                }
                if ($page + 1 < $pages) {
                    $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('devpage', (string) $devour['id'], (string) ($page + 1)))->setLabel('Next page'));
                }
                $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('devreset', (string) $devour['id']))->setLabel('Clear selections'));
                $row->addComponent(Button::new(Button::STYLE_DANGER, $id('devgo', (string) $devour['id']))->setLabel('Confirm Devour'));
                $message->addComponent($row);
                break;

            case 'sacrifice':
                $sacrifice = $game->choiceAwaiting();
                $picks = $choice['sacrifice'];
                $page = $picks['page'];
                $pages = (int) ceil(count($sacrifice['cards']) / 25);
                $visible = array_slice($sacrifice['cards'], $page * 25, 25);
                $message->addComponent(self::cardSelect(
                    $id('sacpick', (string) $sacrifice['id'], (string) $page),
                    "Choose {$sacrifice['count']} creatures total (page ".($page + 1)."/{$pages})",
                    $visible,
                    fn (int $object) => $cardOption($object)->setDefault(in_array($object, $picks['selected'], true)),
                    0,
                    $sacrifice['count']
                ));
                $row = ActionRow::new();
                if ($page > 0) {
                    $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('sacpage', (string) $sacrifice['id'], (string) ($page - 1)))->setLabel('Previous page'));
                }
                if ($page + 1 < $pages) {
                    $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('sacpage', (string) $sacrifice['id'], (string) ($page + 1)))->setLabel('Next page'));
                }
                $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('sacreset', (string) $sacrifice['id']))->setLabel('Clear selections'));
                $row->addComponent(Button::new(Button::STYLE_DANGER, $id('sacgo', (string) $sacrifice['id']))->setLabel('Confirm sacrifices')->setDisabled(count($picks['selected']) !== $sacrifice['count']));
                $message->addComponent($row);
                break;

            case 'optional_madness':
                $optional = $game->choiceAwaiting();
                if (isset($choice['cast'])) {
                    self::castControls($message, $match, $seat, $choice['cast']);
                } else {
                    $message->addComponent(ActionRow::new()
                        ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('madness', (string) $optional['id'], 'accept'))->setLabel('Cast with Madness')->setDisabled(! $game->canCastMadness($seat)))
                        ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('madness', (string) $optional['id'], 'decline'))->setLabel('Decline: put in graveyard')));
                }
                break;

            case 'optional_recover':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('recover', (string) $optional['id'], 'accept'))->setLabel('Pay recover '.$optional['cost'])->setDisabled(! $game->canPayRecover($seat)))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('recover', (string) $optional['id'], 'decline'))->setLabel('Decline: exile original card')));
                break;

            case 'optional_red_goblin':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maygoblin', (string) $optional['id'], 'accept'))->setLabel('Pay {R}: create a Goblin')->setDisabled(! $game->canPayOptionalRedGoblin($seat)))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maygoblin', (string) $optional['id'], 'decline'))->setLabel('Decline')));
                break;

            case 'optional_source_untap':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('mayuntap', (string) $optional['id'], 'accept'))->setLabel('Untap'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('mayuntap', (string) $optional['id'], 'decline'))->setLabel('Decline')));
                break;

            case 'optional_source_counter':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maycounter', (string) $optional['id'], 'accept'))->setLabel('Put a '.($optional['counterKind'] ?? '+1/+1').' counter'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maycounter', (string) $optional['id'], 'decline'))->setLabel('Decline')));
                break;

            case 'optional_basic_land_search':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maysearch', (string) $optional['id'], 'accept'))->setLabel('Search library'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maysearch', (string) $optional['id'], 'decline'))->setLabel('Decline search')));
                break;

            case 'optional_death_draw':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maydraw', (string) $optional['id'], 'accept'))->setLabel('Draw a card'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maydraw', (string) $optional['id'], 'decline'))->setLabel('Decline')));
                break;

            case 'optional_life_gain':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maygain', (string) $optional['id'], 'accept'))->setLabel('Gain 5 life'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maygain', (string) $optional['id'], 'decline'))->setLabel('Decline')));
                break;

            case 'optional_life_loss':
                $optional = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maylife', (string) $optional['id'], 'accept'))->setLabel('Opponent loses 1 life'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('maylife', (string) $optional['id'], 'decline'))->setLabel('Decline')));
                break;

            case 'fabricate':
                $fabricate = $game->choiceAwaiting();
                $row = ActionRow::new();
                foreach ($fabricate['options'] as $option) {
                    $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('fabricate', (string) $fabricate['id'], $option))
                        ->setLabel($option === 'counters' ? "Put {$fabricate['count']} +1/+1 counters" : "Create {$fabricate['count']} Servos"));
                }
                $message->addComponent($row);
                break;

            case 'ward_payment':
                $ward = $game->choiceAwaiting();
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('wardpay', (string) $ward['id'], 'accept'))
                        ->setLabel(Text::clip('Pay '.$ward['label'], 80))->setDisabled(! $game->canPayWard($seat)))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('wardpay', (string) $ward['id'], 'decline'))->setLabel('Decline payment')));
                break;

            case 'trigger_order':
                $order = $game->choiceAwaiting();
                $savedPage = $choice['triggerOrder'] ?? [];
                $lastPage = intdiv(count($order['options']) - 1, 25);
                $page = ($savedPage['id'] ?? null) === $order['id'] ? max(0, min($lastPage, (int) ($savedPage['page'] ?? 0))) : 0;
                $select = StringSelect::new($id('trigorder', (string) $order['id']))->setPlaceholder('Next ability to put on the stack');
                foreach ($game->triggerOrderOptions($page) as $value => $label) {
                    $select->addOption(Option::new(Text::clip($label, 100), (string) $value));
                }
                $message->addComponent(ActionRow::new()->addComponent($select));
                if ($lastPage > 0) {
                    $message->addComponent(ActionRow::new()
                        ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('trigpage', (string) $order['id'], (string) ($page - 1)))->setLabel('Previous triggers')->setDisabled($page === 0))
                        ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('trigpage', (string) $order['id'], (string) ($page + 1)))->setLabel('Next triggers')->setDisabled($page === $lastPage)));
                }
                break;

            case 'ward_unsupported':
                // Held engine state offers no retry/cancel/refund that could alter an already paid action.
                break;

            case 'damage_order':
                $damage = $game->choiceAwaiting();
                $select = StringSelect::new($id('damageorder', (string) $damage['id']))->setPlaceholder('Apply a replacement or prevention');
                foreach ($game->damageOrderOptions() as $value => $label) {
                    $select->addOption(Option::new($label, $value));
                }
                $message->addComponent(ActionRow::new()->addComponent($select));
                break;

            case 'entry_life':
                $entry = $game->choiceAwaiting();
                $row = ActionRow::new();
                foreach ($entry['options'] as $option) {
                    $row->addComponent(Button::new(Button::STYLE_SECONDARY, $id('entrylife', (string) $entry['id'], $option))
                        ->setLabel($option === 'pay' ? "Pay {$entry['life']} life" : "Don't pay"));
                }
                $message->addComponent($row);
                break;

            case 'mode':
                $select = StringSelect::new($id('mode'))->setPlaceholder('Choose a mode');
                foreach (array_slice($game->choiceAwaiting()['texts'], 0, 25, true) as $option => $text) {
                    $select->addOption(Option::new(Text::clip($text, 100), (string) $option));
                }
                $message->addComponent(ActionRow::new()->addComponent($select));
                break;

            case 'trigger':
                $trigger = $game->triggerAwaitingTargets();
                $slot = count((array) ($choice['trigger'] ?? []));
                $kind = $trigger['kinds'][$slot] ?? $trigger['kinds'][0];
                $message->addComponent(self::targetSelect($id('trig'), $game, $seat, $game->targetOptions($seat, $kind), 'Choose a target for '.$trigger['label'], $slot, count($trigger['kinds'])));
                if ($slot > 0) {
                    $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, $id('cancel'))->setLabel('Start over')));
                }
                break;

            case 'priority':
                $cast = $choice['cast'] ?? null;
                if ($cast !== null && in_array((int) $cast['id'], [...$player->hand, ...$game->commandCards($seat), ...$player->graveyard, ...$game->exile], true)) {
                    self::castControls($message, $match, $seat, $cast);
                    break;
                }
                $activate = $choice['activate'] ?? null;
                if ($activate !== null && $game->canActivate($seat, (int) $activate['id'], (int) $activate['index'])) {
                    $object = $game->objects[(int) $activate['id']];
                    $kinds = array_values(array_filter(array_map(fn (array $effect) => $effect['target'] ?? null, $object->definition()->activated[(int) $activate['index']]['effects'])));
                    $slot = count((array) ($activate['targets'] ?? []));
                    if (isset($kinds[$slot])) {
                        $message->addComponent(self::targetSelect($id('atgt'), $game, $seat, $game->targetOptions($seat, $kinds[$slot]), "Choose a target for {$object->name()}'s ability", $slot, count($kinds)));
                    }
                    $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, $id('cancel'))->setLabel('Cancel')));
                    break;
                }
                $plays = array_slice($game->plays($seat), 0, 25);
                if ($plays !== []) {
                    $select = StringSelect::new($id('play'))->setPlaceholder('Play a land or cast a spell');
                    foreach ($plays as $play) {
                        $select->addOption(self::playOption($game, $play['id'], $play['how']));
                    }
                    $message->addComponent(ActionRow::new()->addComponent($select));
                }
                $abilities = array_slice($game->activatableAbilities($seat), 0, 25);
                if ($abilities !== []) {
                    $select = StringSelect::new($id('ability'))->setPlaceholder('Use an ability');
                    foreach ($abilities as [$objectId, $index]) {
                        $object = $game->objects[$objectId];
                        $select->addOption(Option::new(Text::clip($object->name(), 100), "{$objectId}.{$index}")
                            ->setDescription(Text::clip(str_replace('CARDNAME', $object->name(), $object->definition()->activated[$index]['text']), 100)));
                    }
                    $message->addComponent(ActionRow::new()->addComponent($select));
                }
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_PRIMARY, $id('pass'))->setLabel($game->stack === [] ? 'Pass priority' : 'Let it resolve'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('auto'))->setLabel(($game->autoPass[$seat] ?? true) ? 'Auto-pass: on' : 'Auto-pass: off'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('panel'))->setLabel('Refresh')));
                break;

            default:
                $message->addComponent(ActionRow::new()
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('panel'))->setLabel('Refresh'))
                    ->addComponent(Button::new(Button::STYLE_SECONDARY, $id('auto'))->setLabel(($game->autoPass[$seat] ?? true) ? 'Auto-pass: on' : 'Auto-pass: off')));
        }

        return $message;
    }

    /**
     * The X and target pickers for a spell being cast.
     *
     * @param MatchMessageBuilder $message
     * @param MatchRecord         $match
     * @param int                 $seat
     * @param array               $cast    `id`, `x` (null until chosen) and `targets` chosen so far.
     *
     * @return void
     */
    private static function castControls(self $message, MatchRecord $match, int $seat, array $cast): void
    {
        $game = $match->game;
        $object = $game->objects[(int) $cast['id']];
        $card = $object->printed();
        $how = (string) ($cast['how'] ?? '');
        $options = Game::castOptions($how);

        if (PanelActions::xCount($card, $options) > 0 && ! isset($cast['x'])) {
            $max = min(24, $game->maxX($seat, $object->id, 20, $how));
            $select = StringSelect::new(self::id($match->id, 'x'))->setPlaceholder("Choose X for {$card->name}");
            for ($x = 0; $x <= $max; $x++) {
                $select->addOption(Option::new("X = {$x}", (string) $x));
            }
            $message->addComponent(ActionRow::new()->addComponent($select));
        } else {
            $kinds = Game::castTargetKinds($card, $options, $game);
            $slot = count((array) ($cast['targets'] ?? []));
            if (isset($kinds[$slot])) {
                $message->addComponent(self::targetSelect(self::id($match->id, 'tgt'), $game, $seat, $game->targetOptions($seat, $kinds[$slot]), 'Choose a target for '.$card->name, $slot, count($kinds)));
            }
        }

        $message->addComponent(ActionRow::new()->addComponent(Button::new(Button::STYLE_SECONDARY, self::id($match->id, 'cancel'))->setLabel('Cancel')));
    }

    /**
     * A menu of targets for one target slot of a spell or ability.
     *
     * @param string   $customId
     * @param Game     $game
     * @param int      $seat
     * @param string[] $options
     * @param string   $placeholder
     * @param int      $slot
     * @param int      $count       How many targets it needs in all.
     *
     * @return ActionRow
     */
    private static function targetSelect(string $customId, Game $game, int $seat, array $options, string $placeholder, int $slot, int $count): ActionRow
    {
        $select = StringSelect::new($customId)->setPlaceholder(Text::clip($placeholder.($count > 1 ? ' ('.($slot + 1)." of {$count})" : ''), 150));
        foreach (array_slice($options, 0, 25) as $target) {
            $select->addOption(Option::new(Text::clip(self::targetLabel($game, $target, $seat), 100), $target));
        }

        return ActionRow::new()->addComponent($select);
    }

    /**
     * What the player is being asked to do.
     *
     * @param Game        $game
     * @param int         $seat
     * @param string|null $decision
     * @param array       $choice
     *
     * @return string
     */
    private static function prompt(Game $game, int $seat, ?string $decision, array $choice): string
    {
        $player = $game->players[$seat];

        return match ($decision) {
            'sacrifice' => self::sacrificePrompt($game, $choice),
            'ward_payment' => '**'.$game->choiceAwaiting()['label'].'**: you may pay this cost. Declining counters only the referenced spell or ability if it is still counterable. This ability does not target.',
            'trigger_order' => 'Choose the next ability to put on the stack from your simultaneous group. Earlier selections resolve later; other controllers keep their active-player order.',
            'ward_unsupported' => 'This opt-in game is held for review: '.$game->choiceAwaiting()['reason'].' No automatic payment, cancellation or refund is available.',
            'damage_order' => 'Choose what applies next to the damage to **'.$game->describeTarget($game->choiceAwaiting()['target']).'**. Damage waits until all replacement and prevention choices are complete.',
            'devour' => 'Choose any number of your creatures to sacrifice as this creature enters. Select none to keep them all.'
                .' Selected: '.(($choice['devour']['selected'] ?? []) === [] ? 'none' : implode(', ', array_map(fn (int $id) => $game->objects[$id]->name(), $choice['devour']['selected']))),
            'entry_life' => 'As **'.$game->choiceAwaiting()['label'].'** enters, you may pay '.$game->choiceAwaiting()['life'].' life. If you do not, it enters tapped.'.($game->choiceAwaiting()['tapped'] ? ' It enters tapped regardless of payment because of another effect.' : ''),
            'fabricate' => '**'.$game->choiceAwaiting()['label'].'**: put '.$game->choiceAwaiting()['count'].' +1/+1 counters on it, or create that many 1/1 colorless Servo artifact creature tokens.',
            'optional_recover' => '**'.$game->choiceAwaiting()['label'].'**: you may pay '.$game->choiceAwaiting()['cost'].' to return the original graveyard card to your hand. Otherwise, exile it. This does not target.',
            'optional_madness' => '**'.$game->objects[$game->choiceAwaiting()['object']]->name().'**: cast this card for its Madness cost, or put it into its owner’s graveyard.',
            'optional_red_goblin' => '**'.$game->choiceAwaiting()['label'].'**: you may pay {R} to create a 1/1 red Goblin creature token. It gains haste until end of turn. This does not target.',
            'optional_source_untap' => '**'.$game->choiceAwaiting()['label'].'**: you may untap this artifact. Only the original battlefield incarnation can be untapped; this does not target.',
            'optional_source_counter' => '**'.$game->choiceAwaiting()['label'].'**: you may put a '.($game->choiceAwaiting()['counterKind'] ?? '+1/+1').' counter on this permanent. Only the original battlefield incarnation can receive it; this does not target.',
            'optional_death_draw' => '**'.$game->choiceAwaiting()['label'].'**: you may draw a card. This does not target a player; declining does not draw.',
            'optional_basic_land_search' => '**'.$game->choiceAwaiting()['label'].'**: search for a basic land to enter tapped, then shuffle, or decline without searching or shuffling. If you search, you may fail to find.',
            'optional_life_gain' => '**'.$game->choiceAwaiting()['label'].'**: you may gain 5 life. This does not target a player.',
            'optional_life_loss' => '**'.$game->choiceAwaiting()['label'].'**: you may have **'.$game->players[(int) substr($game->choiceAwaiting()['target'], 2)]->name.'** lose 1 life.',
            'mulligan' => $player->mulligans === 0
                ? 'Keep this hand, or shuffle it away and draw a new seven?'
                : "Mulligan {$player->mulligans}: if you keep, you put {$player->mulligans} card".($player->mulligans === 1 ? '' : 's').' on the bottom.',
            'bottom' => "Choose {$player->toBottom} card".($player->toBottom === 1 ? '' : 's').' to put on the bottom of your library.',
            'attack' => 'Choose your attackers, then **Attack**. They attack '.$game->players[$game->defender()]->name.'.',
            'block' => 'For each attacker, choose the creatures that block it, then **Confirm blocks**. Each creature blocks one attacker.',
            'discard' => match (true) {
                $game->choiceAwaiting() === null => 'You have more than seven cards. Choose what to discard.',
                ($game->choiceAwaiting()['from'] ?? $seat) !== $seat => 'Choose a card for '.$game->players[$game->choiceAwaiting()['from']]->name.' to discard.',
                $game->choiceAwaiting()['may'] ?? false => 'You may discard a card'.(($game->choiceAwaiting()['draw'] ?? 0) > 0 ? ' to draw '.$game->choiceAwaiting()['draw'] : '').'.',
                default => 'Choose '.$game->discardCount().' card'.($game->discardCount() === 1 ? '' : 's').' to discard.',
            },
            'trigger' => 'Choose targets for **'.$game->triggerAwaitingTargets()['label'].'**, which just triggered: '.$game->triggerAwaitingTargets()['text'],
            'scry', 'surveil' => ucfirst($decision).' '.count($game->choiceAwaiting()['cards']).": from the top of your library, these are\n"
                .implode("\n", array_map(fn (int $id) => '- '.self::cardLabel($game->objects[$id]), $game->choiceAwaiting()['cards']))
                ."\nChoose any to put ".($decision === 'scry' ? 'on the bottom' : 'into your graveyard').', or keep them all on top.',
            'look' => ($game->choiceAwaiting()['to'] ?? null) === 'split'
                ? 'Search your library: find up to two basic lands and reveal them. Choose which enters tapped; the other goes into your hand. Then shuffle once.'
                : ($game->choiceAwaiting()['rest'] === 'split'
                    ? 'Choose one of the two revealed lands to put onto the battlefield tapped. The other goes into your hand. Then shuffle your library.'
                    : ($game->choiceAwaiting()['rest'] === 'library'
                ? 'Search your library: choose '.($game->choiceAwaiting()['take'] > 1 ? 'up to '.$game->choiceAwaiting()['take'].' cards' : 'a card').' to put '.(($game->choiceAwaiting()['to'] ?? 'hand') === 'hand' ? 'into your hand' : 'onto the battlefield'.(($game->choiceAwaiting()['to'] ?? null) === 'tapped' ? ' tapped' : '')).'. Then your library is shuffled.'
                : 'From the top of your library, these are'."\n"
                .implode("\n", array_map(fn (int $id) => '- '.self::cardLabel($game->objects[$id]), $game->choiceAwaiting()['cards']))
                ."\n".match (true) {
                    $game->choiceAwaiting()['take'] === 0 => 'None of them can go to your hand.',
                    $game->choiceAwaiting()['may'] => 'You may put '.($game->choiceAwaiting()['take'] === 1 ? 'one' : 'up to '.$game->choiceAwaiting()['take']).' of the highlighted choices into your hand.',
                    default => 'Choose '.$game->choiceAwaiting()['take'].' to put into your hand.',
                }.' '.match ($game->choiceAwaiting()['rest']) {
                    'graveyard' => 'The rest go into your graveyard.',
                    'top' => 'They stay on top.',
                    default => 'The rest go on the bottom in a random order.',
                })),
            'mode' => 'Choose the mode for **'.$game->choiceAwaiting()['label'].'**, which just triggered.',
            'priority' => match (true) {
                isset($choice['cast']) => 'Finish casting your spell, or cancel.',
                isset($choice['activate']) => 'Choose targets for the ability, or cancel.',
                $game->stack === [] => "You have priority ({$game->step->label()}).",
                default => 'Something is on the stack: respond, or let it resolve.',
            },
            default => $game->stage === Game::OVER ? 'The game is over.' : 'Nothing to do right now; the game is waiting on your opponent.',
        };
    }

    /** Describes the pending choice and earlier public battlefield selections. */
    private static function sacrificePrompt(Game $game, array $choice): string
    {
        $pending = $game->choiceAwaiting();
        $names = fn (array $ids) => implode(', ', array_map(fn (int $id) => $game->objects[$id]->name(), $ids));
        $lines = ['Choose exactly '.$pending['count'].' creatures to sacrifice. All selections wait until every player has chosen.',
            'Selected: '.(($choice['sacrifice']['selected'] ?? []) === [] ? 'none' : $names($choice['sacrifice']['selected']))];
        foreach ($pending['groups'] as $group) {
            if ($group['selected'] !== null && $group['selected'] !== []) {
                $lines[] = 'Already chosen by '.$game->players[$group['seat']]->name.': '.$names($group['selected']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param string                   $customId
     * @param string                   $placeholder
     * @param int[]                    $objects
     * @param callable(int): Option    $option
     * @param int                      $min
     * @param int                      $max
     *
     * @return ActionRow
     */
    private static function cardSelect(string $customId, string $placeholder, array $objects, callable $option, int $min, int $max): ActionRow
    {
        $objects = array_slice(array_values($objects), 0, 25);
        $select = StringSelect::new($customId)
            ->setPlaceholder(Text::clip($placeholder, 150))
            ->setMinValues(min($min, count($objects)))
            ->setMaxValues(max(1, min($max, count($objects))));
        foreach ($objects as $object) {
            $select->addOption($option($object));
        }

        return ActionRow::new()->addComponent($select);
    }

    /**
     * One way to play a card, for the play menu: see {@see Game::plays()}.
     *
     * @param Game   $game
     * @param int    $id
     * @param string $how
     *
     * @return Option
     */
    private static function playOption(Game $game, int $id, string $how): Option
    {
        $object = $game->objects[$id];
        $card = $object->printed();
        $options = in_array($how, Game::SPECIAL_PLAYS, true) ? null : Game::castOptions($how);
        $way = $options === null ? match ($how) {
            'cycle' => "cycle {$card->cycling}",
            'unearth' => "unearth {$card->unearth}",
            'regrow' => (isset($card->altCosts['regrowTo']) ? 'return to the battlefield' : 'return to hand')." for {$card->altCosts['regrow']}",
            'foretell' => 'foretell for {2}',
            'bloodrush' => "bloodrush {$card->altCosts['bloodrush']}",
            default => "{$how} {$card->altCosts[$how]}",
        } : implode(', ', array_filter([
            $options['exiled'] === 'plot' ? 'plotted, free' : '',
            $options['exiled'] === 'suspended' ? 'suspended, free' : '',
            $options['exiled'] === 'cascade' ? 'cascade, free' : '',
            in_array($options['exiled'], ['warp', 'impulse'], true) ? 'from exile' : '',
            $options['exiled'] === 'madness' ? "madness {$card->altCosts['madness']}" : '',
            $options['exiled'] === 'foretell' ? "foretold, {$card->altCosts['foretell']}" : '',
            $options['exiled'] === 'airbend' ? 'airbent, {2}' : '',
            $options['entwined'] ? (isset($card->altCosts['teamwork']) ? "teamwork {$card->altCosts['teamwork']}, both modes" : "entwined +{$card->entwine}") : '',
            $options['alt'] === '' ? '' : "{$options['alt']} {$card->altCosts[$options['alt']]}",
            $options['faceDown'] ? 'face down for {3}' : '',
            $options['flashback'] ? "flashback {$card->flashback}" : '',
            $options['rebound'] ? 'rebound, free' : '',
            $options['bestowed'] ? "bestow {$card->bestow['cost']}" : '',
            $options['grave'] === 'escape' ? "escape {$card->altCosts['escape']}" : '',
            $options['grave'] === 'disturb' ? "disturb {$card->altCosts['disturb']} as {$card->back?->name}" : '',
            in_array($options['grave'], ['jumpstart', 'retrace', 'harmonize', 'mayhem'], true) ? ($options['grave'] === 'jumpstart' ? 'jump-start' : $options['grave']).' from the graveyard' : '',
            $options['bargained'] ? 'bargained' : '',
            $options['gifted'] ? 'gift promised to an opponent' : '',
            $options['casualty'] ? 'casualty, copied' : '',
            $options['conspire'] ? 'conspire, copied' : '',
            $options['buyback'] ? "buyback +{$card->altCosts['buyback']}" : '',
            $options['kicked'] && ! $options['bargained'] && ! $options['gifted'] ? (in_array('offspring', $card->keywords, true) ? "offspring +{$card->kicker}" : (in_array('replicate', $card->keywords, true) ? 'replicated '.max(1, $options['kicks']).'× +'.str_repeat((string) $card->kicker, max(1, $options['kicks'])) : ($options['kicks'] > 1 ? "kicked {$options['kicks']}× +".str_repeat((string) $card->kicker, $options['kicks']) : "kicked +{$card->kicker}"))) : '',
            $options['modes'] === [] ? '' : 'mode '.implode(' + ', array_map(fn (int $mode) => $mode + 1, $options['modes'])),
            $options['splice'] === [] ? '' : 'splicing '.implode(' and ', array_map(fn (int $splicer) => $game->objects[$splicer]->name().' +'.($game->objects[$splicer]->printed()->altCosts['splice'] ?? ''), $options['splice'])),
        ]));
        $description = $options !== null && $options['modes'] !== []
            ? implode(' + ', array_map(fn (int $mode) => str_replace('CARDNAME', $card->name, $card->modes[$mode]['text']), $options['modes']))
            : self::cardDescription($object);

        return Option::new(Text::clip($card->name.($way === '' ? '' : " — {$way}"), 100), "{$id}:{$how}")->setDescription(Text::clip($description, 100));
    }

    private static function permanentOption(Game $game, int $id): Option
    {
        $object = $game->objects[$id];
        $card = $object->definition();
        $stats = $game->isCreature($object) ? "{$game->power($object)}/{$game->toughness($object)}" : $card->typeLine;
        $keywords = $game->keywords($object);

        return Option::new(Text::clip($object->name(), 100), (string) $id)
            ->setDescription(Text::clip($stats.($keywords === [] ? '' : ' · '.implode(', ', $keywords)), 100));
    }

    /**
     * A card in hand: name, cost and type line.
     *
     * @param GameObject $object
     *
     * @return string
     */
    public static function cardLabel(GameObject $object): string
    {
        return "**{$object->name()}** ".self::cardDescription($object);
    }

    private static function cardDescription(GameObject $object): string
    {
        $card = $object->definition();
        $stats = $card->power !== null ? " {$card->power}/{$card->toughness}" : '';

        return trim(((string) $card->cost).' · '.$card->typeLine.$stats, ' ·');
    }

    private static function targetLabel(Game $game, string $target, int $seat): string
    {
        $parts = explode(':', $target);

        return match ($parts[0]) {
            'p' => (int) $parts[1] === $seat ? "You ({$game->players[$seat]->name})" : $game->players[(int) $parts[1]]->name,
            'o' => str_replace('**', '', self::permanent($game, $game->objects[(int) $parts[1]])).($game->objects[(int) $parts[1]]->controller === $seat ? ' (yours)' : ''),
            '-' => 'No target',
            default => 'Spell: '.$game->describeTarget($target),
        };
    }
}
