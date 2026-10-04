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

namespace MTGPocket\Panels;

use Discord\Builders\Components\Label;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextInput;
use MTG\Helpers\Text;

/**
 * A pop-up form: up to five labelled text boxes and menus. Its fields come
 * back by their ids when it is sent; see {@see Panels::handle()}.
 *
 * @since 0.6.0
 */
final class Modal implements \JsonSerializable
{
    /** Discord's limit on fields in a modal. */
    public const int MAX_FIELDS = 5;

    /**
     * @var Label[]
     */
    private array $fields = [];

    public function __construct(public readonly string $title, public readonly string $customId)
    {
    }

    /**
     * A text box.
     *
     * @param string      $id
     * @param string      $label
     * @param string|null $value       What it starts with.
     * @param bool        $required
     * @param bool        $paragraph   Several lines.
     * @param string|null $placeholder
     * @param int         $max         Longest answer.
     *
     * @return static
     */
    public function text(string $id, string $label, ?string $value = null, bool $required = true, bool $paragraph = false, ?string $placeholder = null, int $max = 100): static
    {
        $input = TextInput::new(null, $paragraph ? TextInput::STYLE_PARAGRAPH : TextInput::STYLE_SHORT, $id)
            ->setRequired($required)
            ->setMaxLength($max);
        if ($value !== null && $value !== '') {
            $input->setValue(Text::clip($value, $max));
        }
        if ($placeholder !== null) {
            $input->setPlaceholder(Text::clip($placeholder, 100));
        }

        return $this->field(Label::new(Text::clip($label, 45), $input));
    }

    /**
     * A menu of fixed choices; one must be picked.
     *
     * @param string                $id
     * @param string                $label
     * @param array<string, string> $choices Value => label.
     * @param string|null           $default
     *
     * @return static
     */
    public function choice(string $id, string $label, array $choices, ?string $default = null): static
    {
        $select = StringSelect::new($id)->setRequired(true)->setMinValues(1)->setMaxValues(1);
        foreach (array_slice($choices, 0, 25, true) as $value => $text) {
            $option = Option::new(Text::clip($text, 100), (string) $value);
            if ((string) $value === $default) {
                $option->setDefault();
            }
            $select->addOption($option);
        }

        return $this->field(Label::new(Text::clip($label, 45), $select));
    }

    /**
     * @return Label[]
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function jsonSerialize(): array
    {
        return [
            'title' => Text::clip($this->title, 45),
            'custom_id' => $this->customId,
            'components' => array_map(fn (Label $label) => $label->jsonSerialize(), $this->fields),
        ];
    }

    private function field(Label $label): static
    {
        if (count($this->fields) >= self::MAX_FIELDS) {
            throw new \OverflowException('A modal holds at most '.self::MAX_FIELDS.' fields.');
        }
        $this->fields[] = $label;

        return $this;
    }
}
