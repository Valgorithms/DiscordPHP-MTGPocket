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
use Discord\Parts\Channel\Message\AllowedMentions;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use MTG\Modules\InteractionTrait;
use MTG\Modules\Module;
use MTG\MTG;
use MTGPocket\Builders\PocketMessageBuilder;
use MTGPocket\Cards\CardPool;
use MTGPocket\Exports\Exporter;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Deck;
use MTGPocket\Pocket;
use MTGPocket\Rentals\RentalDeck;

/**
 * `/export deck [deck] [format]` and `/export collection [format] [set]
 * [color] [rarity] [player]`: a player's decks and cards as a file for MTG
 * Arena, Tabletop Simulator or Frogtown, sent as an attachment.
 *
 * Also answers the deck view's **Export** picker,
 * `pocket:export:<playerId>:<deckId>`, valued with a format.
 *
 * @since 0.6.0
 */
final class Exports implements Module
{
    use InteractionTrait;
    use PocketTrait;

    /**
     * How to load each format, under the file.
     *
     * @var array<string, string>
     */
    public const array HOW_TO = [
        'arena' => 'In MTG Arena, copy the file\'s text and press **Import** in Decks. Moxfield and Archidekt read it too.',
        'tts' => 'Put the file in `Documents/My Games/Tabletop Simulator/Saves/Saved Objects`, then in a game open **Objects ▸ Saved Objects**.',
        'frogtown' => 'On frogtown.me, open the deck builder and paste the file\'s text into its import box. Cockatrice and Forge read it too.',
    ];

    public function __construct(protected Pocket $pocket)
    {
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'exports';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        $format = fn () => self::withChoices($mtg, self::option($mtg, Option::STRING, 'format', 'Where you will use it (default MTG Arena).'), Exporter::FORMATS);

        return [self::command('export', 'Your decks and cards as a file for MTG Arena, Tabletop Simulator or Frogtown.')
            ->addOption(self::subcommand($mtg, 'deck', 'One of your decks as a file.', self::option($mtg, Option::STRING, 'deck', 'One of your decks (default the one you play with).', false, true), $format(), self::hidden($mtg)))
            ->addOption(self::subcommand(
                $mtg,
                'collection',
                'The cards you own as a file.',
                $format(),
                self::option($mtg, Option::STRING, 'set', 'Only this set.', false, true),
                self::colorOption($mtg, 'Only this color.'),
                self::withChoices($mtg, self::option($mtg, Option::STRING, 'rarity', 'Only this rarity.'), array_combine(CardPool::RARITIES, array_map('ucfirst', CardPool::RARITIES))),
                self::option($mtg, Option::USER, 'player', 'Someone else\'s collection.'),
                self::hidden($mtg),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand(['export', 'deck'], function (Interaction $interaction, $options) use ($mtg) {
            $args = self::values($options);
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $this->exportDeck($id, (string) ($args['deck'] ?? ''), (string) ($args['format'] ?? 'arena')));
        }, fn (Interaction $interaction, $option) => $this->suggest($interaction, $option));

        $mtg->listenCommand(['export', 'collection'], function (Interaction $interaction, $options) use ($mtg) {
            $args = self::values($options);
            [$id] = self::caller($interaction);

            return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $this->exportCollection((string) ($args['player'] ?? $id), (string) ($args['format'] ?? 'arena'), $args));
        }, fn (Interaction $interaction, $option) => $this->suggest($interaction, $option));

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }

            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== PocketMessageBuilder::PREFIX || ($parts[1] ?? '') !== 'export') {
                return;
            }

            // Buttons from before the picker carry no value: Arena, as they did.
            $format = (string) ($interaction->data->values[0] ?? 'arena');
            self::answer($mtg, $interaction, fn () => $this->exportDeck((string) ($parts[2] ?? ''), (string) ($parts[3] ?? ''), $format, true));
        });
    }

    /**
     * One of a player's decks as a file.
     *
     * @param string $playerId
     * @param string $deck     Id or name; empty for their active deck.
     * @param string $format
     * @param bool   $byId     The deck came from a custom id, so a missing one has been deleted.
     *
     * @return MessageBuilder
     */
    public function exportDeck(string $playerId, string $deck, string $format, bool $byId = false): MessageBuilder
    {
        if ($byId) {
            $found = $this->pocket->decks->find($playerId, $deck);
            if ($found === null) {
                return PocketMessageBuilder::notice('This deck no longer exists.');
            }
        } else {
            $deck = $deck !== '' ? $deck : ($this->pocket->rentals->activeRental($playerId) ?? $this->pocket->deckBuilder->activeDeckId($playerId) ?? '');
            if ($deck === '') {
                return PocketMessageBuilder::notice('You have no active deck. Pick one with `/export deck deck:`.');
            }
            if (RentalDeck::isRental($deck)) {
                return PocketMessageBuilder::notice('Rental decks cannot be exported. Pick one of your own with `/export deck deck:`.');
            }
            $found = $this->pocket->deckBuilder->find($playerId, $deck);
        }

        return $this->file($found->name, $format, fn (Exporter $exporter) => $exporter->deck($found, $format), $this->summary($found));
    }

    /**
     * A player's cards as a file, filtered as `/collection` filters them.
     *
     * @param string $playerId
     * @param string $format
     * @param array  $filters `set`, `color`, `rarity`.
     *
     * @return MessageBuilder
     */
    public function exportCollection(string $playerId, string $format, array $filters = []): MessageBuilder
    {
        $filters = array_intersect_key($filters, array_flip(['set', 'color', 'rarity']));
        if (isset($filters['set'])) {
            $filters['set'] = strtoupper(trim((string) $filters['set']));
        }

        $cards = new CardCounts();
        foreach ($this->pocket->collection->entries($this->pocket->inventories->get($playerId), $filters) as $entry) {
            $cards->add($entry['card']['uuid'], $entry['count']);
        }
        if ($cards->total() === 0) {
            return PocketMessageBuilder::notice('There are no cards to export. Open a free pack with `/pack open`.');
        }

        $owner = $this->pocket->players->find($playerId)?->name ?: 'Player';
        $title = implode(' ', array_filter([
            "{$owner}'s collection",
            (string) ($filters['set'] ?? ''),
            PocketMessageBuilder::COLOR_NAMES[$filters['color'] ?? ''] ?? '',
            ucfirst((string) ($filters['rarity'] ?? '')),
        ]));

        return $this->file($title, $format, fn (Exporter $exporter) => $exporter->collection($cards, $title, $format), "{$cards->total()} cards, {$cards->count()} different");
    }

    /**
     * The answer: what the file holds and how to load it, with the file attached.
     *
     * @param string                          $title
     * @param string                          $format
     * @param callable(Exporter): array{0: string, 1: string} $export
     * @param string                          $summary
     *
     * @return MessageBuilder
     */
    private function file(string $title, string $format, callable $export, string $summary): MessageBuilder
    {
        if (! isset(Exporter::FORMATS[$format])) {
            return PocketMessageBuilder::notice('Pick a format: '.implode(', ', Exporter::FORMATS).'.');
        }
        [$name, $contents] = $export(new Exporter($this->pocket->deckBuilder->cardData(...)));

        return MessageBuilder::new()
            ->setAllowedMentions(AllowedMentions::none())
            ->setContent("**{$title}** for ".Exporter::FORMATS[$format]." ({$summary}).\n-# ".self::HOW_TO[$format])
            ->addFileFromContent($name, $contents);
    }

    /**
     * A deck's size, for the answer.
     *
     * @param Deck $deck
     *
     * @return string
     */
    private function summary(Deck $deck): string
    {
        return "main deck {$deck->size()}, side deck {$deck->side->total()}";
    }

    /**
     * Autocomplete for `deck` (the caller's decks) and `set` (imported sets).
     *
     * @param Interaction $interaction
     * @param mixed       $option
     *
     * @return array
     */
    private function suggest(Interaction $interaction, $option): array
    {
        $typed = (string) ($option->value ?? '');
        if (($option->name ?? '') === 'set') {
            return self::choices(self::setChoices(array_map(fn (CardPool $pool) => ['name' => $pool->setName], $this->pocket->pools->all()), $typed));
        }
        if (($option->name ?? '') !== 'deck') {
            return [];
        }

        [$id] = self::caller($interaction);
        $choices = [];
        foreach ($this->pocket->deckBuilder->list($id) as $deck) {
            if ($typed === '' || stripos($deck->name, trim($typed)) !== false) {
                $choices[$deck->id] = $deck->name;
            }
        }

        return self::choices($choices);
    }
}
