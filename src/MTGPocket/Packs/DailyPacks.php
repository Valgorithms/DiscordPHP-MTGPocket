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

namespace MTGPocket\Packs;

use MTGPocket\Cards\CardPool;
use MTGPocket\Models\Inventory;
use MTGPocket\Models\Player;
use MTGPocket\Repository\CardPoolRepository;
use MTGPocket\Repository\InventoryRepository;
use MTGPocket\Repository\PlayerRepository;
use Random\Randomizer;

/**
 * One free pack a day per player. The day turns over at midnight UTC, so
 * every player's next pack is ready at the same moment.
 *
 * A player picks the set and color of their pack, or leaves either to
 * chance. Any imported set can be picked, in any color that has a rare or
 * mythic rare to fill the rare slot.
 *
 * @since 0.2.0
 */
class DailyPacks
{
    /**
     * The current Unix time.
     *
     * @var \Closure(): int
     */
    protected \Closure $clock;

    protected Randomizer $random;

    /**
     * @param PlayerRepository      $players
     * @param InventoryRepository   $inventories
     * @param CardPoolRepository    $pools
     * @param PackGenerator         $generator
     * @param (\Closure(): int)|null $clock  Defaults to {@see time()}.
     * @param Randomizer|null       $random For picking a set and color left to chance.
     */
    public function __construct(
        protected PlayerRepository $players,
        protected InventoryRepository $inventories,
        protected CardPoolRepository $pools,
        protected PackGenerator $generator,
        ?\Closure $clock = null,
        ?Randomizer $random = null,
    ) {
        $this->clock = $clock ?? time(...);
        $this->random = $random ?? new Randomizer();
    }

    /**
     * When a player's next free pack is ready.
     *
     * @param Player|null $player Null for someone who has never played.
     *
     * @return int|null Unix time, or null when it is ready now.
     */
    public function nextPackAt(?Player $player): ?int
    {
        if ($player?->lastDailyPackAt === null) {
            return null;
        }
        $next = self::nextDay($player->lastDailyPackAt);

        return $next > ($this->clock)() ? $next : null;
    }

    /**
     * The packs that can be opened: the colors of each imported set that
     * have a rare slot, oldest set first.
     *
     * @return array<string, array{name: string, colors: string[]}> By set code.
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->pools->all() as $setCode => $pool) {
            $colors = array_values(array_filter($pool->colors(), fn (string $color) => $pool->hasRareSlot($color)));
            if ($colors !== []) {
                $choices[$setCode] = ['name' => $pool->setName, 'colors' => $colors];
            }
        }

        return $choices;
    }

    /**
     * Opens a player's free pack for today and adds its cards to their
     * collection.
     *
     * @param string      $playerId Discord user id.
     * @param string      $name     Their display name, kept on their profile.
     * @param string|null $set      A set code or name; null for any.
     * @param string|null $color    One of {@see CardPool::COLORS}; null for any.
     *
     * @throws DailyPackUnavailableException When today's pack is already opened.
     * @throws \InvalidArgumentException     When there is no such pack.
     *
     * @return OpenedPack
     */
    public function open(string $playerId, string $name = '', ?string $set = null, ?string $color = null): OpenedPack
    {
        $player = $this->players->findOrCreate($playerId, $name);
        if (($next = $this->nextPackAt($player)) !== null) {
            throw new DailyPackUnavailableException($next);
        }

        [$pool, $color] = $this->pick($set, $color);
        $pack = $this->generator->generate($pool, $color);

        // Claim the day under the player's lock, so two clicks at once open
        // one pack. The cards are added inside that lock and the claim is
        // written only after them: if adding the cards fails, the day is not
        // used up, so a pack is never claimed without its cards.
        $now = ($this->clock)();
        $new = [];
        $this->players->modify($playerId, function (Player $player) use ($now, $pack, $playerId, &$new): void {
            if ($player->lastDailyPackAt !== null && self::nextDay($player->lastDailyPackAt) > $now) {
                throw new DailyPackUnavailableException(self::nextDay($player->lastDailyPackAt));
            }

            $this->inventories->modify($playerId, function (Inventory $inventory) use ($pack, &$new): void {
                foreach ($pack->counts() as $uuid => $count) {
                    if ($inventory->cards->get($uuid) === 0) {
                        $new[$uuid] = true;
                    }
                    $inventory->cards->add($uuid, $count);
                }
            });
            $player->lastDailyPackAt = $now;
        });

        return new OpenedPack($pack, $new, self::nextDay($now));
    }

    /**
     * Midnight UTC after a moment.
     *
     * @param int $time Unix time.
     *
     * @return int
     */
    public static function nextDay(int $time): int
    {
        return (intdiv($time, 86400) + 1) * 86400;
    }

    /**
     * Resolves the set and color asked for, choosing at random what was left
     * open. The shop picks the packs it sells the same way.
     *
     * @param string|null $set
     * @param string|null $color
     *
     * @throws \InvalidArgumentException
     *
     * @return array{0: CardPool, 1: string}
     */
    public function pick(?string $set, ?string $color): array
    {
        $choices = $this->choices();
        if ($choices === []) {
            throw new \InvalidArgumentException('No packs are available yet: no card pools have been imported.');
        }

        if ($color !== null && $color !== '') {
            $color = strtoupper(trim($color));
            if (! in_array($color, CardPool::COLORS, true)) {
                throw new \InvalidArgumentException("There is no color {$color}. Pick one of ".implode(', ', CardPool::COLORS).'.');
            }
            $choices = array_filter($choices, fn (array $choice) => in_array($color, $choice['colors'], true));
        }

        if ($set !== null && trim($set) !== '') {
            $code = $this->setCode($set);
            if ($code === null) {
                throw new \InvalidArgumentException("There are no packs of {$set}.");
            }
            if (! isset($choices[$code])) {
                $setName = $this->pools->find($code)?->setName ?: $code;

                throw new \InvalidArgumentException($color === null || $color === ''
                    ? "{$setName} has no packs: it has no rare or mythic rare cards."
                    : "{$setName} has no {$color} packs: it has no rare or mythic rare cards of that color.");
            }
            $choices = [$code => $choices[$code]];
        }

        if ($choices === []) {
            throw new \InvalidArgumentException("No set has {$color} packs.");
        }

        $code = $this->random->pickArrayKeys($choices, 1)[0];
        $colors = $choices[$code]['colors'];
        $color = ($color !== null && $color !== '') ? $color : $colors[$this->random->getInt(0, count($colors) - 1)];

        return [$this->pools->find((string) $code), $color];
    }

    /**
     * An imported set's code from its code or name.
     *
     * @param string $set
     *
     * @return string|null
     */
    protected function setCode(string $set): ?string
    {
        $set = trim($set);
        if (preg_match('/^[A-Za-z0-9]{2,8}$/', $set) && ($pool = $this->pools->find($set))) {
            return $pool->setCode;
        }
        foreach ($this->pools->all() as $code => $pool) {
            if (strcasecmp($pool->setName, $set) === 0) {
                return $code;
            }
        }

        return null;
    }
}
