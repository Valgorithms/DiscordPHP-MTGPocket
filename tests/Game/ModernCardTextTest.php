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
use MTGPocket\Game\Step;

/**
 * Lines common among Modern-legal cards: persist, undying, exalted,
 * bushido, toxic, bloodthirst, landwalk, anthems, mill, Food, Clue and
 * Treasure, borrowing a creature, additional costs, Thoughtseize-style
 * discard, returning cards from the graveyard.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class ModernCardTextTest extends GameTestCase
{
    private const array MORE = [
        'Kitchen Finks' => ['manaCost' => '{1}{G/W}{G/W}', 'type' => 'Creature — Ouphe', 'power' => '3', 'toughness' => '2', 'text' => "When this creature enters, you gain 2 life.\nPersist (When this creature dies, if it had no -1/-1 counters on it, return it to the battlefield under its owner's control with a -1/-1 counter on it.)", 'colors' => ['G', 'W']],
        'Strangleroot Geist' => ['manaCost' => '{G}{G}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '1', 'text' => "Haste\nUndying", 'colors' => ['G']],
        'Akrasan Squire' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '1', 'text' => 'Exalted', 'colors' => ['W']],
        'Devoted Retainer' => ['manaCost' => '{W}', 'type' => 'Creature — Human Samurai', 'power' => '1', 'toughness' => '1', 'text' => 'Bushido 1', 'colors' => ['W']],
        'Toxic Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Phyrexian Rat', 'power' => '2', 'toughness' => '2', 'text' => 'Toxic 2', 'colors' => ['B']],
        'Stormblood Berserker' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Berserker', 'power' => '1', 'toughness' => '1', 'text' => "Bloodthirst 2\nMenace", 'colors' => ['R']],
        'Glorious Anthem' => ['manaCost' => '{1}{W}{W}', 'type' => 'Enchantment', 'text' => 'Creatures you control get +1/+1.', 'colors' => ['W']],
        'Benalish Marshal' => ['manaCost' => '{W}{W}{W}', 'type' => 'Creature — Human Knight', 'power' => '3', 'toughness' => '3', 'text' => 'Other creatures you control get +1/+1.', 'colors' => ['W']],
        'Thought Scour' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => "Target player mills two cards.\nDraw a card.", 'colors' => ['U']],
        'Bake into a Pie' => ['manaCost' => '{2}{B}{B}', 'type' => 'Instant', 'text' => 'Destroy target creature. Create a Food token.', 'colors' => ['B']],
        'Strike It Rich' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => "Create a Treasure token.\nFlashback {2}{R}", 'colors' => ['R']],
        'Thraben Inspector' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '2', 'text' => 'When this creature enters, investigate.', 'colors' => ['W']],
        'Act of Treason' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Gain control of target creature until end of turn. Untap that creature. It gains haste until end of turn.', 'colors' => ['R']],
        'Village Rites' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "As an additional cost to cast this spell, sacrifice a creature.\nDraw two cards.", 'colors' => ['B']],
        'Tormenting Voice' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "As an additional cost to cast this spell, discard a card.\nDraw two cards.", 'colors' => ['R']],
        'Thoughtseize' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Target player reveals their hand. You choose a nonland card from it. That player discards that card. You lose 2 life.', 'colors' => ['B']],
        'Unburial Rites' => ['manaCost' => '{4}{B}', 'type' => 'Sorcery', 'text' => "Return target creature card from your graveyard to the battlefield.\nFlashback {3}{W}", 'colors' => ['B']],
        'Raise Dead' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Return target creature card from your graveyard to your hand.', 'colors' => ['B']],
        'Divine Verdict' => ['manaCost' => '{3}{W}', 'type' => 'Instant', 'text' => 'Destroy target attacking or blocking creature.', 'colors' => ['W']],
        'Bog Wraith' => ['manaCost' => '{3}{B}', 'type' => 'Creature — Wraith', 'power' => '3', 'toughness' => '3', 'text' => 'Swampwalk (This creature can\'t be blocked as long as defending player controls a Swamp.)', 'colors' => ['B']],
        'Ghostly Flicker Lite' => ['manaCost' => '{2}{U}', 'type' => 'Instant', 'text' => "Convoke.\nDraw two cards.", 'colors' => ['U']],
        'Impulse' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'Look at the top four cards of your library. Put one of them into your hand and the rest on the bottom of your library in any order.', 'colors' => ['U']],
        'Commune with Nature' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Look at the top five cards of your library. You may reveal a creature card from among them and put it into your hand. Put the rest on the bottom of your library in any order.', 'colors' => ['G']],
        'Mode Sprite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Faerie', 'power' => '2', 'toughness' => '2', 'text' => "When this creature enters, choose one —\n• Draw a card.\n• Destroy target artifact.", 'colors' => ['W']],
        'Steppe Lynx Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Cat', 'power' => '0', 'toughness' => '1', 'text' => 'Landfall — Whenever a land you control enters, this creature gets +2/+2 until end of turn.', 'colors' => ['W']],
        'Sword Lite' => ['manaCost' => '{2}', 'type' => 'Artifact — Equipment', 'text' => "When this Equipment enters, attach it to target creature you control.\nEquipped creature gets +2/+0.\nEquip {3}", 'colors' => []],
        'Amrou Kithkin' => ['manaCost' => '{W}{W}', 'type' => 'Creature — Kithkin', 'power' => '1', 'toughness' => '1', 'text' => "This creature can't be blocked by creatures with power 3 or greater.", 'colors' => ['W']],
        'Wall of Air Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Wall', 'power' => '0', 'toughness' => '5', 'text' => "Defender\nThis creature can block only creatures with flying.", 'colors' => ['U']],
        'Frogmite' => ['manaCost' => '{4}', 'type' => 'Artifact Creature — Frog', 'power' => '2', 'toughness' => '2', 'text' => 'Affinity for artifacts (This spell costs {1} less to cast for each artifact you control.)', 'colors' => []],
        'Hellspark Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Elemental', 'power' => '3', 'toughness' => '1', 'text' => "Trample\nUnearth {R} ({R}: Return this card from your graveyard to the battlefield. It gains haste. Exile it at the beginning of the next end step or if it would leave the battlefield. Unearth only as a sorcery.)", 'colors' => ['R']],
        'Staggershock' => ['manaCost' => '{2}{R}', 'type' => 'Instant', 'text' => "Staggershock deals 2 damage to any target.\nRebound (If you cast this spell from your hand, exile it as it resolves. At the beginning of your next upkeep, you may cast this card from exile without paying its mana cost.)", 'colors' => ['R']],
        'Longtusk Cub' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Cat', 'power' => '1', 'toughness' => '2', 'text' => "Whenever this creature deals combat damage to a player, you get {E}{E} (two energy counters).\nPay {E}{E}: Put a +1/+1 counter on this creature.", 'colors' => ['G']],
        'Thriving Rhino' => ['manaCost' => '{4}{G}', 'type' => 'Creature — Rhino', 'power' => '3', 'toughness' => '3', 'text' => "When this creature enters, you get {E}{E}.\nPay {E}{E}: This creature gets +2/+2 until end of turn.", 'colors' => ['G']],
        'Brightfield Mustang' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Horse Mount', 'power' => '3', 'toughness' => '3', 'text' => "Whenever this creature attacks while saddled, untap it and put a +1/+1 counter on it.\nSaddle 1 (Tap any number of other creatures you control with total power 1 or more: This Mount becomes saddled until end of turn. Saddle only as a sorcery.)", 'colors' => ['W']],
        'Brightfield Glider' => ['manaCost' => '{W}', 'type' => 'Creature — Possum Mount', 'power' => '1', 'toughness' => '1', 'text' => "Vigilance\nWhenever this creature attacks while saddled, it gets +1/+2 and gains flying until end of turn.\nSaddle 3 (Tap any number of other creatures you control with total power 3 or more: This Mount becomes saddled until end of turn. Saddle only as a sorcery.)", 'colors' => ['W']],
        'Grisly Salvage' => ['manaCost' => '{B}{G}', 'type' => 'Instant', 'text' => 'Reveal the top five cards of your library. You may put a creature or land card from among them into your hand. Put the rest into your graveyard.', 'colors' => ['B', 'G']],
        'Sleight of Hand' => ['manaCost' => '{U}', 'type' => 'Sorcery', 'text' => 'Look at the top two cards of your library. Put one of them into your hand and the other on the bottom of your library.', 'colors' => ['U']],
        "Dáin's Company Lite" => ['manaCost' => '{R}{W}', 'type' => 'Creature — Dwarf Warrior', 'power' => '2', 'toughness' => '2', 'text' => 'When this creature enters, look at the top four cards of your library. You may reveal a Dwarf or Equipment card from among them and put it into your hand. Put the rest on the bottom of your library in a random order.', 'colors' => ['R', 'W']],
        'Glimpse Lite' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => "Look at the top three cards of your library, then put them back in any order.\nDraw a card.", 'colors' => ['U']],
        'Hopeful Eidolon' => ['manaCost' => '{W}', 'type' => 'Enchantment Creature — Spirit', 'power' => '1', 'toughness' => '1', 'text' => "Bestow {3}{W} (If you cast this card for its bestow cost, it's an Aura spell with enchant creature. It becomes a creature again if it's not attached.)\nLifelink\nEnchanted creature gets +1/+1 and has lifelink.", 'colors' => ['W']],
        'Patchwork Banner' => ['manaCost' => '{3}', 'type' => 'Artifact', 'text' => "As this artifact enters, choose a creature type.\nCreatures you control of the chosen type get +1/+1.\n{T}: Add one mana of any color.", 'colors' => []],
        'Room of Refuge Lite' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped. As it enters, choose a color.\n{T}: Add one mana of the chosen color.", 'colors' => []],
        'Scuttling Death' => ['manaCost' => '{4}{B}', 'type' => 'Creature — Spirit', 'power' => '4', 'toughness' => '2', 'text' => "Sacrifice this creature: Target creature gets -1/-1 until end of turn.\nSoulshift 4 (When this creature dies, you may return target Spirit card with mana value 4 or less from your graveyard to your hand.)", 'colors' => ['B']],
        'Wicked Akuba Lite' => ['manaCost' => '{B}{B}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '2', 'text' => '', 'colors' => ['B']],
        'Curse of the Pierced Heart' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment — Aura Curse', 'text' => "Enchant player\nAt the beginning of enchanted player's upkeep, Curse of the Pierced Heart deals 1 damage to that player or a planeswalker that player controls.", 'colors' => ['R']],
        "Curse of Death's Hold" => ['manaCost' => '{3}{B}{B}', 'type' => 'Enchantment — Aura Curse', 'text' => "Enchant player\nCreatures enchanted player controls get -1/-1.", 'colors' => ['B']],
        'Druid Class Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment — Class', 'text' => "(Gain the next level as a sorcery to add its ability.)\nWhenever a land you control enters, you gain 1 life.\n{2}{G}: Level 2\nCreatures you control get +1/+1.\n{4}{G}: Level 3\nWhen this Class becomes level 3, draw two cards.", 'colors' => ['G']],
        'Burnout Bashtronaut' => ['manaCost' => '{R}', 'type' => 'Creature — Goblin Warrior', 'power' => '1', 'toughness' => '1', 'text' => "Menace\nStart your engines! (If you have no speed, it starts at 1. It increases once on each of your turns when an opponent loses life. Max speed is 4.)\n{2}: This creature gets +1/+0 until end of turn.\nMax speed — This creature has double strike.", 'colors' => ['R']],
        'Speedway Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Pilot', 'power' => '2', 'toughness' => '2', 'text' => "Start your engines!\nMax speed — This creature gets +1/+1 and has menace.\nMax speed — {T}: Target creature gains haste until end of turn.", 'colors' => ['R']],
        'Outpace Oblivion' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'text' => "Start your engines!\nWhen this enchantment enters, it deals 5 damage to up to one target creature or planeswalker.", 'colors' => ['R']],
        'Frost Breath' => ['manaCost' => '{2}{U}', 'type' => 'Instant', 'text' => "Tap up to two target creatures. Those creatures don't untap during their controller's next untap step.", 'colors' => ['U']],
        'Stun Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'Tap up to two target creatures. Put a stun counter on each of them. (If a permanent with a stun counter would become untapped, remove one from it instead.)', 'colors' => ['U']],
        'Arcbound Worker' => ['manaCost' => '{1}', 'type' => 'Artifact Creature — Construct', 'power' => '0', 'toughness' => '0', 'text' => "Modular 1 (This creature enters with a +1/+1 counter on it. When it dies, you may put its +1/+1 counters on target artifact creature.)", 'colors' => []],
        'Topan Freeblade' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'power' => '2', 'toughness' => '2', 'text' => "Vigilance\nRenown 1 (When this creature deals combat damage to a player, if it isn't renowned, put a +1/+1 counter on it and it becomes renowned.)", 'colors' => ['W']],
        'Cloudfin Raptor' => ['manaCost' => '{U}', 'type' => 'Creature — Bird Mutant', 'power' => '0', 'toughness' => '1', 'text' => "Flying\nEvolve (Whenever a creature you control enters, if that creature has greater power or toughness than this creature, put a +1/+1 counter on this creature.)", 'colors' => ['U']],
        'Glint-Sleeve Artisan' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Dwarf Artificer', 'power' => '2', 'toughness' => '2', 'text' => 'Fabricate 1 (When this creature enters, put a +1/+1 counter on it or create a 1/1 colorless Servo artifact creature token.)', 'colors' => ['W']],
        'Flayer Husk' => ['manaCost' => '{1}', 'type' => 'Artifact — Equipment', 'text' => "Living weapon (When this Equipment enters, create a 0/0 black Phyrexian Germ creature token, then attach this to it.)\nEquipped creature gets +1/+1.\nEquip {2}", 'colors' => []],
        'Syndic of Tithes' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Human Cleric', 'power' => '2', 'toughness' => '2', 'text' => 'Extort (Whenever you cast a spell, you may pay {W/B}. If you do, each opponent loses 1 life and you gain that much life.)', 'colors' => ['W']],
        'Reliquary Tower' => ['manaCost' => '', 'type' => 'Land', 'text' => "You have no maximum hand size.\n{T}: Add {C}.", 'colors' => []],
        'Explore Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'You may play an additional land on each of your turns.', 'colors' => ['G']],
        'Turn Duelist' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Warrior', 'power' => '2', 'toughness' => '1', 'text' => 'During your turn, this creature has first strike.', 'colors' => ['R']],
        "Ajani's Pridemate" => ['manaCost' => '{1}{W}', 'type' => 'Creature — Cat Soldier', 'power' => '2', 'toughness' => '2', 'text' => "Whenever you gain life, put a +1/+1 counter on Ajani's Pridemate.", 'colors' => ['W']],
        'Grim Return Lite' => ['manaCost' => '{2}{B}', 'type' => 'Sorcery', 'text' => 'Return up to two target creature cards from your graveyard to your hand.', 'colors' => ['B']],
        'Reverse Engineer' => ['manaCost' => '{3}{U}{U}', 'type' => 'Sorcery', 'text' => "Improvise (Your artifacts can help cast this spell. Each artifact you tap after you're done activating mana abilities pays for {1}.)\nDraw three cards.", 'colors' => ['U']],
        'Adapt Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Fish', 'power' => '2', 'toughness' => '2', 'text' => '{2}{G}: Adapt 2. (If this creature has no +1/+1 counters on it, put two +1/+1 counters on it.)', 'colors' => ['G']],
        'Monster Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Hydra', 'power' => '2', 'toughness' => '2', 'text' => "{1}{R}: Monstrosity 1. (If this creature isn't monstrous, put a +1/+1 counter on it and it becomes monstrous.)\nWhen this creature becomes monstrous, it deals 2 damage to each opponent.", 'colors' => ['R']],
        'Riot Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin', 'power' => '2', 'toughness' => '1', 'text' => 'Riot (This creature enters with your choice of a +1/+1 counter or haste.)', 'colors' => ['R']],
        'Vanishing Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human', 'power' => '3', 'toughness' => '3', 'text' => 'Vanishing 2 (This creature enters with two time counters on it. At the beginning of your upkeep, remove a time counter from it. When the last is removed, sacrifice it.)', 'colors' => ['R']],
        'Merfolk Branchwalker' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Merfolk Scout', 'power' => '2', 'toughness' => '1', 'text' => 'When this creature enters, it explores. (Reveal the top card of your library. Put that card into your hand if it\'s a land. Otherwise, put a +1/+1 counter on this creature, then put the card back or put it into your graveyard.)', 'colors' => ['G']],
        'Mentor Lite' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Human Soldier', 'power' => '3', 'toughness' => '2', 'text' => 'Mentor (Whenever this creature attacks, put a +1/+1 counter on target attacking creature with lesser power.)', 'colors' => ['W']],
        'Exploit Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Zombie', 'power' => '2', 'toughness' => '2', 'text' => "Exploit (When this creature enters, you may sacrifice a creature.)\nWhen this creature exploits a creature, draw two cards.", 'colors' => ['B']],
        'Ball Lightning' => ['manaCost' => '{R}{R}{R}', 'type' => 'Creature — Elemental', 'power' => '6', 'toughness' => '1', 'text' => "Trample\nHaste\nAt the beginning of the end step, sacrifice this creature.", 'colors' => ['R']],
        'Scrap Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => "As an additional cost to cast this spell, sacrifice an artifact or creature.\nDraw two cards.", 'colors' => ['B']],
        'Firebending Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Warrior', 'power' => '2', 'toughness' => '2', 'text' => 'Firebending 2 (Whenever this creature attacks, add {R}{R}. This mana lasts until end of combat.)', 'colors' => ['R']],
        'Banisher Lite' => ['manaCost' => '{1}{W}{W}', 'type' => 'Enchantment', 'text' => 'When this enchantment enters, exile target nonland permanent an opponent controls until this enchantment leaves the battlefield.', 'colors' => ['W']],
        'Mind Control' => ['manaCost' => '{3}{U}{U}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nYou control enchanted creature.", 'colors' => ['U']],
        'Leyline Lite' => ['manaCost' => '{2}{W}{W}', 'type' => 'Enchantment', 'text' => 'If this card is in your opening hand, you may begin the game with it on the battlefield.', 'colors' => ['W']],
        'Simic Initiate' => ['manaCost' => '{G}', 'type' => 'Creature — Human Mutant', 'power' => '0', 'toughness' => '0', 'text' => 'Graft 1 (This creature enters with a +1/+1 counter on it. Whenever another creature enters, you may move a +1/+1 counter from this creature onto it.)', 'colors' => ['G']],
        'Devour Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Dragon', 'power' => '2', 'toughness' => '2', 'text' => 'Devour 2 (As this creature enters, you may sacrifice any number of creatures. This creature enters with twice that many +1/+1 counters on it.)', 'colors' => ['R']],
        'Rakdos Cackler' => ['manaCost' => '{B/R}', 'type' => 'Creature — Devil', 'power' => '1', 'toughness' => '1', 'text' => "Unleash (You may have this creature enter with a +1/+1 counter on it. It can't block as long as it has a +1/+1 counter on it.)", 'colors' => ['B', 'R']],
        'Fog' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Prevent all combat damage that would be dealt this turn.', 'colors' => ['G']],
        'Naturalize Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Destroy target artifact or enchantment.', 'colors' => ['G']],
        'Oil Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => 'This artifact enters with three oil counters on it.', 'colors' => []],
        'Sudden Shock' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => "Split second (As long as this spell is on the stack, players can't cast spells or activate abilities that aren't mana abilities.)\nSudden Shock deals 2 damage to any target.", 'colors' => ['R']],
        'Hyena Umbra' => ['manaCost' => '{W}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nEnchanted creature gets +1/+1 and has first strike.\nUmbra armor (If enchanted creature would be destroyed, instead remove all damage from it and destroy this Aura.)", 'colors' => ['W']],
        'Day of Judgment' => ['manaCost' => '{2}{W}{W}', 'type' => 'Sorcery', 'text' => 'Destroy all creatures.', 'colors' => ['W']],
        'Electromancer Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin Wizard', 'power' => '2', 'toughness' => '2', 'text' => 'Instant and sorcery spells you cast cost {1} less to cast.', 'colors' => ['R']],
        'Treasure Cruise' => ['manaCost' => '{7}{U}', 'type' => 'Sorcery', 'text' => "Delve (Each card you exile from your graveyard while casting this spell pays for {1}.)\nDraw three cards.", 'colors' => ['U']],
        'Lava Coil' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => 'Lava Coil deals 4 damage to target creature. If that creature would die this turn, exile it instead.', 'colors' => ['R']],
        'Junk Lite' => ['manaCost' => '{1}', 'type' => 'Artifact', 'text' => "When this artifact is put into a graveyard from the battlefield, draw a card.\n{T}, Sacrifice this artifact: You gain 1 life.", 'colors' => []],
        'Lumen-Class Frigate' => ['manaCost' => '{1}{W}', 'type' => 'Artifact — Spacecraft', 'power' => '3', 'toughness' => '5', 'text' => "Station (Tap another creature you control: Put charge counters equal to its power on this Spacecraft. Station only as a sorcery. It's an artifact creature at 12+.)\n2+ | Other creatures you control get +1/+1.\n12+ | Flying, lifelink", 'colors' => ['W']],
        'Ornithopter' => ['manaCost' => '{0}', 'type' => 'Artifact Creature — Thopter', 'power' => '0', 'toughness' => '2', 'text' => 'Flying', 'colors' => []],
        'Mardu Scout' => ['manaCost' => '{R}{R}', 'type' => 'Creature — Goblin Scout', 'power' => '2', 'toughness' => '1', 'text' => "Dash {1}{R} (You may cast this spell for its dash cost. If you do, it gains haste, and it's returned from the battlefield to its owner's hand at the beginning of the next end step.)", 'colors' => ['R']],
        'Mulldrifter' => ['manaCost' => '{4}{U}', 'type' => 'Creature — Elemental', 'power' => '2', 'toughness' => '2', 'text' => "Flying\nWhen this creature enters, draw two cards.\nEvoke {2}{U} (You may cast this spell for its evoke cost. If you do, it's sacrificed when it enters.)", 'colors' => ['U']],
        'Warp Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Human Pilot', 'power' => '3', 'toughness' => '2', 'text' => 'Warp {R} (You can cast this card from your hand for its warp cost. Exile this creature at the beginning of the next end step, then you may cast it from exile on a later turn.)', 'colors' => ['R']],
        'Plot Lite' => ['manaCost' => '{3}{R}', 'type' => 'Sorcery', 'text' => "Plot Lite deals 3 damage to any target.\nPlot {1}{R} (You may pay {1}{R} and exile this card from your hand. Cast it as a sorcery on a later turn without paying its mana cost. Plot only as a sorcery.)", 'colors' => ['R']],
        'Mobilize Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Goblin Warrior', 'power' => '2', 'toughness' => '2', 'text' => 'Mobilize 2 (Whenever this creature attacks, create two tapped and attacking 1/1 red Warrior creature tokens. Sacrifice them at the beginning of the next end step.)', 'colors' => ['R']],
        'Soul Warden Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Cleric', 'power' => '1', 'toughness' => '1', 'text' => 'Whenever another creature you control enters, you gain 1 life.', 'colors' => ['W']],
        'Second Draw Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '1', 'text' => 'Whenever you draw your second card each turn, put a +1/+1 counter on this creature.', 'colors' => ['U']],
        'Draw Lite' => ['manaCost' => '{1}{U}', 'type' => 'Sorcery', 'text' => 'Draw two cards.', 'colors' => ['U']],
        'Goblin War Drums Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin', 'power' => '3', 'toughness' => '3', 'text' => "This creature can't be blocked by more than one creature.", 'colors' => ['R']],
        'Relentless Rats' => ['manaCost' => '{1}{B}{B}', 'type' => 'Creature — Rat', 'power' => '2', 'toughness' => '2', 'text' => 'A deck can have any number of cards named Relentless Rats.', 'colors' => ['B']],
        'Ninja Lite' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Human Ninja', 'power' => '2', 'toughness' => '2', 'text' => 'Ninjutsu {1}{U} ({1}{U}, Return an unblocked attacker you control to hand: Put this card onto the battlefield from your hand tapped and attacking.)', 'colors' => ['U']],
        'Murderous Compulsion' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => "Destroy target tapped creature.\nMadness {1}{B} (If you discard this card, discard it into exile. When you do, cast it for its madness cost or put it into your graveyard.)", 'colors' => ['B']],
        'Spree Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Spree (Choose one or more additional costs.)\n+ {1} — Spree Lite deals 2 damage to any target.\n+ {2} — Draw a card.", 'colors' => ['R']],
        'Flourishing Strike' => ['manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => "Choose one —\n• Flourishing Strike deals 5 damage to target creature with flying.\n• Target creature gets +3/+3 until end of turn.\nEntwine {2}{G} (Choose both if you pay the entwine cost.)", 'colors' => ['G']],
        'Boon-Bringer Valkyrie' => ['manaCost' => '{3}{W}{W}', 'type' => 'Creature — Angel Warrior', 'power' => '4', 'toughness' => '4', 'text' => "Backup 1 (When this creature enters, put a +1/+1 counter on target creature. If that's another creature, it gains the following abilities until end of turn.)\nFlying, first strike, lifelink", 'colors' => ['W']],
        'Bola Slinger' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Cat Soldier', 'power' => '2', 'toughness' => '3', 'text' => "Backup 1 (When this creature enters, put a +1/+1 counter on target creature. If that's another creature, it gains the following ability until end of turn.)\nWhenever this creature attacks, tap target artifact or creature an opponent controls.", 'colors' => ['W']],
        'Offspring Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Rabbit Soldier', 'power' => '2', 'toughness' => '2', 'text' => 'Offspring {2} (You may pay an additional {2} as you cast this spell. If you do, when this creature enters, create a 1/1 token copy of it.)', 'colors' => ['W']],
        'Rift Sower' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Elf Druid', 'power' => '1', 'toughness' => '3', 'text' => "{T}: Add one mana of any color.\nSuspend 2—{G} (Rather than cast this card from your hand, you may pay {G} and exile it with two time counters on it. At the beginning of your upkeep, remove a time counter. When the last is removed, you may cast it without paying its mana cost. It has haste.)", 'colors' => ['G']],
        'Blessed Ghoul' => ['manaCost' => '{W/B}', 'type' => 'Creature — Zombie Cleric', 'power' => '1', 'toughness' => '1', 'text' => "Lifelink\n{2}{W/B}: Return this card from your graveyard to your hand.", 'colors' => ['W', 'B']],
        'Terror' => ['manaCost' => '{1}{B}', 'type' => 'Instant', 'text' => "Destroy target nonartifact, nonblack creature. It can't be regenerated.", 'colors' => ['B']],
        'Pillage' => ['manaCost' => '{1}{R}{R}', 'type' => 'Sorcery', 'text' => "Destroy target artifact or land. It can't be regenerated.", 'colors' => ['R']],
        'Syndicate Messenger' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Bird', 'power' => '2', 'toughness' => '3', 'text' => "Flying\nAfterlife 1 (When this creature dies, create a 1/1 white and black Spirit creature token with flying.)", 'colors' => ['W']],
        'Annihilator Lite' => ['manaCost' => '{5}', 'type' => 'Creature — Eldrazi', 'power' => '4', 'toughness' => '4', 'text' => 'Annihilator 1 (Whenever this creature attacks, defending player sacrifices a permanent of their choice.)', 'colors' => []],
        'No More Lies' => ['manaCost' => '{W}{U}', 'type' => 'Instant', 'text' => "Counter target spell unless its controller pays {3}. If that spell is countered this way, exile it instead of putting it into its owner's graveyard.", 'colors' => ['W', 'U']],
        'Azorius Chancery' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped.\nWhen this land enters, return a land you control to its owner's hand.\n{T}: Add {W}{U}.", 'colors' => []],
        'Rumble Arena' => ['manaCost' => null, 'type' => 'Land', 'text' => "Vigilance\n{T}: Add {C}.\n{1}, {T}: Add one mana of any color.", 'colors' => []],
        'Prismatic Lens' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => "{1}, {T}: Add one mana of any color.\n{T}: Add {C}.", 'colors' => []],
        'Intimidation Tactics' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => "Target opponent reveals their hand. You choose an artifact or creature card from it. Exile that card.\nCycling {3} ({3}, Discard this card: Draw a card.)", 'colors' => ['B']],
        'Stress Dream Lite' => ['manaCost' => '{1}{U}', 'type' => 'Sorcery', 'text' => 'Look at the top two cards of your library. Put one of those cards into your hand and the other on the bottom of your library.', 'colors' => ['U']],
        'Leyline of Sanctity' => ['manaCost' => '{2}{W}{W}', 'type' => 'Enchantment', 'text' => "If this card is in your opening hand, you may begin the game with it on the battlefield.\nYou have hexproof. (You can't be the target of spells or abilities your opponents control.)", 'colors' => ['W']],
        'Retreat to Kazandu' => ['manaCost' => '{2}{G}', 'type' => 'Enchantment', 'text' => "Landfall — Whenever a land you control enters, choose one —\n• Put a +1/+1 counter on target creature.\n• You gain 2 life.", 'colors' => ['G']],
        'Etched Oracle' => ['manaCost' => '{4}', 'type' => 'Artifact Creature — Wizard', 'power' => '0', 'toughness' => '0', 'text' => 'Sunburst (This creature enters with a +1/+1 counter on it for each color of mana spent to cast it.)', 'colors' => []],
        'Reckless Impulse' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => 'Exile the top two cards of your library. Until the end of your next turn, you may play those cards.', 'colors' => ['R']],
        'Seer Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '2', 'text' => 'You may look at the top card of your library any time.', 'colors' => ['U']],
        'Echo Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Beast', 'power' => '3', 'toughness' => '3', 'text' => 'Echo {1}{G} (At the beginning of your upkeep, if this came under your control since the beginning of your last upkeep, sacrifice it unless you pay its echo cost.)', 'colors' => ['G']],
        'Flanking Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Knight', 'power' => '2', 'toughness' => '2', 'text' => 'Flanking (Whenever a creature without flanking blocks this creature, the blocking creature gets -1/-1 until end of turn.)', 'colors' => ['W']],
        'Escape Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie', 'power' => '2', 'toughness' => '1', 'text' => "Escape—{2}{B}, Exile three other cards from your graveyard.\nEscape Lite escapes with a +1/+1 counter on it.", 'colors' => ['B']],
        "Chemister's Insight" => ['manaCost' => '{3}{U}', 'type' => 'Instant', 'text' => "Draw two cards.\nJump-start (You may cast this card from your graveyard by discarding a card in addition to paying its other costs. Then exile this card.)", 'colors' => ['U']],
        'Retrace Lite' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "Retrace Lite deals 2 damage to any target.\nRetrace (You may cast this card from your graveyard by discarding a land card in addition to paying its other costs.)", 'colors' => ['R']],
        'Bargain Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => "Bargain (You may sacrifice an artifact, enchantment, or token as you cast this spell.)\nBargain Lite deals 2 damage to any target. If this spell was bargained, it deals 4 damage instead.", 'colors' => ['R']],
        'Skulking Ghost' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '1', 'text' => "Flying\nWhen Skulking Ghost becomes the target of a spell or ability, sacrifice it.", 'colors' => ['B']],
        'Grapeshot' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "Grapeshot deals 1 damage to any target.\nStorm (When you cast this spell, copy it for each spell cast before it this turn. You may choose new targets for the copies.)", 'colors' => ['R']],
        'Foretell Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => "Foretell Lite deals 3 damage to any target.\nForetell {R} (During your turn, you may pay {2} and exile this card from your hand face down. Cast it on a later turn for its foretell cost.)", 'colors' => ['R']],
        'Cumulative Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elemental', 'power' => '4', 'toughness' => '4', 'text' => 'Cumulative upkeep {1} (At the beginning of your upkeep, put an age counter on this permanent, then sacrifice it unless you pay its upkeep cost for each age counter on it.)', 'colors' => ['G']],
        'Thallid' => ['manaCost' => '{G}', 'type' => 'Creature — Fungus', 'power' => '1', 'toughness' => '1', 'text' => "At the beginning of your upkeep, put a spore counter on Thallid.\nRemove three spore counters from Thallid: Create a 1/1 green Saproling creature token.", 'colors' => ['G']],
        'Murder Lite' => ['manaCost' => '{1}{B}', 'type' => 'Instant', 'text' => 'Destroy target creature. Its controller loses 2 life.', 'colors' => ['B']],
        'Bloodbraid Elf' => ['manaCost' => '{2}{R}{G}', 'type' => 'Creature — Elf Berserker', 'power' => '3', 'toughness' => '2', 'text' => "Cascade (When you cast this spell, exile cards from the top of your library until you exile a nonland card that costs less. You may cast it without paying its mana cost. Put the exiled cards on the bottom of your library in a random order.)\nHaste", 'colors' => ['R', 'G']],
        'Reverberate' => ['manaCost' => '{R}{R}', 'type' => 'Instant', 'text' => 'Copy target instant or sorcery spell. You may choose new targets for the copy.', 'colors' => ['R']],
        'Casualty Lite' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "Casualty 1 (As you cast this spell, you may sacrifice a creature with power 1 or greater. When you do, copy this spell.)\nCasualty Lite deals 2 damage to any target.", 'colors' => ['R']],
        'Mirran Lite' => ['manaCost' => '{2}{R}', 'type' => 'Artifact — Equipment', 'text' => "For Mirrodin! (When this Equipment enters, create a 2/2 red Rebel creature token, then attach this to it.)\nEquipped creature gets +1/+0.\nEquip {2}", 'colors' => ['R']],
        'Ward Discard Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Horror', 'power' => '3', 'toughness' => '3', 'text' => 'Ward—Discard a card.', 'colors' => ['B']],
        'Molimo Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Elemental', 'power' => '*', 'toughness' => '*', 'text' => "Molimo Lite's power and toughness are each equal to the number of lands you control.", 'colors' => ['G']],
        'Bounce Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Faerie', 'power' => '2', 'toughness' => '2', 'text' => "Flying\n{1}{U}: Return Bounce Lite to its owner's hand.", 'colors' => ['U']],
        'Search Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Elf Scout', 'power' => '2', 'toughness' => '2', 'text' => 'When Search Lite enters, you may search your library for a basic land card, reveal it, put it into your hand, then shuffle.', 'colors' => ['G']],
        'Overload Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => "Return target creature you don't control to its owner's hand.\nOverload {4}{U}{U} (You may cast this spell for its overload cost. If you do, change its text by replacing all instances of \"target\" with \"each.\")", 'colors' => ['U']],
        'Eternalize Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '1', 'text' => "Flying\nEternalize {3}{U}{U} ({3}{U}{U}, Exile this card from your graveyard: Create a token that's a copy of it, except it's a 4/4 black Zombie Human Wizard with no mana cost. Eternalize only as a sorcery.)", 'colors' => ['U']],
        'Embalm Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Warrior', 'power' => '1', 'toughness' => '1', 'text' => "Lifelink\nEmbalm {W} ({W}, Exile this card from your graveyard: Create a token that's a copy of it, except it's a white Zombie Human Warrior with no mana cost. Embalm only as a sorcery.)", 'colors' => ['W']],
        'Enlist Lite' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Human Soldier', 'power' => '2', 'toughness' => '2', 'text' => "Enlist (As this creature attacks, you may tap a nonattacking creature you control without summoning sickness. When you do, add its power to this creature's until end of turn.)", 'colors' => ['W']],
        'Life Land Lite' => ['manaCost' => null, 'type' => 'Land', 'text' => "This land enters tapped unless a player has 13 or less life.\n{T}: Add {B} or {R}.", 'colors' => []],
        'Azorius Signet' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => '{1}, {T}: Add {W}{U}.', 'colors' => []],
        'Pro Black Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Knight', 'power' => '2', 'toughness' => '2', 'text' => 'Protection from black', 'colors' => ['W']],
        'Battle Cry Lite' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Goblin', 'power' => '2', 'toughness' => '2', 'text' => 'Battle cry (Whenever this creature attacks, each other attacking creature gets +1/+0 until end of turn.)', 'colors' => ['W']],
        'Proliferate Lite' => ['manaCost' => '{1}{U}', 'type' => 'Sorcery', 'text' => "Proliferate. (Choose any number of permanents and/or players, then give each another counter of each kind already there.)\nDraw a card.", 'colors' => ['U']],
        'Prototype Lite' => ['manaCost' => '{6}', 'type' => 'Artifact Creature — Construct', 'power' => '6', 'toughness' => '6', 'text' => "Prototype {1}{R} — 2/2 (You can cast this spell with different mana cost, color, and size. It keeps its abilities and types.)\nTrample", 'colors' => []],
        'Tribute Lite' => ['manaCost' => '{1}{B}{R}', 'type' => 'Creature — Minotaur', 'power' => '3', 'toughness' => '3', 'text' => "Tribute 1 (As this creature enters, an opponent of your choice may put a +1/+1 counter on it.)\nWhen Tribute Lite enters, if tribute wasn't paid, draw a card.", 'colors' => ['B', 'R']],
        'Look Land Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Look at the top four cards of your library. You may put a land card from among them onto the battlefield tapped. Put the rest on the bottom of your library in a random order. You gain 2 life.', 'colors' => ['G']],
        'Reveal Lands Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Reveal the top three cards of your library. Put all land cards revealed this way into your hand and the rest into your graveyard.', 'colors' => ['G']],
        'Shuffle Lite' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Draw a card. Then shuffle.', 'colors' => ['U']],
        'Temporary Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Return target creature card from your graveyard to the battlefield. It gains haste until end of turn. Exile it at the beginning of the next end step.', 'colors' => ['B']],
        'Path Lite' => ['manaCost' => '{W}', 'type' => 'Instant', 'text' => 'Exile target creature. Its controller may search their library for a basic land card, put that card onto the battlefield tapped, then shuffle.', 'colors' => ['W']],
        'Stress Lite' => ['manaCost' => '{1}{U}{R}', 'type' => 'Instant', 'text' => 'Stress Lite deals 2 damage to up to one target creature. Look at the top two cards of your library. Put one of those cards into your hand and the other on the bottom of your library.', 'colors' => ['U', 'R']],
        'Seismic Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Look at the top X cards of your library, where X is the number of lands you control. You may reveal a creature or land card from among them and put it into your hand. Put the rest on the bottom of your library in a random order.', 'colors' => ['G']],
        'Extraction Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "Choose target card in a graveyard other than a basic land card. Search its owner's graveyard, hand, and library for any number of cards with the same name as that card and exile them. Then that player shuffles.", 'colors' => ['B']],
        'Bribery Lite' => ['manaCost' => '{2}{U}', 'type' => 'Sorcery', 'text' => "Search target opponent's library for a creature card and put that card onto the battlefield under your control. Then that player shuffles.", 'colors' => ['U']],
        'Werewolf Lite // Howler Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Human Werewolf', 'power' => '2', 'toughness' => '2', 'text' => 'At the beginning of each upkeep, if no spells were cast last turn, transform Werewolf Lite.', 'colors' => ['G'], 'layout' => 'transform',
            'back' => ['name' => 'Howler Lite', 'type' => 'Creature — Werewolf', 'manaCost' => null, 'power' => '4', 'toughness' => '4', 'text' => "Trample\nAt the beginning of each upkeep, if a player cast two or more spells last turn, transform Howler Lite.", 'colors' => ['G']]],
        'Daybound Lite // Nightbound Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Human Werewolf', 'power' => '3', 'toughness' => '2', 'text' => "Daybound (If a player casts no spells during their own turn, it becomes night next turn.)", 'colors' => ['R'], 'layout' => 'transform',
            'back' => ['name' => 'Nightbound Lite', 'type' => 'Creature — Werewolf', 'manaCost' => null, 'power' => '5', 'toughness' => '4', 'text' => "Menace\nNightbound (If a player casts at least two spells during their own turn, it becomes day next turn.)", 'colors' => ['R']]],
        'Flip Lite // Flipped Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '1', 'text' => '{1}{U}: Transform Flip Lite. Activate only as a sorcery.', 'colors' => ['U'], 'layout' => 'transform',
            'back' => ['name' => 'Flipped Lite', 'type' => 'Creature — Insect', 'manaCost' => null, 'power' => '3', 'toughness' => '2', 'text' => 'Flying', 'colors' => ['U']]],
        'Ghost Lite // Spirit Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Peasant', 'power' => '2', 'toughness' => '2', 'text' => 'Disturb {1}{W} (You may cast this card from your graveyard transformed for its disturb cost.)', 'colors' => ['W'], 'layout' => 'transform',
            'back' => ['name' => 'Spirit Lite', 'type' => 'Creature — Spirit', 'manaCost' => null, 'power' => '1', 'toughness' => '1', 'text' => "Flying\nIf Spirit Lite would be put into a graveyard from anywhere, exile it instead.", 'colors' => ['W']]],
        'Pay Flip Lite // Paid Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Human', 'power' => '1', 'toughness' => '1', 'text' => 'At the beginning of your first main phase, you may pay {G}. If you do, transform Pay Flip Lite.', 'colors' => ['G'], 'layout' => 'transform',
            'back' => ['name' => 'Paid Lite', 'type' => 'Creature — Beast', 'manaCost' => null, 'power' => '4', 'toughness' => '4', 'text' => 'Trample', 'colors' => ['G']]],
        'Saga Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment — Saga', 'text' => "(As this Saga enters and after your draw step, add a lore counter. Sacrifice after III.)\nI, II — You gain 2 life.\nIII — Draw a card.", 'colors' => ['W']],
        'Flip Saga Lite // Saga Dragon Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment — Saga', 'text' => "(As this Saga enters and after your draw step, add a lore counter.)\nI — Flip Saga Lite deals 1 damage to each opponent.\nII — Exile this Saga, then return it to the battlefield transformed under your control.", 'colors' => ['R'], 'layout' => 'transform',
            'back' => ['name' => 'Saga Dragon Lite', 'type' => 'Enchantment Creature — Dragon', 'manaCost' => null, 'power' => '4', 'toughness' => '4', 'text' => 'Flying', 'colors' => ['R']]],
        'Healer Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Cleric', 'power' => '1', 'toughness' => '1', 'text' => '{T}: Prevent the next 1 damage that would be dealt to any target this turn.', 'colors' => ['W']],
        'Reconfigure Lite' => ['manaCost' => '{1}{G}', 'type' => 'Artifact Creature — Equipment Fox', 'power' => '2', 'toughness' => '2', 'text' => "Equipped creature gets +2/+2.\nReconfigure {2} ({2}: Attach to target creature you control; or unattach from a creature. Reconfigure only as a sorcery. While attached, this isn't a creature.)", 'colors' => ['G']],
        'Zenith Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => "Draw a card. Shuffle Zenith Lite into its owner's library.", 'colors' => ['G']],
        'Ascend Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Cat', 'power' => '1', 'toughness' => '1', 'text' => "First strike, lifelink\nAscend (If you control ten or more permanents, you get the city's blessing for the rest of the game.)", 'colors' => ['W']],
        'Lure Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Beast', 'power' => '2', 'toughness' => '2', 'text' => 'Lure Lite must be blocked if able.', 'colors' => ['G']],
        'Bully Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Ogre', 'power' => '3', 'toughness' => '3', 'text' => "Creatures with power less than Bully Lite's power can't block it.", 'colors' => ['R']],
        'Hand Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Illusion', 'power' => '*', 'toughness' => '*', 'text' => "Hand Lite's power and toughness are each equal to the number of cards in your hand.", 'colors' => ['U']],
        'Grave Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie', 'power' => '*', 'toughness' => '*', 'text' => "Grave Lite's power is equal to the number of creature cards in your graveyard.", 'colors' => ['B']],
        'Read Ahead Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment — Saga', 'text' => "(As this Saga enters and after your draw step, add a lore counter.)\nRead ahead (Choose a chapter and start with that many lore counters. Add one after your draw step. Skipped chapters don't trigger. Sacrifice after III.)\nI, II, III — You gain 1 life.", 'colors' => ['W']],
        'Ramp Lite' => ['manaCost' => '{2}{G}', 'type' => 'Sorcery', 'text' => 'Search your library for up to two basic land cards, put them onto the battlefield tapped, then shuffle.', 'colors' => ['G']],
        'Training Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '2', 'text' => 'Training (Whenever this creature attacks with another creature with greater power, put a +1/+1 counter on this creature.)', 'colors' => ['W']],
        'Scaler Lite' => ['manaCost' => '{2}', 'type' => 'Artifact Creature — Construct', 'power' => '0', 'toughness' => '0', 'text' => 'Scaler Lite gets +1/+1 for each artifact you control.', 'colors' => []],
        'Trample Counters Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'power' => '1', 'toughness' => '1', 'text' => 'Each creature you control with a +1/+1 counter on it has trample.', 'colors' => ['G']],
        'Party Lite' => ['manaCost' => '{4}{W}', 'type' => 'Sorcery', 'text' => "This spell costs {1} less to cast for each creature in your party.\nYou gain 4 life.", 'colors' => ['W']],
        'Sac Ping Lite' => ['manaCost' => '{1}', 'type' => 'Artifact', 'text' => '{1}, Sacrifice Sac Ping Lite: It deals 2 damage to target creature.', 'colors' => []],
        'Afflict Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie Naga', 'power' => '2', 'toughness' => '2', 'text' => 'Afflict 2 (Whenever this creature becomes blocked, defending player loses 2 life.)', 'colors' => ['B']],
        'Dredge Lite' => ['manaCost' => '{B}', 'type' => 'Creature — Zombie', 'power' => '1', 'toughness' => '1', 'text' => 'Dredge 3 (If you would draw a card, you may mill 3 cards instead. If you do, return this card from your graveyard to your hand.)', 'colors' => ['B']],
        'Incubate Lite' => ['manaCost' => '{2}', 'type' => 'Artifact Creature — Phyrexian', 'power' => '1', 'toughness' => '1', 'text' => 'When Incubate Lite enters, incubate 2. (Create an Incubator token with two +1/+1 counters on it and "{2}: Transform this artifact." It transforms into a 0/0 Phyrexian artifact creature.)', 'colors' => []],
        'Ki Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Monk', 'power' => '1', 'toughness' => '1', 'text' => 'Whenever you cast a Spirit or Arcane spell, you may put a ki counter on Ki Lite.', 'colors' => ['U']],
        'Arcane Lite' => ['manaCost' => '{U}', 'type' => 'Instant — Arcane', 'text' => 'Draw a card.', 'colors' => ['U']],
        'Night Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Vampire', 'power' => '2', 'toughness' => '1', 'text' => 'When Night Lite enters, you draw a card and you lose 1 life.', 'colors' => ['B']],
        'Blitz Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Devil', 'power' => '3', 'toughness' => '1', 'text' => 'Blitz {1}{R} (If you cast this spell for its blitz cost, it gains haste and "When this creature dies, draw a card." Sacrifice it at the beginning of the next end step.)', 'colors' => ['R']],
        'Buyback Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Buyback {2} (You may pay an additional {2} as you cast this spell. If you do, put this card into your hand as it resolves.)\nBuyback Lite deals 1 damage to any target.", 'colors' => ['R']],
        'Ingest Lite' => ['manaCost' => '{1}{C}', 'type' => 'Creature — Eldrazi Drone', 'power' => '2', 'toughness' => '1', 'text' => "Ingest (Whenever this creature deals combat damage to a player, that player exiles the top card of their library.)", 'colors' => []],
        'Ally Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf Warrior Ally', 'power' => '1', 'toughness' => '1', 'text' => 'Whenever Ally Lite or another Ally you control enters, you may put a +1/+1 counter on Ally Lite.', 'colors' => ['G']],
        'Conspire Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Conspire (As you cast this spell, you may tap two untapped creatures you control that share a color with it. When you do, copy it and you may choose a new target for the copy.)\nConspire Lite deals 1 damage to any target.", 'colors' => ['R']],
        'Awaken Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => "You gain 2 life.\nAwaken 2—{3}{G} (If you cast this spell for {3}{G}, also put two +1/+1 counters on target land you control and it becomes a 0/0 Elemental creature with haste. It's still a land.)", 'colors' => ['G']],
        'Connive Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Rogue', 'power' => '1', 'toughness' => '1', 'text' => 'When Connive Lite enters, it connives. (Draw a card, then discard a card. If you discarded a nonland card, put a +1/+1 counter on this creature.)', 'colors' => ['U']],
        'Tapped Discount Lite' => ['manaCost' => '{3}{B}', 'type' => 'Instant', 'text' => "Tapped Discount Lite costs {2} less to cast if it targets a tapped creature.\nDestroy target creature.", 'colors' => ['B']],
        'Grave Lands Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'You may play lands from your graveyard.', 'colors' => ['G']],
        'Big Game Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Destroy target creature with power 4 or greater.', 'colors' => ['B']],
        'Tutor Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Search your library for a creature card, reveal it, put it into your hand, then shuffle.', 'colors' => ['B']],
        'Demonic Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Search your library for a card, put that card into your hand, then shuffle.', 'colors' => ['B']],
        'Regrowth Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '1', 'text' => 'When Regrowth Lite enters, return target instant or sorcery card from your graveyard to your hand.', 'colors' => ['U']],
        'Loot Enter Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin', 'power' => '2', 'toughness' => '1', 'text' => 'When Loot Enter Lite enters, you may discard a card. If you do, draw a card.', 'colors' => ['R']],
        'Extra Turn Lite' => ['manaCost' => '{1}{U}', 'type' => 'Sorcery', 'text' => 'Take an extra turn after this one.', 'colors' => ['U']],
        'Grave Exile Lite' => ['manaCost' => '{B}', 'type' => 'Creature — Zombie', 'power' => '3', 'toughness' => '3', 'text' => 'As an additional cost to cast this spell, exile a creature card from your graveyard.', 'colors' => ['B']],
        'Cultivate' => ['manaCost' => '{2}{G}', 'type' => 'Sorcery', 'text' => 'Search your library for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle.', 'colors' => ['G']],
    ];

    private function put(int $seat, string $name, string $zone): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private static function more(string $name): array
    {
        return ['uuid' => 'uuid-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private static function read(string $name): CardDefinition
    {
        return new CardDefinition(self::more($name));
    }

    private function resolve(): void
    {
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
    }

    public function testEveryCardHereIsReadWhole(): void
    {
        foreach (array_diff(array_keys(self::MORE), ['Bola Slinger']) as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertContains('convoke', self::read('Ghostly Flicker Lite')->keywords, 'A keyword with a period reads like one without.');
    }

    public function testPersistAndUndying(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $finks = $this->put(1, 'Kitchen Finks', GameObject::BATTLEFIELD);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$finks}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($finks));
        $this->assertSame(1, $game->objects[$finks]->counter('-1/-1'));
        $this->assertSame(2, $game->power($game->objects[$finks]));
        $this->resolve(); // Its enters trigger.
        $this->assertSame(22, $this->life(1));

        $shock = $this->hand(0, 'Shock');
        $game->cast(0, $shock, 0, ["o:{$finks}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($finks), 'Persist only once.');

        $geist = $this->put(1, 'Strangleroot Geist', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$geist}"]);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$geist]));
    }

    public function testExaltedBushidoToxicAndBloodthirst(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $toxic = $this->put(0, 'Toxic Lite', GameObject::BATTLEFIELD);
        $retainer = $this->put(1, 'Devoted Retainer', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$toxic]);
        $this->assertSame(3, $game->power($game->objects[$toxic]), 'Exalted: attacking alone.');
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, []);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(2, $game->players[1]->poison);
        $this->assertSame(17, $this->life(1));

        // Bob was dealt damage this turn: bloodthirst.
        $this->lands(0, 'Mountain', 2);
        $berserker = $this->put(0, 'Stormblood Berserker', GameObject::HAND);
        $game->cast(0, $berserker);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$berserker]));

        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$berserker, $toxic]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$retainer => $toxic]);
        $this->assertSame(2, $game->power($game->objects[$retainer]), 'Bushido when it blocks.');
    }

    public function testLandwalkAndAttackingTargets(): void
    {
        $game = $this->newGame();
        $wraith = $this->put(0, 'Bog Wraith', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->battlefield(1, 'Swamp');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$wraith]);
        $this->assertFalse($game->canBlock($game->objects[$bears], $game->objects[$wraith]));

        $this->lands(1, 'Plains', 4);
        $verdict = $this->put(1, 'Divine Verdict', GameObject::HAND);
        $this->assertSame(["o:{$wraith}"], $game->targetOptions(1, 'attacking_or_blocking'));
        $game->pass(0);
        $game->cast(1, $verdict, 0, ["o:{$wraith}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($wraith));
    }

    public function testLandfallAndAttachOnEnter(): void
    {
        $game = $this->newGame();
        $lynx = $this->put(0, 'Steppe Lynx Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 3);
        $forest = $this->hand(0, 'Plains');
        $game->playLand(0, $forest);
        $this->resolve();
        $this->assertSame(2, $game->power($game->objects[$lynx]));

        $this->lands(0, 'Plains', 2);
        $sword = $this->put(0, 'Sword Lite', GameObject::HAND);
        $game->cast(0, $sword);
        $this->resolve();
        $this->resolve();
        $this->assertSame($lynx, $game->objects[$sword]->attachedTo);
        $this->assertSame(4, $game->power($game->objects[$lynx]));
    }

    public function testBlockingByPowerAndOnlyFlyers(): void
    {
        $game = $this->newGame();
        $kithkin = $this->put(0, 'Amrou Kithkin', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $giant = $this->battlefield(1, 'Hill Giant');
        $elves = $this->battlefield(1, 'Llanowar Elves');
        $wall = $this->put(1, 'Wall of Air Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$kithkin, $bears]);
        $this->assertFalse($game->canBlock($game->objects[$giant], $game->objects[$kithkin]));
        $this->assertTrue($game->canBlock($game->objects[$elves], $game->objects[$kithkin]));
        $this->assertTrue($game->canBlock($game->objects[$giant], $game->objects[$bears]));
        $this->assertFalse($game->canBlock($game->objects[$wall], $game->objects[$bears]));
    }

    public function testAffinityForArtifacts(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Ornithopter', GameObject::BATTLEFIELD);
        $this->put(0, 'Ornithopter', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 2);
        $frogmite = $this->put(0, 'Frogmite', GameObject::HAND);
        $this->assertTrue($game->canCast(0, $frogmite), 'Two artifacts and two lands pay {4}.');
        $game->cast(0, $frogmite);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($frogmite));
    }

    public function testDashEvokeWarpAndPlot(): void
    {
        $game = $this->newGame();
        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Mountain', 2);
        $scout = $this->put(0, 'Mardu Scout', GameObject::HAND);
        $this->assertContains(['id' => $scout, 'how' => 'dash'], $game->plays(0));
        $this->assertSame('{1}{R}', Game::castCost(self::read('Mardu Scout'), 'dash'));
        $game->cast(0, $scout, 0, [], 'dash');
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($scout));
        $this->assertContains('haste', $game->keywords($game->objects[$scout]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::End, 3);
        $this->assertSame(GameObject::HAND, $this->zone($scout), 'Dash returns it at the end step.');

        // Evoke: it enters, its trigger draws, and it is sacrificed.
        $this->passUntil(Step::PrecombatMain, 5);
        $this->lands(0, 'Island', 3);
        $drifter = $this->put(0, 'Mulldrifter', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $drifter, 0, [], 'evoke');
        for ($i = 0; $i < 5 && ($game->stack !== [] || $this->zone($drifter) === GameObject::STACK); $i++) {
            $this->resolve();
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($drifter));
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Cast one, drew two.');

        // Warp: exiled at the end step, then cast from exile on a later turn.
        $this->passUntil(Step::PrecombatMain, 7);
        $this->lands(0, 'Mountain', 3);
        $warp = $this->put(0, 'Warp Lite', GameObject::HAND);
        $game->cast(0, $warp, 0, [], 'warp');
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($warp));
        $this->passUntil(Step::End, 7);
        $this->assertSame(GameObject::EXILE, $this->zone($warp));
        $this->assertNotContains(['id' => $warp, 'how' => 'wx'], $game->plays(0), 'Not the same turn.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 9);
        $this->assertContains(['id' => $warp, 'how' => 'wx'], $game->plays(0));
        $game->cast(0, $warp, 0, [], 'wx');
        $this->resolve();
        $this->passUntil(Step::PrecombatMain, 11);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($warp), 'Cast normally, it stays.');

        // Plot: exiled now for its plot cost, cast free on a later turn.
        foreach ($game->players[0]->hand as $id) {
            $game->objects[$id]->moveTo(GameObject::EXILE);
            $game->exile[] = $id;
        }
        $game->players[0]->hand = [];
        $plot = $this->put(0, 'Plot Lite', GameObject::HAND);
        $this->assertContains(['id' => $plot, 'how' => 'plot'], $game->plays(0));
        $game->plot(0, $plot);
        $this->assertSame(GameObject::EXILE, $this->zone($plot));
        $this->assertNotContains(['id' => $plot, 'how' => 'pl'], $game->plays(0));
        $this->passUntil(Step::PrecombatMain, 13);
        foreach ($game->permanents(0) as $object) {
            $object->tapped = $object->printed()->isLand();
        }
        $this->assertContains(['id' => $plot, 'how' => 'pl'], $game->plays(0));
        $life = $this->life(1);
        $game->cast(0, $plot, 0, ['p:1'], 'pl');
        $this->resolve();
        $this->assertSame($life - 3, $this->life(1), 'Free, with every land tapped.');
    }

    public function testMobilizeAndBlockLimit(): void
    {
        $game = $this->newGame();
        $mobilize = $this->put(0, 'Mobilize Lite', GameObject::BATTLEFIELD);
        $drums = $this->put(0, 'Goblin War Drums Lite', GameObject::BATTLEFIELD);
        $first = $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $second = $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$mobilize, $drums]);
        $this->resolve();
        $this->assertCount(4, $game->attackers, 'Two attacking Warriors.');
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        try {
            $game->declareBlockers(1, [$first => $drums, $second => $drums]);
            $this->fail('Two blockers on one creature that cannot be blocked by more than one.');
        } catch (GameException) {
        }
        $game->declareBlockers(1, [$first => $mobilize, $second => $mobilize]);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->assertSame(15, $this->life(1));
        $warriors = fn () => count(array_filter($game->permanents(0), fn (GameObject $object) => $object->name() === 'Warrior Token'));
        $this->assertSame(2, $warriors());
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::Upkeep, 4);
        $this->assertSame(0, count(array_filter($game->permanents(0), fn (GameObject $object) => $object->name() === 'Warrior Token')), 'Sacrificed at the end step.');
    }

    public function testCreatureEntersSecondDrawAndAnyNumber(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Soul Warden Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Akrasan Squire', GameObject::HAND), 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame(21, $this->life(0));
        $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->assertSame(21, $this->life(0), "Only creatures you control.");

        $sage = $this->put(0, 'Second Draw Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Draw Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->objects[$sage]->counter('+1/+1'), 'The draw step, then the second card.');

        $this->assertContains('any number', self::read('Relentless Rats')->keywords);
    }

    public function testNinjutsuBackupAndOffspring(): void
    {
        $game = $this->newGame();
        $squire = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $ninja = $this->put(0, 'Ninja Lite', GameObject::HAND);
        $this->passUntil(Step::DeclareAttackers, 3);
        $this->lands(0, 'Island', 2);
        $this->assertNotContains(['id' => $ninja, 'how' => 'ninjutsu'], $game->plays(0), 'Not before blockers.');
        $game->declareAttackers(0, [$squire]);
        $this->passUntil(Step::DeclareBlockers, 3);
        $this->assertContains(['id' => $ninja, 'how' => 'ninjutsu'], $game->plays(0));
        $game->ninjutsu(0, $ninja);
        $this->assertSame(GameObject::HAND, $this->zone($squire));
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($ninja));
        $this->assertTrue($game->objects[$ninja]->tapped);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->assertSame(18, $this->life(1));

        // Backup: the counter on another creature, and the Valkyrie's keywords with it.
        $this->lands(0, 'Plains', 5);
        $valkyrie = $this->put(0, 'Boon-Bringer Valkyrie', GameObject::HAND);
        $game->cast(0, $valkyrie, 0, []);
        $this->resolve();
        $game->chooseTriggerTargets(0, ["o:{$ninja}"]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$ninja]->counter('+1/+1'));
        $this->assertContains('lifelink', $game->keywords($game->objects[$ninja]));
        $this->assertNotContains('backup', $game->keywords($game->objects[$ninja]));
        $this->assertNotSame([], self::read('Bola Slinger')->unsupported, 'Only keywords are granted.');

        // Offspring: kicked, a 1/1 token copy.
        $this->passUntil(Step::PrecombatMain, 5);
        $this->lands(0, 'Plains', 4);
        $rabbit = $this->put(0, 'Offspring Lite', GameObject::HAND);
        $this->assertContains(['id' => $rabbit, 'how' => 'kick'], $game->plays(0));
        $game->cast(0, $rabbit, 0, [], 'kick');
        $this->resolve();
        $this->resolve();
        $copies = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Offspring Lite' && $o->definition()->isToken()));
        $this->assertCount(1, $copies);
        $this->assertSame(1, $game->power($copies[0]));
    }

    public function testMadnessSpreeAndEntwine(): void
    {
        $game = $this->newGame();
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $game->objects[$bears]->tapped = true;
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Swamp', 2);
        $compulsion = $this->put(0, 'Murderous Compulsion', GameObject::HAND);
        $game->cast(0, $this->put(0, 'Tormenting Voice', GameObject::HAND), 0, []);
        $this->assertSame(GameObject::EXILE, $this->zone($compulsion), 'Discarded into exile.');
        $this->assertContains(['id' => $compulsion, 'how' => 'md'], $game->plays(0), 'Cast it at instant speed while the trigger waits.');
        $game->cast(0, $compulsion, 0, ["o:{$bears}"], 'md');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        for ($i = 0; $i < 3 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($compulsion));

        // Not cast: the trigger puts it into the graveyard.
        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Mountain', 2);
        foreach ($game->players[0]->hand as $id) {
            $game->objects[$id]->moveTo(GameObject::EXILE);
            $game->exile[] = $id;
        }
        $game->players[0]->hand = [];
        $again = $this->put(0, 'Murderous Compulsion', GameObject::HAND);
        $game->cast(0, $this->put(0, 'Tormenting Voice', GameObject::HAND), 0, []);
        for ($i = 0; $i < 3 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($again));

        // Spree: each mode adds its cost.
        $this->passUntil(Step::PrecombatMain, 5);
        $spree = self::read('Spree Lite');
        $this->assertSame([], $spree->unsupported);
        $this->assertSame('{R}{1}{2}', Game::castCost($spree, 'm0+1'));
        $this->lands(0, 'Mountain', 4);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Spree Lite', GameObject::HAND), 0, ['p:1'], 'm0+1');
        $this->resolve();
        $this->assertSame(18, $this->life(1));
        $this->assertSame($hand + 1, count($game->players[0]->hand));

        // Entwine: both modes.
        $this->passUntil(Step::PrecombatMain, 7);
        $this->lands(0, 'Forest', 5);
        $bird = $this->put(1, 'Ornithopter', GameObject::BATTLEFIELD);
        $strike = $this->put(0, 'Flourishing Strike', GameObject::HAND);
        $this->assertContains(['id' => $strike, 'how' => 'm0+1,entwine'], $game->plays(0));
        $this->assertNotContains(['id' => $strike, 'how' => 'm0+1'], $game->plays(0));
        $game->cast(0, $strike, 0, ["o:{$bird}", "o:{$this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD)}"], 'm0+1,entwine');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bird));
    }

    public function testSuspendRegrowAfterlifeAndAnnihilator(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 1);
        $sower = $this->put(0, 'Rift Sower', GameObject::HAND);
        $this->assertContains(['id' => $sower, 'how' => 'suspend'], $game->plays(0));
        $game->suspend(0, $sower);
        $this->assertSame(2, $game->objects[$sower]->counter('time'));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::Upkeep, 3);
        $this->assertSame(1, $game->objects[$sower]->counter('time'));
        $this->passUntil(Step::Upkeep, 5);
        $this->assertContains(['id' => $sower, 'how' => 'sp'], $game->plays(0));
        $game->cast(0, $sower, 0, [], 'sp');
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($sower));
        $this->assertContains('haste', $game->keywords($game->objects[$sower]));

        // Return from the graveyard to hand.
        $this->passUntil(Step::PrecombatMain, 5);
        $ghoul = $this->put(0, 'Blessed Ghoul', GameObject::GRAVEYARD);
        $this->lands(0, 'Plains', 3);
        $this->assertContains(['id' => $ghoul, 'how' => 'regrow'], $game->plays(0));
        $game->regrow(0, $ghoul);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($ghoul));

        // Terror and afterlife.
        $messenger = $this->put(1, 'Syndicate Messenger', GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 2);
        $this->assertSame([], self::read('Terror')->unsupported);
        $this->assertSame([], self::read('Pillage')->unsupported);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$messenger}"]);
        $this->resolve();
        $this->resolve();
        $spirits = array_filter($game->permanents(1), fn (GameObject $o) => $o->name() === 'Spirit Token');
        $this->assertCount(1, $spirits);
        $this->assertContains('flying', $game->keywords(reset($spirits)));

        // Annihilator: the weakest permanent, the Spirit token.
        $eldrazi = $this->put(0, 'Annihilator Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 7);
        $game->declareAttackers(0, [$eldrazi]);
        $this->resolve();
        $this->assertSame([], array_filter($game->permanents(1), fn (GameObject $o) => $o->name() === 'Spirit Token'));
    }

    public function testCounteredSpellIsExiled(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Plains', 1);
        $this->lands(1, 'Island', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ['p:1']);
        $game->pass(0);
        $lies = $this->put(1, 'No More Lies', GameObject::HAND);
        $game->cast(1, $lies, 0, ["s:{$game->stack[0]['id']}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($bolt));
        $this->assertSame(20, $this->life(1));
    }

    public function testBounceAndFilterLands(): void
    {
        $game = $this->newGame();
        $plains = $this->battlefield(0, 'Plains');
        $chancery = $this->put(0, 'Azorius Chancery', GameObject::HAND);
        $game->objects[$plains]->tapped = true;
        $game->playLand(0, $chancery);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($plains), 'The tapped Plains goes back.');
        $this->assertTrue($game->objects[$chancery]->tapped);

        // Untapped next turn: {W}{U} pays for a two-color spell.
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame(['count' => 2, 'colors' => ['W', 'U'], 'fixed' => [['W'], ['U']]], $game->manaSources(0)[$chancery]);
        $this->lands(0, 'Swamp', 1);
        $this->assertTrue($game->canCast(0, $this->put(0, 'Syndic of Tithes', GameObject::HAND)), '{2}{W} from {W}{U} and a Swamp.');

        // Rumble Arena: {C}, or {1} and itself for any color.
        $this->assertSame(['count' => 1, 'colors' => ['C'], 'filter' => true], self::read('Rumble Arena')->manaAbility);
        $this->assertSame(['count' => 1, 'colors' => ['C'], 'filter' => true], self::read('Prismatic Lens')->manaAbility);
    }

    public function testFilterManaPaysAColor(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $this->put(0, 'Prismatic Lens', GameObject::BATTLEFIELD);
        $squire = $this->put(0, 'Akrasan Squire', GameObject::HAND);
        $this->assertTrue($game->canCast(0, $squire), '{1} from the Mountain, {W} from the Lens.');
        $game->cast(0, $squire, 0, []);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($squire));
        $this->assertFalse($game->canCast(0, $this->put(0, 'Akrasan Squire', GameObject::HAND)), 'Everything is tapped.');
    }

    public function testRevealExileLookAndPlayerHexproof(): void
    {
        $game = $this->newGame();
        $bears = $this->put(1, 'Akrasan Squire', GameObject::HAND);
        $this->hand(1, 'Lightning Bolt');
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Intimidation Tactics', GameObject::HAND), 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($bears), 'The only creature card: exiled.');

        $this->lands(0, 'Island', 2);
        $hand = count($game->players[0]->hand);
        $library = count($game->players[0]->library);
        $game->cast(0, $this->put(0, 'Stress Dream Lite', GameObject::HAND), 0, []);
        $this->resolve();
        if ($game->choiceAwaiting() !== null) {
            $choice = $game->choiceAwaiting();
            $game->take(0, [$choice['cards'][0]]);
        }
        $this->assertSame($hand + 1, count($game->players[0]->hand));
        $this->assertSame($library - 1, count($game->players[0]->library));

        $this->put(1, 'Leyline of Sanctity', GameObject::BATTLEFIELD);
        $this->assertFalse($game->isLegalTarget('player', 'p:1', 0));
        $this->assertTrue($game->isLegalTarget('player', 'p:1', 1));
    }

    public function testLandfallModesSunburstImpulseAndAscend(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Retreat to Kazandu')->unsupported);
        $this->put(0, 'Retreat to Kazandu', GameObject::BATTLEFIELD);
        $game->playLand(0, $this->hand(0, 'Plains'));
        $this->resolve();
        $this->assertSame(22, $this->life(0), 'No creature to target: only the life.');

        $squire = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 3);
        $game->playLand(0, $this->hand(0, 'Mountain'));
        $this->assertSame('mode', $game->choiceAwaiting()['type']);
        $game->chooseMode(0, 0);
        if ($game->decision(0) === 'trigger') {
            $game->chooseTriggerTargets(0, ["o:{$squire}"]);
        }
        $this->resolve();
        $this->assertSame(1, $game->objects[$squire]->counter('+1/+1'));

        // Sunburst: three colors spent.
        $this->lands(0, 'Island', 1);
        $this->lands(0, 'Swamp', 1);
        $this->lands(0, 'Forest', 1);
        $oracle = $this->put(0, 'Etched Oracle', GameObject::HAND);
        $game->cast(0, $oracle, 0, []);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($oracle));
        $this->assertGreaterThanOrEqual(3, $game->objects[$oracle]->counter('+1/+1'));

        // Impulse draw: playable until the end of the next turn.
        $this->passUntil(Step::PrecombatMain, 5);
        $this->lands(0, 'Mountain', 2);
        $library = $game->players[0]->library;
        $game->cast(0, $this->put(0, 'Reckless Impulse', GameObject::HAND), 0, []);
        $this->resolve();
        $top = end($library);
        $this->assertSame(GameObject::EXILE, $this->zone($top));
        $this->assertSame('impulse', $game->objects[$top]->exiledBy);
        $this->assertContains(['id' => $top, 'how' => ''], $game->plays(0), 'A Forest from exile: played as the land drop.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 7);
        $this->assertContains(['id' => $top, 'how' => ''], $game->plays(0));
        $this->passUntil(Step::PrecombatMain, 9);
        $this->assertNotContains(['id' => $top, 'how' => ''], $game->plays(0), 'Too late.');

        // Ascend: ten permanents.
        $this->assertSame([], self::read('Ascend Lite')->unsupported);
        $this->assertSame([], self::read('Seer Lite')->unsupported);
        $this->put(0, 'Ascend Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 1);
        $this->resolve();
        $this->assertTrue($game->players[0]->blessed);
        $this->assertFalse($game->players[1]->blessed);
    }

    public function testEchoFlankingAndTargetedSacrifice(): void
    {
        $game = $this->newGame();
        $echo = $this->put(0, 'Echo Lite', GameObject::BATTLEFIELD);
        $doomed = $this->put(0, 'Echo Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 2);
        $this->passUntil(Step::PrecombatMain, 3);
        $kept = array_values(array_filter([$echo, $doomed], fn (int $id) => $this->zone($id) === GameObject::BATTLEFIELD));
        $this->assertCount(1, $kept, 'Mana for only one echo: the other is sacrificed.');
        $echo = $kept[0];
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 5);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($echo), 'Echo is paid only once.');

        $flanker = $this->put(0, 'Flanking Lite', GameObject::BATTLEFIELD);
        $blocker = $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 7);
        $game->declareAttackers(0, [$flanker]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$blocker => $flanker]);
        $this->assertContains('Flanking: Akrasan Squire gets -1/-1 until end of turn.', $game->log);
        $this->passUntil(Step::PostcombatMain, 7);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($blocker), 'A 0/0 blocker dies.');

        $ghost = $this->put(1, 'Skulking Ghost', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ["o:{$ghost}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($ghost), 'Sacrificed as it became the target.');
        $this->assertContains('Skulking Ghost\'s ability triggers.', $game->log);
    }

    public function testEscapeJumpStartRetraceAndBargain(): void
    {
        $game = $this->newGame();
        $this->assertSame(['escape' => '{2}{B}'], self::read('Escape Lite')->altCosts);
        $escape = $this->put(0, 'Escape Lite', GameObject::GRAVEYARD);
        $this->put(0, 'Relentless Rats', GameObject::GRAVEYARD);
        $this->put(0, 'Relentless Rats', GameObject::GRAVEYARD);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Swamp', 3);
        $this->assertNotContains(['id' => $escape, 'how' => 'es'], $game->plays(0), 'Only two other cards.');
        $third = $this->put(0, 'Relentless Rats', GameObject::GRAVEYARD);
        $this->assertContains(['id' => $escape, 'how' => 'es'], $game->plays(0));
        $game->cast(0, $escape, 0, [], 'es');
        $this->assertSame(GameObject::EXILE, $this->zone($third));
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($escape));
        $this->assertSame(1, $game->objects[$escape]->counter('+1/+1'));

        $insight = $this->put(0, "Chemister's Insight", GameObject::GRAVEYARD);
        $this->lands(0, 'Island', 4);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $insight, 0, [], 'js');
        $this->resolve();
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Discarded one, drew two.');
        $this->assertSame(GameObject::EXILE, $this->zone($insight));

        $retrace = $this->put(0, 'Retrace Lite', GameObject::GRAVEYARD);
        $this->lands(0, 'Mountain', 2);
        foreach ($game->players[0]->hand as $card) {
            $game->objects[$card]->moveTo(GameObject::EXILE);
            $game->exile[] = $card;
        }
        $game->players[0]->hand = [];
        $this->assertNotContains(['id' => $retrace, 'how' => 'rt'], $game->plays(0), 'No land card to discard.');
        $forest = $this->hand(0, 'Forest');
        $game->cast(0, $retrace, 0, ['p:1'], 'rt');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($forest));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($retrace), 'Retrace does not exile.');
        $this->assertSame(18, $this->life(1));

        $this->passUntil(Step::PrecombatMain, 5);
        $this->lands(0, 'Mountain', 4);
        $bargain = $this->put(0, 'Bargain Lite', GameObject::HAND);
        $this->assertNotContains(['id' => $bargain, 'how' => 'bargain'], $game->plays(0), 'Nothing to sacrifice.');
        $lens = $this->put(0, 'Prismatic Lens', GameObject::BATTLEFIELD);
        $this->assertContains(['id' => $bargain, 'how' => 'bargain'], $game->plays(0));
        $game->cast(0, $bargain, 0, ['p:1'], 'bargain');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($lens));
        $this->resolve();
        $this->assertSame(14, $this->life(1));
        $game->cast(0, $this->put(0, 'Bargain Lite', GameObject::HAND), 0, ['p:1']);
        $this->resolve();
        $this->assertSame(12, $this->life(1));
    }

    public function testStormAndForetell(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ['p:1']);
        $this->resolve();
        $this->assertSame(17, $this->life(1));
        $game->cast(0, $this->put(0, 'Grapeshot', GameObject::HAND), 0, ['p:1']);
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(15, $this->life(1), 'Grapeshot and one copy.');

        $this->lands(0, 'Mountain', 2);
        $foretell = $this->put(0, 'Foretell Lite', GameObject::HAND);
        $this->assertContains(['id' => $foretell, 'how' => 'foretell'], $game->plays(0));
        $game->foretell(0, $foretell);
        $this->assertSame(GameObject::EXILE, $this->zone($foretell));
        $this->assertNotContains(['id' => $foretell, 'how' => 'ft'], $game->plays(0), 'Not the turn it was foretold.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertContains(['id' => $foretell, 'how' => 'ft'], $game->plays(0));
        $this->assertSame('{R}', Game::castCost($game->objects[$foretell]->printed(), 'ft'));
        $game->cast(0, $foretell, 0, ['p:1'], 'ft');
        $this->resolve();
        $this->assertSame(12, $this->life(1));
    }

    public function testCumulativeUpkeepSporesAndControllerLosesLife(): void
    {
        $game = $this->newGame();
        $elemental = $this->put(0, 'Cumulative Lite', GameObject::BATTLEFIELD);
        $thallid = $this->put(0, 'Thallid', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($elemental), 'Paid {1} for one age counter.');
        $this->passUntil(Step::PrecombatMain, 5);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($elemental), 'Two age counters: {2} it cannot pay.');

        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 7);
        $this->assertSame(3, $game->objects[$thallid]->counter('spore'));
        $game->activate(0, $thallid, 0);
        $this->resolve();
        $this->assertSame(0, $game->objects[$thallid]->counter('spore'));
        $this->assertCount(1, array_filter($game->permanents(0), fn (GameObject $object) => $object->name() === 'Saproling Token'));
        try {
            $game->activate(0, $thallid, 0);
            $this->fail('No spore counters left.');
        } catch (GameException) {
        }

        $squire = $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Murder Lite', GameObject::HAND), 0, ["o:{$squire}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($squire));
        $this->assertSame(18, $this->life(1));
    }

    public function testCascade(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Annihilator Lite', GameObject::LIBRARY);
        $grapeshot = $this->put(0, 'Grapeshot', GameObject::LIBRARY);
        $this->game->addCard(0, self::card('Forest'), GameObject::LIBRARY);
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Forest', 2);
        $elf = $this->put(0, 'Bloodbraid Elf', GameObject::HAND);
        $game->cast(0, $elf, 0, []);
        $this->assertSame(GameObject::EXILE, $this->zone($grapeshot), 'The Forest is passed over.');
        $this->assertContains(['id' => $grapeshot, 'how' => 'cc'], $game->plays(0), 'Cast free while the trigger waits.');
        $game->cast(0, $grapeshot, 0, ['p:1'], 'cc');
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(18, $this->life(1), 'Grapeshot is cast, so its storm copies it once for the Elf.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($elf));
        $this->assertSame('Forest', $game->objects[$game->players[0]->library[0]]->name(), 'The Forest goes on the bottom.');

        // Not cast: the card goes on the bottom too.
        $this->passUntil(Step::PrecombatMain, 3);
        $missed = $this->put(0, 'Grapeshot', GameObject::LIBRARY);
        $game->cast(0, $this->put(0, 'Bloodbraid Elf', GameObject::HAND), 0, []);
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(GameObject::LIBRARY, $this->zone($missed));
        $this->assertSame($missed, $game->players[0]->library[0]);
    }

    public function testCopyCasualtyAndForMirrodin(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 4);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ['p:1']);
        $bolt = end($game->stack)['id'];
        $game->cast(0, $this->put(0, 'Reverberate', GameObject::HAND), 0, ["s:{$bolt}"]);
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(14, $this->life(1), 'Bolt and its copy.');

        $this->passUntil(Step::PrecombatMain, 3);
        $squire = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $casualty = $this->put(0, 'Casualty Lite', GameObject::HAND);
        $this->assertContains(['id' => $casualty, 'how' => 'casualty'], $game->plays(0));
        $game->cast(0, $casualty, 0, ['p:1'], 'casualty');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($squire));
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(10, $this->life(1), 'The spell and its copy.');
        $this->assertNotContains(['id' => $this->put(0, 'Casualty Lite', GameObject::HAND), 'how' => 'casualty'], $game->plays(0), 'No creature to sacrifice.');

        $this->lands(0, 'Mountain', 3);
        $mirran = $this->put(0, 'Mirran Lite', GameObject::HAND);
        $game->cast(0, $mirran, 0, []);
        while ($game->stack !== []) {
            $this->resolve();
        }
        $rebel = $game->objects[$game->objects[$mirran]->attachedTo];
        $this->assertSame('Rebel Token', $rebel->name());
        $this->assertSame(3, $game->power($rebel));
    }

    public function testWardDiscardLandCountReturnAndSearch(): void
    {
        $game = $this->newGame();
        $horror = $this->put(1, 'Ward Discard Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        foreach ($game->players[0]->hand as $card) {
            $game->objects[$card]->moveTo(GameObject::EXILE);
            $game->exile[] = $card;
        }
        $game->players[0]->hand = [];
        $bolt = $this->hand(0, 'Lightning Bolt');
        try {
            $game->cast(0, $bolt, 0, ["o:{$horror}"]);
            $this->fail('No card to discard for ward.');
        } catch (GameException) {
        }
        $forest = $this->hand(0, 'Forest');
        $game->cast(0, $bolt, 0, ["o:{$horror}"]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($forest), 'Discarded for ward.');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($horror), 'Ward paid, so the Bolt resolves.');

        $molimo = $this->put(0, 'Molimo Lite', GameObject::BATTLEFIELD);
        $this->assertSame(1, $game->power($game->objects[$molimo]));
        $this->lands(0, 'Forest', 2);
        $this->assertSame(3, $game->toughness($game->objects[$molimo]));

        $faerie = $this->put(0, 'Bounce Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 2);
        $game->activate(0, $faerie, 0);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($faerie));

        $this->assertSame([], self::read('Search Lite')->unsupported);
    }

    public function testOverloadEternalizeEmbalmAndEnlist(): void
    {
        $game = $this->newGame();
        $first = $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $second = $this->put(1, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $mine = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 6);
        $overload = $this->put(0, 'Overload Lite', GameObject::HAND);
        $this->assertContains(['id' => $overload, 'how' => 'overload'], $game->plays(0));
        $game->cast(0, $overload, 0, [], 'overload');
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($first));
        $this->assertSame(GameObject::HAND, $this->zone($second));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($mine), 'Only creatures you don\'t control.');

        $this->passUntil(Step::PrecombatMain, 3);
        $wizard = $this->put(0, 'Eternalize Lite', GameObject::GRAVEYARD);
        $warrior = $this->put(0, 'Embalm Lite', GameObject::GRAVEYARD);
        $this->lands(0, 'Plains', 1);
        $this->assertContains(['id' => $wizard, 'how' => 'eternalize'], $game->plays(0));
        $game->embalm(0, $wizard, 'eternalize');
        $this->resolve();
        $game->embalm(0, $warrior, 'embalm');
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($wizard));
        $tokens = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->definition()->isToken()));
        $this->assertCount(2, $tokens);
        $this->assertSame([4, 4], [$game->power($tokens[0]), $game->toughness($tokens[0])]);
        $this->assertSame(['B'], $tokens[0]->definition()->colors);
        $this->assertContains('Zombie', $tokens[1]->definition()->subtypes);
        $this->assertContains('lifelink', $game->keywords($tokens[1]));

        $enlist = $this->put(0, 'Enlist Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$enlist]);
        $this->assertSame(4, $game->power($game->objects[$enlist]), 'Enlisted a 1/1 creature, plus exalted.');
    }

    public function testLifeLandAndSignet(): void
    {
        $game = $this->newGame();
        $signet = $this->put(0, 'Azorius Signet', GameObject::BATTLEFIELD);
        $draw = $this->put(0, 'Draw Lite', GameObject::HAND);
        $this->assertNotContains(['id' => $draw, 'how' => ''], $game->plays(0), 'A Signet alone cannot pay its own {1}.');
        $first = $this->put(0, 'Life Land Lite', GameObject::HAND);
        $game->playLand(0, $first);
        $this->assertTrue($game->objects[$first]->tapped, 'Both players above 13 life.');
        $this->lands(0, 'Forest', 1);
        $game->cast(0, $draw, 0, []);
        $this->assertTrue($game->objects[$signet]->tapped, 'The Forest pays the Signet\'s {1}; {W}{U} pays {1}{U}.');
        $this->resolve();

        $game->players[1]->life = 13;
        $this->passUntil(Step::PrecombatMain, 3);
        $second = $this->put(0, 'Life Land Lite', GameObject::HAND);
        $game->playLand(0, $second);
        $this->assertFalse($game->objects[$second]->tapped, 'A player has 13 or less life.');
    }

    public function testProtectionBattleCryAndProliferate(): void
    {
        $game = $this->newGame();
        $knight = $this->put(0, 'Pro Black Lite', GameObject::BATTLEFIELD);
        $this->lands(1, 'Swamp', 2);
        $this->passUntil(Step::PrecombatMain, 2);
        try {
            $game->cast(1, $this->put(1, 'Murder Lite', GameObject::HAND), 0, ["o:{$knight}"]);
            $this->fail('Protection from black: a black spell cannot target it.');
        } catch (GameException) {
        }

        $this->passUntil(Step::PrecombatMain, 3);
        $cry = $this->put(0, 'Battle Cry Lite', GameObject::BATTLEFIELD);
        $zombie = $this->put(1, 'Ward Discard Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$knight, $cry]);
        $this->assertSame(3, $game->power($game->objects[$knight]), 'Battle cry: +1/+0.');
        $this->assertSame(2, $game->power($game->objects[$cry]), 'Not itself.');
        $this->assertFalse($game->canBlock($game->objects[$zombie], $game->objects[$knight]), 'A black creature cannot block it.');
        $this->assertTrue($game->canBlock($game->objects[$zombie], $game->objects[$cry]));

        $this->passUntil(Step::PrecombatMain, 5);
        $game->objects[$knight]->addCounters('+1/+1', 1);
        $game->objects[$zombie]->addCounters('-1/-1', 1);
        $game->players[1]->poison = 2;
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Proliferate Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->assertSame(2, $game->objects[$knight]->counter('+1/+1'));
        $this->assertSame(2, $game->objects[$zombie]->counter('-1/-1'));
        $this->assertSame(3, $game->players[1]->poison);
    }

    public function testPrototypeAndTribute(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $construct = $this->put(0, 'Prototype Lite', GameObject::HAND);
        $this->assertContains(['id' => $construct, 'how' => 'prototype'], $game->plays(0));
        $game->cast(0, $construct, 0, [], 'prototype');
        $this->resolve();
        $this->assertSame([2, 2], [$game->power($game->objects[$construct]), $game->toughness($game->objects[$construct])]);

        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Swamp', 1);
        $this->lands(0, 'Mountain', 2);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Tribute Lite', GameObject::HAND), 0, []);
        while ($game->stack !== [] || $game->pendingTriggers !== []) {
            $this->resolve();
        }
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Tribute was not paid: draw a card.');
    }

    public function testPreventionReconfigureAndShuffleIntoLibrary(): void
    {
        $game = $this->newGame();
        $healer = $this->put(0, 'Healer Lite', GameObject::BATTLEFIELD);
        $this->assertSame([], $game->objects[$healer]->definition()->unsupported);
        $game->activate(0, $healer, 0, ['p:1']);
        $this->resolve();
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $game->addCard(0, self::card('Lightning Bolt'), GameObject::HAND)->id, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(18, $game->players[1]->life, 'One of the three damage was prevented.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));

        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Forest', 2);
        $fox = $this->put(0, 'Reconfigure Lite', GameObject::BATTLEFIELD);
        $bearer = $this->put(0, 'Healer Lite', GameObject::BATTLEFIELD);
        $this->assertSame([], $game->objects[$fox]->definition()->unsupported);
        $game->activate(0, $fox, 0, ["o:{$bearer}"]);
        $this->resolve();
        $this->assertFalse($game->isCreature($game->objects[$fox]), 'Not a creature while attached.');
        $this->assertSame(3, $game->power($game->objects[$bearer]));
        $this->lands(0, 'Forest', 2);
        $game->activate(0, $fox, 1);
        $this->resolve();
        $this->assertTrue($game->isCreature($game->objects[$fox]));
        $this->assertSame(1, $game->power($game->objects[$bearer]));

        $this->lands(0, 'Forest', 2);
        $zenith = $this->put(0, 'Zenith Lite', GameObject::HAND);
        $game->cast(0, $zenith, 0, []);
        $this->resolve();
        $this->assertSame(GameObject::LIBRARY, $this->zone($zenith));
    }

    public function testLookRevealShuffleAndEndStepExile(): void
    {
        $game = $this->newGame();
        foreach (['Look Land Lite', 'Reveal Lands Lite', 'Shuffle Lite', 'Temporary Lite'] as $name) {
            $this->assertSame([], (new \MTGPocket\Game\CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->passUntil(Step::PrecombatMain, 1);
        $this->lands(0, 'Forest', 2);
        $forest = $game->addCard(0, self::card('Forest'), GameObject::LIBRARY)->id;
        $life = $game->players[0]->life;
        $game->cast(0, $this->put(0, 'Look Land Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->assertSame('look', $game->decision(0));
        $this->assertSame('tapped', $game->choiceAwaiting()['to']);
        $game->take(0, [$forest]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($forest));
        $this->assertTrue($game->objects[$forest]->tapped);
        $this->assertSame($life + 2, $game->players[0]->life, 'The sentence after the look still happens.');

        $this->lands(0, 'Forest', 2);
        $land = $game->addCard(0, self::card('Forest'), GameObject::LIBRARY)->id;
        $game->cast(0, $this->put(0, 'Reveal Lands Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->assertContains($land, $game->choiceAwaiting()['eligible']);
        $this->assertFalse($game->choiceAwaiting()['may']);
        $game->take(0, $game->choiceAwaiting()['eligible']);
        $this->assertSame(GameObject::HAND, $this->zone($land));

        $this->lands(0, 'Swamp', 2);
        $dead = $game->addCard(0, self::more('Ward Discard Lite'), GameObject::GRAVEYARD)->id;
        $game->cast(0, $this->put(0, 'Temporary Lite', GameObject::HAND), 0, ["o:{$dead}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($dead));
        $this->assertContains('haste', $game->keywords($game->objects[$dead]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::End, 1);
        $this->assertSame(GameObject::EXILE, $this->zone($dead));
    }

    public function testPathStressAndSeismic(): void
    {
        $game = $this->newGame();
        foreach (['Path Lite', 'Stress Lite', 'Seismic Lite'] as $name) {
            $this->assertSame([], (new \MTGPocket\Game\CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->passUntil(Step::PrecombatMain, 1);
        $game->addCard(1, self::card('Plains'), GameObject::LIBRARY);
        $horror = $this->put(1, 'Healer Lite', GameObject::BATTLEFIELD);
        $lands = count(array_filter($game->permanents(1), fn (GameObject $o) => $o->definition()->isLand()));
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $this->put(0, 'Path Lite', GameObject::HAND), 0, ["o:{$horror}"]);
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(GameObject::EXILE, $this->zone($horror));
        $this->assertCount($lands + 1, array_filter($game->permanents(1), fn (GameObject $o) => $o->definition()->isLand()), 'Its controller searched for a basic land.');

        $this->lands(0, 'Island', 1);
        $this->lands(0, 'Mountain', 2);
        $game->cast(0, $this->put(0, 'Stress Lite', GameObject::HAND), 0, ['-']);
        $this->resolve();
        $this->assertSame('look', $game->decision(0));
        $this->assertCount(2, $game->choiceAwaiting()['cards']);

        $game->take(0, [$game->choiceAwaiting()['cards'][0]]);
        $this->lands(0, 'Forest', 1);
        $owned = count(array_filter($game->permanents(0), fn (GameObject $o) => $o->definition()->isLand()));
        $game->cast(0, $this->put(0, 'Seismic Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->assertCount($owned, $game->choiceAwaiting()['cards'], 'X is the number of lands you control.');
    }

    public function testExtractionAndBribery(): void
    {
        $game = $this->newGame();
        foreach (['Extraction Lite', 'Bribery Lite'] as $name) {
            $this->assertSame([], (new \MTGPocket\Game\CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->passUntil(Step::PrecombatMain, 1);
        $dead = $this->put(1, 'Healer Lite', GameObject::GRAVEYARD);
        $inHand = $this->put(1, 'Healer Lite', GameObject::HAND);
        $inLibrary = $game->addCard(1, self::more('Healer Lite'), GameObject::LIBRARY)->id;
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Extraction Lite', GameObject::HAND), 0, ["o:{$dead}"]);
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::EXILE, GameObject::EXILE], [$this->zone($dead), $this->zone($inHand), $this->zone($inLibrary)]);

        $game->addCard(1, self::more('Ward Discard Lite'), GameObject::LIBRARY);
        $this->lands(0, 'Island', 3);
        $game->cast(0, $this->put(0, 'Bribery Lite', GameObject::HAND), 0, ['p:1']);
        $this->resolve();
        $stolen = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->owner === 1));
        $this->assertCount(1, $stolen);
        $this->assertTrue($stolen[0]->definition()->isCreature());
    }

    public function testTransformWerewolvesAndDayNight(): void
    {
        $game = $this->newGame();
        foreach (['Werewolf Lite // Howler Lite', 'Daybound Lite // Nightbound Lite', 'Flip Lite // Flipped Lite'] as $name) {
            $this->assertSame([], (new \MTGPocket\Game\CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->passUntil(Step::PrecombatMain, 1);
        $flip = $this->put(0, 'Flip Lite // Flipped Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 2);
        $game->activate(0, $flip, 0);
        $this->resolve();
        $this->assertSame('Flipped Lite', $game->objects[$flip]->name());
        $this->assertContains('flying', $game->keywords($game->objects[$flip]));

        $wolf = $this->put(0, 'Werewolf Lite // Howler Lite', GameObject::BATTLEFIELD);
        $day = $this->put(0, 'Daybound Lite // Nightbound Lite', GameObject::BATTLEFIELD);
        $this->assertSame('day', $game->dayNight);
        $this->assertFalse($game->objects[$day]->transformed);
        // Nobody casts a spell in turn 1: the werewolf transforms and it becomes night.
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame('night', $game->dayNight);
        $this->assertSame([4, 'Howler Lite'], [$game->power($game->objects[$wolf]), $game->objects[$wolf]->name()]);
        $this->assertSame('Nightbound Lite', $game->objects[$day]->name());
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame([5, 'night'], [$game->power($game->objects[$day]), $game->dayNight]);

        // Two spells in turn 2: day again, and the Howler turns back.
        $this->lands(1, 'Mountain', 2);
        foreach ([1, 2] as $i) {
            $game->cast(1, $game->addCard(1, self::card('Lightning Bolt'), GameObject::HAND)->id, 0, ['p:0']);
            $this->resolve();
        }
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame('day', $game->dayNight);
        $this->assertSame(['Werewolf Lite // Howler Lite', 'Daybound Lite // Nightbound Lite'], [$game->objects[$wolf]->name(), $game->objects[$day]->name()]);
    }

    public function testDisturbAndFirstMainTransform(): void
    {
        $game = $this->newGame();
        foreach (['Ghost Lite // Spirit Lite', 'Pay Flip Lite // Paid Lite'] as $name) {
            $this->assertSame([], (new \MTGPocket\Game\CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->passUntil(Step::PrecombatMain, 1);
        $ghost = $game->addCard(0, self::more('Ghost Lite // Spirit Lite'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Plains', 2);
        $this->assertContains(['id' => $ghost, 'how' => 'db'], $game->plays(0));
        $game->cast(0, $ghost, 0, [], 'db');
        $this->resolve();
        $this->assertSame(['Spirit Lite', GameObject::BATTLEFIELD], [$game->objects[$ghost]->name(), $this->zone($ghost)]);
        $this->assertContains('flying', $game->keywords($game->objects[$ghost]));
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $game->addCard(0, self::card('Lightning Bolt'), GameObject::HAND)->id, 0, ["o:{$ghost}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($ghost), 'Exiled instead of going to the graveyard.');

        $wolf = $this->put(0, 'Pay Flip Lite // Paid Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Upkeep, 3);
        $this->lands(0, 'Forest', 1);
        $this->passUntil(Step::BeginCombat, 3);
        $this->assertSame([4, 'Paid Lite'], [$game->power($game->objects[$wolf]), $game->objects[$wolf]->name()]);
    }

    public function testSagas(): void
    {
        $game = $this->newGame();
        foreach (['Saga Lite', 'Flip Saga Lite // Saga Dragon Lite'] as $name) {
            $this->assertSame([], (new \MTGPocket\Game\CardDefinition(self::more($name)))->unsupported, $name);
        }
        $this->passUntil(Step::PrecombatMain, 1);
        $this->lands(0, 'Plains', 2);
        $this->lands(0, 'Mountain', 2);
        $life = $game->players[0]->life;
        $saga = $this->put(0, 'Saga Lite', GameObject::HAND);
        $game->cast(0, $saga, 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame([1, $life + 2], [$game->objects[$saga]->counter('lore'), $game->players[0]->life], 'Chapter I as it enters.');
        $flip = $this->put(0, 'Flip Saga Lite // Saga Dragon Lite', GameObject::HAND);
        $game->cast(0, $flip, 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame(19, $game->players[1]->life);

        $this->passUntil(Step::BeginCombat, 3);
        $this->assertSame([2, $life + 4], [$game->objects[$saga]->counter('lore'), $game->players[0]->life], 'Chapter II after the draw step.');
        $this->assertSame(['Saga Dragon Lite', GameObject::BATTLEFIELD], [$game->objects[$flip]->name(), $this->zone($flip)]);
        $this->assertTrue($game->isCreature($game->objects[$flip]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));

        $hand = count($game->players[0]->hand);
        $this->passUntil(Step::BeginCombat, 5);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($saga), 'Sacrificed after chapter III.');
        $this->assertSame($hand + 2, count($game->players[0]->hand), 'Chapter III and the draw step.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($flip), 'The transformed Saga is a creature now, not a Saga.');
    }

    public function testBlockingLimitsHandSizeAndLandSearches(): void
    {
        $game = $this->newGame();
        foreach (['Lure Lite', 'Bully Lite', 'Hand Lite', 'Grave Lite', 'Read Ahead Lite', 'Ramp Lite', 'Cultivate'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $lure = $this->put(0, 'Lure Lite', GameObject::BATTLEFIELD);
        $bully = $this->put(0, 'Bully Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$lure, $bully]);
        $this->assertFalse($game->canBlock($game->objects[$bears], $game->objects[$bully]), 'Power 2 is less than 3.');
        $this->assertTrue($game->canBlock($game->objects[$bears], $game->objects[$lure]));
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, []);
        $this->assertSame([$bears => $lure], $game->blockers, 'Lure Lite must be blocked if able.');

        $hand = $this->put(0, 'Hand Lite', GameObject::BATTLEFIELD);
        $this->assertSame(count($game->players[0]->hand), $game->power($game->objects[$hand]));
        $this->put(0, 'Bully Lite', GameObject::HAND);
        $this->assertSame(count($game->players[0]->hand), $game->toughness($game->objects[$hand]));
        $grave = $this->put(0, 'Grave Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Lure Lite', GameObject::GRAVEYARD);
        $this->put(0, 'Ramp Lite', GameObject::GRAVEYARD);
        $this->assertSame(1, $game->power($game->objects[$grave]));

        $this->passUntil(Step::PostcombatMain, 3);
        $lands = count($game->permanents(0));
        $this->lands(0, 'Forest', 6);
        $ramp = $this->put(0, 'Ramp Lite', GameObject::HAND);
        $game->cast(0, $ramp);
        $this->resolve();
        $this->assertSame($lands + 8, count($game->permanents(0)), 'Two basic lands onto the battlefield.');
        $hand = count($game->players[0]->hand);
        $cultivate = $this->put(0, 'Cultivate', GameObject::HAND);
        $game->cast(0, $cultivate);
        $this->resolve();
        $this->assertSame([$lands + 9, $hand + 1], [count($game->permanents(0)), count($game->players[0]->hand)], 'One onto the battlefield, one into hand.');
    }

    public function testTrainingScalingPartyAndSacrificePing(): void
    {
        $game = $this->newGame();
        foreach (['Training Lite', 'Scaler Lite', 'Trample Counters Lite', 'Party Lite', 'Sac Ping Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $training = $this->put(0, 'Training Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->put(0, 'Trample Counters Lite', GameObject::BATTLEFIELD);
        $scaler = $this->put(0, 'Scaler Lite', GameObject::BATTLEFIELD);
        $this->assertSame([1, 1], [$game->power($game->objects[$scaler]), $game->toughness($game->objects[$scaler])]);
        $game->addCard(0, self::card('Ornithopter'), GameObject::BATTLEFIELD);
        $this->assertSame(2, $game->power($game->objects[$scaler]), 'Two artifacts.');

        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$training, $bears]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$training]->counter('+1/+1'), 'Attacked with a creature with greater power.');
        $this->assertContains('trample', $game->keywords($game->objects[$training]));
        $this->assertNotContains('trample', $game->keywords($game->objects[$bears]));

        $this->passUntil(Step::PostcombatMain, 3);
        $this->lands(0, 'Plains', 3);
        $party = $this->put(0, 'Party Lite', GameObject::HAND);
        $this->assertSame(0, $game->partySize(0), 'No Cleric, Rogue, Warrior or Wizard yet.');
        $this->put(0, 'Healer Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Turn Duelist', GameObject::BATTLEFIELD);
        $this->assertSame(2, $game->partySize(0));
        $life = $this->life(0);
        $game->cast(0, $party);
        $this->resolve();
        $this->assertSame($life + 4, $this->life(0), '{2}{W} with two in the party.');

        $ping = $this->put(0, 'Sac Ping Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 1);
        $game->activate(0, $ping, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::GRAVEYARD], [$this->zone($ping), $this->zone($bears)]);
    }

    public function testAfflictDredgeIncubateAndKi(): void
    {
        $game = $this->newGame();
        foreach (['Afflict Lite', 'Dredge Lite', 'Incubate Lite', 'Ki Lite', 'Arcane Lite', 'Night Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $afflict = $this->put(0, 'Afflict Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$afflict]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$bears => $afflict]);
        $this->resolve();
        $this->assertSame(18, $this->life(1), 'Afflict 2.');

        $dredge = $this->put(0, 'Dredge Lite', GameObject::GRAVEYARD);
        $library = count($game->players[0]->library);
        $this->passUntil(Step::PrecombatMain, 5);
        $this->assertSame(GameObject::HAND, $this->zone($dredge), 'Dredged instead of drawing.');
        $this->assertSame($library - 3, count($game->players[0]->library));

        $this->lands(0, 'Island', 5);
        $incubate = $this->put(0, 'Incubate Lite', GameObject::HAND);
        $game->cast(0, $incubate);
        $this->resolve();
        $this->resolve();
        $incubator = array_values(array_filter($game->objects, fn (GameObject $o) => $o->name() === 'Incubator Token'))[0];
        $this->assertSame(2, $incubator->counter('+1/+1'));
        $this->assertFalse($game->isCreature($incubator));
        $game->activate(0, $incubator->id, 0);
        $this->resolve();
        $this->assertSame(['Phyrexian Token', true, 2], [$incubator->name(), $game->isCreature($incubator), $game->power($incubator)]);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(2, $game->power($game->objects[$incubator->id]));

        $ki = $this->put(0, 'Ki Lite', GameObject::BATTLEFIELD);
        $arcane = $this->put(0, 'Arcane Lite', GameObject::HAND);
        $game->cast(0, $arcane);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->objects[$ki]->counter('ki'));
    }

    public function testBlitzBuybackIngestAndAllies(): void
    {
        $game = $this->newGame();
        foreach (['Blitz Lite', 'Buyback Lite', 'Ingest Lite', 'Ally Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $ingest = $this->put(0, 'Ingest Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 3);
        $blitz = $this->put(0, 'Blitz Lite', GameObject::HAND);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertContains(['id' => $blitz, 'how' => 'blitz'], $game->plays(0));
        $game->cast(0, $blitz, 0, [], 'blitz');
        $this->resolve();
        $this->assertContains('haste', $game->keywords($game->objects[$blitz]));
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$blitz, $ingest]);
        $library = count($game->players[1]->library);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->assertSame([$library - 1, 15], [count($game->players[1]->library), $this->life(1)], 'Ingest exiled the top card.');
        $hand = count($game->players[0]->hand);
        $this->passUntil(Step::Upkeep, 4);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($blitz), 'Sacrificed at the end step.');
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Blitz: it died, so draw a card.');

        $this->passUntil(Step::PrecombatMain, 5);
        $buyback = $this->put(0, 'Buyback Lite', GameObject::HAND);
        $this->lands(0, 'Mountain', 3);
        $game->cast(0, $buyback, 0, ['p:1'], 'buyback');
        $this->resolve();
        $this->assertSame([GameObject::HAND, 14], [$this->zone($buyback), $this->life(1)]);

        $this->lands(0, 'Forest', 4);
        $ally = $this->put(0, 'Ally Lite', GameObject::HAND);
        $game->cast(0, $ally);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->objects[$ally]->counter('+1/+1'));
        $other = $this->put(0, 'Ally Lite', GameObject::HAND);
        $game->cast(0, $other);
        $this->resolve();
        while ($game->stack !== [] || $game->triggerAwaitingTargets() !== null) {
            $this->resolve();
        }
        $this->assertSame([2, 1], [$game->objects[$ally]->counter('+1/+1'), $game->objects[$other]->counter('+1/+1')]);
    }

    public function testConspireAndAwaken(): void
    {
        $game = $this->newGame();
        foreach (['Conspire Lite', 'Awaken Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Blitz Lite', GameObject::BATTLEFIELD);
        $this->assertNotContains(['id' => $conspire = $this->put(0, 'Conspire Lite', GameObject::HAND), 'how' => 'conspire'], $game->plays(0), 'Only one red creature.');
        $this->put(0, 'Blitz Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $this->assertContains(['id' => $conspire, 'how' => 'conspire'], $game->plays(0));
        $game->cast(0, $conspire, 0, ['p:1'], 'conspire');
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame(18, $this->life(1), 'Copied once.');
        $this->assertSame(2, count(array_filter($game->permanents(0), fn (GameObject $o) => $o->tapped && $game->isCreature($o))));

        $this->lands(0, 'Forest', 4);
        $awaken = $this->put(0, 'Awaken Lite', GameObject::HAND);
        $game->cast(0, $awaken, 0, [], 'awaken');
        $this->resolve();
        $awakened = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->awakened))[0];
        $this->assertTrue($game->isCreature($awakened));
        $this->assertSame([2, 2], [$game->power($awakened), $game->toughness($awakened)]);
        $this->assertContains('haste', $game->keywords($awakened));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertTrue($game->isCreature($game->objects[$awakened->id]));
    }

    public function testConniveTappedDiscountGraveLandsAndPowerTargets(): void
    {
        $game = $this->newGame();
        foreach (['Connive Lite', 'Tapped Discount Lite', 'Grave Lands Lite', 'Big Game Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Connive: draw, then discard; a nonland discard puts a +1/+1 counter on it.
        $this->lands(0, 'Island', 2);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $connive = $this->put(0, 'Connive Lite', GameObject::HAND);
        $game->cast(0, $connive);
        $this->resolve();
        $this->resolve();
        $this->assertSame('discard', $game->choiceAwaiting()['type']);
        $game->discard(0, [$bolt]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertSame(1, $game->objects[$connive]->counter('+1/+1'));

        // {2} less only against a tapped creature.
        $bear = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Swamp', 2);
        $discount = $this->put(0, 'Tapped Discount Lite', GameObject::HAND);
        try {
            $game->cast(0, $discount, 0, ["o:{$bear}"]);
            $this->fail('An untapped target pays full price.');
        } catch (GameException) {
        }
        $game->objects[$bear]->tapped = true;
        $game->cast(0, $discount, 0, ["o:{$bear}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bear));

        // Power 4 or greater.
        $bear = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Swamp', 2);
        $bigGame = $this->put(0, 'Big Game Lite', GameObject::HAND);
        $this->assertFalse($game->isLegalTarget('creature_power_ge_4', "o:{$bear}", 0));
        $game->objects[$bear]->addCounters('+1/+1', 2);
        $game->cast(0, $bigGame, 0, ["o:{$bear}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bear));

        // Lands from the graveyard, still one a turn.
        $forest = $game->addCard(0, self::card('Forest'), GameObject::GRAVEYARD)->id;
        $this->assertNotContains(['id' => $forest, 'how' => ''], $game->plays(0));
        $this->put(0, 'Grave Lands Lite', GameObject::BATTLEFIELD);
        $this->assertContains(['id' => $forest, 'how' => ''], $game->plays(0));
        $game->playLand(0, $forest);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($forest));
    }

    public function testTutorsRegrowthAndOptionalLoot(): void
    {
        $game = $this->newGame();
        foreach (['Tutor Lite', 'Demonic Lite', 'Regrowth Lite', 'Loot Enter Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // A tutor offers one card of each name that fits; the rest stay and the library is shuffled.
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $size = count($game->players[0]->library);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Tutor Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([$bears], $game->choiceAwaiting()['eligible']);
        $this->assertTrue($game->choiceAwaiting()['may'], 'May fail to find a creature card.');
        $game->take(0, [$bears]);
        $this->assertSame(GameObject::HAND, $this->zone($bears));
        $this->assertCount($size - 1, $game->players[0]->library);

        // Any card: Forest is the only name left, and it must be found.
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Demonic Lite', GameObject::HAND));
        $this->resolve();
        $this->assertCount(1, $game->choiceAwaiting()['eligible']);
        $this->assertFalse($game->choiceAwaiting()['may']);
        $forest = $game->choiceAwaiting()['eligible'][0];
        $game->take(0, [$forest]);
        $this->assertSame(GameObject::HAND, $this->zone($forest));

        // Return an instant or sorcery card from your graveyard.
        $bolt = $game->addCard(0, self::card('Lightning Bolt'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Regrowth Lite', GameObject::HAND));
        $this->resolve();
        $this->assertFalse($game->isLegalTarget('instant_sorcery_card_yours', "o:{$bears}", 0));
        $game->chooseTriggerTargets(0, ["o:{$bolt}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($bolt));

        // You may discard a card; if you do, draw one.
        $this->lands(0, 'Mountain', 4);
        $game->cast(0, $this->put(0, 'Loot Enter Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $this->assertTrue($game->choiceAwaiting()['may']);
        $hand = count($game->players[0]->hand);
        $game->discard(0, []);
        $this->assertCount($hand, $game->players[0]->hand, 'Nothing discarded, nothing drawn.');
        $game->cast(0, $this->put(0, 'Loot Enter Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $game->discard(0, [$bolt]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertCount($hand, $game->players[0]->hand, 'Discarded one, drew one.');
    }

    public function testExtraTurnAndGraveyardExileCost(): void
    {
        $game = $this->newGame();
        foreach (['Extra Turn Lite', 'Grave Exile Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // An additional cost: exile a creature card from your graveyard, the cheapest.
        $this->lands(0, 'Swamp', 1);
        $zombie = $this->put(0, 'Grave Exile Lite', GameObject::HAND);
        $this->assertNotContains(['id' => $zombie, 'how' => ''], $game->plays(0), 'No creature card in the graveyard.');
        $game->addCard(0, self::card('Lightning Bolt'), GameObject::GRAVEYARD);
        $this->assertNotContains(['id' => $zombie, 'how' => ''], $game->plays(0), 'An instant does not pay for it.');
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $game->cast(0, $zombie);
        $this->assertSame(GameObject::EXILE, $this->zone($bears));
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($zombie));

        // Alice takes the next turn too.
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Extra Turn Lite', GameObject::HAND));
        $this->resolve();
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(0, $game->active);
        $this->passUntil(Step::Upkeep, 3);
        $this->assertSame(1, $game->active);
    }

    public function testUnearth(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $hellspark = $game->addCard(0, self::more('Hellspark Lite'), GameObject::GRAVEYARD)->id;
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertContains(['id' => $hellspark, 'how' => 'unearth'], $game->plays(0));
        $game->unearth(0, $hellspark);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($hellspark));
        $this->assertContains('haste', $game->keywords($game->objects[$hellspark]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::End, 3);
        $this->assertSame(GameObject::EXILE, $this->zone($hellspark), 'Exiled at the end step.');

        // Exiled instead of dying, too.
        $again = $game->addCard(0, self::more('Hellspark Lite'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Mountain', 1);
        $this->passUntil(Step::PrecombatMain, 5);
        $game->unearth(0, $again);
        $this->resolve();
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ["o:{$again}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($again));
    }

    public function testRebound(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $shock = $this->put(0, 'Staggershock', GameObject::HAND);
        $game->cast(0, $shock, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(18, $this->life(1));
        $this->assertSame(GameObject::EXILE, $this->zone($shock));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame([], array_filter($game->plays(0), fn (array $play) => $play['id'] === $shock), 'Not until your next upkeep.');

        $this->passUntil(Step::Upkeep, 3);
        $this->assertContains(['id' => $shock, 'how' => 'rb'], $game->plays(0));
        $game->cast(0, $shock, 0, ['p:1'], 'rb');
        $this->resolve();
        $this->assertSame(16, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($shock), 'Rebound only from the hand.');
    }

    public function testEnergy(): void
    {
        $game = $this->newGame();
        $cub = $this->put(0, 'Longtusk Cub', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 5);
        $rhino = $this->put(0, 'Thriving Rhino', GameObject::HAND);
        $game->cast(0, $rhino);
        $this->resolve();
        $this->resolve();
        $this->assertSame(2, $game->players[0]->energy);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(2, $game->players[0]->energy);
        $game->activate(0, $cub, 0);
        $this->resolve();
        $this->assertSame(0, $game->players[0]->energy);
        $this->assertSame(1, $game->objects[$cub]->counter('+1/+1'));
        $this->assertFalse($game->canActivate(0, $cub, 0), 'No energy left.');

        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$cub]);
        $this->passUntil(Step::CombatDamage, 3);
        $this->resolve();
        $this->assertSame(2, $game->players[0]->energy);
    }

    public function testSaddle(): void
    {
        $game = $this->newGame();
        $mustang = $this->put(0, 'Brightfield Mustang', GameObject::BATTLEFIELD);
        $squire = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$mustang]);
        $this->resolve();
        $this->assertSame(0, $game->objects[$mustang]->counter('+1/+1'), 'Not saddled: no trigger.');

        $this->passUntil(Step::PrecombatMain, 5);
        $game->activate(0, $mustang, 0);
        $this->assertTrue($game->objects[$squire]->tapped);
        $this->resolve();
        $this->assertContains('saddled', $game->keywords($game->objects[$mustang]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$mustang]);
        $this->assertTrue($game->objects[$mustang]->tapped);
        $this->resolve();
        $this->assertFalse($game->objects[$mustang]->tapped, 'Untapped by its trigger.');
        $this->assertSame(1, $game->objects[$mustang]->counter('+1/+1'));
    }

    public function testBestow(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 5);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $eidolon = $this->put(0, 'Hopeful Eidolon', GameObject::HAND);
        $this->assertSame([], self::read('Hopeful Eidolon')->unsupported);
        $this->assertContains(['id' => $eidolon, 'how' => 'bestow'], $game->plays(0));
        $game->cast(0, $eidolon, 0, ["o:{$bears}"], 'bestow');
        $this->resolve();
        $this->assertSame($bears, $game->objects[$eidolon]->attachedTo);
        $this->assertFalse($game->isCreature($game->objects[$eidolon]));
        $this->assertSame(3, $game->power($game->objects[$bears]));
        $this->assertContains('lifelink', $game->keywords($game->objects[$bears]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));

        $this->lands(1, 'Mountain', 1);
        $bolt = $this->hand(1, 'Lightning Bolt');
        $game->pass(0);
        $game->cast(1, $bolt, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($eidolon), 'It stays as a creature.');
        $this->assertTrue($game->isCreature($game->objects[$eidolon]));
        $this->assertSame(1, $game->power($game->objects[$eidolon]));
    }

    public function testChosenTypeAndColor(): void
    {
        $game = $this->newGame();
        $elves = array_map(fn () => $this->battlefield(0, 'Llanowar Elves'), range(1, 3));
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->lands(0, 'Forest', 3);
        $banner = $this->put(0, 'Patchwork Banner', GameObject::HAND);
        $game->cast(0, $banner);
        $this->resolve();
        $this->assertSame('Elf', $game->objects[$banner]->chosen, 'The type most of their creatures have.');
        $this->assertSame(2, $game->power($game->objects[$elves[0]]));
        $this->assertSame(2, $game->power($game->objects[$bears]));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame('Elf', $game->objects[$banner]->chosen);

        $room = $this->put(0, 'Room of Refuge Lite', GameObject::BATTLEFIELD);
        $this->assertSame('G', $game->objects[$room]->chosen);
        $this->assertSame(['G'], $game->manaSources(0)[$room]['colors']);
    }

    public function testSoulshift(): void
    {
        $game = $this->newGame();
        $death = $this->put(0, 'Scuttling Death', GameObject::BATTLEFIELD);
        $akuba = $game->addCard(0, self::more('Wicked Akuba Lite'), GameObject::GRAVEYARD)->id;
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ["o:{$death}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($akuba), 'The only Spirit with mana value 4 or less; not itself (5) or the Bears.');
    }

    public function testCurses(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Swamp', 5);
        $theirs = $this->battlefield(1, 'Llanowar Elves');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $curse = $this->put(0, 'Curse of the Pierced Heart', GameObject::HAND);
        $this->assertSame(['player'], self::read('Curse of the Pierced Heart')->targetKinds());
        $game->cast(0, $curse, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(1, $game->objects[$curse]->enchantedPlayer);
        $hold = $this->put(0, "Curse of Death's Hold", GameObject::HAND);
        $game->cast(0, $hold, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($theirs), 'A 1/1 dies.');
        $this->assertSame(1, $game->power($game->objects[$bears]));
        $this->assertSame(2, $game->power($game->objects[$mine]), 'Only their creatures.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(1, $game->objects[$curse]->enchantedPlayer);

        $this->passUntil(Step::Upkeep, 2);
        $this->resolve();
        $this->assertSame(19, $this->life(1));
        $this->assertSame(20, $this->life(0));
    }

    public function testClassLevels(): void
    {
        $game = $this->newGame();
        $class = $this->put(0, 'Druid Class Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->lands(0, 'Forest', 8);
        $this->assertSame(2, $game->power($game->objects[$bears]), 'Level 1 has no anthem.');
        $this->assertFalse($game->canActivate(0, $class, 1), 'Level 3 needs level 2 first.');
        $game->activate(0, $class, 0);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$bears]));
        $this->assertFalse($game->canActivate(0, $class, 0), 'Level 2 only once.');
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $hand = count($game->players[0]->hand);
        $game->activate(0, $class, 1);
        $this->resolve();
        $this->resolve();
        $this->assertCount($hand + 2, $game->players[0]->hand, 'Its level 3 trigger draws two.');
    }

    public function testStation(): void
    {
        $game = $this->newGame();
        $frigate = $this->put(0, 'Lumen-Class Frigate', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->assertSame([], self::read('Lumen-Class Frigate')->unsupported);
        $this->assertFalse($game->isCreature($game->objects[$frigate]));
        $this->assertSame(2, $game->power($game->objects[$bears]));
        $game->activate(0, $frigate, 0);
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->resolve();
        $this->assertSame(2, $game->objects[$frigate]->counter('charge'));
        $this->assertSame(3, $game->power($game->objects[$bears]), 'Its 2+ ability.');
        $this->assertFalse($game->isCreature($game->objects[$frigate]));

        $game->objects[$frigate]->addCounters('charge', 10);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertTrue($game->isCreature($game->objects[$frigate]), 'A creature at 12+.');
        $this->assertContains('lifelink', $game->keywords($game->objects[$frigate]));
        $this->assertFalse($game->canActivate(0, $frigate, 0), 'No other untapped creature.');
    }

    public function testStartYourEnginesAndMaxSpeed(): void
    {
        $game = $this->newGame();
        $bash = $this->put(0, 'Burnout Bashtronaut', GameObject::BATTLEFIELD);
        $pilot = $this->put(0, 'Speedway Lite', GameObject::BATTLEFIELD);
        $this->assertSame([], self::read('Burnout Bashtronaut')->unsupported);
        $this->assertSame([], self::read('Speedway Lite')->unsupported);
        $bolt = function (): void {
            $this->lands(0, 'Mountain', 1);
            $this->game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ['p:1']);
            $this->resolve();
        };
        $bolt();
        $this->assertSame(2, $game->players[0]->speed, 'It starts at 1, then Bob lost life.');
        $bolt();
        $this->assertSame(2, $game->players[0]->speed, 'Once each turn.');
        $this->assertFalse($game->canActivate(0, $pilot, 0), 'Not before max speed.');

        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Mountain', 1);
        $game->cast(1, $this->hand(1, 'Lightning Bolt'), 0, ['p:0']);
        $this->resolve();
        $this->assertSame(2, $game->players[0]->speed, 'Only on your own turns.');

        foreach ([3 => 3, 5 => 4, 7 => 4] as $turn => $speed) {
            $this->passUntil(Step::PrecombatMain, $turn);
            $bolt();
            $this->assertSame($speed, $game->players[0]->speed, 'Max speed is 4.');
        }
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(4, $game->players[0]->speed);
        $this->assertContains('double strike', $game->keywords($game->objects[$bash]));
        $this->assertSame(3, $game->power($game->objects[$pilot]));
        $this->assertContains('menace', $game->keywords($game->objects[$pilot]));
        $this->assertTrue($game->canActivate(0, $pilot, 0));
    }

    public function testUpToTargets(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Frost Breath')->unsupported);
        $this->assertSame(['?creature', '?creature'], self::read('Frost Breath')->targetKinds());
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $other = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Island', 5);
        $game->cast(0, $this->put(0, 'Frost Breath', GameObject::HAND), 0, ["o:{$bears}", '-']);
        $this->resolve();
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->assertFalse($game->objects[$other]->tapped, 'Only one target was chosen.');
        $game->cast(0, $this->put(0, 'Stun Lite', GameObject::HAND), 0, ["o:{$other}", '-']);
        $this->resolve();
        $this->assertSame(1, $game->objects[$other]->counter('stun'));

        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertTrue($game->objects[$bears]->tapped, "It doesn't untap during Bob's next untap step.");
        $this->assertTrue($game->objects[$other]->tapped, 'A stun counter is removed instead.');
        $this->assertSame(0, $game->objects[$other]->counter('stun'));
        $this->passUntil(Step::PrecombatMain, 4);
        $this->assertFalse($game->objects[$bears]->tapped);
        $this->assertFalse($game->objects[$other]->tapped);
    }

    public function testUpToOneTargetWithNothingToTarget(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $oblivion = $this->put(0, 'Outpace Oblivion', GameObject::HAND);
        $game->cast(0, $oblivion, 0, []);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($oblivion));
        $this->assertSame(1, $game->players[0]->speed);
        $this->resolve(); // Its enters trigger, with no target.
        $this->assertSame([], $game->stack);
        $this->assertSame(20, $this->life(1));
    }

    public function testModularFabricateAndLivingWeapon(): void
    {
        $game = $this->newGame();
        $worker = $this->put(0, 'Arcbound Worker', GameObject::BATTLEFIELD);
        $other = $this->put(0, 'Arcbound Worker', GameObject::BATTLEFIELD);
        $this->assertSame(1, $game->power($game->objects[$worker]));
        $this->lands(1, 'Mountain', 1);
        $this->passUntil(Step::PrecombatMain, 2);
        $game->cast(1, $this->hand(1, 'Shock'), 0, ["o:{$worker}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($worker));
        $this->resolve(); // Modular, onto the only artifact creature.
        $this->assertSame(2, $game->objects[$other]->counter('+1/+1'));

        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Plains', 4);
        $artisan = $this->put(0, 'Glint-Sleeve Artisan', GameObject::HAND);
        $game->cast(0, $artisan, 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$artisan]));
        $husk = $this->put(0, 'Flayer Husk', GameObject::HAND);
        $game->cast(0, $husk, 0, []);
        $this->resolve();
        $this->resolve();
        $germ = $game->objects[$game->objects[$husk]->attachedTo];
        $this->assertSame('Phyrexian Germ Token', $germ->name());
        $this->assertSame(1, $game->toughness($germ));
    }

    public function testRenownEvolveAndExtort(): void
    {
        $game = $this->newGame();
        $blade = $this->put(0, 'Topan Freeblade', GameObject::BATTLEFIELD);
        $raptor = $this->put(0, 'Cloudfin Raptor', GameObject::BATTLEFIELD);
        $this->put(0, 'Syndic of Tithes', GameObject::BATTLEFIELD);
        foreach ([$blade, $raptor] as $id) {
            $game->objects[$id]->sick = false;
        }
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Akrasan Squire', GameObject::HAND), 0, []);
        $this->resolve(); // Extort, paid with the other Plains.
        $this->assertSame(19, $this->life(1));
        $this->assertSame(21, $this->life(0));
        $this->resolve(); // The Squire.
        $this->resolve(); // Evolve.
        $this->assertSame(1, $game->objects[$raptor]->counter('+1/+1'));

        $this->passUntil(Step::DeclareAttackers);
        $game->declareAttackers(0, [$blade]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertTrue($game->objects[$blade]->renowned);
        $this->assertSame(1, $game->objects[$blade]->counter('+1/+1'));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertTrue($game->objects[$blade]->renowned);
    }

    public function testStaticRulesChanges(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Explore Lite', GameObject::BATTLEFIELD);
        $duelist = $this->put(0, 'Turn Duelist', GameObject::BATTLEFIELD);
        $this->put(0, 'Reliquary Tower', GameObject::BATTLEFIELD);
        $game->playLand(0, $this->hand(0, 'Forest'));
        $game->playLand(0, $this->hand(0, 'Forest'));
        $this->assertFalse($game->canPlayLand(0, $this->hand(0, 'Forest')));
        $this->assertContains('first strike', $game->keywords($game->objects[$duelist]));
        for ($i = 0; $i < 9; $i++) {
            $this->hand(0, 'Forest');
        }
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertGreaterThan(7, count($game->players[0]->hand), 'No maximum hand size.');
        $this->assertNotContains('first strike', $game->keywords($game->objects[$duelist]));
    }

    public function testLifeGainUpToTwoAndImprovise(): void
    {
        $game = $this->newGame();
        $pridemate = $this->put(0, "Ajani's Pridemate", GameObject::BATTLEFIELD);
        $this->assertSame([], self::read("Ajani's Pridemate")->unsupported);
        $this->put(0, 'Syndic of Tithes', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Akrasan Squire', GameObject::HAND), 0, []);
        $this->resolve(); // Extort.
        $this->resolve(); // Pridemate.
        $this->assertSame(1, $game->objects[$pridemate]->counter('+1/+1'));
        $this->resolve(); // The Squire.

        $this->assertSame(['?creature_card_yours', '?creature_card_yours'], self::read('Grim Return Lite')->targetKinds());
        $dead = $this->put(0, 'Akrasan Squire', GameObject::GRAVEYARD);
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->put(0, 'Grim Return Lite', GameObject::HAND), 0, ["o:{$dead}", '-']);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($dead));

        $this->lands(0, 'Island', 2);
        $this->put(0, 'Arcbound Worker', GameObject::BATTLEFIELD);
        $this->put(0, 'Arcbound Worker', GameObject::BATTLEFIELD);
        $this->put(0, 'Arcbound Worker', GameObject::BATTLEFIELD);
        $engineer = $this->put(0, 'Reverse Engineer', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $engineer, 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame($hand + 2, count($game->players[0]->hand), 'Three artifacts paid for {3}.');
    }

    public function testAdaptMonstrosityRiotAndVanishing(): void
    {
        $game = $this->newGame();
        $adapt = $this->put(0, 'Adapt Lite', GameObject::BATTLEFIELD);
        $monster = $this->put(0, 'Monster Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 6);
        $this->lands(0, 'Mountain', 10);
        $game->activate(0, $adapt, 0);
        $this->resolve();
        $game->activate(0, $adapt, 0);
        $this->resolve();
        $this->assertSame(2, $game->objects[$adapt]->counter('+1/+1'), 'Adapt does nothing with counters already on it.');
        $game->activate(0, $monster, 0);
        $this->resolve();
        $this->resolve(); // Becomes monstrous.
        $this->assertTrue($game->objects[$monster]->monstrous);
        $this->assertSame(18, $this->life(1));
        $game->activate(0, $monster, 0);
        $this->resolve();
        $this->assertSame(1, $game->objects[$monster]->counter('+1/+1'));

        $riot = $this->put(0, 'Riot Lite', GameObject::HAND);
        $game->cast(0, $riot, 0, []);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$riot]));
        $vanishing = $this->put(0, 'Vanishing Lite', GameObject::HAND);
        $game->cast(0, $vanishing, 0, []);
        $this->resolve();
        $this->assertSame(2, $game->objects[$vanishing]->counter('time'));
        $this->passUntil(Step::Draw, 3);
        $this->assertSame(1, $game->objects[$vanishing]->counter('time'));
        $this->passUntil(Step::Draw, 5);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($vanishing));
    }

    public function testExploreMentorExploitAndEndStepSacrifice(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 2);
        $walker = $this->put(0, 'Merfolk Branchwalker', GameObject::HAND);
        $top = end($game->players[0]->library);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $walker, 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($top), 'The top card was a Forest.');
        $this->assertSame($hand, count($game->players[0]->hand));

        $mentor = $this->put(0, 'Mentor Lite', GameObject::BATTLEFIELD);
        $squire = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $game->objects[$mentor]->sick = $game->objects[$squire]->sick = false;
        $this->passUntil(Step::DeclareAttackers);
        $game->declareAttackers(0, [$mentor, $squire]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$squire]->counter('+1/+1'));

        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Swamp', 6);
        $fodder = $this->put(0, 'Akrasan Squire', GameObject::BATTLEFIELD);
        $exploit = $this->put(0, 'Exploit Lite', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $exploit, 0, []);
        $this->resolve();
        $this->resolve(); // Exploit: the new 1/1 Squire.
        $this->resolve(); // Draw two.
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($squire), 'The 1/1 goes first.');
        $this->assertSame($hand + 1, count($game->players[0]->hand));

        $scrap = $this->put(0, 'Scrap Lite', GameObject::HAND);
        $this->assertSame([], self::read('Scrap Lite')->unsupported);
        $game->cast(0, $scrap, 0, []);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($walker), 'The weakest creature.');

        $ball = $this->put(1, 'Ball Lightning', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Cleanup);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($ball), "Also at the end of Alice's turn.");
    }

    public function testExileUntilLeavesAndControlMagic(): void
    {
        $game = $this->newGame();
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $other = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Plains', 3);
        $this->lands(0, 'Island', 5);
        $banisher = $this->put(0, 'Banisher Lite', GameObject::HAND);
        $game->cast(0, $banisher, 0, []);
        $this->resolve();
        $game->chooseTriggerTargets(0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($bears));
        $control = $this->put(0, 'Mind Control', GameObject::HAND);
        $game->cast(0, $control, 0, ["o:{$other}"]);
        $this->resolve();
        $this->assertSame(0, $game->objects[$other]->controller);

        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->lands(1, 'Forest', 2);
        $this->passUntil(Step::PrecombatMain, 2);
        $game->cast(1, $this->put(1, 'Naturalize Lite', GameObject::HAND), 0, ["o:{$banisher}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears), 'Back when the Banisher leaves.');
        $this->assertSame(1, $game->objects[$bears]->controller);
        $game->cast(1, $this->put(1, 'Naturalize Lite', GameObject::HAND), 0, ["o:{$control}"]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$other]->controller, 'Back to its owner when the Aura leaves.');
    }

    public function testFirebendingGraftDevourUnleashFogAndOil(): void
    {
        $game = $this->newGame();
        $bender = $this->put(0, 'Firebending Lite', GameObject::BATTLEFIELD);
        $game->objects[$bender]->sick = false;
        $this->passUntil(Step::DeclareAttackers);
        $game->declareAttackers(0, [$bender]);
        $this->resolve();
        $this->assertSame(2, $game->players[0]->manaPool->total());

        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Forest', 6);
        $this->lands(0, 'Plains', 1);
        $initiate = $this->put(0, 'Simic Initiate', GameObject::BATTLEFIELD);
        $this->assertSame(1, $game->power($game->objects[$initiate]));
        $squire = $this->put(0, 'Akrasan Squire', GameObject::HAND);
        $game->cast(0, $squire, 0, []);
        $this->resolve();
        $this->resolve(); // Graft.
        $this->assertSame(1, $game->objects[$squire]->counter('+1/+1'));
        $this->assertSame(0, $game->power($game->objects[$initiate]));

        $this->put(0, 'Rakdos Cackler', GameObject::BATTLEFIELD);
        $oil = $this->put(0, 'Oil Lite', GameObject::BATTLEFIELD);
        $this->assertSame(3, $game->objects[$oil]->counter('oil'));
        $this->lands(0, 'Mountain', 3);
        $devour = $this->put(0, 'Devour Lite', GameObject::HAND);
        $game->cast(0, $devour, 0, []);
        $this->resolve();
        $this->assertSame(0, $game->objects[$devour]->counter('+1/+1'), 'Devour eats only tokens.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($squire));

        $fog = $this->put(0, 'Fog', GameObject::HAND);
        $game->cast(0, $fog, 0, []);
        $this->resolve();
        $this->assertSame($game->turn, $game->fogTurn);
    }

    public function testUnleashCantBlockAndLeylines(): void
    {
        $game = $this->newGame();
        $this->assertSame(1, self::read('Rakdos Cackler')->entersWithCounters);
        $cackler = $this->put(1, 'Rakdos Cackler', GameObject::BATTLEFIELD);
        $attacker = $this->battlefield(0, 'Grizzly Bears');
        $game->objects[$attacker]->sick = false;
        $this->passUntil(Step::DeclareAttackers);
        $game->declareAttackers(0, [$attacker]);
        $this->assertSame(2, $game->power($game->objects[$cackler]));
        $this->assertFalse($game->canBlock($game->objects[$cackler], $game->objects[$attacker]));

        $deck = array_fill(0, 27, self::more('Leyline Lite'));
        $game = Game::start('game-2', [['id' => '1', 'name' => 'Alice', 'cards' => $deck], ['id' => '2', 'name' => 'Bob', 'cards' => $deck]], 'leyline');
        $game->keep(0);
        $game->keep(1);
        $this->assertCount(7, $game->permanents(0));
        $this->assertSame([], $game->players[0]->hand);
    }

    public function testSplitSecondUmbraArmorAndWraths(): void
    {
        $game = $this->newGame();
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Plains', 5);
        $this->lands(0, 'Mountain', 2);
        $umbra = $this->put(0, 'Hyena Umbra', GameObject::HAND);
        $game->cast(0, $umbra, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertContains('first strike', $game->keywords($game->objects[$bears]));

        $game->cast(0, $this->put(0, 'Sudden Shock', GameObject::HAND), 0, ["o:{$theirs}"]);
        $this->lands(1, 'Mountain', 1);
        $game->pass(0);
        $this->assertFalse($game->canCast(1, $this->hand(1, 'Lightning Bolt')), 'Split second.');
        $game->pass(1);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($theirs));

        $game->cast(0, $this->put(0, 'Day of Judgment', GameObject::HAND), 0, []);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears), 'Umbra armor.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($umbra));
    }

    public function testCostReductionDelveExileIfDiesAndGraveyardTriggers(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Electromancer Lite', GameObject::BATTLEFIELD);
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->put(0, 'Lava Coil', GameObject::HAND), 0, ["o:{$theirs}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($theirs), 'Exiled instead, and {1} cheaper.');

        foreach (range(1, 6) as $i) {
            $this->put(0, 'Akrasan Squire', GameObject::GRAVEYARD);
        }
        $this->lands(0, 'Island', 1);
        $cruise = $this->put(0, 'Treasure Cruise', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $cruise, 0, []);
        $this->resolve();
        $this->assertSame($hand + 2, count($game->players[0]->hand));
        $this->assertCount(2, $game->players[0]->graveyard, 'Six cards delved (it costs {1} less), so one is left beside the Cruise.');

        $junk = $this->put(0, 'Junk Lite', GameObject::BATTLEFIELD);
        $hand = count($game->players[0]->hand);
        $game->activate(0, $junk, 0);
        $this->resolve();
        $this->resolve();
        $this->assertSame($hand + 1, count($game->players[0]->hand));
    }

    public function testAnthems(): void
    {
        $game = $this->newGame();
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->put(0, 'Glorious Anthem', GameObject::BATTLEFIELD);
        $marshal = $this->put(0, 'Benalish Marshal', GameObject::BATTLEFIELD);
        $this->assertSame(4, $game->power($game->objects[$bears]));
        $this->assertSame(4, $game->power($game->objects[$marshal]), 'Only the Anthem, not its own bonus.');
        $this->assertSame(2, $game->power($game->objects[$theirs]));
    }

    public function testMillAndArtifactTokens(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $scour = $this->put(0, 'Thought Scour', GameObject::HAND);
        $game->cast(0, $scour, 0, ['p:1']);
        $this->resolve();
        $this->assertCount(2, $game->players[1]->graveyard);
        $this->assertCount(1, $game->players[0]->hand);

        $this->lands(0, 'Swamp', 4);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $pie = $this->put(0, 'Bake into a Pie', GameObject::HAND);
        $game->cast(0, $pie, 0, ["o:{$bears}"]);
        $this->resolve();
        $food = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Food'));
        $this->assertCount(1, $food);
        $this->assertCount(1, $food[0]->definition()->activated);

        $this->lands(0, 'Mountain', 1);
        $rich = $this->put(0, 'Strike It Rich', GameObject::HAND);
        $game->cast(0, $rich);
        $this->resolve();
        $treasure = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Treasure'))[0];
        $this->assertTrue($treasure->definition()->manaAbility['sacrifice']);
        // The Treasure is the only untapped source left, and it goes away once used.
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ['p:1']);
        $this->assertNotSame(GameObject::BATTLEFIELD, $treasure->zone);

        $this->lands(0, 'Plains', 1);
        $inspector = $this->put(0, 'Thraben Inspector', GameObject::HAND);
        $this->resolve();
        $game->cast(0, $inspector);
        $this->resolve();
        $this->resolve();
        $this->assertCount(1, array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Clue'));
    }

    public function testActOfTreason(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 3);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $game->objects[$bears]->tapped = true;
        $treason = $this->put(0, 'Act of Treason', GameObject::HAND);
        $game->cast(0, $treason, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(0, $game->objects[$bears]->controller);
        $this->assertFalse($game->objects[$bears]->tapped);
        // A saved game remembers whose it is.
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertSame(1, $game->objects[$bears]->borrowedFrom);
        $this->passUntil(Step::DeclareAttackers);
        $this->assertContains($bears, $game->attackCandidates(), 'It gained haste.');
        $game->declareAttackers(0, [$bears]);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(1, $game->objects[$bears]->controller, 'Back at end of turn.');

        // Borrowed from seat 0 is saved too.
        $object = $game->objects[$bears];
        $object->borrowedFrom = 0;
        $this->assertSame(0, GameObject::fromArray($object->toArray())->borrowedFrom);
    }

    public function testAdditionalCosts(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 1);
        $rites = $this->put(0, 'Village Rites', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $rites), 'No creature to sacrifice.');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $giant = $this->battlefield(0, 'Hill Giant');
        $game->cast(0, $rites);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears), 'The weakest creature.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($giant));
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);

        $this->lands(0, 'Mountain', 2);
        $voice = $this->put(0, 'Tormenting Voice', GameObject::HAND);
        $game->cast(0, $voice);
        $this->assertCount(1, $game->players[0]->hand);
        $this->resolve();
        $this->assertCount(3, $game->players[0]->hand);
    }

    public function testThoughtseize(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 1);
        $bolt = $this->hand(1, 'Lightning Bolt');
        $bears = $this->hand(1, 'Grizzly Bears');
        $this->hand(1, 'Forest');
        $seize = $this->put(0, 'Thoughtseize', GameObject::HAND);
        $game->cast(0, $seize, 0, ['p:1']);
        $this->resolve();
        $this->assertSame('discard', $game->decision(0));
        $this->assertNull($game->decision(1));
        $this->assertEqualsCanonicalizing([$bolt, $bears], $game->choiceAwaiting()['cards']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $game->discard(0, [$bears]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame(GameObject::HAND, $this->zone($bolt));
        $this->assertSame(18, $this->life(0));
    }

    public function testReturningCardsFromTheGraveyard(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 6);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $theirs = $game->addCard(1, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $rites = $this->put(0, 'Unburial Rites', GameObject::HAND);
        $this->assertSame(["o:{$bears}"], $game->targetOptions(0, 'creature_card_yours'));
        $game->cast(0, $rites, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($theirs));

        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $raise = $this->put(0, 'Raise Dead', GameObject::HAND);
        $game->cast(0, $raise, 0, ["o:{$giant}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($giant));
    }

    public function testConvoke(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 1);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $elves = $this->battlefield(0, 'Llanowar Elves');
        $game->objects[$elves]->sick = true;
        $flicker = $this->put(0, 'Ghostly Flicker Lite', GameObject::HAND);
        $this->assertTrue($game->canCast(0, $flicker), 'The Island and two creatures pay {2}{U}.');
        $game->cast(0, $flicker);
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->assertTrue($game->objects[$elves]->tapped);
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);
    }

    public function testLookingAtTheTopCards(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 2);
        $library = $game->players[0]->library;
        $top = array_reverse(array_slice($library, -4));
        $impulse = $this->put(0, 'Impulse', GameObject::HAND);
        $game->cast(0, $impulse);
        $this->resolve();
        $this->assertSame('look', $game->decision(0));
        $this->assertSame($top, $game->choiceAwaiting()['cards']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        try {
            $game->take(0, []);
            $this->fail('Impulse must take one.');
        } catch (\MTGPocket\Game\GameException) {
        }
        $game->take(0, [$top[2]]);
        $this->assertSame(GameObject::HAND, $this->zone($top[2]));
        $this->assertCount(count($library) - 4, array_intersect($game->players[0]->library, array_slice($library, 0, -4)));
        $this->assertEqualsCanonicalizing([$top[0], $top[1], $top[3]], array_slice($game->players[0]->library, 0, 3), 'The rest go on the bottom.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($impulse));

        // Only a creature, and only if you want to.
        $this->lands(0, 'Forest', 1);
        $commune = $this->put(0, 'Commune with Nature', GameObject::HAND);
        $this->passUntil(Step::PrecombatMain, 3);
        $game->cast(0, $commune);
        $this->resolve();
        $look = $game->choiceAwaiting();
        foreach ($look['eligible'] as $id) {
            $this->assertTrue($game->objects[$id]->definition()->isCreature());
        }
        $game->take(0, []);
        $this->assertNull($game->choiceAwaiting());
    }

    public function testMoreWaysToLookAtTheTopCards(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 4);
        $this->lands(0, 'Forest', 4);
        $library = &$game->players[0]->library;
        $salvage = $this->put(0, 'Grisly Salvage', GameObject::HAND);
        $top = array_reverse(array_slice($library, -5));
        $game->cast(0, $salvage);
        $this->resolve();
        $look = $game->choiceAwaiting();
        $this->assertSame($top, $look['cards']);
        foreach ($top as $id) {
            $card = $game->objects[$id]->printed();
            $this->assertSame($card->isCreature() || $card->isLand(), in_array($id, $look['eligible'], true));
        }
        $game->take(0, []);
        foreach ($top as $id) {
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($id));
        }

        $this->assertSame('sub:Dwarf|sub:Equipment', self::read("Dáin's Company Lite")->triggered[0]['effects'][0]['filter']);
        $dwarf = $game->addCard(0, self::more("Dáin's Company Lite"), GameObject::LIBRARY)->id;
        $company = $this->put(0, "Dáin's Company Lite", GameObject::HAND);
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $company);
        $this->resolve();
        $this->resolve();
        $this->assertSame([$dwarf], $game->choiceAwaiting()['eligible']);
        $game->take(0, [$dwarf]);
        $this->assertSame(GameObject::HAND, $this->zone($dwarf));

        $top = array_slice($library, -3);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Glimpse Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(0, $game->choiceAwaiting()['take']);
        $game->take(0, []);
        $this->assertContains($top[2], $game->players[0]->hand, 'They stay on top, and then it draws one.');
        $this->assertSame(array_slice($top, 0, 2), array_slice($library, -2));
    }

    public function testModalEntersTriggers(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 4);
        $sprite = $this->put(0, 'Mode Sprite', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $sprite);
        $this->resolve();
        $this->resolve(); // Only "Draw a card" can be chosen with no artifact around.
        $this->assertCount($hand, $game->players[0]->hand);

        $thopter = $this->put(1, 'Ornithopter', GameObject::BATTLEFIELD);
        $again = $this->put(0, 'Mode Sprite', GameObject::HAND);
        $game->cast(0, $again);
        $this->resolve();
        $this->assertSame('mode', $game->decision(0));
        $this->assertSame(['Draw a card', 'Destroy target artifact'], $game->choiceAwaiting()['texts']);
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $game->chooseMode(0, 1);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($thopter));
    }

    public function testDecksOfTheseCardsPlayToTheEnd(): void
    {
        $deck = function (array $basics, array $more): array {
            $cards = [];
            foreach ($basics as $name => $count) {
                array_push($cards, ...array_fill(0, $count, self::card($name)));
            }
            foreach ($more as $name => $count) {
                array_push($cards, ...array_fill(0, $count, self::more($name)));
            }

            return $cards;
        };
        $orzhov = $deck(['Plains' => 9, 'Swamp' => 8], [
            'Kitchen Finks' => 3, 'Akrasan Squire' => 3, 'Devoted Retainer' => 3, 'Toxic Lite' => 3, 'Glorious Anthem' => 2, 'Benalish Marshal' => 2,
            'Bake into a Pie' => 2, 'Thraben Inspector' => 3, 'Village Rites' => 2, 'Thoughtseize' => 3, 'Unburial Rites' => 2, 'Raise Dead' => 1,
            'Divine Verdict' => 2, 'Mode Sprite' => 2, 'Steppe Lynx Lite' => 2, 'Sword Lite' => 1, 'Amrou Kithkin' => 2, 'Frogmite' => 2, 'Brightfield Mustang' => 2, 'Brightfield Glider' => 2, 'Hopeful Eidolon' => 2, 'Patchwork Banner' => 1, 'Lumen-Class Frigate' => 2, 'Burnout Bashtronaut' => 2, 'Frost Breath' => 1, 'Outpace Oblivion' => 1, 'Lava Coil' => 2, 'Electromancer Lite' => 1, 'Hyena Umbra' => 1, 'Treasure Cruise' => 1, 'Banisher Lite' => 1, 'Mind Control' => 1, 'Firebending Lite' => 2, 'Simic Initiate' => 2, 'Monster Lite' => 2, 'Riot Lite' => 2, 'Merfolk Branchwalker' => 2, 'Mentor Lite' => 2, 'Exploit Lite' => 1, 'Topan Freeblade' => 2, 'Glint-Sleeve Artisan' => 2, 'Syndic of Tithes' => 1, "Ajani's Pridemate" => 2, "Curse of Death's Hold" => 1, 'Wicked Akuba Lite' => 2, 'Scuttling Death' => 2, 'Soul Warden Lite' => 2, 'Relentless Rats' => 1, 'Murderous Compulsion' => 2, 'Boon-Bringer Valkyrie' => 1, 'Offspring Lite' => 2, 'Blessed Ghoul' => 2, 'Terror' => 2, 'Syndicate Messenger' => 2, 'Azorius Chancery' => 1, 'Prismatic Lens' => 1, 'Intimidation Tactics' => 1, 'Leyline of Sanctity' => 1, 'Ascend Lite' => 2, 'Echo Lite' => 2, 'Escape Lite' => 2, 'Retrace Lite' => 2, 'Skulking Ghost' => 1, 'Grapeshot' => 2, 'Cumulative Lite' => 2, 'Murder Lite' => 2, 'Bloodbraid Elf' => 2, 'Ward Discard Lite' => 2, 'Search Lite' => 2, 'Embalm Lite' => 2, 'Enlist Lite' => 2, 'Life Land Lite' => 1, 'Pro Black Lite' => 2, 'Tribute Lite' => 2, 'Healer Lite' => 2, 'Reconfigure Lite' => 2, 'Zenith Lite' => 1, 'Look Land Lite' => 2, 'Reveal Lands Lite' => 1, 'Shuffle Lite' => 1, 'Temporary Lite' => 1, 'Path Lite' => 1, 'Stress Lite' => 2, 'Seismic Lite' => 2, 'Extraction Lite' => 1, 'Bribery Lite' => 1, 'Werewolf Lite // Howler Lite' => 2, 'Daybound Lite // Nightbound Lite' => 2, 'Flip Lite // Flipped Lite' => 2, 'Ghost Lite // Spirit Lite' => 2, 'Pay Flip Lite // Paid Lite' => 2, 'Saga Lite' => 2, 'Flip Saga Lite // Saga Dragon Lite' => 2, 'Lure Lite' => 2, 'Bully Lite' => 2, 'Hand Lite' => 1, 'Grave Lite' => 1, 'Read Ahead Lite' => 1, 'Ramp Lite' => 1, 'Cultivate' => 1, 'Training Lite' => 2, 'Scaler Lite' => 1, 'Trample Counters Lite' => 1, 'Party Lite' => 1, 'Sac Ping Lite' => 1, 'Afflict Lite' => 2, 'Dredge Lite' => 2, 'Incubate Lite' => 2, 'Ki Lite' => 1, 'Arcane Lite' => 1, 'Night Lite' => 1, 'Blitz Lite' => 2, 'Buyback Lite' => 2, 'Ingest Lite' => 2, 'Ally Lite' => 2, 'Conspire Lite' => 2, 'Awaken Lite' => 2, 'Connive Lite' => 2, 'Tapped Discount Lite' => 2, 'Grave Lands Lite' => 1, 'Big Game Lite' => 2, 'Tutor Lite' => 1, 'Demonic Lite' => 1, 'Regrowth Lite' => 2, 'Loot Enter Lite' => 2, 'Extra Turn Lite' => 1, 'Grave Exile Lite' => 2,
        ]);
        $gruul = $deck(['Mountain' => 9, 'Forest' => 8, 'Island' => 2], [
            'Strangleroot Geist' => 3, 'Stormblood Berserker' => 3, 'Strike It Rich' => 3, 'Act of Treason' => 3, 'Tormenting Voice' => 3,
            'Thought Scour' => 2, 'Bog Wraith' => 3, 'Impulse' => 2, 'Ornithopter' => 1, 'Wall of Air Lite' => 1, 'Hellspark Lite' => 2, 'Staggershock' => 2, 'Longtusk Cub' => 2, 'Thriving Rhino' => 1, 'Sleight of Hand' => 1, 'Glimpse Lite' => 1, 'Curse of the Pierced Heart' => 2, 'Druid Class Lite' => 2, 'Mardu Scout' => 2, 'Mulldrifter' => 1, 'Warp Lite' => 2, 'Plot Lite' => 2, 'Mobilize Lite' => 2, 'Goblin War Drums Lite' => 1, 'Second Draw Lite' => 1, 'Draw Lite' => 1, 'Ninja Lite' => 2, 'Spree Lite' => 2, 'Flourishing Strike' => 1, 'Rift Sower' => 2, 'Annihilator Lite' => 1, 'Pillage' => 1, 'Rumble Arena' => 1, 'Stress Dream Lite' => 1, 'Retreat to Kazandu' => 1, 'Reckless Impulse' => 2, 'Etched Oracle' => 1, 'Seer Lite' => 1, 'Flanking Lite' => 2, "Chemister's Insight" => 1, 'Bargain Lite' => 2, 'Foretell Lite' => 2, 'Thallid' => 2, 'Reverberate' => 1, 'Casualty Lite' => 2, 'Mirran Lite' => 1, 'Molimo Lite' => 2, 'Bounce Lite' => 2, 'Overload Lite' => 1, 'Eternalize Lite' => 2, 'Azorius Signet' => 1, 'Battle Cry Lite' => 2, 'Proliferate Lite' => 1, 'Prototype Lite' => 2,
        ]);
        array_push($gruul, ...array_fill(0, 4, self::card('Grizzly Bears')), ...array_fill(0, 3, self::card('Lightning Bolt')));

        $games = (int) (getenv('MTGPOCKET_SIM_GAMES') ?: 10);
        for ($seed = 0; $seed < $games; $seed++) {
            $game = Game::start("modern-{$seed}", [
                ['id' => '1', 'name' => 'Alice', 'cards' => $orzhov],
                ['id' => '2', 'name' => 'Bob', 'cards' => $gruul],
            ], "modern-{$seed}");
            for ($moves = 0; $game->stage !== Game::OVER; $moves++) {
                $this->assertLessThan(5000, $moves, "Seed {$seed} never ended.");
                $waiting = $game->waitingOn();
                $this->assertNotSame([], $waiting, "Seed {$seed}: nobody can move in {$game->step->label()} of turn {$game->turn}.");
                $this->assertTrue(AutoPlayer::act($game, $waiting[0]), "Seed {$seed}: seat {$waiting[0]} could not make a {$game->decision($waiting[0])} move.");
                $game = Game::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->assertSame(Game::OVER, $game->stage);
            if (getenv('MTGPOCKET_SIM_LOG')) {
                file_put_contents(getenv('MTGPOCKET_SIM_LOG'), implode("\n", $game->log)."\n", FILE_APPEND);
            }
        }
    }
}
