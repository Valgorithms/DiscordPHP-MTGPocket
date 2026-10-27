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

/** Explicit existing action tails, resumed once after painful mana ordering. */
trait PaidActionContinuation
{
    private const array PAID_UTILITY_ACTIONS = ['cycle', 'unearth', 'plot', 'foretell', 'transmute', 'bloodrush', 'ninjutsu', 'suspend', 'regrow', 'embalm'];

    private function finishPaidUtility(array $after): void
    {
        ['seat' => $seat, 'object' => $id] = $after;
        $object = $this->objects[$id];
        $card = $object->printed();
        switch ($after['kind']) {
            case 'ward_trigger':
                $this->discardCheapest($seat, $after['wardDiscard']);
                $this->pushAbility($after['trigger'], $after['targets'], 'triggers');

                return;
            case 'cycle':
                $this->moveTo($object, GameObject::GRAVEYARD);
                // `When you cycle CARDNAME, …` and `Whenever you cycle a card, …`, above the cycling draw.
                $this->trigger($object, 'cycled', $seat);
                foreach ($this->permanents($seat) as $permanent) {
                    // Cycling discards the card (rule 702.29a).
                    $this->trigger($permanent, 'you_cycle', $seat);
                    $this->trigger($permanent, 'you_discard', $seat);
                    $this->trigger($permanent, 'you_discard_batch', $seat);
                }
                $this->discardedBy($seat, $object->id);
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => [$card->cyclingFinds === null ? ['type' => 'draw', 'amount' => 1] : ['type' => 'search', 'find' => $card->cyclingFinds, 'to' => 'hand']],
                    'kinds' => [],
                    'label' => "{$card->name}'s ".($card->cyclingFinds === null ? 'cycling' : 'landcycling'),
                ], [], 'is activated', "cycle {$card->name}");
                $this->settle();

                return;
            case 'unearth':
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => [['type' => 'unearth']],
                    'kinds' => [],
                    'label' => "{$card->name}'s unearth",
                ], [], 'is activated', "unearth {$card->name}");
                $this->settle();

                return;
            case 'plot':
                $this->moveTo($object, GameObject::EXILE);
                $object->exiledBy = 'plot';
                $object->exiledOn = $this->turn;
                $this->log("{$this->players[$seat]->name} plots {$object->name()}.", $seat, "plot {$object->name()}");
                $this->settle();

                return;
            case 'foretell':
                $this->moveTo($object, GameObject::EXILE);
                $object->exiledBy = 'foretell';
                $object->exiledOn = $this->turn;
                $this->log("{$this->players[$seat]->name} foretells a card.", $seat, 'foretell');
                $this->settle();

                return;
            case 'transmute':
                $value = $card->cost->manaValue();
                $this->moveTo($object, GameObject::GRAVEYARD);
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => [['type' => 'tutor', 'filter' => "mv_ge_{$value}&mv_le_{$value}", 'to' => 'hand']],
                    'kinds' => [],
                    'label' => "{$card->name}'s transmute",
                ], [], 'is activated', "transmute {$card->name}");
                $this->settle();

                return;
            case 'bloodrush':
                $attacker = $this->targetObject($after['attacker']);
                $this->discardCards($seat, [$id]);
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => TextParser::effects($card->altCosts['bloodrushText']) ?? [],
                    'kinds' => ['attacking'],
                    'label' => "{$card->name}'s bloodrush",
                ], ["o:{$attacker->id}"], 'is activated', "bloodrush {$card->name}");
                $this->settle();

                return;
            case 'ninjutsu':
                $back = $this->targetObject($after['returned']);
                $this->moveTo($back, GameObject::HAND);
                $this->log("{$this->players[$seat]->name} returns {$back->name()} to hand for ninjutsu.");
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => [['type' => 'ninjutsu']],
                    'kinds' => [],
                    'label' => "{$card->name}'s ninjutsu",
                ], [], 'is activated', "ninjutsu {$card->name}");
                $this->settle();

                return;
            case 'suspend':
                $this->moveTo($object, GameObject::EXILE);
                $object->exiledBy = 'suspend';
                $object->exiledOn = $this->turn;
                $object->addCounters('time', self::suspendTime($card));
                $this->log("{$this->players[$seat]->name} suspends {$object->name()} with {$object->counter('time')} time counters.", $seat, "suspend {$object->name()}");
                $this->settle();

                return;
            case 'regrow':
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => [['type' => 'regrow']],
                    'kinds' => [],
                    'label' => "{$card->name}'s ability",
                ], [], 'is activated', "return {$card->name}");
                $this->settle();

                return;
            case 'embalm':
                $way = $after['way'];
                $this->moveTo($object, GameObject::EXILE);
                $this->pushAbility([
                    'source' => $object->id,
                    'incarnation' => $object->incarnation,
                    'controller' => $seat,
                    'effects' => [$way === 'scavenge' ? ['type' => 'scavenge', 'amount' => max(0, (int) ($card->card['power'] ?? 0))] : ['type' => 'embalm', 'way' => $way]],
                    'kinds' => [],
                    'label' => "{$card->name}'s {$way}",
                ], [], 'is activated', "{$way} {$card->name}");
                $this->settle();

                return;
        }
        throw new GameException('Unknown paid action continuation.');
    }

    private function validatePaidUtility(array $after): void
    {
        $kind = $after['kind'];
        $keys = ['kind', 'seat', 'object', 'incarnation'];
        if (in_array($kind, ['bloodrush', 'ninjutsu', 'embalm'], true)) {
            $keys[] = match ($kind) {
                'bloodrush' => 'attacker', 'ninjutsu' => 'returned', 'embalm' => 'way'
            };
        }
        $object = $this->objects[$after['object']];
        $zone = in_array($kind, ['unearth', 'regrow', 'embalm'], true) ? GameObject::GRAVEYARD : GameObject::HAND;
        if (array_keys($after) !== $keys || $object->zone !== $zone || $object->owner !== $after['seat']) {
            throw new GameException('Invalid saved paid utility action.');
        }
        $card = $object->printed();
        if (match ($kind) {
            'cycle' => $card->cycling === null,
            'unearth' => $card->unearth === null,
            'foretell' => ! isset($card->altCosts['foretell']),
            'embalm' => ! in_array($after['way'], ['embalm', 'eternalize', 'scavenge'], true) || ! isset($card->altCosts[$after['way']]),
            default => ! isset($card->altCosts[$kind]),
        }) {
            throw new GameException('Invalid saved paid utility ability.');
        }
        if (in_array($kind, ['bloodrush', 'ninjutsu'], true)) {
            $target = $kind === 'bloodrush' ? $after['attacker'] : $after['returned'];
            $creature = is_string($target) ? $this->targetObject($target) : null;
            if ($creature === null || $creature->zone !== GameObject::BATTLEFIELD || $creature->controller !== $after['seat']
                || ! $this->isCreature($creature) || ! isset($this->attackers[$creature->id])
                || ($kind === 'ninjutsu' && ! in_array($creature, $this->unblockedAttackers($after['seat']), true))) {
                throw new GameException('Stale saved paid attacking-creature action.');
            }
        }
    }

    private function validatePaidTrigger(array $after): void
    {
        $trigger = $after['trigger'] ?? null;
        $source = is_int($after['object'] ?? null) ? ($this->objects[$after['object']] ?? null) : null;
        if (array_keys($after) !== ['kind', 'seat', 'object', 'incarnation', 'trigger', 'targets', 'wardDiscard']
            || ! in_array($after['seat'] ?? null, [0, 1], true) || $source === null
            || ! is_int($after['incarnation'] ?? null) || $after['incarnation'] < 0 || $after['incarnation'] > $source->incarnation
            || ! is_array($trigger) || ($trigger['source'] ?? null) !== $after['object'] || ($trigger['incarnation'] ?? null) !== $after['incarnation']
            || ($trigger['controller'] ?? null) !== $after['seat'] || ! is_array($trigger['effects'] ?? null) || count($trigger['effects']) > 256
            || ! is_array($trigger['kinds'] ?? null) || ! is_string($trigger['label'] ?? null)
            || ! is_array($after['targets'] ?? null) || ! array_is_list($after['targets']) || count($after['targets']) > 256
            || array_filter($after['targets'], fn ($target) => ! is_string($target)) !== []
            || ! is_int($after['wardDiscard'] ?? null) || $after['wardDiscard'] < 0 || $after['wardDiscard'] > count($this->players[$after['seat']]->hand)) {
            throw new GameException('Invalid saved paid ward-trigger continuation.');
        }
        $this->checkTargets($trigger['kinds'], $after['targets'], $after['seat'], $trigger['label'], $source, $trigger['effects']);
    }
}
