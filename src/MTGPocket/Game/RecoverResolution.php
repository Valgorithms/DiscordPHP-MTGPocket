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

/** Recover queues after death and pays only when its original trigger resolves. */
trait RecoverResolution
{
    /** @return array<int, array{object: int, incarnation: int, owner: int, cost: string}> */
    private function recoverObservers(): array
    {
        $observers = [];
        foreach ($this->players as $seat => $player) {
            foreach ($player->graveyard as $id) {
                $card = $this->objects[$id];
                if (isset($card->printed()->altCosts['recover'])) {
                    $observers[] = ['object' => $id, 'incarnation' => $card->incarnation, 'owner' => $seat, 'cost' => $card->printed()->altCosts['recover']];
                }
            }
        }

        return $observers;
    }

    /** @param array<int, array{object: int, incarnation: int, owner: int, cost: string}> $observers */
    private function queueRecover(int $graveyardOwner, array $observers): void
    {
        foreach ($observers as $observer) {
            if ($observer['owner'] !== $graveyardOwner) {
                continue;
            }
            $source = $this->objects[$observer['object']];
            $this->pendingTriggers[] = [
                'source' => $source->id, 'incarnation' => $observer['incarnation'], 'controller' => $graveyardOwner,
                'effects' => [['type' => 'optional_recover']], 'kinds' => [],
                'label' => $source->name()."'s recover ability", 'text' => 'Recover '.$observer['cost'],
            ];
        }
    }

    /** Preserve mana Recover; the one symbolic life cost uses the existing life-payment planner. */
    private function recoverPayment(int $seat, string $cost): ?array
    {
        if ($cost === CardDefinition::RECOVER_HALF_LIFE) {
            $life = max(0, $this->players[$seat]->life);

            return $this->payFor($seat, '', intdiv($life, 2) + $life % 2);
        }

        return $this->payFor($seat, $cost);
    }

    /** Whether the original trigger controller can pay, even if its source left. */
    public function canPayRecover(int $seat): bool
    {
        return $this->stage === self::PLAYING && ($this->pendingChoice['type'] ?? null) === 'optional_recover'
            && $this->pendingChoice['seat'] === $seat && $this->recoverPayment($seat, $this->pendingChoice['cost']) !== null;
    }

    /** Resolve an exact Recover decision; nested damage resumes after already-paid costs. */
    public function chooseRecover(int $seat, int $choiceId, string $option): void
    {
        $choice = $this->pendingChoice;
        if ($this->stage !== self::PLAYING || ($choice['type'] ?? null) !== 'optional_recover'
            || $choice['seat'] !== $seat || $choice['id'] !== $choiceId || ! in_array($option, $choice['options'], true)) {
            throw new GameException('That recover choice is no longer available to you.');
        }
        $payment = $option === 'accept' ? $this->recoverPayment($seat, $choice['cost']) : null;
        if ($option === 'accept' && $payment === null) {
            throw new GameException('You cannot pay the recover cost.');
        }
        $resume = $choice['resume'];
        $this->followed = $resume['followed'];
        $this->pendingChoice = null;
        if ($payment !== null && $this->pay($seat, $payment, ['kind' => 'resolution'])) {
            $resume['steps'] = [[['type' => 'recover_return'], null], ...$resume['steps']];
            $this->pendingChoice['resume'] = $resume;
            $this->sealDamageChoice();

            return;
        }
        $this->finishRecover($this->objects[$choice['object']], $choice['incarnation'], $payment !== null);
        $this->runSteps($resume['item'], $resume['steps'], $resume['self']);
        $this->settle();
    }

    /** A different graveyard incarnation is never moved by this older trigger. */
    private function finishRecover(GameObject $source, int $incarnation, bool $paid): void
    {
        if ($source->zone !== GameObject::GRAVEYARD || $source->incarnation !== $incarnation) {
            return;
        }
        $this->log($paid ? "{$this->players[$source->owner]->name} pays recover and returns {$source->name()} to their hand."
            : "{$source->name()} is exiled (recover wasn't paid).");
        $this->moveTo($source, $paid ? GameObject::HAND : GameObject::EXILE);
    }

    /** Additional bounded state checks, alongside the ordinary optional-choice contract. */
    private function validateRecoverChoice(array $choice): void
    {
        $source = is_int($choice['object'] ?? null) ? ($this->objects[$choice['object']] ?? null) : null;
        $resume = $choice['resume'] ?? null;
        if ($source === null || $source->owner !== ($choice['seat'] ?? null)
            || ! is_string($choice['cost'] ?? null) || $choice['cost'] !== ($source->printed()->altCosts['recover'] ?? null)
            || ! is_int($choice['incarnation'] ?? null) || $choice['incarnation'] > $source->incarnation
            || strlen(json_encode($choice, JSON_THROW_ON_ERROR)) > 1048576
            || ! is_array($resume) || ($resume['steps'] ?? null) !== []
            || ! is_array($resume['followed'] ?? null) || count($resume['followed']) > 256
            || ($resume['self'] ?? null) !== null
            || ($resume['item']['effects'] ?? null) !== [['type' => 'optional_recover']]) {
            throw new GameException('Invalid saved recover choice.');
        }
    }

    /** A paid Recover continuation contains exactly one return and never repays. */
    private function validateRecoverDamageResume(array $resume): void
    {
        $item = $resume['item'];
        if (($item['effects'] ?? null) !== [['type' => 'optional_recover']]) {
            return;
        }
        $source = $this->objects[$item['object']];
        if (! self::isAbility($item) || $source->owner !== $item['controller']
            || ! isset($source->printed()->altCosts['recover']) || $source->printed()->altCosts['recover'] === CardDefinition::RECOVER_HALF_LIFE || $item['targets'] !== []
            || $resume['self'] !== null || $resume['steps'] !== [[['type' => 'recover_return'], null]]) {
            throw new GameException('Invalid saved paid recover continuation.');
        }
    }

    /** Older executables could not produce a half-life Recover entry or pending payment. */
    private function validateRecoverSchema(int $version): void
    {
        if ($version >= 21) {
            return;
        }
        if (($this->pendingChoice['type'] ?? null) === 'optional_recover'
            && ($this->pendingChoice['cost'] ?? null) === CardDefinition::RECOVER_HALF_LIFE) {
            throw new GameException('Half-life Recover requires saved game schema 21.');
        }
        foreach ([...$this->pendingTriggers, ...$this->stack, $this->pendingChoice['resume']['item'] ?? []] as $entry) {
            $id = $entry['object'] ?? $entry['source'] ?? null;
            $source = is_int($id) ? ($this->objects[$id] ?? null) : null;
            if (($entry['effects'][0]['type'] ?? null) === 'optional_recover'
                && ($source?->printed()->altCosts['recover'] ?? null) === CardDefinition::RECOVER_HALF_LIFE) {
                throw new GameException('Half-life Recover requires saved game schema 21.');
            }
        }
    }

}
