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
use Discord\Builders\Components\MediaGallery;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTGPocket\Cards\BasicLands;
use MTGPocket\Cards\CardPool;
use MTGPocket\Decks\DeckBuilder;
use MTGPocket\Models\Deck;
use MTGPocket\Packs\OpenedPack;

/**
 * The game's messages, as Components V2 in the style of DiscordPHP-MTG's:
 * an opened pack, the packs on offer, a deck and a player's decks.
 * Collection pages use DiscordPHP-MTG's {@see \MTG\Builders\ListMessageBuilder}.
 *
 * Custom ids: `pocket:card` (a picker valued with a card uuid, which opens
 * DiscordPHP-MTG's card view) and `pocket:export:<playerId>:<deckId>`.
 *
 * @since 0.2.0
 */
class PocketMessageBuilder extends MessageBuilder
{
    public const string PREFIX = 'pocket';

    /**
     * Color names, by {@see CardPool::COLORS} letter.
     *
     * @var array<string, string>
     */
    public const array COLOR_NAMES = [
        'W' => 'White',
        'U' => 'Blue',
        'B' => 'Black',
        'R' => 'Red',
        'G' => 'Green',
        CardPool::MULTICOLOR => 'Multicolor',
        CardPool::COLORLESS => 'Colorless',
    ];

    /**
     * Deck sections, in display order, by the card type that puts a card in
     * one (the first that matches).
     *
     * @var array<string, string>
     */
    public const array SECTIONS = [
        'Land' => 'Lands',
        'Creature' => 'Creatures',
        'Planeswalker' => 'Planeswalkers',
        'Battle' => 'Battles',
        'Instant' => 'Instants',
        'Sorcery' => 'Sorceries',
        'Artifact' => 'Artifacts',
        'Enchantment' => 'Enchantments',
    ];

    /**
     * A just-opened pack: its cards in reveal order, new ones marked, its
     * rares shown big, and a picker to look at any card.
     *
     * @param OpenedPack $opened
     *
     * @return static
     */
    public static function pack(OpenedPack $opened): static
    {
        $pack = $opened->pack;
        $lines = array_map(fn (array $card) => self::cardLine($card).($opened->isNew($card['uuid']) ? ' 🆕' : ''), $pack->cards);

        $container = Container::new()
            ->setAccentColor(self::accent($pack->color))
            ->addComponent(TextDisplay::new(sprintf(
                "### %s — %s pack\n-# %s · %d new · next free pack <t:%d:R>",
                $pack->setName,
                self::COLOR_NAMES[$pack->color] ?? $pack->color,
                Text::plural(count($pack->cards), 'card'),
                count(array_unique(array_filter(array_column($pack->cards, 'uuid'), $opened->isNew(...)))),
                $opened->nextPackAt,
            )))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(Text::clip(implode("\n", $lines), 3500)));

        $gallery = MediaGallery::new();
        $images = 0;
        foreach ($pack->ofRarity('rare', 'mythic') as $card) {
            if (($url = self::imageUrl($card)) && $images < 10) {
                $gallery->addItem($url, Text::clip($card['name'], 1024));
                $images++;
            }
        }
        if ($images > 0) {
            $container->addComponent($gallery);
        }

        return static::panel()
            ->addComponent($container)
            ->addComponent(ActionRow::new()->addComponent(self::cardPicker($pack->cards)));
    }

    /**
     * The packs on offer, and when the player's next free one is ready.
     *
     * @param array<string, array{name: string, colors: string[]}> $choices
     * @param int|null                                           $nextPackAt Null when ready now.
     *
     * @return static
     */
    public static function packList(array $choices, ?int $nextPackAt): static
    {
        $status = $nextPackAt === null
            ? 'Your free pack is ready: `/pack open`.'
            : "Your next free pack is ready <t:{$nextPackAt}:R>.";

        $lines = [];
        foreach ($choices as $code => $choice) {
            $lines[] = "`{$code}` {$choice['name']} — ".implode(' ', array_map(fn (string $color) => self::COLOR_NAMES[$color] ?? $color, $choice['colors']));
        }

        return static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['multicolor'])
            ->addComponent(TextDisplay::new("### Packs\n{$status}\n-# One free pack a day; the day turns over at midnight UTC. Each pack is 15 cards of one color of one set, with at least one rare or mythic rare."))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new($lines === [] ? 'No packs yet: no card pools have been imported.' : Text::clip(implode("\n", $lines), 3500))));
    }

    /**
     * A deck: its cards grouped by type, its side deck, and a picker to look
     * at a card.
     *
     * @param Deck                    $deck
     * @param callable(string): array $card   Card data by deck key.
     * @param bool                    $active Whether it is the player's active deck.
     * @param string|null             $note   What just changed, shown above the deck.
     *
     * @return static
     */
    public static function deck(Deck $deck, callable $card, bool $active, ?string $note = null): static
    {
        $sections = [];
        foreach ($deck->main as $key => $count) {
            $data = $card((string) $key);
            $sections[self::section($data)][] = [$count, $data];
        }
        $order = array_flip([...array_values(self::SECTIONS), 'Other']);
        uksort($sections, fn (string $a, string $b) => $order[$a] <=> $order[$b]);

        $text = [];
        foreach ($sections as $title => $entries) {
            usort($entries, fn (array $a, array $b) => strcasecmp($a[1]['name'], $b[1]['name']));
            $text[] = "**{$title}** (".array_sum(array_column($entries, 0)).")\n".implode("\n", array_map(fn (array $entry) => "{$entry[0]} {$entry[1]['name']}", $entries));
        }
        if ($deck->side->total() > 0) {
            $side = [];
            foreach ($deck->side as $key => $count) {
                $side[] = "{$count} ".$card((string) $key)['name'];
            }
            sort($side);
            $text[] = "**Side deck** ({$deck->side->total()})\n".implode("\n", $side);
        }

        $heading = sprintf(
            "### %s\n-# %s · main deck %d · side deck %d%s",
            $deck->name,
            DeckBuilder::FORMATS[$deck->format] ?? ucfirst($deck->format),
            $deck->main->total(),
            $deck->side->total(),
            $active ? ' · ✅ active' : '',
        );

        $container = Container::new()->setAccentColor(CardMessageBuilder::ACCENTS['multicolor']);
        if ($note !== null) {
            $container->addComponent(TextDisplay::new($note))->addComponent(Separator::new());
        }
        $message = static::panel()->addComponent($container
            ->addComponent(TextDisplay::new($heading))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new($text === [] ? 'This deck is empty. Add cards with `/decks add`.' : Text::clip(implode("\n\n", $text), 3500))));

        $cards = [];
        foreach ($deck->allCards() as $key => $count) {
            if (! BasicLands::isBasic((string) $key)) {
                $cards[] = $card((string) $key);
            }
        }
        if ($cards !== []) {
            $message->addComponent(ActionRow::new()->addComponent(self::cardPicker($cards)));
        }

        return $message->addComponent(ActionRow::new()->addComponent(
            Button::new(Button::STYLE_SECONDARY, self::PREFIX.":export:{$deck->playerId}:{$deck->id}")->setLabel('Export decklist')
        ));
    }

    /**
     * A player's decks.
     *
     * @param Deck[]      $decks
     * @param string|null $activeId
     *
     * @return static
     */
    public static function deckList(array $decks, ?string $activeId): static
    {
        $lines = array_map(fn (Deck $deck) => sprintf(
            '**%s** · %s · %d + %d%s',
            $deck->name,
            DeckBuilder::FORMATS[$deck->format] ?? ucfirst($deck->format),
            $deck->main->total(),
            $deck->side->total(),
            $deck->id === $activeId ? ' · ✅ active' : '',
        ), array_values($decks));

        return static::panel()->addComponent(Container::new()
            ->setAccentColor(CardMessageBuilder::ACCENTS['multicolor'])
            ->addComponent(TextDisplay::new("### Your decks\n-# ".Text::plural(count($decks), 'deck').' · main + side deck'))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new($lines === [] ? 'No decks yet. Start one with `/decks create`.' : implode("\n", $lines))));
    }

    /**
     * A decklist as text: `3 Name (SET) 123`, the side deck after a blank
     * line, as MTG Arena and most deck sites import it.
     *
     * @param Deck                    $deck
     * @param callable(string): array $card Card data by deck key.
     *
     * @return string
     */
    public static function export(Deck $deck, callable $card): string
    {
        $line = function (string $key, int $count) use ($card): string {
            $data = $card($key);

            return trim("{$count} {$data['name']}".(isset($data['setCode']) ? " ({$data['setCode']}) ".($data['number'] ?? '') : ''));
        };

        $main = $side = [];
        foreach ($deck->main as $key => $count) {
            $main[] = $line((string) $key, $count);
        }
        foreach ($deck->side as $key => $count) {
            $side[] = $line((string) $key, $count);
        }

        return "Deck\n".implode("\n", $main).($side ? "\n\nSideboard\n".implode("\n", $side) : '')."\n";
    }

    /**
     * One card: rarity, name, type and set.
     *
     * @param array $card
     *
     * @return string
     */
    public static function cardLine(array $card): string
    {
        return (Text::RARITIES[$card['rarity']] ?? '▫️')." **{$card['name']}**".(empty($card['type']) ? '' : " · {$card['type']}");
    }

    /**
     * A short message.
     *
     * @param string $text
     *
     * @return static
     */
    public static function notice(string $text): static
    {
        return static::panel()->addComponent(Container::new()->setAccentColor(CardMessageBuilder::ACCENTS['colorless'])->addComponent(TextDisplay::new($text)));
    }

    /**
     * A card's Scryfall image.
     *
     * @param array $card
     *
     * @return string|null
     */
    public static function imageUrl(array $card): ?string
    {
        $id = $card['scryfallId'] ?? null;

        return is_string($id) && strlen($id) >= 2 ? "https://cards.scryfall.io/normal/front/{$id[0]}/{$id[1]}/{$id}.jpg" : null;
    }

    /**
     * The accent color of a pack color.
     *
     * @param string $color
     *
     * @return int
     */
    public static function accent(string $color): int
    {
        return CardMessageBuilder::ACCENTS[match ($color) {
            CardPool::MULTICOLOR => 'multicolor',
            CardPool::COLORLESS => 'colorless',
            default => $color,
        }] ?? CardMessageBuilder::ACCENTS['colorless'];
    }

    /**
     * An empty Components V2 message that mentions no one.
     *
     * @return static
     */
    protected static function panel(): static
    {
        return static::new()->setIsComponentsV2Flag()->setAllowedMentions(AllowedMentions::none());
    }

    /**
     * A picker that opens a card's full view.
     *
     * @param array[] $cards
     *
     * @return StringSelect
     */
    protected static function cardPicker(array $cards): StringSelect
    {
        $select = StringSelect::new(self::PREFIX.':card')->setPlaceholder('Look at a card');
        $seen = [];
        foreach ($cards as $card) {
            if (isset($seen[$card['uuid']]) || count($seen) >= 25) {
                continue;
            }
            $seen[$card['uuid']] = true;
            $select->addOption(Option::new(Text::clip($card['name'], 100), $card['uuid'])
                ->setDescription(Text::clip(ucfirst($card['rarity']).(isset($card['setCode']) ? " · {$card['setCode']}" : ''), 100)));
        }

        return $select;
    }

    /**
     * The deck section a card goes in.
     *
     * @param array $card
     *
     * @return string
     */
    protected static function section(array $card): string
    {
        $types = explode('—', (string) ($card['type'] ?? ''))[0];
        foreach (self::SECTIONS as $type => $title) {
            if (str_contains($types, $type)) {
                return $title;
            }
        }

        return 'Other';
    }
}
