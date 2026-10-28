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

namespace MTGPocket\Game;

/** Resolution-owned Madness decision; never grants general priority. */
trait MadnessResolution
{
    /** Preview only the current owner's resolution-owned cast at X = 0. */
    public function canCastMadness(int $seat): bool
    {
        $choice = $this->pendingChoice;
        if (! is_array($choice) || ($choice['type'] ?? null) !== 'optional_madness' || ($choice['seat'] ?? null) !== $seat) {
            return false;
        }
        try {
            $this->validateMadnessChoice($choice);
            $source = $this->objects[$choice['object']];
            if ($source->zone !== GameObject::EXILE || $source->exiledBy !== 'madness' || $source->incarnation !== $choice['incarnation']) {
                return false;
            }
            $options = self::castOptions('md');
            if ($this->whyNotCast($seat, $source->id, $options, $choice) !== null) {
                return false;
            }
            foreach ($this->castKinds($source->printed(), $options) as $kind) {
                if ($this->targetOptions($seat, $kind) === []) {
                    return false;
                }
            }

            return $this->paymentFor($seat, $source->id, 0, $options) !== null;
        } catch (GameException) {
            return false;
        }
    }

    /** @param string[] $targets */
    public function chooseMadness(int $seat, int $choiceId, string $option, array $targets = [], int $x = 0): ?array
    {
        $choice = $this->pendingChoice;
        if (! is_array($choice) || ($choice['type'] ?? null) !== 'optional_madness'
            || ($choice['seat'] ?? null) !== $seat || ($choice['id'] ?? null) !== $choiceId
            || ! in_array($option, ['accept', 'decline'], true)) {
            throw new GameException('That Madness choice is no longer available.');
        }
        $this->validateMadnessChoice($choice);
        $source = $this->objects[$choice['object']];
        if ($source->zone !== GameObject::EXILE || $source->exiledBy !== 'madness' || $source->incarnation !== $choice['incarnation']) {
            throw new GameException('That Madness card incarnation is no longer available.');
        }
        if ($option === 'accept') {
            return $this->castMadness($seat, $source->id, $x, $targets, $choice);
        }
        if ($targets !== [] || $x !== 0) {
            throw new GameException('Declining Madness cannot select targets or X.');
        }
        $this->pendingChoice = null;
        $this->moveTo($source, GameObject::GRAVEYARD);
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();

        return null;
    }

    /** Strict shape and identity checks apply to live and persisted choices. */
    private function validateMadnessChoice(array $choice): void
    {
        $keys = array_keys($choice);
        sort($keys);
        $resume = $choice['resume'] ?? null;
        $item = is_array($resume) ? ($resume['item'] ?? null) : null;
        $source = is_int($choice['object'] ?? null) ? ($this->objects[$choice['object']] ?? null) : null;
        if ($keys !== ['id', 'incarnation', 'object', 'options', 'resume', 'seat', 'triggerId', 'type']
            || ($choice['type'] ?? null) !== 'optional_madness' || $this->stage !== self::PLAYING
            || ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $choice['id'] >= $this->nextChoiceId
            || ! in_array($choice['seat'] ?? null, [0, 1], true) || $source === null || $source->owner !== $choice['seat']
            || ! is_int($choice['incarnation'] ?? null) || $choice['incarnation'] < 0 || $choice['incarnation'] > $source->incarnation
            || ! is_int($choice['triggerId'] ?? null) || $choice['triggerId'] < 1 || $choice['triggerId'] >= $this->nextStackId
            || ($choice['options'] ?? null) !== ['accept', 'decline'] || ! isset($source->printed()->altCosts['madness'])
            || ! is_array($resume) || array_keys($resume) !== ['item', 'steps', 'self', 'followed']
            || ! is_array($item) || array_diff(array_keys($item), ['id', 'object', 'incarnation', 'controller', 'x', 'targets', 'kind', 'effects', 'kinds', 'label', 'uncounterable']) !== []
            || ($item['kind'] ?? null) !== 'ability' || (array_key_exists('uncounterable', $item) && ! is_bool($item['uncounterable']))
            || in_array($choice['triggerId'], array_column($this->stack, 'id'), true) || ($item['id'] ?? null) !== $choice['triggerId']
            || ($item['object'] ?? null) !== $choice['object'] || ($item['incarnation'] ?? null) !== $choice['incarnation']
            || ($item['controller'] ?? null) !== $choice['seat'] || ($item['x'] ?? null) !== 0
            || ($item['effects'] ?? null) !== [['type' => 'madness']] || ($item['targets'] ?? null) !== []
            || ($item['kinds'] ?? null) !== [] || ! is_string($item['label'] ?? null) || strlen($item['label']) > 256
            || ($resume['steps'] ?? null) !== [] || ($resume['self'] ?? null) !== null
            || ! is_array($resume['followed'] ?? null) || count($resume['followed']) > 256
            || strlen(json_encode($choice, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new GameException('Invalid saved Madness resolution choice.');
        }
        foreach ($resume['followed'] as $from => $to) {
            if (! is_string($from) || ! is_string($to) || strlen($from) > 64 || strlen($to) > 64) {
                throw new GameException('Invalid saved Madness resolution targets.');
            }
        }
    }

    private function finishMadnessCast(array $after, ?array $observation): array
    {
        $committed = $this->finishPaidCast($after, $observation);
        $resume = $after['madnessChoice']['resume'];
        $this->followed = $resume['followed'];
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);

        return $committed;
    }
}
