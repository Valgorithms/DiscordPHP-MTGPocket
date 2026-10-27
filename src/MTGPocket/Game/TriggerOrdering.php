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

/** Whole pending groups in the opt-in two-seat mode, not a general APNAP engine. */
trait TriggerOrdering
{
    private int $nextPendingTriggerId = 1;

    private ?array $triggerOrdering = null;

    /** A group is the old queue prefix; triggers generated during placement wait behind it. */
    private function prepareTriggerOrdering(): bool
    {
        if ($this->triggerOrdering !== null && $this->triggerOrdering['remaining'] === 0) {
            $this->triggerOrdering = null;
            $this->checkStateBasedActions();
        }
        if ($this->pendingTriggers === [] || $this->stage !== self::PLAYING) {
            return false;
        }
        if ($this->triggerOrdering === null) {
            if (count($this->pendingTriggers) > 256 || $this->nextPendingTriggerId > 2147483647 - count($this->pendingTriggers)) {
                $this->holdWardExecution('The pending-trigger group exceeds the bounded execution mode.');

                return true;
            }
            usort($this->pendingTriggers, fn (array $a, array $b) => ($a['controller'] !== $this->active) <=> ($b['controller'] !== $this->active));
            foreach ($this->pendingTriggers as &$trigger) {
                $trigger['orderId'] = $this->nextPendingTriggerId++;
            }
            unset($trigger);
            $this->triggerOrdering = ['active' => $this->active, 'participants' => array_values(array_unique(array_column($this->pendingTriggers, 'controller'))),
                'remaining' => count($this->pendingTriggers), 'ordered' => [], 'picks' => []];
        }
        $seat = $this->pendingTriggers[0]['controller'];
        if (in_array($seat, $this->triggerOrdering['ordered'], true)) {
            return false;
        }
        $options = $this->triggerOrderRemaining($seat);
        if (count($options) <= 1) {
            $this->finishTriggerOrder($seat, [...$this->triggerOrdering['picks'], ...$options]);

            return false;
        }
        $this->pendingChoice = ['type' => 'trigger_order', 'id' => $this->nextChoiceId++, 'seat' => $seat,
            'options' => $options, 'selected' => $this->triggerOrdering['picks']];

        return true;
    }

    private function triggerOrderRemaining(int $seat): array
    {
        $entries = array_filter(array_slice($this->pendingTriggers, 0, $this->triggerOrdering['remaining']), fn (array $entry) => $entry['controller'] === $seat);

        return array_values(array_diff(array_column($entries, 'orderId'), $this->triggerOrdering['picks']));
    }

    private function finishTriggerOrder(int $seat, array $ids): void
    {
        $prefix = array_filter(array_slice($this->pendingTriggers, 0, $this->triggerOrdering['remaining']), fn (array $entry) => $entry['controller'] === $seat);
        $byId = array_column($prefix, null, 'orderId');
        $this->pendingTriggers = [...array_map(fn (int $id) => $byId[$id], $ids), ...array_slice($this->pendingTriggers, count($ids))];
        $this->triggerOrdering['ordered'][] = $seat;
        $this->triggerOrdering['picks'] = [];
    }

    /** Choose the next ability put on the stack: earlier picks resolve later. */
    public function chooseNextTrigger(int $seat, int $choiceId, int $triggerId): void
    {
        $choice = $this->pendingChoice;
        if (! $this->wardExecutionEnabled || ($choice['type'] ?? null) !== 'trigger_order' || $choice['seat'] !== $seat
            || $choice['id'] !== $choiceId || ! in_array($triggerId, $choice['options'], true)) {
            throw new GameException('That trigger-order choice is no longer available.');
        }
        $this->validateWardExecutionState();
        $this->triggerOrdering['picks'][] = $triggerId;
        $this->pendingChoice = null;
        $this->settle();
    }

    /** Paged display only; the engine validates the selected stable trigger identity. */
    public function triggerOrderOptions(int $page = 0): array
    {
        if (($this->pendingChoice['type'] ?? null) !== 'trigger_order' || $page < 0) {
            return [];
        }
        $byId = array_column($this->pendingTriggers, null, 'orderId');
        $result = [];
        foreach (array_slice($this->pendingChoice['options'], $page * 25, 25) as $id) {
            $result[$id] = $byId[$id]['label'].': '.($byId[$id]['text'] ?? '');
        }

        return $result;
    }

    /** Preserve the selected group's remaining prefix across modes, targets and payment pauses. */
    private function shiftPendingTrigger(): ?array
    {
        $entry = array_shift($this->pendingTriggers);
        if ($this->wardExecutionEnabled && $this->triggerOrdering !== null && $entry !== null) {
            $this->triggerOrdering['remaining']--;
        }

        return $entry;
    }

    private function validateTriggerOrdering(): void
    {
        if (! is_int($this->nextPendingTriggerId) || $this->nextPendingTriggerId < 1 || $this->nextPendingTriggerId > 2147483647) {
            throw new GameException('Invalid pending-trigger identity sequence.');
        }
        $group = $this->triggerOrdering;
        if ($group === null) {
            if (($this->pendingChoice['type'] ?? null) === 'trigger_order' || array_filter($this->pendingTriggers, fn (array $entry) => array_key_exists('orderId', $entry)) !== []) {
                throw new GameException('Missing pending-trigger ordering group.');
            }

            return;
        }
        if (! $this->wardExecutionEnabled || array_keys($group) !== ['active', 'participants', 'remaining', 'ordered', 'picks'] || $group['active'] !== $this->active
            || ! is_array($group['participants']) || ! array_is_list($group['participants']) || count($group['participants']) < 1 || count($group['participants']) > 2
            || $group['participants'] !== array_values(array_filter([$this->active, 1 - $this->active], fn (int $seat) => in_array($seat, $group['participants'], true)))
            || ! is_int($group['remaining']) || $group['remaining'] < 0 || $group['remaining'] > min(256, count($this->pendingTriggers))
            || ! is_array($group['ordered']) || ! array_is_list($group['ordered']) || count($group['ordered']) > 2
            || count(array_unique($group['ordered'], SORT_REGULAR)) !== count($group['ordered'])
            || array_filter($group['ordered'], fn ($seat) => ! is_int($seat) || ! in_array($seat, [0, 1], true)) !== []
            || ! is_array($group['picks']) || ! array_is_list($group['picks']) || count($group['picks']) > 256
            || count(array_unique($group['picks'], SORT_REGULAR)) !== count($group['picks'])
            || array_filter($group['picks'], fn ($id) => ! is_int($id)) !== []) {
            throw new GameException('Invalid pending-trigger ordering group.');
        }
        if ($group['ordered'] !== array_slice($group['participants'], 0, count($group['ordered']))
            || ($group['remaining'] === 0 && ($group['ordered'] !== $group['participants'] || $group['picks'] !== []))) {
            throw new GameException('Invalid controller order in the pending group.');
        }
        $seen = [];
        $nonactiveSeen = false;
        foreach ($this->pendingTriggers as $index => $entry) {
            if ($index >= $group['remaining']) {
                if (array_key_exists('orderId', $entry)) {
                    throw new GameException('A later trigger was inserted into the older group.');
                }
                continue;
            }
            $id = $entry['orderId'] ?? null;
            if (! is_int($id) || $id < 1 || $id >= $this->nextPendingTriggerId || isset($seen[$id])
                || ! in_array($entry['controller'], [0, 1], true) || ($nonactiveSeen && $entry['controller'] === $this->active)) {
                throw new GameException('Invalid pending-trigger group prefix.');
            }
            $seen[$id] = true;
            $nonactiveSeen = $nonactiveSeen || $entry['controller'] !== $this->active;
        }
        $choice = $this->pendingChoice;
        $current = $this->pendingTriggers[0]['controller'] ?? null;
        $currentIds = array_column(array_filter(array_slice($this->pendingTriggers, 0, $group['remaining']), fn (array $entry) => $entry['controller'] === $current), 'orderId');
        if (array_diff($group['picks'], $currentIds) !== []) {
            throw new GameException('Stale partial trigger-order selection.');
        }
        if (($choice['type'] ?? null) === 'trigger_order') {
            $seat = $this->pendingTriggers[0]['controller'] ?? null;
            if ($group['remaining'] === 0 || $choice['seat'] !== $seat || in_array($seat, $group['ordered'], true)
                || ! is_int($choice['id'] ?? null) || $choice['id'] < 1 || $choice['id'] >= $this->nextChoiceId
                || ($choice['options'] ?? null) !== $this->triggerOrderRemaining($seat) || count($choice['options']) < 2
                || ($choice['selected'] ?? null) !== $group['picks']) {
                throw new GameException('Invalid saved trigger-order choice.');
            }
        } elseif ($group['picks'] !== []) {
            throw new GameException('Missing partial trigger-order selection.');
        }
    }
}
