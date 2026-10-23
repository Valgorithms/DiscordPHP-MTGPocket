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

namespace MTGPocket\Tests\Game;

use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\Game;
use MTGPocket\Game\GameException;
use MTGPocket\Game\GameObject;

/** @covers \MTGPocket\Game\Game @covers \MTGPocket\Game\TextParser */
final class MassRemovalSelectorTest extends GameTestCase
{
    private const array MORE = [
        'Consume the Meek' => ['manaCost' => '{3}{B}{B}', 'type' => 'Instant', 'text' => "Destroy each creature with mana value 3 or less. They can't be regenerated.", 'colors' => ['B']],
        'Jokulhaups' => ['manaCost' => '{4}{R}{R}', 'type' => 'Sorcery', 'text' => "Destroy all artifacts, creatures, and lands. They can't be regenerated.", 'colors' => ['R']],
        'Obliterate' => ['manaCost' => '{6}{R}{R}', 'type' => 'Sorcery', 'text' => "This spell can't be countered.\nDestroy all artifacts, creatures, and lands. They can't be regenerated.", 'colors' => ['R']],
        'Regenerable Fixture' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => 'Destroy all artifacts, creatures, and lands.', 'colors' => ['R']],
        'Exile Fixture' => ['manaCost' => '{W}', 'type' => 'Sorcery', 'text' => 'Exile each creature with mana value 3 or less.', 'colors' => ['W']],
        'Indestructible Bear' => ['manaCost' => '{1}{G}', 'type' => 'Creature - Bear', 'power' => '2', 'toughness' => '2', 'text' => 'Indestructible', 'colors' => ['G']],
        'Darksteel Ingot' => ['manaCost' => '{3}', 'type' => 'Artifact', 'text' => "Indestructible\n{T}: Add one mana of any color.", 'colors' => []],
        'Glorious Anthem' => ['manaCost' => '{1}{W}{W}', 'type' => 'Enchantment', 'text' => 'Creatures you control get +1/+1.', 'colors' => ['W']],
    ];

    private static function more(string $name): array
    {
        return ['uuid' => 'mass-removal-'.$name, 'name' => $name, 'rarity' => 'rare'] + self::MORE[$name];
    }

    private function put(int $seat, string $name, string $zone = GameObject::HAND): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    private function reload(): Game
    {
        return $this->game = Game::fromArray(json_decode(json_encode($this->game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSelectorsAndFollowingRegenerationProhibitionAreReadTogether(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $this->assertSame([], (new CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->assertSame([['type' => 'destroy', 'all' => 'all creatures with mana value 3 or less', 'noRegen' => true]], (new CardDefinition(self::more('Consume the Meek')))->effects);
        $this->assertSame([['type' => 'destroy', 'all' => 'all artifacts, creatures, and lands', 'noRegen' => true]], (new CardDefinition(self::more('Jokulhaups')))->effects);
    }

    public function testConsumeTheMeekUsesManaValueForBothPlayersAndBypassesRegenerationAfterSave(): void
    {
        $game = $this->newGame();
        $lands = $this->lands(0, 'Swamp', 5);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $elf = $this->battlefield(1, 'Llanowar Elves');
        $ornithopter = $this->battlefield(1, 'Ornithopter');
        $pyromancer = $this->battlefield(0, 'Prodigal Pyromancer');
        $giant = $this->battlefield(1, 'Hill Giant');
        $ingot = $this->put(0, 'Darksteel Ingot', GameObject::BATTLEFIELD);
        $game->objects[$bears]->shields = 1;
        $game->objects[$ornithopter]->shields = 1;
        $game->cast(0, $this->put(0, 'Consume the Meek'));
        $this->reload();
        $this->resolve();
        foreach ([$bears, $elf, $ornithopter, $pyromancer] as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        foreach ([$giant, $ingot, ...$lands] as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
    }

    public function testJokulhaupsSelectsAUnionOnceAndLeavesOtherTypesAndIndestructiblePermanents(): void
    {
        $game = $this->newGame();
        $lands = $this->lands(0, 'Mountain', 6);
        $lands[] = $this->battlefield(1, 'Forest');
        $bear = $this->battlefield(1, 'Grizzly Bears');
        $artifact = $this->battlefield(0, 'Ornithopter');
        $equipment = $this->battlefield(1, 'Bonesplitter');
        $ingot = $this->put(0, 'Darksteel Ingot', GameObject::BATTLEFIELD);
        $anthem = $this->put(0, 'Glorious Anthem', GameObject::BATTLEFIELD);
        $indestructible = $this->put(1, 'Indestructible Bear', GameObject::BATTLEFIELD);
        $walker = $this->battlefield(1, 'Chandra, Pyrogenius');
        $game->objects[$bear]->shields = 1;
        $game->objects[$artifact]->shields = 2;
        $game->cast(0, $this->put(0, 'Jokulhaups'));
        $saved = $game->toArray();
        $this->resolve();
        $expected = $game->toArray();
        $this->game = Game::fromArray($saved);
        $this->resolve();
        $this->assertSame($expected, $this->game->toArray());
        foreach ([$bear, $artifact, $equipment, ...$lands] as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }
        foreach ([$ingot, $anthem, $walker, $indestructible] as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
    }

    public function testRegenerationStillWorksWhenTheProhibitionIsAbsent(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $bear = $this->battlefield(0, 'Grizzly Bears');
        $artifact = $this->battlefield(1, 'Ornithopter');
        $game->objects[$bear]->shields = 1;
        $game->objects[$artifact]->shields = 2;
        $game->cast(0, $this->put(0, 'Regenerable Fixture'));
        $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bear));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($artifact));
        $this->assertSame(0, $this->game->objects[$bear]->shields);
        $this->assertSame(1, $this->game->objects[$artifact]->shields, 'A creature artifact is destroyed once, not once per matching type.');
        $this->assertTrue($this->game->objects[$bear]->tapped);
    }

    public function testExileEachCreatureDoesNotUseDestructionOrRegeneration(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 1);
        $bear = $this->put(0, 'Indestructible Bear', GameObject::BATTLEFIELD);
        $elf = $this->battlefield(1, 'Llanowar Elves');
        $giant = $this->battlefield(1, 'Hill Giant');
        $game->objects[$elf]->shields = 1;
        $game->cast(0, $this->put(0, 'Exile Fixture'));
        $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($bear));
        $this->assertSame(GameObject::EXILE, $this->zone($elf));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($giant));
    }

    public function testObliterateRemainsUncounterableAndMassRemovalHasNoTargets(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 8);
        $this->lands(1, 'Island', 2);
        $spell = $this->put(0, 'Obliterate');
        $before = $game->toArray();
        try {
            $game->cast(0, $spell, 0, ['p:1']);
            $this->fail('Mass removal has no targets.');
        } catch (GameException) {
        }
        $this->assertSame($before, $game->toArray());
        $game->cast(0, $spell);
        $game->pass(0);
        $game->cast(1, $this->hand(1, 'Counterspell'), 0, ['s:'.$game->stack[0]['id']]);
        $this->resolve();
        $this->assertSame(GameObject::STACK, $this->zone($spell));
        $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame([], $this->game->battlefield);
    }

    public function testUnsupportedSelectorQualifiersAreNotDropped(): void
    {
        foreach ([
            "Destroy each creature with mana value equal to the number of age counters on this enchantment. They can't be regenerated.",
            'Destroy each creature chosen by an opponent.',
            'Destroy all artifacts, creatures, and lands with mana value 3 or less.',
            'Destroy each creature that shares a color with it.',
        ] as $text) {
            $this->assertNotSame([], (new CardDefinition(['name' => 'Unknown Fixture', 'type' => 'Sorcery', 'text' => $text]))->unsupported, $text);
        }
    }

    public function testOrdinaryMassRemovalCanBeCounteredAndRejectsUnpaidCasts(): void
    {
        $game = $this->newGame();
        $spell = $this->put(0, 'Jokulhaups');
        $before = $game->toArray();
        try {
            $game->cast(0, $spell);
            $this->fail('The mass removal must be paid for.');
        } catch (GameException) {
        }
        $this->assertSame($before, $game->toArray());
        $lands = $this->lands(0, 'Mountain', 6);
        $lands = [...$lands, ...$this->lands(1, 'Island', 2)];
        $bear = $this->battlefield(1, 'Grizzly Bears');
        $game->cast(0, $spell);
        $game->pass(0);
        $game->cast(1, $this->hand(1, 'Counterspell'), 0, ['s:'.$game->stack[0]['id']]);
        $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($spell));
        $this->assertSame([], $this->game->stack);
        foreach ([$bear, ...$lands] as $id) {
            $this->assertSame(GameObject::BATTLEFIELD, $this->zone($id));
        }
    }
}
