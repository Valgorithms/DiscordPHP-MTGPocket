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
use Discord\Builders\Components\UserSelect;
use Discord\Builders\MessageBuilder;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;

/**
 * The game's menus: the home panel `/menu` opens, and the buttons and menus
 * every panel uses to get around.
 *
 * Custom ids: `pocket:ui:<owner>:<action>[:<arg>…]`, where the owner is the
 * player the panel was made for. Their clicks change the panel in place;
 * anyone else's click opens the same panel for themselves, privately. The
 * actions are listed in {@see \MTGPocket\Panels\Panels}.
 *
 * @since 0.6.0
 */
class MenuMessageBuilder extends PocketMessageBuilder
{
    public const string UI = 'ui';

    /**
     * The home panel's sections: action => label.
     *
     * @var array<string, string>
     */
    public const array SECTIONS = [
        'packs' => '🎁 Packs',
        'coll' => '📚 Collection',
        'decks' => '🃏 Decks',
        'play' => '⚔️ Play',
        'draft' => '🎴 Draft',
        'shop' => '🛒 Shop',
        'trades' => '🤝 Trades',
        'quests' => '🎯 Quests',
        'tut' => '📖 How to play',
    ];

    /**
     * A custom id for a panel action.
     *
     * @param string $owner
     * @param string $action
     * @param string ...$args
     *
     * @return string
     */
    public static function id(string $owner, string $action, string ...$args): string
    {
        $id = implode(':', [self::PREFIX, self::UI, $owner, $action, ...$args]);
        if (strlen($id) > 100) {
            throw new \LengthException("Custom id too long: {$id}");
        }

        return $id;
    }

    /**
     * A grey button for a panel action.
     *
     * @param string $owner
     * @param string $label
     * @param string $action
     * @param string ...$args
     *
     * @return Button
     */
    public static function button(string $owner, string $label, string $action, string ...$args): Button
    {
        return Button::new(Button::STYLE_SECONDARY, self::id($owner, $action, ...$args))->setLabel(Text::clip($label, 80));
    }

    /**
     * A button in a given style.
     *
     * @param int    $style A {@see Button} style.
     * @param string $owner
     * @param string $label
     * @param string $action
     * @param string ...$args
     *
     * @return Button
     */
    public static function styled(int $style, string $owner, string $label, string $action, string ...$args): Button
    {
        return self::button($owner, $label, $action, ...$args)->setStyle($style);
    }

    /**
     * **🏠 Menu**, back to the home panel.
     *
     * @param string $owner
     *
     * @return Button
     */
    public static function menuButton(string $owner): Button
    {
        return self::button($owner, '🏠 Menu', 'home');
    }

    /**
     * A row with **Back** to a panel (when given) and **Menu**.
     *
     * @param string      $owner
     * @param string|null $label  The back button's label.
     * @param string|null $action Where it goes.
     * @param string      ...$args
     *
     * @return ActionRow
     */
    public static function nav(string $owner, ?string $label = null, ?string $action = null, string ...$args): ActionRow
    {
        $row = ActionRow::new();
        if ($label !== null && $action !== null) {
            $row->addComponent(self::button($owner, "◀ {$label}", $action, ...$args));
        }

        return $row->addComponent(self::menuButton($owner));
    }

    /**
     * A menu of choices for a panel action.
     *
     * @param string                                                     $customId
     * @param string                                                     $placeholder
     * @param array<string, string|array{label: string, description?: string}> $choices Value => label, or label and description.
     * @param int                                                        $max         How many can be picked at once.
     * @param string|null                                                $default     The value shown as picked.
     *
     * @return ActionRow|null Null when there is nothing to choose.
     */
    public static function select(string $customId, string $placeholder, array $choices, int $max = 1, ?string $default = null): ?ActionRow
    {
        if ($choices === []) {
            return null;
        }
        $choices = array_slice($choices, 0, 25, true);
        $select = StringSelect::new($customId)->setPlaceholder(Text::clip($placeholder, 150));
        foreach ($choices as $value => $choice) {
            $choice = is_array($choice) ? $choice : ['label' => $choice];
            $option = Option::new(Text::clip($choice['label'], 100), (string) $value);
            if (! empty($choice['description'])) {
                $option->setDescription(Text::clip($choice['description'], 100));
            }
            if ($default !== null && (string) $value === $default) {
                $option->setDefault();
            }
            $select->addOption($option);
        }
        if ($max > 1) {
            $select->setMinValues(1)->setMaxValues(min($max, count($choices)));
        }

        return ActionRow::new()->addComponent($select);
    }

    /**
     * A menu to pick another player.
     *
     * @param string $customId
     * @param string $placeholder
     *
     * @return ActionRow
     */
    public static function userSelect(string $customId, string $placeholder): ActionRow
    {
        return ActionRow::new()->addComponent(UserSelect::new($customId)->setPlaceholder(Text::clip($placeholder, 150)));
    }

    /**
     * A panel: a heading and lines in a container, an optional note above.
     *
     * @param string      $text
     * @param string|null $note
     * @param int|null    $accent
     *
     * @return static
     */
    public static function text(string $text, ?string $note = null, ?int $accent = null): static
    {
        $container = Container::new()->setAccentColor($accent ?? CardMessageBuilder::ACCENTS['multicolor']);
        if ($note !== null) {
            $container->addComponent(TextDisplay::new($note))->addComponent(Separator::new());
        }

        return static::panel()->addComponent($container->addComponent(TextDisplay::new(Text::clip($text, 3800))));
    }

    /**
     * Puts a note at the top of a panel: a separate text block above it.
     *
     * @param MessageBuilder $message
     * @param string|null    $note
     *
     * @return MessageBuilder
     */
    public static function withNote(MessageBuilder $message, ?string $note): MessageBuilder
    {
        if ($note === null || $note === '') {
            return $message;
        }
        $components = $message->getComponents();
        $message->setComponents([TextDisplay::new(Text::clip($note, 1900)), ...$components]);

        return $message;
    }

    /**
     * The home panel.
     *
     * @param string   $owner
     * @param string   $name
     * @param string[] $status One line about each part of the game.
     * @param string|null $note
     *
     * @return static
     */
    public static function home(string $owner, string $name, array $status, ?string $note = null): static
    {
        $message = self::text(
            "### 🏠 {$name}'s Pocket\n".implode("\n", $status)."\n-# Everything is here: pick a section. The slash commands still work too.",
            $note,
        );
        $rows = array_chunk(self::SECTIONS, 5, true);
        foreach ($rows as $sections) {
            $row = ActionRow::new();
            foreach ($sections as $action => $label) {
                $row->addComponent(self::styled($action === 'packs' ? Button::STYLE_PRIMARY : Button::STYLE_SECONDARY, $owner, $label, $action));
            }
            $message->addComponent($row);
        }

        return $message;
    }

    /**
     * Counts the components in a message, nested ones included; Discord
     * allows 40.
     *
     * @param MessageBuilder $message
     *
     * @return int
     */
    public static function componentCount(MessageBuilder $message): int
    {
        $count = 0;
        $walk = function (array $components) use (&$walk, &$count): void {
            foreach ($components as $component) {
                $count++;
                foreach (['components', 'accessory'] as $key) {
                    if (isset($component[$key])) {
                        $walk($key === 'accessory' ? [$component[$key]] : $component[$key]);
                    }
                }
            }
        };
        $walk(json_decode(json_encode($message->getComponents()), true));

        return $count;
    }
}
