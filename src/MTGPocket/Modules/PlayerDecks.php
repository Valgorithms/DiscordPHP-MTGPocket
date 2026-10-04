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

namespace MTGPocket\Modules;

use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Models\Deck;
use MTGPocket\Modes\GameMode;
use MTG\Helpers\Text;
use MTGPocket\Panels\Panels;
use MTGPocket\Pocket;
use MTGPocket\Rentals\RentalDeck;
use React\Promise\PromiseInterface;

/**
 * The player's own decks and side decks, built from the cards they own:
 * `/decks list|show|create|add|remove|rename|format|commander|delete|use`, and the
 * official decks anyone can borrow: `/decks rentals|rent`.
 *
 * Named `/decks` so it does not clash with DiscordPHP-MTG's `/deck`, which
 * looks up preconstructed decks. Edits answer only the player; `list` and
 * `show` take `hidden` like every other command.
 *
 * The deck view's **Export** picker is answered by {@see Exports}.
 *
 * @since 0.2.0
 */
final class PlayerDecks implements Module
{
    use InteractionTrait;
    use PocketTrait;

    private Panels $panels;

    public function __construct(protected Pocket $pocket)
    {
        $this->panels = new Panels($pocket);
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'player-decks';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        $deck = fn (bool $required = true) => self::option($mtg, Option::STRING, 'deck', 'One of your decks.', $required, true);
        $card = fn (string $description) => self::option($mtg, Option::STRING, 'card', $description, true, true);
        $count = fn (string $description) => self::option($mtg, Option::INTEGER, 'count', $description)->setMinValue(1)->setMaxValue(250);
        $side = fn () => self::option($mtg, Option::BOOLEAN, 'side', 'The side deck instead of the main deck.');
        $format = fn (bool $required) => self::withChoices($mtg, self::option($mtg, Option::STRING, 'format', 'What the deck is for (default Standard).', $required), DeckBuilder::FORMATS);

        return [self::command('decks', 'Build your own decks and side decks from the cards you own.')
            ->addOption(self::subcommand($mtg, 'list', 'Your decks.', self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'show', 'One of your decks and its cards.', $deck(false), self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'create', 'Start a new, empty deck.', self::option($mtg, Option::STRING, 'name', 'What to call it.', true)->setMaxLength(DeckBuilder::MAX_NAME), $format(false)))
            ->addOption(self::subcommand($mtg, 'add', 'Put cards you own, or basic lands, in a deck.', $deck(), $card('A card you own, or a basic land.'), $count('How many copies (default 1).'), $side()))
            ->addOption(self::subcommand($mtg, 'remove', 'Take cards out of a deck.', $deck(), $card('A card in the deck.'), $count('How many copies (default all).'), $side()))
            ->addOption(self::subcommand($mtg, 'rename', 'Rename a deck.', $deck(), self::option($mtg, Option::STRING, 'name', 'Its new name.', true)->setMaxLength(DeckBuilder::MAX_NAME)))
            ->addOption(self::subcommand($mtg, 'format', 'Change what a deck is for.', $deck(), $format(true)))
            ->addOption(self::subcommand($mtg, 'delete', 'Delete a deck.', $deck()))
            ->addOption(self::subcommand($mtg, 'commander', 'Pick the legendary creature that leads a Commander deck.', $deck(), self::option($mtg, Option::STRING, 'card', 'A legendary creature you own; leave empty to take the commander out.', false, true)))
            ->addOption(self::subcommand($mtg, 'use', 'Make a deck the one you play with.', $deck()))
            ->addOption(self::subcommand($mtg, 'rentals', 'Official decks you can play without owning the cards.', self::hidden($mtg)))
            ->addOption(self::subcommand($mtg, 'rent', 'Play with an official deck, a few games a day.', self::option($mtg, Option::STRING, 'rental', 'A rental deck.', true, true)))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $suggest = fn (Interaction $interaction, $option) => $this->suggest($interaction, $option);
        $builder = $this->pocket->deckBuilder;

        $rentals = $this->pocket->rentals;

        $mtg->listenCommand(['decks', 'list'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => $this->panels->decks($id), false));
        $mtg->listenCommand(['decks', 'show'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($builder, $rentals) {
            $deck = ($args['deck'] ?? '') !== '' ? $args['deck'] : ($rentals->activeRental($id) ?? $builder->activeDeckId($id));
            if ($deck === null) {
                return $this->panels->decks($id, 'You have no active deck. Open one below, start one with **➕ New deck**, or borrow a rental deck.');
            }
            if (RentalDeck::isRental($deck)) {
                return $this->panels->rentalView($id, $deck);
            }

            return $this->view($builder->find($id, $deck));
        }, false), $suggest);
        $mtg->listenCommand(['decks', 'rentals'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id) => $this->panels->rentals($id), false));
        $mtg->listenCommand(['decks', 'rent'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($rentals) {
            $rental = $rentals->rent($id, $name, (string) $args['rental']);
            $left = $rentals->gamesLeft($id);

            return $this->panels->rentalView($id, RentalDeck::PREFIX.$rental->id, "You now play with **{$rental->name}** until you pick one of your own decks with `/decks use`. You have ".Text::plural($left, 'rental game').' left today.');
        }), $suggest);
        $mtg->listenCommand(['decks', 'create'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => $this->view(
            $deck = $builder->create($id, $name, (string) $args['name'], (string) ($args['format'] ?? 'standard')),
            "Started **{$deck->name}**. Add cards with **➕ Add cards** or `/decks add`."
        )));
        $mtg->listenCommand(['decks', 'add'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($builder) {
            $count = (int) ($args['count'] ?? 1);
            $side = (bool) ($args['side'] ?? false);
            [$deck, $card] = $builder->add($id, (string) $args['deck'], (string) $args['card'], $count, $side);

            return $this->view($deck, "Added {$count} **{$card['name']}** to the ".($side ? 'side' : 'main').' deck.');
        }), $suggest);
        $mtg->listenCommand(['decks', 'remove'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($builder) {
            $side = (bool) ($args['side'] ?? false);
            $before = $builder->find($id, (string) $args['deck']);
            [$deck, $card] = $builder->remove($id, $before->id, (string) $args['card'], isset($args['count']) ? (int) $args['count'] : null, $side);
            $removed = ($side ? $before->side : $before->main)->total() - ($side ? $deck->side : $deck->main)->total();

            return $this->view($deck, "Took {$removed} **{$card['name']}** out of the ".($side ? 'side' : 'main').' deck.');
        }), $suggest);
        $mtg->listenCommand(['decks', 'rename'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => $this->view(
            $builder->rename($id, (string) $args['deck'], (string) $args['name']),
            'Renamed.'
        )), $suggest);
        $mtg->listenCommand(['decks', 'format'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => $this->view(
            $deck = $builder->setFormat($id, (string) $args['deck'], (string) $args['format']),
            'It is now a '.DeckBuilder::FORMATS[$deck->format].' deck.'
        )), $suggest);
        $mtg->listenCommand(['decks', 'commander'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, function (string $id, string $name, array $args) use ($builder) {
            [$deck, $card] = $builder->setCommander($id, (string) $args['deck'], isset($args['card']) ? (string) $args['card'] : null);

            return $this->view($deck, $card === null ? 'This deck has no commander now.' : "**{$card['name']}** now leads this deck.");
        }), $suggest);
        $mtg->listenCommand(['decks', 'delete'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => $this->panels->decks(
            $id,
            'Deleted **'.$builder->delete($id, (string) $args['deck'])->name.'**.'
        )), $suggest);
        $mtg->listenCommand(['decks', 'use'], fn (Interaction $i, $options) => $this->run($mtg, $i, $options, fn (string $id, string $name, array $args) => $this->view(
            $builder->activate($id, $name, (string) $args['deck']),
            'You now play with this deck.'
        )), $suggest);
    }

    /**
     * Runs a sub-command for the caller and answers with what it returns.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param iterable    $options
     * @param callable(string, string, array): MessageBuilder $work Gets the caller's id, name and the option values.
     * @param bool        $private     Always answer only the caller; otherwise follow `hidden`.
     *
     * @return PromiseInterface
     */
    private function run(MTG $mtg, Interaction $interaction, iterable $options, callable $work, bool $private = true): PromiseInterface
    {
        $args = self::values($options);
        [$id, $name] = self::caller($interaction);

        return self::reply($mtg, $interaction, $private || (bool) ($args['hidden'] ?? false), fn () => $work($id, $name, $args));
    }

    /**
     * A deck's view.
     *
     * @param Deck        $deck
     * @param string|null $note
     *
     * @return MessageBuilder
     */
    private function view(Deck $deck, ?string $note = null): MessageBuilder
    {
        return $this->panels->deck($deck->playerId, $deck->id, $note);
    }

    /**
     * Autocomplete for `deck` (the caller's decks) and `card` (cards they
     * own and basic lands, or for `remove`, the cards in the deck).
     *
     * @param Interaction $interaction
     * @param mixed       $option
     *
     * @return array
     */
    private function suggest(Interaction $interaction, $option): array
    {
        [$id] = self::caller($interaction);
        $typed = (string) ($option->value ?? '');
        $builder = $this->pocket->deckBuilder;
        $subcommand = '';
        foreach ($interaction->data->options ?? [] as $sub) {
            $subcommand = (string) ($sub->name ?? '');
            break;
        }

        if (($option->name ?? '') === 'rental') {
            return self::choices($this->pocket->rentals->suggest($typed));
        }

        if (($option->name ?? '') === 'deck') {
            $choices = [];
            foreach ($builder->list($id) as $deck) {
                if ($typed === '' || stripos($deck->name, trim($typed)) !== false) {
                    $choices[$deck->id] = $deck->name;
                }
            }
            if ($subcommand === 'show') {
                $choices += $this->pocket->rentals->suggest($typed);
            }

            return self::choices($choices);
        }

        if (($option->name ?? '') !== 'card') {
            return [];
        }
        if ($subcommand === 'commander') {
            return self::choices(array_filter($builder->suggestCards($id, $typed), fn ($key) => GameMode::canBeCommander($builder->cardData((string) $key)), ARRAY_FILTER_USE_KEY));
        }
        if ($subcommand !== 'remove') {
            return self::choices($builder->suggestCards($id, $typed));
        }

        $typedArgs = self::typed($interaction);
        try {
            $deck = $builder->find($id, (string) ($typedArgs['deck'] ?? ''));
        } catch (\OutOfBoundsException) {
            return [];
        }
        $cards = ($typedArgs['side'] ?? false) ? $deck->side : $deck->main;
        $choices = [];
        foreach ($cards as $key => $count) {
            $card = $builder->cardData((string) $key);
            if ($typed === '' || stripos($card['name'], trim($typed)) !== false) {
                $choices[(string) $key] = "{$card['name']} ×{$count}";
            }
        }

        return self::choices($choices);
    }
}
