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
use MTGPocket\Game\Mana\ManaCost;
use MTGPocket\Game\Mana\ManaPayer;
use MTGPocket\Game\Mana\ManaPool;

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
        'Scavenge Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Ooze', 'power' => '2', 'toughness' => '2', 'text' => 'Scavenge {1}{G} ({1}{G}, Exile this card from your graveyard: Put a number of +1/+1 counters equal to this card\'s power on target creature. Scavenge only as a sorcery.)', 'colors' => ['G']],
        'Filter Land Lite' => ['manaCost' => null, 'type' => 'Land', 'text' => "{T}: Add {C}.\n{W/U}, {T}: Add {W}{W}, {W}{U}, or {U}{U}.", 'colors' => []],
        'Double White Lite' => ['manaCost' => '{W}{W}', 'type' => 'Sorcery', 'text' => 'You gain 2 life.', 'colors' => ['W']],
        'Freerun Lite' => ['manaCost' => '{3}{B}', 'type' => 'Creature — Human Assassin', 'power' => '3', 'toughness' => '2', 'text' => 'Freerunning {1}{B} (You may cast this spell for its freerunning cost if you dealt combat damage to a player this turn with an Assassin or commander.)', 'colors' => ['B']],
        'Tapped Aura Lite' => ['manaCost' => '{B}', 'type' => 'Enchantment — Aura', 'text' => "Enchant tapped creature\nEnchanted creature gets -1/-1.", 'colors' => ['B']],
        'Forest Aura Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment — Aura', 'text' => "Enchant Forest\nEnchanted land has hexproof.", 'colors' => ['G']],
        'Mixed Aura Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment — Aura', 'text' => "Enchant artifact or creature you control\nEnchanted creature gets +1/+1.", 'colors' => ['G']],
        'Color Aura Lite' => ['manaCost' => '{R}', 'type' => 'Enchantment — Aura', 'text' => "Enchant red or green creature\nEnchanted creature gets +2/+0.", 'colors' => ['R']],
        'Inquisition Lite' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Target opponent reveals their hand. You choose a nonland card with mana value 3 or less from it. That player discards that card.', 'colors' => ['B']],
        'Peek Hand Lite' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Target opponent reveals their hand. You draw a card.', 'colors' => ['U']],
        'Untapper Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'text' => "{T}: You gain 1 life.\n{1}: Untap this creature.", 'colors' => ['G'], 'power' => '1', 'toughness' => '1'],
        'No Regen Burn Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "This spell deals 3 damage to target creature. It can't be regenerated.", 'colors' => ['R']],
        'Shield Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human', 'power' => '2', 'toughness' => '2', 'text' => 'This creature enters with a shield counter on it.', 'colors' => ['W']],
        'Island Walker Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Serpent', 'power' => '3', 'toughness' => '3', 'text' => "This creature can't attack unless defending player controls an Island.", 'colors' => ['U']],
        'Lure Beast Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Beast', 'power' => '4', 'toughness' => '4', 'text' => 'All creatures able to block this creature do so.', 'colors' => ['G']],
        'Charge Relic Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => "This artifact enters with two charge counters on it.\n{T}, Remove a charge counter from this artifact: Add one mana of any color.", 'colors' => []],
        'Amass Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Amass Orcs 2.', 'colors' => ['B']],
        'Permanent Regrowth Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Return target permanent card from your graveyard to your hand.', 'colors' => ['G']],
        'Lazy Untap Lite' => ['manaCost' => '{2}', 'type' => 'Artifact Creature — Golem', 'power' => '2', 'toughness' => '2', 'text' => 'You may choose not to untap this creature during your untap step.', 'colors' => []],
        'Freeze Aura Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment — Aura', 'text' => "Enchant permanent\nWhen this Aura enters, tap enchanted permanent.\nEnchanted permanent doesn't untap during its controller's untap step.", 'colors' => ['U']],
        'Crowd Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Giant', 'power' => '5', 'toughness' => '5', 'text' => "This creature can't be blocked except by three or more creatures.", 'colors' => ['R']],
        'Tuck Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => "Target creature's owner puts it on their choice of the top or bottom of their library.", 'colors' => ['U']],
        'Team Counters Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'power' => '1', 'toughness' => '1', 'text' => 'When this creature enters, put a +1/+1 counter on each other creature you control.', 'colors' => ['G']],
        'Double Pump Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Up to two target creatures each get +2/+2 and gain trample until end of turn.', 'colors' => ['G']],
        'Ritual Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => 'Add {R}{R}{R}.', 'colors' => ['R']],
        'Outlast Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Warrior', 'power' => '1', 'toughness' => '1', 'text' => 'Outlast {1}{W} ({1}{W}, {T}: Put a +1/+1 counter on this creature. Outlast only as a sorcery.)', 'colors' => ['W']],
        'Shrink Lite' => ['manaCost' => '{2}{B}', 'type' => 'Enchantment', 'text' => 'Creatures your opponents control get -1/-1.', 'colors' => ['B']],
        'Double Threaten Lite' => ['manaCost' => '{3}{R}', 'type' => 'Sorcery', 'text' => 'Gain control of up to two target creatures until end of turn. Untap those creatures. They gain haste until end of turn.', 'colors' => ['R']],
        'Smother Lite' => ['manaCost' => '{1}{B}', 'type' => 'Instant', 'text' => "Destroy target creature with mana value 3 or less. It can't be regenerated.", 'colors' => ['B']],
        'X Leak Lite' => ['manaCost' => '{X}{U}', 'type' => 'Instant', 'text' => 'Counter target spell unless its controller pays {X}.', 'colors' => ['U']],
        'Impulse Today Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Exile the top three cards of your library. Until end of turn, you may play those cards.', 'colors' => ['R']],
        'Transmute Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => "Transmute Lite deals 2 damage to any target.\nTransmute {1}{B}{B}. ({1}{B}{B}, Discard this card: Search your library for a card with the same mana value as this card, reveal it, put it into your hand, then shuffle. Transmute only as a sorcery.)", 'colors' => ['B']],
        'Transmute Beast Lite' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Beast', 'power' => '2', 'toughness' => '2', 'text' => 'Transmute {1}{U}{U} ({1}{U}{U}, Discard this card: Search your library for a card with the same mana value as this card, reveal it, put it into your hand, then shuffle. Transmute only as a sorcery.)', 'colors' => ['U']],
        'Mother Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Cleric', 'power' => '1', 'toughness' => '1', 'text' => '{T}: Target creature you control gains protection from the color of your choice until end of turn.', 'colors' => ['W']],
        'Two Block Lite' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Soldier', 'power' => '3', 'toughness' => '5', 'text' => 'Two Block Lite can block an additional creature each combat.', 'colors' => ['W']],
        'Learn Lite' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "Learn Lite deals 2 damage to target creature.\nLearn. (You may reveal a Lesson card you own from outside the game and put it into your hand, or discard a card to draw a card.)", 'colors' => ['R']],
        'Grave Hate Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "Exile target player's graveyard.\nDraw a card.", 'colors' => ['B']],
        'Titan Lite' => ['manaCost' => '{8}', 'type' => 'Creature — Eldritch Titan', 'power' => '8', 'toughness' => '8', 'text' => "When Titan Lite is put into a graveyard from anywhere, shuffle it into its owner's library.", 'colors' => []],
        'Compleated Lite' => ['manaCost' => '{1}{B}{B/P}', 'type' => 'Legendary Planeswalker — Lite', 'supertypes' => ['Legendary'], 'types' => ['Planeswalker'], 'subtypes' => ['Lite'], 'loyalty' => '5', 'text' => "Compleated ({B/P} can be paid with {B} or 2 life. For each {B/P} paid with life, this planeswalker enters with two fewer loyalty counters.)\n+1: You gain 1 life.", 'colors' => ['B']],
        'Drum Lite' => ['manaCost' => '{1}', 'type' => 'Artifact', 'text' => '{T}, Tap an untapped creature you control: Add one mana of any color.', 'colors' => []],
        'Scene Lite' => ['manaCost' => '', 'type' => 'Artifact Land — Clue', 'types' => ['Artifact', 'Land'], 'text' => "{T}: Add {C}.\n{T}, Tap an untapped creature you control: Add one mana of any color.", 'colors' => []],
        'Edict Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => 'Target player sacrifices a creature of their choice.', 'colors' => ['B']],
        'Artifact Sac Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "As an additional cost to cast Artifact Sac Lite, sacrifice an artifact.\nDraw two cards.", 'colors' => ['B']],
        'Haste Magic Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => 'Target creature gets +3/+1 and gains haste until end of turn. Exile the top card of your library. You may play it until your next end step.', 'colors' => ['R']],
        'Relay Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Exile the top card of your library. During your next turn, you may play that card.', 'colors' => ['R']],
        'Stirrings Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Look at the top five cards of your library. You may reveal a colorless card from among them and put it into your hand. Then put the rest on the bottom of your library in any order.', 'colors' => ['G']],
        'Teamwork Lite' => ['manaCost' => '{1}{B}', 'type' => 'Instant', 'text' => "Teamwork 3 (As an additional cost to cast this spell, you may tap any number of creatures you control with total power 3 or more.)\nChoose one. If this spell was cast using teamwork, choose both instead.\n• Venom — Target creature gains deathtouch until end of turn.\n• Target creature gets -2/-2 until end of turn.", 'colors' => ['B']],
        'Multikick Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'power' => '0', 'toughness' => '0', 'text' => "Multikicker {1}{G} (You may pay an additional {1}{G} any number of times as you cast this spell.)\nMultikick Lite enters with a +1/+1 counter on it for each time it was kicked.", 'colors' => ['G']],
        'Clash Burn Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Clash Burn Lite deals 1 damage to any target. Clash with an opponent. If you win, return Clash Burn Lite to its owner's hand. (Each clashing player reveals the top card of their library, then puts that card on the top or bottom. A player wins if their card had a higher mana value.)", 'colors' => ['R']],
        'Clash Rat Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Rat', 'power' => '1', 'toughness' => '1', 'text' => 'When this creature enters, clash with an opponent. If you win, put a +1/+1 counter on this creature.', 'colors' => ['B']],
        'Clash Pump Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Target creature gets +2/+2 until end of turn. Clash with an opponent. If you win, that creature gets an additional +2/+2 and gains trample until end of turn.', 'colors' => ['G']],
        'Lash Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => "Lash Lite deals 3 damage to target creature. Clash with an opponent. If you win, Lash Lite deals 3 damage to that creature's controller.", 'colors' => ['R']],
        'Gift Pump Lite' => ['manaCost' => '{W}', 'type' => 'Instant', 'text' => "Gift a Food (You may promise an opponent a gift as you cast this spell. If you do, they create a Food token before its other effects.)\nTarget creature you control gets +2/+2 until end of turn. If the gift was promised, that creature also gains indestructible until end of turn.", 'colors' => ['W']],
        'Gift Draw Lite' => ['manaCost' => '{1}{U}', 'type' => 'Sorcery', 'text' => "Gift a card (You may promise an opponent a gift as you cast this spell. If you do, they draw a card before its other effects.)\nDraw a card. If the gift wasn't promised, you gain 3 life.", 'colors' => ['U']],
        'Gift Otter Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Otter', 'power' => '2', 'toughness' => '1', 'text' => "Gift a tapped Fish (You may promise an opponent a gift as you cast this spell. If you do, they create a tapped 1/1 blue Fish creature token before its other effects.)\nWhen this creature enters, if the gift was promised, draw a card.", 'colors' => ['U']],
        'Courser Lite' => ['manaCost' => '{1}{G}{G}', 'type' => 'Enchantment Creature — Centaur', 'power' => '2', 'toughness' => '4', 'text' => "Play with the top card of your library revealed.\nYou may play lands from the top of your library.", 'colors' => ['G']],
        'Future Lite' => ['manaCost' => '{2}{U}{U}{U}', 'type' => 'Enchantment', 'text' => "Play with the top card of your library revealed.\nYou may play lands and cast spells from the top of your library.", 'colors' => ['U']],
        'Cipher Lite' => ['manaCost' => '{U}', 'type' => 'Sorcery', 'text' => "Draw a card.\nCipher (Then you may exile this spell card encoded on a creature you control. Whenever that creature deals combat damage to a player, its controller may cast a copy of the encoded card without paying its mana cost.)", 'colors' => ['U']],
        'Company Lite' => ['manaCost' => '{3}{G}', 'type' => 'Instant', 'text' => 'Look at the top six cards of your library. Put up to two creature cards with mana value 3 or less from among them onto the battlefield. Put the rest on the bottom of your library in any order.', 'colors' => ['G']],
        'Battlefront Lite' => ['manaCost' => '{3}{W}', 'type' => 'Sorcery', 'text' => 'Look at the top seven cards of your library. Put up to two noncreature, nonland permanent cards with mana value 3 or less from among them onto the battlefield. Put the rest on the bottom of your library in a random order.', 'colors' => ['W']],
        'Rummager Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Rogue', 'power' => '2', 'toughness' => '1', 'text' => '{1}, Discard a card: Draw a card.', 'colors' => ['R']],
        'Obliteration Lite' => ['manaCost' => '{1}{B}{B}', 'type' => 'Sorcery', 'text' => "Choose a creature card name. Search target opponent's graveyard, hand, and library for any number of cards with that name and exile them. Then that player shuffles.", 'colors' => ['B']],
        'Goryo Lite' => ['manaCost' => '{1}{B}', 'type' => 'Instant', 'text' => 'Return target legendary creature card from your graveyard to the battlefield. That creature gains haste. Exile it at the beginning of the next end step.', 'colors' => ['B']],
        'Elf Lord Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf Warrior', 'power' => '2', 'toughness' => '2', 'text' => "Other Elves you control get +1/+1.\nWhenever you cast a creature spell, draw a card.", 'colors' => ['G']],
        'Bookworm Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '1', 'text' => 'Whenever you draw a card, put a +1/+1 counter on this creature.', 'colors' => ['U']],
        'Pro Artifacts Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf Druid', 'power' => '2', 'toughness' => '2', 'text' => 'Protection from artifacts', 'colors' => ['G']],
        'Bolster Lite' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => 'Bolster 2.', 'colors' => ['W']],
        'Hideous Lite' => ['manaCost' => '{1}{B}{B}', 'type' => 'Instant', 'text' => 'Destroy target nonblack creature. Its controller loses 2 life.', 'colors' => ['B']],
        'Countersquall Lite' => ['manaCost' => '{U}{B}', 'type' => 'Instant', 'text' => 'Counter target noncreature spell. Its controller loses 2 life.', 'colors' => ['U', 'B']],
        'Grave Discount Lite' => ['manaCost' => '{4}{U}', 'type' => 'Creature — Drake', 'power' => '3', 'toughness' => '3', 'text' => "This spell costs {1} less to cast for each instant and sorcery card in your graveyard.\nFlying", 'colors' => ['U']],
        'Exiler Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie', 'power' => '2', 'toughness' => '1', 'text' => 'If a creature an opponent controls would die, exile it instead.', 'colors' => ['B']],
        'Converge Lite' => ['manaCost' => '{1}{R}{G}', 'type' => 'Creature — Elemental', 'power' => '0', 'toughness' => '0', 'text' => 'Converge — CARDNAME enters with a +1/+1 counter on it for each color of mana spent to cast it.', 'colors' => ['R', 'G']],
        'Stun Sage Lite' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => 'When CARDNAME enters, tap target creature an opponent controls and put a stun counter on it.', 'colors' => ['U']],
        'Skullcrack Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => "Damage can't be prevented this turn. CARDNAME deals 3 damage to target creature.", 'colors' => ['R']],
        'Replicate Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Replicate {R}\nCARDNAME deals 1 damage to any target.", 'colors' => ['R']],
        'Ring Lite' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'The Ring tempts you. Draw a card.', 'colors' => ['B']],
        'Ring Orc Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Orc Soldier', 'power' => '2', 'toughness' => '2', 'text' => 'When CARDNAME enters, the Ring tempts you.', 'colors' => ['B']],
        'Raid Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Pirate', 'power' => '2', 'toughness' => '2', 'text' => 'Raid — CARDNAME enters with a +1/+1 counter on it if you attacked this turn.', 'colors' => ['R']],
        'Pupil Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human', 'power' => '0', 'toughness' => '0', 'text' => "CARDNAME enters with a +1/+1 counter on it.\nWhen CARDNAME dies, put its counters on target creature you control.", 'colors' => ['W']],
        'Wings Lite' => ['manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => 'Destroy target artifact, enchantment, or creature with flying.', 'colors' => ['G']],
        'Rally Lite' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => 'Untap all creatures you control. Tap all creatures your opponents control.', 'colors' => ['W']],
        'Frostwalk Lite' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Human Warrior', 'power' => '2', 'toughness' => '3', 'text' => "Whenever CARDNAME deals combat damage to a creature, tap that creature and it doesn't untap during its controller's next untap step.", 'colors' => ['U']],
        'Increment Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => '1', 'toughness' => '3', 'text' => 'Increment (Whenever you cast a spell, if the amount of mana you spent is greater than this creature\'s power or toughness, put a +1/+1 counter on this creature.)', 'colors' => ['U']],
        'Fiend Hunter Lite' => ['manaCost' => '{1}{W}{W}', 'type' => 'Creature — Human Cleric', 'power' => '1', 'toughness' => '3', 'text' => "When CARDNAME enters, exile another target creature.\nWhen CARDNAME leaves the battlefield, return the exiled card to the battlefield under its owner's control.", 'colors' => ['W']],
        'Blood Guest Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Vampire', 'power' => '1', 'toughness' => '4', 'text' => 'When CARDNAME enters, create a Blood token.', 'colors' => ['B']],
        'Prepared Angel Lite // Seed Lite' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Angel Cleric', 'power' => '2', 'toughness' => '4', 'text' => "Flying, vigilance\nThis creature enters prepared. (While it's prepared, you may cast a copy of its spell. Doing so unprepares it.)", 'colors' => ['W'],
            'prepare' => ['name' => 'Seed Lite', 'type' => 'Sorcery', 'manaCost' => '{G/W}', 'colors' => ['G', 'W'], 'text' => 'Put a +1/+1 counter on target creature. You gain 1 life.']],
        'Upkeep Prep Lite // Mend Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => "At the beginning of your upkeep, if this creature isn't prepared, it becomes prepared.", 'colors' => ['R'],
            'prepare' => ['name' => 'Mend Lite', 'type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'You gain 1 life.']],
        'Mourner Lite // Mourn Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => "At the beginning of each end step, if two or more creatures died this turn, this creature becomes prepared.", 'colors' => ['R'],
            'prepare' => ['name' => 'Mourn Lite', 'type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'You gain 1 life.']],
        'Leech Prep Lite // Sip Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => "Whenever you gain life for the first time each turn, this creature becomes prepared.", 'colors' => ['R'],
            'prepare' => ['name' => 'Sip Lite', 'type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'You gain 1 life.']],
        'Third Spell Lite // Third Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => "Whenever you cast your third spell each turn, this creature becomes prepared.", 'colors' => ['R'],
            'prepare' => ['name' => 'Third Lite', 'type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'You gain 1 life.']],
        'Silver Prep Lite // Silver Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => "At the beginning of your second main phase, if you gained 2 or more life this turn, this creature becomes prepared.", 'colors' => ['R'],
            'prepare' => ['name' => 'Silver Lite', 'type' => 'Instant', 'manaCost' => '{R}', 'colors' => ['R'], 'text' => 'You gain 1 life.']],
        'Refractor Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => '{2}: Add one mana of any color.', 'colors' => []],
        'Mascot Lite' => ['manaCost' => '{2}', 'type' => 'Artifact Creature — Shapeshifter', 'power' => '1', 'toughness' => '1', 'text' => "Changeling\n{1}: Add one mana of any color. Activate only once each turn.", 'colors' => []],
        'Vivid Lite' => ['type' => 'Land', 'text' => "This land enters tapped with two charge counters on it.\n{T}: Add {R}.\n{T}, Remove a charge counter from this land: Add one mana of any color.", 'colors' => []],
        'Bolt Lite // Heal Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Bolt Lite deals 2 damage to any target.\nFuse (You may cast one or both halves of this card from your hand.)", 'colors' => ['R', 'W'],
            'split' => ['name' => 'Heal Lite', 'type' => 'Instant', 'manaCost' => '{W}', 'colors' => ['W'], 'text' => "You gain 3 life.\nFuse (You may cast one or both halves of this card from your hand.)"]],
        'Fire Lite // Ice Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => 'Fire Lite deals 2 damage to any target.', 'colors' => ['R', 'U'],
            'split' => ['name' => 'Ice Lite', 'type' => 'Instant', 'manaCost' => '{1}{U}', 'colors' => ['U'], 'text' => 'Draw a card.']],
        'Heroic Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Soldier', 'power' => '1', 'toughness' => '1', 'text' => 'Heroic — Whenever you cast a spell that targets this creature, put a +1/+1 counter on this creature.', 'colors' => ['W']],
        'Tenacity Lite' => ['manaCost' => '{3}{W}', 'type' => 'Instant', 'text' => 'Creatures you control get +1/+1 and gain lifelink until end of turn. Untap those creatures.', 'colors' => ['W']],
        'Taxed Lite' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Sphinx', 'power' => '2', 'toughness' => '2', 'text' => 'Spells your opponents cast that target this creature cost {2} more to cast.', 'colors' => ['U']],
        'Rupture Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Rupture Lite deals 2 damage to each creature without flying.', 'colors' => ['R']],
        'Harmonize Lite' => ['manaCost' => '{3}{G}', 'type' => 'Sorcery', 'text' => "Draw two cards.\nHarmonize {5}{G} (You may cast this card from your graveyard for its harmonize cost. You may tap a nonattacking creature you control to reduce that cost by {X}, where X is its power. Then exile this spell.)", 'colors' => ['G']],
        'Husk Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Phyrexian Insect', 'power' => '3', 'toughness' => '3', 'text' => 'Sacrifice a creature: This creature gets +2/+2 until end of turn.', 'colors' => ['B']],
        'Flunkies Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Goblin', 'power' => '3', 'toughness' => '3', 'text' => "This creature can't attack or block alone.", 'colors' => ['R']],
        'Tide Lite' => ['manaCost' => '{3}{U}', 'type' => 'Sorcery', 'text' => "Return up to two target creatures to their owners' hands.", 'colors' => ['U']],
        'Dragonfire Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => 'Dragonfire Lite deals 3 damage to target creature or planeswalker. If that creature or planeswalker would die this turn, exile it instead.', 'colors' => ['R']],
        'Support Lite' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Bird', 'power' => '2', 'toughness' => '2', 'text' => 'When this creature enters, support two. (Put a +1/+1 counter on each of up to two other target creatures.)', 'colors' => ['W']],
        'Glacial Ray Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant — Arcane', 'text' => "Glacial Ray Lite deals 2 damage to any target.\nSplice onto Arcane {1}{R} (As you cast an Arcane spell, you may reveal this card from your hand and pay its splice cost. If you do, add this card's effects to that spell.)", 'colors' => ['R']],
        'Evermind Lite' => ['manaCost' => '{U}', 'type' => 'Instant — Arcane', 'text' => "Draw a card.\nSplice onto Arcane {1}{U} (As you cast an Arcane spell, you may reveal this card from your hand and pay its splice cost. If you do, add this card's effects to that spell.)", 'colors' => ['U']],
        'Ornery Goblin Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin Warrior', 'text' => 'Whenever Ornery Goblin Lite blocks or becomes blocked by a creature, Ornery Goblin Lite deals 1 damage to that creature.', 'power' => '2', 'toughness' => '1', 'colors' => ['R']],
        'Gilded Lotus Lite' => ['manaCost' => '{5}', 'type' => 'Artifact', 'text' => '{T}: Add three mana of any one color.', 'colors' => []],
        'Vitality Lite' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Angel', 'text' => "Flying\nIf you would gain life, you gain that much life plus 1 instead.", 'power' => '2', 'toughness' => '2', 'colors' => ['W']],
        'Lynx Lite' => ['manaCost' => '{2}{R}{R}', 'type' => 'Creature — Elemental Cat', 'text' => "Players can't gain life.", 'power' => '5', 'toughness' => '4', 'colors' => ['R']],
        'Reanimate Lite' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Put target creature card from a graveyard onto the battlefield under your control.', 'colors' => ['B']],
        'Witness Lite' => ['manaCost' => '{1}{W}', 'type' => 'Sorcery', 'text' => 'Return target creature card with mana value 2 or less from your graveyard to the battlefield.', 'colors' => ['W']],
        'Liege Lite' => ['manaCost' => '{2}{R/G}{R/G}{R/G}', 'type' => 'Creature — Goblin Knight', 'text' => "Other red creatures you control get +1/+1.\nOther green creatures you control get +1/+1.", 'power' => '3', 'toughness' => '4', 'colors' => ['R', 'G']],
        'Oriflamme Lite' => ['manaCost' => '{3}{R}', 'type' => 'Enchantment', 'text' => 'Attacking creatures you control get +1/+0.', 'colors' => ['R']],
        'Wild Growth Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment — Aura', 'text' => "Enchant land\nWhenever enchanted land is tapped for mana, its controller adds an additional one mana of any color.", 'colors' => ['G']],
        'Command Lite' => ['manaCost' => '{1}{W}', 'type' => 'Sorcery', 'text' => "Choose one —\n• Target player gains 4 life.\n• Draw a card.", 'colors' => ['W']],
        'Metalcraft Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Vedalken', 'text' => 'Metalcraft — Metalcraft Lite gets +2/+2 as long as you control three or more artifacts.', 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Threshold Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Horror', 'text' => 'Threshold — Threshold Lite gets +1/+1 and has flying as long as seven or more cards are in your graveyard.', 'power' => '2', 'toughness' => '2', 'colors' => ['B']],
        'Hussar Lite' => ['manaCost' => '{2}{U}', 'type' => 'Creature — Vedalken Knight', 'text' => "Vigilance\nWhen Hussar Lite enters, sacrifice it unless {W} was spent to cast it.", 'power' => '1', 'toughness' => '3', 'colors' => ['U']],
        'Execute Lite' => ['manaCost' => '{2}{B}', 'type' => 'Instant', 'text' => "Destroy target white creature. It can't be regenerated.\nDraw a card.", 'colors' => ['B']],
        'Beckon Lite' => ['manaCost' => '{W/B}', 'type' => 'Instant', 'text' => "Exile target card from a graveyard.\nDraw a card.", 'colors' => ['W', 'B']],
        'Wrath Lite' => ['manaCost' => '{4}{R}{R}', 'type' => 'Sorcery', 'text' => "Wrath Lite deals 5 damage to any target.\nMiracle {R} (You may cast this card for its miracle cost when you draw it if it's the first card you drew this turn.)", 'colors' => ['R']],
        'Zendikon Lite' => ['manaCost' => '{2}{W}', 'type' => 'Enchantment — Aura', 'text' => "Enchant land\nEnchanted land is a 2/2 white Knight Ally creature with first strike. It's still a land.\nWhen enchanted land dies, return that card to its owner's hand.", 'colors' => ['W']],
        'Roar Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => "As an additional cost to cast this spell, you may reveal a Dragon card from your hand.\nRoar Lite deals 3 damage to target creature. If a Dragon card was revealed as you cast this spell, Roar Lite deals 3 damage to that creature's controller.", 'colors' => ['R']],
        'Whelp Lite' => ['manaCost' => '{2}{R}{R}', 'type' => 'Creature — Dragon', 'text' => 'Flying', 'power' => '2', 'toughness' => '3', 'colors' => ['R']],
        'Champion Lite' => ['manaCost' => '{3}{R}{R}', 'type' => 'Artifact Creature — Construct', 'text' => "Trample, haste\nWhenever Champion Lite attacks, you may pay {1}{R}. If you do, put a +1/+1 counter on it.", 'power' => '4', 'toughness' => '4', 'colors' => ['R']],
        'Zero Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => "Target creature has base power and toughness 0/1 until end of turn.\nDraw a card.", 'colors' => ['U']],
        'Sneak Lite' => ['manaCost' => '{3}{U}{U}', 'type' => 'Creature — Turtle Ninja', 'text' => 'Sneak {1}{U}', 'power' => '4', 'toughness' => '4', 'colors' => ['U']],
        'Mercy Lite' => ['manaCost' => '{W}', 'type' => 'Instant', 'text' => 'Prevent the next 3 damage that would be dealt to target creature this turn. You gain life equal to the damage prevented this way.', 'colors' => ['W']],
        'Energy Champion Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goat', 'text' => 'Whenever Energy Champion Lite attacks, you may pay {E}{E}. If you do, put a +1/+1 counter on it.', 'power' => '2', 'toughness' => '2', 'colors' => ['R']],
        'Bond Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Wolf', 'text' => "Soulbond\nAs long as Bond Lite is paired with another creature, each of those creatures gets +1/+1.", 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Wing Bond Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Bird', 'text' => "Soulbond\nAs long as Wing Bond Lite is paired with another creature, both creatures have flying.", 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Emerge Lite' => ['manaCost' => '{6}{G}{G}', 'type' => 'Creature — Eldrazi', 'text' => 'Emerge {5}{G}', 'power' => '6', 'toughness' => '6', 'colors' => ['G']],
        'Pact Lite' => ['manaCost' => '{0}', 'type' => 'Instant', 'text' => "Draw a card.\nAt the beginning of your next upkeep, pay {1}{U}. If you don't, you lose the game.", 'colors' => ['U']],
        'Hideaway Lite' => ['manaCost' => '', 'type' => 'Land', 'text' => "Hideaway 4\n{1}, {T}: You may play the exiled card without paying its mana cost."],
        'Windbrisk Lite' => ['manaCost' => '', 'type' => 'Land', 'text' => "Hideaway 4\nThis land enters tapped.\n{W}, {T}: You may play the exiled card without paying its mana cost if you attacked with three or more creatures this turn."],
        'Impulse Enter Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Goblin', 'text' => 'When Impulse Enter Lite enters, exile the top card of your library. Until the end of your next turn, you may play that card.', 'power' => '2', 'toughness' => '2', 'colors' => ['R']],
        'Untapped Hexproof Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'text' => "Untapped Hexproof Lite has hexproof as long as it's untapped.", 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Grave Tuck Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => "{1}: Put target card from a graveyard on the bottom of its owner's library."],
        'Justice Lite' => ['manaCost' => '{R}{W}', 'type' => 'Instant', 'text' => 'Target creature deals damage to itself equal to its power.', 'colors' => ['R', 'W']],
        'Flicker Lite' => ['manaCost' => '{W}', 'type' => 'Instant', 'text' => "Exile target creature you control, then return that card to the battlefield under its owner's control.", 'colors' => ['W']],
        'Exile Wrath Lite' => ['manaCost' => '{3}{W}{W}', 'type' => 'Sorcery', 'text' => 'Exile all creatures.', 'colors' => ['W']],
        'Prophecy Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => "Prophecy Lite deals 3 damage to target creature. You may put a card from your hand on the bottom of your library. If you do, draw a card.", 'colors' => ['R']],
        'Land Scout Lite' => ['manaCost' => '{2}', 'type' => 'Artifact Creature — Spider', 'text' => 'When Land Scout Lite enters, you may search your library for a basic land card, reveal it, then shuffle and put that card on top.', 'power' => '2', 'toughness' => '2'],
        'Twin Bolt Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => 'Twin Bolt Lite deals 2 damage divided as you choose among one or two targets.', 'colors' => ['R']],
        'Counterpart Lite' => ['manaCost' => '{2}{U}', 'type' => 'Instant', 'text' => "Create a token that's a copy of target creature you control.", 'colors' => ['U']],
        'Skystinger Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Insect', 'text' => "Reach\nWhenever Skystinger Lite blocks a creature with flying, Skystinger Lite gets +5/+0 until end of turn.", 'power' => '0', 'toughness' => '5', 'colors' => ['G']],
        'Herbalist Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'text' => '{T}: You may put a land card from your hand onto the battlefield.', 'power' => '1', 'toughness' => '1', 'colors' => ['G']],
        'Infiltrator Lite' => ['manaCost' => '{1}{U}{B}', 'type' => 'Creature — Human Rogue', 'text' => "Fear\nWhenever Infiltrator Lite deals combat damage to a player, you may draw a card.", 'power' => '1', 'toughness' => '3', 'colors' => ['U', 'B']],
        'Twin Copy Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => "Create a token that's a copy of target creature you control, except it has haste. Exile it at the beginning of the next end step.", 'colors' => ['R']],
        'Journey Lite' => ['manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => "Target player shuffles up to three target cards from their graveyard into their library.", 'colors' => ['G']],
        'Dragonstorm Lite' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'text' => "When a Dragon you control enters, return Dragonstorm Lite to its owner's hand.", 'colors' => ['R']],
        'Collapse Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'Tap target creature. Its controller mills two cards.', 'colors' => ['U']],
        'Wave Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => 'Wave Lite deals 1 damage to each creature your opponents control.', 'colors' => ['R']],
        'Banshee Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Spirit', 'text' => 'When Banshee Lite enters, each player loses 2 life.', 'power' => '2', 'toughness' => '2', 'colors' => ['B']],
        'Eruption Lite' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => "Creatures without flying can't block this turn.", 'colors' => ['R']],
        'Sporeback Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Wolf', 'text' => 'During your turn, Sporeback Lite gets +2/+0.', 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Flayer Lite' => ['manaCost' => '{B}{G}', 'type' => 'Creature — Horror', 'text' => 'Delirium — Flayer Lite gets +2/+2 as long as there are four or more card types among cards in your graveyard.', 'power' => '2', 'toughness' => '2', 'colors' => ['B', 'G']],
        'Stinger Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Vampire', 'text' => "Stinger Lite has first strike as long as it's attacking.", 'power' => '2', 'toughness' => '1', 'colors' => ['R']],
        'Drake Lord Lite' => ['manaCost' => '{3}{U}', 'type' => 'Creature — Drake', 'text' => "Flying\nOther creatures you control with flying get +1/+1.", 'power' => '2', 'toughness' => '2', 'colors' => ['U']],
        'Speed Lite' => ['manaCost' => '{R}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\nWhen Speed Lite enters, enchanted creature gains first strike until end of turn.\nEnchanted creature gets +1/+0.", 'colors' => ['R']],
        'Informant Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Elf', 'text' => 'When Informant Lite enters or dies, surveil 1.', 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Shambler Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Fungus', 'text' => 'Whenever another creature you control dies, put a +1/+1 counter on Shambler Lite.', 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Racketeer Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Human', 'text' => 'Whenever Racketeer Lite deals combat damage to a player, that player discards a card.', 'power' => '2', 'toughness' => '2', 'colors' => ['B']],
        'Energy Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => 'You get {E}{E}. Draw a card.', 'colors' => ['U']],
        'Vista Lite' => ['manaCost' => null, 'type' => 'Land — Forest Plains', 'text' => "{T}: Add {G} or {W}.\nVista Lite enters tapped unless you control two or more basic lands.", 'colors' => []],
        'Devotee Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Monk', 'text' => '{1}: Add {R}, {W}, or {B}. Activate only once each turn.', 'power' => '1', 'toughness' => '1', 'colors' => ['R']],
        'Tunnel Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Rat', 'text' => '{2}{B}: Return Tunnel Lite from your graveyard to the battlefield tapped.', 'power' => '2', 'toughness' => '1', 'colors' => ['B']],
        'Endure Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human', 'text' => 'When Endure Lite enters, it endures 2.', 'power' => '2', 'toughness' => '2', 'colors' => ['W']],
        'Exhaust Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf', 'text' => 'Exhaust — {G}: Put two +1/+1 counters on Exhaust Lite.', 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Journeymage Lite' => ['manaCost' => '{4}{U}', 'type' => 'Creature — Human Wizard', 'text' => 'Journeymage Lite costs {1} less to cast if you control a Wizard.', 'power' => '3', 'toughness' => '2', 'colors' => ['U']],
        'Rockslide Lite' => ['manaCost' => '{2}{R}', 'type' => 'Instant', 'text' => 'Rockslide Lite deals damage to target creature equal to the number of lands you control.', 'colors' => ['R']],
        'Games Hero Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'text' => 'Whenever you cast a spell that targets Games Hero Lite, creatures you control get +1/+1 until end of turn.', 'power' => '2', 'toughness' => '1', 'colors' => ['W']],
        'Leopard Lite' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Cat', 'text' => 'Whenever Leopard Lite becomes tapped, you gain 1 life.', 'power' => '3', 'toughness' => '2', 'colors' => ['W']],
        'Discover Lite' => ['manaCost' => '{2}{G}', 'type' => 'Sorcery', 'text' => 'Discover 3.', 'colors' => ['G']],
        'Mare Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Horse', 'text' => "Mare Lite can't be blocked by white creatures.", 'power' => '2', 'toughness' => '2', 'colors' => ['B']],
        'Medallion Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => 'Red spells you cast cost {1} less to cast.', 'colors' => []],
        'Firewalker Lite' => ['manaCost' => '{W}{W}', 'type' => 'Creature — Kor Soldier', 'text' => 'Whenever a player casts a red spell, you may gain 1 life.', 'power' => '2', 'toughness' => '2', 'colors' => ['W']],
        'Harbinger Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Goblin Shaman', 'text' => 'When Harbinger Lite enters, you may search your library for a Goblin card, reveal it, then shuffle and put that card on top.', 'power' => '1', 'toughness' => '1', 'colors' => ['B']],
        'Kird Lite' => ['manaCost' => '{R}', 'type' => 'Creature — Ape', 'text' => 'Kird Lite gets +1/+2 as long as you control a Forest.', 'power' => '1', 'toughness' => '1', 'colors' => ['R']],
        'Piledriver Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin Warrior', 'text' => 'Whenever Piledriver Lite attacks, it gets +2/+0 until end of turn for each other attacking Goblin.', 'power' => '1', 'toughness' => '2', 'colors' => ['R']],
        'Predation Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => "Target creature you control gets +1/+1 until end of turn. It fights target creature you don't control.", 'colors' => ['G']],
        'Drifter Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Wizard', 'text' => 'At the beginning of your upkeep, sacrifice Drifter Lite unless you pay {U}.', 'power' => '2', 'toughness' => '1', 'colors' => ['U']],
        'Sliver Lord Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Sliver', 'text' => 'All Sliver creatures get +1/+1.', 'power' => '1', 'toughness' => '1', 'colors' => ['W']],
        'Hivepool Lite' => ['manaCost' => '{6}{B}', 'type' => 'Creature — Sliver', 'text' => 'Affinity for Slivers', 'power' => '4', 'toughness' => '4', 'colors' => ['B']],
        'Rallier Lite' => ['manaCost' => '{2}{W}', 'type' => 'Creature — Human', 'text' => "Rallier Lite can't attack alone.", 'power' => '3', 'toughness' => '3', 'colors' => ['W']],
        'Peppersmoke Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "Target creature gets -1/-1 until end of turn.\nIf you control a Faerie, draw a card.", 'colors' => ['B']],
        'Windstorm Lite' => ['manaCost' => '{2}{G}', 'type' => 'Instant', 'text' => 'Windstorm Lite deals 4 damage to each creature with flying.', 'colors' => ['G']],
        'Snarecaster Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Cat', 'text' => 'When Snarecaster Lite enters, you may tap target creature.', 'power' => '1', 'toughness' => '1', 'colors' => ['W']],
        'Colonnade Lite' => ['type' => 'Land', 'text' => "{T}: Add {W}.\n{1}{W}: Until end of turn, Colonnade Lite becomes a 4/4 white Elemental creature with flying and vigilance. It's still a land."],
        'Construct Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => '{1}: Construct Lite becomes a 2/2 Construct artifact creature until end of turn.', 'colors' => []],
        'Wither X Lite' => ['manaCost' => '{X}{B}', 'type' => 'Instant', 'text' => 'Target creature gets -X/-X until end of turn.', 'colors' => ['B']],
        'Gruesome Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => "As an additional cost to cast this spell, sacrifice a creature or pay {3}.\nDraw two cards.", 'colors' => ['B']],
        'Elf Reveal Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => "As an additional cost to cast this spell, reveal an Elf card from your hand or pay {3}.\nTarget creature gets +2/+2 until end of turn.", 'colors' => ['G']],
        'Beast Within Lite' => ['manaCost' => '{2}{G}', 'type' => 'Instant', 'text' => 'Destroy target permanent. Its controller creates a 3/3 green Beast creature token.', 'colors' => ['G']],
        'Swords Lite' => ['manaCost' => '{W}', 'type' => 'Instant', 'text' => 'Exile target creature. Its controller gains life equal to its power.', 'colors' => ['W']],
        'Regen Aura Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature\n{G}: Regenerate enchanted creature.", 'colors' => ['G']],
        'Surge Lite' => ['manaCost' => '{3}{R}', 'type' => 'Instant', 'text' => "Surge {1}{R} (You may cast this spell for its surge cost if you've cast another spell this turn.)\nSurge Lite deals 3 damage to any target.\nIf Surge Lite's surge cost was paid, draw a card.", 'colors' => ['R']],
        'Bloodrush Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Ogre Warrior', 'power' => '3', 'toughness' => '3', 'text' => 'Bloodrush — {R}, Discard Bloodrush Lite: Target attacking creature gets +3/+3 until end of turn.', 'colors' => ['R']],
        'Bracket Walker Lite' => ['manaCost' => '{2}{U}{U}', 'type' => 'Legendary Planeswalker — Lite', 'supertypes' => ['Legendary'], 'types' => ['Planeswalker'], 'subtypes' => ['Lite'], 'loyalty' => '3', 'text' => "[+1]: Create a 1/1 blue Illusion creature token.\n[−2]: Bracket Walker Lite deals 3 damage to any target.\n[0]: You gain 1 life.", 'colors' => ['U']],
        'God Lite' => ['manaCost' => '{3}{W}', 'type' => 'Legendary Enchantment Creature — God', 'supertypes' => ['Legendary'], 'types' => ['Enchantment', 'Creature'], 'subtypes' => ['God'], 'power' => '5', 'toughness' => '5', 'text' => "Indestructible\nAs long as your devotion to white is less than five, God Lite isn't a creature.", 'colors' => ['W']],
        'Sword Pair Lite' => ['manaCost' => '{3}', 'type' => 'Artifact — Equipment', 'subtypes' => ['Equipment'], 'text' => "Equipped creature gets +2/+2 and has protection from black and from green.\nEquip {2}", 'colors' => []],
        'Spectacle Lite' => ['manaCost' => '{3}{B}', 'type' => 'Sorcery', 'text' => "Spectacle {B} (You may cast this spell for its spectacle cost rather than its mana cost if an opponent lost life this turn.)\nTarget player loses 2 life.\nIf Spectacle Lite's spectacle cost was paid, draw a card.", 'colors' => ['B']],
        'Cleave Lite' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => "Cleave {3}{W} (You may cast this spell for its cleave cost. If you do, remove the words in square brackets.)\nDestroy target [attacking] creature.", 'colors' => ['W']],
        'Wizard Staff Lite' => ['manaCost' => '{1}', 'type' => 'Artifact — Equipment', 'subtypes' => ['Equipment'], 'text' => "Equipped creature gets +2/+0.\nEquip Wizard {1}\nEquip {4}", 'colors' => []],
        'Were-Worm Lite' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Wurm', 'power' => '1', 'toughness' => '4', 'text' => 'Were-Worm Lite gets +2/+0 for each Mountain you control.', 'colors' => ['R']],
        'Uril Lite' => ['manaCost' => '{3}{G}', 'type' => 'Creature — Beast', 'power' => '2', 'toughness' => '2', 'text' => 'Uril Lite gets +2/+2 for each Aura attached to it.', 'colors' => ['G']],
        'Viashino Lite' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Viashino', 'power' => '3', 'toughness' => '3', 'text' => 'Whenever you cast a multicolored spell, Viashino Lite gets +3/+3 until end of turn.', 'colors' => ['R']],
        'Onna Lite' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '2', 'text' => "When Onna Lite enters, you gain 2 life.\nWhenever you cast a Spirit or Arcane spell, you may return Onna Lite to its owner's hand.", 'colors' => ['W']],
        'Channel Lite' => ['manaCost' => '{4}{R}', 'type' => 'Creature — Spirit', 'power' => '4', 'toughness' => '4', 'text' => 'Channel — {1}{R}, Discard Channel Lite: Channel Lite deals 2 damage to any target.', 'colors' => ['R']],
        'Reinforce Lite' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Kithkin', 'power' => '2', 'toughness' => '2', 'text' => 'Reinforce 2—{1}{W} ({1}{W}, Discard this card: Put two +1/+1 counters on target creature.)', 'colors' => ['W']],
        'Power-up Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human', 'power' => '1', 'toughness' => '1', 'text' => 'Power-up — {2}{W}: Put two +1/+1 counters on Power-up Lite.', 'colors' => ['W']],
        'Mayhem Lite' => ['manaCost' => '{3}{R}', 'type' => 'Sorcery', 'text' => "Mayhem Lite deals 3 damage to any target.\nMayhem {R} (You may cast this card from your graveyard for {R} if you discarded it this turn. Timing rules still apply.)", 'colors' => ['R']],
        'Recover Lite' => ['manaCost' => '{1}{B}', 'type' => 'Sorcery', 'text' => "Target player loses 1 life.\nRecover {1} (When a creature is put into your graveyard from the battlefield, you may pay {1}. If you do, return this card from your graveyard to your hand. Otherwise, exile this card.)", 'colors' => ['B']],
        'Amphitheater Lite' => ['type' => 'Land', 'text' => "As Amphitheater Lite enters, you may reveal a Giant card from your hand. If you don't, Amphitheater Lite enters tapped.\n{T}: Add {R} or {W}."],
        'Inspiration Lite' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => 'Attacking creatures get +2/+0 until end of turn.', 'colors' => ['W']],
        'Gremlins Lite' => ['manaCost' => '{X}{R}{R}', 'type' => 'Sorcery', 'text' => 'Create X 2/2 red Gremlin creature tokens.', 'colors' => ['R']],
        'Stomp Kick Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "Kicker {2}\nTarget creature gets -2/-2 until end of turn.\nIf this spell was kicked, that creature gets -5/-5 until end of turn instead.", 'colors' => ['B']],
        'Hatchling Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Elemental', 'power' => '2', 'toughness' => '2', 'text' => "{R}: Target creature can't block Hatchling Lite this turn.", 'colors' => ['R']],
        'Mastery Lite' => ['manaCost' => '{4}{B}{B}', 'type' => 'Instant', 'text' => "You may pay {1}{B} rather than pay this spell's mana cost.\nIf the {1}{B} cost was paid, an opponent draws a card.\nDestroy target creature.", 'colors' => ['B']],
        'Chrome Lite' => ['manaCost' => '{2}', 'type' => 'Artifact Creature — Construct', 'text' => 'Other artifact creatures you control get +1/+0.', 'power' => '1', 'toughness' => '1', 'colors' => []],
        'Giant Sac Lite' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Giant', 'text' => '{1}{R}, Sacrifice an artifact: Giant Sac Lite deals 2 damage to any target.', 'power' => '3', 'toughness' => '3', 'colors' => ['R']],
        'Vortex Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Elemental', 'text' => '{U}: Target creature blocks Vortex Lite this turn if able.', 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Strive Lite' => ['manaCost' => '{R}', 'type' => 'Instant', 'text' => "Strive — This spell costs {1}{R} more to cast for each target beyond the first.\nStrive Lite deals 2 damage to any number of target creatures.", 'colors' => ['R']],
        'Craft Lite // Crafted Lite' => ['manaCost' => '{1}', 'type' => 'Artifact', 'text' => "Craft with artifact {2} ({2}, Exile this artifact, Exile another artifact you control or an artifact card from your graveyard: Return this card transformed under its owner's control. Craft only as a sorcery.)", 'colors' => [], 'layout' => 'transform',
            'back' => ['name' => 'Crafted Lite', 'type' => 'Artifact Creature — Golem', 'manaCost' => null, 'power' => '4', 'toughness' => '4', 'text' => '', 'colors' => []]],
        'Webslinger Lite' => ['manaCost' => '{3}{W}', 'type' => 'Creature — Spider Human Hero', 'power' => '3', 'toughness' => '3', 'text' => "Web-slinging {W} (You may cast this spell for {W} if you also return a tapped creature you control to its owner's hand.)", 'colors' => ['W']],
        'Kozilek Lite' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Target player reveals their hand. You choose a nonland card from it with mana value 3 or less. That player discards that card.', 'colors' => ['B']],
        'Shortsword Lite' => ['manaCost' => '{1}', 'type' => 'Artifact — Equipment', 'text' => "Equipped creature gets +1/+1.\nWhen this Equipment enters, create a 2/2 red Dwarf creature token, then attach this Equipment to it.\nEquip {2}", 'colors' => []],
        'Two Kicker Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => "Kicker {1}{B} and/or {G}\nIf this spell was kicked with its {1}{B} kicker, you gain 2 life.\nIf this spell was kicked with its {G} kicker, you gain 3 life.\nDraw a card.", 'colors' => ['B', 'G']],
        'Wildebeest Lite' => ['manaCost' => '{1}{G}{G}', 'type' => 'Creature — Antelope Beast', 'power' => '5', 'toughness' => '4', 'text' => "Trample\nAt the beginning of your upkeep, return a green creature you control to its owner's hand.", 'colors' => ['G']],
        'Lecturer Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Human Druid', 'power' => '2', 'toughness' => '2', 'text' => "{T}: Add an amount of {G} equal to this creature's power.", 'colors' => ['G']],
        'Necro Lite' => ['manaCost' => '{B}{B}', 'type' => 'Legendary Enchantment', 'text' => 'Skip your draw step.', 'colors' => ['B']],
        'Tiered Lite' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => "Tiered (Choose one additional cost.)\n• Inspiration — {0} — Draw a card.\n• Insight — {1} — Draw two cards.\n• Illumination — {3}{U} — Draw three cards.", 'colors' => ['U']],
        'Paradigm Lite' => ['manaCost' => '{W}', 'type' => 'Sorcery — Lesson', 'text' => "You gain 2 life.\nParadigm (Then exile this spell. After you first resolve a spell with this name, you may cast a copy of it from exile without paying its mana cost at the beginning of each of your first main phases.)", 'colors' => ['W']],
        'Haunter Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Spirit', 'power' => '2', 'toughness' => '1', 'text' => "Haunt (When this creature dies, exile it haunting target creature.)\nWhen this creature enters or the creature it haunts dies, you gain 2 life.", 'colors' => ['W']],
        'Haunt Spell Lite' => ['manaCost' => '{W}', 'type' => 'Sorcery', 'text' => "You gain 1 life.\nHaunt (When this spell card is put into a graveyard after resolving, exile it haunting target creature.)\nWhen the creature this spell haunts dies, you gain 3 life.", 'colors' => ['W']],
        'Mutant Lite' => ['manaCost' => '{3}{G}{G}', 'type' => 'Creature — Beast', 'power' => '4', 'toughness' => '4', 'text' => "Mutate {2}{G} (If you cast this spell for its mutate cost, put it over or under target non-Human creature you own. They mutate into the creature on top plus all abilities from under it.)\nTrample\nWhenever this creature mutates, you gain 3 life.", 'colors' => ['G']],
        'Evidence Lite' => ['manaCost' => '{U}', 'type' => 'Sorcery', 'text' => "As an additional cost to cast this spell, you may collect evidence 4. (Exile cards with total mana value 4 or more from your graveyard.)\nDraw a card. If evidence was collected, draw two cards instead.", 'colors' => ['U']],
        'Chalice Lite' => ['manaCost' => '{X}{X}', 'type' => 'Artifact', 'text' => "Chalice Lite enters with X charge counters on it.\nWhenever a player casts a spell with mana value equal to the number of charge counters on Chalice Lite, counter that spell.", 'colors' => []],
        'Tail Swipe Lite' => ['manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => "Choose target creature you control and target creature you don't control. The creature you control gets +1/+1 until end of turn. Then those creatures fight each other.", 'colors' => ['G']],
        'Juggernaut Lite' => ['manaCost' => '{4}', 'type' => 'Artifact Creature — Juggernaut', 'power' => '5', 'toughness' => '3', 'text' => "Juggernaut Lite can't be blocked by Walls.", 'colors' => []],
        'Phantom Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Elemental Spirit', 'power' => '2', 'toughness' => '1', 'text' => 'When this creature dies, it deals damage equal to its power to any target.', 'colors' => ['R']],
        'Ramble Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => "You may play an additional land this turn.\nDraw a card.", 'colors' => ['G']],
        'Fowl Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => "Reinforce 2—{2}{G} ({2}{G}, Discard this card: Put two +1/+1 counters on target creature.)\nTarget creature gets +2/+2 until end of turn.", 'colors' => ['G']],
        'Kenway Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Assassin', 'power' => '2', 'toughness' => '2', 'text' => 'Protection from Elves', 'colors' => ['W']],
        'Confluence Lite' => ['manaCost' => '{3}{U}', 'type' => 'Instant', 'text' => "Choose three. You may choose the same mode more than once.\n• You gain 2 life.\n• Draw a card.", 'colors' => ['U']],
        'Savant Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Elf Shaman', 'power' => '1', 'toughness' => '1', 'text' => '{T}: Add one mana of any color. Spend this mana only to cast a creature spell.', 'colors' => ['G']],
        'Stomp Lite' => ['manaCost' => '{1}{R}', 'type' => 'Sorcery', 'text' => "Kicker—Sacrifice an artifact or creature.\nStomp Lite deals 2 damage to any target. If this spell was kicked, it deals 4 damage instead.", 'colors' => ['R']],
        'Recycle Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Return target creature card from your graveyard to your hand. Then shuffle your graveyard into your library.', 'colors' => ['G']],
        'Overlord Lite' => ['manaCost' => '{3}{G}{G}', 'type' => 'Enchantment Creature — Avatar', 'power' => '5', 'toughness' => '5', 'text' => "Impending 2—{1}{G}{G} (If you cast this spell for its impending cost, it enters with two time counters and isn't a creature until the last is removed. At the beginning of your end step, remove a time counter from it.)\nWhen Overlord Lite enters, you gain 2 life.", 'colors' => ['G']],
        'Sengir Lite' => ['manaCost' => '{3}{B}', 'type' => 'Creature — Vampire', 'power' => '2', 'toughness' => '2', 'text' => "{T}: Sengir Lite deals 2 damage to target creature.\nWhenever a creature dealt damage by Sengir Lite this turn dies, put a +1/+1 counter on Sengir Lite.", 'colors' => ['B']],
        'Snuff Lite' => ['manaCost' => '{3}{B}', 'type' => 'Instant', 'text' => "If you control a Swamp, you may pay 4 life rather than pay this spell's mana cost.\nDestroy target creature.", 'colors' => ['B']],
        'Breath Lite' => ['manaCost' => '{4}{R}', 'type' => 'Sorcery', 'text' => "You may cast this spell without paying its mana cost if you control a Dragon.\nBreath Lite deals 3 damage to any target.", 'colors' => ['R']],
        'Swipe Lite' => ['manaCost' => '{1}{G}', 'type' => 'Instant', 'text' => "Choose target creature you control and target creature you don't control. If you cast this spell during your main phase, the creature you control gets +1/+1 until end of turn. Then those creatures fight each other.", 'colors' => ['G']],
        'Salvage Lite' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'text' => "Draw a card. If you control an artifact, draw a card.", 'colors' => ['U']],
        'Mirror Lite' => ['manaCost' => '{3}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => 'Whenever you cast an instant or sorcery spell, copy that spell. You may choose new targets for the copy.', 'colors' => ['R']],
        'Twin Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Human Wizard', 'power' => '2', 'toughness' => '2', 'text' => 'Whenever you cast your second spell each turn, copy it. You may choose new targets for the copy.', 'colors' => ['R']],
        'Kinsong Lite' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'text' => 'Choose a creature type. Creatures you control of the chosen type get +2/+2 until end of turn.', 'colors' => ['W']],
        'Elfsong Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Elves you control get +1/+1 until end of turn.', 'colors' => ['G']],
        'Silence Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Cleric', 'power' => '2', 'toughness' => '1', 'text' => "Your opponents can't cast spells during your turn.", 'colors' => ['W']],
        'Warden Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'power' => '2', 'toughness' => '2', 'text' => "When Warden Lite enters, exile target nonland permanent an opponent controls.\nWhen Warden Lite leaves the battlefield, return the exiled card to its owner's hand.", 'colors' => ['W']],
        'Kiki Lite' => ['manaCost' => '{2}{R}', 'type' => 'Sorcery', 'text' => "Create a token that's a copy of target creature you control. It gains haste. Exile it at the beginning of the next end step.", 'colors' => ['R']],
        'Lantern Lite' => ['manaCost' => '{5}', 'type' => 'Artifact', 'text' => "Lands you control have \"{T}: Add one mana of any color.\"", 'colors' => []],
        'Toplook Lite' => ['manaCost' => '{2}{G}', 'type' => 'Enchantment', 'text' => 'You may cast creature spells from the top of your library.', 'colors' => ['G']],
        'Stop Lite' => ['manaCost' => '{4}{U}{U}', 'type' => 'Instant', 'text' => 'End the turn.', 'colors' => ['U']],
        'Bender Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Human Warrior Ally', 'text' => "When this creature enters, earthbend 2. (Target land you control becomes a 0/0 creature with haste that's still a land. Put two +1/+1 counters on it. When it dies or is exiled, return it to the battlefield tapped.)", 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Lander Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Human Scout', 'text' => 'When this creature enters, create a Lander token. (It\'s an artifact with "{2}, {T}, Sacrifice this token: Search your library for a basic land card, put it onto the battlefield tapped, then shuffle.")', 'power' => '2', 'toughness' => '2', 'colors' => ['G']],
        'Mapper Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Create two Map tokens.', 'colors' => ['G']],
        'Pitch Bolt Lite' => ['manaCost' => '{3}{R}{R}', 'type' => 'Instant', 'text' => "You may exile a red card from your hand rather than pay this spell's mana cost.\nThis spell deals 3 damage to any target.", 'colors' => ['R']],
        'Flare Lite' => ['manaCost' => '{2}{R}', 'type' => 'Instant', 'text' => "You may sacrifice a nontoken red creature rather than pay this spell's mana cost.\nThis spell deals 2 damage to any target.", 'colors' => ['R']],
        'Pitch Negate Lite' => ['manaCost' => '{1}{U}{U}', 'type' => 'Instant', 'text' => "If it's not your turn, you may exile a blue card from your hand rather than pay this spell's mana cost.\nCounter target noncreature spell.", 'colors' => ['U']],
        'Harness Lite' => ['manaCost' => '{1}{R}', 'type' => 'Instant', 'text' => 'Choose target creature. You get {E}{E}{E}, then you may pay any amount of {E}. This spell deals that much damage to that creature.', 'colors' => ['R']],
        'Charger Lite' => ['manaCost' => '{1}{G}', 'type' => 'Creature — Beast', 'text' => 'When this creature enters, you may pay any amount of {E}. Put that many +1/+1 counters on it.', 'power' => '1', 'toughness' => '1', 'colors' => ['G']],
        'Swarm Pump Lite' => ['manaCost' => '{G}', 'type' => 'Instant', 'text' => 'Target creature gets +X/+X until end of turn, where X is the number of creatures you control.', 'colors' => ['G']],
        'Firebreather Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Dragon', 'text' => '{X}{R}: This creature gets +X/+0 until end of turn.', 'power' => '2', 'toughness' => '2', 'colors' => ['R']],
        'X Pinger Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Human Wizard', 'text' => '{X}, {T}: This creature deals X damage to any target.', 'power' => '1', 'toughness' => '1', 'colors' => ['R']],
        'Welcome Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever another creature you control enters, put a +1/+1 counter on it.', 'colors' => ['G']],
        'Auto Sword Lite' => ['manaCost' => '{2}', 'type' => 'Artifact — Equipment', 'text' => "Whenever a creature you control enters, you may attach this Equipment to it.\nEquipped creature gets +2/+0.\nEquip {3}", 'colors' => []],
        'War Banner Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control attacks, it gets +1/+0 until end of turn.', 'colors' => ['R']],
        'Raid Horn Lite' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'text' => 'Whenever you attack, creatures you control get +0/+1 until end of turn.', 'colors' => ['R']],
        'Spy Net Lite' => ['manaCost' => '{2}{U}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control deals combat damage to a player, draw a card.', 'colors' => ['U']],
        'Blood Pact Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control dies, each opponent loses 1 life and you gain 1 life.', 'colors' => ['B']],
        'Drill Sergeant Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'At the beginning of combat on your turn, target creature you control gets +1/+0 until end of turn.', 'colors' => ['R']],
        'Disenchanter Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Cleric', 'text' => 'When this creature enters, destroy target artifact or enchantment an opponent controls.', 'power' => '2', 'toughness' => '2', 'colors' => ['W']],
        'Watcher Lite' => ['manaCost' => '{W}', 'type' => 'Enchantment', 'text' => 'Whenever an opponent casts a spell, you gain 1 life.', 'colors' => ['W']],
        'Lifewatch Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'At the beginning of your end step, if you gained life this turn, draw a card.', 'colors' => ['W']],
        'Raider Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Pirate', 'text' => 'At the beginning of your end step, if you attacked this turn, put a +1/+1 counter on this creature.', 'power' => '2', 'toughness' => '1', 'colors' => ['R']],
        'Bargainer Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Human Warlock', 'text' => 'When this creature enters, you may sacrifice another creature. If you do, draw two cards.', 'power' => '2', 'toughness' => '2', 'colors' => ['B']],
        'First Spark Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'Whenever you cast your first spell each turn, draw a card.', 'colors' => ['U']],
        'Raid Leader Lite' => ['manaCost' => '{2}{R}', 'type' => 'Creature — Goblin', 'text' => "Whenever this creature attacks, create a 1/1 red Goblin creature token that's tapped and attacking.", 'power' => '2', 'toughness' => '2', 'colors' => ['R']],
        'Scrap Finder Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Artificer', 'text' => 'When this creature enters, you may return target artifact card from your graveyard to your hand.', 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Bounce Ally Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Faerie', 'text' => "When this creature enters, return another target creature you control to its owner's hand.", 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Sweeper Lite' => ['manaCost' => '{2}{W}{W}', 'type' => 'Sorcery', 'text' => 'Destroy all creatures with power 3 or less.', 'colors' => ['W']],
        'Drain Lite' => ['manaCost' => '{2}{B}', 'type' => 'Sorcery', 'text' => 'Each opponent loses 3 life. You gain life equal to the life lost this way.', 'colors' => ['B']],
        'Noncreature Lite' => ['manaCost' => '{1}{B}{G}', 'type' => 'Instant', 'text' => 'Destroy target noncreature permanent.', 'colors' => ['B', 'G']],
        'Guard Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'text' => 'Whenever this creature blocks, it gets +1/+1 until end of turn.', 'power' => '1', 'toughness' => '3', 'colors' => ['W']],
        'Farewell Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Spirit', 'text' => 'When this creature leaves the battlefield, draw a card.', 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Vulture Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Whenever a creature an opponent controls dies, you gain 1 life.', 'colors' => ['B']],
        'Madcap Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'Whenever you discard a card, each opponent loses 1 life.', 'colors' => ['R']],
        'Cycler Lite' => ['manaCost' => '{4}{W}', 'type' => 'Creature — Bird', 'text' => "Flying\nWhen you cycle this card, you gain 2 life.\nCycling {1}", 'power' => '3', 'toughness' => '3', 'colors' => ['W']],
        'Cycle Fan Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'Whenever you cycle a card, you gain 1 life.', 'colors' => ['U']],
        'Elf Buddy Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Elf Warrior', 'text' => 'As long as you control another Elf, this creature gets +1/+1.', 'power' => '1', 'toughness' => '1', 'colors' => ['G']],
        'Shy Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Faerie', 'text' => "As long as it's not your turn, this creature has hexproof.\nThis creature gets +1/+0 as long as you have one or fewer cards in hand.", 'power' => '1', 'toughness' => '1', 'colors' => ['U']],
        'Thalia Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'text' => "First strike\nNoncreature spells cost {1} more to cast.", 'power' => '2', 'toughness' => '1', 'colors' => ['W']],
        'Tax Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Spells your opponents cast cost {1} more to cast.', 'colors' => ['W']],
        'Frost Gate Lite' => ['manaCost' => '{2}{W}', 'type' => 'Enchantment', 'text' => 'Creatures your opponents control enter tapped.', 'colors' => ['W']],
        'Sulfuric Lite' => ['manaCost' => '{2}{B}', 'type' => 'Enchantment', 'text' => "At the beginning of each opponent's upkeep, that player loses 1 life.", 'colors' => ['B']],
        'Painful Lite' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'text' => 'Whenever an opponent draws a card, this enchantment deals 1 damage to that player.', 'colors' => ['R']],
        'Spiteful Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => "Whenever a land enters under an opponent's control, that player discards a card.", 'colors' => ['B']],
        'Harvest Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Draw a card for each creature you control.', 'colors' => ['G']],
        'Spore Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Create X 1/1 green Saproling creature tokens, where X is the number of lands you control.', 'colors' => ['G']],
        'Ghostly Lite' => ['manaCost' => '{W}', 'type' => 'Sorcery', 'text' => 'Create a 1/1 white Spirit creature token with flying for each creature card in your graveyard.', 'colors' => ['W']],
        'Tally Lite' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Each opponent loses 1 life for each creature you control. You gain 2 life for each card in your hand.', 'colors' => ['B']],
        'Pale Edict Lite' => ['manaCost' => '{B}', 'type' => 'Sorcery', 'text' => 'Each opponent sacrifices a creature with the greatest power among creatures they control.', 'colors' => ['B']],
        'Salvage Mill Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Mill three cards, then return a creature card from your graveyard to your hand.', 'colors' => ['G']],
        'Swamp Shrink Lite' => ['manaCost' => '{B}', 'type' => 'Instant', 'text' => 'Target creature gets -X/-X until end of turn, where X is the number of Swamps you control.', 'colors' => ['B']],
        'Mob Rider Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Warrior', 'text' => 'Whenever Mob Rider Lite attacks, it gets +1/+0 until end of turn for each other attacking creature.', 'power' => '1', 'toughness' => '2', 'colors' => ['R']],
        'Crowd Muse Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'At the beginning of your end step, if you control three or more creatures, draw a card.', 'colors' => ['U']],
        'Empty Hand Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'At the beginning of your upkeep, if you have no cards in hand, draw a card.', 'colors' => ['B']],
        'Sea Loot Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Merfolk Rogue', 'power' => 1, 'toughness' => 2, 'text' => 'When Sea Loot Lite enters, you may draw a card. If you do, discard a card.', 'colors' => ['U']],
        'Fierce Eye Lite' => ['manaCost' => '{2}{G}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control with power 4 or greater enters, draw a card.', 'colors' => ['G']],
        'Fierce Beast Lite' => ['manaCost' => '{3}{G}', 'type' => 'Creature — Beast', 'power' => 4, 'toughness' => 4, 'text' => 'Whenever a creature you control with power 4 or greater enters, you gain 2 life.', 'colors' => ['G']],
        'Altar Hymn Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Whenever you sacrifice a permanent, you gain 1 life.', 'colors' => ['B']],
        'Grim Taker Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Human Cleric', 'power' => 3, 'toughness' => 3, 'text' => 'Whenever you sacrifice another creature, each opponent loses 1 life.', 'colors' => ['B']],
        'Gloat Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Whenever an opponent sacrifices a creature, you gain 1 life.', 'colors' => ['B']],
        'Mayhem Imp Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Imp', 'power' => 2, 'toughness' => 2, 'text' => 'Whenever a player sacrifices a permanent, Mayhem Imp Lite deals 1 damage to each opponent.', 'colors' => ['R']],
        'Grave Tally Lite' => ['manaCost' => '{2}{B}', 'type' => 'Enchantment', 'text' => 'Whenever a nontoken creature you control dies, draw a card.', 'colors' => ['B']],
        'Tally Hound Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Dog', 'power' => 2, 'toughness' => 2, 'text' => 'Whenever a nontoken creature you control dies, draw a card.', 'colors' => ['B']],
        'Scrap Bell Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => "Whenever an artifact you control is put into a graveyard from the battlefield, each opponent loses 1 life.\n{T}, Sacrifice Scrap Bell Lite: You gain 1 life.", 'colors' => []],
        'Scrap Shard Lite' => ['manaCost' => '{1}', 'type' => 'Artifact', 'text' => '{T}, Sacrifice Scrap Shard Lite: You gain 1 life.', 'colors' => []],
        'Spite Eye Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'Whenever an opponent casts a noncreature spell, Spite Eye Lite deals 1 damage to that player.', 'colors' => ['R']],
        'Raid Hound Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Dog', 'power' => 1, 'toughness' => 1, 'text' => 'Whenever Raid Hound Lite attacks, defending player loses 1 life.', 'colors' => ['B']],
        'Ember Cache Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'Whenever you discard one or more cards, Ember Cache Lite deals 1 damage to each opponent.', 'colors' => ['R']],
        'Tidy Wits Lite' => ['manaCost' => '{U}', 'type' => 'Sorcery', 'text' => 'Draw two cards, then discard two cards.', 'colors' => ['U']],
        'Return Pup Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Dog', 'power' => 1, 'toughness' => 1, 'text' => "When Return Pup Lite dies, return it to its owner's hand.", 'colors' => ['B']],
        'Back Again Lite' => ['manaCost' => '{2}{B}', 'type' => 'Creature — Spirit', 'power' => 1, 'toughness' => 1, 'text' => "When Back Again Lite dies, return it to the battlefield tapped under its owner's control.", 'colors' => ['B']],
        'Sink Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Fish', 'power' => 1, 'toughness' => 1, 'text' => "When Sink Lite dies, put it on the bottom of its owner's library.", 'colors' => ['U']],
        'Watchful Eye Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control becomes the target of a spell or ability an opponent controls, draw a card.', 'colors' => ['U']],
        'Wary Elk Lite' => ['manaCost' => '{2}{G}', 'type' => 'Creature — Elk', 'power' => 2, 'toughness' => 4, 'text' => 'Whenever Wary Elk Lite becomes the target of a spell or ability an opponent controls, you gain 2 life.', 'colors' => ['G']],
        'Brawny Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human Soldier', 'power' => 2, 'toughness' => 2, 'text' => 'Whenever Brawny Lite attacks or blocks, you gain 1 life.', 'colors' => ['W']],
        'Scout Pilot Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Pilot', 'power' => 1, 'toughness' => 1, 'text' => 'Whenever Scout Pilot Lite deals combat damage to a player or planeswalker, draw a card.', 'colors' => ['U']],
        'Life Warden Lite' => ['manaCost' => '{W}', 'type' => 'Creature — Human Cleric', 'power' => 1, 'toughness' => 1, 'text' => 'Whenever another creature enters, you gain 1 life.', 'colors' => ['W']],
        'Grudge Eye Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'Whenever a creature an opponent controls enters, Grudge Eye Lite deals 1 damage to each opponent.', 'colors' => ['R']],
        'Lone Raider Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Warrior', 'power' => 2, 'toughness' => 2, 'text' => 'Whenever Lone Raider Lite attacks alone, it gets +2/+2 until end of turn.', 'colors' => ['R']],
        'Lone Banner Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control attacks alone, it gains lifelink until end of turn.', 'colors' => ['W']],
        'Hoard Eye Lite' => ['manaCost' => '{2}{U}', 'type' => 'Enchantment', 'text' => 'Whenever you cast a spell with mana value 5 or greater, draw a card.', 'colors' => ['U']],
        'Dragon Lover Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Shaman', 'power' => 1, 'toughness' => 2, 'text' => 'Whenever you cast a Dragon spell, you gain 2 life.', 'colors' => ['R']],
        'Hoard Wurm Lite' => ['manaCost' => '{4}{G}', 'type' => 'Creature — Wurm', 'power' => 5, 'toughness' => 5, 'text' => '', 'colors' => ['G']],
        'Spite Hound Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Dog', 'power' => 2, 'toughness' => 2, 'text' => 'Whenever Spite Hound Lite deals damage to a player, that player discards a card.', 'colors' => ['B']],
        'Spite Pinger Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Goblin Shaman', 'power' => 1, 'toughness' => 1, 'text' => "{T}: Spite Pinger Lite deals 1 damage to any target.\nWhenever Spite Pinger Lite deals damage to an opponent, you gain 2 life.", 'colors' => ['R']],
        'Howling Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => "At the beginning of each player's upkeep, that player draws a card.", 'colors' => []],
        'Torment Altar Lite' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'text' => "At the beginning of each player's upkeep, Torment Altar Lite deals 1 damage to that player.", 'colors' => ['R']],
        'Carrion Feeder Lite' => ['manaCost' => '{B}', 'type' => 'Creature — Zombie', 'power' => 1, 'toughness' => 1, 'text' => 'Whenever a creature dies, you gain 1 life.', 'colors' => ['B']],
        'Busy Square Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Whenever a player casts a spell, you gain 1 life.', 'colors' => ['W']],
        'Spell Watcher Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'Whenever a player casts an instant or sorcery spell, you gain 1 life.', 'colors' => ['U']],
        'Bloodbond Bat Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Bat', 'power' => 1, 'toughness' => 1, 'text' => 'Whenever an opponent loses life, put a +1/+1 counter on Bloodbond Bat Lite.', 'colors' => ['B']],
        'Tinker Eye Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'Whenever an artifact enters under your control, you gain 1 life.', 'colors' => ['U']],
        'Scrap Collector Lite' => ['manaCost' => '{2}', 'type' => 'Artifact', 'text' => 'Whenever an artifact you control enters, draw a card.', 'colors' => []],
        'Land Count Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever a land enters, you gain 1 life.', 'colors' => ['G']],
        'Pain Eater Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Horror', 'power' => 1, 'toughness' => 3, 'text' => 'Whenever you lose life, put a +1/+1 counter on Pain Eater Lite.', 'colors' => ['B']],
        'Life Spite Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Whenever an opponent gains life, draw a card.', 'colors' => ['B']],
        'Seer Eye Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'Whenever you scry, you gain 1 life.', 'colors' => ['U']],
        'Spiral Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Whenever a player draws a card, that player loses 1 life.', 'colors' => ['B']],
        'Opt Lite' => ['manaCost' => '{U}', 'type' => 'Instant', 'text' => 'Scry 1. Draw a card.', 'colors' => ['U']],
        'Token Fan Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever you create one or more tokens, you gain 1 life.', 'colors' => ['G']],
        'Map Fan Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever you create a Map token, you gain 2 life.', 'colors' => ['G']],
        'Night Toll Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => "At the beginning of each opponent's end step, that player loses 1 life.", 'colors' => ['B']],
        'Battle Hymn Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'At the beginning of each combat, you gain 1 life.', 'colors' => ['W']],
        'Dawn Draw Lite' => ['manaCost' => '{1}{U}', 'type' => 'Enchantment', 'text' => 'At the beginning of your draw step, you gain 1 life.', 'colors' => ['U']],
        'Watch Post Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature attacks you, you gain 1 life.', 'colors' => ['W']],
        'Shieldmate Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control blocks, it gets +0/+2 until end of turn.', 'colors' => ['W']],
        'Raid Captain Lite' => ['manaCost' => '{1}{R}', 'type' => 'Enchantment', 'text' => 'Whenever one or more creatures you control deal combat damage to a player, draw a card.', 'colors' => ['R']],
        'Exodus Eye Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control leaves the battlefield, you gain 1 life.', 'colors' => ['W']],
        'Land Joy Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever you play a land, you gain 2 life.', 'colors' => ['G']],
        'Outlaw Lite' => ['manaCost' => '{1}{R}', 'type' => 'Creature — Human Rogue', 'power' => 2, 'toughness' => 2, 'text' => 'Whenever you commit a crime, put a +1/+1 counter on Outlaw Lite.', 'colors' => ['R']],
        'Expend Rabbit Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Rabbit', 'power' => 1, 'toughness' => 1, 'text' => 'Whenever you expend 4, put a +1/+1 counter on Expend Rabbit Lite.', 'colors' => ['G']],
        'Engine Fan Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever you activate an exhaust ability, you gain 2 life.', 'colors' => ['G']],
        'Busy Hands Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => "Whenever you activate an ability that isn't a mana ability, you gain 1 life.", 'colors' => ['W']],
        'Tap Tithe Lite' => ['manaCost' => '{1}{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control becomes tapped, you gain 1 life.', 'colors' => ['W']],
        'Masked Lite' => ['manaCost' => '{3}{G}', 'type' => 'Creature — Elf', 'power' => 3, 'toughness' => 3, 'text' => 'Morph {1}{G}', 'colors' => ['G']],
        'Unmask Fan Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever a permanent you control is turned face up, you gain 2 life.', 'colors' => ['G']],
        'Explore Fan Lite' => ['manaCost' => '{1}{G}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control explores, you gain 1 life.', 'colors' => ['G']],
        'Dig Up Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Mill four cards. You may put a land card from among them into your hand.', 'colors' => ['G']],
        'Dig Beast Lite' => ['manaCost' => '{1}{G}', 'type' => 'Sorcery', 'text' => 'Mill four cards. You may put a creature card from among them into your hand.', 'colors' => ['G']],
        'Forager Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Squirrel', 'power' => 1, 'toughness' => 1, 'text' => '{T}, Forage: Put a +1/+1 counter on Forager Lite.', 'colors' => ['G']],
        'Food Maker Lite' => ['manaCost' => '{G}', 'type' => 'Sorcery', 'text' => 'Create a Food token.', 'colors' => ['G']],
        'Grave Scholar Lite' => ['manaCost' => '{1}{U}', 'type' => 'Creature — Human Wizard', 'power' => 1, 'toughness' => 2, 'text' => '{1}, Exile two cards from your graveyard: Draw a card.', 'colors' => ['U']],
        'Taskmaster Lite' => ['manaCost' => '{1}{W}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => 'Tap two untapped creatures you control: Draw a card.', 'colors' => ['W']],
        'Grave Eater Lite' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie', 'power' => 3, 'toughness' => 3, 'text' => '{T}, Exile a creature card from your graveyard: You gain 2 life.', 'colors' => ['B']],
        'Pyre Lite' => ['manaCost' => '{1}{B}', 'type' => 'Enchantment', 'text' => 'Sacrifice two creatures: Draw two cards.', 'colors' => ['B']],
        'Mill Seer Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => '{T}, Mill a card: You gain 1 life.', 'colors' => ['U']],
        'Bounce Seer Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => "Return a land you control to its owner's hand: Draw a card.", 'colors' => ['U']],
        'Counter Sage Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Elf', 'power' => 3, 'toughness' => 3, 'text' => 'Remove a +1/+1 counter from a creature you control: You gain 2 life.', 'colors' => ['G']],
        'Retreat Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => "{1}, Return CARDNAME to its owner's hand: Draw a card.", 'colors' => ['U']],
        'Token Mourner Lite' => ['manaCost' => '{B}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => 'Whenever a creature token you control dies, you gain 2 life.', 'colors' => ['B']],
        'Pain Ledger Lite' => ['manaCost' => '{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature deals combat damage to you, you gain 1 life.', 'colors' => ['W']],
        'Ward Watch Lite' => ['manaCost' => '{W}', 'type' => 'Enchantment', 'text' => 'Whenever a creature you control becomes the target of a spell or ability, you gain 1 life.', 'colors' => ['W']],
        'Self Focus Lite' => ['manaCost' => '{U}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => 'Whenever CARDNAME becomes the target of a spell or ability you control, draw a card.', 'colors' => ['U']],
        'Thorn Guard Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Plant', 'power' => 2, 'toughness' => 4, 'text' => 'Whenever CARDNAME blocks a creature, that creature gets -1/-0 until end of turn.', 'colors' => ['G']],
        'Land Mourner Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment', 'text' => "Whenever a land you control is put into a graveyard from the battlefield, you gain 2 life.\nWhenever a Treasure you control is put into a graveyard, you gain 1 life.", 'colors' => ['G']],
        'Land Burner Lite' => ['manaCost' => '{R}', 'type' => 'Creature — Human', 'power' => 3, 'toughness' => 3, 'text' => "Sacrifice a land: Draw a card.\nSacrifice a Treasure: Draw a card.", 'colors' => ['R']],
        'Big Caller Lite' => ['manaCost' => '{G}', 'type' => 'Enchantment', 'text' => 'Whenever you cast a creature spell with power 4 or greater, you gain 2 life.', 'colors' => ['G']],
        'Big Beast Lite' => ['manaCost' => '{G}', 'type' => 'Creature — Beast', 'power' => 4, 'toughness' => 4, 'text' => '', 'colors' => ['G']],
        'Escalate Lite' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'text' => "Escalate {1} (Pay this cost for each mode chosen beyond the first.)\nChoose one or more —\n• Escalate Lite deals 2 damage to target player.\n• You gain 2 life.", 'colors' => ['R']],
        'Small Aura Lite' => ['manaCost' => '{W}', 'type' => 'Enchantment — Aura', 'text' => "Enchant creature with power 3 or less\nEnchanted creature has flying.", 'colors' => ['W']],
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

    public function testScavengeAndFilterLands(): void
    {
        $game = $this->newGame();
        foreach (['Scavenge Lite', 'Filter Land Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Scavenge: exiled from the graveyard for counters equal to its power.
        $ooze = $game->addCard(0, self::more('Scavenge Lite'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Forest', 2);
        $this->assertNotContains(['id' => $ooze, 'how' => 'scavenge'], $game->plays(0), 'No creature for the counters.');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->assertContains(['id' => $ooze, 'how' => 'scavenge'], $game->plays(0));
        $game->embalm(0, $ooze, 'scavenge');
        $this->assertSame(GameObject::EXILE, $this->zone($ooze));
        $this->resolve();
        $this->assertSame(2, $game->objects[$bears]->counter('+1/+1'));

        // A filter land turns one colored mana into two of its colors.
        $spell = $this->put(0, 'Double White Lite', GameObject::HAND);
        $filter = $this->put(0, 'Filter Land Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 2);
        $this->assertNotContains(['id' => $spell, 'how' => ''], $game->plays(0), 'Forests can\'t pay {W/U}.');
        $this->lands(0, 'Plains', 1);
        $this->assertContains(['id' => $spell, 'how' => ''], $game->plays(0));
        $game->cast(0, $spell);
        $this->assertTrue($game->objects[$filter]->tapped);
        $this->resolve();
        $this->assertSame(22, $this->life(0));
    }

    public function testFreerunning(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Freerun Lite')->unsupported);
        $assassin = $this->put(0, 'Freerun Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 2);
        $freerun = $this->put(0, 'Freerun Lite', GameObject::HAND);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertNotContains(['id' => $freerun, 'how' => 'freerunning'], $game->plays(0), 'No combat damage yet.');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$assassin]);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->assertSame(17, $this->life(1));
        $this->assertContains(['id' => $freerun, 'how' => 'freerunning'], $game->plays(0));
        $game->cast(0, $freerun, 0, [], 'freerunning');
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($freerun));
    }

    public function testMoreEnchantKinds(): void
    {
        $game = $this->newGame();
        foreach (['Tapped Aura Lite', 'Forest Aura Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $forest = $this->battlefield(1, 'Forest');
        $plains = $this->battlefield(1, 'Plains');
        $this->assertSame([], $game->targetOptions(0, 'tapped_creature'));
        $game->objects[$bears]->tapped = true;
        $this->assertSame(["o:{$bears}"], $game->targetOptions(0, self::read('Tapped Aura Lite')->aura['enchant']));
        $kind = self::read('Forest Aura Lite')->aura['enchant'];
        $this->assertTrue($game->isLegalTarget($kind, "o:{$forest}", 0));
        $this->assertFalse($game->isLegalTarget($kind, "o:{$plains}", 0));
    }

    public function testCombinedEnchantKinds(): void
    {
        $game = $this->newGame();
        foreach (['Mixed Aura Lite', 'Color Aura Lite', 'Small Aura Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $forest = $this->battlefield(0, 'Forest');
        $this->assertSame(["o:{$mine}"], $game->targetOptions(0, self::read('Mixed Aura Lite')->aura['enchant']));
        $this->assertFalse($game->isLegalTarget(self::read('Mixed Aura Lite')->aura['enchant'], "o:{$forest}", 0));
        // Grizzly Bears is green and has power 2.
        $this->assertSame(["o:{$mine}", "o:{$theirs}"], $game->targetOptions(0, self::read('Color Aura Lite')->aura['enchant']));
        $this->assertSame(["o:{$mine}", "o:{$theirs}"], $game->targetOptions(0, self::read('Small Aura Lite')->aura['enchant']));
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
        $this->passUntil(Step::Upkeep, 5);
        $this->resolve();
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

    public function testRevealHandVariants(): void
    {
        $game = $this->newGame();
        foreach (['Inquisition Lite', 'Peek Hand Lite', 'Untapper Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Swamp', 1);
        $bolt = $this->hand(1, 'Lightning Bolt');
        $giant = $this->hand(1, 'Hill Giant');
        $this->hand(1, 'Forest');
        $inquisition = $this->put(0, 'Inquisition Lite', GameObject::HAND);
        $game->cast(0, $inquisition, 0, ['p:1']);
        $this->resolve();
        // Only Lightning Bolt is a nonland card with mana value 3 or less.
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertSame(GameObject::HAND, $this->zone($giant));

        $this->lands(0, 'Island', 1);
        $peek = $this->put(0, 'Peek Hand Lite', GameObject::HAND);
        $before = count($game->players[0]->hand);
        $game->cast(0, $peek, 0, ['p:1']);
        $this->resolve();
        $this->assertCount($before, $game->players[0]->hand, 'Peek left the hand and a card was drawn.');
    }

    public function testDamageThatCantBeRegenerated(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('No Regen Burn Lite')->unsupported);
        $this->lands(0, 'Mountain', 1);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $game->objects[$bears]->shields = 1;
        $burn = $this->put(0, 'No Regen Burn Lite', GameObject::HAND);
        $game->cast(0, $burn, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
    }

    public function testShieldCounters(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Shield Lite')->unsupported);
        $this->lands(0, 'Plains', 2);
        $shield = $this->put(0, 'Shield Lite', GameObject::HAND);
        $this->passUntil(Step::PrecombatMain, 1);
        $game->cast(0, $shield);
        $this->resolve();
        $this->assertSame(1, $game->objects[$shield]->counter('shield'));
        $this->lands(0, 'Mountain', 2);
        foreach ([GameObject::BATTLEFIELD, GameObject::GRAVEYARD] as $zone) {
            $bolt = $this->hand(0, 'Lightning Bolt');
            $game->cast(0, $bolt, 0, ["o:{$shield}"]);
            $this->resolve();
            $this->assertSame($zone, $this->zone($shield));
        }
    }

    public function testIslandAttackersLuresAndChargeMana(): void
    {
        $game = $this->newGame();
        foreach (['Island Walker Lite', 'Lure Beast Lite', 'Charge Relic Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $walker = $this->put(0, 'Island Walker Lite', GameObject::BATTLEFIELD);
        $lure = $this->put(0, 'Lure Beast Lite', GameObject::BATTLEFIELD);
        $first = $this->battlefield(1, 'Grizzly Bears');
        $second = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 3);
        $this->assertNotContains($walker, $game->attackCandidates(), 'No Island on the other side.');
        $this->battlefield(1, 'Island');
        $this->assertContains($walker, $game->attackCandidates());
        $game->declareAttackers(0, [$lure]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, []);
        $this->assertSame([$first => $lure, $second => $lure], $game->blockers);

        $relic = $this->put(0, 'Charge Relic Lite', GameObject::BATTLEFIELD);
        $game->objects[$relic]->counters['charge'] = 1;
        $this->assertArrayHasKey($relic, $game->manaSources(0));
        $game->objects[$relic]->counters['charge'] = 0;
        $this->assertArrayNotHasKey($relic, $game->manaSources(0), 'No charge counter left.');
    }

    public function testAmassAndPermanentRegrowth(): void
    {
        $game = $this->newGame();
        foreach (['Amass Lite', 'Permanent Regrowth Lite', 'Lazy Untap Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Swamp', 4);
        $this->passUntil(Step::PrecombatMain, 1);
        foreach ([2, 4] as $counters) {
            $amass = $this->put(0, 'Amass Lite', GameObject::HAND);
            $game->cast(0, $amass);
            $this->resolve();
            $armies = array_values(array_filter($game->permanents(0), fn (GameObject $o) => in_array('Army', $o->definition()->subtypes, true)));
            $this->assertCount(1, $armies, 'The second amass grows the same Army.');
            $this->assertContains('Orc', $armies[0]->definition()->subtypes);
            $this->assertSame($counters, $armies[0]->counter('+1/+1'));
        }

        $this->lands(0, 'Forest', 2);
        $relic = $game->addCard(0, self::more('Charge Relic Lite'), GameObject::GRAVEYARD)->id;
        $bolt = $game->addCard(0, self::card('Lightning Bolt'), GameObject::GRAVEYARD)->id;
        $regrowth = $this->put(0, 'Permanent Regrowth Lite', GameObject::HAND);
        $this->assertSame(["o:{$relic}"], $game->targetOptions(0, 'permanent_card_yours'), 'Not Lightning Bolt.');
        $game->cast(0, $regrowth, 0, ["o:{$relic}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($relic));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
    }

    public function testFreezeAurasCrowdsAndTucks(): void
    {
        $game = $this->newGame();
        foreach (['Freeze Aura Lite', 'Crowd Lite', 'Tuck Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Island', 4);
        $land = $this->battlefield(1, 'Forest');
        $this->passUntil(Step::PrecombatMain, 1);
        $aura = $this->put(0, 'Freeze Aura Lite', GameObject::HAND);
        $game->cast(0, $aura, 0, ["o:{$land}"]);
        $this->resolve();
        $this->resolve();
        $this->assertTrue($game->objects[$land]->tapped);
        $this->assertContains("doesn't untap", $game->keywords($game->objects[$land]));

        $bears = $this->battlefield(1, 'Grizzly Bears');
        $tuck = $this->put(0, 'Tuck Lite', GameObject::HAND);
        $game->cast(0, $tuck, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::LIBRARY, $this->zone($bears));
        $this->assertSame($bears, end($game->players[1]->library), 'On top.');

        $crowd = $this->put(0, 'Crowd Lite', GameObject::BATTLEFIELD);
        $this->assertSame(3, $game->minBlockers($game->objects[$crowd]));
        $first = $this->battlefield(1, 'Grizzly Bears');
        $second = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$crowd]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        try {
            $game->declareBlockers(1, [$first => $crowd, $second => $crowd]);
            $this->fail('Two blockers are not enough.');
        } catch (GameException) {
        }
        $game->declareBlockers(1, []);
        $this->assertSame([], $game->blockers);
    }

    public function testTeamCountersPumpsAndRituals(): void
    {
        $game = $this->newGame();
        foreach (['Team Counters Lite', 'Double Pump Lite', 'Ritual Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Forest', 3);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::PrecombatMain, 1);
        $elf = $this->put(0, 'Team Counters Lite', GameObject::HAND);
        $game->cast(0, $elf);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->objects[$bears]->counter('+1/+1'));
        $this->assertSame(0, $game->objects[$elf]->counter('+1/+1'), 'Other creatures only.');
        $this->assertSame(0, $game->objects[$theirs]->counter('+1/+1'));

        $pump = $this->put(0, 'Double Pump Lite', GameObject::HAND);
        $game->cast(0, $pump, 0, ["o:{$bears}", "o:{$elf}"]);
        $this->resolve();
        $this->assertSame(5, $game->power($game->objects[$bears]));
        $this->assertContains('trample', $game->keywords($game->objects[$elf]));

        $this->lands(0, 'Mountain', 1);
        $ritual = $this->put(0, 'Ritual Lite', GameObject::HAND);
        $game->cast(0, $ritual);
        $this->resolve();
        $this->assertSame(3, $game->players[0]->manaPool->total());
    }

    public function testOutlastShrinkAndDoubleThreaten(): void
    {
        $game = $this->newGame();
        foreach (['Outlast Lite', 'Shrink Lite', 'Double Threaten Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Plains', 2);
        $outlast = $this->put(0, 'Outlast Lite', GameObject::BATTLEFIELD);
        $game->objects[$outlast]->sick = false;
        $this->passUntil(Step::PrecombatMain, 1);
        $game->activate(0, $outlast, 0);
        $this->resolve();
        $this->assertSame(1, $game->objects[$outlast]->counter('+1/+1'));
        $this->assertTrue($game->objects[$outlast]->tapped);

        $mine = $this->battlefield(0, 'Grizzly Bears');
        $first = $this->battlefield(1, 'Grizzly Bears');
        $second = $this->battlefield(1, 'Grizzly Bears');
        $this->put(0, 'Shrink Lite', GameObject::BATTLEFIELD);
        $this->assertSame(1, $game->power($game->objects[$first]));
        $this->assertSame(2, $game->power($game->objects[$mine]));

        $game->objects[$first]->tapped = $game->objects[$second]->tapped = true;
        $this->lands(0, 'Mountain', 4);
        $threaten = $this->put(0, 'Double Threaten Lite', GameObject::HAND);
        $game->cast(0, $threaten, 0, ["o:{$first}", "o:{$second}"]);
        $this->resolve();
        foreach ([$first, $second] as $id) {
            $this->assertSame(0, $game->objects[$id]->controller);
            $this->assertFalse($game->objects[$id]->tapped);
            $this->assertContains('haste', $game->keywords($game->objects[$id]));
            $this->assertSame(2, $game->power($game->objects[$id]), 'No longer shrunk while borrowed.');
        }
    }

    public function testSmotherXCountersAndImpulseToday(): void
    {
        $game = $this->newGame();
        foreach (['Smother Lite', 'X Leak Lite', 'Impulse Today Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertSame('creature_mv_le_3', self::read('Smother Lite')->effects[0]['target']);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $giant = $this->battlefield(1, 'Hill Giant');
        $this->assertSame(["o:{$bears}"], $game->targetOptions(0, 'creature_mv_le_3'));
        $this->assertNotContains("o:{$giant}", $game->targetOptions(0, 'creature_mv_le_3'));

        // {X} with X = 2: the controller pays {2} when it can.
        $this->lands(0, 'Mountain', 3);
        $this->lands(1, 'Island', 3);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $leak = $this->put(1, 'X Leak Lite', GameObject::HAND);
        $game->cast(0, $bolt, 0, ['p:1']);
        $game->pass(0);
        $game->cast(1, $leak, 2, ['s:'.$game->stack[0]['id']]);
        $this->resolve();
        $this->assertStringContainsString('pays {2}, so Lightning Bolt is not countered.', implode("\n", $game->log));
        $this->resolve();

        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Mountain', 3);
        $impulse = $this->put(0, 'Impulse Today Lite', GameObject::HAND);
        $game->cast(0, $impulse);
        $this->resolve();
        $exiled = array_values(array_filter($game->objects, fn (GameObject $o) => $o->exiledBy === 'impulse'));
        $this->assertCount(3, $exiled);
        $this->assertSame($game->turn, $exiled[0]->exiledOn, 'Playable this turn only.');
    }

    public function testTransmuteAndChosenProtection(): void
    {
        $game = $this->newGame();
        foreach (['Transmute Lite', 'Transmute Beast Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Discard it as a sorcery to find a card with the same mana value.
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $game->addCard(0, self::card('Lightning Bolt'), GameObject::LIBRARY);
        $card = $this->put(0, 'Transmute Lite', GameObject::HAND);
        $this->assertNotContains(['id' => $card, 'how' => 'transmute'], $game->plays(0), 'Needs {1}{B}{B}.');
        $this->lands(0, 'Swamp', 3);
        $this->assertContains(['id' => $card, 'how' => 'transmute'], $game->plays(0));
        $game->transmute(0, $card);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($card));
        $this->resolve();
        $this->assertSame([$bears], $game->choiceAwaiting()['eligible']);
        $game->take(0, [$bears]);
        $this->assertSame(GameObject::HAND, $this->zone($bears));

        // Protection from the color of your choice: the color the opponent plays most.
        $mother = $this->put(0, 'Mother Lite', GameObject::BATTLEFIELD);
        $this->assertSame([], self::read('Mother Lite')->unsupported);
        $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->activate(0, $mother, 0, ["o:{$mother}"]);
        $this->resolve();
        $this->assertTrue($game->hasKeyword($game->objects[$mother], 'protection from red'));
    }

    public function testBlockAnAdditionalCreature(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Two Block Lite')->unsupported);
        $first = $this->battlefield(0, 'Grizzly Bears');
        $second = $this->battlefield(0, 'Grizzly Bears');
        $plain = $this->battlefield(1, 'Grizzly Bears');
        $wall = $this->put(1, 'Two Block Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$first, $second]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        try {
            $game->declareBlockers(1, [$plain => [$first, $second]]);
            $this->fail('Grizzly Bears can block only one attacker.');
        } catch (GameException) {
        }
        $game->declareBlockers(1, [$wall => [$first, $second]]);
        $this->assertSame([$wall => $first], $game->blockers);
        $this->assertSame([$wall => [$second]], $game->extraBlocks);
        $this->assertArrayHasKey($second, $game->blocked);
        $this->assertSame([$wall => [$second]], Game::fromArray(json_decode(json_encode($game->toArray()), true))->extraBlocks, 'Saved with the match.');
        while ($game->step !== Step::EndCombat) {
            $game->pass($game->priority);
        }
        // 3 damage: lethal 2 to the first, the rest to the second; it takes 4 and survives.
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($first));
        $this->assertSame(1, $game->objects[$second]->damage);
        $this->assertSame(4, $game->objects[$wall]->damage);
        $this->assertSame(20, $game->players[1]->life);
    }

    public function testLearnAndGraveyardHate(): void
    {
        $game = $this->newGame();
        foreach (['Learn Lite', 'Grave Hate Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Learn: discard a card to draw a card, or not.
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $bolt = $game->addCard(0, self::card('Lightning Bolt'), GameObject::HAND)->id;
        $this->lands(0, 'Mountain', 2);
        $game->cast(0, $this->put(0, 'Learn Lite', GameObject::HAND), 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertTrue($game->choiceAwaiting()['may']);
        $hand = count($game->players[0]->hand);
        $game->discard(0, [$bolt]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt));
        $this->assertCount($hand, $game->players[0]->hand, 'Discarded one, drew one.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));

        // Exile target player's graveyard.
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Grave Hate Lite', GameObject::HAND), 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($bears));
        $this->assertSame([], $game->players[1]->graveyard);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bolt), 'Only that player\'s graveyard.');
    }

    public function testShuffleBackFromGraveyardAndCompleated(): void
    {
        $game = $this->newGame();
        foreach (['Titan Lite', 'Compleated Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Destroyed, it goes back into its owner's library.
        $titan = $this->put(1, 'Titan Lite', GameObject::BATTLEFIELD);
        $library = count($game->players[1]->library);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $game->addCard(0, self::more('Terror'), GameObject::HAND)->id, 0, ["o:{$titan}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::LIBRARY, $this->zone($titan));
        $this->assertCount($library + 1, $game->players[1]->library);

        // Compleated: {B/P} paid with 2 life, two fewer loyalty counters.
        $this->lands(0, 'Swamp', 2);
        $life = $game->players[0]->life;
        $walker = $this->put(0, 'Compleated Lite', GameObject::HAND);
        $game->cast(0, $walker);
        $this->resolve();
        $this->assertSame($life - 2, $game->players[0]->life);
        $this->assertSame(3, $game->objects[$walker]->counter('loyalty'));
    }

    public function testCreatureTapManaEdictsAndArtifactSacrifice(): void
    {
        $game = $this->newGame();
        foreach (['Drum Lite', 'Scene Lite', 'Edict Lite', 'Artifact Sac Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Springleaf Drum: any color, tapping a creature without a mana ability.
        $drum = $this->put(0, 'Drum Lite', GameObject::BATTLEFIELD);
        $this->assertArrayNotHasKey($drum, $game->manaSources(0), 'No creature to tap.');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->assertSame(['W', 'U', 'B', 'R', 'G'], $game->manaSources(0)[$drum]['colors']);
        $game->cast(0, $game->addCard(0, self::card('Lightning Bolt'), GameObject::HAND)->id, 0, ['p:1']);
        $this->assertTrue($game->objects[$drum]->tapped);
        $this->assertTrue($game->objects[$bears]->tapped, 'Tapped for the Drum.');
        $this->resolve();

        // Scene of the Crime: {C} alone, any color with a creature to tap.
        $scene = $this->put(0, 'Scene Lite', GameObject::BATTLEFIELD);
        $this->assertSame(['C'], $game->manaSources(0)[$scene]['colors'], 'Bears is tapped.');
        $game->objects[$bears]->tapped = false;
        $this->assertSame(['W', 'U', 'B', 'R', 'G'], $game->manaSources(0)[$scene]['colors']);

        // Target player sacrifices a creature: their weakest.
        $small = $this->battlefield(1, 'Grizzly Bears');
        $big = $this->battlefield(1, 'Hill Giant');
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Edict Lite', GameObject::HAND), 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($small));
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($big));

        // Sacrifice an artifact to cast it.
        $hand = count($game->players[0]->hand);
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Artifact Sac Lite', GameObject::HAND));
        $this->assertContains($this->zone($drum), [GameObject::GRAVEYARD], 'The Drum is the weakest artifact.');
        $this->resolve();
        $this->assertCount($hand + 2, $game->players[0]->hand);
    }

    public function testImpulseWindowsStirringsAndTeamwork(): void
    {
        $game = $this->newGame();
        foreach (['Haste Magic Lite', 'Relay Lite', 'Stirrings Lite', 'Teamwork Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertSame('impulse', array_column(self::read('Haste Magic Lite')->effects, 'type')[1] ?? null);

        // During your next turn only.
        $bolt = $game->addCard(0, self::card('Lightning Bolt'), GameObject::LIBRARY)->id;
        $this->lands(0, 'Mountain', 4);
        $game->cast(0, $this->put(0, 'Relay Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($bolt));
        $this->assertNotContains(['id' => $bolt, 'how' => 'ix'], $game->plays(0), 'Not this turn.');
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertContains(['id' => $bolt, 'how' => 'ix'], $game->plays(0));

        // Teamwork: tap creatures with total power 3 for both modes.
        $giant = $this->battlefield(1, 'Hill Giant');
        $first = $this->battlefield(0, 'Grizzly Bears');
        $second = $this->battlefield(0, 'Grizzly Bears');
        $teamwork = $this->put(0, 'Teamwork Lite', GameObject::HAND);
        $this->lands(0, 'Swamp', 2);
        $this->assertContains(['id' => $teamwork, 'how' => 'm0+1,entwine'], $game->plays(0));
        $game->cast(0, $teamwork, 0, ["o:{$giant}", "o:{$giant}"], 'm0+1,entwine');
        $this->assertTrue($game->objects[$first]->tapped && $game->objects[$second]->tapped, 'The team is tapped.');
        $this->resolve();
        $this->assertSame(1, $game->toughness($game->objects[$giant]));
        $this->assertTrue($game->hasKeyword($game->objects[$giant], 'deathtouch'));

        // Ancient Stirrings: a colorless card, here a land.
        $this->lands(0, 'Forest', 1);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Stirrings Lite', GameObject::HAND));
        $this->resolve();
        $this->assertTrue($game->choiceAwaiting()['may']);
        $game->take(0, [$game->choiceAwaiting()['eligible'][0]]);
        $this->assertCount($hand + 1, $game->players[0]->hand);
    }

    public function testMultikicker(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Multikick Lite')->unsupported);
        $elf = $this->put(0, 'Multikick Lite', GameObject::HAND);
        $this->lands(0, 'Forest', 6);
        $this->assertContains(['id' => $elf, 'how' => 'kick2'], $game->plays(0));
        $this->assertNotContains(['id' => $elf, 'how' => 'kick3'], $game->plays(0), 'Needs eight mana.');
        $game->cast(0, $elf, 0, [], 'kick2');
        $this->resolve();
        $this->assertSame(2, $game->objects[$elf]->counter('+1/+1'));
    }

    public function testClash(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Clash Burn Lite')->unsupported);
        $this->assertSame([], self::read('Clash Rat Lite')->unsupported);
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Swamp', 2);
        $burn = $this->put(0, 'Clash Burn Lite', GameObject::HAND);
        $game->cast(0, $burn, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(19, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($burn), 'Forest against Forest is no win.');

        $game->addCard(0, self::card('Hill Giant'), GameObject::LIBRARY);
        $rat = $this->put(0, 'Clash Rat Lite', GameObject::HAND);
        $game->cast(0, $rat, 0, []);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->objects[$rat]->counter('+1/+1'));
        $this->assertSame('Hill Giant', $game->objects[end($game->players[0]->library)]->name(), 'The revealed card stays on top.');
    }

    public function testClashWonSpellReturnsToHand(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 1);
        $game->addCard(0, self::card('Hill Giant'), GameObject::LIBRARY);
        $burn = $this->put(0, 'Clash Burn Lite', GameObject::HAND);
        $game->cast(0, $burn, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(19, $this->life(1));
        $this->assertSame(GameObject::HAND, $this->zone($burn));
    }

    public function testClashWonPumpsTheSameCreature(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Clash Pump Lite')->unsupported);
        $this->lands(0, 'Forest', 1);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $game->addCard(0, self::card('Hill Giant'), GameObject::LIBRARY);
        $pump = $this->put(0, 'Clash Pump Lite', GameObject::HAND);
        $game->cast(0, $pump, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(6, $game->power($game->objects[$bears]));
        $this->assertTrue($game->hasKeyword($game->objects[$bears], 'trample'));
    }

    public function testClashWonBurnsTheController(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Lash Lite')->unsupported);
        $this->lands(0, 'Mountain', 2);
        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $game->addCard(0, self::card('Hill Giant'), GameObject::LIBRARY);
        $lash = $this->put(0, 'Lash Lite', GameObject::HAND);
        $game->cast(0, $lash, 0, ["o:{$giant}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($giant));
        $this->assertSame(17, $this->life(1));
    }

    public function testGift(): void
    {
        $game = $this->newGame();
        foreach (['Gift Pump Lite', 'Gift Draw Lite', 'Gift Otter Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Plains', 1);
        $this->lands(0, 'Island', 4);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $pump = $this->put(0, 'Gift Pump Lite', GameObject::HAND);
        $this->assertContains(['id' => $pump, 'how' => 'gift'], $game->plays(0));
        $game->cast(0, $pump, 0, ["o:{$bears}"], 'gift');
        $this->resolve();
        $this->assertSame(4, $game->power($game->objects[$bears]));
        $this->assertTrue($game->hasKeyword($game->objects[$bears], 'indestructible'));
        $this->assertSame(['Food'], array_values(array_map(fn (GameObject $o) => $o->name(), $game->permanents(1))));

        $draw = $this->put(0, 'Gift Draw Lite', GameObject::HAND);
        $hands = [count($game->players[0]->hand), count($game->players[1]->hand)];
        $game->cast(0, $draw, 0, []);
        $this->resolve();
        $this->assertSame(23, $this->life(0), 'No gift: the life too.');
        $this->assertSame($hands, [count($game->players[0]->hand), count($game->players[1]->hand)], 'The spell left, a card came.');

        $otter = $this->put(0, 'Gift Otter Lite', GameObject::HAND);
        $hands = [count($game->players[0]->hand), count($game->players[1]->hand)];
        $game->cast(0, $otter, 0, [], 'gift');
        $this->resolve();
        $this->resolve();
        $this->assertSame($hands, [count($game->players[0]->hand), count($game->players[1]->hand)], 'Its enters ability draws; the Fish is the gift.');
        $fish = array_values(array_filter($game->permanents(1), fn (GameObject $o) => $o->name() === 'Fish Token'));
        $this->assertCount(1, $fish);
        $this->assertTrue($fish[0]->tapped);
    }

    public function testPlayingFromTheTopOfTheLibrary(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Courser Lite')->unsupported);
        $this->assertSame([], self::read('Future Lite')->unsupported);
        $this->put(0, 'Courser Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $this->assertSame([], array_filter($game->plays(0), fn (array $play) => $play['id'] === $bears), 'Only lands from the top.');
        $forest = $game->addCard(0, self::card('Forest'), GameObject::LIBRARY)->id;
        $this->assertContains(['id' => $forest, 'how' => ''], $game->plays(0));
        $game->playLand(0, $forest);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($forest));

        $this->put(0, 'Future Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $this->assertContains(['id' => $bears, 'how' => ''], $game->plays(0));
        $game->cast(0, $bears);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears));
    }

    public function testCipher(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Cipher Lite')->unsupported);
        $this->lands(0, 'Island', 1);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $cipher = $this->put(0, 'Cipher Lite', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $cipher);
        $this->resolve();
        $this->assertSame($hand, count($game->players[0]->hand), 'Cast, then drew.');
        $this->assertSame(GameObject::EXILE, $this->zone($cipher));
        $this->assertSame([$cipher], $game->objects[$bears]->encoded);

        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$bears]);
        $hand = count($game->players[0]->hand);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(18, $this->life(1));
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'The copy draws a card.');
        $this->assertSame(GameObject::EXILE, $this->zone($cipher), 'The card stays encoded.');
    }

    public function testCollectedCompanyDigs(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Company Lite')->unsupported);
        $this->assertSame([], self::read('Battlefront Lite')->unsupported);
        $this->lands(0, 'Forest', 4);
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::LIBRARY)->id;
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY)->id;
        $company = $this->put(0, 'Company Lite', GameObject::HAND);
        $game->cast(0, $company);
        $this->resolve();
        $this->assertSame('look', $game->decision(0));
        $this->assertContains($bears, $game->choiceAwaiting()['eligible']);
        $this->assertNotContains($giant, $game->choiceAwaiting()['eligible'], 'Mana value 4.');
        $game->take(0, [$bears]);
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears));
        $this->assertSame(GameObject::LIBRARY, $this->zone($giant));
    }

    public function testDiscardACardAsACost(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Rummager Lite')->unsupported);
        $this->lands(0, 'Mountain', 1);
        $rummager = $this->put(0, 'Rummager Lite', GameObject::BATTLEFIELD);
        $game->addCard(0, self::card('Hill Giant'), GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $graveyard = count($game->players[0]->graveyard);
        $game->activate(0, $rummager, 0);
        $this->resolve();
        $this->assertSame($hand, count($game->players[0]->hand));
        $this->assertSame($graveyard + 1, count($game->players[0]->graveyard));
    }

    public function testNamingACardToExile(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Obliteration Lite')->unsupported);
        $this->lands(0, 'Swamp', 3);
        $kept = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $gone = [];
        foreach ([GameObject::GRAVEYARD, GameObject::HAND, GameObject::LIBRARY] as $zone) {
            $gone[] = $game->addCard(1, self::card('Grizzly Bears'), $zone)->id;
        }
        $game->addCard(1, self::card('Hill Giant'), GameObject::GRAVEYARD);
        $game->cast(0, $this->put(0, 'Obliteration Lite', GameObject::HAND), 0, ['p:1']);
        $this->resolve();
        foreach ($gone as $id) {
            $this->assertSame(GameObject::EXILE, $this->zone($id));
        }
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($kept), 'Only its graveyard, hand and library.');
    }

    public function testTemporaryReanimation(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Goryo Lite')->unsupported);
        $this->lands(0, 'Swamp', 2);
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        $legend = $game->addCard(0, ['uuid' => 'legend', 'name' => 'Legend Lite', 'type' => 'Legendary Creature — Spirit', 'manaCost' => '{5}{W}', 'power' => '6', 'toughness' => '6', 'text' => ''], GameObject::GRAVEYARD)->id;
        $this->assertSame(["o:{$legend}"], $game->targetOptions(0, 'legendary_creature_card_yours'));
        $game->cast(0, $this->put(0, 'Goryo Lite', GameObject::HAND), 0, ["o:{$legend}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($legend));
        $this->assertTrue($game->hasKeyword($game->objects[$legend], 'haste'));
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame(GameObject::EXILE, $this->zone($legend));
    }

    public function testTribalLordsAndCastTriggers(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Elf Lord Lite')->unsupported);
        $lord = $this->put(0, 'Elf Lord Lite', GameObject::BATTLEFIELD);
        $elf = $game->addCard(0, ['uuid' => 'elf', 'name' => 'Elf Lite', 'type' => 'Creature — Elf', 'manaCost' => '{G}', 'power' => '1', 'toughness' => '1', 'text' => ''], GameObject::BATTLEFIELD)->id;
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::HAND)->id;
        $this->assertSame(2, $game->power($game->objects[$elf]));
        $this->assertSame(2, $game->power($game->objects[$lord]), 'Other Elves only.');
        $this->lands(0, 'Forest', 2);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $bears);
        $this->resolve();
        $this->resolve();
        $this->assertSame(2, $game->power($game->objects[$bears]), 'Not an Elf.');
        $this->assertSame($hand, count($game->players[0]->hand), 'Cast a creature, drew a card.');
    }

    public function testDrawTriggers(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Bookworm Lite')->unsupported);
        $worm = $this->put(0, 'Bookworm Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::Draw, 3);
        $this->resolve();
        $this->assertSame(1, $game->objects[$worm]->counter('+1/+1'));
    }

    public function testProtectionFromArtifactsBolsterAndControllerLosesLife(): void
    {
        $game = $this->newGame();
        foreach (['Pro Artifacts Lite', 'Bolster Lite', 'Hideous Lite', 'Countersquall Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $elf = $this->put(0, 'Pro Artifacts Lite', GameObject::BATTLEFIELD);
        $frogmite = $this->put(1, 'Frogmite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;

        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Bolster Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(2, $game->objects[$elf]->counter('+1/+1'), 'Bolster: the creature with the least toughness.');
        $this->assertSame(0, $game->objects[$giant]->counter('+1/+1'));

        $this->lands(0, 'Swamp', 3);
        $life = $game->players[1]->life;
        $game->cast(0, $this->put(0, 'Hideous Lite', GameObject::HAND), 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$bears]->zone);
        $this->assertSame($life - 2, $game->players[1]->life, 'Its controller loses 2 life.');

        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Plains', 2);
        $this->lands(0, 'Island', 1);
        $this->lands(0, 'Swamp', 1);
        $game->cast(1, $this->put(1, 'Bolster Lite', GameObject::HAND));
        $spell = end($game->stack)['id'];
        $game->pass(1);
        $game->cast(0, $this->put(0, 'Countersquall Lite', GameObject::HAND), 0, ["s:{$spell}"]);
        $life = $game->players[1]->life;
        $this->resolve();
        $this->assertSame([], $game->stack, 'Countered.');
        $this->assertSame($life - 2, $game->players[1]->life, "The countered spell's controller loses 2 life.");

        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$elf]);
        $this->assertFalse($game->canBlock($game->objects[$frogmite], $game->objects[$elf]), 'Protection from artifacts: an artifact creature cannot block it.');
        $other = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->assertTrue($game->canBlock($game->objects[$other], $game->objects[$elf]));
    }

    public function testGraveyardDiscountAndExileDyingCreatures(): void
    {
        $game = $this->newGame();
        foreach (['Grave Discount Lite', 'Exiler Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Hideous Lite', GameObject::GRAVEYARD);
        $this->put(0, 'Countersquall Lite', GameObject::GRAVEYARD);
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        $this->lands(0, 'Island', 3);
        $drake = $this->put(0, 'Grave Discount Lite', GameObject::HAND);
        $game->cast(0, $drake);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$drake]->zone, 'Two instants in the graveyard: {4}{U} costs {2}{U}.');

        $this->put(0, 'Exiler Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->put(0, 'Hideous Lite', GameObject::HAND), 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $game->objects[$bears]->zone, "An opponent's creature is exiled instead.");
    }

    public function testConvergeStunAndUnpreventableDamage(): void
    {
        $game = $this->newGame();
        foreach (['Converge Lite', 'Stun Sage Lite', 'Skullcrack Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Forest', 1);
        $converge = $this->put(0, 'Converge Lite', GameObject::HAND);
        $game->cast(0, $converge);
        $this->resolve();
        $this->assertSame(2, $game->objects[$converge]->counter('+1/+1'), 'Converge: red and green were spent.');

        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Island', 3);
        $game->cast(0, $this->put(0, 'Stun Sage Lite', GameObject::HAND));
        $this->resolve();
        if ($game->triggerAwaitingTargets() !== null) {
            $game->chooseTriggerTargets(0, ["o:{$bears}"]);
        }
        $this->resolve();
        $this->assertTrue($game->objects[$bears]->tapped);
        $this->assertSame(1, $game->objects[$bears]->counter('stun'));

        $game->objects[$bears]->counters['shield'] = 1;
        $this->lands(0, 'Mountain', 2);
        $game->cast(0, $this->put(0, 'Skullcrack Lite', GameObject::HAND), 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$bears]->zone, "Damage can't be prevented: the shield counter doesn't stop it.");
    }

    public function testReplicate(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Replicate Lite')->unsupported);
        $spell = $this->put(0, 'Replicate Lite', GameObject::HAND);
        $this->lands(0, 'Mountain', 3);
        $this->assertContains(['id' => $spell, 'how' => 'kick2'], $game->plays(0));
        $life = $this->life(1);
        $game->cast(0, $spell, 0, ['p:1'], 'kick2');
        $this->assertCount(3, $game->stack, 'The spell and two copies.');
        while ($game->stack !== []) {
            $this->resolve();
        }
        $this->assertSame($life - 3, $this->life(1));
    }

    public function testTheRingTemptsYou(): void
    {
        $game = $this->newGame();
        foreach (['Ring Lite', 'Ring Orc Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Swamp', 2);
        $orc = $this->put(0, 'Ring Orc Lite', GameObject::HAND);
        $game->cast(0, $orc);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->players[0]->ring);
        $this->assertSame(1, $game->ringLevel($game->objects[$orc]), 'The only creature is the Ring-bearer.');
        foreach ([1, 2, 3] as $time) {
            $this->lands(0, 'Swamp', 1);
            $game->cast(0, $this->put(0, 'Ring Lite', GameObject::HAND));
            $this->resolve();
        }
        $this->assertSame(4, $game->ringLevel($game->objects[$orc]));

        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, 3);
        $hand = count($game->players[0]->hand);
        $game->declareAttackers(0, [$orc]);
        $this->assertFalse($game->canBlock($game->objects[$giant], $game->objects[$orc]), 'Greater power cannot block the Ring-bearer.');
        $this->assertTrue($game->canBlock($game->objects[$bears], $game->objects[$orc]));
        $this->resolve();
        $this->assertSame('discard', $game->decision(0), 'Draw a card, then discard a card.');
        $game->discard(0, [end($game->players[0]->hand)]);
        $this->assertSame($hand, count($game->players[0]->hand));
        $life = $this->life(1);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->resolve();
        $this->assertSame($life - 2 - 3, $this->life(1), 'Combat damage, then each opponent loses 3 life.');

        $wall = $game->addCard(1, ['uuid' => 'wall', 'name' => 'Wall Lite', 'type' => 'Creature — Wall', 'manaCost' => '{1}', 'power' => '0', 'toughness' => '4', 'text' => ''], GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, 5);
        $game->declareAttackers(0, [$orc]);
        $this->resolve();
        $game->discard(0, [end($game->players[0]->hand)]);
        $this->passUntil(Step::DeclareBlockers, 5);
        $game->declareBlockers(1, [$wall => $orc]);
        $this->passUntil(Step::PostcombatMain, 5);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$wall]->zone, 'The Ring: a creature that blocked the Ring-bearer is sacrificed at end of combat.');
    }

    public function testRaidDyingCountersFlierKillAndUntapAll(): void
    {
        $game = $this->newGame();
        foreach (['Raid Lite', 'Pupil Lite', 'Wings Lite', 'Rally Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Mountain', 2);
        $first = $this->put(0, 'Raid Lite', GameObject::HAND);
        $game->cast(0, $first);
        $this->resolve();
        $this->assertSame(0, $game->objects[$first]->counter('+1/+1'), 'No attack yet.');

        $pupil = $this->put(0, 'Pupil Lite', GameObject::BATTLEFIELD);
        $game->objects[$pupil]->addCounters('+1/+1', 1);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$pupil]);
        $this->passUntil(Step::PostcombatMain, 3);
        $second = $this->put(0, 'Raid Lite', GameObject::HAND);
        $this->lands(0, 'Mountain', 2);
        $game->cast(0, $second);
        $this->resolve();
        $this->assertSame(1, $game->objects[$second]->counter('+1/+1'), 'Raid: attacked this turn.');

        $this->assertTrue($game->objects[$first]->tapped === false);
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Rally Lite', GameObject::HAND));
        $this->resolve();
        $this->assertFalse($game->objects[$pupil]->tapped, 'Untap all creatures you control.');

        $this->lands(0, 'Swamp', 3);
        $had = $game->objects[$pupil]->counter('+1/+1');
        $game->cast(0, $this->put(0, 'Hideous Lite', GameObject::HAND), 0, ["o:{$pupil}"]);
        $this->resolve();
        if ($game->triggerAwaitingTargets() !== null) {
            $game->chooseTriggerTargets(0, ["o:{$first}"]);
        }
        $this->resolve();
        $this->assertGreaterThan(0, $had);
        $this->assertSame($had, $game->objects[$first]->counter('+1/+1'), 'Its counters moved to another creature.');

        $bird = $game->addCard(1, ['uuid' => 'bird', 'name' => 'Bird Lite', 'type' => 'Creature — Bird', 'manaCost' => '{U}', 'power' => '1', 'toughness' => '1', 'text' => 'Flying'], GameObject::BATTLEFIELD)->id;
        $this->assertFalse($game->isLegalTarget('artifact_enchantment_flier', "o:{$first}", 0), 'Not a flier.');
        $this->lands(0, 'Forest', 2);
        $game->cast(0, $this->put(0, 'Wings Lite', GameObject::HAND), 0, ["o:{$bird}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$bird]->zone);
    }

    public function testCombatDamageToACreatureFreezesIt(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Frostwalk Lite')->unsupported);
        $frost = $this->put(0, 'Frostwalk Lite', GameObject::BATTLEFIELD);
        $wall = $game->addCard(1, ['uuid' => 'wall', 'name' => 'Wall Lite', 'type' => 'Creature — Wall', 'manaCost' => '{1}', 'power' => '0', 'toughness' => '4', 'text' => ''], GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$frost]);
        $this->passUntil(Step::DeclareBlockers, 3);
        $game->declareBlockers(1, [$wall => $frost]);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->assertTrue($game->objects[$wall]->tapped);
        $this->assertTrue($game->objects[$wall]->frozen);
        $this->passUntil(Step::PrecombatMain, 4);
        $this->assertTrue($game->objects[$wall]->tapped, "It doesn't untap during its controller's next untap step.");
    }

    public function testIncrement(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Increment Lite')->unsupported);
        $student = $this->put(0, 'Increment Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 3);
        $game->cast(0, $game->addCard(0, self::card('Grizzly Bears'), GameObject::HAND)->id);
        $this->assertSame(1, $game->objects[$student]->counter('+1/+1'), 'Two mana is more than its power of 1.');
        $this->resolve();
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Rally Lite', GameObject::HAND));
        $this->assertSame(1, $game->objects[$student]->counter('+1/+1'), 'Not more than 2/4.');
    }

    public function testFiendHunterAndBloodTokens(): void
    {
        $game = $this->newGame();
        foreach (['Fiend Hunter Lite', 'Blood Guest Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Plains', 3);
        $hunter = $this->put(0, 'Fiend Hunter Lite', GameObject::HAND);
        $game->cast(0, $hunter);
        $this->resolve();
        $this->assertNull($game->triggerAwaitingTargets(), 'Another target creature: the bears are the only choice, not the hunter itself.');
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $game->objects[$bears]->zone);
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->put(0, 'Hideous Lite', GameObject::HAND), 0, ["o:{$hunter}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$bears]->zone, 'Back when the hunter leaves.');

        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->put(0, 'Blood Guest Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $blood = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Blood'));
        $this->assertCount(1, $blood);
        $this->assertSame([], $blood[0]->definition()->unsupported);
    }

    public function testPreparedCreatures(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Prepared Angel Lite // Seed Lite')->unsupported);
        $unprepared = self::read('Prepared Angel Lite // Seed Lite');
        $this->assertSame(['CARDNAME enters prepared.'], (new CardDefinition(array_diff_key(['uuid' => 'x', 'name' => 'Old Import'] + self::MORE['Prepared Angel Lite // Seed Lite'], ['prepare' => true])))->unsupported, 'Without its spell, the line stays unread.');
        $this->lands(0, 'Plains', 5);
        $angel = $this->put(0, 'Prepared Angel Lite // Seed Lite', GameObject::HAND);
        $game->cast(0, $angel);
        $this->resolve();
        $this->assertTrue($game->objects[$angel]->prepared);
        $index = count($unprepared->activated) - 1;
        $life = $this->life(0);
        $game->activate(0, $angel, $index, ["o:{$angel}"]);
        $this->assertFalse($game->objects[$angel]->prepared, 'Casting the copy unprepares it.');
        $this->resolve();
        $this->assertSame(1, $game->objects[$angel]->counter('+1/+1'));
        $this->assertSame($life + 1, $this->life(0));
        $this->expectException(GameException::class);
        $game->activate(0, $angel, $index, ["o:{$angel}"]);
    }

    public function testBecomingPreparedLater(): void
    {
        $game = $this->newGame();
        foreach (['Upkeep Prep Lite // Mend Lite', 'Mourner Lite // Mourn Lite', 'Leech Prep Lite // Sip Lite', 'Third Spell Lite // Third Lite', 'Silver Prep Lite // Silver Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertSame(['CARDNAME becomes prepared.'], (new CardDefinition(array_diff_key(['uuid' => 'x', 'name' => 'Old Import'] + self::MORE['Upkeep Prep Lite // Mend Lite'], ['prepare' => true])))->unsupported, 'Without its spell, the line stays unread.');
        $upkeep = $this->put(0, 'Upkeep Prep Lite // Mend Lite', GameObject::BATTLEFIELD);
        $mourner = $this->put(0, 'Mourner Lite // Mourn Lite', GameObject::BATTLEFIELD);
        $leech = $this->put(0, 'Leech Prep Lite // Sip Lite', GameObject::BATTLEFIELD);
        $third = $this->put(0, 'Third Spell Lite // Third Lite', GameObject::BATTLEFIELD);
        $silver = $this->put(0, 'Silver Prep Lite // Silver Lite', GameObject::BATTLEFIELD);
        $this->assertFalse($game->objects[$upkeep]->prepared, 'Only "enters prepared" cards enter prepared.');

        // One creature died: not enough for the end step.
        [$game->died, $game->diedTurn] = [1, $game->turn];
        $this->passUntil(Step::End, 1);
        $this->assertSame([], $game->stack);
        $this->assertFalse($game->objects[$mourner]->prepared);

        $this->passUntil(Step::Upkeep, 3);
        $this->resolve();
        $this->assertTrue($game->objects[$upkeep]->prepared);

        // The first life gained this turn prepares the leech; it isn't 2 life yet.
        $this->passUntil(Step::PrecombatMain, 3);
        $this->lands(0, 'Plains', 6);
        $this->lands(0, 'Mountain', 1);
        $game->activate(0, $upkeep, count(self::read('Upkeep Prep Lite // Mend Lite')->activated) - 1);
        $this->assertFalse($game->objects[$upkeep]->prepared);
        $this->resolve();
        $this->resolve();
        $this->assertTrue($game->objects[$leech]->prepared);
        $this->assertSame(1, $game->players[0]->gained);

        // Copying the prepare spell isn't casting it: the third spell cast is the third Double White.
        for ($i = 1; $i <= 3; $i++) {
            $game->cast(0, $this->put(0, 'Double White Lite', GameObject::HAND));
            if ($i < 3) {
                $this->resolve();
                $this->assertFalse($game->objects[$third]->prepared);
            }
        }
        $this->resolve();
        $this->assertTrue($game->objects[$third]->prepared);
        $this->resolve();
        $this->assertSame(7, $game->players[0]->gained);

        // Gained 7 life this turn: the second main phase prepares it.
        $this->passUntil(Step::PostcombatMain, 3);
        $this->resolve();
        $this->assertTrue($game->objects[$silver]->prepared);

        // Two creatures die: the end step prepares the mourner.
        $game->objects[$leech]->damage = 5;
        $game->objects[$third]->damage = 5;
        $this->passUntil(Step::End, 3);
        $this->resolve();
        $this->assertSame(2, $game->died);
        $this->assertTrue($game->objects[$mourner]->prepared);
    }

    public function testManaOfAnyColorFilters(): void
    {
        $game = $this->newGame();
        foreach (['Refractor Lite', 'Mascot Lite', 'Vivid Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Once each turn, without tapping, even summoning sick: {1} and three Plains pay for Hill Giant's {3}{R}.
        $mascot = $this->put(0, 'Mascot Lite', GameObject::BATTLEFIELD);
        $game->objects[$mascot]->sick = true;
        $this->lands(0, 'Plains', 4);
        $game->cast(0, $game->addCard(0, self::card('Hill Giant'), GameObject::HAND)->id);
        $this->assertFalse($game->objects[$mascot]->tapped);
        $this->resolve();
        $this->lands(0, 'Plains', 4);
        try {
            $game->cast(0, $game->addCard(0, self::card('Hill Giant'), GameObject::HAND)->id);
            $this->fail('The filter was already used this turn.');
        } catch (GameException) {
        }

        // {2} for one mana of any color.
        $game = $this->newGame();
        $this->put(0, 'Refractor Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 4);
        try {
            $game->cast(0, $game->addCard(0, self::card('Hill Giant'), GameObject::HAND)->id);
            $this->fail('Four Plains and {2} for {R} are one short.');
        } catch (GameException) {
        }
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $game->addCard(0, self::card('Hill Giant'), GameObject::HAND)->id);

        // A Vivid land makes {R} for free, and any other color for a charge counter.
        $game = $this->newGame();
        $vivid = $this->put(0, 'Vivid Lite', GameObject::BATTLEFIELD);
        $this->assertSame(2, $game->objects[$vivid]->counter('charge'));
        $game->objects[$vivid]->tapped = false;
        $this->lands(0, 'Plains', 3);
        $game->cast(0, $game->addCard(0, self::card('Hill Giant'), GameObject::HAND)->id);
        $this->assertSame(2, $game->objects[$vivid]->counter('charge'));
        $this->resolve();
        for ($left = 1; $left >= 0; $left--) {
            $game->objects[$vivid]->tapped = false;
            $this->lands(0, 'Plains', 1);
            $game->cast(0, $game->addCard(0, self::card('Grizzly Bears'), GameObject::HAND)->id);
            $this->assertSame($left, $game->objects[$vivid]->counter('charge'));
            $this->resolve();
        }
        $game->objects[$vivid]->tapped = false;
        $this->lands(0, 'Plains', 1);
        $this->expectException(GameException::class);
        $game->cast(0, $game->addCard(0, self::card('Grizzly Bears'), GameObject::HAND)->id);
    }

    public function testSplitCardsAndFuse(): void
    {
        $game = $this->newGame();
        $fuse = self::read('Bolt Lite // Heal Lite');
        $this->assertSame([], $fuse->unsupported);
        $this->assertSame([], self::read('Fire Lite // Ice Lite')->unsupported);
        $this->assertSame(2, $fuse->cost->manaValue(), 'Both halves count outside the stack.');
        $this->assertSame(['{R}', '{W}', '{R}{W}'], [Game::castCost($fuse, 'm0'), Game::castCost($fuse, 'm1'), Game::castCost($fuse, 'm0+1')]);
        $this->assertSame('draw', self::read('Fire Lite // Ice Lite')->spellEffects([1], false)[0]['type'], 'The right half is the second mode.');

        // Fused from hand: both halves.
        $this->lands(0, 'Mountain', 1);
        $this->lands(0, 'Plains', 1);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->cast(0, $this->put(0, 'Bolt Lite // Heal Lite', GameObject::HAND), 0, ['p:1'], 'm0+1');
        $this->resolve();
        $this->assertSame([$mine + 3, $theirs - 2], [$this->life(0), $this->life(1)]);

        // One half alone.
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $this->put(0, 'Bolt Lite // Heal Lite', GameObject::HAND), 0, [], 'm1');
        $this->resolve();
        $this->assertSame($mine + 6, $this->life(0));

        // Without fuse, only one half.
        $this->lands(0, 'Island', 4);
        $this->expectException(GameException::class);
        $game->cast(0, $this->put(0, 'Fire Lite // Ice Lite', GameObject::HAND), 0, ['p:1'], 'm0+1');
    }

    public function testHeroicTeamUntapAndTargetingTax(): void
    {
        $game = $this->newGame();
        foreach (['Heroic Lite', 'Tenacity Lite', 'Taxed Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Heroic: a spell that targets it.
        $heroic = $this->put(0, 'Heroic Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $game->cast(0, $game->addCard(0, self::card('Giant Growth'), GameObject::HAND)->id, 0, ["o:{$heroic}"]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$heroic]->counter('+1/+1'));
        $this->resolve();
        $this->assertSame(5, $game->power($game->objects[$heroic]));

        // Untap those creatures, after a team pump.
        $game->objects[$heroic]->tapped = true;
        $this->lands(0, 'Plains', 4);
        $game->cast(0, $this->put(0, 'Tenacity Lite', GameObject::HAND));
        $this->resolve();
        $this->assertFalse($game->objects[$heroic]->tapped);

        // The opponent's spells that target it cost {2} more; not its controller's.
        $taxed = $this->put(0, 'Taxed Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $game->cast(0, $game->addCard(0, self::card('Giant Growth'), GameObject::HAND)->id, 0, ["o:{$taxed}"]);
        $this->resolve();
        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Forest', 2);
        $growth = $game->addCard(1, self::card('Giant Growth'), GameObject::HAND)->id;
        try {
            $game->cast(1, $growth, 0, ["o:{$taxed}"]);
            $this->fail('{G} and {2} more is three mana.');
        } catch (GameException) {
        }
        $this->lands(1, 'Forest', 1);
        $game->cast(1, $growth, 0, ["o:{$taxed}"]);
        $this->assertSame(GameObject::STACK, $game->objects[$growth]->zone);
    }

    public function testEscalate(): void
    {
        $game = $this->newGame();
        $escalate = self::read('Escalate Lite');
        $this->assertSame([], $escalate->unsupported);
        $this->assertSame(['{R}', '{R}{1}'], [Game::castCost($escalate, 'm0'), Game::castCost($escalate, 'm0+1')]);
        $this->lands(0, 'Mountain', 2);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->cast(0, $this->put(0, 'Escalate Lite', GameObject::HAND), 0, ['p:1'], 'm0+1');
        $this->resolve();
        $this->assertSame([$mine + 2, $theirs - 2], [$this->life(0), $this->life(1)]);
    }

    public function testHarmonize(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Harmonize Lite')->unsupported);
        $card = $this->put(0, 'Harmonize Lite', GameObject::GRAVEYARD);
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Forest', 3);
        $this->assertContains(['id' => $card, 'how' => 'hz'], $game->plays(0), "Hill Giant's 3 power takes {3} off {5}{G}.");
        $hand = count($game->players[0]->hand);
        $game->cast(0, $card, 0, [], 'hz');
        $this->assertTrue($game->objects[$giant]->tapped);
        $this->resolve();
        $this->assertSame($hand + 2, count($game->players[0]->hand));
        $this->assertSame(GameObject::EXILE, $game->objects[$card]->zone);
    }

    public function testSpliceOntoArcane(): void
    {
        $game = $this->newGame();
        foreach (['Glacial Ray Lite', 'Evermind Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $arcane = $this->put(0, 'Arcane Lite', GameObject::HAND);
        $ray = $this->put(0, 'Glacial Ray Lite', GameObject::HAND);
        $evermind = $this->put(0, 'Evermind Lite', GameObject::HAND);
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Island', 3);
        $plays = $game->plays(0);
        $this->assertContains(['id' => $arcane, 'how' => "splice{$ray}"], $plays);
        $this->assertContains(['id' => $arcane, 'how' => "splice{$ray}+{$evermind}"], $plays);
        $this->assertNotContains(['id' => $ray, 'how' => "splice{$ray}"], $plays, "A card isn't spliced onto itself.");
        $this->assertSame(['any'], Game::castTargetKinds($game->objects[$arcane]->printed(), "splice{$ray}", $game));

        // Arcane Lite with Glacial Ray Lite and Evermind Lite spliced on: {U} + {1}{R} + {1}{U}.
        $hand = count($game->players[0]->hand);
        $life = $game->players[1]->life;
        $game->cast(0, $arcane, 0, ['p:1'], "splice{$ray}+{$evermind}");
        $this->assertSame([GameObject::HAND, GameObject::HAND], [$game->objects[$ray]->zone, $game->objects[$evermind]->zone], 'Spliced cards stay in hand.');
        $this->resolve();
        $this->assertSame($life - 2, $game->players[1]->life);
        $this->assertSame($hand - 1 + 2, count($game->players[0]->hand), 'Arcane Lite and Evermind Lite each draw a card.');

        // Not onto a spell that isn't Arcane.
        $this->lands(0, 'Mountain', 4);
        $bolt = $this->put(0, 'Lava Coil', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $bolt, "splice{$ray}"));
    }

    public function testDamageToTheCreatureItBlocksOrIsBlockedBy(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Ornery Goblin Lite')->unsupported);
        $goblin = $this->put(0, 'Ornery Goblin Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$goblin]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$bears => $goblin]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$bears]->damage, 'The blocker is dealt 1 damage.');
        $this->assertSame(0, $game->objects[$giant]->damage);
    }

    public function testThreeManaOfAnyOneColor(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Gilded Lotus Lite')->unsupported);
        $lotus = $this->put(0, 'Gilded Lotus Lite', GameObject::BATTLEFIELD);
        $sources = $game->manaSources(0);
        $pay = fn (string $cost) => ManaPayer::plan(ManaCost::parse($cost)->payments(0)[0], new ManaPool(), $sources);
        $this->assertNotNull($pay('{2}{U}'));
        $this->assertSame(['U' => 2], $pay('{U}')['float'], 'The rest floats in the same color.');
        $this->assertNull($pay('{W}{U}'), 'All three are one color.');

        // With a Mountain: {R} from it, {U}{U} from the Lotus.
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $game->addCard(0, self::card('Hill Giant'), GameObject::HAND)->id);
        $this->assertTrue($game->objects[$lotus]->tapped);
    }

    public function testLifeGainReplacements(): void
    {
        $game = $this->newGame();
        foreach (['Vitality Lite', 'Lynx Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Vitality Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Vitality Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 4);
        $life = $game->players[0]->life;
        $game->cast(0, $this->put(0, 'Double White Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame($life + 4, $game->players[0]->life, '2 life plus 1, twice.');

        // Players can't gain life, the Lynx's controller's opponents included.
        $this->put(1, 'Lynx Lite', GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Double White Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame($life + 4, $game->players[0]->life);
    }

    public function testReanimateFromAnyGraveyardAndByManaValue(): void
    {
        $game = $this->newGame();
        foreach (['Reanimate Lite', 'Witness Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $theirs = $game->addCard(1, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::GRAVEYARD)->id;
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Swamp', 1);
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Reanimate Lite', GameObject::HAND), 0, ["o:{$theirs}"]);
        $this->resolve();
        $this->assertSame([GameObject::BATTLEFIELD, 0], [$game->objects[$theirs]->zone, $game->objects[$theirs]->controller]);

        $witness = $this->put(0, 'Witness Lite', GameObject::HAND);
        $this->assertSame(["o:{$bears}"], $game->targetOptions(0, 'creature_card_yours_mv_le_2'), "Hill Giant's mana value is 4.");
        $game->cast(0, $witness, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame([GameObject::BATTLEFIELD, GameObject::GRAVEYARD], [$game->objects[$bears]->zone, $game->objects[$giant]->zone]);
    }

    public function testColorLordsAndAttackingAnthems(): void
    {
        $game = $this->newGame();
        foreach (['Liege Lite', 'Oriflamme Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $liege = $this->put(0, 'Liege Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Oriflamme Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $knight = $game->addCard(0, self::card('White Knight'), GameObject::BATTLEFIELD)->id;
        $this->assertSame([3, 4, 3, 2], [$game->power($game->objects[$bears]), $game->power($game->objects[$giant]), $game->power($game->objects[$liege]), $game->power($game->objects[$knight])], 'Green and red get +1/+1; not the Liege itself.');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$bears]);
        $this->assertSame([4, 4], [$game->power($game->objects[$bears]), $game->power($game->objects[$giant])], 'Attacking creatures get +1/+0.');
    }

    public function testLandAuraManaAndTargetPlayerGainsLife(): void
    {
        $game = $this->newGame();
        foreach (['Wild Growth Lite', 'Command Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Plains', 1);
        $plains = array_values(array_filter($game->battlefield, fn (int $id) => $game->objects[$id]->name() === 'Plains'))[0];
        $growth = $this->put(0, 'Wild Growth Lite', GameObject::BATTLEFIELD);
        $game->objects[$growth]->attachedTo = $plains;
        $this->assertSame(2, $game->manaSources(0)[$plains]['count']);

        // {1}{W} from one enchanted Plains: target player gains 4 life.
        $life = $game->players[1]->life;
        $game->cast(0, $this->put(0, 'Command Lite', GameObject::HAND), 0, ['p:1'], 'm0');
        $this->assertTrue($game->objects[$plains]->tapped);
        $this->resolve();
        $this->assertSame($life + 4, $game->players[1]->life);
    }

    public function testMetalcraftAndThresholdPumps(): void
    {
        $game = $this->newGame();
        foreach (['Metalcraft Lite', 'Threshold Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $metal = $this->put(0, 'Metalcraft Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->put(0, 'Prismatic Lens', GameObject::BATTLEFIELD);
        $this->put(0, 'Prismatic Lens', GameObject::BATTLEFIELD);
        $this->assertSame(1, $game->power($game->objects[$metal]));
        $this->put(0, 'Prismatic Lens', GameObject::BATTLEFIELD);
        $this->assertSame([3, 3, 2], [$game->power($game->objects[$metal]), $game->toughness($game->objects[$metal]), $game->power($game->objects[$bears])], 'Only itself, with three artifacts.');

        $threshold = $this->put(0, 'Threshold Lite', GameObject::BATTLEFIELD);
        for ($i = 0; $i < 7; $i++) {
            $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        }
        $this->assertSame(3, $game->power($game->objects[$threshold]));
        $this->assertTrue($game->hasKeyword($game->objects[$threshold], 'flying'));
    }

    public function testSacrificeUnlessAColorWasSpent(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Hussar Lite')->unsupported);
        $this->lands(0, 'Island', 3);
        $first = $this->put(0, 'Hussar Lite', GameObject::HAND);
        $game->cast(0, $first);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$first]->zone, 'No {W} was spent.');

        $game = $this->newGame();
        $this->lands(0, 'Island', 2);
        $this->lands(0, 'Plains', 1);
        $second = $this->put(0, 'Hussar Lite', GameObject::HAND);
        $game->cast(0, $second);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$second]->zone);
        $this->assertSame($game->objects[$second]->spent, GameObject::fromArray($game->objects[$second]->toArray())->spent);
    }

    public function testExileTargetCardFromAGraveyard(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Beckon Lite')->unsupported);
        $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $this->put(0, 'Beckon Lite', GameObject::HAND), 0, ["o:{$theirs}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $game->objects[$theirs]->zone);
    }

    public function testMiracle(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Wrath Lite')->unsupported);
        $this->lands(0, 'Mountain', 1);
        $this->passUntil(Step::Upkeep, 3);
        $wrath = $this->put(0, 'Wrath Lite', GameObject::LIBRARY);
        $this->passUntil(Step::Draw, 3);
        $this->assertSame([GameObject::HAND, ['id' => $wrath, 'how' => 'miracle']], [$game->objects[$wrath]->zone, array_values(array_filter($game->plays(0), fn (array $play) => $play['id'] === $wrath))[0] ?? null]);
        $life = $game->players[1]->life;
        $game->cast(0, $wrath, 0, ['p:1'], 'miracle');
        $this->resolve();
        $this->assertSame($life - 5, $game->players[1]->life);

        // Not the first card drawn, or not right after drawing it: no miracle.
        $later = $this->put(0, 'Wrath Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $later, 'miracle'));
    }

    public function testZendikon(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Zendikon Lite')->unsupported);
        $this->lands(0, 'Plains', 4);
        $plains = array_values(array_filter($game->battlefield, fn (int $id) => $game->objects[$id]->name() === 'Plains'))[0];
        $zendikon = $this->put(0, 'Zendikon Lite', GameObject::HAND);
        $game->cast(0, $zendikon, 0, ["o:{$plains}"]);
        $this->resolve();
        $land = $game->objects[$plains];
        $this->assertTrue($game->isCreature($land));
        $this->assertSame([2, 2, true], [$game->power($land), $game->toughness($land), $game->hasKeyword($land, 'first strike')]);

        // It dies: the land returns to its owner's hand, and the Zendikon goes to the graveyard.
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Murder Lite', GameObject::HAND), 0, ["o:{$plains}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame([GameObject::HAND, GameObject::GRAVEYARD], [$game->objects[$plains]->zone, $game->objects[$zendikon]->zone]);
    }

    public function testRevealADragonCard(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Roar Lite')->unsupported);
        $this->lands(0, 'Mountain', 4);
        $life = $game->players[1]->life;
        $game->cast(0, $this->put(0, 'Roar Lite', GameObject::HAND), 0, ['o:'.$game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id]);
        $this->resolve();
        $this->assertSame($life, $game->players[1]->life, 'No Dragon to reveal.');

        $this->put(0, 'Whelp Lite', GameObject::HAND);
        $game->cast(0, $this->put(0, 'Roar Lite', GameObject::HAND), 0, ['o:'.$game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id]);
        $this->resolve();
        $this->assertSame($life - 3, $game->players[1]->life);
    }

    public function testMayPayWhenAttacking(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Champion Lite')->unsupported);
        $champion = $this->put(0, 'Champion Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$champion]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$champion]->counter('+1/+1'));

        // Without the mana, nothing happens.
        $this->passUntil(Step::DeclareAttackers, 5);
        foreach ($game->permanents(0) as $permanent) {
            $permanent->tapped = $permanent->definition()->isLand();
        }
        $game->declareAttackers(0, [$champion]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$champion]->counter('+1/+1'));
    }

    public function testBasePowerAndToughness(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Zero Lite')->unsupported);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $bears->addCounters('+1/+1', 1);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Zero Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame([1, 2], [$game->power($bears), $game->toughness($bears)], 'Counters still apply on top of the new base.');

        $this->passUntil(Step::Upkeep, $game->turn + 1);
        $this->assertSame([3, 3], [$game->power($bears), $game->toughness($bears)]);
    }

    public function testSneak(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Sneak Lite')->unsupported);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $sneak = $this->put(0, 'Sneak Lite', GameObject::HAND);
        $this->lands(0, 'Island', 2);
        $this->passUntil(Step::DeclareAttackers, 3);
        $this->assertNotContains(['id' => $sneak, 'how' => 'sneak'], $game->plays(0), 'Only once blockers are declared.');
        $game->declareAttackers(0, [$bears]);
        $this->passUntil(Step::DeclareBlockers);
        $this->assertContains(['id' => $sneak, 'how' => 'sneak'], $game->plays(0));
        $game->cast(0, $sneak, 0, [], 'sneak');
        $this->assertSame(GameObject::HAND, $game->objects[$bears]->zone);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$sneak]->zone);
        $this->assertTrue($game->objects[$sneak]->tapped);
        $this->assertArrayHasKey($sneak, $game->attackers);
    }

    public function testGainLifeFromPreventedDamage(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Mercy Lite')->unsupported);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Plains', 1);
        $this->lands(0, 'Mountain', 2);
        $life = $game->players[0]->life;
        $game->cast(0, $this->put(0, 'Mercy Lite', GameObject::HAND), 0, ["o:{$bears}"]);
        $this->resolve();
        $game->cast(0, $this->put(0, 'Lava Coil', GameObject::HAND), 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$bears]->zone, '3 of the 4 damage is prevented.');
        $this->assertSame($life + 3, $game->players[0]->life);
    }

    public function testMayPayEnergyWhenAttacking(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Energy Champion Lite')->unsupported);
        $champion = $this->put(0, 'Energy Champion Lite', GameObject::BATTLEFIELD);
        $game->players[0]->energy = 3;
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$champion]);
        $this->resolve();
        $this->assertSame(1, $game->objects[$champion]->counter('+1/+1'));
        $this->assertSame(1, $game->players[0]->energy);
    }

    public function testSoulbond(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Bond Lite')->unsupported);
        $this->assertSame([], self::read('Wing Bond Lite')->unsupported);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 3);
        $bond = $this->put(0, 'Bond Lite', GameObject::HAND);
        $game->cast(0, $bond, 0, []);
        $this->resolve();
        $bond = $game->objects[$bond];
        $this->assertSame($bears->id, $game->partner($bond)?->id);
        $this->assertSame([3, 3], [$game->power($bears), $game->toughness($bears)]);
        $this->assertSame([3, 3], [$game->power($bond), $game->toughness($bond)]);

        // A creature entering later pairs with Wing Bond Lite, not the paired ones.
        $this->passUntil(Step::Upkeep, $game->turn + 2);
        $this->passUntil(Step::PrecombatMain);
        $this->lands(0, 'Island', 2);
        $wing = $this->put(0, 'Wing Bond Lite', GameObject::HAND);
        $game->cast(0, $wing, 0, []);
        $this->resolve();
        $this->assertNull($game->partner($game->objects[$wing]), 'No unpaired creature to pair with.');
        $this->assertSame($bears->id, $game->partner($bond)?->id);


        // The pair breaks when one leaves.
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertNull($game->partner($bond));
        $this->assertSame(2, $game->power($bond));
    }

    public function testEmerge(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Emerge Lite')->unsupported);
        $emerge = $this->put(0, 'Emerge Lite', GameObject::HAND);
        $this->lands(0, 'Forest', 4);
        $this->assertNotContains(['id' => $emerge, 'how' => 'emerge'], $game->plays(0), 'Nothing to sacrifice.');
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->assertContains(['id' => $emerge, 'how' => 'emerge'], $game->plays(0), '{5}{G} less 2 for the Bears.');
        $game->cast(0, $emerge, 0, [], 'emerge');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$bears]->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$emerge]->zone);
    }

    public function testPact(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Pact Lite')->unsupported);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Pact Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->passUntil(Step::Draw, 3);
        $this->assertFalse($game->players[0]->lost);
        $this->assertSame(2, count(array_filter($game->permanents(0), fn (GameObject $o) => $o->tapped)), 'Paid at the next upkeep.');

        // Two at once: the second can't be paid, and its caster loses.
        $this->passUntil(Step::PrecombatMain);
        foreach ([1, 2] as $_) {
            $game->cast(0, $this->put(0, 'Pact Lite', GameObject::HAND), 0, []);
            $this->resolve();
        }
        $this->passUntil(Step::Upkeep, 5);
        $this->assertTrue($game->players[0]->lost);
    }

    public function testHideaway(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Hideaway Lite')->unsupported);
        $this->assertSame([], self::read('Windbrisk Lite')->unsupported);
        foreach (['Island', 'Grizzly Bears', 'Island', 'Island'] as $name) {
            $game->addCard(0, self::card($name), GameObject::LIBRARY);
        }
        $library = count($game->players[0]->library);
        $this->lands(0, 'Island', 1);
        $land = $this->put(0, 'Hideaway Lite', GameObject::HAND);
        $game->playLand(0, $land);
        $this->resolve();
        $hidden = $game->objects[$land]->hidden;
        $this->assertSame('Grizzly Bears', $game->objects[$hidden]->name());
        $this->assertSame(GameObject::EXILE, $game->objects[$hidden]->zone);
        $this->assertCount($library - 1, $game->players[0]->library, 'The rest go on the bottom.');

        $game->activate(0, $land, 0);
        $this->resolve();
        $this->assertContains(['id' => $hidden, 'how' => 'cc'], $game->plays(0));
        $game->cast(0, $hidden, 0, [], 'cc');
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$hidden]->zone);
    }

    public function testFlickerSelfDamageExileAllAndGraveyardTuck(): void
    {
        $game = $this->newGame();
        foreach (['Impulse Enter Lite', 'Untapped Hexproof Lite', 'Grave Tuck Lite', 'Justice Lite', 'Flicker Lite', 'Exile Wrath Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $elf = $this->put(0, 'Untapped Hexproof Lite', GameObject::BATTLEFIELD);
        $this->assertContains('hexproof', $game->keywords($game->objects[$elf]));
        $game->objects[$elf]->tapped = true;
        $this->assertNotContains('hexproof', $game->keywords($game->objects[$elf]));

        $this->lands(0, 'Plains', 7);
        $this->lands(0, 'Mountain', 1);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $bears->addCounters('+1/+1', 1);
        $game->cast(0, $this->put(0, 'Flicker Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $bears->zone);
        $this->assertSame(0, $bears->counter('+1/+1'), 'It returns as a new object.');

        $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Justice Lite', GameObject::HAND), 0, ["o:{$theirs->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $theirs->zone);

        $game->cast(0, $this->put(0, 'Exile Wrath Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $bears->zone);
        $this->assertSame(GameObject::EXILE, $game->objects[$elf]->zone);
    }

    public function testBottomThenDrawAndSearchToTop(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Prophecy Lite')->unsupported);
        $this->assertSame([], self::read('Land Scout Lite')->unsupported);
        $this->lands(0, 'Mountain', 4);
        $game->players[0]->hand = [];
        $spare = [$game->addCard(0, self::card('Mountain'), GameObject::HAND)->id, $game->addCard(0, self::card('Mountain'), GameObject::HAND)->id];
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Prophecy Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
        $this->assertSame($game->players[0]->library[0], $spare[0], 'A spare land goes to the bottom.');
        $this->assertCount(2, $game->players[0]->hand, 'And a card is drawn.');

        $game->cast(0, $this->put(0, 'Land Scout Lite', GameObject::HAND), 0, []);
        $this->resolve();
        $this->resolve();
        $top = $game->objects[end($game->players[0]->library)];
        $this->assertContains('Basic', $top->printed()->supertypes);
    }

    public function testDividedDamageTokenCopiesAndLandsFromHand(): void
    {
        $game = $this->newGame();
        foreach (['Twin Bolt Lite', 'Counterpart Lite', 'Skystinger Lite', 'Herbalist Lite', 'Infiltrator Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Island', 3);
        $one = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $two = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Twin Bolt Lite', GameObject::HAND), 0, ["o:{$one->id}", "o:{$two->id}"]);
        $this->resolve();
        $this->assertSame([1, 1], [$one->damage, $two->damage], 'Split one each.');
        $this->assertSame(GameObject::BATTLEFIELD, $one->zone);

        $mine = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Counterpart Lite', GameObject::HAND), 0, ["o:{$mine->id}"]);
        $this->resolve();
        $this->assertCount(2, array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Grizzly Bears'));

        $herbalist = $this->put(0, 'Herbalist Lite', GameObject::BATTLEFIELD);
        $land = $game->addCard(0, self::card('Forest'), GameObject::HAND)->id;
        $game->activate(0, $herbalist, 0);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$land]->zone);
    }

    public function testHastyTokenCopiesAndGraveyardShuffles(): void
    {
        $game = $this->newGame();
        foreach (['Twin Copy Lite', 'Journey Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Mountain', 3);
        $this->lands(0, 'Forest', 2);
        $mine = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Twin Copy Lite', GameObject::HAND), 0, ["o:{$mine->id}"]);
        $this->resolve();
        $copies = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Grizzly Bears' && $o->id !== $mine->id));
        $this->assertCount(1, $copies);
        $this->assertTrue($game->hasKeyword($copies[0], 'haste'));

        $graveyard = [];
        foreach (['Forest', 'Hill Giant', 'Grizzly Bears', 'Shock'] as $name) {
            $graveyard[$name] = $game->addCard(0, self::card($name), GameObject::GRAVEYARD)->id;
        }
        $game->cast(0, $this->put(0, 'Journey Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$graveyard['Forest']]->zone, 'The land stays.');
        $this->assertSame(GameObject::LIBRARY, $game->objects[$graveyard['Hill Giant']]->zone);

        $this->passUntil(Step::End, 1);
        $this->assertNotContains($copies[0]->id, array_map(fn (GameObject $o) => $o->id, $game->permanents(0)), 'Exiled at the end step.');
    }

    public function testTribalEntersStaticConditionsAndDeathCounters(): void
    {
        $game = $this->newGame();
        foreach (['Dragonstorm Lite', 'Collapse Lite', 'Wave Lite', 'Banshee Lite', 'Eruption Lite', 'Sporeback Lite', 'Flayer Lite', 'Stinger Lite', 'Drake Lord Lite', 'Speed Lite', 'Informant Lite', 'Shambler Lite', 'Racketeer Lite', 'Energy Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Only a Dragon entering sends it back.
        $storm = $this->put(0, 'Dragonstorm Lite', GameObject::BATTLEFIELD);
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 4);
        $dragon = $game->objects[$this->put(0, 'Whelp Lite', GameObject::HAND)];
        $game->cast(0, $dragon->id);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($storm), 'The trigger waits on the stack.');
        $this->resolve();
        $this->assertSame(GameObject::HAND, $this->zone($storm));

        // During your turn; with flying; when attacking.
        $spore = $game->objects[$this->put(0, 'Sporeback Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(4, $game->power($spore));
        $this->put(0, 'Drake Lord Lite', GameObject::BATTLEFIELD);
        $this->assertSame(3, $game->power($dragon));
        $this->assertSame(2, $game->power($game->objects[array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Grizzly Bears'))[0]->id]));

        // Each player loses 2; damage to each creature an opponent controls.
        $before = [$game->players[0]->life, $game->players[1]->life];
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->put(0, 'Banshee Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $this->assertSame([$before[0] - 2, $before[1] - 2], [$game->players[0]->life, $game->players[1]->life]);
        $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 3);
        $game->cast(0, $this->put(0, 'Wave Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(1, $theirs->damage);
        $this->assertSame(0, $spore->damage);

        // A creature of yours dies: a counter.
        $shambler = $game->objects[$this->put(0, 'Shambler Lite', GameObject::BATTLEFIELD)];
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$spore->id}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $shambler->counter('+1/+1'));

        // Its controller mills.
        $library = count($game->players[1]->library);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Collapse Lite', GameObject::HAND), 0, ["o:{$theirs->id}"]);
        $this->resolve();
        $this->assertSame($library - 2, count($game->players[1]->library));
    }

    public function testBasicLandChecksDevoteesGraveyardReturnsEndureAndExhaust(): void
    {
        $game = $this->newGame();
        foreach (['Vista Lite', 'Devotee Lite', 'Tunnel Lite', 'Endure Lite', 'Exhaust Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Forest', 1);
        $vista = $this->put(0, 'Vista Lite', GameObject::HAND);
        $game->playLand(0, $vista);
        $this->assertTrue($game->objects[$vista]->tapped, 'One basic is not enough.');
        $game = $this->newGame();
        $this->lands(0, 'Forest', 2);
        $vista = $this->put(0, 'Vista Lite', GameObject::HAND);
        $game->playLand(0, $vista);
        $this->assertFalse($game->objects[$vista]->tapped);

        // The Devotee turns {1} into a color it names.
        $this->put(0, 'Devotee Lite', GameObject::BATTLEFIELD);
        $sources = array_column(array_filter($game->manaSources(0), fn ($source) => ($source['filter'] ?? false)), 'filterAs');
        $this->assertContains(['count' => 1, 'colors' => ['R', 'W', 'B']], $sources);

        // Back from the graveyard, tapped.
        $this->lands(0, 'Swamp', 3);
        $rat = $this->put(0, 'Tunnel Lite', GameObject::GRAVEYARD);
        $game->regrow(0, $rat);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($rat));
        $this->assertTrue($game->objects[$rat]->tapped);

        // Endure 2 as counters.
        $this->lands(0, 'Plains', 2);
        $endure = $this->put(0, 'Endure Lite', GameObject::HAND);
        $game->cast(0, $endure);
        $this->resolve();
        $this->resolve();
        $this->assertSame(2, $game->objects[$endure]->counter('+1/+1'));

        // Exhaust: once only.
        $exhaust = $this->put(0, 'Exhaust Lite', GameObject::BATTLEFIELD);
        $game->activate(0, $exhaust, 0);
        $this->resolve();
        $this->assertSame(2, $game->objects[$exhaust]->counter('+1/+1'));
        $this->expectException(\MTGPocket\Game\GameException::class);
        $game->activate(0, $exhaust, 0);
    }

    public function testTypeDiscountsAndDamageByLandCount(): void
    {
        $game = $this->newGame();
        foreach (['Journeymage Lite', 'Rockslide Lite', 'Games Hero Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // {4}{U} for four with a Wizard out.
        $this->put(0, 'Second Draw Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 4);
        $mage = $this->put(0, 'Journeymage Lite', GameObject::HAND);
        $game->cast(0, $mage);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($mage));

        // Seven lands: seven damage.
        $this->lands(0, 'Mountain', 3);
        $giant = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Rockslide Lite', GameObject::HAND), 0, ["o:{$giant->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $giant->zone);
    }

    public function testBecomesTapped(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Leopard Lite')->unsupported);
        $leopard = $this->put(0, 'Leopard Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $life = $this->life(0);
        $game->declareAttackers(0, [$leopard]);
        $this->resolve();
        $this->assertSame($life + 1, $this->life(0));
    }

    public function testDiscover(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Discover Lite')->unsupported);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY);
        $forest = $game->addCard(0, self::card('Forest'), GameObject::LIBRARY);
        $this->lands(0, 'Forest', 3);
        $game->cast(0, $this->put(0, 'Discover Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $bears->zone, 'Castable while the discover waits.');
        $this->assertSame(GameObject::LIBRARY, $forest->zone);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $bears->zone, 'Not cast: into the hand.');
    }

    public function testColorAndTypeFamilies(): void
    {
        $game = $this->newGame();
        foreach (['Mare Lite', 'Medallion Lite', 'Firewalker Lite', 'Harbinger Lite', 'Kird Lite', 'Piledriver Lite', 'Predation Lite', 'Drifter Lite', 'Sliver Lord Lite', 'Hivepool Lite', 'Rallier Lite', 'Peppersmoke Lite', 'Windstorm Lite', 'Snarecaster Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Kird Ape with a Forest; Slivers for everyone.
        $ape = $game->objects[$this->put(0, 'Kird Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(1, $game->power($ape));
        $this->lands(0, 'Forest', 1);
        $this->assertSame([2, 3], [$game->power($ape), $game->toughness($ape)]);
        $this->put(0, 'Sliver Lord Lite', GameObject::BATTLEFIELD);
        $theirs = $game->objects[$this->put(1, 'Hivepool Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(5, $game->power($theirs), 'All Slivers, whoever controls them.');

        // The Firewalker: casting red Kird Lite gains its controller a life.
        $this->put(0, 'Medallion Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Firewalker Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $life = $this->life(1);
        $game->cast(0, $this->put(0, 'Kird Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $this->assertSame($life + 1, $this->life(1));

        // Windstorm hits only fliers; Predation fights.
        $flier = $game->addCard(1, self::card('Serra Angel'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 3);
        $game->cast(0, $this->put(0, 'Windstorm Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $flier->zone);
        $this->assertSame(0, $theirs->damage);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Predation Lite', GameObject::HAND), 0, ["o:{$ape->id}", "o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
    }

    public function testManlandsXPumpsAndOrPayCosts(): void
    {
        $game = $this->newGame();
        foreach (['Colonnade Lite', 'Construct Lite', 'Wither X Lite', 'Gruesome Lite', 'Elf Reveal Lite', 'Beast Within Lite', 'Swords Lite', 'Regen Aura Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // A manland becomes a 4/4 flier until end of turn.
        $colonnade = $game->objects[$this->put(0, 'Colonnade Lite', GameObject::BATTLEFIELD)];
        $this->assertFalse($game->isCreature($colonnade));
        $this->lands(0, 'Plains', 2);
        $game->activate(0, $colonnade->id, 0);
        $this->resolve();
        $this->assertTrue($game->isCreature($colonnade));
        $this->assertSame([4, 4], [$game->power($colonnade), $game->toughness($colonnade)]);
        $this->assertTrue($game->hasKeyword($colonnade, 'flying'));

        // Swords: the creature's controller gains life equal to its power.
        $angel = $game->addCard(1, self::card('Serra Angel'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 1);
        $life = $this->life(1);
        $game->cast(0, $this->put(0, 'Swords Lite', GameObject::HAND), 0, ["o:{$angel->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $angel->zone);
        $this->assertSame($life + 4, $this->life(1));

        // Beast Within: the permanent's controller gets the Beast.
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 3);
        $game->cast(0, $this->put(0, 'Beast Within Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
        $this->assertCount(1, array_filter($game->permanents(1), fn (GameObject $o) => $o->name() === 'Beast Token'));

        // -X/-X with X = 2.
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 3);
        $game->cast(0, $this->put(0, 'Wither X Lite', GameObject::HAND), 2, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);

        // Sacrifice a creature, or pay {3} without one.
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 2);
        $gruesome = $this->put(0, 'Gruesome Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $gruesome), 'No creature: {3} more.');
        $this->lands(0, 'Swamp', 3);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $gruesome);
        $this->resolve();
        $this->assertSame([], array_filter($game->permanents(0), fn (GameObject $o) => ! $o->tapped));
        $this->assertSame($hand + 1, count($game->players[0]->hand));

        // With a creature, it is sacrificed instead.
        $game = $this->newGame();
        $this->lands(0, 'Swamp', 2);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Gruesome Lite', GameObject::HAND));
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);

        // Reveal an Elf, or pay {3}.
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $reveal = $this->put(0, 'Elf Reveal Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $reveal));
        $game->addCard(0, self::card('Llanowar Elves'), GameObject::HAND);
        $this->assertTrue($game->canCast(0, $reveal));
    }

    public function testSurgeAndBloodrush(): void
    {
        $game = $this->newGame();
        foreach (['Surge Lite', 'Bloodrush Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Surge only after another spell this turn; then it also draws.
        $this->lands(0, 'Mountain', 3);
        $surge = $this->put(0, 'Surge Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $surge, 'surge'));
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Wither X Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertTrue($game->canCast(0, $surge, 'surge'));
        $hand = count($game->players[0]->hand);
        $life = $this->life(1);
        $game->cast(0, $surge, 0, ['p:1'], 'surge');
        $this->resolve();
        $this->assertSame($life - 3, $this->life(1));
        $this->assertSame($hand, count($game->players[0]->hand), 'Cast from hand, then drew one.');

        // Bloodrush: discard it to pump an attacker.
        $game = $this->newGame();
        $bears = $this->put(0, 'Bloodrush Lite', GameObject::BATTLEFIELD);
        $rush = $this->put(0, 'Bloodrush Lite', GameObject::HAND);
        $this->lands(0, 'Mountain', 1);
        $this->assertNotContains(['id' => $rush, 'how' => 'bloodrush'], $game->plays(0));
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$bears]);
        $this->assertContains(['id' => $rush, 'how' => 'bloodrush'], $game->plays(0));
        $game->bloodrush(0, $rush);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$rush]->zone);
        $this->resolve();
        $this->assertSame(6, $game->power($game->objects[$bears]));
    }

    public function testBracketedLoyaltyGodsSwordsAndSpectacle(): void
    {
        $game = $this->newGame();
        foreach (['Bracket Walker Lite', 'God Lite', 'Sword Pair Lite', 'Spectacle Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertSame([1, -2, 0], array_map(fn ($a) => $a['cost']['loyalty'], self::read('Bracket Walker Lite')->activated));
        $this->assertSame(['protection from black', 'protection from green'], self::read('Sword Pair Lite')->equipment['keywords']);

        // A god is a creature only with devotion five.
        $god = $game->objects[$this->put(0, 'God Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(1, $game->devotion(0, ['W']));
        $this->assertFalse($game->isCreature($god));
        $game->addCard(0, self::card('Serra Angel'), GameObject::BATTLEFIELD);
        $this->assertSame(3, $game->devotion(0, ['W']));
        $game->addCard(0, self::card('Serra Angel'), GameObject::BATTLEFIELD);
        $this->assertTrue($game->isCreature($god));

        // Spectacle once an opponent lost life this turn; then it also draws.
        $this->lands(0, 'Swamp', 2);
        $spectacle = $this->put(0, 'Spectacle Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $spectacle, 'spectacle'));
        $first = $this->put(0, 'Spectacle Lite', GameObject::HAND);
        $this->lands(0, 'Swamp', 4);
        $game->cast(0, $first, 0, ['p:1']);
        $this->resolve();
        $this->assertTrue($game->canCast(0, $spectacle, 'spectacle'));
        $hand = count($game->players[0]->hand);
        $game->cast(0, $spectacle, 0, ['p:1'], 'spectacle');
        $this->resolve();
        $this->assertSame(16, $this->life(1));
        $this->assertSame($hand, count($game->players[0]->hand));
    }

    public function testCleaveTypedEquipAndCountedPumps(): void
    {
        $game = $this->newGame();
        foreach (['Cleave Lite', 'Wizard Staff Lite', 'Were-Worm Lite', 'Uril Lite', 'Viashino Lite', 'Onna Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Cleave: only an attacker normally, any creature for the cleave cost.
        $this->assertSame(['attacking'], Game::castTargetKinds(self::read('Cleave Lite'), ''));
        $this->assertSame(['creature'], Game::castTargetKinds(self::read('Cleave Lite'), 'cleave'));
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 4);
        $game->cast(0, $this->put(0, 'Cleave Lite', GameObject::HAND), 0, ["o:{$bears->id}"], 'cleave');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);

        // Equip Wizard: only onto a Wizard.
        $staff = $this->put(0, 'Wizard Staff Lite', GameObject::BATTLEFIELD);
        $angel = $game->addCard(0, self::card('Serra Angel'), GameObject::BATTLEFIELD);
        $this->assertSame([], $game->targetOptions(0, self::read('Wizard Staff Lite')->activated[0]['effects'][0]['target']));
        $this->assertContains("o:{$angel->id}", $game->targetOptions(0, self::read('Wizard Staff Lite')->activated[1]['effects'][0]['target']));

        // For each Mountain; for each Aura on it.
        $worm = $game->objects[$this->put(0, 'Were-Worm Lite', GameObject::BATTLEFIELD)];
        $this->lands(0, 'Mountain', 2);
        $this->assertSame(5, $game->power($worm));
        $uril = $game->objects[$this->put(0, 'Uril Lite', GameObject::BATTLEFIELD)];
        $aura = $game->objects[$this->put(0, 'Small Aura Lite', GameObject::BATTLEFIELD)];
        $aura->attachedTo = $uril->id;
        $this->assertSame(4, $game->power($uril));
    }

    public function testChannelReinforceAndPowerUp(): void
    {
        $game = $this->newGame();
        foreach (['Channel Lite', 'Reinforce Lite', 'Power-up Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Channel: from the hand, discarding it.
        $this->lands(0, 'Mountain', 2);
        $channel = $this->put(0, 'Channel Lite', GameObject::HAND);
        $this->assertContains([$channel, 0], $game->activatableAbilities(0));
        $life = $this->life(1);
        $game->activate(0, $channel, 0, ['p:1']);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$channel]->zone);
        $this->resolve();
        $this->assertSame($life - 2, $this->life(1));

        // Reinforce: counters on a creature, from the hand.
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 2);
        $game->activate(0, $this->put(0, 'Reinforce Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(2, $bears->counter('+1/+1'));

        // Power-up: once, as a sorcery; not from the hand.
        $hand = $this->put(0, 'Power-up Lite', GameObject::HAND);
        $this->assertNotContains([$hand, 0], $game->activatableAbilities(0));
        $up = $this->put(0, 'Power-up Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 6);
        $game->activate(0, $up, 0);
        $this->resolve();
        $this->assertSame(2, $game->objects[$up]->counter('+1/+1'));
        $this->assertFalse($game->canActivate(0, $up, 0));
    }

    public function testMayhemAndRecover(): void
    {
        $game = $this->newGame();
        foreach (['Mayhem Lite', 'Recover Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Mayhem: from the graveyard only the turn it was discarded.
        $this->lands(0, 'Mountain', 2);
        $mayhem = $this->put(0, 'Mayhem Lite', GameObject::GRAVEYARD);
        $this->assertFalse($game->canCast(0, $mayhem, 'mh'));
        $loot = $this->put(0, 'Mayhem Lite', GameObject::HAND);
        (fn () => $this->discardCards(0, [$loot]))->call($game);
        $this->assertTrue($game->canCast(0, $loot, 'mh'));
        $life = $this->life(1);
        $game->cast(0, $loot, 0, ['p:1'], 'mh');
        $this->resolve();
        $this->assertSame($life - 3, $this->life(1));

        // Recover: a creature dies, so it returns when paid for, or is exiled.
        $recover = $this->put(0, 'Recover Lite', GameObject::GRAVEYARD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
        $this->assertSame(GameObject::HAND, $game->objects[$recover]->zone, 'Paid {1}.');

        $again = $this->put(0, 'Recover Lite', GameObject::GRAVEYARD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $game->objects[$again]->zone, 'No mana left to pay.');
    }

    public function testRevealLandsAttackingPumpsXTokensAndCantBlockIt(): void
    {
        $game = $this->newGame();
        foreach (['Amphitheater Lite', 'Inspiration Lite', 'Gremlins Lite', 'Stomp Kick Lite', 'Hatchling Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertSame(['reveal' => 'Giant'], self::read('Amphitheater Lite')->entersTappedUnless);
        $kicked = self::read('Stomp Kick Lite')->spellEffects([], true);
        $this->assertSame([-5, -5], [$kicked[0]['power'], $kicked[0]['toughness']]);

        // X tokens.
        $this->lands(0, 'Mountain', 5);
        $game->cast(0, $this->put(0, 'Gremlins Lite', GameObject::HAND), 3);
        $this->resolve();
        $this->assertCount(3, array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Gremlin Token'));

        // Can't block it, then attackers get +2/+0.
        $hatchling = $this->put(0, 'Hatchling Lite', GameObject::BATTLEFIELD);
        $wall = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $this->lands(0, 'Mountain', 1);
        $game->declareAttackers(0, [$hatchling]);
        $game->activate(0, $hatchling, 0, ["o:{$wall->id}"]);
        $this->resolve();
        $this->assertFalse($game->canBlock($wall, $game->objects[$hatchling]));
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Inspiration Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(4, $game->power($game->objects[$hatchling]));
    }

    public function testMasteryArtifactLordsArtifactSacrificeAndForcedBlocks(): void
    {
        $game = $this->newGame();
        foreach (['Mastery Lite', 'Chrome Lite', 'Giant Sac Lite', 'Vortex Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Mastery: the cheaper cost, and the opponent draws.
        $this->lands(0, 'Swamp', 2);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $mastery = $this->put(0, 'Mastery Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $mastery));
        $this->assertTrue($game->canCast(0, $mastery, 'mastery'));
        $hand = count($game->players[1]->hand);
        $game->cast(0, $mastery, 0, ["o:{$bears->id}"], 'mastery');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
        $this->assertSame($hand + 1, count($game->players[1]->hand));

        // Other artifact creatures get +1/+0; sacrificing an artifact pays for the ping.
        $game = $this->newGame();
        $chrome = $game->objects[$this->put(0, 'Chrome Lite', GameObject::BATTLEFIELD)];
        $other = $game->objects[$this->put(0, 'Chrome Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(2, $game->power($chrome));
        $giant = $game->objects[$this->put(0, 'Giant Sac Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(3, $game->power($giant));
        $this->lands(0, 'Mountain', 2);
        $life = $this->life(1);
        $game->activate(0, $giant->id, 0, ['p:1']);
        $this->resolve();
        $this->assertSame($life - 2, $this->life(1));
        $this->assertSame(1, count(array_filter([$chrome, $other], fn (GameObject $o) => $o->zone === GameObject::GRAVEYARD)));

        // Vortex: the target must block it.
        $game = $this->newGame();
        $vortex = $game->objects[$this->put(0, 'Vortex Lite', GameObject::BATTLEFIELD)];
        $vortex->sick = false;
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 1);
        $game->activate(0, $vortex->id, 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertContains("blocks o{$vortex->id} if able", $game->keywords($bears));
    }

    public function testStriveCostsMoreForEachExtraTarget(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Strive Lite')->unsupported);
        $bears = [$game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD), $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)];
        $strive = $this->put(0, 'Strive Lite', GameObject::HAND);
        $this->lands(0, 'Mountain', 2);
        try {
            $game->cast(0, $strive, 0, ["o:{$bears[0]->id}", "o:{$bears[1]->id}", '-']);
            $this->fail('Two targets cost {R} plus {1}{R}.');
        } catch (GameException) {
        }
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $strive, 0, ["o:{$bears[0]->id}", "o:{$bears[1]->id}", '-']);
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::GRAVEYARD], [$bears[0]->zone, $bears[1]->zone]);
    }

    public function testCraftExilesAnArtifactAndTransforms(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Craft Lite // Crafted Lite')->unsupported);
        $craft = $game->objects[$this->put(0, 'Craft Lite // Crafted Lite', GameObject::BATTLEFIELD)];
        $this->lands(0, 'Plains', 2);
        $this->assertFalse($game->canActivate(0, $craft->id, 0));
        $chrome = $game->objects[$this->put(0, 'Chrome Lite', GameObject::GRAVEYARD)];
        $game->activate(0, $craft->id, 0);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $chrome->zone);
        $this->assertSame('Crafted Lite', $craft->name());
        $this->assertSame(GameObject::BATTLEFIELD, $craft->zone);
    }

    public function testWebSlingingReturnsATappedCreature(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Webslinger Lite')->unsupported);
        $this->lands(0, 'Plains', 1);
        $sling = $this->put(0, 'Webslinger Lite', GameObject::HAND);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->assertFalse($game->canCast(0, $sling, 'webslinging'));
        $bears->tapped = true;
        $this->assertTrue($game->canCast(0, $sling, 'webslinging'));
        $game->cast(0, $sling, 0, [], 'webslinging');
        $this->resolve();
        $this->assertSame(GameObject::HAND, $bears->zone);
        $this->assertSame(GameObject::BATTLEFIELD, $game->objects[$sling]->zone);
    }

    public function testInquisitionTokenCarriedEquipmentAndTwoKickers(): void
    {
        $game = $this->newGame();
        foreach (['Kozilek Lite', 'Shortsword Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->assertSame('mv_le_3&nonland', self::read('Kozilek Lite')->spellEffects()[0]['filter']);
        $sword = $game->objects[$this->put(0, 'Shortsword Lite', GameObject::HAND)];
        $this->lands(0, 'Plains', 1);
        $game->cast(0, $sword->id);
        $this->resolve();
        $this->resolve();
        $this->assertNotNull($sword->attachedTo);
        $dwarf = $game->objects[$sword->attachedTo];
        $this->assertSame([3, 3], [$game->power($dwarf), $game->toughness($dwarf)]);

        // Kicker {1}{B} and/or {G}: kicked pays both, and both riders happen.
        $game = $this->newGame();
        $this->assertSame([], self::read('Two Kicker Lite')->unsupported);
        $this->lands(0, 'Swamp', 3);
        $this->lands(0, 'Forest', 1);
        $life = $this->life(0);
        $game->cast(0, $this->put(0, 'Two Kicker Lite', GameObject::HAND), 0, [], 'kick');
        $this->resolve();
        $this->assertSame($life + 5, $this->life(0));
    }

    public function testUpkeepBouncePowerManaSkipDrawAndTiered(): void
    {
        $game = $this->newGame();
        foreach (['Wildebeest Lite', 'Lecturer Lite', 'Necro Lite', 'Tiered Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Tiered: exactly one mode, paying its cost.
        $this->assertSame(['min' => 1, 'max' => 1], self::read('Tiered Lite')->choose);
        $this->assertSame('{U}{1}', Game::castCost(self::read('Tiered Lite'), 'm1'));
        $this->lands(0, 'Island', 2);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Tiered Lite', GameObject::HAND), 0, [], 'm1');
        $this->resolve();
        $this->assertSame($hand + 2, count($game->players[0]->hand));

        // A creature taps for as much mana as its power.
        $game = $this->newGame();
        $lecturer = $game->objects[$this->put(0, 'Lecturer Lite', GameObject::BATTLEFIELD)];
        $lecturer->sick = false;
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::HAND);
        $this->assertTrue($game->canCast(0, $bears->id));

        // Skip your draw step; the upkeep returns a green creature.
        $game = $this->newGame();
        $this->put(0, 'Necro Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Wildebeest Lite', GameObject::BATTLEFIELD);
        $grizzly = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame([$grizzly->id], $game->players[0]->hand);
    }

    public function testParadigmCopiesEachFirstMainPhase(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Paradigm Lite')->unsupported);
        $this->lands(0, 'Plains', 1);
        $life = $this->life(0);
        $paradigm = $this->put(0, 'Paradigm Lite', GameObject::HAND);
        $game->cast(0, $paradigm);
        $this->resolve();
        $this->assertSame($life + 2, $this->life(0));
        $this->assertSame(GameObject::EXILE, $game->objects[$paradigm]->zone);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->resolve();
        $this->assertSame($life + 4, $this->life(0));
        $this->assertSame(GameObject::EXILE, $game->objects[$paradigm]->zone);
    }

    public function testHauntTriggersWhenTheHauntedCreatureDies(): void
    {
        $game = $this->newGame();
        foreach (['Haunter Lite', 'Haunt Spell Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $life = $this->life(0);
        $haunter = $this->put(0, 'Haunter Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 6);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$haunter}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $game->objects[$haunter]->zone);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame($life + 2, $this->life(0));

        // A haunt spell haunts a creature as it resolves.
        $game = $this->newGame();
        $life = $this->life(0);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 1);
        $spell = $this->put(0, 'Haunt Spell Lite', GameObject::HAND);
        $game->cast(0, $spell);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $game->objects[$spell]->zone);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame($life + 4, $this->life(0));
    }

    public function testMutateMergesOntoANonHumanCreature(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Mutant Lite')->unsupported);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $mutant = $this->put(0, 'Mutant Lite', GameObject::HAND);
        $this->lands(0, 'Forest', 3);
        $life = $this->life(0);
        $this->assertTrue($game->canCast(0, $mutant, 'mutate'));
        $game->cast(0, $mutant, 0, ["o:{$bears->id}"], 'mutate');
        $this->resolve();
        $this->resolve();
        // On top: a 4/4 Mutant Lite with trample, still the Bears' object.
        $this->assertSame('Mutant Lite', $bears->name());
        $this->assertSame([4, 4], [$game->power($bears), $game->toughness($bears)]);
        $this->assertTrue($game->hasKeyword($bears, 'trample'));
        $this->assertSame($life + 3, $this->life(0));
        $this->assertNotContains($mutant, $game->battlefield);
        // Both cards go to the graveyard together.
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$mutant]->zone);
        $this->assertSame('Grizzly Bears', $bears->name());
    }

    public function testCollectEvidenceAndChaliceCounters(): void
    {
        $game = $this->newGame();
        foreach (['Evidence Lite', 'Chalice Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Collect evidence 4: exile mana value 4 from the graveyard to draw two instead of one.
        $this->lands(0, 'Island', 2);
        $evidence = $this->put(0, 'Evidence Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $evidence, 'evidence'));
        $wildebeest = $this->put(0, 'Wildebeest Lite', GameObject::GRAVEYARD);
        $bears = $this->put(0, 'Lecturer Lite', GameObject::GRAVEYARD);
        $this->assertTrue($game->canCast(0, $evidence, 'evidence'));
        $hand = count($game->players[0]->hand);
        $game->cast(0, $evidence, 0, [], 'evidence');
        $this->resolve();
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Cast one, drew two.');
        $this->assertSame([GameObject::EXILE, GameObject::EXILE], [$game->objects[$wildebeest]->zone, $game->objects[$bears]->zone]);

        // Chalice with X = 1 counters a one-mana spell.
        $game = $this->newGame();
        $this->lands(0, 'Island', 3);
        $chalice = $this->put(0, 'Chalice Lite', GameObject::HAND);
        $game->cast(0, $chalice, 1);
        $this->resolve();
        $this->assertSame(1, $game->objects[$chalice]->counter('charge'));
        $draw = $this->put(0, 'Evidence Lite', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $draw);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$draw]->zone);
        $this->assertSame($hand - 1, count($game->players[0]->hand), 'Countered: no card drawn.');
    }

    public function testChooseAndFightTypeEvasionDeathPowerExtraLandsAndTypeProtection(): void
    {
        $game = $this->newGame();
        foreach (['Tail Swipe Lite', 'Juggernaut Lite', 'Phantom Lite', 'Ramble Lite', 'Fowl Lite', 'Kenway Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Choose two creatures; yours gets +1/+1, then they fight.
        $mine = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 2);
        $game->cast(0, $this->put(0, 'Tail Swipe Lite', GameObject::HAND), 0, ["o:{$mine->id}", "o:{$theirs->id}"]);
        $this->resolve();
        $this->assertSame([GameObject::BATTLEFIELD, GameObject::GRAVEYARD], [$mine->zone, $theirs->zone]);

        // Walls can't block it; Elves can't block the Assassin.
        $game = $this->newGame();
        $juggernaut = $this->put(0, 'Juggernaut Lite', GameObject::BATTLEFIELD);
        $kenway = $this->put(0, 'Kenway Lite', GameObject::BATTLEFIELD);
        $wall = $this->battlefield(1, 'Wall of Stone');
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $elves = $this->battlefield(1, 'Llanowar Elves');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$juggernaut, $kenway]);
        $this->assertFalse($game->canBlock($game->objects[$wall], $game->objects[$juggernaut]));
        $this->assertTrue($game->canBlock($game->objects[$bears], $game->objects[$juggernaut]));
        $this->assertFalse($game->canBlock($game->objects[$elves], $game->objects[$kenway]));
        $this->assertTrue($game->canBlock($game->objects[$bears], $game->objects[$kenway]));

        // Dies: damage equal to its power.
        $game = $this->newGame();
        $phantom = $this->put(0, 'Phantom Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$phantom}"]);
        $this->resolve();
        $life = $this->life(1);
        $this->assertNotNull($game->triggerAwaitingTargets());
        $game->chooseTriggerTargets(0, ['p:1']);
        $this->resolve();
        $this->assertSame($life - 2, $this->life(1));

        // An additional land this turn.
        $game = $this->newGame();
        $this->lands(0, 'Forest', 2);
        $game->cast(0, $this->put(0, 'Ramble Lite', GameObject::HAND));
        $this->resolve();
        $game->playLand(0, $game->addCard(0, self::card('Forest'), GameObject::HAND)->id);
        $game->playLand(0, $game->addCard(0, self::card('Forest'), GameObject::HAND)->id);
        $this->assertSame(1, $game->players[0]->landsPlayed);

        // Reinforce from an instant in hand.
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $fowl = $this->put(0, 'Fowl Lite', GameObject::HAND);
        $this->lands(0, 'Forest', 3);
        $this->assertContains([$fowl, 0], $game->activatableAbilities(0));
    }

    public function testRepeatedModesCreatureOnlyManaSacrificeKickerAndGraveyardShuffle(): void
    {
        $game = $this->newGame();
        foreach (['Confluence Lite', 'Savant Lite', 'Stomp Lite', 'Recycle Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // The same mode twice.
        $this->lands(0, 'Island', 4);
        $life = $this->life(0);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Confluence Lite', GameObject::HAND), 0, [], 'm1+0+0');
        $this->resolve();
        $this->assertSame($life + 4, $this->life(0));
        $this->assertSame($hand + 1, count($game->players[0]->hand));

        // Creature-only mana.
        $game = $this->newGame();
        $savant = $game->objects[$this->put(0, 'Savant Lite', GameObject::BATTLEFIELD)];
        $savant->sick = false;
        $this->lands(0, 'Swamp', 1);
        $this->assertTrue($game->canCast(0, $this->hand(0, 'Grizzly Bears')));
        $this->assertFalse($game->canCast(0, $this->put(0, 'Terror', GameObject::HAND)));

        // Kicked by sacrificing a creature.
        $game = $this->newGame();
        $this->lands(0, 'Mountain', 2);
        $stomp = $this->put(0, 'Stomp Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $stomp, 'bargain'));
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $life = $this->life(1);
        $game->cast(0, $stomp, 0, ['p:1'], 'bargain');
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone);
        $this->assertSame($life - 4, $this->life(1));

        // Then shuffle your graveyard into your library.
        $this->lands(0, 'Forest', 2);
        $game->cast(0, $regrowth = $this->put(0, 'Recycle Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $bears->zone);
        $this->assertSame([$regrowth], $game->players[0]->graveyard, 'Stomp Lite was shuffled away; the spell itself goes there after.');
    }

    public function testImpending(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Overlord Lite')->unsupported);
        $this->lands(0, 'Forest', 3);
        $overlord = $this->put(0, 'Overlord Lite', GameObject::HAND);
        $life = $this->life(0);
        $game->cast(0, $overlord, 0, [], 'impending');
        $this->resolve();
        $this->resolve();
        $object = $game->objects[$overlord];
        $this->assertSame([GameObject::BATTLEFIELD, 2, false], [$object->zone, $object->counter('time'), $game->isCreature($object)]);
        $this->assertSame($life + 2, $this->life(0));
        $this->passUntil(Step::End, 1);
        $this->assertSame(1, $object->counter('time'));
        $this->passUntil(Step::End, 2);
        $this->assertSame(1, $object->counter('time'), "Only on its controller's end step.");
        $this->passUntil(Step::End, 3);
        $this->assertSame(0, $object->counter('time'));
        $this->assertTrue($game->isCreature($object));
    }

    public function testCreatureDealtDamageThisTurnDies(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Sengir Lite')->unsupported);
        $sengir = $this->put(0, 'Sengir Lite', GameObject::BATTLEFIELD);
        $game->objects[$sengir]->sick = false;
        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->activate(0, $sengir, 0, ["o:{$giant->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $giant->zone);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$giant->id}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $giant->zone);
        $this->assertSame(1, $game->objects[$sengir]->counter('+1/+1'));

        // Not one it didn't damage.
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id}"]);
        $this->resolve();
        $this->resolve();
        $this->assertSame(1, $game->objects[$sengir]->counter('+1/+1'));
    }

    public function testConditionalAlternativeCosts(): void
    {
        $game = $this->newGame();
        foreach (['Snuff Lite', 'Breath Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Four life instead of its mana cost, with a Swamp.
        $snuff = $this->put(0, 'Snuff Lite', GameObject::HAND);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->assertFalse($game->canCast(0, $snuff, 'conditional'));
        $swamp = $this->lands(0, 'Swamp', 1);
        $game->objects[$swamp[0]]->tapped = true;
        $this->assertTrue($game->canCast(0, $snuff, 'conditional'));
        $life = $this->life(0);
        $game->cast(0, $snuff, 0, ["o:{$bears->id}"], 'conditional');
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, $life - 4], [$bears->zone, $this->life(0)]);

        // Free with a Dragon.
        $breath = $this->put(0, 'Breath Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $breath, 'conditional'));
        $this->put(0, 'Whelp Lite', GameObject::BATTLEFIELD);
        $life = $this->life(1);
        $game->cast(0, $breath, 0, ['p:1'], 'conditional');
        $this->resolve();
        $this->assertSame($life - 3, $this->life(1));
    }

    public function testMainPhaseAndCardTypeConditions(): void
    {
        $game = $this->newGame();
        foreach (['Swipe Lite', 'Salvage Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Cast in a main phase: +1/+1 first, so ours survives the fight.
        $mine = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 2);
        $this->assertContains($game->step, [Step::PrecombatMain, Step::PostcombatMain]);
        $game->cast(0, $this->put(0, 'Swipe Lite', GameObject::HAND), 0, ["o:{$mine->id}", "o:{$theirs->id}"]);
        $this->resolve();
        $this->assertSame([GameObject::BATTLEFIELD, GameObject::GRAVEYARD], [$mine->zone, $theirs->zone]);

        // One card, then another with an artifact.
        $this->lands(0, 'Island', 4);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Salvage Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame($hand + 1, count($game->players[0]->hand));
        $this->put(0, 'Ornithopter', GameObject::BATTLEFIELD);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Salvage Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame($hand + 2, count($game->players[0]->hand));
    }

    public function testCastTriggersCopyThatSpell(): void
    {
        $game = $this->newGame();
        foreach (['Mirror Lite', 'Twin Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Mirror Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $life = $this->life(1);
        $game->cast(0, $this->hand(0, 'Lightning Bolt'), 0, ['p:1']);
        $this->resolve();
        $this->resolve();
        $this->resolve();
        $this->assertSame($life - 6, $this->life(1));

        // Only the second spell each turn.
        $game = $this->newGame();
        $this->put(0, 'Twin Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        $life = $this->life(1);
        $game->cast(0, $this->hand(0, 'Shock'), 0, ['p:1']);
        $this->resolve();
        $this->assertSame($life - 2, $this->life(1));
        $game->cast(0, $this->hand(0, 'Shock'), 0, ['p:1']);
        $this->resolve();
        $this->resolve();
        $this->resolve();
        $this->assertSame($life - 6, $this->life(1));
    }

    public function testChosenTypeAndTypedTeamPumps(): void
    {
        $game = $this->newGame();
        foreach (['Kinsong Lite', 'Elfsong Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $bears = [$game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD), $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)];
        $elf = $game->addCard(0, self::card('Llanowar Elves'), GameObject::BATTLEFIELD);
        $theirs = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        // The game picks Bear, the type most of your creatures share.
        $this->lands(0, 'Plains', 2);
        $game->cast(0, $this->put(0, 'Kinsong Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([4, 4, 1, 2], [$game->power($bears[0]), $game->power($bears[1]), $game->power($elf), $game->power($theirs)]);

        $this->lands(0, 'Forest', 1);
        $game->cast(0, $this->put(0, 'Elfsong Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([4, 2], [$game->power($bears[0]), $game->power($elf)]);
    }

    public function testNoSpellsOnYourTurnExileUntilLeavesToHandAndHastyCopies(): void
    {
        $game = $this->newGame();
        foreach (['Silence Lite', 'Warden Lite', 'Kiki Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Opponents can't cast spells during your turn.
        $this->lands(1, 'Mountain', 1);
        $bolt = $this->hand(1, 'Lightning Bolt');
        $game->priority = 1;
        $this->assertTrue($game->canCast(1, $bolt));
        $this->put(0, 'Silence Lite', GameObject::BATTLEFIELD);
        $this->assertFalse($game->canCast(1, $bolt));
        $game->priority = 0;

        // Exiled until it leaves, then back to its owner's hand.
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 2);
        $warden = $this->put(0, 'Warden Lite', GameObject::HAND);
        $game->cast(0, $warden);
        $this->resolve();
        if ($game->triggerAwaitingTargets() !== null) {
            $game->chooseTriggerTargets(0, ["o:{$bears->id}"]);
        }
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $bears->zone);
        $this->lands(0, 'Swamp', 2);
        $game->cast(0, $this->put(0, 'Terror', GameObject::HAND), 0, ["o:{$warden}"]);
        $this->resolve();
        $this->assertSame(GameObject::HAND, $bears->zone);

        // A hasty copy.
        $this->lands(0, 'Mountain', 3);
        $mine = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Kiki Lite', GameObject::HAND), 0, ["o:{$mine->id}"]);
        $this->resolve();
        $copy = $game->objects[max(array_keys($game->objects))];
        $this->assertSame('Grizzly Bears', $copy->name());
        $this->assertContains('haste', $game->keywords($copy));
    }

    public function testAnyColorLandsCreaturesFromTopAndEndTheTurn(): void
    {
        $game = $this->newGame();
        foreach (['Lantern Lite', 'Toplook Lite', 'Stop Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // A Forest taps for red with the Lantern out.
        $this->lands(0, 'Forest', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $this->assertFalse($game->canCast(0, $bolt));
        $this->put(0, 'Lantern Lite', GameObject::BATTLEFIELD);
        $this->assertTrue($game->canCast(0, $bolt));

        // Creature spells from the top of the library, not other spells.
        $game = $this->newGame();
        $this->lands(0, 'Forest', 2);
        $this->put(0, 'Toplook Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::LIBRARY);
        $this->assertSame($bears->id, end($game->players[0]->library));
        $this->assertContains($bears->id, array_column($game->plays(0), 'id'));
        $game->cast(0, $bears->id);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $bears->zone);
        $giant = $game->addCard(0, self::card('Giant Growth'), GameObject::LIBRARY);
        $this->assertNotContains($giant->id, array_column($game->plays(0), 'id'));

        // End the turn: the spell under it is exiled and the next turn begins.
        $game = $this->newGame();
        $this->lands(0, 'Island', 6);
        $this->lands(0, 'Mountain', 1);
        $life = $this->life(1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $game->cast(0, $bolt, 0, ['p:1']);
        $game->cast(0, $this->put(0, 'Stop Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([GameObject::EXILE, $life, 2], [$game->objects[$bolt]->zone, $this->life(1), $game->turn]);
    }

    public function testEarthbendReturnsTheLandTapped(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Bender Lite')->unsupported);
        $this->lands(0, 'Forest', 2);
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->put(0, 'Bender Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $land = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->earthbent))[0];
        $this->assertSame([true, 2, 2], [$game->isCreature($land), $game->power($land), $game->toughness($land)]);
        $this->assertContains('haste', $game->keywords($land));
        $game = $this->game = Game::fromArray(json_decode(json_encode($game->toArray()), true));
        $this->assertTrue($game->objects[$land->id]->earthbent);
        $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$land->id}"]);
        $this->resolve();
        $land = $game->objects[$land->id];
        $this->assertSame([GameObject::BATTLEFIELD, true, false, 0], [$land->zone, $land->tapped, $game->isCreature($land), $land->counter('+1/+1')]);
    }

    public function testLanderAndMapTokens(): void
    {
        $game = $this->newGame();
        foreach (['Lander Lite', 'Mapper Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Forest', 2);
        $game->cast(0, $this->put(0, 'Lander Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $lander = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Lander'))[0];
        $this->lands(0, 'Forest', 2);
        $lands = count(array_filter($game->permanents(0), fn (GameObject $o) => $o->definition()->isLand()));
        $game->activate(0, $lander->id, 0);
        $this->resolve();
        $this->assertSame(GameObject::GONE, $lander->zone);
        $fetched = array_filter($game->permanents(0), fn (GameObject $o) => $o->definition()->isLand());
        $this->assertCount($lands + 1, $fetched);

        $game = $this->newGame();
        $this->lands(0, 'Forest', 2);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Mapper Lite', GameObject::HAND));
        $this->resolve();
        $maps = array_values(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Map'));
        $this->assertCount(2, $maps);
        $hand = count($game->players[0]->hand);
        $game->activate(0, $maps[0]->id, 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'The top card is a Forest.');
    }

    public function testPitchSpells(): void
    {
        $game = $this->newGame();
        foreach (['Pitch Bolt Lite', 'Flare Lite', 'Pitch Negate Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $pitch = $this->put(0, 'Pitch Bolt Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $pitch, 'conditional'), 'No other red card to exile.');
        $bolt = $this->hand(0, 'Lightning Bolt');
        $this->assertTrue($game->canCast(0, $pitch, 'conditional'));
        $life = $this->life(1);
        $game->cast(0, $pitch, 0, ['p:1'], 'conditional');
        $this->resolve();
        $this->assertSame([GameObject::EXILE, $life - 3], [$game->objects[$bolt]->zone, $this->life(1)]);

        $flare = $this->put(0, 'Flare Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $flare, 'conditional'));
        $goblin = $this->put(0, 'Ornery Goblin Lite', GameObject::BATTLEFIELD);
        $game->cast(0, $flare, 0, ['p:1'], 'conditional');
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, $life - 5], [$game->objects[$goblin]->zone, $this->life(1)]);

        // Only on an opponent's turn.
        $negate = $this->put(0, 'Pitch Negate Lite', GameObject::HAND);
        $this->put(0, 'Pitch Negate Lite', GameObject::HAND);
        $this->assertFalse($game->canCast(0, $negate, 'conditional'));
        $other = $this->put(1, 'Pitch Negate Lite', GameObject::HAND);
        $this->put(1, 'Pitch Negate Lite', GameObject::HAND);
        $this->lands(0, 'Forest', 1);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->hand(0, 'Giant Growth'), 0, ["o:{$bears->id}"]);
        $game->priority = 1;
        $this->assertTrue($game->canCast(1, $other, 'conditional'));
        $game->cast(1, $other, 0, ['s:'.$game->stack[0]['id']], 'conditional');
        $this->resolve();
        $this->assertSame(2, $game->power($bears), 'Countered.');
    }

    public function testPayAnyAmountOfEnergy(): void
    {
        $game = $this->newGame();
        foreach (['Harness Lite', 'Charger Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Mountain', 2);
        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Harness Lite', GameObject::HAND), 0, ["o:{$giant->id}"]);
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, 0], [$giant->zone, $game->players[0]->energy]);

        $this->lands(0, 'Forest', 2);
        $game->players[0]->energy = 2;
        $charger = $game->objects[$this->put(0, 'Charger Lite', GameObject::HAND)];
        $game->cast(0, $charger->id);
        $this->resolve();
        $this->resolve();
        $this->assertSame([2, 0], [$charger->counter('+1/+1'), $game->players[0]->energy]);
    }

    public function testActivatedAbilitiesWithXInTheirCost(): void
    {
        $game = $this->newGame();
        foreach (['Firebreather Lite', 'X Pinger Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $dragon = $game->objects[$this->put(0, 'Firebreather Lite', GameObject::BATTLEFIELD)];
        $this->lands(0, 'Mountain', 1);
        $this->assertFalse($game->canActivate(0, $dragon->id, 0), 'X is at least 1.');
        $this->lands(0, 'Mountain', 3);
        $game->activate(0, $dragon->id, 0);
        $this->resolve();
        $this->assertSame(5, $game->power($dragon));

        $game = $this->newGame();
        $pinger = $game->objects[$this->put(0, 'X Pinger Lite', GameObject::BATTLEFIELD)];
        $this->lands(0, 'Mountain', 2);
        $life = $this->life(1);
        $game->activate(0, $pinger->id, 0, ['p:1']);
        $this->resolve();
        $this->assertSame($life - 2, $this->life(1));
    }

    public function testTriggersOnTheCreatureThatEntered(): void
    {
        $game = $this->newGame();
        foreach (['Welcome Lite', 'Auto Sword Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $welcome = $game->objects[$this->put(0, 'Welcome Lite', GameObject::BATTLEFIELD)];
        $sword = $game->objects[$this->put(0, 'Auto Sword Lite', GameObject::BATTLEFIELD)];
        $this->lands(0, 'Forest', 2);
        $bears = $this->hand(0, 'Grizzly Bears');
        $game->cast(0, $bears);
        for ($i = 0; $i < 3; $i++) {
            $this->resolve();
        }
        $this->assertSame([1, 0, $bears], [$game->objects[$bears]->counter('+1/+1'), $welcome->counter('+1/+1'), $sword->attachedTo]);
        $this->assertSame(5, $game->power($game->objects[$bears]));
    }

    public function testTriggersOnCreaturesYouControlAttacking(): void
    {
        $game = $this->newGame();
        foreach (['War Banner Lite', 'Raid Horn Lite', 'Spy Net Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'War Banner Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Raid Horn Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Spy Net Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $hand = count($game->players[0]->hand);
        $game->declareAttackers(0, [$bears->id]);
        $this->resolve();
        $this->resolve();
        $this->assertSame([3, 3, 3, 4], [$game->power($bears), $game->toughness($bears), $game->power($giant), $game->toughness($giant)]);
        $this->passUntil(Step::PostcombatMain, 3);
        $this->assertSame($hand + 1, count($game->players[0]->hand));
    }

    public function testDiesCombatOpponentCastAndOpponentsArtifactTriggers(): void
    {
        $game = $this->newGame();
        foreach (['Blood Pact Lite', 'Drill Sergeant Lite', 'Disenchanter Lite', 'Watcher Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $pact = $this->put(0, 'Blood Pact Lite', GameObject::BATTLEFIELD);
        $watcher = $this->put(1, 'Watcher Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->resolve();
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, $mine + 1, $theirs], [$bears->zone, $this->life(0), $this->life(1)], 'They gain 1 for the Shock and lose 1 for the Bears.');
        $this->assertSame(["o:{$watcher}"], $game->targetOptions(0, 'artifact_or_enchantment_opponent'));
        $this->assertNotContains("o:{$pact}", $game->targetOptions(0, 'artifact_or_enchantment_opponent'));

        $game = $this->newGame();
        $this->put(0, 'Drill Sergeant Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->passUntil(Step::BeginCombat, 3);
        if ($game->triggerAwaitingTargets() !== null) {
            $game->chooseTriggerTargets(0, ["o:{$bears->id}"]);
        }
        $this->resolve();
        $this->assertSame(3, $game->power($bears));
    }

    public function testEndStepConditionsOnWhatHappenedThisTurn(): void
    {
        $game = $this->newGame();
        foreach (['Lifewatch Lite', 'Raider Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Lifewatch Lite', GameObject::BATTLEFIELD);
        $raider = $game->objects[$this->put(0, 'Raider Lite', GameObject::BATTLEFIELD)];
        $hand = count($game->players[0]->hand);
        $this->passUntil(Step::End, 1);
        $this->resolve();
        $this->assertSame([$hand, 0], [count($game->players[0]->hand), $raider->counter('+1/+1')], 'Nothing happened on turn 1.');

        $this->passUntil(Step::DeclareAttackers, 3);
        $hand = count($game->players[0]->hand);
        $game->players[0]->gained = 2;
        $game->players[0]->gainedTurn = 3;
        $game->declareAttackers(0, [$raider->id]);
        $this->passUntil(Step::End, 3);
        $this->resolve();
        $this->resolve();
        $this->assertSame([$hand + 1, 1], [count($game->players[0]->hand), $raider->counter('+1/+1')]);
    }

    public function testFirstSpellEachTurnAndMaySacrifice(): void
    {
        $game = $this->newGame();
        foreach (['Bargainer Lite', 'First Spark Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'First Spark Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        $shock = $this->hand(0, 'Shock');
        $bolt = $this->hand(0, 'Lightning Bolt');
        $hand = count($game->players[0]->hand);
        $game->cast(0, $shock, 0, ['p:1']);
        $this->resolve();
        $this->resolve();
        $game->cast(0, $bolt, 0, ['p:1']);
        $this->resolve();
        $this->assertSame($hand - 1, count($game->players[0]->hand), 'Two spells cast, one card drawn.');

        $game = $this->newGame();
        $this->lands(0, 'Swamp', 2);
        $thopter = $this->put(0, 'Ornithopter', GameObject::BATTLEFIELD);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Bargainer Lite', GameObject::HAND));
        $this->resolve();
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, $hand + 2], [$game->objects[$thopter]->zone, count($game->players[0]->hand)]);
    }

    public function testAttackingTokensMayReturnAndAnotherCreatureYouControl(): void
    {
        $game = $this->newGame();
        foreach (['Raid Leader Lite', 'Scrap Finder Lite', 'Bounce Ally Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $leader = $this->put(0, 'Raid Leader Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$leader]);
        $this->resolve();
        $this->assertCount(2, $game->attackers, 'The Goblin token is attacking too.');

        $game = $this->newGame();
        $thopter = $this->put(0, 'Ornithopter', GameObject::GRAVEYARD);
        $this->assertSame(["o:{$thopter}"], $game->targetOptions(0, 'artifact_card_yours'));
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $faerie = $this->put(0, 'Bounce Ally Lite', GameObject::BATTLEFIELD);
        $this->assertSame(["o:{$bears->id}"], $game->targetOptions(0, "creature_yours_not_{$faerie}"));
    }

    public function testSweepByPowerDrainAndNoncreatureTargets(): void
    {
        $game = $this->newGame();
        foreach (['Sweeper Lite', 'Drain Lite', 'Noncreature Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $wall = $game->addCard(0, self::card('Wall of Stone'), GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 4);
        $game->cast(0, $this->put(0, 'Sweeper Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::GRAVEYARD, GameObject::GRAVEYARD], [$bears->zone, $giant->zone, $wall->zone]);

        $game = $this->newGame();
        $this->lands(0, 'Swamp', 3);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->cast(0, $this->put(0, 'Drain Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([$mine + 3, $theirs - 3], [$this->life(0), $this->life(1)]);
        $thopter = $this->put(1, 'Ornithopter', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->assertNotContains("o:{$bears->id}", $game->targetOptions(0, 'noncreature_permanent'));
        $this->assertNotContains("o:{$thopter}", $game->targetOptions(0, 'noncreature_permanent'));
    }

    public function testBlocksLeavesOpponentDiesDiscardAndCycleTriggers(): void
    {
        $game = $this->newGame();
        foreach (['Guard Lite', 'Farewell Lite', 'Vulture Lite', 'Madcap Lite', 'Cycler Lite', 'Cycle Fan Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $farewell = $this->put(0, 'Farewell Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Vulture Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $shock = $this->hand(0, 'Shock');
        [$hand, $theirs] = [count($game->players[0]->hand), $this->life(1)];
        $game->cast(0, $shock, 0, ["o:{$farewell}"]);
        for ($i = 0; $i < 3; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand, $theirs + 1], [count($game->players[0]->hand), $this->life(1)], 'Shock out, a card in; they gain 1.');

        $this->put(0, 'Madcap Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Cycle Fan Lite', GameObject::BATTLEFIELD);
        $cycler = $this->put(0, 'Cycler Lite', GameObject::HAND);
        $this->lands(0, 'Plains', 1);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->cycle(0, $cycler);
        while ($game->stack !== [] && $game->priority !== null) {
            $this->resolve();
        }
        $this->assertSame([$mine + 3, $theirs - 1], [$this->life(0), $this->life(1)]);

        $game = $this->newGame();
        $guard = $this->put(0, 'Guard Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 2);
        $game->declareAttackers(1, [$bears->id]);
        while ($game->decision(0) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(0, [$guard => $bears->id]);
        $this->resolve();
        $this->assertSame(2, $game->power($game->objects[$guard]));
    }

    public function testStaticsAsLongAsAnotherNotYourTurnAndFewCards(): void
    {
        $game = $this->newGame();
        foreach (['Elf Buddy Lite', 'Shy Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $buddy = $game->objects[$this->put(0, 'Elf Buddy Lite', GameObject::BATTLEFIELD)];
        $this->assertSame(1, $game->power($buddy), 'It does not count itself.');
        $game->addCard(0, self::card('Llanowar Elves'), GameObject::BATTLEFIELD);
        $this->assertSame(2, $game->power($buddy));

        $shy = $game->objects[$this->put(0, 'Shy Lite', GameObject::BATTLEFIELD)];
        $this->assertNotContains('hexproof', $game->keywords($shy), 'Its own turn.');
        $game->players[0]->hand = [];
        $this->assertSame(2, $game->power($shy));
        $this->passUntil(Step::Upkeep, 2);
        $this->assertContains('hexproof', $game->keywords($shy));
    }

    public function testTaxesAndOpponentsCreaturesEnteringTapped(): void
    {
        $game = $this->newGame();
        foreach (['Thalia Lite', 'Tax Lite', 'Frost Gate Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Thalia Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $bolt = $this->hand(0, 'Lightning Bolt');
        $this->assertFalse($game->canCast(0, $bolt), 'Thalia taxes its own controller too.');
        $this->lands(0, 'Mountain', 1);
        $this->assertTrue($game->canCast(0, $bolt));

        $game = $this->newGame();
        $this->put(0, 'Tax Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Frost Gate Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $this->assertTrue($game->canCast(0, $this->hand(0, 'Lightning Bolt')), 'Not on its controller.');
        $this->passUntil(Step::PrecombatMain, 2);
        $this->lands(1, 'Forest', 2);
        $bears = $this->hand(1, 'Grizzly Bears');
        $this->assertFalse($game->canCast(1, $bears));
        $this->lands(1, 'Forest', 1);
        $game->cast(1, $bears);
        $this->resolve();
        $this->assertSame([GameObject::BATTLEFIELD, true], [$game->objects[$bears]->zone, $game->objects[$bears]->tapped]);
    }

    public function testTriggersOnOpponentsUpkeepDrawsAndLands(): void
    {
        $game = $this->newGame();
        foreach (['Sulfuric Lite', 'Painful Lite', 'Spiteful Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Sulfuric Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Painful Lite', GameObject::BATTLEFIELD);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame([$mine, $theirs - 2], [$this->life(0), $this->life(1)], 'One for the upkeep, one for the draw.');

        $game = $this->newGame();
        $this->put(0, 'Spiteful Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 2);
        $this->hand(1, 'Shock');
        $hand = count($game->players[1]->hand);
        $forest = array_values(array_filter($game->players[1]->hand, fn (int $id) => $game->objects[$id]->name() === 'Forest'))[0];
        $game->playLand(1, $forest);
        $this->resolve();
        $this->assertSame($hand - 2, count($game->players[1]->hand), 'The land, then a discard.');
    }

    public function testForEachCountsAsItResolves(): void
    {
        $game = $this->newGame();
        foreach (['Harvest Lite', 'Spore Lite', 'Ghostly Lite', 'Tally Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Forest', 3);
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $harvest = $this->put(0, 'Harvest Lite', GameObject::HAND);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $harvest);
        $this->resolve();
        $this->assertSame($hand - 1 + 2, count($game->players[0]->hand), 'Two creatures, two cards.');

        $saprolings = fn () => count(array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Saproling Token'));
        $game->cast(0, $this->put(0, 'Spore Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(3, $saprolings(), 'One for each of three lands.');

        $game = $this->newGame();
        $this->lands(0, 'Plains', 1);
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        $game->addCard(0, self::card('Hill Giant'), GameObject::GRAVEYARD);
        $game->addCard(0, self::card('Shock'), GameObject::GRAVEYARD);
        $game->cast(0, $this->put(0, 'Ghostly Lite', GameObject::HAND));
        $this->resolve();
        $this->assertCount(2, array_filter($game->permanents(0), fn (GameObject $o) => $o->name() === 'Spirit Token'), 'Creature cards only.');

        $game = $this->newGame();
        $this->lands(0, 'Swamp', 1);
        $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $tally = $this->put(0, 'Tally Lite', GameObject::HAND);
        [$mine, $theirs, $hand] = [$this->life(0), $this->life(1), count($game->players[0]->hand)];
        $game->cast(0, $tally);
        $this->resolve();
        $this->assertSame([$mine + 2 * ($hand - 1), $theirs - 1], [$this->life(0), $this->life(1)]);
    }

    public function testEdictTakesTheGreatestPower(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Pale Edict Lite')->unsupported);
        $this->lands(0, 'Swamp', 1);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $giant = $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Pale Edict Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([GameObject::BATTLEFIELD, GameObject::GRAVEYARD], [$bears->zone, $giant->zone]);
    }

    public function testReturnACardMilledByTheSameSpell(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Salvage Mill Lite')->unsupported);
        $this->lands(0, 'Forest', 1);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD);
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::LIBRARY);
        $this->assertSame($giant->id, end($game->players[0]->library), 'On top.');
        $game->cast(0, $this->put(0, 'Salvage Mill Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::HAND], [$bears->zone, $giant->zone], 'The milled Hill Giant has the higher mana value.');
    }

    public function testXPumpsCountedAndAttackingCreatures(): void
    {
        $game = $this->newGame();
        foreach (['Swamp Shrink Lite', 'Mob Rider Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->lands(0, 'Swamp', 2);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Swamp Shrink Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $bears->zone, '-2/-2 from two Swamps.');

        $game = $this->newGame();
        $rider = $this->put(0, 'Mob Rider Lite', GameObject::BATTLEFIELD);
        $others = [$game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id, $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id];
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$rider, ...$others]);
        $this->resolve();
        $this->assertSame(3, $game->power($game->objects[$rider]), 'Two other attackers.');
    }

    public function testInterveningIfCountsCreaturesAndCardsInHand(): void
    {
        $game = $this->newGame();
        foreach (['Crowd Muse Lite', 'Empty Hand Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Crowd Muse Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Empty Hand Lite', GameObject::BATTLEFIELD);
        $this->hand(0, 'Forest');
        $this->hand(0, 'Forest');
        $this->battlefield(0, 'Grizzly Bears');
        $this->battlefield(0, 'Grizzly Bears');
        $hand = count($game->players[0]->hand);
        $this->passUntil(Step::Upkeep, 2);
        $this->assertSame($hand, count($game->players[0]->hand), 'Two creatures: no draw.');

        $this->battlefield(0, 'Grizzly Bears');
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Upkeep with cards in hand: only the draw step.');
        $this->passUntil(Step::Upkeep, 4);
        $this->assertSame($hand + 2, count($game->players[0]->hand), 'Three creatures: drew at the end step.');

        foreach ($game->players[0]->hand as $id) {
            $game->objects[$id]->moveTo(GameObject::GRAVEYARD);
            $game->players[0]->graveyard[] = $id;
        }
        $game->players[0]->hand = [];
        $this->passUntil(Step::PrecombatMain, 5);
        $this->assertCount(2, $game->players[0]->hand, 'Empty hand at upkeep: drew, then the draw step.');
    }

    public function testOptionalDrawThenDiscard(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Sea Loot Lite')->unsupported);
        $this->lands(0, 'Island', 2);
        $kept = $this->hand(0, 'Forest');
        $game->cast(0, $this->put(0, 'Sea Loot Lite', GameObject::HAND), 0);
        $this->resolve();
        $this->resolve();
        $this->assertSame('discard', $game->decision(0), 'Drew, then discards.');
        $this->assertCount(2, $game->players[0]->hand);
        $game->discard(0, [$kept]);
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($kept));
    }

    public function testEntersTriggersFilteredByPower(): void
    {
        $game = $this->newGame();
        foreach (['Fierce Eye Lite', 'Fierce Beast Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Fierce Eye Lite', GameObject::BATTLEFIELD);
        $hand = count($game->players[0]->hand);
        $this->lands(0, 'Mountain', 4);
        $game->cast(0, $this->hand(0, 'Hill Giant'), 0);
        $this->resolve();
        $this->assertSame($hand, count($game->players[0]->hand), 'Power 3: no draw.');
        $this->assertSame([], $game->stack);

        $life = $this->life(0);
        $this->passUntil(Step::PrecombatMain, 3);
        $hand = count($game->players[0]->hand);
        $this->lands(0, 'Forest', 4);
        $game->cast(0, $this->put(0, 'Fierce Beast Lite', GameObject::HAND), 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Power 4: drew.');
        $this->assertSame($life + 2, $this->life(0), 'It saw itself enter.');
    }

    public function testSacrificeTriggers(): void
    {
        $game = $this->newGame();
        foreach (['Altar Hymn Lite', 'Grim Taker Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Altar Hymn Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Altar Hymn Lite', GameObject::BATTLEFIELD);
        $husk = $this->put(0, 'Husk Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->activate(0, $husk, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($mine + 1, $this->life(0), "Gained 1 for the sacrifice; their own Altar Hymn didn't trigger.");
        $this->assertSame($theirs, $this->life(1));

        // Grim Taker: another creature sacrificed, but not itself.
        $taker = $this->put(0, 'Grim Taker Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $game->activate(0, $husk, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$mine + 2, $theirs - 1], [$this->life(0), $this->life(1)]);
        $game->activate(0, $husk, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($taker));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$mine + 3, $theirs - 1], [$this->life(0), $this->life(1)]);
    }

    public function testSacrificeTriggersForAnyPlayer(): void
    {
        $game = $this->newGame();
        foreach (['Gloat Lite', 'Mayhem Imp Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Gloat Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Mayhem Imp Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $this->lands(0, 'Swamp', 1);
        $game->cast(0, $this->put(0, 'Pale Edict Lite', GameObject::HAND));
        for ($i = 0; $i < 5 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        $this->assertSame([$mine + 1, $theirs - 1], [$this->life(0), $this->life(1)], 'An opponent sacrificed a creature.');

        // Your own sacrifice: the Imp, not Gloat.
        $husk = $this->put(0, 'Husk Lite', GameObject::BATTLEFIELD);
        $this->battlefield(0, 'Grizzly Bears');
        $game->activate(0, $husk, 0);
        for ($i = 0; $i < 5 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$mine + 1, $theirs - 2], [$this->life(0), $this->life(1)]);
    }

    public function testNontokenCreatureDies(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Grave Tally Lite')->unsupported);
        $this->put(0, 'Grave Tally Lite', GameObject::BATTLEFIELD);
        $husk = $this->put(0, 'Husk Lite', GameObject::BATTLEFIELD);
        $game->addCard(0, ['token' => true] + self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $hand = count($game->players[0]->hand);
        $game->activate(0, $husk, 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($hand, count($game->players[0]->hand), 'A token died: no draw.');
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $game->activate(0, $husk, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'A nontoken creature died.');

        // Its own death, with as many +1/+1 counters as some token's id: still nontoken.
        $token = $game->addCard(1, ['token' => true] + self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $hound = $this->put(0, 'Tally Hound Lite', GameObject::BATTLEFIELD);
        $game->objects[$hound]->addCounters('+1/+1', $token);
        $game->activate(0, $husk, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($hound));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($hand + 3, count($game->players[0]->hand), 'Grave Tally and the Hound itself.');
    }

    public function testArtifactPutIntoAGraveyard(): void
    {
        $game = $this->newGame();
        foreach (['Scrap Bell Lite', 'Scrap Shard Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $bell = $this->put(0, 'Scrap Bell Lite', GameObject::BATTLEFIELD);
        $shard = $this->put(0, 'Scrap Shard Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Scrap Shard Lite', GameObject::BATTLEFIELD);
        [$mine, $theirs] = [$this->life(0), $this->life(1)];
        $game->activate(0, $shard, 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$mine + 1, $theirs - 1], [$this->life(0), $this->life(1)]);
        $game->activate(0, $bell, 0);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bell));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$mine + 2, $theirs - 2], [$this->life(0), $this->life(1)], 'It saw itself go.');
    }

    public function testOpponentSpellKindsAndDefendingPlayer(): void
    {
        $game = $this->newGame();
        foreach (['Spite Eye Lite', 'Raid Hound Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Spite Eye Lite', GameObject::BATTLEFIELD);
        $hound = $this->put(0, 'Raid Hound Lite', GameObject::BATTLEFIELD);
        $game->objects[$hound]->sick = false;
        $this->lands(1, 'Forest', 2);
        $this->lands(1, 'Mountain', 1);
        $theirs = $this->life(1);
        $this->passUntil(Step::PrecombatMain, 2);
        $game->cast(1, $this->hand(1, 'Grizzly Bears'), 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($theirs, $this->life(1), 'A creature spell: no damage.');
        $game->cast(1, $this->hand(1, 'Shock'), 0, ['p:0']);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($theirs - 1, $this->life(1), 'A noncreature spell: 1 damage to its caster.');

        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$hound]);
        $this->resolve();
        $this->assertSame($theirs - 2, $this->life(1), 'Attacking: the defending player loses 1 life.');
    }

    public function testDiscardOneOrMoreCardsTriggersOnce(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Ember Cache Lite')->unsupported);
        $this->put(0, 'Ember Cache Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 1);
        $theirs = $this->life(1);
        $this->assertSame([], $game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Tidy Wits Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame([], $game->players[0]->hand, 'Drew two, then discarded both.');
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($theirs - 1, $this->life(1), 'Two cards discarded at once: one trigger.');
    }

    public function testDiesTriggersMoveTheCardItBecame(): void
    {
        $game = $this->newGame();
        foreach (['Return Pup Lite', 'Back Again Lite', 'Sink Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $husk = $this->put(0, 'Husk Lite', GameObject::BATTLEFIELD);
        $sac = function () use ($game, $husk): void {
            $game->activate(0, $husk, 0);
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
        };
        $pup = $this->put(0, 'Return Pup Lite', GameObject::BATTLEFIELD);
        $sac();
        $this->assertSame(GameObject::HAND, $this->zone($pup), 'Back to its hand from the graveyard.');

        $back = $this->put(0, 'Back Again Lite', GameObject::BATTLEFIELD);
        $incarnation = $game->objects[$back]->incarnation;
        $sac();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($back));
        $this->assertTrue($game->objects[$back]->tapped, 'Returned tapped.');
        $this->assertNotSame($incarnation, $game->objects[$back]->incarnation, 'A new object.');

        $sink = $this->put(0, 'Sink Lite', GameObject::BATTLEFIELD);
        $game->objects[$back]->moveTo(GameObject::EXILE);
        $game->battlefield = array_values(array_diff($game->battlefield, [$back]));
        $game->exile[] = $back;
        $sac();
        $this->assertSame(GameObject::LIBRARY, $this->zone($sink));
        $this->assertSame($sink, $game->players[0]->library[0], 'On the bottom; draws come off the end.');
    }

    public function testTargetedByAnOpponent(): void
    {
        $game = $this->newGame();
        foreach (['Watchful Eye Lite', 'Wary Elk Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Watchful Eye Lite', GameObject::BATTLEFIELD);
        $elk = $this->put(0, 'Wary Elk Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Mountain', 1);
        [$hand, $life] = [count($game->players[0]->hand), $this->life(0)];
        $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$elk}"]);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand, $life], [count($game->players[0]->hand), $this->life(0)], 'Its own controller targeting it: nothing.');

        $this->passUntil(Step::PrecombatMain, 2);
        $hand = count($game->players[0]->hand);
        $game->cast(1, $this->hand(1, 'Shock'), 0, ["o:{$elk}"]);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand + 1, $life + 2], [count($game->players[0]->hand), $this->life(0)], 'An opponent targeting it: a card and 2 life.');
    }

    public function testAttacksOrBlocksAndPlayerOrPlaneswalkerDamage(): void
    {
        $game = $this->newGame();
        foreach (['Brawny Lite', 'Scout Pilot Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $brawny = $this->put(0, 'Brawny Lite', GameObject::BATTLEFIELD);
        $pilot = $this->put(0, 'Scout Pilot Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 3);
        [$hand, $life] = [count($game->players[0]->hand), $this->life(0)];
        $game->declareAttackers(0, [$brawny, $pilot]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, []);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame($life + 1, $this->life(0), 'It attacked.');
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'Combat damage to a player draws.');
        $this->assertSame(17, $this->life(1));

        $this->passUntil(Step::DeclareAttackers, 4);
        $game->objects[$brawny]->tapped = false; // It attacked last turn and untaps on its own turn.
        $game->declareAttackers(1, [$bears]);
        while ($game->decision(0) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(0, [$brawny => $bears]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame($life + 2, $this->life(0), 'It blocked.');
    }

    public function testAnyPlayersCreatureEnters(): void
    {
        $game = $this->newGame();
        foreach (['Life Warden Lite', 'Grudge Eye Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Life Warden Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Grudge Eye Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 2);
        $this->lands(1, 'Forest', 2);
        $game->cast(0, $this->hand(0, 'Grizzly Bears'));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([21, 20], [$this->life(0), $this->life(1)], 'Its own creature: life, no damage.');

        $this->passUntil(Step::PrecombatMain, 2);
        $game->cast(1, $this->hand(1, 'Grizzly Bears'));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([22, 19], [$this->life(0), $this->life(1)], "An opponent's creature: both.");
    }

    public function testAttacksAlone(): void
    {
        $game = $this->newGame();
        foreach (['Lone Raider Lite', 'Lone Banner Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $raider = $this->put(0, 'Lone Raider Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Lone Banner Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        foreach ([3 => [$raider], 5 => [$raider, $bears]] as $turn => $attackers) {
            $this->passUntil(Step::DeclareAttackers, $turn);
            $game->declareAttackers(0, $attackers);
            $this->passUntil(Step::PostcombatMain);
        }
        $this->assertSame(20 - 4 - 2 - 2, $this->life(1), 'Alone: +2/+2; with company: 2/2.');
        $this->assertSame(24, $this->life(0), 'Lifelink only while attacking alone.');
    }

    public function testCastManaValueAndSubtypeTriggers(): void
    {
        $game = $this->newGame();
        foreach (['Hoard Eye Lite', 'Dragon Lover Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Hoard Eye Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Dragon Lover Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Forest', 5);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Firebreather Lite', GameObject::HAND));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand, 22], [count($game->players[0]->hand), $this->life(0)], 'A Dragon with mana value 2: life, no card.');
        $game->cast(0, $this->put(0, 'Hoard Wurm Lite', GameObject::HAND));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand + 1, 22], [count($game->players[0]->hand), $this->life(0)], 'A Wurm with mana value 5: a card, no life.');
    }

    public function testDealsDamageToAPlayer(): void
    {
        $game = $this->newGame();
        foreach (['Spite Hound Lite', 'Spite Pinger Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $hound = $this->put(0, 'Spite Hound Lite', GameObject::BATTLEFIELD);
        $pinger = $this->put(0, 'Spite Pinger Lite', GameObject::BATTLEFIELD);
        $this->hand(1, 'Shock');
        $this->passUntil(Step::PrecombatMain, 3);
        $game->activate(0, $pinger, 0, ['p:0']);
        $this->resolve();
        $this->assertSame(19, $this->life(0), 'Damage to its own controller: no life.');
        $game->objects[$pinger]->tapped = false;
        $game->activate(0, $pinger, 0, ['p:1']);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([21, 19], [$this->life(0), $this->life(1)], 'Noncombat damage to an opponent.');

        $hand = count($game->players[1]->hand);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$hound]);
        while ($game->step !== Step::PostcombatMain) {
            $game->decision(1) === 'discard' ? $game->discard(1, [$game->players[1]->hand[0]]) : $game->pass($game->priority);
        }
        $this->assertSame($hand - 1, count($game->players[1]->hand), 'That player discards.');
    }

    public function testEachPlayersUpkeep(): void
    {
        $game = $this->newGame();
        foreach (['Howling Lite', 'Torment Altar Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Howling Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Torment Altar Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame([20, 19], [$this->life(0), $this->life(1)], "The opponent's upkeep: that player.");
        $this->assertSame([0, 2], [count($game->players[0]->hand), count($game->players[1]->hand)]);
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame([19, 19], [$this->life(0), $this->life(1)], 'Its controller too, on its own upkeep.');
        $this->assertSame([2, 2], [count($game->players[0]->hand), count($game->players[1]->hand)]);
    }

    public function testAnyCreatureDies(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Carrion Feeder Lite')->unsupported);
        $feeder = $this->put(0, 'Carrion Feeder Lite', GameObject::BATTLEFIELD);
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 3);
        foreach ([$mine, $theirs, $feeder] as $i => $victim) {
            $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$victim}"]);
            for ($j = 0; $j < 4 && $game->stack !== []; $j++) {
                $this->resolve();
            }
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($victim));
            $this->assertSame(21 + $i, $this->life(0), ['Its own controller\'s creature', "An opponent's creature", 'Itself'][$i]);
        }
    }

    public function testAnyPlayerCasts(): void
    {
        $game = $this->newGame();
        foreach (['Busy Square Lite', 'Spell Watcher Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Busy Square Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Spell Watcher Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Forest', 2);
        $game->cast(0, $this->hand(0, 'Shock'), 0, ['p:1']);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(22, $this->life(0), 'Its controller casting an instant: both.');

        $this->passUntil(Step::PrecombatMain, 2);
        $game->cast(1, $this->hand(1, 'Grizzly Bears'));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(23, $this->life(0), 'An opponent casting a creature: only any spell.');
    }

    public function testOpponentLosesLife(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Bloodbond Bat Lite')->unsupported);
        $bat = $this->put(0, 'Bloodbond Bat Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        foreach (['p:0' => 0, 'p:1' => 1] as $target => $counters) {
            $game->cast(0, $this->hand(0, 'Shock'), 0, [$target]);
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
            $this->assertSame($counters, $game->objects[$bat]->counter('+1/+1'), $target);
        }
    }

    public function testPermanentKindEnters(): void
    {
        $game = $this->newGame();
        foreach (['Tinker Eye Lite', 'Scrap Collector Lite', 'Land Count Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Tinker Eye Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Land Count Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 2);
        $game->cast(0, $this->put(0, 'Scrap Collector Lite', GameObject::HAND));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([21, 1], [$this->life(0), count($game->players[0]->hand)], 'An artifact entering: life, and a card from itself.');
        $game->playLand(0, $this->hand(0, 'Plains'));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(21, $this->life(1), "Any player's land.");
    }

    public function testYouLoseLifeAndOpponentGainsLife(): void
    {
        $game = $this->newGame();
        foreach (['Pain Eater Lite', 'Life Spite Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $eater = $this->put(0, 'Pain Eater Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Life Warden Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Life Spite Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        $this->lands(0, 'Forest', 2);
        foreach (['p:1' => 0, 'p:0' => 1] as $target => $counters) {
            $game->cast(0, $this->hand(0, 'Shock'), 0, [$target]);
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
            $this->assertSame($counters, $game->objects[$eater]->counter('+1/+1'), $target);
        }
        $hand = count($game->players[1]->hand);
        $game->cast(0, $this->hand(0, 'Grizzly Bears'));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(19, $this->life(0));
        $this->assertSame($hand + 1, count($game->players[1]->hand), 'Its opponent gained life.');
    }

    public function testScryAndAnyPlayerDraws(): void
    {
        $game = $this->newGame();
        foreach (['Seer Eye Lite', 'Spiral Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Seer Eye Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Spiral Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 1);
        $game->cast(0, $this->put(0, 'Opt Lite', GameObject::HAND));
        for ($i = 0; $i < 6 && ($game->stack !== [] || $game->choiceAwaiting() !== null); $i++) {
            $game->choiceAwaiting() !== null ? $game->arrange(0, []) : $this->resolve();
        }
        $this->assertSame(20, $this->life(0), 'Scry: 1 life; the draw: 1 life lost.');
        $this->assertSame(1, count($game->players[0]->hand));
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame(19, $this->life(1), 'Its controller drawing too.');
    }

    public function testCreateTokenTriggers(): void
    {
        $game = $this->newGame();
        foreach (['Token Fan Lite', 'Map Fan Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Token Fan Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Map Fan Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $game->cast(0, $this->put(0, 'Mapper Lite', GameObject::HAND));
        for ($i = 0; $i < 6 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(20 + 1 + 2 + 2, $this->life(0), 'Two Maps: once for the batch, twice for each Map.');
    }

    public function testOpponentEndStepEachCombatAndDrawStep(): void
    {
        $game = $this->newGame();
        foreach (['Night Toll Lite', 'Battle Hymn Lite', 'Dawn Draw Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
            $this->put(0, $name, GameObject::BATTLEFIELD);
        }
        $this->passUntil(Step::PostcombatMain, 1);
        $this->assertSame([21, 20], [$this->life(0), $this->life(1)], 'Its own combat.');
        $this->passUntil(Step::PrecombatMain, 2);
        $this->assertSame([21, 20], [$this->life(0), $this->life(1)], 'Not its own end step, nor the opponent\'s draw step.');
        $this->passUntil(Step::PrecombatMain, 3);
        $this->assertSame([23, 19], [$this->life(0), $this->life(1)], "The opponent's combat and end step, then its own draw step.");
    }

    public function testAttacksYouBlocksAndBatchCombatDamage(): void
    {
        $game = $this->newGame();
        foreach (['Watch Post Lite', 'Shieldmate Lite', 'Raid Captain Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Raid Captain Lite', GameObject::BATTLEFIELD);
        $first = $this->battlefield(0, 'Grizzly Bears');
        $second = $this->battlefield(0, 'Grizzly Bears');
        $this->put(1, 'Watch Post Lite', GameObject::BATTLEFIELD);
        $this->put(1, 'Shieldmate Lite', GameObject::BATTLEFIELD);
        $guard = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 3);
        $hand = count($game->players[0]->hand);
        $game->declareAttackers(0, [$first, $second]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, [$guard => $first]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(20 + 2 - 2, $this->life(1), 'A life for each attacker, then one unblocked hit.');
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($guard), 'Its blocker got +0/+2.');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($first));
        $this->assertSame($hand + 1, count($game->players[0]->hand), 'One card for the combat damage.');
    }

    public function testLeavesTheBattlefieldAndPlayALand(): void
    {
        $game = $this->newGame();
        foreach (['Exodus Eye Lite', 'Land Joy Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
            $this->put(0, $name, GameObject::BATTLEFIELD);
        }
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 4);
        foreach ([$theirs, $bears] as $victim) {
            $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$victim}"]);
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
        }
        $this->assertSame(21, $this->life(0), 'Only its controller\'s creature leaving.');
        $game->playLand(0, $this->hand(0, 'Plains'));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(23, $this->life(0), 'A land played.');
    }

    public function testCommitACrime(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Outlaw Lite')->unsupported);
        $outlaw = $this->put(0, 'Outlaw Lite', GameObject::BATTLEFIELD);
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 3);
        foreach (["o:{$mine}" => 0, 'p:1' => 1, "o:{$theirs}" => 2] as $target => $counters) {
            $game->cast(0, $this->hand(0, 'Shock'), 0, [$target]);
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
            $this->assertSame($counters, $game->objects[$outlaw]->counter('+1/+1'), $target);
        }
    }

    public function testExpend(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Expend Rabbit Lite')->unsupported);
        $rabbit = $this->put(0, 'Expend Rabbit Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 6);
        foreach ([0, 1, 1] as $counters) {
            $game->cast(0, $this->hand(0, 'Grizzly Bears'));
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
            $this->assertSame($counters, $game->objects[$rabbit]->counter('+1/+1'), 'The fourth mana this turn, once.');
        }
        $this->passUntil(Step::PrecombatMain, 3);
        foreach ([1, 2] as $counters) {
            $game->cast(0, $this->hand(0, 'Grizzly Bears'));
            for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
                $this->resolve();
            }
            $this->assertSame($counters, $game->objects[$rabbit]->counter('+1/+1'), 'A new turn starts over.');
        }
    }

    public function testActivateAbilityTriggers(): void
    {
        $game = $this->newGame();
        foreach (['Engine Fan Lite', 'Busy Hands Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
            $this->put(0, $name, GameObject::BATTLEFIELD);
        }
        $exhaust = $this->put(0, 'Exhaust Lite', GameObject::BATTLEFIELD);
        $pinger = $this->put(0, 'Spite Pinger Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 1);
        $this->passUntil(Step::PrecombatMain, 3);
        $game->activate(0, $exhaust, 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(23, $this->life(0), 'An exhaust ability: both.');
        $game->activate(0, $pinger, 0, ['p:1']);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(24 + 2, $this->life(0), 'Another ability: one, then the pinger\'s own life gain.');
    }

    public function testCreatureYouControlBecomesTapped(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Tap Tithe Lite')->unsupported);
        $this->put(0, 'Tap Tithe Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $this->battlefield(1, 'Grizzly Bears');
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->hand(0, 'Shock'), 0, ['p:1']);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(20, $this->life(0), 'A land tapped for mana: nothing.');
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$bears]);
        while ($game->decision(1) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(1, []);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(21, $this->life(0), 'A creature tapped to attack.');
    }

    public function testExploresAndTurnedFaceUp(): void
    {
        $game = $this->newGame();
        foreach (['Unmask Fan Lite', 'Explore Fan Lite', 'Masked Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Unmask Fan Lite', GameObject::BATTLEFIELD);
        $this->put(0, 'Explore Fan Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 7);
        $game->cast(0, $this->put(0, 'Merfolk Branchwalker', GameObject::HAND));
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(21, $this->life(0), 'It explored.');
        $masked = $this->put(0, 'Masked Lite', GameObject::HAND);
        $game->cast(0, $masked, 0, [], 'morph');
        $this->resolve();
        $game->activate(0, $masked, 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertFalse($game->objects[$masked]->faceDown);
        $this->assertSame(23, $this->life(0), 'Turned face up.');
    }

    public function testMillThenPutOneFromAmongThem(): void
    {
        $game = $this->newGame();
        foreach (['Dig Up Lite', 'Dig Beast Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::GRAVEYARD)->id;
        $plains = $game->addCard(0, self::card('Plains'), GameObject::GRAVEYARD)->id;
        $this->lands(0, 'Forest', 4);
        $game->cast(0, $this->put(0, 'Dig Beast Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($bears), 'Only the milled cards: no creature among them.');
        $this->assertSame([], $game->players[0]->hand);
        $game->cast(0, $this->put(0, 'Dig Up Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($plains));
        $this->assertSame(['Forest'], array_map(fn (int $id) => $game->objects[$id]->name(), $game->players[0]->hand), 'One of the milled Forests.');
    }

    public function testForageAndGraveyardExileCosts(): void
    {
        $game = $this->newGame();
        foreach (['Forager Lite', 'Food Maker Lite', 'Grave Scholar Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $forager = $this->put(0, 'Forager Lite', GameObject::BATTLEFIELD);
        $scholar = $this->put(0, 'Grave Scholar Lite', GameObject::BATTLEFIELD);
        foreach (range(1, 3) as $i) {
            $game->addCard(0, self::card('Plains'), GameObject::GRAVEYARD);
        }
        $this->lands(0, 'Forest', 2);
        $this->passUntil(Step::PrecombatMain, 3);
        $game->activate(0, $forager, 0);
        $this->resolve();
        $this->assertSame([1, []], [$game->objects[$forager]->counter('+1/+1'), $game->players[0]->graveyard], 'Three cards from the graveyard.');

        $game->objects[$forager]->tapped = false;
        $this->assertFalse($game->canActivate(0, $forager, 0), 'Nothing to forage with.');
        $game->cast(0, $this->put(0, 'Food Maker Lite', GameObject::HAND));
        $this->resolve();
        $game->activate(0, $forager, 0);
        $this->resolve();
        $this->assertSame(2, $game->objects[$forager]->counter('+1/+1'), 'A Food instead.');
        $this->assertSame([], array_filter($this->game->permanents(0), fn (GameObject $o) => $o->name() === 'Food'));

        $this->assertFalse($game->canActivate(0, $scholar, 0));
        $game->addCard(0, self::card('Plains'), GameObject::GRAVEYARD);
        $game->addCard(0, self::card('Plains'), GameObject::GRAVEYARD);
        $hand = count($game->players[0]->hand);
        $game->activate(0, $scholar, 0);
        $this->resolve();
        $this->assertSame([$hand + 1, 0], [count($game->players[0]->hand), count(array_filter($game->players[0]->graveyard, fn (int $id) => $game->objects[$id]->name() === 'Plains'))]);
    }

    public function testTapOthersSacrificeSeveralMillAndCardKindCosts(): void
    {
        $game = $this->newGame();
        foreach (['Taskmaster Lite', 'Grave Eater Lite', 'Pyre Lite', 'Mill Seer Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $master = $this->put(0, 'Taskmaster Lite', GameObject::BATTLEFIELD);
        $eater = $this->put(0, 'Grave Eater Lite', GameObject::BATTLEFIELD);
        $pyre = $this->put(0, 'Pyre Lite', GameObject::BATTLEFIELD);
        $seer = $this->put(0, 'Mill Seer Lite', GameObject::BATTLEFIELD);
        $first = $this->battlefield(0, 'Grizzly Bears');
        $second = $this->battlefield(0, 'Grizzly Bears');
        $this->passUntil(Step::PrecombatMain, 3);

        $this->assertFalse($game->canActivate(0, $eater, 0), 'No creature card in the graveyard.');
        $game->activate(0, $seer, 0);
        $this->resolve();
        $this->assertSame([21, 1], [$this->life(0), count($game->players[0]->graveyard)], 'It milled a card as its cost.');
        $this->assertFalse($game->canActivate(0, $eater, 0), 'A Forest is not a creature card.');

        $hand = count($game->players[0]->hand);
        $game->activate(0, $master, 0);
        $this->resolve();
        $this->assertFalse($game->objects[$master]->tapped, 'Never itself.');
        $this->assertSame($hand + 1, count($game->players[0]->hand));
        $this->assertFalse($game->canActivate(0, $master, 0), 'Two untapped creatures are needed.');

        $game->activate(0, $pyre, 0);
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, GameObject::GRAVEYARD], [$this->zone($first), $this->zone($second)], 'The two weakest creatures.');
        $this->assertSame($hand + 3, count($game->players[0]->hand));

        $game->activate(0, $eater, 0);
        $this->resolve();
        $this->assertSame(23, $this->life(0), 'A creature card exiled from the graveyard.');
        $this->assertSame(GameObject::EXILE, $this->zone($first));
    }

    public function testReturnALandRemoveACounterAndReturnItselfCosts(): void
    {
        $game = $this->newGame();
        foreach (['Bounce Seer Lite', 'Counter Sage Lite', 'Retreat Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $seer = $this->put(0, 'Bounce Seer Lite', GameObject::BATTLEFIELD);
        $sage = $this->put(0, 'Counter Sage Lite', GameObject::BATTLEFIELD);
        $retreat = $this->put(0, 'Retreat Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        [$tapped, $untapped] = $this->lands(0, 'Island', 2);
        $this->passUntil(Step::PrecombatMain, 3);
        $game->objects[$tapped]->tapped = true;

        $hand = count($game->players[0]->hand);
        $game->activate(0, $seer, 0);
        $this->assertSame([GameObject::HAND, GameObject::BATTLEFIELD], [$this->zone($tapped), $this->zone($untapped)], 'The tapped land first.');
        $this->resolve();
        $this->assertSame($hand + 2, count($game->players[0]->hand));

        $this->assertFalse($game->canActivate(0, $sage, 0), 'No +1/+1 counter to remove.');
        $game->objects[$bears]->addCounters('+1/+1', 2);
        $game->activate(0, $sage, 0);
        $this->resolve();
        $this->assertSame([22, 1], [$this->life(0), $game->objects[$bears]->counter('+1/+1')]);

        $game->activate(0, $retreat, 0);
        $this->assertSame(GameObject::HAND, $this->zone($retreat), 'Returned as its cost.');
        $this->resolve();
        $this->assertSame($hand + 4, count($game->players[0]->hand));
    }

    public function testACreatureTokenYouControlDies(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Token Mourner Lite')->unsupported);
        $this->put(0, 'Token Mourner Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(0, 'Grizzly Bears');
        $token = $game->addCard(0, ['token' => true] + self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Mountain', 2);
        $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertSame([GameObject::GRAVEYARD, 20], [$this->zone($bears), $this->life(0)], 'A nontoken creature: no life.');
        $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$token}"]);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(22, $this->life(0));
    }

    public function testACreatureDealsCombatDamageToYou(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Pain Ledger Lite')->unsupported);
        $this->put(0, 'Pain Ledger Lite', GameObject::BATTLEFIELD);
        $first = $this->battlefield(1, 'Grizzly Bears');
        $second = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 2);
        $game->declareAttackers(1, [$first, $second]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame([18, 20], [$this->life(0), $this->life(1)], 'Once for each creature that hit you.');
    }

    public function testBecomesTheTargetOfAnyOrYourSpellOrAbility(): void
    {
        $game = $this->newGame();
        foreach (['Ward Watch Lite', 'Self Focus Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $this->put(0, 'Ward Watch Lite', GameObject::BATTLEFIELD);
        $focus = $this->put(0, 'Self Focus Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $this->lands(1, 'Mountain', 1);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $this->hand(0, 'Shock'), 0, ["o:{$focus}"]);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand + 1, 21], [count($game->players[0]->hand), $this->life(0)], 'Your own spell: a card and 1 life.');

        $this->passUntil(Step::PrecombatMain, 2);
        $hand = count($game->players[0]->hand);
        $game->cast(1, $this->hand(1, 'Shock'), 0, ["o:{$focus}"]);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame([$hand, 22], [count($game->players[0]->hand), $this->life(0)], "An opponent's spell: only the life.");
    }

    public function testBlocksACreatureThatCreatureAndLandOrTreasureToGraveyard(): void
    {
        $game = $this->newGame();
        foreach (['Thorn Guard Lite', 'Land Mourner Lite', 'Land Burner Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        $guard = $this->put(0, 'Thorn Guard Lite', GameObject::BATTLEFIELD);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $this->passUntil(Step::DeclareAttackers, 2);
        $game->declareAttackers(1, [$bears]);
        while ($game->decision(0) !== 'block') {
            $game->pass($game->priority);
        }
        $game->declareBlockers(0, [$guard => $bears]);
        $this->passUntil(Step::PostcombatMain);
        $this->assertSame(1, $game->objects[$guard]->damage, 'The blocked creature got -1/-0.');
        $this->assertSame(2, (int) $game->power($game->objects[$guard]), 'Not the blocker.');

        $this->passUntil(Step::PrecombatMain, 3);
        $this->put(0, 'Land Mourner Lite', GameObject::BATTLEFIELD);
        $burner = $this->put(0, 'Land Burner Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 1);
        $game->cast(0, $this->put(0, 'Strike It Rich', GameObject::HAND), 0);
        $this->resolve();
        $life = $this->life(0);
        $game->activate(0, $burner, 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($life + 2, $this->life(0), 'A land went to the graveyard.');
        $game->activate(0, $burner, 1);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame($life + 3, $this->life(0), 'A Treasure went to the graveyard.');
    }

    public function testYouCastACreatureSpellWithPowerOrGreater(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Big Caller Lite')->unsupported);
        $this->put(0, 'Big Caller Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Forest', 3);
        $game->cast(0, $this->hand(0, 'Grizzly Bears'), 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(20, $this->life(0), 'Power 2: nothing.');
        $game->cast(0, $this->put(0, 'Big Beast Lite', GameObject::HAND), 0);
        for ($i = 0; $i < 4 && $game->stack !== []; $i++) {
            $this->resolve();
        }
        $this->assertSame(22, $this->life(0));
    }

    public function testPumpByTheNumberOfCreaturesYouControl(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Swarm Pump Lite')->unsupported);
        $this->lands(0, 'Forest', 1);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->addCard(1, self::card('Hill Giant'), GameObject::BATTLEFIELD);
        $game->cast(0, $this->put(0, 'Swarm Pump Lite', GameObject::HAND), 0, ["o:{$bears->id}"]);
        $this->resolve();
        $this->assertSame([4, 4], [$game->power($bears), $game->toughness($bears)]);
    }

    public function testDestroyTargetCreatureOfAColor(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Execute Lite')->unsupported);
        $knight = $game->addCard(1, self::card('White Knight'), GameObject::BATTLEFIELD)->id;
        $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD);
        $this->assertSame(["o:{$knight}"], $game->targetOptions(0, 'creature_color_W'));
    }

    public function testSacrificeAloneBounceAndSupport(): void
    {
        $game = $this->newGame();
        foreach (['Husk Lite', 'Flunkies Lite', 'Tide Lite', 'Dragonfire Lite', 'Support Lite'] as $name) {
            $this->assertSame([], self::read($name)->unsupported, $name);
        }
        // Sacrifice a creature: the weakest other one.
        $husk = $this->put(0, 'Husk Lite', GameObject::BATTLEFIELD);
        $bears = $game->addCard(0, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $game->activate(0, $husk, 0);
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$bears]->zone);
        $this->resolve();
        $this->assertSame(5, $game->power($game->objects[$husk]));

        // Support two: other creatures only.
        $giant = $game->addCard(0, self::card('Hill Giant'), GameObject::BATTLEFIELD)->id;
        $this->lands(0, 'Plains', 4);
        $support = $this->put(0, 'Support Lite', GameObject::HAND);
        $game->cast(0, $support);
        $this->resolve();
        $game->chooseTriggerTargets(0, ["o:{$husk}", "o:{$giant}"]);
        $this->resolve();
        $this->assertSame([1, 1, 0], [$game->objects[$husk]->counter('+1/+1'), $game->objects[$giant]->counter('+1/+1'), $game->objects[$support]->counter('+1/+1')]);

        // Up to two target creatures back to hand.
        $this->lands(0, 'Island', 4);
        $game->cast(0, $this->put(0, 'Tide Lite', GameObject::HAND), 0, ["o:{$giant}", "o:{$support}"]);
        $this->resolve();
        $this->assertSame([GameObject::HAND, GameObject::HAND], [$game->objects[$giant]->zone, $game->objects[$support]->zone]);

        // Alone, it doesn't attack.
        $game = $this->newGame();
        $flunkies = $this->put(0, 'Flunkies Lite', GameObject::BATTLEFIELD);
        $this->passUntil(Step::DeclareAttackers, 3);
        $game->declareAttackers(0, [$flunkies]);
        $this->assertSame([], $game->attackers);
    }

    public function testDamageToEachCreatureWithoutFlying(): void
    {
        $game = $this->newGame();
        $this->assertSame([], self::read('Rupture Lite')->unsupported);
        $bears = $game->addCard(1, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
        $angel = $this->put(1, 'Prepared Angel Lite // Seed Lite', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 3);
        $game->cast(0, $this->put(0, 'Rupture Lite', GameObject::HAND));
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $game->objects[$bears]->zone);
        $this->assertSame(0, $game->objects[$angel]->damage, 'Fliers are spared.');
    }

    public function testUntapSelfAbility(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Forest', 1);
        $elf = $this->put(0, 'Untapper Lite', GameObject::BATTLEFIELD);
        $game->objects[$elf]->sick = false;
        $this->passUntil(Step::PrecombatMain, 1);
        $game->activate(0, $elf, 0);
        $this->resolve();
        $this->assertTrue($game->objects[$elf]->tapped);
        $game->activate(0, $elf, 1);
        $this->resolve();
        $this->assertFalse($game->objects[$elf]->tapped);
        $game->activate(0, $elf, 0);
        $this->resolve();
        $this->assertSame(22, $this->life(0));
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
            'Divine Verdict' => 2, 'Mode Sprite' => 2, 'Steppe Lynx Lite' => 2, 'Sword Lite' => 1, 'Amrou Kithkin' => 2, 'Frogmite' => 2, 'Brightfield Mustang' => 2, 'Brightfield Glider' => 2, 'Hopeful Eidolon' => 2, 'Patchwork Banner' => 1, 'Lumen-Class Frigate' => 2, 'Burnout Bashtronaut' => 2, 'Frost Breath' => 1, 'Outpace Oblivion' => 1, 'Lava Coil' => 2, 'Electromancer Lite' => 1, 'Hyena Umbra' => 1, 'Treasure Cruise' => 1, 'Banisher Lite' => 1, 'Mind Control' => 1, 'Firebending Lite' => 2, 'Simic Initiate' => 2, 'Monster Lite' => 2, 'Riot Lite' => 2, 'Merfolk Branchwalker' => 2, 'Mentor Lite' => 2, 'Exploit Lite' => 1, 'Topan Freeblade' => 2, 'Glint-Sleeve Artisan' => 2, 'Syndic of Tithes' => 1, "Ajani's Pridemate" => 2, "Curse of Death's Hold" => 1, 'Wicked Akuba Lite' => 2, 'Scuttling Death' => 2, 'Soul Warden Lite' => 2, 'Relentless Rats' => 1, 'Murderous Compulsion' => 2, 'Boon-Bringer Valkyrie' => 1, 'Offspring Lite' => 2, 'Blessed Ghoul' => 2, 'Terror' => 2, 'Syndicate Messenger' => 2, 'Azorius Chancery' => 1, 'Prismatic Lens' => 1, 'Intimidation Tactics' => 1, 'Leyline of Sanctity' => 1, 'Ascend Lite' => 2, 'Echo Lite' => 2, 'Escape Lite' => 2, 'Retrace Lite' => 2, 'Skulking Ghost' => 1, 'Grapeshot' => 2, 'Cumulative Lite' => 2, 'Murder Lite' => 2, 'Bloodbraid Elf' => 2, 'Ward Discard Lite' => 2, 'Search Lite' => 2, 'Embalm Lite' => 2, 'Enlist Lite' => 2, 'Life Land Lite' => 1, 'Pro Black Lite' => 2, 'Tribute Lite' => 2, 'Healer Lite' => 2, 'Reconfigure Lite' => 2, 'Zenith Lite' => 1, 'Look Land Lite' => 2, 'Reveal Lands Lite' => 1, 'Shuffle Lite' => 1, 'Temporary Lite' => 1, 'Path Lite' => 1, 'Stress Lite' => 2, 'Seismic Lite' => 2, 'Extraction Lite' => 1, 'Bribery Lite' => 1, 'Werewolf Lite // Howler Lite' => 2, 'Daybound Lite // Nightbound Lite' => 2, 'Flip Lite // Flipped Lite' => 2, 'Ghost Lite // Spirit Lite' => 2, 'Pay Flip Lite // Paid Lite' => 2, 'Saga Lite' => 2, 'Flip Saga Lite // Saga Dragon Lite' => 2, 'Lure Lite' => 2, 'Bully Lite' => 2, 'Hand Lite' => 1, 'Grave Lite' => 1, 'Read Ahead Lite' => 1, 'Ramp Lite' => 1, 'Cultivate' => 1, 'Training Lite' => 2, 'Scaler Lite' => 1, 'Trample Counters Lite' => 1, 'Party Lite' => 1, 'Sac Ping Lite' => 1, 'Afflict Lite' => 2, 'Dredge Lite' => 2, 'Incubate Lite' => 2, 'Ki Lite' => 1, 'Arcane Lite' => 1, 'Night Lite' => 1, 'Blitz Lite' => 2, 'Buyback Lite' => 2, 'Ingest Lite' => 2, 'Ally Lite' => 2, 'Conspire Lite' => 2, 'Awaken Lite' => 2, 'Connive Lite' => 2, 'Tapped Discount Lite' => 2, 'Grave Lands Lite' => 1, 'Big Game Lite' => 2, 'Tutor Lite' => 1, 'Demonic Lite' => 1, 'Regrowth Lite' => 2, 'Loot Enter Lite' => 2, 'Extra Turn Lite' => 1, 'Grave Exile Lite' => 2, 'Scavenge Lite' => 2, 'Filter Land Lite' => 1, 'Double White Lite' => 1, 'Freerun Lite' => 2, 'Tapped Aura Lite' => 1, 'Forest Aura Lite' => 1, 'Mixed Aura Lite' => 1, 'Color Aura Lite' => 1, 'Small Aura Lite' => 1, 'Inquisition Lite' => 2, 'Peek Hand Lite' => 1, 'Untapper Lite' => 2, 'No Regen Burn Lite' => 1, 'Shield Lite' => 2, 'Island Walker Lite' => 2, 'Lure Beast Lite' => 2, 'Charge Relic Lite' => 1, 'Amass Lite' => 2, 'Permanent Regrowth Lite' => 1, 'Lazy Untap Lite' => 1, 'Freeze Aura Lite' => 1, 'Crowd Lite' => 2, 'Tuck Lite' => 1, 'Team Counters Lite' => 2, 'Double Pump Lite' => 1, 'Ritual Lite' => 1, 'Outlast Lite' => 2, 'Shrink Lite' => 1, 'Double Threaten Lite' => 1, 'Smother Lite' => 2, 'X Leak Lite' => 1, 'Impulse Today Lite' => 1, 'Transmute Lite' => 1, 'Transmute Beast Lite' => 1, 'Mother Lite' => 2, 'Two Block Lite' => 2, 'Learn Lite' => 1, 'Grave Hate Lite' => 1, 'Titan Lite' => 1, 'Compleated Lite' => 1, 'Drum Lite' => 1, 'Edict Lite' => 1, 'Artifact Sac Lite' => 1, 'Haste Magic Lite' => 1, 'Relay Lite' => 1, 'Stirrings Lite' => 1, 'Teamwork Lite' => 1, 'Multikick Lite' => 2, 'Clash Burn Lite' => 1, 'Clash Rat Lite' => 2, 'Clash Pump Lite' => 1, 'Lash Lite' => 1, 'Gift Pump Lite' => 1, 'Gift Draw Lite' => 1, 'Gift Otter Lite' => 2, 'Courser Lite' => 2, 'Future Lite' => 1, 'Cipher Lite' => 2, 'Company Lite' => 1, 'Battlefront Lite' => 1, 'Rummager Lite' => 2, 'Obliteration Lite' => 1, 'Goryo Lite' => 1, 'Elf Lord Lite' => 2, 'Bookworm Lite' => 2, 'Pro Artifacts Lite' => 2, 'Bolster Lite' => 1, 'Hideous Lite' => 1, 'Countersquall Lite' => 1, 'Grave Discount Lite' => 1, 'Exiler Lite' => 2, 'Converge Lite' => 2, 'Stun Sage Lite' => 2, 'Skullcrack Lite' => 1, 'Replicate Lite' => 2, 'Ring Lite' => 1, 'Ring Orc Lite' => 2, 'Raid Lite' => 2, 'Pupil Lite' => 2, 'Wings Lite' => 1, 'Rally Lite' => 1, 'Frostwalk Lite' => 2, 'Increment Lite' => 2, 'Fiend Hunter Lite' => 2, 'Blood Guest Lite' => 2, 'Prepared Angel Lite // Seed Lite' => 2, 'Upkeep Prep Lite // Mend Lite' => 1, 'Mourner Lite // Mourn Lite' => 1, 'Leech Prep Lite // Sip Lite' => 1, 'Third Spell Lite // Third Lite' => 1, 'Silver Prep Lite // Silver Lite' => 1, 'Refractor Lite' => 1, 'Mascot Lite' => 1, 'Vivid Lite' => 1, 'Bolt Lite // Heal Lite' => 2, 'Fire Lite // Ice Lite' => 2, 'Heroic Lite' => 2, 'Tenacity Lite' => 1, 'Taxed Lite' => 2, 'Escalate Lite' => 2, 'Rupture Lite' => 1, 'Harmonize Lite' => 1, 'Husk Lite' => 2, 'Flunkies Lite' => 2, 'Tide Lite' => 1, 'Dragonfire Lite' => 1, 'Support Lite' => 2, 'Glacial Ray Lite' => 2, 'Evermind Lite' => 1, 'Ornery Goblin Lite' => 2, 'Gilded Lotus Lite' => 1, 'Vitality Lite' => 2, 'Lynx Lite' => 1, 'Reanimate Lite' => 1, 'Witness Lite' => 1, 'Liege Lite' => 1, 'Oriflamme Lite' => 1, 'Wild Growth Lite' => 1, 'Command Lite' => 1, 'Metalcraft Lite' => 2, 'Threshold Lite' => 2, 'Hussar Lite' => 2, 'Execute Lite' => 1, 'Beckon Lite' => 1, 'Wrath Lite' => 2, 'Zendikon Lite' => 1, 'Roar Lite' => 2, 'Whelp Lite' => 2, 'Champion Lite' => 2, 'Zero Lite' => 1, 'Sneak Lite' => 2, 'Mercy Lite' => 1, 'Energy Champion Lite' => 1, 'Bond Lite' => 2, 'Wing Bond Lite' => 1, 'Emerge Lite' => 1, 'Pact Lite' => 1, 'Hideaway Lite' => 1, 'Windbrisk Lite' => 1, 'Impulse Enter Lite' => 1, 'Untapped Hexproof Lite' => 1, 'Grave Tuck Lite' => 1, 'Justice Lite' => 1, 'Flicker Lite' => 1, 'Exile Wrath Lite' => 1, 'Prophecy Lite' => 1, 'Land Scout Lite' => 1, 'Twin Bolt Lite' => 1, 'Counterpart Lite' => 1, 'Skystinger Lite' => 1, 'Herbalist Lite' => 1, 'Infiltrator Lite' => 1, 'Twin Copy Lite' => 1, 'Journey Lite' => 1, 'Dragonstorm Lite' => 1, 'Collapse Lite' => 1, 'Wave Lite' => 1, 'Banshee Lite' => 1, 'Eruption Lite' => 1, 'Sporeback Lite' => 1, 'Flayer Lite' => 1, 'Stinger Lite' => 1, 'Drake Lord Lite' => 1, 'Speed Lite' => 1, 'Informant Lite' => 1, 'Shambler Lite' => 1, 'Racketeer Lite' => 1, 'Energy Lite' => 1, 'Vista Lite' => 1, 'Devotee Lite' => 1, 'Tunnel Lite' => 1, 'Endure Lite' => 1, 'Exhaust Lite' => 1, 'Journeymage Lite' => 1, 'Rockslide Lite' => 1, 'Games Hero Lite' => 1, 'Leopard Lite' => 1, 'Discover Lite' => 1, 'Mare Lite' => 1, 'Medallion Lite' => 1, 'Firewalker Lite' => 1, 'Harbinger Lite' => 1, 'Kird Lite' => 1, 'Piledriver Lite' => 1, 'Predation Lite' => 1, 'Drifter Lite' => 1, 'Sliver Lord Lite' => 1, 'Hivepool Lite' => 1, 'Rallier Lite' => 1, 'Peppersmoke Lite' => 1, 'Windstorm Lite' => 1, 'Snarecaster Lite' => 1, 'Colonnade Lite' => 1, 'Construct Lite' => 1, 'Wither X Lite' => 1, 'Gruesome Lite' => 1, 'Elf Reveal Lite' => 1, 'Beast Within Lite' => 1, 'Swords Lite' => 1, 'Regen Aura Lite' => 1, 'Surge Lite' => 1, 'Bloodrush Lite' => 1, 'Bracket Walker Lite' => 1, 'God Lite' => 1, 'Sword Pair Lite' => 1, 'Spectacle Lite' => 1, 'Cleave Lite' => 1, 'Wizard Staff Lite' => 1, 'Were-Worm Lite' => 1, 'Uril Lite' => 1, 'Viashino Lite' => 1, 'Onna Lite' => 1, 'Channel Lite' => 1, 'Reinforce Lite' => 1, 'Power-up Lite' => 1, 'Mayhem Lite' => 1, 'Recover Lite' => 1, 'Amphitheater Lite' => 1, 'Inspiration Lite' => 1, 'Gremlins Lite' => 1, 'Stomp Kick Lite' => 1, 'Hatchling Lite' => 1, 'Mastery Lite' => 1, 'Chrome Lite' => 1, 'Giant Sac Lite' => 1, 'Vortex Lite' => 1, 'Strive Lite' => 1, 'Craft Lite // Crafted Lite' => 1, 'Webslinger Lite' => 1, 'Kozilek Lite' => 1, 'Shortsword Lite' => 1, 'Two Kicker Lite' => 1, 'Wildebeest Lite' => 1, 'Lecturer Lite' => 1, 'Tiered Lite' => 1, 'Necro Lite' => 1, 'Paradigm Lite' => 1, 'Haunter Lite' => 1, 'Haunt Spell Lite' => 1, 'Mutant Lite' => 2, 'Evidence Lite' => 1, 'Chalice Lite' => 1, 'Tail Swipe Lite' => 1, 'Juggernaut Lite' => 1, 'Phantom Lite' => 1, 'Ramble Lite' => 1, 'Confluence Lite' => 1, 'Savant Lite' => 2, 'Stomp Lite' => 1, 'Recycle Lite' => 1, 'Overlord Lite' => 2, 'Sengir Lite' => 2, 'Snuff Lite' => 1, 'Breath Lite' => 1, 'Swipe Lite' => 2, 'Salvage Lite' => 1, 'Mirror Lite' => 1, 'Twin Lite' => 1, 'Kinsong Lite' => 1, 'Elfsong Lite' => 1, 'Silence Lite' => 1, 'Warden Lite' => 2, 'Kiki Lite' => 1, 'Lantern Lite' => 1, 'Toplook Lite' => 1, 'Stop Lite' => 1, 'Fowl Lite' => 1, 'Kenway Lite' => 1, 'Bender Lite' => 1, 'Lander Lite' => 1, 'Mapper Lite' => 1, 'Pitch Bolt Lite' => 1, 'Flare Lite' => 1, 'Pitch Negate Lite' => 1, 'Harness Lite' => 1, 'Charger Lite' => 1, 'Swarm Pump Lite' => 1, 'Firebreather Lite' => 1, 'X Pinger Lite' => 1, 'Welcome Lite' => 1, 'Auto Sword Lite' => 1, 'War Banner Lite' => 1, 'Raid Horn Lite' => 1, 'Spy Net Lite' => 1, 'Blood Pact Lite' => 1, 'Drill Sergeant Lite' => 1, 'Disenchanter Lite' => 1, 'Watcher Lite' => 1, 'Lifewatch Lite' => 1, 'Raider Lite' => 1, 'Bargainer Lite' => 1, 'First Spark Lite' => 1, 'Raid Leader Lite' => 1, 'Scrap Finder Lite' => 1, 'Bounce Ally Lite' => 1, 'Sweeper Lite' => 1, 'Drain Lite' => 1, 'Noncreature Lite' => 1, 'Guard Lite' => 1, 'Farewell Lite' => 1, 'Vulture Lite' => 1, 'Madcap Lite' => 1, 'Cycler Lite' => 1, 'Cycle Fan Lite' => 1, 'Elf Buddy Lite' => 1, 'Shy Lite' => 1, 'Thalia Lite' => 1, 'Tax Lite' => 1, 'Frost Gate Lite' => 1, 'Sulfuric Lite' => 1, 'Painful Lite' => 1, 'Spiteful Lite' => 1, 'Harvest Lite' => 1, 'Spore Lite' => 1, 'Ghostly Lite' => 1, 'Tally Lite' => 1, 'Pale Edict Lite' => 1, 'Salvage Mill Lite' => 1, 'Swamp Shrink Lite' => 1, 'Mob Rider Lite' => 2, 'Crowd Muse Lite' => 1, 'Empty Hand Lite' => 1, 'Sea Loot Lite' => 2, 'Fierce Eye Lite' => 1, 'Fierce Beast Lite' => 2, 'Altar Hymn Lite' => 1, 'Grim Taker Lite' => 2, 'Gloat Lite' => 1, 'Mayhem Imp Lite' => 2, 'Grave Tally Lite' => 1, 'Tally Hound Lite' => 2, 'Scrap Bell Lite' => 1, 'Scrap Shard Lite' => 2, 'Spite Eye Lite' => 1, 'Raid Hound Lite' => 2, 'Ember Cache Lite' => 1, 'Tidy Wits Lite' => 2, 'Return Pup Lite' => 2, 'Back Again Lite' => 1, 'Sink Lite' => 2, 'Watchful Eye Lite' => 1, 'Wary Elk Lite' => 2, 'Brawny Lite' => 2, 'Scout Pilot Lite' => 2, 'Life Warden Lite' => 2, 'Grudge Eye Lite' => 1, 'Lone Raider Lite' => 2, 'Lone Banner Lite' => 1, 'Hoard Eye Lite' => 1, 'Dragon Lover Lite' => 2, 'Hoard Wurm Lite' => 2, 'Spite Hound Lite' => 2, 'Spite Pinger Lite' => 1, 'Howling Lite' => 1, 'Torment Altar Lite' => 1, 'Carrion Feeder Lite' => 2, 'Busy Square Lite' => 1, 'Spell Watcher Lite' => 1, 'Bloodbond Bat Lite' => 2, 'Tinker Eye Lite' => 1, 'Scrap Collector Lite' => 1, 'Land Count Lite' => 1, 'Pain Eater Lite' => 2, 'Life Spite Lite' => 1, 'Seer Eye Lite' => 1, 'Spiral Lite' => 1, 'Opt Lite' => 2, 'Token Fan Lite' => 1, 'Map Fan Lite' => 1, 'Night Toll Lite' => 1, 'Battle Hymn Lite' => 1, 'Dawn Draw Lite' => 1, 'Watch Post Lite' => 1, 'Shieldmate Lite' => 1, 'Raid Captain Lite' => 1, 'Exodus Eye Lite' => 1, 'Land Joy Lite' => 1, 'Outlaw Lite' => 2, 'Expend Rabbit Lite' => 2, 'Engine Fan Lite' => 1, 'Busy Hands Lite' => 1, 'Tap Tithe Lite' => 1, 'Masked Lite' => 1, 'Unmask Fan Lite' => 1, 'Explore Fan Lite' => 1, 'Dig Up Lite' => 1, 'Dig Beast Lite' => 1, 'Forager Lite' => 2, 'Food Maker Lite' => 1, 'Grave Scholar Lite' => 1, 'Taskmaster Lite' => 1, 'Grave Eater Lite' => 1, 'Pyre Lite' => 1, 'Mill Seer Lite' => 1, 'Bounce Seer Lite' => 1, 'Counter Sage Lite' => 1, 'Retreat Lite' => 1, 'Token Mourner Lite' => 1, 'Pain Ledger Lite' => 1, 'Ward Watch Lite' => 1, 'Self Focus Lite' => 1, 'Thorn Guard Lite' => 1, 'Land Mourner Lite' => 1, 'Land Burner Lite' => 1, 'Big Caller Lite' => 1, 'Big Beast Lite' => 1,
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
