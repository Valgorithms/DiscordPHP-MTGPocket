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

use MTGPocket\Cards\BasicLands;
use MTGPocket\Game\CardDefinition;
use MTGPocket\Game\TextParser;

/**
 * Reading cards: types, stats, keywords, mana abilities and spell effects.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\TextParser
 */
final class CardTextTest extends GameTestCase
{
    public function testTypesAndStats(): void
    {
        $card = new CardDefinition(self::card('Isamaru, Hound of Konda'));
        $this->assertTrue($card->isLegendary());
        $this->assertTrue($card->isCreature());
        $this->assertSame(['Dog'], $card->subtypes);
        $this->assertSame([2, 2], [$card->power, $card->toughness]);

        $this->assertSame([['Legendary', 'Snow'], ['Artifact', 'Creature'], ['Golem']], CardDefinition::splitTypeLine('Legendary Snow Artifact Creature — Golem'));

        $tarmogoyf = new CardDefinition(['name' => 'Tarmogoyf', 'type' => 'Creature — Lhurgoyf', 'manaCost' => '{1}{G}', 'power' => '*', 'toughness' => '1+*']);
        $this->assertSame([0, 1], [$tarmogoyf->power, $tarmogoyf->toughness]);
    }

    public function testBasicLandsAndManaAbilities(): void
    {
        $forest = new CardDefinition(BasicLands::card('basic:Forest'));
        $this->assertTrue($forest->isLand());
        $this->assertSame(['count' => 1, 'colors' => ['G']], $forest->manaAbility);

        $gate = new CardDefinition(self::card('Selesnya Guildgate'));
        $this->assertTrue($gate->entersTapped);
        $this->assertSame(['count' => 1, 'colors' => ['G', 'W']], $gate->manaAbility);
        $this->assertSame([], $gate->unsupported);

        $ring = new CardDefinition(['name' => 'Sol Ring', 'type' => 'Artifact', 'manaCost' => '{1}', 'text' => '{T}: Add {C}{C}.']);
        $this->assertSame(['count' => 2, 'colors' => ['C']], $ring->manaAbility);

        $tri = new CardDefinition(['name' => 'Jungle Shrine', 'type' => 'Land', 'text' => "Jungle Shrine enters tapped.\n{T}: Add {R}, {G}, or {W}."]);
        $this->assertSame(['R', 'G', 'W'], $tri->manaAbility['colors']);
        $this->assertTrue($tri->entersTapped);

        $any = new CardDefinition(['name' => 'Birds of Paradise', 'type' => 'Creature — Bird', 'manaCost' => '{G}', 'power' => '0', 'toughness' => '1', 'text' => "Flying\n{T}: Add one mana of any color."]);
        $this->assertSame(5, count($any->manaAbility['colors']));
        $this->assertSame(['flying'], $any->keywords);
    }

    public function testKeywordsIgnoreReminderTextAndReportTheRest(): void
    {
        $rats = new CardDefinition(self::card('Typhoid Rats'));
        $this->assertSame(['deathtouch'], $rats->keywords);
        $this->assertSame([], $rats->unsupported);

        $knight = new CardDefinition(self::card('White Knight'));
        $this->assertSame(['first strike'], $knight->keywords);
        $this->assertSame(['Protection from black'], $knight->unsupported);

        $nighthawk = new CardDefinition(self::card('Vampire Nighthawk'));
        $this->assertSame(['flying', 'deathtouch', 'lifelink'], $nighthawk->keywords);
    }

    public function testSpellEffects(): void
    {
        $this->assertSame([['type' => 'damage', 'amount' => 3, 'target' => 'any']], (new CardDefinition(self::card('Lightning Bolt')))->effects);
        $this->assertSame([['type' => 'damage', 'amount' => 'X', 'target' => 'any']], (new CardDefinition(self::card('Blaze')))->effects);
        $this->assertSame([['type' => 'draw', 'amount' => 2]], (new CardDefinition(self::card('Divination')))->effects);
        $this->assertSame(['creature'], (new CardDefinition(self::card('Murder')))->targetKinds());

        $cases = [
            'Draw a card' => ['type' => 'draw', 'amount' => 1],
            'You gain 4 life' => ['type' => 'gain_life', 'amount' => 4],
            'Target opponent loses three life' => ['type' => 'lose_life', 'amount' => 3, 'target' => 'opponent'],
            'Each opponent loses 1 life' => ['type' => 'lose_life', 'amount' => 1, 'each' => 'opponent'],
            'Destroy target nonland permanent' => ['type' => 'destroy', 'target' => 'nonland_permanent'],
            'Exile target creature an opponent controls' => ['type' => 'exile', 'target' => 'creature_opponent'],
            'Counter target noncreature spell' => ['type' => 'counter', 'target' => 'noncreature_spell'],
            'Target creature gets -2/-2 until end of turn' => ['type' => 'pump', 'power' => -2, 'toughness' => -2, 'keywords' => [], 'target' => 'creature'],
            'Target creature gets +1/+0 and gains first strike until end of turn' => ['type' => 'pump', 'power' => 1, 'toughness' => 0, 'keywords' => ['first strike'], 'target' => 'creature'],
            'Target creature you control gains hexproof and indestructible until end of turn' => ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => ['hexproof', 'indestructible'], 'target' => 'creature_you_control'],
            'CARDNAME deals 2 damage to target creature or planeswalker' => ['type' => 'damage', 'amount' => 2, 'target' => 'creature_or_planeswalker'],
            'CARDNAME deals 1 damage to each creature' => ['type' => 'damage', 'amount' => 1, 'each' => 'creature'],
        ];
        foreach ($cases as $sentence => $effect) {
            $this->assertSame($effect, TextParser::effect($sentence), $sentence);
        }
        $this->assertNull(TextParser::effect('Destroy target player'));
        $this->assertNull(TextParser::effect('Proliferate'));

        $charm = new CardDefinition(['name' => 'Twin Bolt', 'type' => 'Instant', 'manaCost' => '{1}{R}', 'text' => 'Twin Bolt deals 2 damage to target creature. You gain 2 life. Proliferate.']);
        $this->assertSame(['creature'], $charm->targetKinds());
        $this->assertCount(2, $charm->effects);
        $this->assertSame(['Proliferate.'], $charm->unsupported);
    }

    public function testAuras(): void
    {
        $aura = new CardDefinition(self::card('Rancor Lite'));
        $this->assertTrue($aura->isAura());
        $this->assertSame(['enchant' => 'creature', 'power' => 2, 'toughness' => 0, 'keywords' => ['trample']], $aura->aura);
        $this->assertSame(['creature'], $aura->targetKinds());

        $pacifism = new CardDefinition(['name' => 'Pacifism', 'type' => 'Enchantment — Aura', 'manaCost' => '{1}{W}', 'text' => "Enchant creature\nEnchanted creature can't attack or block."]);
        $this->assertSame([], $pacifism->unsupported);
        $this->assertSame(["can't attack", "can't block"], $pacifism->aura['keywords']);
    }

    public function testNewTemplatingThisCreature(): void
    {
        $card = new CardDefinition(['name' => 'Sure Strike', 'type' => 'Instant', 'manaCost' => '{1}{R}', 'text' => 'This spell deals 3 damage to any target.']);
        $this->assertSame([['type' => 'damage', 'amount' => 3, 'target' => 'any']], $card->effects);
    }

    public function testSymbolsThatAreNotManaArePaidAsGeneric(): void
    {
        // Mystery Booster 2 playtest card: {D} is a land drop.
        $card = new CardDefinition(['name' => 'Boulder Jockey', 'type' => 'Creature — Goblin', 'manaCost' => '{2}{R}{D}', 'manaValue' => 4.0, 'power' => '3', 'toughness' => '3']);
        $this->assertSame(4, $card->cost->manaValue());
        $this->assertSame(['Mana cost {2}{R}{D} (paid as generic mana)'], $card->unsupported);

        $equipment = new CardDefinition(['name' => 'Odd Blade', 'type' => 'Artifact — Equipment', 'manaCost' => '{1}', 'text' => "Equipped creature gets +1/+0.
Equip {E}"]);
        $this->assertSame([], $equipment->activated);
        $this->assertSame(['Equip {E}'], $equipment->unsupported);
    }
}
