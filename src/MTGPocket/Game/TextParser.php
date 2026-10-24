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

/**
 * Reads the parts of a card's rules text the engine applies:
 *
 * - keyword lines, e.g. `Flying, vigilance` ({@see CardDefinition::KEYWORDS}),
 *   with `Ward {2}`, `Crew 3`, `Cycling {2}`, `Flashback {1}{R}`,
 *   `Kicker {2}`, `Morph {3}` (and megamorph and disguise) and `Level up {1}`
 * - mana abilities: `{T}: Add {G}.`, `{T}: Add {W} or {U}.`, `{T}: Add {C}{C}.`,
 *   `{T}: Add one mana of any color.`, several on one permanent, and the
 *   painful ones (`… CARDNAME deals 1 damage to you.`, `{T}, Pay 1 life: …`)
 * - `This land enters tapped.` and `… enters with N +1/+1 counters on it.`
 * - an Aura's `Enchant …` line and `Enchanted creature gets +N/+N` / `has …` /
 *   `can't attack or block` / `doesn't untap …`, and the same bonuses for
 *   Equipment (`Equipped creature …`) with `Equip {N}`
 * - `CARDNAME can't block.` and the like ({@see CardDefinition::RESTRICTIONS})
 * - the most common effects: damage, card draw, life gain and loss,
 *   destroy, exile, return to hand, counter, tap and untap, +N/+N until end
 *   of turn, +1/+1 counters, creature tokens, scry, surveil and regenerate
 * - modal spells: `Choose one —`, `Choose two —`, `Choose one or both —` and
 *   `Choose one or more —` with a `•` line per mode
 * - `If this spell was kicked, …` on a spell, and `When this creature enters,
 *   if it was kicked, …`
 * - those effects as triggered abilities (`When CARDNAME enters, …`,
 *   `… dies, …`, `Whenever CARDNAME attacks, …`, `… deals combat damage to a
 *   player, …`, `When CARDNAME is turned face up, …`, `At the beginning of
 *   your upkeep / end step, …`, `Whenever you cast a noncreature spell, …`),
 *   as activated abilities (`{2}, {T}, Sacrifice CARDNAME: …`) and as a
 *   planeswalker's loyalty abilities (`+1: …`)
 * - level up creatures' `LEVEL N-M` bands: their power, toughness and keywords
 *
 * An ability is applied whole or not at all: if any sentence of it is not
 * understood, the line is reported as unsupported.
 *
 * @since 0.3.0
 */
final class TextParser
{
    /** The count after `where X is the number of `; see per(). */
    private const string X_COUNT = '(?:(other creatures|creatures|artifacts|lands|enchantments|[A-Z][a-z]+) you control|(creature |artifact |land |instant |sorcery )?(cards) in your (graveyard|hand)|(opponents) you have|(attacking creatures)(?: you control)?)';

    private const array NUMBERS = [
        'a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    /**
     * Target phrases => target kinds.
     */
    public const array TARGETS = [
        'any target' => 'any',
        'target creature or player' => 'any',
        'target creature' => 'creature',
        // Not the source itself; see Game::trigger().
        'another target creature' => 'creature_other',
        'target player' => 'player',
        'target opponent' => 'opponent',
        'target creature or planeswalker' => 'creature_or_planeswalker',
        'target player or planeswalker' => 'player_or_planeswalker',
        'target opponent or planeswalker' => 'player_or_planeswalker',
        'target artifact' => 'artifact',
        'target enchantment' => 'enchantment',
        'target artifact or planeswalker' => 'any:artifact|planeswalker',
        'target artifact or planeswalker an opponent controls' => 'any:artifact|planeswalker@opp',
        'target artifact or planeswalker you don\'t control' => 'any:artifact|planeswalker@opp',
        'target artifact or planeswalker you control' => 'any:artifact|planeswalker@you',
        'target enchantment or planeswalker' => 'any:enchantment|planeswalker',
        'target enchantment or planeswalker an opponent controls' => 'any:enchantment|planeswalker@opp',
        'target enchantment or planeswalker you don\'t control' => 'any:enchantment|planeswalker@opp',
        'target enchantment or planeswalker you control' => 'any:enchantment|planeswalker@you',
        'target artifact, enchantment, or planeswalker' => 'any:artifact|enchantment|planeswalker',
        'target artifact, enchantment, or planeswalker an opponent controls' => 'any:artifact|enchantment|planeswalker@opp',
        'target artifact, enchantment, or planeswalker you don\'t control' => 'any:artifact|enchantment|planeswalker@opp',
        'target artifact, enchantment, or planeswalker you control' => 'any:artifact|enchantment|planeswalker@you',
        'target artifact, creature, or enchantment' => 'any:artifact|creature|enchantment',
        'target artifact, creature, or enchantment you don\'t control' => 'any:artifact|creature|enchantment@opp',
        'target artifact, creature, or enchantment you control' => 'any:artifact|creature|enchantment@you',
        'target artifact, creature, or planeswalker' => 'any:artifact|creature|planeswalker',
        'target artifact, creature, or planeswalker an opponent controls' => 'any:artifact|creature|planeswalker@opp',
        'target artifact, creature, or planeswalker you don\'t control' => 'any:artifact|creature|planeswalker@opp',
        'target artifact, creature, or planeswalker you control' => 'any:artifact|creature|planeswalker@you',
        'target creature or enchantment' => 'any:creature|enchantment',
        'target creature or enchantment an opponent controls' => 'any:creature|enchantment@opp',
        'target creature or enchantment you don\'t control' => 'any:creature|enchantment@opp',
        'target creature or enchantment you control' => 'any:creature|enchantment@you',
        'target artifact or creature you don\'t control' => 'any:artifact|creature@opp',
        'target artifact or creature you control' => 'any:artifact|creature@you',
        'target creature or land' => 'any:creature|land',
        'target creature or land an opponent controls' => 'any:creature|land@opp',
        'target creature or land you don\'t control' => 'any:creature|land@opp',
        'target creature or land you control' => 'any:creature|land@you',
        'target artifact or land an opponent controls' => 'any:artifact|land@opp',
        'target artifact or land you don\'t control' => 'any:artifact|land@opp',
        'target artifact or land you control' => 'any:artifact|land@you',
        'target artifact, enchantment, or land' => 'any:artifact|enchantment|land',
        'target artifact, enchantment, or land an opponent controls' => 'any:artifact|enchantment|land@opp',
        'target artifact, enchantment, or land you don\'t control' => 'any:artifact|enchantment|land@opp',
        'target artifact, enchantment, or land you control' => 'any:artifact|enchantment|land@you',
        'target creature, enchantment, or planeswalker' => 'any:creature|enchantment|planeswalker',
        'target creature, enchantment, or planeswalker an opponent controls' => 'any:creature|enchantment|planeswalker@opp',
        'target creature, enchantment, or planeswalker you don\'t control' => 'any:creature|enchantment|planeswalker@opp',
        'target creature, enchantment, or planeswalker you control' => 'any:creature|enchantment|planeswalker@you',
        'target artifact or enchantment' => 'artifact_or_enchantment',
        'target artifact or creature' => 'artifact_or_creature',
        'target artifact creature' => 'artifact_creature',
        'target artifact or land' => 'artifact_or_land',
        'target nonblack creature' => 'creature_nonblack',
        'target nonartifact, nonblack creature' => 'creature_nonartifact_nonblack',
        'target nonartifact creature' => 'creature_nonartifact',
        'target land' => 'land',
        'target planeswalker' => 'planeswalker',
        'target nonland permanent' => 'nonland_permanent',
        'target noncreature permanent' => 'noncreature_permanent',
        'target nonland permanent an opponent controls' => 'nonland_permanent_opponent',
        // With two players, the defending player is the opponent: `… defending player controls` reads as `… an opponent controls`.
        'target nonland permanent defending player controls' => 'nonland_permanent_opponent',
        'target nonland permanent you don\'t control' => 'nonland_permanent_opponent',
        'target artifact or creature an opponent controls' => 'artifact_or_creature_opponent',
        'target artifact or creature defending player controls' => 'artifact_or_creature_opponent',
        'target artifact an opponent controls' => 'artifact_opponent',
        'target artifact defending player controls' => 'artifact_opponent',
        'target artifact you don\'t control' => 'artifact_opponent',
        'target enchantment an opponent controls' => 'enchantment_opponent',
        'target enchantment defending player controls' => 'enchantment_opponent',
        'target enchantment you don\'t control' => 'enchantment_opponent',
        'target land an opponent controls' => 'land_opponent',
        'target land defending player controls' => 'land_opponent',
        'target land you don\'t control' => 'land_opponent',
        'target land you control' => 'any:land@you',
        'target permanent an opponent controls' => 'permanent_opponent',
        'target permanent defending player controls' => 'permanent_opponent',
        'target permanent you don\'t control' => 'permanent_opponent',
        'target artifact or enchantment an opponent controls' => 'artifact_or_enchantment_opponent',
        'target artifact or enchantment defending player controls' => 'artifact_or_enchantment_opponent',
        'target artifact or enchantment you don\'t control' => 'artifact_or_enchantment_opponent',
        'target artifact, creature, or enchantment an opponent controls' => 'artifact_creature_enchantment_opponent',
        'target artifact, creature, or enchantment defending player controls' => 'artifact_creature_enchantment_opponent',
        'target artifact, enchantment, or creature with flying' => 'artifact_enchantment_flier',
        'target permanent' => 'permanent',
        'target creature you control' => 'creature_you_control',
        'another target creature you control' => 'creature_you_control_other',
        'target permanent you control' => 'permanent_you_control',
        'another target permanent you control' => 'permanent_you_control_other',
        'target nonland permanent you control' => 'nonland_permanent_you_control',
        'another target nonland permanent you control' => 'nonland_permanent_you_control_other',
        // Not the source; see Game::trigger().
        'another target permanent' => 'permanent_other',
        'another target nonland permanent' => 'nonland_permanent_other',
        'target creature an opponent controls' => 'creature_opponent',
        'target creature defending player controls' => 'creature_opponent',
        'target creature you don\'t control' => 'creature_opponent',
        // Only plural targets say `… your opponents control` (`up to two target creatures your opponents control`), read one at a time.
        'target creature your opponents control' => 'creature_opponent',
        'target nonland permanent your opponents control' => 'nonland_permanent_opponent',
        'target artifact your opponents control' => 'artifact_opponent',
        'target permanent your opponents control' => 'permanent_opponent',
        'target creature or planeswalker your opponents control' => 'creature_or_planeswalker_opponent',
        'target creature or planeswalker an opponent controls' => 'creature_or_planeswalker_opponent',
        'target creature or planeswalker defending player controls' => 'creature_or_planeswalker_opponent',
        'target creature or planeswalker you don\'t control' => 'creature_or_planeswalker_opponent',
        'target attacking or blocking creature' => 'attacking_or_blocking',
        'target attacking creature without flying' => 'attacking_no_flying',
        'target attacking creature with flying' => 'attacking_flying',
        'target attacking creature' => 'attacking',
        'target blocking creature' => 'blocking',
        'target creature with flying' => 'creature_flying',
        'target creature without flying' => 'creature_no_flying',
        'target tapped creature' => 'tapped_creature',
        'target untapped creature' => 'untapped_creature',
        'target creature with power 2 or greater' => 'creature_power_ge_2',
        'target creature with power 3 or greater' => 'creature_power_ge_3',
        'target creature with power 4 or greater' => 'creature_power_ge_4',
        'target creature with power 5 or greater' => 'creature_power_ge_5',
        'target creature with power 6 or greater' => 'creature_power_ge_6',
        'target creature with power 1 or less' => 'creature_power_le_1',
        'target creature with power 2 or less' => 'creature_power_le_2',
        'target creature with power 3 or less' => 'creature_power_le_3',
        'target creature with power 4 or less' => 'creature_power_le_4',
        'target creature with mana value 1 or less' => 'creature_mv_le_1',
        'target creature with mana value 2 or less' => 'creature_mv_le_2',
        'target creature with mana value 3 or less' => 'creature_mv_le_3',
        'target creature with mana value 4 or less' => 'creature_mv_le_4',
        'target creature with mana value 5 or less' => 'creature_mv_le_5',
        'target creature with mana value 1 or less an opponent controls' => 'creature_opp_mv_le_1',
        'target creature with mana value 2 or less an opponent controls' => 'creature_opp_mv_le_2',
        'target creature with mana value 3 or less an opponent controls' => 'creature_opp_mv_le_3',
        'target creature with mana value 4 or less an opponent controls' => 'creature_opp_mv_le_4',
        'target creature with mana value 5 or less an opponent controls' => 'creature_opp_mv_le_5',
        'target creature with power 1 or less an opponent controls' => 'creature_opp_power_le_1',
        'target creature an opponent controls with power 1 or less' => 'creature_opp_power_le_1',
        'target creature you don\'t control with power 1 or less' => 'creature_opp_power_le_1',
        'target creature with power 2 or less an opponent controls' => 'creature_opp_power_le_2',
        'target creature an opponent controls with power 2 or less' => 'creature_opp_power_le_2',
        'target creature you don\'t control with power 2 or less' => 'creature_opp_power_le_2',
        'target creature with power 3 or less an opponent controls' => 'creature_opp_power_le_3',
        'target creature an opponent controls with power 3 or less' => 'creature_opp_power_le_3',
        'target creature you don\'t control with power 3 or less' => 'creature_opp_power_le_3',
        'target creature with power 4 or less an opponent controls' => 'creature_opp_power_le_4',
        'target creature an opponent controls with power 4 or less' => 'creature_opp_power_le_4',
        'target creature you don\'t control with power 4 or less' => 'creature_opp_power_le_4',
        'target creature an opponent controls with mana value 1 or less' => 'creature_opp_mv_le_1',
        'target creature you don\'t control with mana value 1 or less' => 'creature_opp_mv_le_1',
        'target creature an opponent controls with mana value 2 or less' => 'creature_opp_mv_le_2',
        'target creature you don\'t control with mana value 2 or less' => 'creature_opp_mv_le_2',
        'target creature an opponent controls with mana value 3 or less' => 'creature_opp_mv_le_3',
        'target creature you don\'t control with mana value 3 or less' => 'creature_opp_mv_le_3',
        'target creature an opponent controls with mana value 4 or less' => 'creature_opp_mv_le_4',
        'target creature you don\'t control with mana value 4 or less' => 'creature_opp_mv_le_4',
        'target creature an opponent controls with mana value 5 or less' => 'creature_opp_mv_le_5',
        'target creature you don\'t control with mana value 5 or less' => 'creature_opp_mv_le_5',
        // `destroy target creature or planeswalker with mana value 3 or less`; see Game::matchesKind().
        'target creature or planeswalker with mana value 1 or less' => 'creature_or_planeswalker_mv_le_1',
        'target creature or planeswalker with mana value 2 or less' => 'creature_or_planeswalker_mv_le_2',
        'target creature or planeswalker with mana value 3 or less' => 'creature_or_planeswalker_mv_le_3',
        'target creature or planeswalker with mana value 4 or less' => 'creature_or_planeswalker_mv_le_4',
        'target creature or planeswalker with mana value 5 or less' => 'creature_or_planeswalker_mv_le_5',
        // `exile target nonland permanent an opponent controls with mana value 2 or less`; see Game::matchesKind().
        'target nonland permanent an opponent controls with mana value 1 or less' => 'nonland_opp_mv_le_1',
        'target nonland permanent you don\'t control with mana value 1 or less' => 'nonland_opp_mv_le_1',
        'target nonland permanent with mana value 1 or less an opponent controls' => 'nonland_opp_mv_le_1',
        'target nonland permanent an opponent controls with mana value 2 or less' => 'nonland_opp_mv_le_2',
        'target nonland permanent you don\'t control with mana value 2 or less' => 'nonland_opp_mv_le_2',
        'target nonland permanent with mana value 2 or less an opponent controls' => 'nonland_opp_mv_le_2',
        'target nonland permanent an opponent controls with mana value 3 or less' => 'nonland_opp_mv_le_3',
        'target nonland permanent you don\'t control with mana value 3 or less' => 'nonland_opp_mv_le_3',
        'target nonland permanent with mana value 3 or less an opponent controls' => 'nonland_opp_mv_le_3',
        'target nonland permanent an opponent controls with mana value 4 or less' => 'nonland_opp_mv_le_4',
        'target nonland permanent you don\'t control with mana value 4 or less' => 'nonland_opp_mv_le_4',
        'target nonland permanent with mana value 4 or less an opponent controls' => 'nonland_opp_mv_le_4',
        'target nonland permanent an opponent controls with mana value 5 or less' => 'nonland_opp_mv_le_5',
        'target nonland permanent you don\'t control with mana value 5 or less' => 'nonland_opp_mv_le_5',
        'target nonland permanent with mana value 5 or less an opponent controls' => 'nonland_opp_mv_le_5',
        'target nonwhite creature' => 'creature_noncolor_W',
        'target nonblue creature' => 'creature_noncolor_U',
        'target nonred creature' => 'creature_noncolor_R',
        'target nongreen creature' => 'creature_noncolor_G',
        'target white creature' => 'creature_color_W',
        'target blue creature' => 'creature_color_U',
        'target black creature' => 'creature_color_B',
        'target red creature' => 'creature_color_R',
        'target green creature' => 'creature_color_G',
        'target non-zombie creature' => 'creature_nonsub_Zombie',
        'target non-human creature' => 'creature_nonsub_Human',
        'target non-vampire creature' => 'creature_nonsub_Vampire',
        'target non-angel creature' => 'creature_nonsub_Angel',
        'target non-dragon creature' => 'creature_nonsub_Dragon',
        'target non-elf creature' => 'creature_nonsub_Elf',
        'target non-goblin creature' => 'creature_nonsub_Goblin',
        'target non-wall creature' => 'creature_nonsub_Wall',
        'target creature that isn\'t enchanted' => 'creature_unenchanted',
        'target creature card from your graveyard' => 'creature_card_yours',
        'another target creature card from your graveyard' => 'creature_card_yours_other',
        'target legendary creature card from your graveyard' => 'legendary_creature_card_yours',
        'target creature card with mana value 1 or less from your graveyard' => 'creature_card_yours_mv_le_1',
        'target creature card with mana value 2 or less from your graveyard' => 'creature_card_yours_mv_le_2',
        'target creature card with mana value 3 or less from your graveyard' => 'creature_card_yours_mv_le_3',
        'target creature card with mana value 4 or less from your graveyard' => 'creature_card_yours_mv_le_4',
        'target creature card with mana value 5 or less from your graveyard' => 'creature_card_yours_mv_le_5',
        'target creature card with mana value 6 or less from your graveyard' => 'creature_card_yours_mv_le_6',
        'target creature card from a graveyard' => 'creature_card_graveyard',
        'target card from a graveyard' => 'card_graveyard',
        'target card from an opponent\'s graveyard' => 'card_graveyard_opponent',
        'target card from defending player\'s graveyard' => 'card_graveyard_opponent',
        'target creature card from an opponent\'s graveyard' => 'creature_card_graveyard_opponent',
        'target card from your graveyard' => 'card_yours',
        'target instant or sorcery card from your graveyard' => 'instant_sorcery_card_yours',
        'target artifact card from your graveyard' => 'artifact_card_yours',
        'target permanent card from your graveyard' => 'permanent_card_yours',
        'target land card from your graveyard' => 'land_card_yours',
        'target enchantment card from your graveyard' => 'enchantment_card_yours',
        'target card in a graveyard other than a basic land card' => 'card_graveyard_nonbasic',
        'target nonbasic land' => 'nonbasic_land',
        'target spell' => 'spell',
        'target creature spell' => 'creature_spell',
        'target noncreature spell' => 'noncreature_spell',
        // `Counter target spell that targets a creature you control.`, `… that targets you.`
        'target spell that targets a creature you control' => 'spell_targets_your_creature',
        'target spell that targets you' => 'spell_targets_you',
        'target spell that targets you or a creature you control' => 'spell_targets_you_or_creature',
        'target spell with mana value 1 or less' => 'spell_mv_le_1',
        'target spell with mana value 2 or less' => 'spell_mv_le_2',
        'target spell with mana value 3 or less' => 'spell_mv_le_3',
        'target spell with mana value 4 or less' => 'spell_mv_le_4',
        'target instant or sorcery spell' => 'instant_sorcery_spell',
        'target instant or sorcery spell you control' => 'instant_sorcery_spell_yours',
    ];

    /**
     * Enchant lines => what an Aura may be attached to.
     */
    private const array ENCHANT = [
        'creature' => 'creature',
        'land' => 'land',
        'artifact' => 'artifact',
        'enchantment' => 'enchantment',
        'permanent' => 'permanent',
        'creature you control' => 'creature_you_control',
        'creature an opponent controls' => 'creature_opponent',
        'creature you don\'t control' => 'creature_opponent',
        'creature or planeswalker' => 'creature_or_planeswalker',
        'planeswalker' => 'planeswalker',
        'nonland permanent' => 'nonland_permanent',
        'artifact or creature' => 'artifact_or_creature',
        'land you control' => 'land_you_control',
        'creature or vehicle' => 'creature_or_vehicle',
        'player' => 'player',
        'opponent' => 'opponent',
        'tapped creature' => 'tapped_creature',
        'untapped creature' => 'untapped_creature',
        'creature with flying' => 'creature_flying',
        'creature without flying' => 'creature_no_flying',
        'artifact creature' => 'artifact_creature',
        'nonbasic land' => 'nonbasic_land',
        'artifact or enchantment' => 'artifact_or_enchantment',
        'artifact or land' => 'artifact_or_land',
        'nonland permanent an opponent controls' => 'nonland_permanent_opponent',
        'nonland permanent you don\'t control' => 'nonland_permanent_opponent',
        'creature or planeswalker an opponent controls' => 'creature_or_planeswalker_opponent',
        'creature or planeswalker you don\'t control' => 'creature_or_planeswalker_opponent',
        'artifact or creature an opponent controls' => 'artifact_or_creature_opponent',
    ];

    /** Whether the card being parsed is a creature. */
    private static bool $creature = false;

    /** @var string[] The subtypes of the card being parsed: `Whenever a Goblin you control dies, …` on a Goblin includes itself. */
    private static array $subtypes = [];

    /** Whether lines() drops leading ability words, for parse()'s second reading. */
    private static bool $withoutAbilityWords = false;

    /**
     * Ability words (rule 207.2c), which have no rules meaning: the text after the dash says everything.
     * Not saga chapters, `Solved`, `Max speed` or keywords such as Exhaust, whose dash does mean something.
     */
    private const ABILITY_WORDS = 'Adamant|Addendum|Alliance|Battalion|Celebration|Channel|Chroma|Cohort|Constellation|Converge|Corrupted|Council\'s dilemma|Coven|Delirium|Descend \d+|Domain|Eerie|Eminence|Enrage|Fateful hour|Fathomless descent|Ferocious|Flurry|Formidable|Grandeur|Hellbent|Heroic|Imprint|Inspired|Join forces|Kinship|Landfall|Lieutenant|Magecraft|Metalcraft|Morbid|Pack tactics|Paradox|Parley|Radiance|Raid|Rally|Renew|Revolt|Spell mastery|Strive|Survival|Sweep|Tempting offer|Threshold|Undergrowth|Valiant|Void|Will of the council';

    /** Predefined artifact tokens (rule 111.10). */
    private const array ARTIFACT_TOKENS = [
        'Food' => ['name' => 'Food', 'type' => 'Token Artifact — Food', 'types' => ['Artifact'], 'subtypes' => ['Food'], 'colors' => [], 'text' => '{2}, {T}, Sacrifice this token: You gain 3 life.', 'manaCost' => null],
        'Clue' => ['name' => 'Clue', 'type' => 'Token Artifact — Clue', 'types' => ['Artifact'], 'subtypes' => ['Clue'], 'colors' => [], 'text' => '{2}, Sacrifice this token: Draw a card.', 'manaCost' => null],
        'Treasure' => ['name' => 'Treasure', 'type' => 'Token Artifact — Treasure', 'types' => ['Artifact'], 'subtypes' => ['Treasure'], 'colors' => [], 'text' => '{T}, Sacrifice this token: Add one mana of any color.', 'manaCost' => null],
        'Blood' => ['name' => 'Blood', 'type' => 'Token Artifact — Blood', 'types' => ['Artifact'], 'subtypes' => ['Blood'], 'colors' => [], 'text' => '{1}, {T}, Discard a card, Sacrifice this token: Draw a card.', 'manaCost' => null],
        'Lander' => ['name' => 'Lander', 'type' => 'Token Artifact — Lander', 'types' => ['Artifact'], 'subtypes' => ['Lander'], 'colors' => [], 'text' => '{2}, {T}, Sacrifice this token: Search your library for a basic land card, put it onto the battlefield tapped, then shuffle.', 'manaCost' => null],
        'Map' => ['name' => 'Map', 'type' => 'Token Artifact — Map', 'types' => ['Artifact'], 'subtypes' => ['Map'], 'colors' => [], 'text' => '{1}, {T}, Sacrifice this token: Target creature you control explores. Activate only as a sorcery.', 'manaCost' => null],
        'Junk' => ['name' => 'Junk', 'type' => 'Token Artifact — Junk', 'types' => ['Artifact'], 'subtypes' => ['Junk'], 'colors' => [], 'text' => '{T}, Sacrifice this token: Exile the top card of your library. You may play that card this turn. Activate only as a sorcery.', 'manaCost' => null],
    ];

    /** Target kinds that are always a creature. */
    private const array CREATURE_KINDS = [
        'creature', 'creature_other', 'creature_you_control', 'creature_you_control_other', 'creature_opponent', 'attacking_or_blocking', 'attacking', 'attacking_flying', 'attacking_no_flying', 'blocking',
        'creature_flying', 'creature_no_flying', 'tapped_creature', 'untapped_creature',
        'creature_power_ge_2', 'creature_power_ge_3', 'creature_power_ge_4', 'creature_power_ge_5', 'creature_power_ge_6', 'creature_power_le_1', 'creature_power_le_2', 'creature_power_le_3', 'creature_power_le_4',
        'creature_mv_le_1', 'creature_mv_le_2', 'creature_mv_le_3', 'creature_mv_le_4', 'creature_mv_le_5',
        'creature_opp_mv_le_1', 'creature_opp_mv_le_2', 'creature_opp_mv_le_3', 'creature_opp_mv_le_4', 'creature_opp_mv_le_5',
        'creature_noncolor_W', 'creature_noncolor_U', 'creature_noncolor_R', 'creature_noncolor_G', 'creature_unenchanted',
        'creature_color_W', 'creature_color_U', 'creature_color_B', 'creature_color_R', 'creature_color_G',
        'creature_nonsub_Zombie', 'creature_nonsub_Human', 'creature_nonsub_Vampire', 'creature_nonsub_Angel', 'creature_nonsub_Dragon', 'creature_nonsub_Elf', 'creature_nonsub_Goblin', 'creature_nonsub_Wall',
    ];

    /**
     * `Choose …` => the least and most modes chosen (null: as many as there are).
     */
    private const array CHOOSE = [
        'one' => [1, 1],
        'two' => [2, 2],
        'three' => [3, 3],
        'one or both' => [1, 2],
        'one or more' => [1, null],
    ];

    /** A mana cost, e.g. `{2}{G/U}`. */
    private const string COST = '((?:\{[0-9WUBRGCSX\/P]+\})+)';

    /**
     * A predefined artifact token: `Food`, `Clue` or `Treasure`.
     *
     * @param string $name
     *
     * @return array
     */
    public static function artifactToken(string $name): array
    {
        return self::ARTIFACT_TOKENS[$name];
    }

    /**
     * @param CardDefinition $card With its name, types and text set.
     *
     * @return array{keywords: string[], mana: array|null, entersTapped: bool, counters: int, effects: array[], modes: array[], choose: array|null, aura: array|null, equipment: array|null, triggered: array[], activated: array[], ward: array|null, kicker: string|null, kickerCounters: int, flashback: string|null, cycling: string|null, morph: array|null, levels: array[], unsupported: string[]}
     */
    public static function parse(CardDefinition $card): array
    {
        $result = [
            'keywords' => [], 'mana' => null, 'entersTapped' => false, 'counters' => 0, 'effects' => [], 'modes' => [], 'choose' => null,
            'aura' => null, 'equipment' => null, 'triggered' => [], 'activated' => [],
            'ward' => null, 'kicker' => null, 'kickerCounters' => 0, 'entwine' => null, 'flashback' => null, 'cycling' => null, 'cyclingFinds' => null, 'unearth' => null, 'bestow' => null, 'chooses' => null, 'stationBands' => [], 'maxSpeed' => null, 'countsAs' => null, 'yourTurnKeywords' => [], 'otherCounters' => [], 'costReductions' => [], 'altCosts' => [], 'morph' => null, 'levels' => [],
            'minusCounters' => 0, 'tappedUnless' => null, 'anthem' => [], 'additionalCost' => null,
            'unsupported' => [],
        ];
        $spell = ! $card->isPermanentCard();
        self::$creature = $card->is('Creature');
        self::$subtypes = $card->subtypes;
        $lines = self::lines($card);
        $classLevel = 1;
        // `Kicker {G} and/or {1}{U}`: read as one kicker paying both, so either kicker's `if it was kicked with its {G} kicker` happens.
        foreach ($lines as $index => $line) {
            if (preg_match('/^Kicker '.self::COST.' and\/or '.self::COST.'$/', $line, $both)) {
                $lines[$index] = "Kicker {$both[1]}{$both[2]}";
                $lines = array_map(fn (string $other) => (string) preg_replace('/ (was|were) kicked with (?:its|their) (?:\{[^ ]+\}|both) kickers?/', ' $1 kicked', $other), $lines);
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Any other triggered ability with modes: `Landfall — Whenever a land you control enters, choose one —`.
            if (! $spell && preg_match('/^(.+), choose (one|two|three|one or both|one or more) —$/u', $line, $match)
                && ! preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks)$/', $match[1])) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                $probe = $result;
                $modes = array_map(fn (string $bullet) => ['text' => $bullet, 'effects' => self::effects($bullet)], $bullets);
                [$min, $max] = self::CHOOSE[$match[2]];
                if (count($bullets) >= 2 && ! in_array(null, array_column($modes, 'effects'), true) && $min <= count($modes)
                    && self::triggered($match[1].', you gain 1 life.', $probe) && count($probe['triggered']) === count($result['triggered']) + 1) {
                    $ability = end($probe['triggered']);
                    $result['triggered'][] = [
                        'text' => implode("\n", [$line, ...array_map(fn ($bullet) => "• {$bullet}", $bullets)]),
                        'effects' => [],
                        'modes' => $modes,
                        'choose' => ['min' => $min, 'max' => min($max ?? count($modes), count($modes))],
                    ] + $ability;
                } else {
                    $unread = array_values(array_filter($bullets, fn (string $bullet) => self::effects($bullet) === null));
                    $result['unsupported'] = [...$result['unsupported'], ...($unread === [] ? [$line] : array_map(fn ($bullet) => "• {$bullet}", $unread))];
                }

                continue;
            }
            // Teamwork's `Choose one. If this spell was cast using teamwork, choose both instead.`: entwined by tapping the team.
            if ($spell && isset($result['altCosts']['teamwork']) && preg_match('/^Choose one\. If CARDNAME was cast using teamwork, choose both instead\.?$/', $line)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    // `• Street Justice — Exile …`: the mode's name is flavor.
                    $bullets[] = preg_replace('/^[A-Z][\w\'’ ]* — /u', '', trim(mb_substr($lines[++$i], 1)));
                }
                if (count($bullets) === 2 && self::modes($spell, 'one', $bullets, $result)) {
                    $result['entwine'] = '';
                } else {
                    $result['unsupported'][] = $line;
                }

                continue;
            }
            // Mystic Confluence: `Choose three. You may choose the same mode more than once.`; see CardDefinition::choices().
            if ($spell && preg_match('/^Choose (two|three|four|five)\. You may choose the same mode more than once\.$/', $line, $match)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                if (self::modes($spell, 'one', $bullets, $result)) {
                    $n = self::amount($match[1]);
                    $result['choose'] = ['min' => $n, 'max' => $n, 'repeat' => true];
                } else {
                    $unread = array_values(array_filter($bullets, fn (string $bullet) => self::effects($bullet) === null));
                    $result['unsupported'] = [...$result['unsupported'], ...($unread === [] ? [$line] : array_map(fn ($bullet) => "• {$bullet}", $unread))];
                }

                continue;
            }
            // A modal spell's `Choose one —` and its `•` modes, or a triggered ability's `When CARDNAME enters, choose one —`.
            if (preg_match('/^(?:(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks), )?[Cc]hoose (one|two|three|one or both|one or more) —$/u', $line, $match)) {
                $bullets = [];
                while (isset($lines[$i + 1]) && str_starts_with($lines[$i + 1], '•')) {
                    $bullets[] = trim(mb_substr($lines[++$i], 1));
                }
                $read = $match[1] === ''
                    ? self::modes($spell, $match[2], $bullets, $result)
                    : ! $spell && self::modalTrigger(['enters the battlefield' => 'enters', 'enters' => 'enters', 'dies' => 'dies', 'attacks' => 'attacks'][$match[1]], $line, $match[2], $bullets, $result);
                if (! $read) {
                    // Each mode not read yet, so the coverage report counts them one by one.
                    $unread = array_values(array_filter($bullets, fn (string $bullet) => self::effects($bullet) === null));
                    $result['unsupported'] = [...$result['unsupported'], ...($unread === [] ? [$line] : array_map(fn ($bullet) => "• {$bullet}", $unread))];
                }

                continue;
            }

            // A level up creature's `LEVEL 2-6`, then its power and toughness, then its abilities at that level.
            if (preg_match('/^LEVEL (\d+)(?:-(\d+)|\+)$/', $line, $match)) {
                $band = ['min' => (int) $match[1], 'max' => isset($match[2]) ? (int) $match[2] : null, 'power' => null, 'toughness' => null, 'keywords' => []];
                if (isset($lines[$i + 1]) && preg_match('/^(\d+)\/(\d+)$/', $lines[$i + 1], $stats)) {
                    [$band['power'], $band['toughness']] = [(int) $stats[1], (int) $stats[2]];
                    $i++;
                }
                while (isset($lines[$i + 1]) && ! preg_match('/^LEVEL /', $lines[$i + 1])) {
                    $keywords = self::keywordList(str_replace(',', ', ', $lines[++$i]));
                    if ($keywords === null) {
                        $result['unsupported'][] = "{$line}: {$lines[$i]}";
                    } else {
                        $band['keywords'] = [...$band['keywords'], ...$keywords];
                    }
                }
                $result['levels'][] = $band;

                continue;
            }

            // A Class's `{2}{G}: Level 2`, then the abilities it gains at that level (rule 716).
            if (! $spell && in_array('Class', $card->subtypes, true) && preg_match('/^((?:\{[0-9WUBRGC\/]+\})+): Level (\d+)$/', $line, $match)) {
                $classLevel = (int) $match[2];
                $result['activated'][] = ['text' => $line, 'cost' => ['mana' => $match[1]], 'effects' => [['type' => 'class_level', 'amount' => $classLevel, 'self' => true]], 'sorcery' => true, 'once' => false, 'fromLevel' => $classLevel - 1];

                continue;
            }
            // Spree (rule 702.172): `+ {1} — …` modes, each an additional cost; one or more of them.
            // Tiered: `• Insight — {1} — …` modes, each an additional cost; exactly one of them.
            if ($spell && preg_match('/^(Spree|Tiered)\.?$/', $line, $kind)) {
                [$modes, $read, $tiered] = [[], true, $kind[1] === 'Tiered'];
                while (isset($lines[$i + 1]) && preg_match($tiered ? '/^• [^—]+ — '.self::COST.' — (.+)$/u' : '/^\+ '.self::COST.' — (.+)$/u', $lines[$i + 1], $match)) {
                    $i++;
                    if (str_contains($match[1], 'X') || ($effects = self::effects($match[2])) === null) {
                        $result['unsupported'][] = $lines[$i];
                        $read = false;
                    } else {
                        $modes[] = ['text' => $match[2], 'effects' => $effects, 'cost' => $match[1]];
                    }
                }
                if (! $read) {
                    continue;
                }
                if (count($modes) >= 2 && $result['modes'] === []) {
                    $result['modes'] = $modes;
                    $result['choose'] = ['min' => 1, 'max' => $tiered ? 1 : count($modes)];
                } else {
                    $result['unsupported'][] = $kind[1];
                }

                continue;
            }
            // Outlast (rule 702.107): `{cost}, {T}: Put a +1/+1 counter on CARDNAME. Activate only as a sorcery.`
            if (preg_match('/^Outlast '.self::COST.'$/', $line, $match)) {
                $line = "{$match[1]}, {T}: Put a +1/+1 counter on CARDNAME. Activate only as a sorcery.";
            }
            // `{2}{B}: Return CARDNAME from your graveyard to your hand.`: see Game::regrow().
            // Also `… to the battlefield tapped.` (Haunted Dead, Tunnel Rats).
            if (preg_match('/^'.self::COST.': Return CARDNAME from your graveyard to (your hand|the battlefield(?: tapped)?)\.$/', $line, $match) && ! str_contains($match[1], 'X')) {
                $result['altCosts']['regrow'] = $match[1];
                if ($match[2] !== 'your hand') {
                    $result['altCosts']['regrowTo'] = str_replace(['the ', ' '], ['', '_'], $match[2]);
                }

                continue;
            }
            // Static lines that work like keywords.
            $statics = [
                'You have no maximum hand size.' => 'no maximum hand size', 'Skip your draw step.' => 'skip your draw step', 'You may play an additional land on each of your turns.' => 'additional land', 'You may play lands from your graveyard.' => 'lands from graveyard',
                'If CARDNAME is in your opening hand, you may begin the game with it on the battlefield.' => 'leyline',
                'A deck can have any number of cards named CARDNAME.' => 'any number',
                'You have hexproof.' => 'you have hexproof',
                // Read by Game::putOntoBattlefield().
                'Creatures your opponents control enter tapped.' => 'opponent creatures enter tapped',
                'Artifacts and creatures your opponents control enter tapped.' => 'opponent artifacts and creatures enter tapped',
                'Creatures your opponents control enter the battlefield tapped.' => 'opponent creatures enter tapped',
                // Read by Game::whyNotCast().
                "Your opponents can't cast spells during your turn." => 'opponents cast nothing on your turn',
                'You may cast spells as though they had flash.' => 'spells have flash',
                'You may cast creature spells as though they had flash.' => 'creature spells have flash',
                // Chromatic Lantern; read by Game::manaSources().
                'Lands you control have "{T}: Add one mana of any color."' => 'lands tap for any color',
                'You may look at the top card of your library any time.' => 'look at top any time',
                // Shown on the board; see MatchMessageBuilder::side().
                'Play with the top card of your library revealed.' => 'top revealed',
                // Read by Game::playableFromTop().
                'You may play lands from the top of your library.' => 'lands from top',
                'You may play lands and cast spells from the top of your library.' => 'play from top',
                'You may cast creature spells from the top of your library.' => 'creatures from top',
                // Daybound sets it to day as it enters when it is neither (rule 702.145); see Game::dayNight().
                "If it's neither day nor night, it becomes day as CARDNAME enters." => 'daybound',
                'If CARDNAME would be put into a graveyard from anywhere, exile it instead.' => 'exile instead of graveyard',
                // A preparation card; see CardDefinition::$prepare.
                'CARDNAME enters prepared.' => 'enters prepared',
                // Both halves of a split card at once; see CardDefinition::$split.
                'Fuse' => 'fuse', 'Fuse.' => 'fuse',
                // Converge: counters for each color spent, as sunburst does for a creature.
                'Converge — CARDNAME enters with a +1/+1 counter on it for each color of mana spent to cast it.' => 'sunburst',
                // Read by Game::moveTo().
                'If a creature an opponent controls would die, exile it instead.' => 'exile dying opponent creatures',
                'CARDNAME escapes with a +1/+1 counter on it.' => 'escapes with 1',
                'CARDNAME escapes with two +1/+1 counters on it.' => 'escapes with 2',
                'CARDNAME escapes with three +1/+1 counters on it.' => 'escapes with 3',
                'CARDNAME escapes with four +1/+1 counters on it.' => 'escapes with 4',
                // Read by Game::canBlock().
                "Creatures with power less than CARDNAME's power can't block it." => "can't be blocked by lesser power",
                "CARDNAME can't block creatures with flying." => "can't block flying",
                // Read ahead (rule 714.3a): the controller may start a Saga on a later chapter; Game always starts it on chapter I, which is one of the legal choices.
                'Read ahead' => 'read ahead',
                // Rule 502.3: untapping it anyway is always one of the legal choices, and Game always does.
                'You may choose not to untap CARDNAME during your untap step.' => 'may not untap',
                // Read by Game::declareAttackers() and Game::declareBlockers().
                "CARDNAME can't attack or block alone." => "can't attack or block alone",
                "CARDNAME can't attack alone." => "can't attack alone",
                "CARDNAME can't block alone." => "can't block alone",
                // Read by Game::gainLife().
                "Players can't gain life." => "players can't gain life",
                "Your opponents can't gain life." => "opponents can't gain life",
                "Each opponent can't gain life." => "opponents can't gain life",
            ];
            if (! $spell && isset($statics[$line])) {
                $result['keywords'][] = $statics[$line];

                continue;
            }
            // Wild Growth / Fertile Ground: `Whenever enchanted land is tapped for mana, its controller adds an additional {G}.`; see Game::manaSources().
            if (! $spell && ($result['aura']['enchant'] ?? null) !== null && preg_match('/^Whenever enchanted land is tapped for mana, its controller adds an additional ((?:\{[WUBRGC]\})+|one mana of any colou?r)\.$/', $line, $match)) {
                preg_match_all('/\{([WUBRGC])\}/', $match[1], $symbols);
                $result['keywords'][] = 'enchanted land adds '.($symbols[1] === [] ? 'any' : implode('', $symbols[1]));

                continue;
            }
            // `If you would gain life, you gain that much life plus 1 instead.`: see Game::gainLife().
            if (! $spell && preg_match('/^If you would gain life, you gain that much life plus (\w+) instead\.$/', $line, $match) && is_int($n = self::amount($match[1]))) {
                $result['keywords'][] = "gain life plus {$n}";

                continue;
            }
            // Frost Titan: `Whenever CARDNAME becomes the target of a spell or ability an opponent controls, counter that spell or ability unless its controller pays {2}.` is ward (rule 702.21a).
            if (! $spell && $result['ward'] === null && preg_match('/^Whenever CARDNAME becomes the target of a spell or ability an opponent controls, counter that spell or ability unless its controller pays (?:('.self::COST.')|(\d+) life)\.$/', $line, $match)) {
                $result['ward'] = ($match[1] ?? '') !== '' ? ['mana' => $match[1]] : ['life' => (int) $match[3]];

                continue;
            }
            // `Spells your opponents cast that target CARDNAME cost {2} more to cast.`: ward's cost without the counter; see Game::wardCost().
            if (! $spell && $result['ward'] === null && preg_match('/^Spells your opponents cast that target CARDNAME cost ((?:\{\d+\})+) more to cast\.$/', $line, $match)) {
                $result['ward'] = ['mana' => $match[1], 'tax' => true];

                continue;
            }
            // A characteristic-defining ability (rule 604.3): `CARDNAME's power and toughness are each equal to the number of lands you control.`
            if (! $spell && preg_match("/^CARDNAME's (power and toughness are each|power is|toughness is) equal to the number of (?:(lands|creatures|artifacts|enchantments|Forests|Islands|Mountains|Plains|Swamps) you control|cards in your (hand|graveyard)|(creature) cards in your graveyard)\\.$/", $line, $match)) {
                $of = match (true) {
                    ($match[4] ?? '') !== '' => 'creature cards in graveyard',
                    ($match[3] ?? '') !== '' => $match[3],
                    default => strtolower($match[2]),
                };
                $result['countsAs'] = ['of' => $of, 'power' => $match[1] !== 'toughness is', 'toughness' => $match[1] !== 'power is'];

                continue;
            }
            // `CARDNAME gets +1/+1 for each artifact you control.`: read by Game::scaling().
            if (! $spell && preg_match('/^CARDNAME gets \+(\d+)\/\+(\d+) for each (artifact|creature|other creature|land|enchantment) you control\.$/', $line, $match)) {
                $result['keywords'][] = "gets +{$match[1]}/+{$match[2]} for each {$match[3]}";

                continue;
            }
            // `CARDNAME gets +1/+1 for each creature you control with a +1/+1 counter on it.`; see Game::countYours().
            if (! $spell && preg_match('/^CARDNAME gets \+(\d+)\/\+(\d+) for each creature you control with an? \+1\/\+1 counter on it\.$/', $line, $match)) {
                $result['keywords'][] = "gets +{$match[1]}/+{$match[2]} for each countered creature";

                continue;
            }
            // `CARDNAME gets +1/+0 for each card in your hand.`, `… for each creature card in your graveyard.`; counted by Game::perCount().
            if (! $spell && preg_match('/^CARDNAME gets \+(\d+)\/\+(\d+) for each (?:card in your hand|(creature |artifact |land |instant |sorcery )?card in your graveyard)\.$/', $line, $match)) {
                $result['keywords'][] = "gets +{$match[1]}/+{$match[2]} for each ".(str_ends_with($line, 'hand.') ? 'hand' : 'gy:'.(trim($match[3] ?? '') === '' ? 'card' : trim($match[3])));

                continue;
            }
            // `CARDNAME gets +2/+0 for each Mountain you control.`, `… for each other Elf you control.`, `… for each Aura attached to it.`
            if (! $spell && preg_match('/^CARDNAME gets \+(\d+)\/\+(\d+) for each (?:(other )?([A-Z][a-z]+) you control|(Aura|Equipment|Aura and Equipment) attached to it)\.$/', $line, $match)) {
                $result['keywords'][] = "gets +{$match[1]}/+{$match[2]} for each ".(($match[5] ?? '') !== '' ? 'attached:'.str_replace(' and ', '|', $match[5]) : $match[3].'sub:'.$match[4]);

                continue;
            }
            // `CARDNAME costs {1} less to cast for each creature in your party.`: read by Game::paymentFor().
            $eachCost = fn (string $each) => match ($each) {
                'creature in your party' => 'party',
                'opponent you have' => 'opponent',
                'card in your hand' => 'card',
                'instant and sorcery card in your graveyard' => 'graveyard_spell',
                'creature card in your graveyard' => 'graveyard_creature',
                'card in your graveyard' => 'graveyard_card',
                default => strtok($each, ' '),
            };
            $eachCostPattern = 'creature in your party|artifact you control|creature you control|land you control|card in your hand|opponent you have|instant and sorcery card in your graveyard|creature card in your graveyard|card in your graveyard';
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast for each ('.$eachCostPattern.')\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less for each ".$eachCost($match[2]);

                continue;
            }
            // `CARDNAME costs {1} less to cast if you control a Wizard.`
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast if you control an? ([A-Z][a-z]+|artifact|enchantment|legendary creature)\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less if you control {$match[2]}";

                continue;
            }
            if (preg_match('/^(?:This spell|CARDNAME) costs \{(\d+)\} less to cast if it targets a tapped creature\.$/', $line, $match)) {
                $result['keywords'][] = "costs {$match[1]} less if it targets a tapped creature";

                continue;
            }
            $kinds = ['' => 'any', 'Instant and sorcery ' => 'instant_sorcery', 'Creature ' => 'creature', 'Noncreature ' => 'noncreature', 'Artifact ' => 'artifact', 'Enchantment ' => 'enchantment'];
            // `Creature spells you cast cost {1} less to cast for each creature card in your graveyard.`
            if (! $spell && preg_match('/^(Instant and sorcery |Creature |Noncreature |Artifact |Enchantment )?[Ss]pells you cast cost \{(\d+)\} less to cast(?: for each ('.$eachCostPattern.'))?\.$/', $line, $match)) {
                $result['costReductions'][] = ['kind' => $kinds[$match[1]], 'amount' => (int) $match[2]] + (($match[3] ?? '') !== '' ? ['each' => $eachCost($match[3])] : []);

                continue;
            }
            // Thalia: `Noncreature spells cost {1} more to cast.`, every player's, or only opponents'.
            if (! $spell && preg_match('/^(Instant and sorcery |Creature |Noncreature |Artifact |Enchantment )?[Ss]pells( your opponents cast)? cost \{(\d+)\} more to cast\.$/', $line, $match)) {
                $result['costReductions'][] = ['kind' => $kinds[$match[1]], 'amount' => -(int) $match[3], 'who' => $match[2] !== '' ? 'opponents' : 'all'];

                continue;
            }
            // The Medallions and `Dragon spells you cast cost {1} less to cast.`
            if (! $spell && preg_match('/^(White|Blue|Black|Red|Green|[A-Z][a-z]+) spells you cast cost \{(\d+)\} less to cast\.$/', $line, $match)
                && ($kind = ['White' => 'color_W', 'Blue' => 'color_U', 'Black' => 'color_B', 'Red' => 'color_R', 'Green' => 'color_G'][$match[1]] ?? (($type = self::subtypeOf($match[1])) !== null ? "subtype_{$type}" : null)) !== null) {
                $result['costReductions'][] = ['kind' => $kind, 'amount' => (int) $match[2]];

                continue;
            }
            if (! $spell && preg_match('/^During your turn, CARDNAME has (.+?)\.$/', $line, $match) && ($keywords = self::keywordList($match[1])) !== null) {
                $result['yourTurnKeywords'] = [...$result['yourTurnKeywords'], ...$keywords];

                continue;
            }
            if (! $spell && preg_match('/^Max speed — (.+)$/u', $line, $match)) {
                $before = $result;
                if (preg_match('/^CARDNAME (?:gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?|has (.+?))\.?$/', $match[1], $has)
                    && ($keywords = self::keywordList(($has[3] ?? '') !== '' ? $has[3] : ($has[4] ?? ''))) !== null) {
                    $result['maxSpeed'] = ['power' => (int) ($has[1] ?? 0), 'toughness' => (int) ($has[2] ?? 0), 'keywords' => $keywords];
                } elseif (self::triggered($match[1], $result) || self::activated($match[1], $result) || self::anthem($match[1], $result)) {
                    foreach (['triggered', 'activated', 'anthem'] as $key) {
                        foreach (array_slice(array_keys($result[$key]), count($before[$key])) as $index) {
                            $result[$key][$index]['maxSpeed'] = true;
                        }
                    }
                } else {
                    $result = $before;
                    $result['unsupported'][] = $line;
                }

                continue;
            }
            // A Spacecraft's `12+ | Flying, lifelink`: what it has with that many charge counters.
            if (! $spell && preg_match('/^(\d+)\+ \| (.+)$/', $line, $match)) {
                $before = $result;
                if (($keywords = self::keywordList(rtrim($match[2], '.'))) !== null) {
                    $result['stationBands'][] = ['min' => (int) $match[1], 'keywords' => $keywords];
                } elseif (self::triggered($match[2], $result) || self::activated($match[2], $result) || self::anthem($match[2], $result)) {
                    foreach (['triggered', 'activated', 'anthem'] as $key) {
                        foreach (array_slice(array_keys($result[$key]), count($before[$key])) as $index) {
                            $result[$key][$index]['charge'] = (int) $match[1];
                        }
                    }
                    $result['stationBands'][] = ['min' => (int) $match[1], 'keywords' => []];
                } else {
                    $result = $before;
                    $result['unsupported'][] = $line;
                }

                continue;
            }
            if ($classLevel > 1) {
                $before = $result;
                if (! $spell && (self::triggered($line, $result) || self::activated($line, $result) || self::anthem($line, $result))) {
                    foreach (['triggered', 'activated', 'anthem'] as $key) {
                        foreach (array_slice(array_keys($result[$key]), count($before[$key])) as $index) {
                            $result[$key][$index]['classLevel'] = $classLevel;
                        }
                    }
                } else {
                    $result = $before;
                    $result['unsupported'][] = $line;
                }

                continue;
            }

            // Cleave (rule 702.148): the whole text, or without the words in square brackets when cast for its cleave cost (read as kicked; see Game::cast()).
            if ($spell && isset($result['altCosts']['cleave']) && str_contains($line, '[')
                && ($whole = self::effects(str_replace(['[', ']'], '', $line))) !== null
                && ($cleaved = self::effects((string) preg_replace(['/\s*\[[^\]]*\]/', '/\s+([.,])/'], ['', '$1'], $line))) !== null) {
                foreach ($whole as $effect) {
                    $result['effects'][] = $effect + ['unkicked' => true];
                }
                foreach ($cleaved as $effect) {
                    $result['effects'][] = $effect + ['kicked' => true];
                }

                continue;
            }
            if (self::keywords($line, $result, $spell)
                || self::manaAbility($line, $result)
                || self::entersTapped($line, $result)
                || (! $spell && self::restriction($line, $result))
                || (! $spell && self::anthem($line, $result))
                || self::additionalCost($line, $result)
                || (($card->isAura() || $result['bestow'] !== null) && self::aura($line, $result))
                || ($card->isEquipment() && self::equipment($line, $result))
                || ($card->isPlaneswalker() && self::loyalty($line, $result))
                || (in_array('Saga', $card->subtypes, true) && self::chapter($line, $result))
                || (! $spell && self::triggered($line, $result))
                || ((! $spell || str_contains($line, 'Discard CARDNAME:') || str_starts_with($line, 'Reinforce ')) && self::activated($line, $result))) {
                continue;
            }

            if (! $spell) {
                $result['unsupported'][] = $line;

                continue;
            }

            if (($impulse = self::impulse($line)) !== null) {
                $result['effects'][] = $impulse;

                continue;
            }
            // Haste Magic: other sentences, then the impulse.
            if (preg_match('/^(.+?\.)\s+(Exile the top .*)$/s', $line, $split) && ($impulse = self::impulse($split[2])) !== null && ($before = self::effects($split[1])) !== null) {
                array_push($result['effects'], ...$before, ...[$impulse]);

                continue;
            }
            if (($look = self::look($line)) === null && preg_match('/^(.+?\.)\s+((?:Look at|Reveal) the top .*)$/s', $line, $split)
                && ($later = self::look($split[2])) !== null && ($before = self::effects($split[1])) !== null) {
                array_push($result['effects'], ...$before);
                $look = $later;
            }
            if ($look !== null) {
                [$result['effects'][], $line] = $look;
                if ($line === '') {
                    continue;
                }
            }
            // A haunt spell's `When the creature CARDNAME haunts dies, …`, from exile.
            if (str_starts_with($line, 'When the creature CARDNAME haunts dies, ') && self::triggered($line, $result)) {
                continue;
            }
            // Harnessed Lightning: `Choose target creature. You get {E}{E}{E}, then you may pay any amount of {E}. CARDNAME deals that much damage to that creature.`
            if (preg_match('/^Choose target creature\. You get ((?:\{E\})+), then you may pay any amount of \{E\}\. CARDNAME deals that much damage to that creature\.$/u', $line, $energy)) {
                array_push($result['effects'], ['type' => 'energy', 'amount' => substr_count($energy[1], '{E}')], ['type' => 'damage', 'amount' => 0, 'target' => 'creature', 'payEnergy' => true]);

                continue;
            }
            // Tail Swipe: `Choose target creature you control and target creature you don't control. [The creature you control gets …] Then those creatures fight each other.`
            if (preg_match("/^Choose target creature you control and target creature you (?:don't|do not) control\\.\\s*(.*?)\\s*(?:Then )?(?:[Tt]hose creatures|[Tt]hey) fight each other\\.$/", $line, $fight)) {
                $middle = [];
                foreach (self::sentences($fight[1]) as $sentence) {
                    $found = self::sentenceEffects(preg_replace('/\\bthe creature you control\\b/i', 'target creature you control', $sentence));
                    if ($found === null || array_filter($found, fn (array $effect) => ($effect['target'] ?? null) !== 'creature_you_control') !== []) {
                        $middle = null;
                        break;
                    }
                    foreach ($found as $effect) {
                        unset($effect['target']);
                        $middle[] = $effect + ['sameTarget' => true];
                    }
                }
                if ($middle !== null) {
                    array_push($result['effects'], ['type' => 'chosen', 'target' => 'creature_you_control'], ...$middle, ...[['type' => 'fight', 'target' => 'creature_opponent', 'mutual' => true]]);

                    continue;
                }
            }
            // `Kicker—Sacrifice an artifact or creature.`: paid like bargain, by sacrificing one; see Game::bargainFodder().
            if (preg_match('/^Kicker—Sacrifice an? (artifact|creature|enchantment)(?: or (artifact|creature|enchantment))?\.$/u', $line, $sacrifice)) {
                $result['keywords'][] = 'bargain';
                $result['altCosts']['bargain'] = ucfirst($sacrifice[1]).(isset($sacrifice[2]) ? '|'.ucfirst($sacrifice[2]) : '');

                continue;
            }
            // Snuff Out: `If you control a Swamp, you may pay 4 life rather than pay CARDNAME's mana cost.`, and casting free if you control a Dragon.
            if (preg_match("/^(?:If you control an? ([A-Z][a-z]+), you may (?:pay (\\w+) life rather than pay CARDNAME's mana cost|cast CARDNAME without paying its mana cost)|You may cast CARDNAME without paying its mana cost if you control an? ([A-Z][a-z]+))\\.$/", $line, $pitch)
                && (($pitch[2] ?? '') === '' || is_int(self::amount($pitch[2])))) {
                $result['altCosts']['conditional'] = '';
                $result['keywords'][] = 'conditional control '.(($pitch[1] ?? '') !== '' ? $pitch[1] : $pitch[3]);
                if (($pitch[2] ?? '') !== '') {
                    $result['keywords'][] = 'conditional life '.self::amount($pitch[2]);
                }

                continue;
            }
            // Pitch spells: `You may exile a blue card from your hand rather than pay CARDNAME's mana cost.`, or sacrifice a nontoken creature of a color; see Game::pitchFodder().
            if (preg_match("/^(If it's not your turn, )?you may (?:pay (\\w+) life and )?(exile an? (white|blue|black|red|green) card from your hand|sacrifice an? nontoken (white|blue|black|red|green) creature) rather than pay CARDNAME's mana cost\\.$/i", $line, $pitch)
                && (($pitch[2] ?? '') === '' || is_int(self::amount($pitch[2])))) {
                $color = ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'][strtolower(($pitch[4] ?? '') !== '' ? $pitch[4] : $pitch[5])];
                $result['altCosts']['conditional'] = '';
                $result['keywords'][] = (($pitch[4] ?? '') !== '' ? 'conditional exile ' : 'conditional sacrifice ').$color;
                if ($pitch[1] !== '') {
                    $result['keywords'][] = 'conditional not your turn';
                }
                if (($pitch[2] ?? '') !== '') {
                    $result['keywords'][] = 'conditional life '.self::amount($pitch[2]);
                }

                continue;
            }
            // Strive: `{2}{R}` more for each target beyond the first; see Game::paymentFor().
            if (preg_match('/^Strive — CARDNAME costs '.self::COST.' more to cast for each target beyond the first\.$/u', $line, $strive)) {
                $result['altCosts']['strive'] = $strive[1];

                continue;
            }
            if (($reveal = self::revealDiscard($line)) !== null) {
                [$result['effects'][], $line] = $reveal;
            }
            foreach (self::sentences($line) as $sentence) {
                if ($sentence === "CARDNAME can't be countered") {
                    $result['keywords'][] = "can't be countered";
                } elseif (preg_match("/^You may pay ".self::COST." rather than pay CARDNAME's mana cost$/", $sentence, $mastery)) {
                    // An alternative cost (rule 118.9), cast like surge; "If the {M} cost was paid" reads as kicked.
                    $result['altCosts']['mastery'] = $mastery[1];
                } elseif ($sentence === 'Exile CARDNAME') {
                    // The spell goes to exile instead of its owner's graveyard as it finishes resolving.
                    $result['effects'][] = ['type' => 'exile_spell'];
                } elseif (! self::kickedSentence($sentence, $result) && ! self::modifies($sentence, $result['effects'])) {
                    $effects = self::sentenceEffects($sentence);
                    if ($effects === null) {
                        $result['unsupported'][] = $sentence.'.';
                    } else {
                        array_push($result['effects'], ...$effects);
                    }
                }
            }
        }

        if ($card->isAura() && $result['aura'] === null) {
            $result['unsupported'][] = 'Enchant …';
        }
        // Bestowed, it is an Aura with enchant creature (rule 702.103); otherwise a creature.
        if ($result['bestow'] !== null) {
            $result['bestow'] += ($result['aura'] ?? []) + ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['bestow']['enchant'] = 'creature';
            $result['aura'] = null;
        }
        // Backup grants only keywords; other abilities it would grant are not read yet.
        if (in_array('backup', $result['keywords'], true) && (count($result['triggered']) > 1 || $result['activated'] !== [] || $result['anthem'] !== [] || in_array('prowess', $result['keywords'], true))) {
            $result['unsupported'][] = 'Backup N';
        }
        if (in_array('prowess', $result['keywords'], true)) {
            $result['triggered'][] = ['text' => 'Prowess', 'event' => 'cast_noncreature', 'effects' => [['type' => 'pump', 'power' => 1, 'toughness' => 1, 'keywords' => [], 'self' => true]]];
        }
        // `Flurry — Whenever you cast your second spell each turn, …`: an ability word some lines aren't read with; read them again without it.
        if ($result['unsupported'] !== [] && ! self::$withoutAbilityWords && preg_match('/^(?:'.self::ABILITY_WORDS.') — /mu', $card->text)) {
            self::$withoutAbilityWords = true;
            try {
                $plain = self::parse($card);
            } finally {
                self::$withoutAbilityWords = false;
            }
            if (count($plain['unsupported']) < count($result['unsupported'])) {
                return $plain;
            }
        }

        return $result;
    }

    /**
     * A modal spell's modes, each read whole.
     *
     * @param bool     $spell
     * @param string   $choose  `one`, `two`, `one or both` …
     * @param string[] $bullets
     * @param array    $result
     *
     * @return bool
     */
    private static function modes(bool $spell, string $choose, array $bullets, array &$result): bool
    {
        if (! $spell || $result['modes'] !== [] || count($bullets) < 2) {
            return false;
        }
        $modes = [];
        foreach ($bullets as $bullet) {
            $effects = self::effects($bullet);
            if ($effects === null) {
                return false;
            }
            $modes[] = ['text' => $bullet, 'effects' => $effects];
        }
        [$min, $max] = self::CHOOSE[$choose];
        if ($min > count($modes)) {
            return false;
        }
        $result['modes'] = $modes;
        $result['choose'] = ['min' => $min, 'max' => min($max ?? count($modes), count($modes))];

        return true;
    }

    /**
     * A triggered ability with modes, such as `When CARDNAME enters, choose
     * one —`.
     *
     * @param string   $event
     * @param string   $line
     * @param string   $choose
     * @param string[] $bullets
     * @param array    $result
     *
     * @return bool
     */
    private static function modalTrigger(string $event, string $line, string $choose, array $bullets, array &$result): bool
    {
        if (count($bullets) < 2) {
            return false;
        }
        $modes = [];
        foreach ($bullets as $bullet) {
            if (($effects = self::effects($bullet)) === null) {
                return false;
            }
            $modes[] = ['text' => $bullet, 'effects' => $effects];
        }
        [$min, $max] = self::CHOOSE[$choose];
        if ($min > count($modes)) {
            return false;
        }
        $result['triggered'][] = [
            'text' => implode("\n", [$line, ...array_map(fn ($bullet) => "• {$bullet}", $bullets)]),
            'event' => $event,
            'effects' => [],
            'modes' => $modes,
            'choose' => ['min' => $min, 'max' => min($max ?? count($modes), count($modes))],
        ];

        return true;
    }

    /**
     * `If this spell was kicked, draw a card.` adds an effect done only
     * when kicked; `… it deals 5 damage instead.` raises the damage before it.
     *
     * @param string $sentence
     * @param array  $result
     *
     * @return bool
     */
    private static function kickedSentence(string $sentence, array &$result): bool
    {
        $unkicked = false;
        if (isset($result['altCosts']['gift']) && preg_match("/^If the gift (was|wasn't) promised, (.+)$/", $sentence, $gift)) {
            [$unkicked, $match] = [$gift[1] !== 'was', [$gift[0], $gift[2]]];
        } elseif (preg_match('/^If (?:an? ([A-Z][a-z]+) card was revealed|you revealed an? ([A-Z][a-z]+) card(?: or controlled an? \\2)?) as you cast CARDNAME, (.+)$/', $sentence, $reveal)
            || preg_match('/^If (?:an? ([A-Z][a-z]+) was beheld|you beheld an? ([A-Z][a-z]+)), (.+)$/', $sentence, $reveal)
            && in_array('reveal '.($reveal[1] !== '' ? $reveal[1] : $reveal[2]), $result['keywords'], true)) {
            // The reveal reads as kicked; `or controlled a Dragon` too (Game::revealed()).
            if (str_contains($sentence, 'or controlled')) {
                $result['keywords'][] = 'reveal or control';
            }
            $match = [$reveal[0], $reveal[3]];
        } elseif (preg_match("/^If CARDNAME's (surge|spectacle) cost was paid, (.+)$/", $sentence, $paid) && isset($result['altCosts'][$paid[1]])
            || isset($result['altCosts']['mastery']) && preg_match('/^If the (\\S+) cost was paid, (.+)$/', $sentence, $paid) && $paid[1] === $result['altCosts']['mastery']) {
            // Surge and spectacle read as kicked (see Game::cast()).
            $match = [$paid[0], $paid[2]];
        } elseif (($result['kicker'] === null && ! isset($result['altCosts']['bargain']) || ! preg_match('/^If CARDNAME was kicked, (.+)$/', $sentence, $match))
            && (! in_array('bargain', $result['keywords'], true) || ! preg_match('/^If CARDNAME was bargained, (.+)$/', $sentence, $match))
            && (! isset($result['altCosts']['evidence']) || ! preg_match('/^If evidence was collected, (.+)$/', $sentence, $match))) {
            return false;
        }
        if (preg_match('/^(?:it|CARDNAME) deals (\w+) damage(?: to (?:that|this) [\w ]+)? instead$/', $match[1], $instead)) {
            $last = array_key_last($result['effects']);
            if ($last === null || $result['effects'][$last]['type'] !== 'damage' || ($amount = self::amount($instead[1])) === null) {
                return false;
            }
            $result['effects'][$last]['kickedAmount'] = $amount;

            return true;
        }
        // Stomped by the Foot: `If this spell was kicked, that creature gets -5/-5 until end of turn instead.`
        if (preg_match('/^(?:that creature|it) gets ([+-]\d+)\/([+-]\d+) until end of turn instead$/', $match[1], $instead)) {
            $last = array_key_last($result['effects']);
            if ($last === null || $result['effects'][$last]['type'] !== 'pump' || ! isset($result['effects'][$last]['target'])) {
                return false;
            }
            $result['effects'][$last]['kickedPT'] = [(int) $instead[1], (int) $instead[2]];

            return true;
        }
        // `Draw a card. If evidence was collected, draw two cards instead.`: the earlier effect only when not kicked.
        if (! $unkicked && preg_match('/^(.+) instead$/', $match[1], $instead) && ($last = array_key_last($result['effects'])) !== null
            && ($alt = self::effects(ucfirst($instead[1]).'.')) !== null && count($alt) === 1 && $alt[0]['type'] === $result['effects'][$last]['type']
            && ! isset($result['effects'][$last]['kicked']) && ! isset($result['effects'][$last]['unkicked'])) {
            $result['effects'][$last]['unkicked'] = true;
            $result['effects'][] = $alt[0] + ['kicked' => true];

            return true;
        }
        $effects = self::effects($match[1]) ?? self::thatCreature(preg_replace('/^(?:that creature|it) also /', 'that creature ', $match[1]), $result['effects']);
        if ($effects === null) {
            return false;
        }
        foreach ($effects as $effect) {
            $result['effects'][] = $effect + [$unkicked ? 'unkicked' : 'kicked' => true];
        }

        return true;
    }

    /**
     * The text's abilities, one per line, with the card's own name as
     * `CARDNAME` and reminder text removed.
     *
     * @param CardDefinition $card
     *
     * @return string[]
     */
    private static function lines(CardDefinition $card): array
    {
        $text = $card->text;
        if ($text === '') {
            return [];
        }

        // A double-faced or split card's face is named before ` // `.
        $face = explode(' // ', $card->name)[0];
        $names = array_filter([$card->name, $face, explode(',', $face)[0]], fn ($name) => strlen(trim($name)) > 2);
        foreach (array_unique($names) as $name) {
            $text = str_replace($name, 'CARDNAME', $text);
        }
        $text = preg_replace('/\b[Tt]his ([Ss]pell|creature|land|artifact|enchantment|card|permanent|[Aa]ura|[Ee]quipment|planeswalker|[Vv]ehicle|[Ss]aga|[Cc]lass|[Ss]pacecraft|[Mm]ount|[Bb]attle|[Tt]oken)\b/', 'CARDNAME', $text);
        // A named ability such as `Gae Bolg — Equip {4}` works like the plain one.
        $text = preg_replace('/^[^\n—]+ — (Equip\b)/m', '$1', $text);
        $text = preg_replace('/\s*\([^)]*\)/', '', $text);
        // `Whenever CARDNAME or another creature you control dies`: the same as "a creature you control", which includes itself.
        $text = preg_replace_callback('/\bCARDNAME or another (nontoken creature|creature|[A-Z][a-z]+) you control\b/', function (array $m) {
            $kind = str_ends_with($m[1], 'creature') ? self::$creature : in_array($m[1], self::$subtypes, true);

            return $kind ? (preg_match('/^[AEIOU]/i', $m[1]) ? 'an ' : 'a ').$m[1].' you control' : $m[0];
        }, $text);
        if (self::$withoutAbilityWords) {
            $text = preg_replace('/^(?:'.self::ABILITY_WORDS.') — /mu', '', $text);
        }

        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== ''));
    }

    /**
     * @param string $line
     *
     * @return string[] Without their final periods.
     */
    private static function sentences(string $line): array
    {
        // `tap target creature an opponent controls and put a stun counter on it.`: two sentences, see modifies().
        $line = preg_replace('/\b(tap target [^.]+?) and put a stun counter on it\./i', '$1. Put a stun counter on it.', $line);

        return array_values(array_filter(array_map(fn ($s) => trim($s, " .\t"), preg_split('/(?<=\.)\s+/', $line)), fn ($s) => $s !== ''));
    }

    /**
     * A line of keywords, which may include the ones with a cost or number:
     * `Flying, ward {2}`, `Crew 3`, `Cycling {2}`, `Kicker {1}{G}` …
     *
     * @param string $line
     * @param array  $result
     * @param bool   $spell
     *
     * @return bool
     */
    private static function keywords(string $line, array &$result, bool $spell): bool
    {
        $found = $result;
        // Escape (rule 702.138): `Escape—{4}{B}, Exile five other cards from your graveyard.`
        if (preg_match('/^Escape—'.self::COST.', Exile (\w+) other cards? from your graveyard\.?$/u', $line, $m) && is_int($n = self::amount($m[2])) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['escape'] = $m[1];
            $result['keywords'][] = "escape {$n}";

            return true;
        }
        // Bloodrush (an ability word): `Bloodrush — {R}, Discard CARDNAME: Target attacking creature gets +4/+4 until end of turn.`; see Game::bloodrush().
        if (preg_match('/^Bloodrush — '.self::COST.', Discard CARDNAME: (Target attacking creature gets .+)\.$/u', $line, $m) && ! str_contains($m[1], 'X')
            && ($effects = self::effects($m[2])) !== null && count($effects) === 1 && $effects[0]['type'] === 'pump' && ($effects[0]['target'] ?? null) === 'attacking') {
            $result['altCosts']['bloodrush'] = $m[1];
            $result['altCosts']['bloodrushText'] = $m[2];

            return true;
        }
        // Recover (rule 702.59): see Game::recover().
        if (preg_match('/^Recover '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['recover'] = $m[1];

            return true;
        }
        // Mayhem: cast from your graveyard the turn you discarded it; see Game::whyNotFromGraveyard().
        if (preg_match('/^Mayhem '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['mayhem'] = $m[1];

            return true;
        }
        // Sneak (rule 702.190): cast during the declare blockers step by returning an unblocked attacker; see Game::whyNotCast().
        if (preg_match('/^Sneak '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['sneak'] = $m[1];

            return true;
        }
        // Emerge (rule 702.119): cast for this cost by sacrificing a creature, less that creature's mana value; see Game::emergeFodder().
        if (preg_match('/^Emerge '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['emerge'] = $m[1];

            return true;
        }
        // Miracle (rule 702.94): cast for this cost as the first card its owner draws in a turn; see Game::draw().
        if (preg_match('/^Miracle '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['miracle'] = $m[1];

            return true;
        }
        // Splice onto Arcane (rule 702.47): revealed from hand as an Arcane spell is cast, adding its effects for this cost; see Game::splicers().
        if ($spell && preg_match('/^Splice onto Arcane '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['splice'] = $m[1];

            return true;
        }
        // Harmonize (rule 702.180): cast from your graveyard, tapping a creature for its power off the cost, then exiled.
        if ($spell && preg_match('/^Harmonize '.self::COST.'\.?$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['harmonize'] = $m[1];

            return true;
        }
        // Disturb (rule 702.146): cast transformed from your graveyard.
        if (preg_match('/^Disturb '.self::COST.'$/u', $line, $m) && ! str_contains($m[1], 'X')) {
            $result['altCosts']['disturb'] = $m[1];

            return true;
        }
        // `Convoke.` and `Rebound.` are written with a period in some sets.
        foreach (array_map('trim', preg_split('/[,;]\s*/', rtrim($line, '.'))) as $part) {
            $word = strtolower($part);
            if (in_array($word, CardDefinition::KEYWORDS, true) && ! in_array($word, ['evolve', 'unleash'], true)) {
                $found['keywords'][] = $word;
            } elseif (preg_match('/^(bushido|toxic|bloodthirst|dredge) (\d+)$/', $word, $m)) {
                // Dredge (rule 702.52): see Game::draw().
                $found['keywords'][] = "{$m[1]} {$m[2]}";
            } elseif (preg_match('/^afflict (\d+)$/', $word, $m) && ! $spell) {
                // Afflict (rule 702.130): the defending player loses N life when it becomes blocked.
                $found['triggered'][] = ['text' => $part, 'event' => 'blocked', 'effects' => [['type' => 'lose_life', 'amount' => (int) $m[1], 'each' => 'opponent']]];
            } elseif (preg_match('/^ward '.self::COST.'$/i', $part, $m)) {
                $found['ward'] = ['mana' => $m[1]];
            } elseif (preg_match('/^ward—pay (\d+) life\.?$/iu', $part, $m)) {
                $found['ward'] = ['life' => (int) $m[1]];
            } elseif (preg_match('/^ward—discard a card\.?$/iu', $part)) {
                $found['ward'] = ['discard' => 1];
            } elseif (preg_match('/^kicker '.self::COST.'$/i', $part, $m)) {
                $found['kicker'] = $m[1];
            } elseif (preg_match('/^multikicker '.self::COST.'$/i', $part, $m)) {
                // Multikicker (rule 702.33c): kicker paid any number of times; see Game::castOptions().
                $found['kicker'] = $m[1];
                $found['keywords'][] = 'multikicker';
            } elseif (preg_match('/^replicate '.self::COST.'\.?$/i', $part, $m) && $spell && ! isset($found['kicker'])) {
                // Replicate (rule 702.56): paid like multikicker, a copy for each payment; see Game::castTriggers().
                $found['kicker'] = $m[1];
                $found['keywords'][] = 'multikicker';
                $found['keywords'][] = 'replicate';
            } elseif (preg_match('/^teamwork (\d+)$/i', $part, $m) && $spell) {
                // Teamwork: tap creatures with that much total power as you cast it; see Game::teamworkFodder().
                $found['altCosts']['teamwork'] = $m[1];
            } elseif (preg_match('/^Affinity for ([A-Z][a-z]+)$/', ucfirst($part), $m) && strtolower($m[1]) !== 'artifacts' && ($type = $m[1] === 'Equipment' ? 'Equipment' : self::subtypeOf($m[1])) !== null) {
                // Affinity for Equipment, Slivers …: {1} less for each one you control; see Game::paymentFor().
                $found['keywords'][] = "affinity for sub:{$type}";
            } elseif (preg_match('/^gift (a card|a food|a treasure|a clue|a tapped fish)$/i', $part, $m)) {
                // Gift (rule 702.174): an opponent gets it as the spell resolves, if it was promised; see Game::giveGift().
                $found['altCosts']['gift'] = substr(strtolower($m[1]), 2);
            } elseif (preg_match('/^entwine '.self::COST.'$/i', $part, $m) && $spell) {
                // Entwine (rule 702.42): every mode, for this much more.
                $found['entwine'] = $m[1];
            } elseif (preg_match('/^flashback '.self::COST.'$/i', $part, $m) && $spell) {
                $found['flashback'] = $m[1];
            } elseif (preg_match('/^bestow '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['bestow'] = ['cost' => $m[1]];
            } elseif (preg_match('/^unearth '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['unearth'] = $m[1];
            } elseif (preg_match('/^scavenge '.self::COST.'$/i', $part, $m) && ! $spell && ! str_contains($m[1], 'X')) {
                // Scavenge (rule 702.97): exile it from your graveyard, as a sorcery, for +1/+1 counters equal to its power.
                $found['altCosts']['scavenge'] = $m[1];
            } elseif (preg_match('/^cycling '.self::COST.'$/i', $part, $m)) {
                $found['cycling'] = $m[1];
            } elseif (preg_match('/^(basic land|plains|island|swamp|mountain|forest)cycling '.self::COST.'$/i', $part, $m)) {
                // Landcycling (rule 702.29e): search for that land instead of drawing.
                $found['cycling'] = $m[2];
                $found['cyclingFinds'] = strtolower($m[1]) === 'basic land' ? 'basic land' : ucfirst(strtolower($m[1]));
            } elseif (strtolower($part) === 'job select' && ! $spell) {
                $found['triggered'][] = ['text' => 'Job select', 'event' => 'enters', 'effects' => [['type' => 'job_select', 'self' => true]]];
            } elseif (preg_match('/^(morph|megamorph|disguise) '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['morph'] = ['kind' => strtolower($m[1]), 'cost' => $m[2]];
            } elseif (preg_match('/^crew (\d+)$/i', $part, $m) && ! $spell) {
                $found['activated'][] = ['text' => $part, 'cost' => ['crew' => (int) $m[1]], 'effects' => [['type' => 'crewed', 'self' => true]], 'sorcery' => false, 'once' => false];
            } elseif (strtolower($part) === 'start your engines!' && ! $spell) {
                // Speed (rule 702.179): see Game::updateSpeed().
                $found['keywords'][] = 'start your engines';
            } elseif (strtolower($part) === 'station' && ! $spell) {
                // Station (rule 702.184): tap another creature for charge counters equal to its power, as a sorcery.
                $found['activated'][] = ['text' => 'Station', 'cost' => ['station' => true], 'effects' => [['type' => 'charge', 'amount' => 0, 'self' => true]], 'sorcery' => true, 'once' => false];
            } elseif (preg_match('/^soulshift (\d+)$/i', $part, $m) && ! $spell) {
                // Soulshift (rule 702.46): when it dies, return a Spirit card with mana value N or less.
                $found['triggered'][] = ['text' => $part, 'event' => 'dies', 'effects' => [['type' => 'bounce', 'target' => "spirit_card_yours_{$m[1]}"]]];
            } elseif (preg_match('/^hideaway (\d+)$/i', $part, $m) && ! $spell) {
                // Hideaway (rule 702.75): one of the top N exiled face down for later; see Game::hideaway().
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'effects' => [['type' => 'hideaway', 'amount' => (int) $m[1], 'self' => true]]];
            } elseif (preg_match('/^fabricate (\d+)$/i', $part, $m) && ! $spell) {
                // Fabricate (rule 702.123): choose counters or Servos as the ability resolves.
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'effects' => [['type' => 'fabricate', 'amount' => (int) $m[1], 'self' => true]]];
            } elseif (preg_match('/^renown (\d+)$/i', $part, $m) && ! $spell) {
                // Renown (rule 702.112): the first time it deals combat damage to a player.
                $found['triggered'][] = ['text' => $part, 'event' => 'combat_damage', 'effects' => [['type' => 'renown', 'amount' => (int) $m[1], 'self' => true]]];
            } elseif (preg_match('/^modular (\d+)$/i', $part, $m) && ! $spell) {
                // Modular (rule 702.43): its +1/+1 counters move to an artifact creature as it dies.
                $found['counters'] = (int) $found['counters'] + (int) $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'dies', 'effects' => [['type' => 'counters', 'amount' => 'counters', 'target' => 'artifact_creature']]];
            } elseif ($word === 'evolve' && ! $spell) {
                // Evolve (rule 702.100): see Game::putOntoBattlefield().
                $found['keywords'][] = 'evolve';
                $found['triggered'][] = ['text' => 'Evolve', 'event' => 'evolve', 'effects' => [['type' => 'counters', 'amount' => 1, 'self' => true]]];
            } elseif ($word === 'for mirrodin!' && ! $spell) {
                // For Mirrodin! (rule 702.163): a 2/2 red Rebel token to carry it.
                $found['triggered'][] = ['text' => 'For Mirrodin!', 'event' => 'enters', 'effects' => [['type' => 'for_mirrodin', 'self' => true]]];
            } elseif (preg_match('/^awaken (\d+)—'.self::COST.'$/iu', $part, $m) && $spell) {
                // Awaken (rule 702.113): see Game::awaken().
                $found['altCosts']['awaken'] = $m[2];
                $found['keywords'][] = "awaken {$m[1]}";
            } elseif ($word === 'conspire' && $spell) {
                // Conspire (rule 702.78): see Game::conspirators().
                $found['keywords'][] = 'conspire';
            } elseif (preg_match('/^casualty (\d+)$/', $word, $m) && $spell) {
                // Casualty (rule 702.153): see Game::castOptions().
                $found['keywords'][] = "casualty {$m[1]}";
            } elseif ($word === 'living weapon' && ! $spell) {
                // Living weapon (rule 702.92): a 0/0 Germ token to carry it.
                $found['triggered'][] = ['text' => 'Living weapon', 'event' => 'enters', 'effects' => [['type' => 'living_weapon', 'self' => true]]];
            } elseif ($word === 'extort' && ! $spell) {
                // Extort (rule 702.101): paid whenever its controller can.
                $found['triggered'][] = ['text' => 'Extort', 'event' => 'cast_spell', 'effects' => [['type' => 'extort']]];
            } elseif (preg_match('/^overload '.self::COST.'$/i', $part, $m) && $spell) {
                // Overload (rule 702.96): see Game::castOptions().
                $found['altCosts']['overload'] = $m[1];
            } elseif (preg_match('/^(eternalize|embalm) '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Eternalize and embalm: see Game::embalm().
                $found['altCosts'][strtolower($m[1])] = $m[2];
            } elseif ($word === 'increment' && ! $spell) {
                // Increment: a +1/+1 counter when you cast a spell with more mana than its power or toughness; see Game::castTriggers().
                $found['keywords'][] = 'increment';
            } elseif ($word === 'enlist' && ! $spell) {
                $found['keywords'][] = 'enlist';
            } elseif (preg_match('/^protection from (white|blue|black|red|green|artifacts|creatures|enchantments|instants|sorceries|planeswalkers)$/', $word) && ! $spell) {
                // Protection from a color or a card type (rule 702.16): see Game::protectedFrom().
                $found['keywords'][] = $word;
            } elseif (preg_match('/^[Pp]rotection from ([A-Z][a-z]+s)$/', $part, $m) && ($type = self::subtypeOf($m[1])) !== null && ! $spell) {
                // Haytham Kenway: `Protection from Assassins`; see Game::protectedFrom().
                $found['keywords'][] = "protection from sub:{$type}";
            } elseif (preg_match('/^impending (\d+) ?— ?'.self::COST.'$/iu', $part, $m) && ! $spell) {
                // Impending (rule 702.176): cast for less, a non-creature until its time counters are gone; see Game::isCreature().
                $found['altCosts']['impending'] = $m[2];
                $found['keywords'][] = "impending {$m[1]}";
            } elseif (preg_match('/^prototype '.self::COST.' — (\d+)\/(\d+)$/iu', $part, $m) && ! $spell) {
                // Prototype (rule 702.160): cast smaller for less, see Game::prototype().
                $found['altCosts']['prototype'] = $m[1];
                $found['keywords'][] = "prototype {$m[2]}/{$m[3]}";
            } elseif (preg_match('/^tribute (\d+)$/', $word, $m) && ! $spell) {
                // Tribute (rule 702.104): the opponent never pays it, so `if tribute wasn't paid` always happens.
                $found['keywords'][] = "tribute {$m[1]}";
            } elseif (preg_match('/^buyback '.self::COST.'$/i', $part, $m) && $spell) {
                // Buyback (rule 702.27): an additional cost that returns the spell to its owner's hand.
                $found['altCosts']['buyback'] = $m[1];
            } elseif (preg_match('/^escalate '.self::COST.'$/i', $part, $m) && $spell) {
                // Escalate (rule 702.120): paid for each mode chosen beyond the first; see Game::castCost().
                $found['altCosts']['escalate'] = $m[1];
            } elseif ($word === 'ingest' && ! $spell) {
                // Ingest (rule 702.115): the damaged player exiles the top card of their library.
                $found['triggered'][] = ['text' => 'Ingest', 'event' => 'combat_damage', 'effects' => [['type' => 'ingest']]];
            } elseif (preg_match('/^transmute '.self::COST.'$/i', $part, $m)) {
                // Transmute (rule 702.53): discard it as a sorcery to search for a card with the same mana value; see Game::transmute().
                $found['altCosts']['transmute'] = $m[1];
            } elseif (preg_match('/^(dash|evoke|warp|plot|blitz|freerunning|surge|spectacle|cleave|web-slinging|mutate) '.self::COST.'$/i', $part, $m)) {
                // Other ways to cast it: see Game::castOptions().
                $found['altCosts'][str_replace('-', '', strtolower($m[1]))] = $m[2];
            } elseif (preg_match('/^echo '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Echo (rule 702.30): see Game::applyEffect().
                $found['altCosts']['echo'] = $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'echo', 'self' => true]]];
            } elseif ($word === 'haunt') {
                // Haunt (rule 702.55): exiled haunting a creature as it dies (or as the spell resolves); see Game::haunt().
                $found['keywords'][] = 'haunt';
                if (! $spell) {
                    $found['triggered'][] = ['text' => 'Haunt', 'event' => 'dies', 'effects' => [['type' => 'haunt', 'self' => true]]];
                }
            } elseif ($word === 'paradigm' && $spell) {
                // Paradigm: exiled as it resolves, then copied for free each first main phase; see Game::spellLeavesStack().
                $found['keywords'][] = 'paradigm';
            } elseif ($word === 'cascade') {
                // Cascade (rule 702.85): see Game::cascade().
                $found['keywords'][] = 'cascade';
                $found['triggered'][] = ['text' => 'Cascade', 'event' => 'cascade', 'effects' => [['type' => 'cascade']]];
            } elseif (preg_match('/^cumulative upkeep '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Cumulative upkeep (rule 702.24): an age counter each upkeep, then pay for each or sacrifice it.
                $found['altCosts']['cumulative'] = $m[1];
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'cumulative_upkeep', 'self' => true]]];
            } elseif (preg_match('/^foretell '.self::COST.'$/i', $part, $m)) {
                // Foretell (rule 702.143): exiled face down for {2} on your turn, cast on a later turn for this.
                $found['altCosts']['foretell'] = $m[1];
            } elseif (preg_match('/^madness '.self::COST.'$/i', $part, $m)) {
                // Madness (rule 702.35): discarded, it is exiled and may be cast for this while its trigger waits.
                $found['altCosts']['madness'] = $m[1];
                $found['triggered'][] = ['text' => 'Madness', 'event' => 'madness', 'effects' => [['type' => 'madness']]];
            } elseif (preg_match('/^suspend (\d+)—'.self::COST.'$/iu', $part, $m)) {
                // Suspend (rule 702.62): see Game::suspend().
                $found['altCosts']['suspend'] = $m[2];
                $found['keywords'][] = "suspend {$m[1]}";
            } elseif (preg_match('/^ninjutsu '.self::COST.'$/i', $part, $m) && ! $spell) {
                // Ninjutsu (rule 702.49): see Game::ninjutsu().
                $found['altCosts']['ninjutsu'] = $m[1];
            } elseif (preg_match('/^offspring '.self::COST.'$/i', $part, $m) && ! $spell && $found['kicker'] === null) {
                // Offspring (rule 702.175): an optional additional cost, like kicker; a 1/1 token copy when it enters.
                $found['kicker'] = $m[1];
                $found['keywords'][] = 'offspring';
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'kicked' => true, 'effects' => [['type' => 'offspring']]];
            } elseif (preg_match('/^afterlife (\d+)$/i', $part, $m) && ! $spell && ($spirit = self::effect('create a 1/1 white and black Spirit creature token with flying')) !== null) {
                // Afterlife (rule 702.135): flying Spirits when it goes to the graveyard from the battlefield.
                $found['triggered'][] = ['text' => $part, 'event' => 'to_graveyard', 'effects' => [['amount' => (int) $m[1]] + $spirit]];
            } elseif (preg_match('/^annihilator (\d+)$/i', $part, $m) && ! $spell) {
                // Annihilator (rule 702.86): the defending player sacrifices that many permanents, their weakest.
                $found['triggered'][] = ['text' => $part, 'event' => 'attacks', 'effects' => [['type' => 'edict', 'amount' => (int) $m[1]]]];
            } elseif (preg_match('/^backup (\d+)$/i', $part, $m) && ! $spell) {
                // Backup (rule 702.165): counters on target creature; another one gains this creature's keywords until end of turn.
                $found['keywords'][] = 'backup';
                $found['triggered'][] = ['text' => $part, 'event' => 'enters', 'effects' => [['type' => 'backup', 'amount' => (int) $m[1], 'target' => 'creature']]];
            } elseif (preg_match('/^mobilize (\d+)$/i', $part, $m) && ! $spell) {
                // Mobilize (rule 702.181): attacking Warrior tokens, sacrificed at the end step.
                $found['triggered'][] = ['text' => $part, 'event' => 'attacks', 'effects' => [['type' => 'mobilize', 'amount' => (int) $m[1]]]];
            } elseif (preg_match('/^firebending (\d+)$/i', $part, $m) && ! $spell) {
                // Firebending (rule 702.188): {R} for each, when it attacks.
                $found['triggered'][] = ['text' => $part, 'event' => 'attacks', 'effects' => [['type' => 'add_mana', 'color' => 'R', 'amount' => (int) $m[1]]]];
            } elseif (preg_match('/^graft (\d+)$/i', $part, $m) && ! $spell) {
                // Graft (rule 702.58): its counters move, one at a time, to your creatures as they enter.
                $found['counters'] = (int) $found['counters'] + (int) $m[1];
                $found['keywords'][] = 'graft';
                $found['triggered'][] = ['text' => $part, 'event' => 'graft', 'effects' => [['type' => 'graft', 'self' => true]]];
            } elseif (preg_match('/^devour (\d+)$/i', $part, $m) && ! $spell) {
                // Devour (rule 702.82): see Game::putOntoBattlefield().
                $found['keywords'][] = "devour {$m[1]}";
            } elseif ($word === 'unleash' && ! $spell) {
                // Unleash (rule 702.98): always with the counter, so it can't block.
                $found['counters'] = (int) $found['counters'] + 1;
                $found['keywords'][] = 'unleash';
            } elseif ($word === 'riot' && ! $spell) {
                // Riot (rule 702.136): always the counter, not haste.
                $found['counters'] = (int) $found['counters'] + 1;
            } elseif (preg_match('/^vanishing (\d+)$/i', $part, $m) && ! $spell) {
                // Vanishing (rule 702.63): time counters, one removed each upkeep; sacrificed when the last goes.
                $found['keywords'][] = "vanishing {$m[1]}";
                $found['triggered'][] = ['text' => $part, 'event' => 'upkeep', 'effects' => [['type' => 'vanishing', 'self' => true]]];
            } elseif ($word === 'mentor' && ! $spell) {
                // Mentor (rule 702.134): a +1/+1 counter on an attacking creature with lesser power.
                $found['triggered'][] = ['text' => 'Mentor', 'event' => 'attacks', 'effects' => [['type' => 'counters', 'amount' => 1, 'target' => 'attacking_lesser']]];
            } elseif ($word === 'training' && ! $spell) {
                // Training (rule 702.149): a +1/+1 counter when it attacks with a creature with greater power.
                $found['triggered'][] = ['text' => 'Training', 'event' => 'attacks', 'effects' => [['type' => 'training', 'self' => true]]];
            } elseif ($word === 'exploit' && ! $spell) {
                // Exploit (rule 702.110): see Game::applyEffect().
                $found['triggered'][] = ['text' => 'Exploit', 'event' => 'enters', 'effects' => [['type' => 'exploit', 'self' => true]]];
            } elseif (preg_match('/^saddle (\d+)$/i', $part, $m) && ! $spell) {
                // Saddle (rule 702.171): like crew, but only as a sorcery, and it stays a creature.
                $found['activated'][] = ['text' => $part, 'cost' => ['crew' => (int) $m[1]], 'effects' => [['type' => 'saddled', 'self' => true]], 'sorcery' => true, 'once' => false];
            } elseif (preg_match('/^craft with (artifact|creature|land|[A-Z][a-z]+) '.self::COST.'$/i', $part, $m) && ! $spell
                && (in_array(strtolower($m[1]), ['artifact', 'creature', 'land'], true) || self::subtypeOf($m[1]) !== null)) {
                // Craft (rule 702.167): exile it and another artifact (yours, or a card from your graveyard), then return it transformed; see Game::craftMaterial().
                $kind = in_array(strtolower($m[1]), ['artifact', 'creature', 'land'], true) ? strtolower($m[1]) : self::subtypeOf($m[1]);
                $found['activated'][] = ['text' => $part, 'cost' => ['mana' => $m[2], 'craft' => $kind], 'effects' => [['type' => 'exile_transformed', 'self' => true]], 'sorcery' => true, 'once' => false];
            } elseif (preg_match('/^level up '.self::COST.'$/i', $part, $m) && ! $spell) {
                $found['activated'][] = ['text' => $part, 'cost' => ['mana' => $m[1]], 'effects' => [['type' => 'level', 'self' => true]], 'sorcery' => true, 'once' => false];
            } else {
                return false;
            }
        }
        foreach ($found['altCosts'] as $cost) {
            if (str_contains($cost, 'X')) {
                return false;
            }
        }
        foreach (['kicker', 'entwine', 'flashback', 'cycling', 'morph', 'unearth', 'bestow'] as $cost) {
            if ($found[$cost] !== null && str_contains(is_array($found[$cost]) ? $found[$cost]['cost'] : $found[$cost], 'X')) {
                return false;
            }
        }
        $found['keywords'] = array_values(array_unique($found['keywords']));
        $result = $found;

        return true;
    }

    /**
     * A mana ability, or another one on the same permanent: a painland's
     * `{T}: Add {C}.` and `{T}: Add {R} or {G}. CARDNAME deals 1 damage to
     * you.` make one source of {C}, {R} or {G} that hurts for the colors.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function manaAbility(string $line, array &$result): bool
    {
        // Beastcaller Savant: `{T}: Add one mana of any color. Spend this mana only to cast a creature spell.`; see Game::payFor().
        if (preg_match('/^(.+\.) Spend this mana only to cast (?:an? )?(creature|artifact) spells?\.$/', $line, $match)) {
            if ($result['mana'] !== null) {
                return false;
            }
            if (! self::manaAbility($match[1], $result) || ($result['mana']['noTap'] ?? false) || ($result['mana']['filter'] ?? false)) {
                $result['mana'] = null;

                return false;
            }
            $result['mana']['only'] = ucfirst($match[2]);

            return true;
        }
        // `{2}: Add one mana of any color.` and `{1}: … Activate only once each turn.`: a filter that doesn't tap; see Game::payFor().
        if (preg_match('/^\{(\d)\}: Add one mana of any colou?r\.( Activate only once each turn\.)?$/', $line, $match)) {
            if ($result['mana'] !== null) {
                return false;
            }
            $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'noTap' => true] + ((int) $match[1] !== 1 ? ['filterCost' => (int) $match[1]] : []) + (($match[2] ?? '') !== '' ? ['once' => true] : []);

            return true;
        }
        // The Devotees: `{1}: Add {R}, {W}, or {B}. Activate only once each turn.`
        if (preg_match('/^\{(\d)\}: Add (\{[WUBRG]\}(?:,? (?:or )?\{[WUBRG]\})+)\.( Activate only once each turn\.)?$/', $line, $match)) {
            if ($result['mana'] !== null) {
                return false;
            }
            preg_match_all('/\{([WUBRG])\}/', $match[2], $symbols);
            $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'noTap' => true, 'filterAs' => ['count' => 1, 'colors' => array_values(array_unique($symbols[1]))]]
                + ((int) $match[1] !== 1 ? ['filterCost' => (int) $match[1]] : []) + (($match[3] ?? '') !== '' ? ['once' => true] : []);

            return true;
        }
        if ($result['mana']['noTap'] ?? false) {
            return false;
        }
        // `{1}, {T}: Add one mana of any color.`: see Game::payFor().
        if (preg_match('/^\{1\}, \{T\}: Add one mana of any colou?r\.$/', $line)) {
            if ($result['mana'] === null) {
                $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true];
            } elseif (($result['mana']['filter'] ?? false) || ($result['mana']['sacrifice'] ?? false)) {
                return false;
            } else {
                $result['mana']['filter'] = true;
            }

            return true;
        }
        // A Signet's `{1}, {T}: Add {W}{U}.`: two mana for {1}, see Game::payFor().
        if (preg_match('/^\{1\}, \{T\}: Add \{([WUBRGC])\}\{([WUBRGC])\}\.$/', $line, $match)) {
            $two = ['count' => 2, 'colors' => array_values(array_unique([$match[1], $match[2]])), 'fixed' => [[$match[1]], [$match[2]]]];
            if ($result['mana'] === null) {
                $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'filterAs' => $two];
            } elseif (($result['mana']['filter'] ?? false) || ($result['mana']['sacrifice'] ?? false) || isset($result['mana']['fixed'])) {
                return false;
            } else {
                $result['mana'] += ['filter' => true, 'filterAs' => $two];
            }

            return true;
        }
        // A filter land's `{W/U}, {T}: Add {W}{W}, {W}{U}, or {U}{U}.`: like a Signet, its cost paid with either color.
        if (preg_match('/^\{([WUBRG])\/([WUBRG])\}, \{T\}: Add \{(\w)\}\{(\w)\}, \{(\w)\}\{(\w)\}, or \{(\w)\}\{(\w)\}\.$/', $line, $match)
            && [$match[3], $match[4], $match[5], $match[6], $match[7], $match[8]] === [$match[1], $match[1], $match[1], $match[2], $match[2], $match[2]]) {
            $pair = [$match[1], $match[2]];
            $two = ['count' => 2, 'colors' => $pair, 'fixed' => [$pair, $pair]];
            if ($result['mana'] === null) {
                $result['mana'] = ['count' => 0, 'colors' => ['C'], 'filter' => true, 'filterAs' => $two, 'filterPays' => $pair];
            } elseif (($result['mana']['filter'] ?? false) || ($result['mana']['sacrifice'] ?? false) || isset($result['mana']['fixed'])) {
                return false;
            } else {
                $result['mana'] += ['filter' => true, 'filterAs' => $two, 'filterPays' => $pair];
            }

            return true;
        }
        // Elvish Archdruid: `{T}: Add {G} for each Elf you control.`; see Game::manaSources().
        if (preg_match('/^\{T\}: Add \{([WUBRGC])\} for each (creature|artifact|[A-Z][a-z]+) you control\.$/', $line, $match) && $result['mana'] === null
            && ($kind = $match[2] === 'creature' ? 'Creature' : ($match[2] === 'artifact' ? 'Artifact' : self::subtypeOf($match[2]))) !== null) {
            $result['mana'] = ['count' => 1, 'colors' => [$match[1]], 'same' => true, 'countPer' => $kind];

            return true;
        }
        // Topiary Lecturer: `{T}: Add an amount of {G} equal to CARDNAME's power.`; see Game::manaSources().
        if (preg_match("/^\{T\}: Add an amount of \{([WUBRGC])\} equal to CARDNAME's power\.$/", $line, $match) && $result['mana'] === null) {
            $result['mana'] = ['count' => 1, 'colors' => [$match[1]], 'same' => true, 'powerCount' => true];

            return true;
        }
        // Springleaf Drum: `{T}, Tap an untapped creature you control: Add one mana of any color.`; see Game::manaSources().
        if (preg_match('/^\{T\}, Tap an untapped creature you control: Add one mana of any colou?r\.$/', $line)) {
            $any = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
            if ($result['mana'] === null) {
                $result['mana'] = $any + ['tapCreature' => true];
            } elseif ($result['mana']['count'] === 1 && ! isset($result['mana']['withCreature']) && array_diff(array_keys($result['mana']), ['count', 'colors']) === []) {
                // Scene of the Crime: `{T}: Add {C}.` too, so either.
                $result['mana']['withCreature'] = $any;
            } else {
                return false;
            }

            return true;
        }
        if (! preg_match('/^\{T\}(, Pay 1 life|, Sacrifice CARDNAME|, Remove an? (?:charge|storage|oil) counter from CARDNAME)?: Add (.+?)\.?(?: CARDNAME deals 1 damage to you\.)?$/', $line, $match)) {
            return false;
        }
        $sacrifice = ($match[1] ?? '') === ', Sacrifice CARDNAME';
        // `{T}, Remove a charge counter from CARDNAME: Add one mana of any color.`: see Game::manaSources().
        $counter = preg_match('/Remove an? (\w+) counter/', $match[1] ?? '', $c) ? $c[1] : null;
        $plain = fn (?array $mana) => $mana !== null && $mana['count'] === 1 && array_diff(array_keys($mana), ['count', 'colors']) === [];
        if ($counter !== null && $result['mana'] !== null && ! $plain($result['mana'])) {
            return false;
        }
        $pain = (($match[1] ?? '') !== '' && ! $sacrifice && $counter === null) || str_ends_with($line, 'deals 1 damage to you.');
        $what = $match[2];
        $ability = null;
        if (preg_match('/^one mana of any colou?r$/i', $what)) {
            $ability = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G']];
        } elseif (preg_match('/^(two|three|four|five) mana of any one colou?r$/i', $what, $n)) {
            // Gilded Lotus: all of one color; see ManaPayer::plan().
            $ability = ['count' => self::amount($n[1]), 'colors' => ['W', 'U', 'B', 'R', 'G'], 'same' => true];
        } elseif (preg_match('/^(two|three) mana in any combination of (colou?rs|\{[WUBRG]\} and\/or \{[WUBRG]\})$/i', $what, $n)) {
            // `Add two mana in any combination of colors.`, `… of {R} and/or {G}.`: each one of them; see ManaPayer::plan().
            preg_match_all('/\{([WUBRG])\}/', $n[2], $symbols);
            $ability = ['count' => self::amount($n[1]), 'colors' => $symbols[1] !== [] ? array_values(array_unique($symbols[1])) : ['W', 'U', 'B', 'R', 'G']];
        } elseif (preg_match('/^one mana of the chosen color$/i', $what) && $result['chooses'] === 'color') {
            // See Game::manaSources().
            $ability = ['count' => 1, 'colors' => ['W', 'U', 'B', 'R', 'G'], 'chosen' => true];
        } elseif (preg_match('/^(\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            $ability = count(array_unique($symbols[1])) === 1
                ? ['count' => count($symbols[1]), 'colors' => [$symbols[1][0]]]
                // A bounce land's `{W}{U}`: one of each.
                : ['count' => count($symbols[1]), 'colors' => array_values(array_unique($symbols[1])), 'fixed' => array_map(fn (string $color) => [$color], $symbols[1])];
        } elseif (preg_match('/^\{[WUBRGC]\}(?:,? (?:or )?\{[WUBRGC]\})+$/', $what)) {
            preg_match_all('/\{([WUBRGC])\}/', $what, $symbols);
            $ability = ['count' => 1, 'colors' => array_values(array_unique($symbols[1]))];
        }
        if ($ability === null) {
            return false;
        }
        if ($pain) {
            $ability['pain'] = $ability['colors'];
        }
        if ($counter !== null) {
            // Vivid Crag / Tendo Ice Bridge: `{T}: Add {R}.` as well, so the counter is only spent for the other colors.
            $result['mana'] = $result['mana'] !== null && $ability['count'] === 1 && ! isset($ability['pain'])
                ? $result['mana'] + ['withCounter' => [$counter, $ability['colors']]]
                : $ability + ['counter' => $counter];

            return true;
        }
        if ($sacrifice) {
            // A Treasure: one use.
            if ($result['mana'] !== null) {
                return false;
            }
            $result['mana'] = $ability + ['sacrifice' => true];

            return true;
        }

        $existing = $result['mana'];
        if (($existing['tapCreature'] ?? false) && $ability['count'] === 1 && array_diff(array_keys($ability), ['count', 'colors']) === []) {
            // The creature-tapping ability came first.
            $result['mana'] = $ability + ['withCreature' => array_diff_key($existing, ['tapCreature' => true])];

            return true;
        }
        if ($existing !== null && (isset($existing['tapCreature']) || isset($existing['withCreature']))) {
            return false;
        }
        if ($existing !== null && $existing['count'] === 0) {
            // The filter ability came first.
            $ability += array_intersect_key($existing, ['filter' => true, 'filterAs' => true, 'filterPays' => true]);
            $existing = null;
        }
        if ($existing === null) {
            $result['mana'] = $ability;

            return true;
        }
        if (isset($existing['fixed']) || isset($ability['fixed']) || isset($existing['counter'])) {
            return false;
        }
        // Two abilities of one mana each: one source of either, the painless colors first.
        if ($existing['count'] !== 1 || $ability['count'] !== 1) {
            return false;
        }
        $pain = array_values(array_unique([...($existing['pain'] ?? []), ...($ability['pain'] ?? [])]));
        $colors = array_values(array_unique([...$existing['colors'], ...$ability['colors']]));
        // A color one ability makes without pain is never painful.
        $painless = array_values(array_unique([...array_diff($existing['colors'], $existing['pain'] ?? []), ...array_diff($ability['colors'], $ability['pain'] ?? [])]));
        $pain = array_values(array_diff($pain, $painless));
        usort($colors, fn (string $a, string $b) => in_array($a, $pain, true) <=> in_array($b, $pain, true));
        $result['mana'] = ['count' => 1, 'colors' => $colors] + ($pain === [] ? [] : ['pain' => $pain]) + (($existing['filter'] ?? false) ? ['filter' => true] : []);

        return true;
    }

    private static function entersTapped(string $line, array &$result): bool
    {
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped\.?$/', $line)) {
            $result['entersTapped'] = true;

            return true;
        }
        // "As CARDNAME enters, choose a creature type." (rule 614.12): chosen as it enters.
        if (preg_match('/^(CARDNAME enters(?: the battlefield)? tapped\. )?As (?:CARDNAME|it) enters(?: the battlefield)?, choose a (creature type|color)\.?$/', $line, $match)) {
            $result['entersTapped'] = $result['entersTapped'] || $match[1] !== '';
            $result['chooses'] = $match[2] === 'color' ? 'color' : 'type';

            return true;
        }
        if (preg_match('/^As CARDNAME enters(?: the battlefield)?, you may pay (\d+) life\. If you don\'t, it enters(?: the battlefield)? tapped\.?$/', $line, $match)) {
            $result['tappedUnless'] = ['life' => (int) $match[1]];

            return true;
        }
        // Ancient Amphitheater: `As CARDNAME enters, you may reveal a Giant card from your hand. If you don't, CARDNAME enters tapped.`
        if (preg_match('/^As CARDNAME enters(?: the battlefield)?, you may reveal an? ([A-Z][a-z]+) card from your hand\. If you don\'t, (?:CARDNAME|it) enters(?: the battlefield)? tapped\.?$/', $line, $match)) {
            $result['tappedUnless'] = ['reveal' => $match[1]];

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped unless a player has (\d+) or less life\.?$/', $line, $match)) {
            $result['tappedUnless'] = ['player_life' => (int) $match[1]];

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? tapped unless you control (.+?)\.?$/', $line, $match) && ($unless = self::tappedUnless($match[1])) !== null) {
            $result['tappedUnless'] = $unless;

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) -1\/-1 counters? on it\.?$/', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['minusCounters'] = $n;

            return true;
        }
        // Vivid lands: `This land enters tapped with two charge counters on it.`
        if (preg_match('/^CARDNAME enters(?: the battlefield)?( tapped)? with (\w+) (oil|charge|time|lore|loyalty|verse|fade|ice|age|quest|study|storage|page|shield) counters? on it\.?$/', $line, $match) && ($n = self::amount($match[2])) !== null) {
            $result['otherCounters'][$match[3]] = $n;
            $result['entersTapped'] = $result['entersTapped'] || $match[1] !== '';

            return true;
        }
        // Chalice of the Void: `Whenever a player casts a spell with mana value equal to the number of charge counters on CARDNAME, counter that spell.`; see Game::castTriggers().
        if ($line === 'Whenever a player casts a spell with mana value equal to the number of charge counters on CARDNAME, counter that spell.') {
            $result['keywords'][] = 'chalice';

            return true;
        }
        // Raid: `CARDNAME enters with a +1/+1 counter on it if you attacked this turn.`; see Game::resolve().
        if (preg_match('/^Raid — CARDNAME enters(?: the battlefield)? with (an?|\w+) \+1\/\+1 counters? on it if you attacked this turn\.?$/u', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['keywords'][] = "raid counters {$n}";

            return true;
        }
        // `CARDNAME enters with two +1/+1 counters on it if you control another Elf.`: checked as it enters; see Game::resolve().
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (an?|\w+)(?: additional)? \+1\/\+1 counters? on it if (.+?)\.?$/u', $line, $match) && is_int($n = self::amount($match[1]))
            && ($if = self::staticCondition($match[2])) !== null && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)) {
            $result['keywords'][] = "enters counters {$n} if {$if}";

            return true;
        }
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with (\w+) \+1\/\+1 counters? on it\.?$/', $line, $match) && ($n = self::amount($match[1])) !== null) {
            $result['counters'] = $n;

            return true;
        }
        // `CARDNAME enters with a +1/+1 counter on it for each other creature you control.`: counted as it enters; see Game::perCount().
        if (preg_match('/^CARDNAME enters(?: the battlefield)? with an? \+1\/\+1 counter on it for each (?:(other creature|creature|artifact|land|enchantment|[A-Z][a-z]+) you control|(creature |artifact |land |instant |sorcery )?(card) in your (graveyard|hand)|(opponent) you have|(creature that died this turn))\.?$/', $line, $match)
            && ($per = ($match[6] ?? '') !== '' ? 'died' : self::per([null, null, $match[1], $match[2] ?? '', $match[3] ?? '', $match[4] ?? '', $match[5] ?? ''])) !== null) {
            $result['counters'] = "per:{$per}";

            return true;
        }
        if (in_array('multikicker', $result['keywords'], true) && preg_match('/^CARDNAME enters(?: the battlefield)? with an? \+1\/\+1 counter on it for each time it was kicked\.?$/', $line)) {
            $result['kickerCounters'] = 1;

            return true;
        }
        if ($result['kicker'] !== null && preg_match('/^If CARDNAME was kicked, it enters(?: the battlefield)? with (\w+) (?:additional )?\+1\/\+1 counters? on it\.?$/', $line, $match) && is_int($n = self::amount($match[1]))) {
            $result['kickerCounters'] = $n;

            return true;
        }

        return false;
    }

    /**
     * `Creatures you control get +1/+1.`, `Other creatures you control get
     * +1/+0 and have haste.`: a static bonus to its controller's creatures.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function anthem(string $line, array &$result): bool
    {
        if (preg_match('/^Creatures enchanted player controls get ([+-]\d+)\/([+-]\d+)\.?$/', $line, $m) && in_array($result['aura']['enchant'] ?? null, ['player', 'opponent'], true)) {
            $result['anthem'][] = ['power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => [], 'other' => false, 'enchantedPlayer' => true];

            return true;
        }
        // `Creatures your opponents control get -1/-1.` (see Game::anthems()).
        if (preg_match('/^Creatures your opponents control get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?\.?$/', $line, $m) && ($keywords = self::keywordList($m[3] ?? '')) !== null) {
            $result['anthem'][] = ['power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'other' => false, 'opponents' => true];

            return true;
        }
        // Soulbond (rule 702.95): `As long as CARDNAME is paired with another creature, each of those creatures gets +1/+1 (and has flying).`
        if (preg_match('/^As long as CARDNAME is paired with another creature, (?:each of those creatures|both creatures) (?:gets? \+(\d+)\/\+(\d+)(?: and ha(?:s|ve) (.+?))?|ha(?:s|ve) (.+?))\.?$/', $line, $m)) {
            if (($keywords = self::keywordList(($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''))) === null) {
                return false;
            }
            $result['anthem'][] = ['power' => (int) ($m[1] ?? 0), 'toughness' => (int) ($m[2] ?? 0), 'keywords' => $keywords, 'other' => false, 'paired' => true];

            return true;
        }
        // `Each creature you control with a +1/+1 counter on it has trample.`
        // `Each creature you control with a counter on it has trample.`: any kind of counter.
        if (preg_match('/^(?:Each (other )?creature you control with an? (\+1\/\+1 )?counter on it has|(Other )?[Cc]reatures you control with (\+1\/\+1 )?counters on them have) (.+?)\.?$/', $line, $m)) {
            if (($keywords = self::keywordList($m[5])) === null) {
                return false;
            }
            $result['anthem'][] = ['power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'other' => $m[1] !== '' || $m[3] !== '', 'withCounter' => $m[2] !== '' || $m[4] !== '' ? true : 'any'];

            return true;
        }
        // `During your turn, CARDNAME gets +2/+0.` and `As long as …, CARDNAME has flying.`: the same, condition last.
        // `As long as CARDNAME has a +1/+1 counter on it, it has flying.`: the condition about itself as `it …`.
        if (preg_match('/^(?:([A-Z][a-z]+(?: [a-z]+)? — ))?(?:During your turn|As long as (.+?)), ((?:CARDNAME|it) (?:gets|has) .+?)\.$/u', $line, $m)) {
            $condition = (string) preg_replace(['/^CARDNAME is /', '/^CARDNAME has /'], ["it's ", 'it has '], $m[2]);
            $line = $m[1].preg_replace('/^it /', 'CARDNAME ', $m[3]).' as long as '.($m[2] !== '' ? $condition : "it's your turn").'.';
        }
        // `Creatures you control get +1/+0 as long as it's your turn.` and `During your turn, other creatures you control have first strike.`:
        // a condition about the game or the controller, not the creature.
        $line = (string) preg_replace('/^During your turn, ((?:other )?creatures you control (?:get|have) .+?)\.$/', "\$1 as long as it's your turn.", $line);
        if (preg_match('/^(?:[A-Z][a-z]+(?: [a-z]+)? — )?((?:[Oo]ther )?)[Cc]reatures you control (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?)) as long as (.+?)\.$/u', ucfirst($line), $m)
            && ($keywords = self::keywordList(($m[5] ?? '') !== '' ? $m[5] : ($m[4] ?? ''))) !== null && ($if = self::staticCondition($m[6])) !== null
            && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)) {
            $result['anthem'][] = ['power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'other' => $m[1] !== '', 'if' => $if];

            return true;
        }
        // Metalcraft, Threshold: `CARDNAME gets +2/+2 as long as you control three or more artifacts.`; see Game::conditionMet().
        if (preg_match('/^(?:[A-Z][a-z]+(?: [a-z]+)? — )?CARDNAME gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))? as long as (.+?)\.$/u', $line, $m)
            && ($keywords = self::keywordList($m[3] ?? '')) !== null && ($if = self::staticCondition($m[4])) !== null) {
            $result['anthem'][] = ['power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'other' => false, 'self' => true, 'if' => $if];

            return true;
        }
        // `CARDNAME has hexproof as long as it's untapped.`
        if (preg_match('/^(?:[A-Z][a-z]+(?: [a-z]+)? — )?CARDNAME has (.+?) as long as (.+?)\.$/u', $line, $m)
            && ($keywords = self::keywordList($m[1])) !== null && ($if = self::staticCondition($m[2])) !== null) {
            $result['anthem'][] = ['power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'other' => false, 'self' => true, 'if' => $if];

            return true;
        }
        // `Other green creatures you control get +1/+1.` (the Lieges), `Attacking creatures you control get +1/+0.` (Goblin Oriflamme) and `Equipped creatures you control have vigilance.`; see Game::anthems().
        if (preg_match('/^(Other )?(white|blue|black|red|green|[Aa]ttacking|[Aa]rtifact|[Ee]quipped|[Ee]nchanted) creatures you control (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)) {
            $keywords = self::keywordList(($m[6] ?? '') !== '' ? $m[6] : ($m[5] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $which = in_array(strtolower($m[2]), ['attacking', 'artifact', 'equipped', 'enchanted'], true) ? [strtolower($m[2]) => true] : ['color' => ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'][$m[2]]];
            $result['anthem'][] = ['power' => (int) ($m[3] ?? 0), 'toughness' => (int) ($m[4] ?? 0), 'keywords' => $keywords, 'other' => $m[1] !== ''] + $which;

            return true;
        }
        // `Creature tokens you control get +1/+1.` and `Nontoken creatures you control have vigilance.`; see Game::anthems().
        if (preg_match('/^(Other )?([Cc]reature tokens|[Nn]ontoken creatures) you control (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)) {
            $keywords = self::keywordList(($m[6] ?? '') !== '' ? $m[6] : ($m[5] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $result['anthem'][] = ['power' => (int) ($m[3] ?? 0), 'toughness' => (int) ($m[4] ?? 0), 'keywords' => $keywords, 'other' => $m[1] !== '', 'token' => strtolower($m[2]) === 'creature tokens'];

            return true;
        }
        // The Slivers: `All Sliver creatures get +1/+1.` and `All Slivers have haste.`, every player's; see Game::anthems().
        if (preg_match('/^All ([A-Z][a-z]+)(?: creatures)? (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)
            && ($type = self::subtypeOf($m[1])) !== null && ($keywords = self::keywordList(($m[5] ?? '') !== '' ? $m[5] : ($m[4] ?? ''))) !== null) {
            $result['anthem'][] = ['power' => (int) ($m[2] ?? 0), 'toughness' => (int) ($m[3] ?? 0), 'keywords' => $keywords, 'other' => false, 'subtype' => $type, 'everyone' => true];

            return true;
        }
        // `Each other creature you control that's a Wizard gets +1/+1.`: read as `Other Wizard creatures you control get +1/+1.`
        if (preg_match("/^Each (other )?creature you control that's an? ([A-Z][a-z]+) (gets|has) (.+)$/", $line, $m)) {
            $line = ($m[1] !== '' ? 'Other ' : '')."{$m[2]} creatures you control ".($m[3] === 'gets' ? 'get' : 'have')." {$m[4]}";
        }
        // A lord: `Other Kithkin you control get +1/+1.`, `Elf creatures you control get +1/+1 and have trample.`
        if (preg_match('/^(Other )?([A-Z][a-z]+(?:-[a-z]+)?)(?: creatures)? you control (?:get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?|have (.+?))\.?$/', $line, $m)
            && ($type = self::subtypeOf($m[2])) !== null) {
            $keywords = self::keywordList(($m[6] ?? '') !== '' ? $m[6] : ($m[5] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $result['anthem'][] = ['power' => (int) ($m[3] ?? 0), 'toughness' => (int) ($m[4] ?? 0), 'keywords' => $keywords, 'other' => $m[1] !== '', 'subtype' => $type];

            return true;
        }
        // `Other creatures you control with flying get +1/+1.`
        if (preg_match('/^(Other )?[Cc]reatures you control with (flying|haste|trample|vigilance|deathtouch|lifelink) get ([+-]\d+)\/([+-]\d+)\.?$/', $line, $m)) {
            $result['anthem'][] = ['power' => (int) $m[3], 'toughness' => (int) $m[4], 'keywords' => [], 'other' => $m[1] !== '', 'withKeyword' => $m[2]];

            return true;
        }
        if (preg_match('/^(Other )?[Cc]reatures you control( of the chosen type)? get ([+-]\d+)\/([+-]\d+)(?: and have (.+?))?\.?$/', $line, $m)) {
            [$power, $toughness, $keywords] = [(int) $m[3], (int) $m[4], self::keywordList($m[5] ?? '')];
        } elseif (preg_match('/^(Other )?[Cc]reatures you control( of the chosen type)? have (.+?)\.?$/', $line, $m)) {
            [$power, $toughness, $keywords] = [0, 0, self::keywordList($m[3])];
        } else {
            return false;
        }
        if ($keywords === null || ($m[2] !== '' && $result['chooses'] !== 'type')) {
            return false;
        }
        $result['anthem'][] = ['power' => $power, 'toughness' => $toughness, 'keywords' => $keywords, 'other' => $m[1] !== ''] + ($m[2] !== '' ? ['chosenType' => true] : []);

        return true;
    }

    /**
     * A condition of a static ability, as Game::conditionMet() reads it:
     * `you control three or more artifacts`, `seven or more cards are in
     * your graveyard`, `you have 25 or more life`, `it's your turn`.
     *
     * @param string $text
     *
     * @return ?string
     */
    private static function staticCondition(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/^you control (\w+) or more (artifacts|lands|enchantments)$/', $text, $m) && is_int($n = self::amount($m[1])) => rtrim($m[2], 's')."s {$n}",
            (bool) preg_match('/^you control an? (artifact|enchantment)$/', $text, $m) => "{$m[1]}s 1",
            (bool) preg_match('/^(?:(\w+) or more cards are in your graveyard|there are (\w+) or more cards in your graveyard)$/', $text, $m) && is_int($n = self::amount($m[1] !== '' ? $m[1] : $m[2])) => "graveyard {$n}",
            $text === "it's untapped" => 'untapped',
            $text === "it's tapped" => 'tapped',
            (bool) preg_match('/^you have (\w+) or more life$/', $text, $m) && is_int($n = self::amount($m[1])) => "life {$n}",
            $text === "it's your turn" => 'your_turn',
            $text === "it's night" => 'night',
            $text === "it's day" => 'day',
            $text === "it's attacking" => 'attacking',
            // Kird Ape: `as long as you control a Forest`.
            (bool) preg_match('/^you control an? ([A-Z][a-z]+)$/', $text, $m) => "controls {$m[1]}",
            // `as long as you control another Elf`: not counting itself.
            (bool) preg_match('/^you control another ([A-Z][a-z]+|creature|artifact|enchantment)$/', $text, $m) && ($type = ucfirst($m[1])) !== '' => 'controls_other '.(self::subtypeOf($type) ?? $type),
            $text === "it's not your turn" => 'not_your_turn',
            (bool) preg_match('/^you have (\w+) or fewer cards in hand$/', $text, $m) && is_int($n = self::amount($m[1])) => "hand_le {$n}",
            (bool) preg_match('/^there are (\w+) or more card types among cards in your graveyard$/', $text, $m) && is_int($n = self::amount($m[1])) => "delirium {$n}",
            // Descend 4: `as long as there are four or more permanent cards in your graveyard`.
            (bool) preg_match('/^there are (\w+) or more (permanent|creature|land|artifact|enchantment) cards in your graveyard$/', $text, $m) && is_int($n = self::amount($m[1])) => "gy_{$m[2]} {$n}",
            (bool) preg_match('/^you control (\w+) or more creatures$/', $text, $m) && is_int($n = self::amount($m[1])) => "creatures {$n}",
            (bool) preg_match('/^you have (\w+) or more cards in your graveyard$/', $text, $m) && is_int($n = self::amount($m[1])) => "graveyard {$n}",
            (bool) preg_match('/^you have (\w+) or more cards in hand$/', $text, $m) && is_int($n = self::amount($m[1])) => "hand_ge {$n}",
            $text === 'you have no cards in hand' => 'hand_le 0',
            (bool) preg_match('/^an opponent has (\w+) or less life$/', $text, $m) && is_int($n = self::amount($m[1])) => "opp_life_le {$n}",
            $text === 'you gained life this turn' => 'gained 1',
            $text === 'you attacked this turn' => 'attacked',
            $text === 'a creature died this turn' => 'died 1',
            $text === 'an opponent lost life this turn' => 'opponent_lost',
            $text === 'it has a +1/+1 counter on it' => 'has_counter',
            // `As long as CARDNAME is equipped, it has first strike.`: an Aura or Equipment attached to it.
            $text === "it's enchanted" => 'enchanted',
            $text === "it's equipped" => 'equipped',
            $text === 'you control no other creatures' => 'alone',
            // `as long as you have 5 or less life`, `… you've cast two or more spells this turn`, `… you have more life than each opponent`.
            (bool) preg_match('/^you have (\w+) or less life$/', $text, $m) && is_int($n = self::amount($m[1])) => "life_le {$n}",
            (bool) preg_match('/^you(?:\'ve)? cast (\w+) or more spells this turn$/', $text, $m) && is_int($n = self::amount($m[1])) => "spells_cast {$n}",
            $text === 'two or more nonland permanents entered the battlefield under your control this turn' => 'celebration',
            $text === 'you have more life than each opponent' => 'life_lead',
            $text === 'an opponent has more life than you' => 'life_behind',
            // `as long as an opponent controls more creatures than you`: see Game::conditionMet().
            $text === 'an opponent controls more creatures than you' => 'creatures_behind',
            $text === 'an opponent controls more lands than you' => 'lands_behind',
            (bool) preg_match('/^you control no other (artifacts|enchantments)$/', $text, $m) => 'no_other '.ucfirst(rtrim($m[1], 's')),
            default => null,
        };
    }

    /**
     * A creature type as a lord names it, singular: `Elves` is `Elf`,
     * `Kithkin` stays. Null for words that are not a creature type.
     *
     * @param string $word
     *
     * @return string|null
     */
    /**
     * The count a `for each …` or `where X is the number of …` tail names, for Game::perCount().
     *
     * @param array $m Groups 2 (permanents you control), 3 and 4 (a card type and `card`), 5 (hand or graveyard) and 6 (opponents).
     *
     * @return string|null
     */
    private static function per(array $m): ?string
    {
        $kind = rtrim(strtolower($m[2] ?? ''), 's') === 'other creature' ? 'other creature' : rtrim($m[2] ?? '', 's');

        return match (true) {
            ($m[6] ?? '') !== '' => 'opponents',
            // `each opponent loses 1 life for each attacking creature`: counted as it resolves.
            ($m[7] ?? '') !== '' => 'attacking',
            ($m[5] ?? '') === 'hand' => ($m[3] ?? '') === '' ? 'hand' : null,
            ($m[5] ?? '') === 'graveyard' => 'gy:'.(trim($m[3] ?? '') === '' ? 'card' : trim($m[3])),
            in_array(strtolower($kind), ['other creature', 'creature', 'artifact', 'land', 'enchantment'], true) => 'yours:'.strtolower($kind),
            // `for each other Elf you control`: see Game::countYours().
            (bool) preg_match('/^other ([A-Z][a-z]+)$/', $m[2] ?? '', $other) && ($type = self::subtypeOf($other[1])) !== null => 'yours:other sub:'.$type,
            $kind !== '' && ($type = self::subtypeOf($m[2])) !== null => 'yours:sub:'.$type,
            default => null,
        };
    }

    /**
     * `Equipped (Enchanted) creature gets +1/+1 for each artifact you control.`: the bonus for each, and what to count.
     *
     * @param string $line
     * @param string $word `Enchanted` or `Equipped`.
     *
     * @return array{perPower: int, perToughness: int, per: string}|null
     */
    private static function attachedPer(string $line, string $word): ?array
    {
        // `… for each creature card in your graveyard.` and `… for each card in your hand.` count the same way.
        // `Enchanted creature gets +1/+1 for each Aura attached to it.`: counted on the creature; see Game::perCount().
        if (preg_match('/^'.$word.' creature gets \+(\d+)\/\+(\d+) for each (Aura|Equipment|Aura and Equipment) attached to it\.?$/', $line, $m)) {
            return ['perPower' => (int) $m[1], 'perToughness' => (int) $m[2], 'per' => 'attached:'.str_replace(' and ', '|', $m[3])];
        }
        if (! preg_match('/^'.$word.' creature gets \+(\d+)\/\+(\d+) for each (?:(other creature|creature|artifact|land|enchantment|[A-Z][a-z]+) you control|(creature |artifact |land |instant |sorcery )?card in your (graveyard|hand))\.?$/', $line, $m)) {
            return null;
        }
        $per = self::per([null, null, $m[3], $m[4] ?? '', 'card', $m[5] ?? '']);

        return $per === null ? null : ['perPower' => (int) $m[1], 'perToughness' => (int) $m[2], 'per' => $per];
    }

    private static function subtypeOf(string $word): ?string
    {
        if (in_array($word, ['Other', 'Creatures', 'Artifact', 'Artifacts', 'Enchantment', 'Legendary', 'Token', 'Tokens', 'Nontoken', 'Attacking', 'Blocking', 'Tapped', 'Untapped', 'White', 'Blue', 'Black', 'Red', 'Green', 'Colorless', 'Multicolored', 'Monocolored', 'Snow', 'Land', 'Lands', 'Permanents', 'Equipped', 'Enchanted'], true)) {
            return null;
        }

        return match (true) {
            isset(['Elves' => 1, 'Wolves' => 1, 'Dwarves' => 1][$word]) => substr($word, 0, -3).'f',
            in_array($word, ['Allies', 'Mercenaries'], true) => substr($word, 0, -3).'y',
            str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }

    /**
     * `As an additional cost to cast this spell, sacrifice a creature.`,
     * `… discard a card.` or `… exile a creature card from your graveyard.`
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function additionalCost(string $line, array &$result): bool
    {
        // Collect evidence (rule 701.59): `As an additional cost to cast CARDNAME, you may collect evidence 6.`; reads as kicked (see Game::evidence()).
        if (preg_match('/^As an additional cost to cast CARDNAME, you may collect evidence (\d+)\.$/', $line, $m)) {
            $result['altCosts']['evidence'] = $m[1];

            return true;
        }
        // Dragons of Tarkir: `As an additional cost to cast CARDNAME, you may reveal a Dragon card from your hand.`; revealed whenever one can be (see Game::cast()).
        if (preg_match('/^As an additional cost to cast CARDNAME, you may reveal an? ([A-Z][a-z]+) card from your hand\.$/', $line, $m)) {
            $result['keywords'][] = "reveal {$m[1]}";

            return true;
        }
        // Behold (Tarkir: Dragonstorm): `As an additional cost to cast CARDNAME, you may behold a Dragon.`, a Dragon card in your hand or a Dragon you control; reads as the reveal above.
        if (preg_match('/^As an additional cost to cast CARDNAME, you may behold an? ([A-Z][a-z]+)\.$/', $line, $m)) {
            array_push($result['keywords'], "reveal {$m[1]}", 'reveal or control');

            return true;
        }
        // `As an additional cost to cast CARDNAME, behold a Dragon or pay {2}.`; see Game::orPay().
        if (preg_match('/^As an additional cost to cast CARDNAME, behold an? ([A-Z][a-z]+) or pay ((?:\{[^}]+\})+)\.$/', $line, $m)) {
            array_push($result['keywords'], "reveal or pay {$m[1]}", "or pay {$m[2]}", 'reveal or control');

            return true;
        }
        // Lorwyn: `As an additional cost to cast CARDNAME, reveal an Elemental card from your hand or pay {3}.`; see Game::orPay().
        if (preg_match('/^As an additional cost to cast CARDNAME, reveal an? ([A-Z][a-z]+) card from your hand or pay ((?:\{[^}]+\})+)\.$/', $line, $m)) {
            array_push($result['keywords'], "reveal or pay {$m[1]}", "or pay {$m[2]}");

            return true;
        }
        if (! preg_match('/^As an additional cost to cast CARDNAME, (sacrifice a creature|sacrifice an artifact or creature|sacrifice an artifact|sacrifice a land|discard a card|exile a creature card from your graveyard|exile a card from your graveyard)(?: or pay ((?:\{[^}]+\})+))?\.?$/', $line, $m)) {
            return false;
        }
        if (isset($m[2])) {
            $result['keywords'][] = "or pay {$m[2]}";
        }
        $result['additionalCost'] = match ($m[1]) {
            'sacrifice a creature' => 'sacrifice_creature',
            'sacrifice an artifact or creature' => 'sacrifice_artifact_or_creature',
            'sacrifice an artifact' => 'sacrifice_artifact',
            'sacrifice a land' => 'sacrifice_land',
            'exile a creature card from your graveyard' => 'exile_creature_card',
            'exile a card from your graveyard' => 'exile_card',
            default => 'discard',
        };

        return true;
    }

    /**
     * What a land needs you to control to enter untapped: `two or more other
     * lands`, `two or fewer other lands`, `a Forest or an Island`, `a basic
     * land`, `a planeswalker`, `a Mount or Vehicle` …
     *
     * @param string $text
     *
     * @return array{lands_min?: int, lands_max?: int, any?: string[]}|null
     */
    private static function tappedUnless(string $text): ?array
    {
        if (preg_match('/^(\w+) or (more|fewer) other lands$/', $text, $match) && is_int($n = self::amount($match[1]))) {
            return [$match[2] === 'more' ? 'lands_min' : 'lands_max' => $n];
        }
        // The Battlebond lands: `… unless you control two or more basic lands.`
        if (preg_match('/^(\w+) or more basic lands$/', $text, $match) && is_int($n = self::amount($match[1]))) {
            return ['basics_min' => $n];
        }
        $any = [];
        foreach (preg_split('/,? or |, /', $text) as $noun) {
            $noun = preg_replace('/^an? /', '', trim($noun));
            if (! preg_match('/^(basic land|legendary creature|[A-Za-z]+)$/', $noun)) {
                return null;
            }
            $any[] = $noun;
        }

        return $any === [] ? null : ['any' => $any];
    }

    /**
     * `can't attack or block, and its activated abilities can't be
     * activated` and the like, as {@see CardDefinition::RESTRICTIONS}.
     *
     * @param string $text
     *
     * @return string[]|null
     */
    private static function restrictions(string $text): ?array
    {
        $text = rtrim(trim($text), '.');
        $found = [];
        if (preg_match('/^(.*?),? and its activated abilities can\'t be activated$/', $text, $match)) {
            $found[] = "abilities can't be activated";
            $text = $match[1];
        }
        if (preg_match('/^(.*?),? and (can\'t be blocked by creatures with power \d+ or (?:greater|less)|can block only creatures with flying)$/', $text, $match)) {
            $text = $match[1];
            $found = [...$found, ...(self::restrictions($match[2]) ?? [null])];
        }
        // `can't attack unless you control another creature`, `can't block unless you control a Dragon`: read by Game::attackAllowedByLands() and canBlock().
        if (preg_match('/^can\'t (attack|block|attack or block) unless you control (another|an?) (artifact|creature|enchantment|[A-Z][a-z]+)$/', $text, $match)
            && (in_array($match[3], ['artifact', 'creature', 'enchantment'], true) || self::subtypeOf($match[3]) !== null)) {
            $kind = in_array($match[3], ['artifact', 'creature', 'enchantment'], true) ? ucfirst($match[3]) : self::subtypeOf($match[3]);
            $needs = ($match[2] === 'another' ? 'another ' : '').$kind;

            return [...$found, ...array_map(fn (string $what) => "can't {$what} unless you control {$needs}", $match[1] === 'attack or block' ? ['attack', 'block'] : [$match[1]])];
        }
        // `can't be blocked as long as defending player controls an artifact`; a basic land type is landwalk (rule 702.14). Read by Game::canBlock().
        if (preg_match('/^can\'t be blocked as long as defending player controls (?:an? )?(artifact|enchantment|Plains|Island|Swamp|Mountain|Forest|(white|blue|black|red|green) permanent)$/', $text, $match)) {
            return [...$found, match (true) {
                in_array($match[1], ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'], true) => strtolower($match[1]).'walk',
                default => "unblockable if defender controls {$match[1]}",
            }];
        }
        // The Mare cycle: `can't be blocked by black creatures`; read by Game::canBlock().
        if (preg_match('/^can\'t be blocked by (white|blue|black|red|green|artifact) creatures$/', $text, $match)) {
            return [...$found, "can't be blocked by {$match[1]} creatures"];
        }
        // Treetop Rangers: `can't be blocked except by creatures with flying`; `… by creatures with flying` without "except". Read by Game::canBlock().
        if (preg_match('/^can\'t be blocked (except )?by creatures with (flying|reach|defender|flying or reach|horsemanship)$/', $text, $match)) {
            return [...$found, ($match[1] !== '' ? 'blockable only by kw:' : "can't be blocked by kw:").str_replace(' or ', '|', $match[2])];
        }
        // `can't be blocked except by Walls`.
        if (preg_match('/^can\'t be blocked except by ([A-Z][a-z]+s)$/', $text, $match) && ($type = self::subtypeOf($match[1])) !== null) {
            return [...$found, "blockable only by sub:{$type}"];
        }
        // Juggernaut: `can't be blocked by Walls`.
        if (preg_match('/^can\'t be blocked by ([A-Z][a-z]+s)$/', $text, $match) && ($type = self::subtypeOf($match[1])) !== null) {
            return [...$found, "can't be blocked by sub:{$type}"];
        }
        if (preg_match('/^can\'t be blocked by creatures with power (\d+) or (greater|less)$/', $text, $match)) {
            // Read by Game::canBlock().
            return [...$found, "can't be blocked by power {$match[1]} or {$match[2]}"];
        }
        if (preg_match('/^can\'t block creatures with power (\d+) or (greater|less)$/', $text, $match)) {
            // Read by Game::canBlock().
            return [...$found, "can't block power {$match[1]} or {$match[2]}"];
        }
        $found = [...$found, ...match ($text) {
            'can block only creatures with flying' => ['can block only creatures with flying'],
            "can't attack" => ["can't attack"],
            "can't block" => ["can't block"],
            "can't attack or block", "can't attack, block, or crew Vehicles" => ["can't attack", "can't block"],
            "doesn't untap during its controller's untap step", "doesn't untap during your untap step" => ["doesn't untap"],
            "can't be blocked" => ["can't be blocked"],
            "can't be blocked by more than one creature" => ["can't be blocked by more than one creature"],
            // Read by Game::minBlockers().
            "can't be blocked except by two or more creatures" => ['menace'],
            "can't be the target of spells or abilities your opponents control" => ['hexproof'],
            "can't be blocked except by three or more creatures" => ["can't be blocked except by 3 or more creatures"],
            "can't be blocked except by four or more creatures" => ["can't be blocked except by 4 or more creatures"],
            "attacks each combat if able" => ["attacks each combat if able"],
            // Read by Game::declareBlockers().
            'must be blocked if able' => ['must be blocked if able'],
            'can block an additional creature each combat' => ['can block an additional creature each combat'],
            // Read by Game::attackCandidates().
            "can't attack unless defending player controls an Island" => ["can't attack unless defending player controls an Island"],
            "can't attack unless defending player controls a Swamp" => ["can't attack unless defending player controls a Swamp"],
            "can't attack unless defending player controls a Mountain" => ["can't attack unless defending player controls a Mountain"],
            "can't attack unless defending player controls a Forest" => ["can't attack unless defending player controls a Forest"],
            "can't attack unless defending player controls a Plains" => ["can't attack unless defending player controls a Plains"],
            "can't be countered" => ["can't be countered"],
            '' => [],
            default => [null],
        }];

        return in_array(null, $found, true) || $found === [] ? null : $found;
    }

    /**
     * `CARDNAME can't block.` and the like, as keywords.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function restriction(string $line, array &$result): bool
    {
        // The Theros gods: `As long as your devotion to white is less than five, CARDNAME isn't a creature.`; see Game::isCreature().
        if (preg_match("/^As long as your devotion to (white|blue|black|red|green)(?: and (white|blue|black|red|green))? is less than (\\w+), CARDNAME isn't a creature\\.$/", $line, $m) && is_int($n = self::amount($m[3]))) {
            $colors = implode('', array_map(fn (string $color) => self::COLOR_WORDS[$color], array_filter([$m[1], $m[2] ?? ''])));
            $result['keywords'][] = "devotion {$colors} {$n}";

            return true;
        }
        // Lure: every creature able to block it must (see Game::declareBlockers()).
        if (preg_match('/^All creatures able to block CARDNAME do so\.?$/', $line)) {
            $result['keywords'] = array_values(array_unique([...$result['keywords'], 'all creatures able to block it do so']));

            return true;
        }
        if (! preg_match('/^CARDNAME (.+)$/', $line, $match) || ($found = self::restrictions($match[1])) === null) {
            return false;
        }
        $result['keywords'] = array_values(array_unique([...$result['keywords'], ...$found]));

        return true;
    }

    /**
     * A combined Enchant line such as `creature or land`, `red or green creature`
     * or `artifact, creature, or planeswalker you control`, as `any:a|b@you`
     * (see Game::matchesKind()). A single subtype such as `Forest` is `sub_Forest`.
     */
    private static function enchantKind(string $text): ?string
    {
        if (preg_match('/^[A-Z][a-z]+$/', $text)) {
            return 'sub_'.$text;
        }
        $who = '';
        if (preg_match('/^(.+) (you control|an opponent controls|you don\'t control)$/', $text, $m)) {
            [$text, $who] = [$m[1], $m[2] === 'you control' ? '@you' : '@opp'];
        }
        $parts = preg_split('/,? or (?!less|greater)|, /', $text);
        $noun = null;
        $atoms = [];
        foreach (array_reverse($parts) as $part) {
            $atom = self::enchantAtom($part);
            // `red or green creature`: a bare color borrows the noun after it.
            if ($atom === null && $noun !== null && isset(self::COLOR_WORDS[$part])) {
                $atom = $noun === 'creature' ? 'creature_color_'.self::COLOR_WORDS[$part] : null;
            }
            if ($atom === null) {
                return null;
            }
            $noun ??= preg_match('/(creature|land|permanent)$/', $part, $n) ? $n[1] : null;
            $atoms[] = $atom;
        }

        return 'any:'.implode('|', array_reverse($atoms)).$who;
    }

    private const array COLOR_WORDS = ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'];

    /** One kind inside a combined Enchant line, or null when it isn't understood. */
    private static function enchantAtom(string $part): ?string
    {
        $simple = ['creature', 'land', 'artifact', 'enchantment', 'planeswalker', 'permanent', 'battle', 'artifact creature', 'nonbasic land',
            'basic land' => 'basic_land', 'snow land' => 'snow_land', 'legendary creature' => 'legendary_creature', 'modified creature' => 'modified_creature',
            'non-Aura enchantment' => 'nonaura_enchantment', 'nonland permanent' => 'nonland_permanent', 'tapped creature' => 'tapped_creature', 'untapped creature' => 'untapped_creature'];
        foreach ($simple as $key => $value) {
            if ($part === (is_int($key) ? $value : $key)) {
                return str_replace(' ', '_', $value);
            }
        }
        if (preg_match('/^(white|blue|black|red|green) creature$/', $part, $m)) {
            return 'creature_color_'.self::COLOR_WORDS[$m[1]];
        }
        if (preg_match('/^non(white|blue|black|red|green) creature$/', $part, $m)) {
            return 'creature_noncolor_'.self::COLOR_WORDS[$m[1]];
        }
        if (preg_match('/^creature with power (\d+) or (greater|less)$/', $part, $m)) {
            return 'creature_power_'.($m[2] === 'greater' ? 'ge' : 'le').'_'.$m[1];
        }
        if (preg_match('/^creature with mana value (\d+) or less$/', $part, $m)) {
            return 'creature_mv_le_'.$m[1];
        }
        if (preg_match('/^[A-Z][a-z]+$/', $part)) {
            return 'sub_'.$part;
        }

        return null;
    }

    private static function aura(string $line, array &$result): bool
    {
        // `Enchant Forest` or `Enchant Vehicle`: a permanent with that subtype.
        if (preg_match('/^Enchant (.+)$/', $line, $match) && ($kind = self::ENCHANT[strtolower($match[1])] ?? self::enchantKind($match[1])) !== null) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['enchant'] = $kind;

            return true;
        }
        // "You control enchanted creature.": see Game::updateControl().
        if (preg_match('/^You control enchanted (?:creature|permanent|artifact|land|planeswalker)\.?$/', $line)) {
            $result['aura'] ??= ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
            $result['aura']['control'] = true;

            return true;
        }
        // A Zendikon: `Enchanted land is a 2/2 white Knight creature with first strike. It's still a land.`; see Game::isCreature().
        if (($result['aura']['enchant'] ?? null) !== null && preg_match("/^Enchanted land is an? (\\d+)\\/(\\d+) [A-Za-z ]+? creature(?: with (.+?))?\\. It's still a land\\.$/", $line, $match)
            && ($keywords = self::keywordList($match[3] ?? '')) !== null) {
            $result['aura']['power'] += (int) $match[1];
            $result['aura']['toughness'] += (int) $match[2];
            $result['aura']['keywords'] = array_values(array_unique([...$result['aura']['keywords'], ...$keywords]));
            $result['aura']['animates'] = true;

            return true;
        }
        if (($result['aura']['enchant'] ?? null) !== null && preg_match("/^When enchanted (?:land|creature) dies, return that card to its owner's hand\\.$/", $line)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'enchanted_dies', 'effects' => [['type' => 'return_that', 'each' => 'that']]];

            return true;
        }
        // `Enchanted creature gets +1/+1 for each creature you control.`; see Game::attachmentPower().
        if (($per = self::attachedPer($line, 'Enchanted')) !== null) {
            $result['aura'] = $per + ($result['aura'] ?? ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []]);

            return true;
        }
        $keywords = null;
        if (preg_match('/^Enchanted creature gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?\.?$/', $line, $match)
            || preg_match('/^Enchanted (?:creature|permanent|land|artifact|[A-Z][a-z]+) (has) (.+?)\.?$/', $line, $match)) {
            $keywords = self::keywordList($match[1] === 'has' ? $match[2] : ($match[3] ?? ''));
        } elseif (preg_match('/^Enchanted (?:creature|permanent|artifact|land) (.+)$/', $line, $match)) {
            $keywords = self::restrictions($match[1]);
            $match = [null, 'has'];
        } elseif (preg_match("/^Its activated abilities can't be activated\\.?$/", $line)) {
            $keywords = ["abilities can't be activated"];
            $match = [null, 'has'];
        }
        if ($keywords === null) {
            return false;
        }
        $aura = $result['aura'] ?? ['enchant' => 'creature', 'power' => 0, 'toughness' => 0, 'keywords' => []];
        if ($match[1] !== 'has') {
            $aura['power'] += (int) $match[1];
            $aura['toughness'] += (int) $match[2];
        }
        $aura['keywords'] = array_values(array_unique([...$aura['keywords'], ...$keywords]));
        $result['aura'] = $aura;

        return true;
    }

    /**
     * `Equipped creature gets +N/+N (and has …)` and `Equip {N}`.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function equipment(string $line, array &$result): bool
    {
        // Reconfigure (rule 702.151): attach to another creature you control, or unattach, as a sorcery.
        if (preg_match('/^Reconfigure ((?:\{[0-9WUBRGC\/P]+\})+)$/', $line, $match)) {
            foreach ([['type' => 'attach', 'target' => 'creature_you_control'], ['type' => 'unattach', 'self' => true]] as $effect) {
                $result['activated'][] = ['text' => $line, 'cost' => ['mana' => $match[1]], 'effects' => [$effect], 'sorcery' => true, 'once' => false];
            }

            return true;
        }
        // `Equip Wizard {1}`: only onto a Wizard you control.
        if (preg_match('/^Equip ([A-Z][a-z]+) ((?:\{[0-9WUBRGC\/P]+\})+)$/', $line, $match) && $match[1] !== 'Legendary') {
            $result['activated'][] = ['text' => $line, 'cost' => ['mana' => $match[2]], 'effects' => [['type' => 'attach', 'target' => "any:sub_{$match[1]}@you"]], 'sorcery' => true, 'once' => false];

            return true;
        }
        if (preg_match('/^Equip ((?:\{[0-9WUBRGC\/P]+\})+)$/', $line, $match)) {
            $result['activated'][] = [
                'text' => $line,
                'cost' => ['mana' => $match[1]],
                'effects' => [['type' => 'attach', 'target' => 'creature_you_control']],
                'sorcery' => true,
                'once' => false,
            ];

            return true;
        }
        if (($per = self::attachedPer($line, 'Equipped')) !== null) {
            $result['equipment'] = $per + ($result['equipment'] ?? ['power' => 0, 'toughness' => 0, 'keywords' => []]);

            return true;
        }
        if (preg_match('/^Equipped creature gets ([+-]\d+)\/([+-]\d+)(?: and has (.+?))?\.?$/', $line, $match)
            || preg_match('/^Equipped creature (has) (.+?)\.?$/', $line, $match)) {
            $keywords = self::keywordList($match[1] === 'has' ? $match[2] : ($match[3] ?? ''));
            if ($keywords === null) {
                return false;
            }
            $bonus = $result['equipment'] ?? ['power' => 0, 'toughness' => 0, 'keywords' => []];
            if ($match[1] !== 'has') {
                $bonus['power'] += (int) $match[1];
                $bonus['toughness'] += (int) $match[2];
            }
            $bonus['keywords'] = array_values(array_unique([...$bonus['keywords'], ...$keywords]));
            $result['equipment'] = $bonus;

            return true;
        }

        return false;
    }

    /**
     * A Saga's chapter ability (rule 714), e.g. `I, II — Create a 1/1 …`:
     * it triggers when its lore counter is added. The last chapter is kept
     * as the keyword `saga N`.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function chapter(string $line, array &$result): bool
    {
        $numerals = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];
        if (! preg_match('/^((?:I|II|III|IV|V|VI)(?:, (?:I|II|III|IV|V|VI))*) — (.+)$/u', $line, $match) || ($effects = self::effects($match[2])) === null) {
            return false;
        }
        $chapters = array_map(fn (string $numeral) => $numerals[$numeral], explode(', ', $match[1]));
        foreach ($chapters as $n) {
            $result['triggered'][] = ['text' => $line, 'event' => "chapter_{$n}", 'effects' => $effects];
        }
        $last = max([...$chapters, ...array_map(fn (string $keyword) => (int) substr($keyword, 5), preg_grep('/^saga \d+$/', $result['keywords']))]);
        $result['keywords'] = [...array_values(preg_grep('/^saga \d+$/', $result['keywords'], PREG_GREP_INVERT)), "saga {$last}"];

        return true;
    }

    /**
     * A planeswalker's loyalty ability, e.g. `+1: You gain 2 life.` or
     * `−3: Destroy target creature.`
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function loyalty(string $line, array &$result): bool
    {
        // Printed as `+1: …` or, in newer card text, `[+1]: …`.
        if (! preg_match('/^\[?([+−-]?)(\d+)\]?: (.+)$/u', $line, $match) || ($effects = self::effects($match[3])) === null) {
            return false;
        }
        $result['activated'][] = [
            'text' => $line,
            'cost' => ['loyalty' => ($match[1] === '+' || $match[1] === '' ? 1 : -1) * (int) $match[2]],
            'effects' => $effects,
            'sorcery' => true,
            'once' => true,
        ];

        return true;
    }

    /**
     * A triggered ability (rule 603) this engine knows the event of.
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function triggered(string $line, array &$result): bool
    {
        // Keep the optional search distinct from accepting and failing to find a land.
        $optionalBasicSearch = match ($line) {
            'When CARDNAME enters, you may search your library for a basic land card, put that card onto the battlefield tapped, then shuffle.' => 'enters',
            'When CARDNAME dies, you may search your library for a basic land card, put it onto the battlefield tapped, then shuffle.' => 'dies',
            default => null,
        };
        if ($optionalBasicSearch !== null) {
            $result['triggered'][] = ['text' => $line, 'event' => $optionalBasicSearch, 'effects' => [['type' => 'optional_basic_land_search']]];

            return true;
        }
        // Solemn Simulacrum's self-death draw is optional at resolution.
        if ($line === 'When CARDNAME dies, you may draw a card.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'dies',
                'effects' => [['type' => 'optional_death_draw']]];

            return true;
        }
        // Exact source-counter wording; bind the artifact's battlefield mana value before movement changes its face.
        if ($line === "Whenever an artifact is put into a graveyard from the battlefield, put a number of +1/+1 counters equal to that artifact's mana value on CARDNAME.") {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'counters', 'amount' => 'departing_mana_value', 'self' => true]]];

            return true;
        }
        // Exact source-plus-Golem wording; the existing effects preserve source incarnation and controller.
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, put a +1/+1 counter on CARDNAME and a +1/+1 counter on each Golem you control.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'counters', 'amount' => 1, 'self' => true], ['type' => 'counters', 'amount' => 1, 'each' => 'yours', 'subtype' => 'Golem']]];

            return true;
        }
        // Exact optional red-payment token family; payment is a resolution choice.
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, you may pay {R}. If you do, create a 1/1 red Goblin creature token. It gains haste until end of turn.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'optional_red_goblin']]];

            return true;
        }
        // Exact optional source untap; the original controller makes the decision.
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, you may untap CARDNAME.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'optional_source_untap']]];

            return true;
        }
        // Exact optional source-counter wording; keep its decision separate from life choices.
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, you may put a +1/+1 counter on CARDNAME.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'optional_source_counter']]];

            return true;
        }
        // The quest counter is optional at resolution, like the existing source counter.
        if (preg_match('/^(?:Landfall — )?Whenever a land (?:you control enters|enters the battlefield under your control|enters under your control), you may put a quest counter on CARDNAME\.$/', $line)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'landfall',
                'effects' => [['type' => 'optional_source_counter', 'kind' => 'quest']]];

            return true;
        }
        // Exact temporary source boost; existing self/incarnation and cleanup rules own it.
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, CARDNAME gets +2/+0 until end of turn.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'pump', 'power' => 2, 'toughness' => 0, 'keywords' => [], 'self' => true]]];

            return true;
        }
        // Narrow optional/all-player burial family; only the life-loss wording targets.
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, you may gain 5 life.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'optional_life_gain', 'amount' => 5]]];

            return true;
        }
        if ($line === 'Whenever an artifact is put into a graveyard from the battlefield, you may have target opponent lose 1 life.') {
            $result['triggered'][] = ['text' => $line, 'event' => 'artifact_to_graveyard_any', 'objectKind' => 'artifact',
                'effects' => [['type' => 'optional_life_loss', 'amount' => 1, 'target' => 'opponent']]];

            return true;
        }
        // Valiant: `Whenever CARDNAME becomes the target of a spell or ability you control for the first time each turn, …`: see Game::trigger().
        // `Whenever …, draw a card. This ability triggers only once each turn.`: the same record.
        if (preg_match('/^(Whenever .+?) for the first time each turn, (.+)$/', $line, $once) || preg_match('/^(Whenever [^.]+?), (.+\.) This ability triggers only once each turn\.$/', $line, $once)) {
            $before = count($result['triggered'] ?? []);
            if (! self::triggered("{$once[1]}, {$once[2]}", $result)) {
                return false;
            }
            for ($i = $before; $i < count($result['triggered']); $i++) {
                $result['triggered'][$i] = ['text' => $line, 'oncePerTurn' => true] + $result['triggered'][$i];
            }

            return true;
        }
        // Creatures only attack players here (see Game::declareAttackers()), so "or planeswalker" adds nothing.
        $line = (string) preg_replace('/deals combat damage to a player(?:, planeswalker,)? or (?:planeswalker|battle)\b/', 'deals combat damage to a player', $line);
        // Creatures attack only opponents, so their combat damage to a player is always to an opponent.
        $line = (string) preg_replace('/^(Whenever CARDNAME deals combat damage to) an opponent,/', '$1 a player,', $line);
        // `Whenever another creature you control enters, if it has power 3 or greater, …`: read as `… with power 3 or greater enters, …`.
        $line = (string) preg_replace('/^Whenever (another|a) creature you control enters, if it has power (\d+) or greater, /', 'Whenever $1 creature you control with power $2 or greater enters, ', $line);
        // `Whenever another creature you control enters, if it's a Human, …`: read as `Whenever another Human you control enters, …`.
        $line = (string) preg_replace('/^Whenever (another|a) creature you control enters, if it\'s an? ([A-Z][a-z]+), /', 'Whenever $1 $2 you control enters, ', $line);
        // `Whenever you attack with two or more creatures, …`: an intervening if on how many attacked.
        $line = (string) preg_replace('/^Whenever you attack with (\w+) or more creatures, /', 'Whenever you attack, if you attacked with $1 or more creatures this combat, ', $line);
        // `Whenever an opponent casts a spell during your turn, …`: an intervening if.
        $line = (string) preg_replace('/^(Whenever an opponent casts (?:a|an) [a-z ]*spell) during your turn, /', "\$1, if it's your turn, ", $line);
        // `Whenever CARDNAME attacks while you control a Dragon, …`: an intervening if.
        $line = (string) preg_replace('/^Whenever CARDNAME attacks while you control (an? [A-Z][a-z]+), /', 'Whenever CARDNAME attacks, if you control $1, ', $line);
        // Exert (rule 701.43): `You may exert CARDNAME as it attacks. When you do, …` is an attack trigger.
        $line = (string) preg_replace('/^You may exert CARDNAME as it attacks\. When you do, /', 'Whenever CARDNAME attacks, you may exert CARDNAME. When you do, ', $line);
        $events = [
            'becomes the target of a spell or ability' => 'targeted',
            'becomes the target of a spell or ability an opponent controls' => 'targeted_by_opponent',
            'becomes the target of a spell or ability you control' => 'targeted_by_you',
            'becomes the target of a spell' => 'targeted_by_spell',
            'becomes the target of an Aura spell' => 'targeted_by_aura_spell',
            'enters' => 'enters', 'enters the battlefield' => 'enters', 'dies' => 'dies', 'attacks' => 'attacks', 'attacks alone' => 'attacks_alone',
            'deals combat damage to a player' => 'combat_damage', 'is turned face up' => 'turned_face_up', 'becomes tapped' => 'becomes_tapped',
            'exploits a creature' => 'exploits', 'becomes monstrous' => 'monstrous', 'mutates' => 'mutates',
            'is put into a graveyard from the battlefield' => 'to_graveyard', 'is put into a graveyard from anywhere' => 'to_graveyard_anywhere', 'becomes blocked' => 'blocked',
            'blocks' => 'blocks', 'leaves the battlefield' => 'leaves', 'blocks a creature' => 'blocks_creature', 'becomes blocked by a creature' => 'blocked_by_creature',
        ];
        $casts = [
            'a noncreature spell' => 'cast_noncreature', 'an instant or sorcery spell' => 'cast_instant_sorcery', 'a spell' => 'cast_spell', 'a Spirit or Arcane spell' => 'cast_spirit_arcane',
            'a creature spell' => 'cast_creature', 'an artifact spell' => 'cast_artifact', 'an enchantment spell' => 'cast_enchantment',
            'a white spell' => 'cast_color_W', 'a blue spell' => 'cast_color_U', 'a black spell' => 'cast_color_B', 'a red spell' => 'cast_color_R', 'a green spell' => 'cast_color_G',
            'a multicolored spell' => 'cast_multicolored', 'a spell from your graveyard' => 'cast_from_graveyard',
            // See Game::castTriggers(): the zone it was cast from, and a kicker cost paid.
            'a spell from exile' => 'cast_from_exile', 'a spell from anywhere other than your hand' => 'cast_not_from_hand', 'a kicked spell' => 'cast_kicked',
        ];
        // Haunt: `When CARDNAME enters or the creature it haunts dies, …` and `When the creature CARDNAME haunts dies, …`
        if (preg_match('/^When (CARDNAME enters or the creature it haunts dies|the creature CARDNAME haunts dies), (.+)\.$/', $line, $m) && ($effects = self::effects($m[2].'.')) !== null) {
            foreach (str_starts_with($m[1], 'CARDNAME enters') ? ['enters', 'haunted_dies'] : ['haunted_dies'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];
            }

            return true;
        }
        // `When CARDNAME enters or dies, surveil 1.`: the same ability on both events.
        if (preg_match('/^When(?:ever)? CARDNAME (enters|attacks|dies) or (attacks|dies)(?: the battlefield)?, (.+)\.$/', $line, $m) && $m[1] !== $m[2]
            && (($effects = self::effects($m[3].'.')) !== null || ! str_contains($m[3], 'target') && ($effects = self::effects(preg_replace('/\bit\b/', 'CARDNAME', $m[3]).'.')) !== null)) {
            foreach ([$m[1], $m[2]] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $events[$event], 'effects' => $effects];
            }

            return true;
        }
        // Frostwalk Bastion / Kashi-Tribe: on the creature it dealt combat damage to; see Game::combatDamage().
        if (preg_match("/^Whenever CARDNAME deals combat damage to a creature, tap that creature and it doesn't untap during its controller's next untap step\\.$/", $line)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'combat_damage_creature', 'effects' => [['type' => 'tap', 'target' => 'creature', 'freeze' => true]]];

            return true;
        }
        // `Whenever CARDNAME deals (combat) damage to a creature, that creature doesn't untap during its controller's next untap step.`: tapped or not.
        if (preg_match("/^Whenever CARDNAME deals (combat )?damage to a creature, that creature doesn't untap during its controller's next untap step\\.$/", $line, $m)) {
            $result['triggered'][] = ['text' => $line, 'event' => ($m[1] ?? '') === '' ? 'damages_creature' : 'combat_damage_creature', 'effects' => [['type' => 'freeze', 'target' => 'creature']]];

            return true;
        }
        // `Whenever CARDNAME deals damage, you gain that much life.`; see Game::dealDamage().
        if (preg_match('/^Whenever CARDNAME deals damage, (.+)\.$/', $line, $m) && substr_count($m[1], 'that much') === 1
            && ($effects = self::sentenceEffects((string) preg_replace('/\bthat much (damage|life)\b/', '1 $1', $m[1]))) !== null
            && count($effects) === 1 && ($effects[0]['amount'] ?? null) === 1 && ! isset($effects[0]['per']) && ! isset($effects[0]['target'])) {
            $result['triggered'][] = ['text' => $line, 'event' => 'deals_damage', 'effects' => [['amount' => 'that_much'] + $effects[0]]];

            return true;
        }
        // Boros Reckoner: `Whenever CARDNAME is dealt damage, it deals that much damage to any target.`; see Game::dealDamage().
        if (preg_match('/^Whenever CARDNAME is dealt damage, (.+)\.$/', $line, $m) && substr_count($m[1], 'that much') === 1
            && ($effects = self::sentenceEffects((string) preg_replace(['/^it deals that much damage\b/', '/\bthat much (damage|life)\b/'], ['CARDNAME deals 1 damage', '1 $1'], $m[1]))) !== null
            && count($effects) === 1 && ($effects[0]['amount'] ?? null) === 1 && ! isset($effects[0]['per'])) {
            $result['triggered'][] = ['text' => $line, 'event' => 'dealt_damage', 'effects' => [['amount' => 'that_much'] + $effects[0]]];

            return true;
        }
        // Enrage: `Whenever CARDNAME is dealt damage, create a Treasure token.`; see Game::dealDamage().
        if (preg_match('/^Whenever CARDNAME is dealt damage, (.+)\.$/', $line, $m) && ! str_contains($m[1], 'that much')
            // `… put a +1/+1 counter on it.`: "it" is CARDNAME.
            && ($effects = self::sentenceEffects((string) preg_replace(['/^it deals\b/', '/\b(counters?) on it$/'], ['CARDNAME deals', '$1 on CARDNAME'], $m[1]))) !== null) {
            $result['triggered'][] = ['text' => $line, 'event' => 'dealt_damage', 'effects' => $effects];

            return true;
        }
        // `Whenever CARDNAME deals combat damage to a creature, you gain that much life.`: the damage it dealt; see Game::combatDamage().
        if (preg_match('/^Whenever CARDNAME deals combat damage to a creature, (.+)\.$/', $line, $m) && substr_count($m[1], 'that much') === 1
            && ($effects = self::sentenceEffects((string) preg_replace('/\bthat much (damage|life)\b/', '1 $1', $m[1]))) !== null
            && count($effects) === 1 && ($effects[0]['amount'] ?? null) === 1 && ! isset($effects[0]['per']) && ! isset($effects[0]['target'])) {
            $result['triggered'][] = ['text' => $line, 'event' => 'combat_damage_creature', 'effects' => [['amount' => 'that_much'] + $effects[0]]];

            return true;
        }
        // `Whenever CARDNAME deals combat damage to a creature, destroy that creature.`: that creature, not targeted; see Game::combatDamage().
        if (preg_match('/^Whenever CARDNAME deals (combat )?damage to a creature, (.+)\.$/', $line, $m)
            && ($effects = self::sentenceEffects((string) preg_replace('/\bthat creature\b/', 'target creature', $m[2]))) !== null
            && count(array_filter($effects, fn (array $effect) => isset($effect['target']))) === (str_contains($m[2], 'that creature') ? 1 : 0)
            && array_filter($effects, fn (array $effect) => isset($effect['target']) && $effect['target'] !== 'creature') === []) {
            $result['triggered'][] = ['text' => $line, 'event' => $m[1] === '' ? 'damages_creature' : 'combat_damage_creature', 'effects' => $effects];

            return true;
        }
        // Court Hussar: `When CARDNAME enters, sacrifice it unless {W} was spent to cast it.`; see GameObject::$spent.
        if (preg_match('/^When CARDNAME enters(?: the battlefield)?, sacrifice it unless \{([WUBRG])\} was spent to cast it\.$/', $line, $m)) {
            $result['triggered'][] = ['text' => $line, 'event' => 'enters', 'effects' => [['type' => 'sacrifice_unless_spent', 'color' => $m[1], 'self' => true]]];

            return true;
        }
        // Basilisk-style: `Whenever CARDNAME deals combat damage to a creature, destroy that creature at end of combat.`; see Game::combatDamage().
        // Without "combat", any damage counts and the creature is destroyed at the next end of combat; see Game::dealDamage().
        if (preg_match('/^Whenever CARDNAME deals (combat )?damage to a creature, destroy that creature at end of combat\.$/', $line, $m)) {
            $result['triggered'][] = ['text' => $line, 'event' => ($m[1] ?? '') === '' ? 'damages_creature' : 'combat_damage_creature', 'effects' => [['type' => 'destroy_end_combat', 'target' => 'creature']]];

            return true;
        }
        // Cockatrice-style: `Whenever CARDNAME blocks or becomes blocked by a creature, destroy that creature at end of combat.`; see Game::$destroyAtEndOfCombat.
        if (preg_match('/^Whenever CARDNAME (blocks|becomes blocked by|blocks or becomes blocked by) a(?: non-?(\w+))? creature, destroy that creature at end of combat\.$/', $line, $m)
            && (($m[2] ?? '') === '' || in_array(strtolower($m[2]), ['wall'], true))) {
            $event = ['blocks' => 'blocks_creature', 'becomes blocked by' => 'blocked_by_creature'][$m[1]] ?? 'block_pair';
            $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => [['type' => 'destroy_end_combat', 'each' => 'that'] + (($m[2] ?? '') === '' ? [] : ['notSubtype' => 'Wall'])]];

            return true;
        }
        // Ornery Goblin: `Whenever CARDNAME blocks or becomes blocked by a creature, CARDNAME deals 1 damage to that creature.`; see Game::declareBlockers().
        if (preg_match('/^Whenever CARDNAME (blocks|becomes blocked by|blocks or becomes blocked by) a creature, CARDNAME deals (\w+) damage to that creature\.$/', $line, $m) && is_int($n = self::amount($m[2]))) {
            $event = ['blocks' => 'blocks_creature', 'becomes blocked by' => 'blocked_by_creature'][$m[1]] ?? 'block_pair';
            $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => [['type' => 'damage', 'amount' => $n, 'each' => 'that']]];

            return true;
        }
        // Skystinger: `Whenever CARDNAME blocks a creature with flying, CARDNAME gets +5/+0 until end of turn.`
        if (preg_match('/^Whenever CARDNAME blocks a creature with flying, (.+)\.$/', $line, $m) && ($effects = self::effects($m[1].'.')) !== null) {
            $result['triggered'][] = ['text' => $line, 'event' => 'blocks_flyer', 'effects' => $effects];

            return true;
        }
        // Fiend Hunter: `When CARDNAME leaves the battlefield, return the exiled card to the battlefield under its owner's control.`
        // makes its enters exile last until it leaves, as `exile … until CARDNAME leaves the battlefield` does.
        // Or, with `return the exiled card to its owner's hand.`, back to its owner's hand.
        if (preg_match("/^When CARDNAME leaves the battlefield, return the exiled (?:card|creature|permanent) to (the battlefield under its owner's control|its owner's hand)\\.$/", $line, $back)) {
            foreach ($result['triggered'] as $i => $ability) {
                $last = count($ability['effects']) - 1;
                if ($ability['event'] === 'enters' && $last >= 0 && $ability['effects'][$last]['type'] === 'exile' && isset($ability['effects'][$last]['target']) && ! isset($ability['effects'][$last]['until'])) {
                    $result['triggered'][$i]['effects'][$last]['until'] = $back[1] === "its owner's hand" ? 'hand' : true;

                    return true;
                }
            }

            return false;
        }
        $saddled = false;
        if (preg_match('/^When CARDNAME becomes level (\d+), (.+)$/', $line, $match)) {
            $event = "class_level_{$match[1]}";
            $text = $match[2];
        } elseif (preg_match('/^(?:When|Whenever) CARDNAME (enters the battlefield|enters|dies|attacks alone|attacks|deals combat damage to a player|is turned face up|exploits a creature|becomes monstrous|mutates|is put into a graveyard from the battlefield|is put into a graveyard from anywhere|becomes the target of a spell or ability an opponent controls|becomes the target of a spell or ability you control|becomes the target of a spell or ability|becomes the target of a spell|becomes the target of an Aura spell|becomes blocked by a creature|becomes blocked|becomes tapped|blocks a creature|blocks|leaves the battlefield)( while saddled)?, (.+)$/', $line, $match)) {
            $event = $events[$match[1]];
            $saddled = $match[2] !== '';
            $text = $match[3];
        } elseif (preg_match('/^(?:When|Whenever) CARDNAME (blocks or becomes blocked|attacks or blocks), (.+)$/', $line, $match)
            && ($effects = self::effects($match[2]) ?? (str_contains($match[2], 'target') ? null : self::effects(preg_replace('/\bit\b/', 'CARDNAME', $match[2])))) !== null) {
            // Once for each of the two events.
            foreach (['blocks or becomes blocked' => ['blocks', 'blocked'], 'attacks or blocks' => ['attacks', 'blocks']][$match[1]] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];
            }

            return true;
        } elseif (preg_match('/^Whenever a creature an opponent controls dies, (.+)$/', $line, $match)) {
            $event = 'creature_dies_any';
            $text = $match[1];
        } elseif (preg_match('/^When you cycle CARDNAME, (.+)$/', $line, $match)) {
            $event = 'cycled';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you cycle (?:a|another) card, (.+)$/', $line, $match)) {
            $event = 'you_cycle';
            $text = $match[1];
        } elseif (preg_match("/^(?:At the beginning of each (?:opponent's |player's )?upkeep|At the beginning of each opponent's end step|Whenever an opponent draws a card|Whenever a player draws a card|Whenever an opponent loses life(?: during your turn)?|Whenever a land enters (?:the battlefield )?under an opponent's control), (.+)$/", $line, $match)
            && ! preg_match('/^if (?:no spells were|a player) cast /', $match[1])) {
            // "That player" is that opponent (or, each player's upkeep, the active player); see Game::trigger().
            $event = match (true) {
                str_contains($line, "opponent's upkeep") => 'opponent_upkeep',
                str_contains($line, "opponent's end step") => 'opponent_end_step',
                str_contains($line, 'upkeep') => 'each_upkeep',
                str_contains($line, 'a player draws') => 'any_draw',
                str_contains($line, 'draws') => 'opponent_draw',
                str_contains($line, 'loses life during your turn') => 'opponent_loses_life_your_turn',
                str_contains($line, 'loses life') => 'opponent_loses_life',
                default => 'opponent_landfall',
            };
            // `Whenever an opponent draws a card, they lose 1 life.`
            $text = (string) preg_replace('/^they (lose|discard|mill|sacrifice)\b/', 'that player $1s', $match[1]);
            if (str_contains($text, 'that player') && ! str_contains($text, 'target')) {
                $effects = self::effects(str_replace('that player', 'target player', $text));
                if ($effects === null) {
                    return false;
                }
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => array_map(fn (array $effect) => ($effect['target'] ?? null) === 'player' ? ['thatPlayer' => true] + array_diff_key($effect, ['target' => true]) : $effect, $effects)];

                return true;
            }
        } elseif (preg_match('/^(?:When|Whenever) CARDNAME deals damage to (a player|an opponent), (.+)$/', $line, $match)) {
            // Any damage, not only combat; "that player" is the player dealt damage. See Game::dealDamage().
            $event = $match[1] === 'a player' ? 'damages_player' : 'damages_opponent';
            $text = $match[2];
            if (str_contains($text, 'that player') && ! str_contains($text, 'target')) {
                $effects = self::effects(str_replace('that player', 'target player', $text));
                if ($effects === null) {
                    return false;
                }
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => array_map(fn (array $effect) => ($effect['target'] ?? null) === 'player' ? ['thatPlayer' => true] + array_diff_key($effect, ['target' => true]) : $effect, $effects)];

                return true;
            }
        } elseif (preg_match('/^Whenever you (?:cycle or )?discard an? (creature|land|artifact|enchantment|instant or sorcery) card, (.+)$/', $line, $match) && ! preg_match('/\b(?:it|that card)\b/', $match[2])) {
            // See Game::discardedBy(): the card discarded is the context.
            $event = 'you_discard_kind';
            $extra = ['objectKind' => $match[1]];
            $text = $match[2];
        } elseif (preg_match('/^Whenever an opponent (?:cycles or )?discards a card, (.+)$/', $line, $match) && ! preg_match('/\b(?:it|that card)\b/', $match[1])) {
            // See Game::discardedBy(): once for each card. "That player" is the opponent who discarded.
            $event = 'opponent_discards';
            $text = (string) preg_replace('/^they (lose|discard|mill|sacrifice)\b/', 'that player $1s', $match[1]);
            if (str_contains($text, 'that player') && ! str_contains($text, 'target')) {
                $effects = self::effects(str_replace('that player', 'target player', $text));
                if ($effects === null) {
                    return false;
                }
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => array_map(fn (array $effect) => ($effect['target'] ?? null) === 'player' ? ['thatPlayer' => true] + array_diff_key($effect, ['target' => true]) : $effect, $effects)];

                return true;
            }
        } elseif (preg_match('/^Whenever you (?:cycle or )?discard (a card|one or more cards), (.+)$/', $line, $match)) {
            // See Game::discardCards(): "one or more" triggers once for them all. Cycling discards too.
            $event = $match[1] === 'a card' ? 'you_discard' : 'you_discard_batch';
            $text = $match[2];
        } elseif (preg_match("/^Whenever you activate (an ability that isn't a mana ability|an exhaust ability)(?: that isn't a mana ability)?, (.+)$/", $line, $match)
            && ! preg_match('/\b(?:it|that ability)\b/', $match[2])) {
            // See Game::activate(): mana abilities don't use the stack and never get here.
            $event = str_contains($match[1], 'exhaust') ? 'you_activate_exhaust' : 'you_activate';
            $text = $match[2];
        } elseif (preg_match('/^Whenever you expend (\d+), (.+)$/', $line, $match)) {
            // See Game::pay(): the Nth total mana spent this turn.
            $event = "expend_{$match[1]}";
            $text = $match[2];
        } elseif (preg_match('/^Whenever (a|another) creature you control explores, (.+)$/', $line, $match)) {
            // See Game's explore effect: "it" is the creature that explored.
            $event = 'creature_explores';
            $extra = $match[1] === 'another' ? ['objectKind' => 'creature', 'other' => true] : [];
            $text = $match[2];
        } elseif (preg_match('/^Whenever (a|another) (permanent|creature) you control is turned face up, (.+)$/', $line, $match)) {
            $event = 'permanent_turned_face_up';
            $extra = ['objectKind' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[3];
        } elseif (preg_match('/^Whenever (a|an|another) (creature|[A-Z][a-z]+) you control becomes tapped, (.+)$/', $line, $match) && ($match[2] === 'creature' || self::subtypeOf($match[2]) === $match[2])) {
            // See Game::checkStateBasedActions(): "it" is that creature. `Whenever an Elf you control becomes tapped, …` by subtype.
            $event = 'creature_becomes_tapped';
            $extra = $match[1] === 'another' || $match[2] !== 'creature' ? ['objectKind' => $match[2], 'other' => $match[1] === 'another'] : [];
            $text = $match[3];
        } elseif (preg_match('/^Whenever a creature an opponent controls becomes tapped, (.+)$/', $line, $match)) {
            // See Game::checkStateBasedActions(): "it" is that creature.
            $event = 'opponent_creature_becomes_tapped';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you commit a crime, (.+)$/', $line, $match)) {
            // See Game::targetedTriggers().
            $event = 'you_commit_crime';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you forage, (.+)$/', $line, $match)) {
            // See Game::foraged(): as a cost or a `you may forage` payment.
            $event = 'you_forage';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you play a land, (.+)$/', $line, $match)) {
            $event = 'you_play_land';
            $text = $match[1];
        } elseif (preg_match('/^Whenever (a|another) (nontoken creature|creature token|token|nontoken permanent|creature|permanent|artifact|enchantment|land|[A-Z][a-z]+) you control leaves the battlefield, (.+)$/', $line, $match)
            && ! preg_match('/\b(?:it|its|that creature|that permanent)\b/', $match[3])) {
            // See Game::moveTo(): itself included unless "another".
            $event = 'permanent_leaves_yours';
            $extra = ['objectKind' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[3];
        } elseif (preg_match('/^Whenever a creature dealt damage by CARDNAME this turn dies, (.+)$/', $line, $match)) {
            // Sengir Vampire; see Game::moveTo().
            $event = 'damaged_dies';
            $text = $match[1];
        } elseif (preg_match('/^At the beginning of combat on your turn, (.+)$/', $line, $match)) {
            $event = 'begin_combat';
            $text = $match[1];
        } elseif (preg_match('/^At the beginning of (each combat|your draw step), (.+)$/', $line, $match)) {
            $event = $match[1] === 'each combat' ? 'each_combat' : 'draw_step';
            $text = $match[2];
        } elseif (preg_match('/^Whenever an opponent casts a spell, (.+)$/', $line, $match)) {
            $event = 'opponent_cast';
            $text = $match[1];
        } elseif (preg_match('/^Whenever an opponent casts an? (creature|noncreature|artifact|enchantment|instant or sorcery|multicolored) spell, (.+)$/', $line, $match)) {
            // See Game::castSpell(): the spell cast is the trigger's context.
            $event = 'opponent_cast_kind';
            $extra = ['objectKind' => $match[1]];
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of your (upkeep|end step), (.+)$/', $line, $match)) {
            $event = $match[1] === 'upkeep' ? 'upkeep' : 'end_step';
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of each upkeep, if (no spells were cast last turn|a player cast two or more spells last turn), transform CARDNAME\.?$/', $line, $match)) {
            // The werewolves of Innistrad (rule 701.28).
            $result['triggered'][] = ['text' => $line, 'event' => 'each_upkeep', 'effects' => [['type' => 'transform', 'self' => true, 'if' => str_starts_with($match[1], 'no') ? 'no_spells' : 'two_spells']]];

            return true;
        } elseif (preg_match('/^At the beginning of your (?:first|precombat) main phase, you may pay '.self::COST.'\. If you do, transform CARDNAME\.?$/u', $line, $match) && ! str_contains($match[1], 'X')) {
            $result['triggered'][] = ['text' => $line, 'event' => 'first_main', 'effects' => [['type' => 'pay_transform', 'cost' => $match[1], 'self' => true]]];

            return true;
        } elseif (preg_match('/^At the beginning of your (first|precombat|second|postcombat) main phase, (.+)$/', $line, $match)) {
            $event = in_array($match[1], ['first', 'precombat'], true) ? 'first_main' : 'second_main';
            $text = $match[2];
        } elseif (preg_match('/^At the beginning of (?:the|each) end step, (.+)$/', $line, $match)) {
            $event = 'each_end_step';
            $text = $match[1];
        } elseif (preg_match("/^At the beginning of enchanted player's upkeep, (.+)$/", $line, $match) && in_array($result['aura']['enchant'] ?? null, ['player', 'opponent'], true)) {
            // A curse: "that player" is the enchanted player.
            $effects = self::effects(str_replace(['that player or a planeswalker that player controls', 'that player'], 'target player', $match[1]));
            if ($effects === null || array_filter($effects, fn (array $effect) => ($effect['target'] ?? 'player') !== 'player') !== []) {
                return false;
            }
            $effects = array_map(fn (array $effect) => isset($effect['target']) ? ['toEnchanted' => true] + array_diff_key($effect, ['target' => true]) : $effect, $effects);
            $result['triggered'][] = ['text' => $line, 'event' => 'enchanted_upkeep', 'effects' => $effects];

            return true;
        } elseif (preg_match('/^(?:Landfall — )?Whenever a land (?:you control enters|enters the battlefield under your control|enters under your control), (.+)$/', $line, $match)) {
            $event = 'landfall';
            $text = $match[1];
        } elseif (preg_match('/^Whenever CARDNAME or another Ally (?:you control enters|enters the battlefield under your control)(?: the battlefield)?, (.+)$/', $line, $match) && ($effects = self::effects($match[1])) !== null) {
            // Rally-style Allies: once for itself, once for each other Ally.
            foreach (['enters', 'ally_enters_other'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];
            }

            return true;
        } elseif (preg_match('/^(?:When|Whenever) (?:equipped|enchanted) creature (attacks|blocks|deals combat damage to a player|dies), (.+)$/', $line, $match)) {
            // The Swords: `Whenever equipped creature deals combat damage to a player, …`; see Game::attachedTrigger(). "It" is that creature.
            $event = 'attached_'.['attacks' => 'attacks', 'blocks' => 'blocks', 'deals combat damage to a player' => 'combat_damage', 'dies' => 'dies'][$match[1]];
            $text = $match[2];
        } elseif (preg_match('/^Whenever an? (equipped|enchanted) creature you control (attacks|blocks|deals combat damage to a player), (.+)$/', $line, $match)) {
            // `Whenever an equipped creature you control attacks, …`: "it" is that creature; see Game::objectFits().
            $event = ['attacks' => 'creature_attacks', 'blocks' => 'creature_blocks', 'deals combat damage to a player' => 'creature_combat_damage'][$match[2]];
            $extra = ['objectKind' => "{$match[1]} creature", 'other' => false];
            $text = $match[3];
        } elseif (preg_match('/^Whenever (a|an|another) (creature|[A-Z][a-z]+) you control(?: with (flying|trample|deathtouch|lifelink|menace|reach|haste|vigilance|first strike|defender))? (attacks alone|attacks|blocks|becomes blocked|deals combat damage to a player), (.+)$/', (string) preg_replace('/^(Whenever (?:a|another) creature you control) with power (\w+) or greater (attacks|blocks)/', '$1 $3', $line), $match)
            && ($match[1] === 'an') === (bool) preg_match('/^[AEIOU]/', $match[2])) {
            // See Game::declareAttackers(), Game::declareBlockers() and Game::combatDamage(): "it" is that creature.
            // `Whenever a Human you control attacks, …`, `… a creature you control with flying …`: see Game::trigger().
            $event = ['attacks' => 'creature_attacks', 'attacks alone' => 'creature_attacks_alone', 'blocks' => 'creature_blocks', 'becomes blocked' => 'creature_becomes_blocked', 'deals combat damage to a player' => 'creature_combat_damage'][$match[4]]
                .($match[1] === 'another' && ! in_array($match[4], ['attacks alone', 'blocks', 'becomes blocked'], true) ? '_other' : '');
            $extra = ($match[1] === 'another' && in_array($match[4], ['blocks', 'becomes blocked'], true) || $match[3] !== '' ? ['objectKind' => $match[3] !== '' ? "creature with {$match[3]}" : 'creature', 'other' => $match[1] === 'another'] : [])
                + ($match[2] !== 'creature' ? ['subtype' => $match[2], 'other' => $match[1] === 'another'] : []);
            // `Whenever a creature you control with power 4 or greater attacks, …`: that creature's power; see Game::trigger().
            if (preg_match('/^Whenever (?:a|another) creature you control with power (\w+) or greater (?:attacks|blocks),/', $line, $strong) && is_int($power = self::amount($strong[1]))) {
                $extra['minPower'] = $power;
            }
            $text = $match[5];
        } elseif (preg_match('/^Whenever a creature (?:attacks you|an opponent controls attacks), (.+)$/', $line, $match)) {
            // See Game::declareAttackers(): "it" is the attacking creature.
            $event = 'opponent_creature_attacks';
            $text = $match[1];
        } elseif (preg_match('/^Whenever one or more \+1\/\+1 counters are put on (CARDNAME|a creature you control|another creature you control), (.+)$/', $line, $match)
            && ! preg_match('/\b(?:it|its|that creature|that many)\b/', $match[2])) {
            // See Game::checkStateBasedActions(): more +1/+1 counters than at the last check.
            $event = $match[1] === 'CARDNAME' ? 'counters_put' : 'counters_put_creature';
            $extra = $match[1] === 'another creature you control' ? ['objectKind' => 'creature', 'other' => true] : [];
            $text = $match[2];
        } elseif (preg_match('/^Whenever a creature (?:an opponent controls )?deals combat damage to you, (.+)$/', $line, $match) && ! preg_match('/\b(?:it|its|that creature|that player|its controller)\b/', $match[1])) {
            // See Game::combatDamage(): once for each creature that hit you.
            $event = 'creature_combat_damages_you';
            $text = $match[1];
        } elseif (preg_match('/^Whenever a source an opponent controls deals damage to you, (.+)$/', $line, $match) && ! preg_match('/\b(?:it|its|that source|that much|that player|its controller)\b/', $match[1])) {
            // See Game::dealDamage(): once for each source and each time it deals damage.
            $event = 'opponent_source_damages_you';
            $text = $match[1];
        } elseif (preg_match('/^Whenever one or more creatures you control deal combat damage to a player, (.+)$/', $line, $match) && ! preg_match('/\b(?:they|those creatures|that player)\b/', $match[1])) {
            // Once, however many; see Game::combatDamage().
            $event = 'you_combat_damage_batch';
            $text = $match[1];
        } elseif (preg_match('/^Whenever one or more creatures (?:you control with power (\w+) or greater|with power (\w+) or greater you control) attack, (.+)$/', $line, $match)
            && is_int($power = self::amount($match[1] !== '' ? $match[1] : $match[2])) && ! preg_match('/\b(?:it|its|they|them|their|those creatures)\b/', $match[3])) {
            // Once, however many; see Game::declareAttackers().
            $event = 'you_attack_strongest';
            $extra = ['minPower' => $power];
            $text = $match[3];
            $match = [$match[0], $text];
        } elseif (preg_match('/^Whenever (?:one or more ([A-Z][a-z]+) you control attack|you attack with one or more ([A-Z][a-z]+)), (.+)$/', $line, $match)
            && ($kind = self::subtypeOf($match[1] !== '' ? $match[1] : $match[2])) !== null && ! preg_match('/\b(?:it|its|they|them|their|those creatures)\b/', $match[3])) {
            // `Whenever one or more Rabbits you control attack, …`: once, however many; see Game::declareAttackers().
            $event = 'you_attack_kind';
            $extra = ['objectKind' => $kind];
            $text = $match[3];
            $match = [$match[0], $text];
        } elseif (preg_match('/^Whenever (?:you attack|one or more creatures you control attack), (.+)$/', $line, $match)) {
            $event = 'you_attack';
            $text = (string) preg_replace('/^they (get|gain)/', 'attacking creatures you control $1', $match[1]);
        } elseif (preg_match('/^Whenever (another|a) creature (?:you control )?with power (\w+) or greater enters(?: the battlefield)?(?: under your control)?, (.+)$/', $line, $match)
            && (str_contains($match[0], 'you control with') || str_contains($match[0], 'under your control')) && is_int($power = self::amount($match[2]))) {
            // Ferocious-style: `Whenever a creature you control with power 4 or greater enters, draw a card.`; see Game::trigger().
            $extra = ['minPower' => $power];
            $text = $match[3];
            $event = 'creature_enters_other';
            if ($match[1] === 'a' && self::$creature) {
                // Itself too, when it enters with that much power.
                if (($effects = self::effects($text)) === null || preg_match('/\b(it|its)\b/', $text)) {
                    return false;
                }
                foreach (['enters', 'creature_enters_other'] as $event) {
                    $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + $extra;
                }

                return true;
            }
        } elseif (preg_match('/^Whenever one or more other (creatures|nontoken creatures) you control die, (.+)$/', $line, $m2) && ! preg_match('/\b(?:they|them|their|those creatures|it|its)\b/', $m2[2])) {
            // Once for creatures dying together; see Game::trigger().
            $match = [$m2[0], $m2[2]];
            $event = 'creature_dies_other';
            $extra = ['batch' => true] + ($m2[1] !== 'creatures' ? ['nontoken' => true] : []);
            $text = $match[1];
        } elseif (preg_match('/^Whenever one or more (other )?creatures (?:you control enter|enter(?: the battlefield)? under your control)(?: the battlefield)?, (.+)$/', $line, $m2) && ($m2[1] !== '' || ! self::$creature)
            && ! preg_match('/\b(?:they|them|their|those creatures|it|its)\b/', $m2[2])) {
            // Once per batch, such as several tokens created together; see Game::putOntoBattlefield().
            $match = [$m2[0], $m2[2]];
            $event = 'creatures_enter_other';
            $text = $match[1];
        } elseif (preg_match('/^Whenever (?:another|(a)) creature you control enters(?: the battlefield)?, (.+)$/', $line, $m2) && ($m2[1] === '' || ! self::$creature)) {
            // "a creature" on a noncreature (an Equipment) is any other creature.
            $match = [$m2[0], $m2[2]];
            $event = 'creature_enters_other';
            $text = $match[1];
        } elseif (self::$creature && preg_match('/^Whenever a creature you control enters(?: the battlefield)?, (.+)$/', $line, $match)
            && ! preg_match('/\b(?:it|its|that creature(?:\'s)?)\b/', $match[1]) && ($effects = self::effects($match[1])) !== null) {
            // On a creature, once for itself and once for each other creature, like the Allies above.
            foreach (['enters', 'creature_enters_other'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects];
            }

            return true;
        } elseif (preg_match('/^Whenever (another|a) (nontoken )?creature (enters(?: the battlefield)?|an opponent controls enters|enters(?: the battlefield)? under an opponent\'s control), (.+)$/', $line, $match)
            && ! preg_match('/\b(it|its|that creature(?:\'s)?|that player)\b/', $match[4]) && ($effects = self::effects($match[4])) !== null) {
            // Any player's creature (Soul Warden), or only an opponent's; see Game::putOntoBattlefield().
            $event = str_contains($match[3], 'opponent') ? 'opponent_creature_enters' : 'any_creature_enters';
            $extra = $match[2] === '' ? [] : ['nontoken' => true];
            $fired = $event === 'any_creature_enters' && $match[1] === 'a' && self::$creature ? ['enters', $event] : [$event];
            foreach ($fired as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($event === 'enters' ? [] : $extra);
            }

            return true;
        } elseif (preg_match('/^(?:When|Whenever) (a|another) ([A-Z][a-z]+) you control enters(?: the battlefield)?, (.+)$/', $line, $match) && $match[2] !== 'Creature') {
            // Tribal enters triggers: `Whenever another Elf you control enters, …`.
            $event = 'subtype_enters';
            $extra = ['subtype' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[3];
        } elseif (preg_match(
            "/^Whenever (an?|another) (nontoken creature|nontoken permanent|nontoken artifact|noncreature permanent|creature token|artifact creature|creature with (?:flying|trample|deathtouch|lifelink|menace|reach|haste|vigilance|first strike|defender)|permanent|artifact|enchantment|land|token|[A-Z][a-z]+) (you control enters|an opponent controls enters|enters)(?: the battlefield)?( under your control| under an opponent's control)?, (.+)$/",
            // `Whenever another creature you control with flying enters, …`: as `… creature with flying you control enters …`.
            (string) preg_replace('/^(Whenever (?:a|another) creature) you control (with [a-z ]+?) enters/', '$1 $2 you control enters', $line),
            $match
        )
            && ! preg_match('/\b(?:controller|that player)\b/', $match[5])) {
            // See Game::putOntoBattlefield(): the permanent that entered is the context, itself included unless "another".
            $whose = str_contains($match[3].$match[4], 'opponent') ? 'opponent' : (str_contains($match[3].$match[4], 'your') || str_contains($match[3], 'you control') ? 'yours' : 'any');
            $event = "permanent_enters_{$whose}";
            $extra = ['objectKind' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[5];
        } elseif (preg_match('/^Whenever (a|another) (creature|permanent|artifact|enchantment|land|[A-Z][a-z]+) you control becomes the target of a spell or ability( an opponent controls| you control)?, (.+)$/', $line, $match)
            && ! preg_match('/\b(it|its|that (?:spell|ability|creature|permanent))\b/', $match[4])) {
            // See Game::targetedTriggers(): the permanent targeted is the context.
            $event = match ($match[3]) {
                ' an opponent controls' => 'yours_targeted_by_opponent',
                ' you control' => 'yours_targeted_by_you',
                default => 'yours_targeted',
            };
            $extra = ['objectKind' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[4];
        } elseif (preg_match('/^Whenever (an|another) (nontoken )?artifact you control is put into a graveyard from the battlefield, (.+)$/', $line, $match)
            && ! preg_match('/\b(it|its|that artifact)\b/', $match[3])) {
            // See Game::moveTo(): the artifact itself too, unless "another".
            $event = 'artifact_to_graveyard';
            $extra = ['objectKind' => 'artifact', 'other' => $match[1] === 'another'] + ($match[2] !== '' ? ['nontoken' => true] : []);
            $text = $match[3];
        } elseif (preg_match('/^Whenever (an?|another) (enchantment|land|permanent|nontoken permanent|Treasure|Food|Clue|Blood|[A-Z][a-z]+) you control is put into a graveyard( from the battlefield)?, (.+)$/', $line, $match)
            && ($match[3] !== '' || in_array($match[2], ['Treasure', 'Food', 'Clue', 'Blood'], true)) && ! preg_match('/\b(it|its|that (?:card|permanent|land|enchantment))\b/', $match[4])) {
            // See Game::moveTo(): itself too, unless "another". A token goes to a graveyard only from the battlefield.
            $event = 'permanent_to_graveyard_yours';
            $extra = ['objectKind' => $match[2], 'other' => $match[1] === 'another'];
            $text = $match[4];
        } elseif (preg_match('/^Whenever (you sacrifice|a player sacrifices|an opponent sacrifices) (a|an|another) (permanent|creature|artifact|enchantment|land|token|nontoken creature|[A-Z][a-z]+), (.+)$/', $line, $match)
            && ! preg_match('/\b(it|its|that (?:permanent|creature|artifact|enchantment|land|token|player))\b/', $match[4])) {
            // `Whenever you sacrifice a permanent, …`, Mayhem Devil's `Whenever a player sacrifices a permanent, …`: see Game::sacrifice().
            $event = match ($match[1]) {
                'you sacrifice' => 'you_sacrifice',
                'a player sacrifices' => 'player_sacrifices',
                default => 'opponent_sacrifices',
            };
            $extra = ['objectKind' => $match[3], 'other' => $match[2] === 'another'];
            $text = $match[4];
        } elseif (preg_match('/^Whenever (a|an|another) (nontoken )?([A-Z][a-z]+) you control dies, (.+)$/', $line, $match) && ! in_array($match[3], ['Creature', 'Artifact'], true)
            && ($effects = self::effects($match[4])) !== null && ! preg_match('/\b(it|its|that creature)\b/', $match[4])) {
            // `Whenever a Goblin you control dies, …`: another one, and itself when it is one (a nontoken card).
            $events = $match[1] !== 'another' && self::$creature && in_array($match[3], self::$subtypes, true) ? ['creature_dies_other', 'dies'] : ['creature_dies_other'];
            foreach ($events as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects]
                    + ($event === 'creature_dies_other' ? ['subtype' => $match[3], 'other' => $match[1] === 'another'] : []) + ($match[2] !== '' ? ['nontoken' => true] : []);
            }

            return true;
        } elseif (preg_match('/^Whenever a (nontoken )?creature (token )?you control dies, (.+)$/', $line, $match) && ($match[1] === '' || $match[2] === '')
            && ($effects = self::effects($match[3])) !== null) {
            // Once for another creature dying, and once for itself when it is a creature (a card, so never a token). "Nontoken", "token": see Game::trigger().
            foreach (self::$creature && $match[2] === '' ? ['creature_dies_other', 'dies'] : ['creature_dies_other'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($match[1] !== '' ? ['nontoken' => true] : []) + ($match[2] !== '' ? ['tokenOnly' => true] : []);
            }

            return true;
        } elseif (preg_match('/^Whenever a (nontoken )?creature dies, (.+)$/', $line, $match) && ($effects = self::effects($match[2])) !== null && ! preg_match('/\b(target|it|its|that creature)\b/', $match[2])) {
            // Any player's creature, and itself when it is a creature; see "another creature dies" below.
            foreach (self::$creature ? ['creature_dies_other', 'creature_dies_any', 'dies'] : ['creature_dies_other', 'creature_dies_any'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($match[1] !== '' ? ['nontoken' => true] : []);
            }

            return true;
        } elseif (preg_match('/^Whenever (a|another) creature you control with a \+1\/\+1 counter on it dies, (.+)$/', $line, $match)
            && ($effects = self::effects($match[2])) !== null && ! preg_match('/\b(target|it|its|that creature)\b/', $match[2])) {
            // Its counters as it last existed on the battlefield; see Game::$diedWithCounter.
            foreach (self::$creature && $match[1] === 'a' ? ['creature_dies_other', 'dies'] : ['creature_dies_other'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects, 'diedWithCounter' => true];
            }

            return true;
        } elseif ((preg_match('/^Whenever (a|another) creature you control with power (\w+) or greater dies, (.+)$/', $line, $match)
                || preg_match('/^Whenever (a|another) creature you control dies, if it had power (\w+) or greater, (.+)$/', $line, $match))
            && is_int($power = self::amount($match[2])) && ($effects = self::effects($match[3])) !== null && ! preg_match('/\b(target|it|its|that creature)\b/', $match[3])) {
            // Its power as it last existed on the battlefield; see Game::$diedPower. "A creature" on a creature includes itself.
            foreach (self::$creature && $match[1] === 'a' ? ['creature_dies_other', 'dies'] : ['creature_dies_other'] as $event) {
                $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects, 'diedMinPower' => $power];
            }

            return true;
        } elseif (preg_match('/^Whenever another (nontoken )?creature (you control )?dies, (.+)$/', $line, $match)) {
            // `creature_dies_any` fires for other players' creatures; "another creature dies" listens to both.
            $event = 'creature_dies_other';
            $extra = ($match[2] === '' ? ['anyController' => true] : []) + ($match[1] !== '' ? ['nontoken' => true] : []);
            $text = $match[3];
        } elseif (preg_match('/^Whenever a player casts (?:a spell|an? (creature|noncreature|artifact|enchantment|instant or sorcery|multicolored|[A-Z][a-z]+) spell|a spell with (mana value \d+ or (?:greater|less))), (.+)$/', $line, $match)
            && ! preg_match('/\b(that player|its controller|that spell|it)\b/', $match[3])) {
            // Any player's spell, the caster's own included; see Game::castSpell().
            $kind = $match[1] !== '' ? $match[1] : $match[2];
            $event = $kind === '' ? 'any_cast' : 'any_cast_kind';
            $extra = $kind === '' ? [] : ['objectKind' => $kind];
            $text = $match[3];
        } elseif (preg_match('/^Whenever a player casts an? (white|blue|black|red|green) spell, (?:you may gain (\w+) life\.?$|(.+)$)/', $line, $match)) {
            // Kor Firewalker, the Crystal Rods; see Game::castSpell().
            $event = 'any_cast_color_'.['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G'][$match[1]];
            $text = ($match[2] ?? '') !== '' ? "you gain {$match[2]} life." : $match[3];
        } elseif (preg_match('/^Whenever you draw a card, (.+)$/', $line, $match)) {
            $event = 'you_draw';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you draw your second card each turn, (.+)$/', $line, $match)) {
            $event = 'second_draw';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you create (an?|one or more) (?:(creature|artifact|[A-Z][a-z]+) )?tokens?, (.+)$/', $line, $match) && ($match[1] !== 'one or more' || $match[2] === '')
            && ! preg_match('/\b(?:it|that token|they|those tokens)\b/', $match[3])) {
            // See Game::createToken(): "one or more" once per batch of tokens.
            $event = $match[1] === 'one or more' ? 'you_create_tokens' : 'you_create_token';
            $extra = $match[2] !== '' ? ['objectKind' => $match[2]] : [];
            $text = $match[3];
        } elseif (preg_match('/^Whenever you mill one or more cards, (.+)$/', $line, $match)) {
            // See Game::mill(): once per mill that put any card into your graveyard.
            $event = 'you_mill';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you (scry|surveil), (.+)$/', $line, $match)) {
            $event = "you_{$match[1]}";
            $text = $match[2];
        } elseif (preg_match('/^Whenever you lose life(?: during your turn)?, (.+)$/', $line, $match)) {
            // See Game::lostLife(); "during your turn" is checked there.
            $event = str_contains($line, 'during your turn') ? 'you_lose_life_your_turn' : 'you_lose_life';
            $text = $match[1];
        } elseif (preg_match('/^Whenever an opponent gains life, (.+)$/', $line, $match) && ! str_contains($match[1], 'that player')) {
            $event = 'opponent_gains_life';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you gain life( for the first time each turn)?, (.+)$/', $line, $match)) {
            $event = $match[1] !== '' ? 'first_gain_life' : 'gain_life';
            $text = $match[2];
        } elseif (preg_match('/^(?:Heroic — )?Whenever you cast a spell that targets CARDNAME, (.+)$/u', $line, $match)) {
            $event = 'heroic';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you cast an? (?:(instant or sorcery|noncreature|creature) )?spell that targets (?:a|one or more) creatures? (you control|an opponent controls|your opponents control|you don\'t control), (.+)$/u', $line, $match)
            && ! preg_match('/\b(?:it|its|that creature|that spell|those creatures)\b/', $match[3])) {
            // Once for each such spell, however many creatures it targets; an `instant or sorcery spell` checks the spell; see Game::castSpell().
            $event = $match[2] === 'you control' ? 'heroic_any' : 'cast_targets_opponent_creature';
            $extra = $match[1] !== '' ? ['objectKind' => $match[1]] : [];
            $text = $match[3];
        } elseif (preg_match('/^Whenever (you|an opponent) casts? a spell that has an X in its mana cost, (.+)$/', $line, $match)
            && ($match[1] === 'you') === str_starts_with($match[0], 'Whenever you cast ')) {
            // See Game::objectFits().
            $event = $match[1] === 'you' ? 'you_cast_kind' : 'opponent_cast_kind';
            $extra = ['objectKind' => 'x spell'];
            $text = $match[2];
        } elseif (preg_match("/^Whenever you cast your first spell during each opponent's turn, (.+)$/", $line, $match)) {
            // See Game::castSpell().
            $event = 'first_spell_opponent_turn';
            $text = $match[1];
        } elseif (preg_match('/^Whenever you cast your (first|second|third) spell each turn, (.+)$/', $line, $match)) {
            $event = "{$match[1]}_spell";
            $text = $match[2];
        } elseif (preg_match('/^Whenever (you|an opponent) casts? (a (historic|legendary|colorless) spell|a creature spell (?:that has|with) (flying|trample|deathtouch|lifelink|menace|reach|haste|vigilance|first strike|defender)), (.+)$/', $line, $match)
            && ($match[1] === 'you') === str_starts_with($match[0], 'Whenever you cast ')) {
            // Historic: an artifact, legendary or Saga (rule 700.6). See Game::objectFits().
            $event = $match[1] === 'you' ? 'you_cast_kind' : 'opponent_cast_kind';
            $extra = ['objectKind' => $match[4] !== '' ? "creature with {$match[4]}" : $match[3]];
            $text = $match[5];
        } elseif (preg_match('/^Whenever (you|an opponent) casts? a creature spell with (power|toughness) (\w+) or greater, (.+)$/', $line, $match)
            && ($match[1] === 'you') === str_starts_with($match[0], 'Whenever you cast ') && is_int($n = self::amount($match[3]))) {
            // `Whenever you cast a creature spell with power 4 or greater, …`: its printed power; see Game::objectFits().
            $event = $match[1] === 'you' ? 'you_cast_kind' : 'opponent_cast_kind';
            $extra = ['objectKind' => "creature with {$match[2]} {$n} or greater"];
            $text = $match[4];
        } elseif (preg_match('/^Whenever (you|an opponent) casts? (?:an? ((?:creature|noncreature|artifact|enchantment) )?spell with (mana value \d+ or (?:greater|less))|an? ([A-Z][a-z]+) spell), (.+)$/', $line, $match)
            && ($match[1] === 'you') === str_starts_with($match[0], 'Whenever you cast ') && ! in_array($match[4] ?? '', ['Spirit', 'Arcane'], true)) {
            // `Whenever you cast a (creature) spell with mana value 5 or greater, …`, `Whenever you cast a Dragon spell, …`; see Game::castSpell() and Game::objectFits().
            $event = $match[1] === 'you' ? 'you_cast_kind' : 'opponent_cast_kind';
            $extra = ['objectKind' => $match[3] !== '' ? ($match[2] !== '' ? "{$match[2]}with {$match[3]}" : $match[3]) : $match[4]];
            $text = $match[5];
        } elseif (preg_match('/^Whenever (you|an opponent) casts? an? ((?:[A-Z][a-z]+, )*[A-Z][a-z]+,? or [A-Z][a-z]+) spell, (.+)$/', $line, $match)
            && ($match[1] === 'you') === str_starts_with($match[0], 'Whenever you cast ')
            && ($types = array_map(fn (string $word) => self::subtypeOf($word), preg_split('/,? or |, /', $match[2]))) !== [] && ! in_array(null, $types, true)) {
            // `Whenever you cast an Aura, Equipment, or Vehicle spell, …`: any of those subtypes; see Game::objectFits().
            $event = $match[1] === 'you' ? 'you_cast_kind' : 'opponent_cast_kind';
            $extra = ['objectKind' => 'any:'.implode('|', $types)];
            $text = $match[3];
        } elseif (preg_match('/^Whenever you cast ('.implode('|', array_keys($casts)).'), (.+)$/', $line, $match)) {
            $event = $casts[$match[1]];
            $text = $match[2];
        } elseif (preg_match('/^When you cast CARDNAME, (.+)$/', $line, $match) && ! preg_match('/\b(?:it|copy|CARDNAME)\b/i', $match[1])) {
            // `When you cast this spell, draw a card.` (rule 603.2): it triggers from the stack, above the spell, and resolves even if the spell is countered.
            $event = 'cast_self';
            $text = $match[1];
        } else {
            return false;
        }
        // `Whenever you cast your second spell each turn, copy it.`: the spell just cast.
        if ((str_starts_with($event, 'cast_') && $event !== 'cast_self') || in_array($event, ['first_spell', 'first_spell_opponent_turn', 'second_spell', 'third_spell', 'you_cast_kind'], true)) {
            $text = (string) preg_replace('/^copy it\./i', 'copy that spell.', $text);
        }
        if ($event === 'enters' && preg_match("/^if tribute wasn't paid, (.+)$/", $text, $match) && preg_grep('/^tribute \d+$/', $result['keywords']) !== []) {
            $text = $match[1];
        }
        // `if there are three or more cards in your graveyard`: the same as `if you have …`.
        $text = (string) preg_replace('/^if there are (\w+) or more cards in your graveyard, /', 'if you have $1 or more cards in your graveyard, ', $text);
        // An intervening "if" clause (rule 603.4), checked as it triggers; see Game::conditionMet().
        $if = null;
        if (self::effects($text) === null && ($found = self::interveningIf($text)) !== null) {
            if ($found[0] === false) {
                return false;
            }
            [$if, $text] = $found;
        } elseif (self::effects($text) === null && preg_match("/^if (it's night|it's day|you cast it|you cast it from your hand), (.+)$/", $text, $match)
            && ($event === 'enters' || ! str_starts_with($match[1], 'you'))) {
            // Day and night (rule 726): `When CARDNAME enters, if it's night, draw a card.`; and `When CARDNAME enters, if you cast it, …`.
            $if = ['you cast it' => 'cast_it', 'you cast it from your hand' => 'cast_hand'][$match[1]] ?? substr($match[1], 5);
            $text = $match[2];
        }
        // `Whenever CARDNAME deals combat damage to a player, that player mills that many cards.`: the damage it dealt; see Game::combatDamage().
        $dealt = in_array($event, ['combat_damage', 'attached_combat_damage', 'creature_combat_damage', 'creature_combat_damage_other'], true) && preg_match_all('/\bthat (?:many|much)\b/', $text) === 1;
        if ($dealt) {
            $text = (string) preg_replace('/\bthat (?:many|much)\b/', '1', $text);
        }
        // `Whenever CARDNAME deals combat damage to a player, destroy target artifact that player controls.`: with two players, the opponent's.
        if (in_array($event, ['combat_damage', 'attached_combat_damage'], true) && self::effects($text) === null && preg_match('/\btarget [a-z ,]+ that player controls\b/', $text)) {
            $text = (string) preg_replace('/\b(target [a-z ,]+) that player controls\b/', '$1 an opponent controls', $text);
        }
        // `Whenever CARDNAME deals combat damage to a player, that player discards a card.`: the opponent, untargeted.
        if (in_array($event, ['combat_damage', 'attached_combat_damage', 'opponent_cast', 'opponent_cast_kind'], true) && self::effects($text) === null && preg_match('/^that player (discards|loses|mills|sacrifices) /', $text)) {
            $text = 'each opponent '.substr($text, strlen('that player '));
        }
        // `Whenever an opponent casts a noncreature spell, CARDNAME deals 1 damage to that player.`: two players, so the opponent.
        if (in_array($event, ['opponent_cast', 'opponent_cast_kind'], true) && preg_match('/^CARDNAME deals (\w+) damage to that player\.?$/', $text, $match)) {
            $text = "CARDNAME deals {$match[1]} damage to each opponent";
        }
        // `Whenever CARDNAME attacks, defending player loses 1 life.`: with two players, the opponent.
        if (in_array($event, ['attacks', 'attacks_alone', 'creature_attacks', 'creature_attacks_other', 'creature_attacks_alone', 'blocked', 'blocked_by_creature', 'creature_becomes_blocked'], true) && self::effects($text) === null && preg_match('/^defending player (discards|loses|mills|sacrifices) /', $text)) {
            $text = 'each opponent '.substr($text, strlen('defending player '));
        }
        // `Whenever CARDNAME attacks, it deals 1 damage to defending player.`
        if (in_array($event, ['attacks', 'creature_attacks', 'creature_attacks_other'], true) && self::effects($text) === null) {
            $text = (string) preg_replace('/^(?:CARDNAME|it) deals (\w+) damage to defending player\b/', 'CARDNAME deals $1 damage to each opponent', $text);
        }
        $kicked = false;
        if ($event === 'enters' && ($result['kicker'] !== null && preg_match('/^if it was kicked, (.+)$/', $text, $match)
            || isset($result['altCosts']['gift']) && preg_match('/^if the gift was promised, (.+)$/', $text, $match)
            || isset($result['altCosts']['evidence']) && preg_match('/^if evidence was collected, (.+)$/', $text, $match))) {
            [$kicked, $text] = [true, $match[1]];
        }
        // The Thriving creatures: `you may pay {2}{G}. If you do, put a +1/+1 counter on it.`; paid when it can be (see Game::applyEffect()).
        $pay = null;
        // Energy too (Scrapper Champion): `you may pay {E}{E}. If you do, …`.
        if (preg_match('/^you may pay ((?:\{E\})+|'.substr(self::COST, 1).'\. (?:If|When) you do, (.+)$/u', $text, $match) && ! str_contains($match[1], 'X')) {
            [$pay, $text] = [$match[1], $match[2]];
        }
        // `you may sacrifice another creature. If you do, draw two cards.`: see Game's `pay` effect.
        // A reflexive `When you do, …` works out the same; a target is chosen with the trigger.
        if (preg_match('/^you may sacrifice (an?|another) (creature|artifact|enchantment|land|permanent)\. (?:If|When) you do, (.+)$/u', $text, $match)) {
            [$pay, $text] = ["sacrifice {$match[2]}".($match[1] === 'another' ? ' other' : ''), $match[3]];
        }
        // `you may exert CARDNAME. When you do, …`: see Game's `pay` effect; a target is chosen with the trigger.
        if (preg_match('/^you may exert (?:it|CARDNAME)\. (?:If|When) you do, (.+)$/u', $text, $match)) {
            [$pay, $text] = ['exert', $match[1]];
        }
        // Sarkhan, Dragon Ascendant: `you may behold a Dragon. If you do, …`; see Game's `pay` effect.
        if (preg_match('/^you may behold an? ([A-Z][a-z]+)\. (?:If|When) you do, (.+)$/u', $text, $match)) {
            [$pay, $text] = ["behold {$match[1]}", $match[2]];
        }
        // `you may put a +1/+1 counter on target creature. If you do, that creature gains trample …`: done whenever it can be.
        if (preg_match('/^you may ([^.]*\btarget\b[^.]*)\. If you do, (.+)$/u', $text, $match) && self::effects($text) === null
            && self::effects(ucfirst($match[1]).'. '.ucfirst($match[2])) !== null) {
            $text = ucfirst($match[1]).'. '.ucfirst($match[2]);
        }
        // `When CARDNAME dies, you may exile it. If you do, …`: the card it became in the graveyard; see Game's `pay` effect.
        if (in_array($event, ['dies', 'to_graveyard'], true) && preg_match('/^you may exile (?:it|CARDNAME)\. If you do, (.+)$/u', $text, $match)) {
            [$pay, $text] = ['exile self', $match[1]];
        }
        // `you may exile a card from your graveyard. If you do, …`, `you may return a land you control to its owner's hand. …`: see Game's `pay` effect.
        if (preg_match("/^you may (?:exile (a|two|three) cards? from your graveyard|return a land you control to its owner's hand)\\. (?:If|When) you do, (.+)$/u", $text, $match)) {
            [$pay, $text] = [$match[1] !== '' ? 'exile grave '.self::amount($match[1] === 'a' ? 'one' : $match[1]) : 'bounce land', $match[2]];
        }
        // `you may discard a card. When you do, …`; `If you do, draw a card.` is read as a discard effect instead.
        if (preg_match('/^you may discard a card\. (?:If|When) you do, (.+)$/u', $text, $match) && self::effects($text) === null) {
            [$pay, $text] = ['discard', $match[1]];
        }
        // `you may sacrifice a Treasure (it). If you do, …`, `you may pay 2 life. …`, `you may mill two cards. …`: see Game's `pay` effect.
        if (preg_match('/^you may (?:sacrifice (?:an? ([A-Z][a-z]+)|(it|CARDNAME))|pay (\w+) life|mill (\w+) cards?)\. (?:If|When) you do, (.+)$/u', $text, $match)
            && (($match[1] ?? '') === '' || self::subtypeOf($match[1]) !== null)) {
            $pay = match (true) {
                $match[1] !== '' => 'sacrifice sub:'.self::subtypeOf($match[1]),
                $match[2] !== '' => 'sacrifice self',
                $match[3] !== '' => 'life '.self::amount($match[3]),
                default => 'mill '.self::amount($match[4]),
            };
            $text = $match[5];
        }
        // `you may forage. If you do, …` and `you may tap an untapped creature you control. If you do, …`: see Game's `pay` effect.
        if (preg_match('/^you may forage\. (?:If|When) you do, (.+)$/u', $text, $match)) {
            [$pay, $text] = ['forage', $match[1]];
        }
        if (preg_match('/^you may tap (an|another) untapped (creature|artifact) you control\. (?:If|When) you do, (.+)$/u', $text, $match)) {
            [$pay, $text] = ["tap {$match[2]}".($match[1] === 'another' ? ' other' : ''), $match[3]];
        }
        // `you may collect evidence 6. If you do, …` (rule 701.59): see Game's `pay` effect.
        if (preg_match('/^(?:you may )?collect evidence (\d+)\. If you do, (.+)$/u', $text, $match) && ! str_contains($match[2], 'target')) {
            [$pay, $text] = ["evidence {$match[1]}", $match[2]];
        }
        // `you may pay any amount of {E}. Put that many +1/+1 counters on it.`: X is the energy paid.
        if (preg_match('/^you may pay any amount of \{E\}\. (?:If you do, )?(.+?)(?:, where X is the amount of \{E\} paid)?\.?$/u', $text, $match)) {
            [$pay, $text] = ['any {E}', preg_replace('/\bthat many\b/', 'X', $match[1])];
        }
        // `Whenever another creature you control enters, put a +1/+1 counter on it.`: "it" is the creature that entered.
        $entering = in_array($event, ['attached_attacks', 'attached_blocks', 'attached_combat_damage', 'permanent_enters_yours', 'permanent_enters_any', 'permanent_enters_opponent', 'creature_enters_other', 'subtype_enters', 'creature_attacks', 'creature_attacks_other', 'creature_attacks_alone', 'creature_blocks', 'creature_becomes_blocked', 'creature_becomes_tapped', 'opponent_creature_becomes_tapped', 'creature_explores', 'permanent_turned_face_up', 'opponent_creature_attacks', 'creature_combat_damage', 'creature_combat_damage_other'], true);
        // `Whenever CARDNAME blocks (becomes blocked by) a creature, that creature gets -1/-0 …`: the other creature; "it" stays CARDNAME.
        if (in_array($event, ['blocks_creature', 'blocked_by_creature'], true) && str_contains($text, 'that creature') && ! str_contains($text, 'target') && ! str_contains($text, 'CARDNAME')) {
            $effects = self::effects(str_replace('that creature', 'CARDNAME', $text));
            $effects = $effects === null ? null : array_map(fn (array $effect) => ($effect['self'] ?? false) ? ['entering' => true] + array_diff_key($effect, ['self' => true]) : $effect, $effects);
        } elseif ($entering && preg_match('/^you may attach CARDNAME to (?:it|that creature)\.?$/', $text)) {
            $effects = [['type' => 'attach', 'entering' => true]];
        } elseif ($entering && ! str_contains($text, 'target') && ! str_contains($text, 'CARDNAME')) {
            $effects = self::effects(preg_replace('/\b(?:it|that creature)\b/', 'CARDNAME', $text));
            $effects = $effects === null ? null : array_map(fn (array $effect) => ($effect['self'] ?? false) ? ['entering' => true] + array_diff_key($effect, ['self' => true]) : $effect, $effects);
        } else {
            $effects = self::effects($text);
        }
        if ($effects === null && ! $entering && ! str_contains($text, 'target')) {
            // "Whenever CARDNAME attacks, put a +1/+1 counter on it.": with no target, "it" is CARDNAME.
            $effects = self::effects(preg_replace('/\bit\b/', 'CARDNAME', $text));
        }
        if ($effects !== null && $pay !== null) {
            $effects = [['type' => 'pay', 'cost' => $pay, 'then' => $effects] + ($pay === 'exile self' ? ['self' => true] : [])];
            // The paid-for effects' one target is chosen with the trigger; two different ones are not read yet.
            $aimed = array_unique(array_column($effects[0]['then'], 'target'));
            if (count($aimed) > 1) {
                return false;
            }
            if ($aimed !== []) {
                $effects[0]['target'] = reset($aimed);
            }
        }
        if ($effects === null) {
            return false;
        }
        if ($dealt) {
            if (count($effects) !== 1 || ($effects[0]['amount'] ?? null) !== 1 || isset($effects[0]['per'])) {
                return false;
            }
            $effects[0]['amount'] = 'combat_dealt';
        }
        // Returning itself to the battlefield only makes sense from the graveyard (see Game::trigger()).
        if (! in_array($event, ['dies', 'to_graveyard'], true) && in_array('return_self', array_column($effects, 'type'), true)) {
            return false;
        }
        $result['triggered'][] = ['text' => $line, 'event' => $event, 'effects' => $effects] + ($kicked ? ['kicked' => true] : []) + ($saddled ? ['saddled' => true] : []) + ($if !== null ? ['if' => $if] : []) + array_diff_key($extra ?? [], ['anyController' => true]);
        if ($extra['anyController'] ?? false) {
            $result['triggered'][] = ['event' => 'creature_dies_any'] + $result['triggered'][count($result['triggered']) - 1];
        }

        return true;
    }

    /**
     * An activated ability: a cost of mana, `{T}`, `Sacrifice CARDNAME`
     * and `Pay N life`, then its effect, then optionally `Activate only as
     * a sorcery.` or `Activate only once each turn.`
     *
     * @param string $line
     * @param array  $result
     *
     * @return bool
     */
    private static function activated(string $line, array &$result): bool
    {
        // Exhaust (rule 702.177): `Exhaust — {cost}: …` activates only once.
        [$ever, $exhaust] = [false, false];
        if (str_starts_with($line, 'Exhaust — ')) {
            [$ever, $exhaust, $line] = [true, true, substr($line, strlen('Exhaust — '))];
        }
        // Power-up: once, as a sorcery (the discount when it entered this turn is not taken).
        $powerUp = false;
        if (str_starts_with($line, 'Power-up — ')) {
            [$ever, $powerUp, $line] = [true, true, substr($line, strlen('Power-up — '))];
        }
        // Channel: `Channel — {1}{G}, Discard CARDNAME: …`, activated from your hand; see Game::whyNotActivate().
        if (str_starts_with($line, 'Channel — ')) {
            $line = substr($line, strlen('Channel — '));
        }
        // Reinforce (rule 702.77): `Reinforce 2—{1}{W}`.
        if (preg_match('/^Reinforce (\w+)—((?:\{[0-9WUBRGC\/]+\})+)\.?$/u', $line, $reinforce) && is_int($n = self::amount($reinforce[1]))) {
            $line = "{$reinforce[2]}, Discard CARDNAME: Put {$reinforce[1]} +1/+1 counter".($n === 1 ? '' : 's').' on target creature.';
        }
        if (! preg_match('/^([^:"]+): (.+)$/', $line, $match)) {
            return false;
        }
        $cost = [];
        foreach (array_map('trim', explode(',', $match[1])) as $part) {
            if ($part === '{T}') {
                $cost['tap'] = true;
            } elseif (preg_match('/^(\{[0-9WUBRGCX\/P]+\})+$/', $part) && ! isset($cost['waterbend'])) {
                $cost['mana'] = ($cost['mana'] ?? '').$part;
            } elseif (preg_match('/^[Ww]aterbend (\{\d+\})$/', $part, $water) && ! isset($cost['mana'])) {
                // Waterbend (TLA): untapped artifacts and creatures can be tapped for {1} each; see Game::abilityPayment().
                [$cost['mana'], $cost['waterbend']] = [$water[1], true];
            } elseif ($part === 'Sacrifice CARDNAME') {
                $cost['sacrifice'] = true;
            } elseif ($part === 'Remove three quest counters from CARDNAME and sacrifice it') {
                $cost += ['remove' => ['quest', 3], 'sacrifice' => true];
            } elseif ($part === 'Discard CARDNAME') {
                $cost['discardSelf'] = true;
            } elseif ($part === 'Exile CARDNAME from your graveyard') {
                // Renew and the like: activated from your graveyard; see Game::activatable().
                $cost['exileSelf'] = true;
            } elseif (preg_match('/^Sacrifice (an?|another) (creature|artifact|enchantment|land|permanent|token|nontoken creature|[A-Z][a-z]+)$/', $part, $sac)) {
                // Nantuko Husk: the weakest creature (or artifact, Food, …); see Game::fodder().
                $cost['fodder'] = ($sac[1] === 'another' ? 'other' : 'any').' '.$sac[2];
            } elseif (preg_match('/^Sacrifice (two|three|four|five) (other )?(creature|artifact|land|permanent)s$/', $part, $sac)) {
                $cost += ['fodder' => ($sac[2] !== '' ? 'other' : 'any').' '.$sac[3], 'fodderCount' => self::amount($sac[1])];
            } elseif (preg_match('/^Tap (an|two|three|four|five) untapped (creature|artifact|land|[A-Z][a-z]+)s? you control$/', $part, $tapCost)) {
                // Tapped as a cost: never the source itself, which `{T}` taps; see Game::fodderMany().
                $cost['tapOthers'] = [$tapCost[1] === 'an' ? 1 : self::amount($tapCost[1]), $tapCost[2]];
            } elseif (preg_match('/^Discard (two|three) cards$/', $part, $discards)) {
                $cost['discard'] = self::amount($discards[1]);
            } elseif (preg_match('/^Mill (a|two|three|four|five) cards?$/', $part, $mills)) {
                $cost['mill'] = $mills[1] === 'a' ? 1 : self::amount($mills[1]);
            } elseif (preg_match('/^((?:\{E\})+)$/', $part, $energy)) {
                $cost['energy'] = substr_count($energy[1], '{E}');
            } elseif (preg_match('/^Exile an? (creature|artifact|land|instant or sorcery|enchantment|[A-Z][a-z]+) card from your graveyard$/', $part, $grave)) {
                $cost += ['exileGrave' => 1, 'exileGraveKind' => $grave[1]];
            } elseif (preg_match('/^Exile (\w+) cards? from your graveyard$/', $part, $grave) && is_int($n = self::amount($grave[1]))) {
                $cost['exileGrave'] = $n;
            } elseif ($part === "Return CARDNAME to its owner's hand") {
                $cost['bounceSelf'] = true;
            } elseif (preg_match("/^Return (an?|another) (untapped )?(creature|artifact|enchantment|land|[A-Z][a-z]+) you control to its owner's hand$/", $part, $bounce)) {
                // Paid with a tapped one first; see Game::bounceFodder().
                $cost['bounce'] = [($bounce[1] === 'another' ? 'other' : 'any').' '.$bounce[3], $bounce[2] !== ''];
            } elseif (preg_match('/^Remove (a|an|one|two|three) ([+-]1\/[+-]1|\w+) counters? from (a|another) (creature|artifact|permanent) you control$/', $part, $remove) && is_int($n = in_array($remove[1], ['a', 'an'], true) ? 1 : self::amount($remove[1]))) {
                // From the permanent with the most of them; see Game::counterHost().
                $cost['removeAny'] = [$remove[2], $n, $remove[4], $remove[3] === 'another'];
            } elseif ($part === 'Forage') {
                // Forage (Bloomburrow): exile three cards from your graveyard or sacrifice a Food.
                $cost['forage'] = true;
            } elseif ($part === 'Discard a card') {
                // Paid with the cheapest card in hand; see Game::discardCheapest().
                $cost['discard'] = 1;
            } elseif (preg_match('/^Pay (\d+) life$/', $part, $life)) {
                $cost['life'] = (int) $life[1];
            } elseif (preg_match('/^Pay ((?:\{E\})+)$/', $part, $energy)) {
                $cost['energy'] = substr_count($energy[1], '{E}');
            } elseif (preg_match('/^Remove (\w+) (\w+) counters? from CARDNAME$/', $part, $remove) && is_int($n = self::amount($remove[1])) && $remove[2] !== 'loyalty') {
                $cost['remove'] = [$remove[2], $n];
            } else {
                return false;
            }
        }
        // `{X}{R}: CARDNAME gets +X/+0 until end of turn.`: see Game::abilityPayment() for X.
        $xCost = str_contains($cost['mana'] ?? '', 'X');

        $text = $match[2];
        $sorcery = $once = false;
        $perTurn = null;
        if (preg_match('/^(.+?)\s*Activate only as a sorcery\.$/', $text, $limit)) {
            [$text, $sorcery] = [$limit[1], true];
        } elseif (preg_match('/^(.+?)\s*Activate only once each turn\.$/', $text, $limit)) {
            [$text, $once] = [$limit[1], true];
        } elseif (preg_match('/^(.+?)\s*Activate no more than (twice|three times) each turn\.$/', $text, $limit)) {
            // See Game::whyNotActivate() and GameObject::$activations.
            [$text, $perTurn] = [$limit[1], $limit[2] === 'twice' ? 2 : 3];
        }
        if (preg_match('/^(.+?)\s*Activate only once\.$/', $text, $limit)) {
            [$text, $ever] = [$limit[1], true];
        }
        // `Activate only if you control an artifact.`, `Activate only during your turn.`, `… during combat.`: checked as it is activated; see Game::conditionMet().
        $only = null;
        $windows = ['during your turn' => 'your_turn', "during an opponent's turn" => 'opponent_turn', 'during combat' => 'combat', 'before blockers are declared' => 'before_blockers', 'during your upkeep' => 'your_upkeep', 'during any upkeep step' => 'upkeep'];
        if (preg_match('/^(.+?)\s*Activate only (during your turn|during an opponent\'s turn|during combat|before blockers are declared|during your upkeep|during any upkeep step)\.$/', $text, $limit)) {
            [$text, $only] = [$limit[1], $windows[$limit[2]]];
        } elseif (preg_match('/^(.+?)\s*Activate only if (.+)\.$/', $text, $limit)) {
            if (! is_string($only = self::interveningIf("if {$limit[2]}, x")[0] ?? null)) {
                return false;
            }
            $text = $limit[1];
        }
        if ($cost['sacrifice'] ?? false) {
            // `Sacrifice CARDNAME: It deals 2 damage to target creature.`: "it" is the sacrificed permanent.
            $text = preg_replace('/^It deals /', 'CARDNAME deals ', $text);
        }
        $effects = self::effects($text);
        if ($effects === null || $xCost && ! preg_match('/"X"|xPump/', (string) json_encode($effects))) {
            return false;
        }
        $result['activated'][] = ['text' => $line, 'cost' => $cost, 'effects' => $effects, 'sorcery' => $sorcery || $powerUp, 'once' => $once] + ($ever ? ['ever' => true] : []) + ($exhaust ? ['exhaust' => true] : []) + (($cost['discardSelf'] ?? false) ? ['hand' => true] : []) + (($cost['exileSelf'] ?? false) ? ['graveyard' => true] : []) + ($only !== null ? ['if' => $only] : []) + ($perTurn !== null ? ['perTurn' => $perTurn] : []);

        return true;
    }

    /**
     * An intervening "if" clause (rule 603.4) at the start of a trigger's
     * effect text, as a Game::conditionMet() condition and the rest of the
     * text; false for a clause it matches but cannot express.
     *
     * @param string $text Such as `if you control an artifact, draw a card.`
     *
     * @return array{0: string|false, 1: string}|null
     */
    private static function interveningIf(string $text): ?array
    {
        if (! preg_match("/^if (CARDNAME isn't prepared|you control three or more creatures with different powers|you control (\\w+) or more lands|(\\w+) or more creatures died this turn|an opponent has more cards in hand than you|you gained (\\w+) or more life this turn|you gained life this turn|a creature died this turn|you attacked this turn|CARDNAME attacked this turn|an opponent lost life this turn|you have (\\w+) or more life|you control an? (artifact|enchantment|[A-Z][a-z]+)|you control (\\w+) or more (creatures|artifacts|enchantments)|you have (\\w+) or more cards in your graveyard|you have (no|one or fewer|two or fewer) cards in hand|it's not your turn|it's your turn|you've cast a noncreature spell this turn|CARDNAME is tapped|CARDNAME is untapped|a nonland permanent left the battlefield this turn or a spell was warped this turn|you control no (?:other )?creatures|an opponent controls more (?:lands|creatures) than you|an opponent has more life than you|you control another (?<other>artifact|enchantment|creature|[A-Z][a-z]+)|you control (?<count>\\w+) or more other (?<others>creatures|artifacts|[A-Z][a-z]+)|an opponent controls an? (?<theirs>creature with flying|artifact|enchantment|[A-Z][a-z]+)|you attacked with (?<attackers>\\w+) or more creatures this (?:combat|turn)|you control a creature with power (?<power>\\w+) or greater|you have (?<lifeLe>\\w+) or less life|you have more life than each opponent|you control no other (?<noOther>artifacts|enchantments)|you(?:'ve)? cast (?<spells>\\w+) or more spells this turn|two or more nonland permanents entered the battlefield under your control this turn), (?<rest>.+)$/", $text, $match)) {
            return null;
        }
        $if = match (true) {
            $match[1] === 'you control no creatures' => 'no_creatures',
            $match[1] === 'you control no other creatures' => 'no_other_creatures',
            // Ferocious: `if you control a creature with power 4 or greater`.
            ($match['power'] ?? '') !== '' => 'power_ge '.self::amount($match['power']),
            // Coven: `if you control three or more creatures with different powers`.
            $match[1] === 'you control three or more creatures with different powers' => 'coven',
            $match[1] === "CARDNAME isn't prepared" => 'unprepared',
            $match[1] === 'you gained life this turn' => 'gained 1',
            $match[1] === 'a creature died this turn' => 'died 1',
            $match[1] === 'you attacked this turn' => 'attacked',
            $match[1] === 'CARDNAME attacked this turn' => 'self_attacked',
            $match[1] === 'an opponent lost life this turn' => 'opponent_lost',
            $match[1] === "it's not your turn" => 'not_your_turn',
            $match[1] === "it's your turn" => 'your_turn',
            // Survival: `At the beginning of your second main phase, if CARDNAME is tapped, …`.
            $match[1] === 'CARDNAME is tapped' => 'tapped',
            $match[1] === 'CARDNAME is untapped' => 'untapped',
            $match[1] === 'a nonland permanent left the battlefield this turn or a spell was warped this turn' => 'void',
            $match[1] === "you've cast a noncreature spell this turn" => 'cast_noncreature',
            // Knight of the White Orchid: `if an opponent controls more lands than you`.
            $match[1] === 'an opponent controls more lands than you' => 'lands_behind',
            $match[1] === 'an opponent controls more creatures than you' => 'creatures_behind',
            $match[1] === 'an opponent has more life than you' => 'life_behind',
            // Celebration: `if two or more nonland permanents entered the battlefield under your control this turn`.
            $match[1] === 'two or more nonland permanents entered the battlefield under your control this turn' => 'celebration',
            $match[1] === 'you have more life than each opponent' => 'life_lead',
            // `if you have 10 or less life`.
            ($match['lifeLe'] ?? '') !== '' => 'life_le '.self::amount($match['lifeLe']),
            // `if you've cast two or more spells this turn`; see GamePlayer::$cast.
            ($match['spells'] ?? '') !== '' => 'spells_cast '.self::amount($match['spells']),
            // `if you control no other artifacts`.
            ($match['noOther'] ?? '') !== '' => 'no_other '.ucfirst(rtrim($match['noOther'], 's')),
            // Lorwyn: `When this creature enters, if you control another Elf, …`.
            ($match['other'] ?? '') !== '' => 'controls_other '.ucfirst($match['other']),
            // `if an opponent controls a creature with flying` / `… an artifact`.
            ($match['theirs'] ?? '') !== '' => $match['theirs'] === 'creature with flying' ? 'opp_flier' : 'opp_controls '.ucfirst($match['theirs']),
            // `if you attacked with three or more creatures this combat`; see Game::declareAttackers().
            ($match['attackers'] ?? '') !== '' => 'attackers '.self::amount($match['attackers']),
            ($match['others'] ?? '') !== '' => 'controls_others '.($match['others'] === 'creatures' ? 'Creature' : ($match['others'] === 'artifacts' ? 'Artifact' : self::subtypeOf($match['others']))).'_'.self::amount($match['count']),
            ($match[7] ?? '') !== '' => strtolower($match[8]).' '.self::amount($match[7]),
            ($match[9] ?? '') !== '' => 'graveyard '.self::amount($match[9]),
            ($match[10] ?? '') !== '' => 'hand_le '.match ($match[10]) {
                'no' => 0,
                'one or fewer' => 1,
                default => 2,
            },
            ($match[5] ?? '') !== '' => 'life '.self::amount($match[5]),
            ($match[6] ?? '') !== '' => 'controls '.ucfirst($match[6]),
            $match[2] !== '' => 'lands '.self::amount($match[2]),
            $match[3] !== '' => 'died '.self::amount($match[3]),
            $match[4] !== '' => 'gained '.self::amount($match[4]),
            default => 'hand_behind',
        };

        return [preg_match('/^\w+( \w+)?$/', $if) ? $if : false, $match['rest']];
    }

    /**
     * Every sentence of an ability's text as effects, or null when any is
     * not understood.
     *
     * @param string $text
     *
     * @return array[]|null
     */
    public static function effects(string $text): ?array
    {
        // `Look at the top card of your library. You may put that card into your graveyard.`: surveil 1 in all but name, so no `Whenever you surveil` triggers.
        if (preg_match('/^[Ll]ook at the top card of your library\. You may put (?:that card|it) into your graveyard\.?$/', trim($text))) {
            return [['type' => 'surveil', 'amount' => 1, 'quiet' => true]];
        }
        if (($impulse = self::impulse($text)) !== null) {
            return [$impulse];
        }
        // Haste Magic: `Target creature gets +3/+1 … Exile the top card of your library. You may play it until your next end step.`
        if (preg_match('/^(.+?\.)\s+(Exile the top .*)$/s', trim($text), $split) && ($impulse = self::impulse($split[2])) !== null) {
            $before = self::effects($split[1]);

            return $before === null ? null : [...$before, $impulse];
        }
        $effects = [];
        // `Stress Dream deals 5 damage … Look at the top two cards …`: the sentences before the look, then the look.
        if (preg_match('/^(.+?\.)\s+((?:Look at|Reveal) the top .*)$/s', trim($text), $split) && self::look($split[2]) !== null) {
            $before = self::effects($split[1]);
            if ($before === null) {
                return null;
            }
            [$effects, $text] = [$before, $split[2]];
        }
        if (($look = self::look($text)) !== null) {
            [$effects[], $text] = $look;
            if ($text === '') {
                return $effects;
            }
        }
        if (($reveal = self::revealDiscard($text)) !== null) {
            [$effects[], $text] = $reveal;
            if ($text === '') {
                return $effects;
            }
        }
        foreach (self::sentences($text) as $sentence) {
            if (self::modifies($sentence, $effects)) {
                continue;
            }
            // In an ability, "it" at the start means the card itself: "When CARDNAME enters, it deals 4 damage …".
            $found = self::sentenceEffects(preg_replace('/^it /', 'CARDNAME ', $sentence));
            // `You may return target artifact card from your graveyard to your hand.`: choosing the target is choosing to.
            if ($found === null && preg_match('/^you may ((?:return|exile|tap|untap|destroy) (?:up to one )?(?:another )?target .+)$/i', $sentence, $may)) {
                $found = self::sentenceEffects(ucfirst($may[1]));
            }
            if ($found === null) {
                return null;
            }
            array_push($effects, ...$found);
        }

        return $effects === [] ? null : $effects;
    }

    /**
     * `Look at the top four cards of your library. Put one of them into
     * your hand and the rest on the bottom of your library in any order.`,
     * `… You may reveal a creature or land card from among them and put it
     * into your hand. Put the rest …`, `Reveal the top five cards …`, or
     * `… then put them back in any order.`
     *
     * @param string $text
     *
     * @return array|null
     */
    private static function look(string $text): ?array
    {
        if (! preg_match('/^(Look at|Reveal) the top (\w+) cards of your library(, where X is the number of lands you control)?(.*)$/s', ucfirst(trim($text)), $head) || (! is_int($n = self::amount($head[2])) && $n !== 'X') || $n === 0) {
            return null;
        }
        if ($head[3] !== '') {
            if ($n !== 'X') {
                return null;
            }
            $n = 'lands';
        }
        $head[3] = $head[4];
        // `Put up to two creature cards with mana value 3 or less from among them onto the battlefield.` (Collected Company)
        $mv = null;
        if (preg_match('/^(\. (?:You may put|Put) (?:an?|up to \w+) .+?) cards? with mana value (\d+) or (less|greater)( from among them.*)$/s', $head[3], $w)) {
            [$head[3], $mv] = [$w[1].' cards'.$w[4], 'mv_'.($w[3] === 'less' ? 'le' : 'ge').'_'.$w[2]];
        }
        $head[3] = (string) preg_replace('/^\. Put (up to \w+) /', '. You may put $1 ', $head[3]);
        $where = '(on the bottom of your library(?: in (?:a random|any) order)?|into your graveyard)';
        $to = '(into your hand|onto the battlefield tapped|onto the battlefield)';
        $after = '(?:\.|$)\s*(.*)$/s';
        $dest = 'hand';
        if ($head[1] === 'Look at' && preg_match("/^, then put them back in any order{$after}", $head[3], $m)) {
            [$take, $may, $filter, $rest, $tail] = [0, true, 'any', 'top', $m[1]];
        } elseif (preg_match("/^\\. Put (up to )?(\\w+)(?: of (?:them|those cards))? into your hand and the (?:rest|other|others) {$where}{$after}", $head[3], $m) && is_int($take = self::amount($m[2]))) {
            [$may, $filter, $rest, $tail] = [$m[1] !== '', 'any', $m[3], $m[4]];
        } elseif (preg_match("/^\\. (?:You may (?:reveal|put|choose) (an?|up to \\w+|any number of) (?:(.+?) )?cards? from among them(?: and put (?:it|that card|them|those cards|the revealed cards) {$to}| {$to})?|Put all (.+?) cards revealed this way {$to})(?:\\. (?:Then p|P)ut the rest| and the rest) {$where}{$after}", $head[3], $m)) {
            if (($m[5] ?? '') !== '') {
                [$take, $may, $words, $place] = [is_int($n) ? $n : 99, false, $m[5], $m[6]];
            } else {
                $take = match (true) {
                    $m[1] === 'any number of' => is_int($n) ? $n : 99,
                    str_starts_with($m[1], 'up to') => self::amount(substr($m[1], 6)),
                    default => 1,
                };
                [$may, $words, $place] = [true, $m[2], ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : 'into your hand')];
            }
            if (preg_match('/^(\w+) card and\/or an? (\w+)$/', $words, $pair)) {
                // `a creature card and/or a land card`: one of each, at most two.
                [$words, $take] = ["{$pair[1]} or {$pair[2]}", 2];
            }
            // `noncreature, nonland permanent`: all of them, not any.
            $filter = $words === '' ? 'any' : (preg_match('/^non[a-z]+, /', $words) ? self::revealFilter("{$words} cards") : self::cardFilter($words));
            if ($filter === null || ! is_int($take)) {
                return null;
            }
            if ($mv !== null) {
                $filter .= "&{$mv}";
            }
            [$rest, $tail, $dest] = [$m[7], $m[8], match ($place) {
                'onto the battlefield tapped' => 'tapped',
                'onto the battlefield' => 'battlefield',
                default => 'hand',
            }];
        } else {
            return null;
        }

        return [[
            'type' => 'look',
            'amount' => $n,
            'take' => $take,
            'may' => $may,
            'filter' => $filter,
            'rest' => $rest === 'top' ? 'top' : (str_starts_with($rest, 'into') ? 'graveyard' : 'bottom'),
        ] + ($dest === 'hand' ? [] : ['to' => $dest]), trim($tail)];
    }

    /**
     * `Exile the top two cards of your library. Until the end of your next
     * turn, you may play those cards.`, or `… You may play that card this turn.`
     *
     * @param string $text
     *
     * @return array|null
     */
    private static function impulse(string $text): ?array
    {
        // Also inside a trigger: `When CARDNAME enters, exile the top card of your library. …`
        if (! preg_match('/^[Ee]xile the top (card|(\w+) cards) of your library\. (.+?)\.?$/', trim($text), $m)) {
            return null;
        }
        $it = '(?:that card|those cards|them|it|the exiled cards?)';
        $until = match (true) {
            (bool) preg_match("/^(?:Until end of turn, you may play {$it}|You may play {$it} (?:this turn|until end of turn))$/", $m[3]) => 'this',
            (bool) preg_match("/^(?:Until the end of your next turn, you may play {$it}|You may play {$it} until the end of your next turn)$/", $m[3]) => 'next',
            // Haste Magic: the end step of this turn when it is yours, else of your next turn.
            (bool) preg_match("/^You may play {$it} until your next end step$/", $m[3]) => 'end_step',
            // Galvanic Relay: only during your next turn.
            (bool) preg_match("/^During your next turn, you may play {$it}$/", $m[3]) => 'during_next',
            default => null,
        };
        $n = $m[1] === 'card' ? 1 : self::amount($m[2]);
        if ($until === null || ! is_int($n) || $n < 1) {
            return null;
        }

        return ['type' => 'impulse', 'amount' => $n, 'until' => $until];
    }

    /**
     * The cards a phrase like `creature or land`, `Dwarf or Equipment` or
     * `nonland permanent` names, as a filter for {@see Game::matchesFilter()}:
     * kinds joined by `|`, subtypes as `sub:Dwarf`.
     *
     * @param string $words
     *
     * @return string|null
     */
    private static function cardFilter(string $words): ?string
    {
        // Clay-Fired Bricks requires both a Basic supertype and the Plains subtype.
        // Keep mixed subtype alternatives unread until their grouping is supported.
        if ($words === 'basic Plains') {
            return 'basic_land&sub:Plains';
        }
        $kinds = [
            'creature' => 'creature', 'land' => 'land', 'artifact' => 'artifact', 'enchantment' => 'enchantment', 'instant' => 'instant',
            'sorcery' => 'sorcery', 'planeswalker' => 'planeswalker', 'permanent' => 'permanent', 'nonland' => 'nonland',
            'noncreature' => 'noncreature', 'nonland permanent' => 'nonland_permanent', 'battle' => 'battle', 'colorless' => 'colorless',
        ];
        $pieces = [];
        foreach (preg_split('/,? or |, /', $words) as $piece) {
            if (isset($kinds[$piece])) {
                $pieces[] = $kinds[$piece];
            } elseif (preg_match('/^[A-Z][a-z]+$/', $piece)) {
                $pieces[] = "sub:{$piece}";
            } else {
                return null;
            }
        }

        return implode('|', $pieces);
    }

    /**
     * `Target opponent reveals their hand. You choose a nonland card from
     * it. That player discards that card.`: the caster picks the discard.
     *
     * @param string $text
     *
     * @return array{0: array, 1: string}|null The effect, and the text after it.
     */
    private static function revealDiscard(string $text): ?array
    {
        $filters = ['card' => 'any', 'nonland card' => 'nonland', 'creature card' => 'creature', 'noncreature card' => 'noncreature', 'noncreature, nonland card' => 'noncreature_nonland', 'instant or sorcery card' => 'instant_sorcery', 'nonland permanent card' => 'nonland_permanent', 'creature or planeswalker card' => 'creature|planeswalker', 'artifact or creature card' => 'artifact|creature'];
        // `… That player discards that card.`, or `… and exile that card.` / `… Exile that card.`
        // `You may choose …. If you do, that player discards that card.`: choosing is always worth it.
        // Inquisition of Kozilek: `You choose a nonland card from it with mana value 3 or less.`
        if (! preg_match("/^(Target opponent|Target player) reveals (?:their|his or her) hand\. You (?:may )?choose an? ([^.]+?) from it( with mana value \d+ or (?:less|greater))?(\. (?:If you do, that|That) player discards that card| and exile that card|\. Exile that card)\.?\s*(.*)$/s", ucfirst(trim($text)), $m)
            || ($filter = ($m[3] === '' ? $filters[$m[2]] ?? null : null) ?? self::revealFilter($m[2].$m[3])) === null) {
            return null;
        }

        return [['type' => 'discard', 'amount' => 1, 'target' => self::TARGETS[strtolower($m[1])], 'chooser' => 'you', 'filter' => $filter] + (str_contains($m[4], 'xile') ? ['exile' => true] : []), trim($m[5])];
    }

    /**
     * The card a hand-reveal discard picks, beyond the common ones:
     * `nonland card with mana value 3 or less`, `nonartifact, nonland card`
     * or `artifact or enchantment card`, as a filter for Game::matchesFilter()
     * (`&` joins filters that must all hold).
     *
     * @param string $words
     *
     * @return string|null
     */
    private static function revealFilter(string $words): ?string
    {
        $all = [];
        if (preg_match('/^(.+?) with mana value (\d+) or (less|greater)$/', $words, $m)) {
            [$words, $all[]] = [$m[1], 'mv_'.($m[3] === 'less' ? 'le' : 'ge').'_'.$m[2]];
        }
        if (! preg_match('/^(.+?) cards?$/', $words, $m)) {
            return null;
        }
        // `noncreature, nonland`: every one of them; `noncreature, nonland permanent`: and a permanent.
        if (preg_match('/^non[a-z]+(?:, non[a-z]+)+(?: permanent)?$/', $m[1])) {
            foreach (explode(', ', $m[1]) as $piece) {
                $all[] = str_contains($piece, ' ') ? self::cardFilter($piece) : 'not:'.substr($piece, 3);
            }
        } elseif (($filter = self::cardFilter($m[1])) !== null) {
            $all[] = $filter;
        } else {
            return null;
        }

        return implode('&', $all);
    }

    /**
     * One sentence as effects: one, or two joined by `, then` (`Draw two
     * cards, then discard a card`).
     *
     * @param string $sentence
     *
     * @return array[]|null
     */
    private static function sentenceEffects(string $sentence): ?array
    {
        // `… for each creature you control with a +1/+1 counter on it.`: read as `for each creature you control`, then narrowed; see Game::countYours().
        if (preg_match('/^(.+ for each creature you control) with an? \+1\/\+1 counter on it$/', $sentence, $m) && ($found = self::sentenceEffects($m[1])) !== null
            && count($found) === 1 && ($found[0]['per'] ?? null) === 'yours:creature') {
            return [['per' => 'yours:countered creature'] + $found[0]];
        }
        // Reforge the Soul: `Each player discards their hand, then draws seven cards.`; `Discard your hand, then draw four cards.`
        if (preg_match('/^(Discard your hand|Each player discards their hand)(?:, then (draws?) (\w+) cards?)?$/', $sentence, $m)
            && (! isset($m[2]) || ($m[2] === 'draw') === str_starts_with($m[1], 'Discard')) && (! isset($m[3]) || is_int(self::amount($m[3])))) {
            $each = str_starts_with($m[1], 'Each') ? ['each' => 'player'] : [];

            return [['type' => 'discard', 'amount' => 0, 'all' => true] + $each, ...(isset($m[3]) ? [['type' => 'draw', 'amount' => self::amount($m[3])] + $each] : [])];
        }
        // Airbend (TLA): exile the permanent; its owner may cast it for {2} while it stays exiled. See Game's `airbend` effect.
        if (preg_match('/^([Aa])irbend (.+)$/', $sentence, $m) && ($found = self::sentenceEffects(($m[1] === 'A' ? 'E' : 'e')."xile {$m[2]}")) !== null
            && count($found) === 1 && $found[0]['type'] === 'exile' && array_keys($found[0]) === ['type', 'target'] && ! preg_match('/card|spell|player|opponent|^\??any$/', $found[0]['target'])) {
            return [['type' => 'airbend'] + $found[0]];
        }
        // `It deals 1 damage to each opponent and each creature your opponents control.`: one damage effect for each group.
        if (preg_match('/^(.+ deals? \w+ damage to) (each (?:(?!\band\b).)+) and (each .+)$/', $sentence, $m)
            && ($first = self::sentenceEffects("{$m[1]} {$m[2]}")) !== null && ($second = self::sentenceEffects("{$m[1]} {$m[3]}")) !== null
            && count($first) === 1 && count($second) === 1 && $first[0]['type'] === 'damage' && $second[0]['type'] === 'damage' && isset($first[0]['each'], $second[0]['each'])) {
            return [$first[0], $second[0]];
        }
        // Lure for a turn: `Target creature must be blocked this turn if able.`, `It must be blocked this turn if able.`
        if (preg_match('/^(.+?) must be blocked this turn if able$/', $sentence, $m) && preg_match('/^(?:[Tt]arget creature(?: you control)?|It|That creature|CARDNAME)$/', $m[1])) {
            return self::sentenceEffects("{$m[1]} gains must be blocked if able until end of turn");
        }
        // `Each creature you control gets +1/+1 until end of turn.`: read as `Creatures you control get …`; `Each creature gets …` as `All creatures get …`.
        if (preg_match('/^([Ee])ach (other )?creature( you control| your opponents control)? (gets|gains) (.+)$/', $sentence, $m) && ($m[2] === '' || $m[3] !== '')) {
            $subject = $m[3] === '' ? 'All creatures' : ($m[2] !== '' ? 'Other creatures' : 'Creatures').$m[3];

            return self::sentenceEffects(($m[1] === 'e' ? lcfirst($subject) : $subject).' '.substr($m[4], 0, -1).' '.$m[5]);
        }
        // `Target Elf you control gets +2/+2 until end of turn.` and `Destroy target Dragon.`: read as a creature (you control), then narrowed to that type.
        if (preg_match('/\b[Tt]arget ([A-Z][a-z]+)( you control)?\b(?! card| spell| or| and|,)/', $sentence, $typed) && substr_count($sentence, 'arget ') === 1 && ($subtype = self::subtypeOf($typed[1])) === $typed[1]
            && ! in_array($subtype, ['Creature', 'Permanent', 'Nonland', 'Noncreature', 'Aura', 'Equipment', 'Vehicle', 'Treasure', 'Food', 'Clue', 'Blood', 'Map', 'Forest', 'Island', 'Swamp', 'Mountain', 'Plains', 'Saga', 'Gate', 'Desert', 'Cave', 'Town', 'Sphere', 'Lesson', 'Class', 'Room', 'Shrine', 'Curse', 'Role', 'Background', 'Case', 'Spacecraft', 'Planet', 'Incubator', 'Powerstone', 'Gold', 'Junk', 'Lander', 'Planeswalker', 'Battle', 'Player', 'Opponent', 'Spell', 'Card'], true)) {
            $kind = ($typed[2] ?? '') !== '' ? 'creature_you_control' : 'creature';
            $effects = self::sentenceEffects((string) preg_replace('/\b([Tt])arget [A-Z][a-z]+'.(($typed[2] ?? '') !== '' ? ' you control' : '').'\b/', '$1arget '.str_replace('_', ' ', $kind), $sentence, 1));
            $narrow = fn (array $effect) => in_array($effect['target'] ?? null, [$kind, "?{$kind}"], true);

            return $effects === null || ! in_array(true, array_map($narrow, $effects), true) ? null
                : array_map(fn (array $effect) => $narrow($effect) ? ['target' => str_replace($kind, $kind === 'creature' ? "subtype_creature_{$subtype}" : "subtype_you_control_{$subtype}", $effect['target'])] + $effect : $effect, $effects);
        }
        if (($effect = self::effect($sentence)) !== null) {
            return [$effect];
        }
        // `When CARDNAME dies, you gain life equal to its toughness.`: its own power or toughness as it last was (a target's is read in modifies()).
        if (preg_match("/^((?:.+ )?(?:[Gg]ains?|[Ll]oses?|[Dd]raws?)) (life|cards) equal to (?:its|CARDNAME's) (power|toughness)$/", $sentence, $m)
            && ($found = self::effect("{$m[1]} 1 ".($m[2] === 'cards' ? 'card' : 'life'))) !== null && in_array($found['type'], ['gain_life', 'lose_life', 'draw'], true) && ($found['amount'] ?? null) === 1) {
            return [['amount' => 'self_'.$m[3]] + $found];
        }
        // `Put a +1/+1 counter on target creature, then double the number of +1/+1 counters on it.`
        if (preg_match('/^(.+), then double the number of \\+1\\/\\+1 counters on (?:it|that creature)$/i', $sentence, $m)
            && ($first = self::sentenceEffects($m[1])) !== null && count($first) === 1 && in_array($first[0]['target'] ?? null, self::CREATURE_KINDS, true)) {
            return [$first[0], ['type' => 'double_counters', 'sameTarget' => true]];
        }
        // `Create a number of 1/1 … tokens equal to the number of Elves you control.`: read as `Create X …, where X is the number of …`.
        if (preg_match('/^([Cc]reate) a number of (.+ tokens) equal to the number of ('.self::X_COUNT.')$/', $sentence, $m)) {
            return self::sentenceEffects("{$m[1]} X {$m[2]}, where X is the number of {$m[3]}");
        }
        // `Target opponent loses life equal to the number of cards in their hand.`, Sudden Impact's `… deals damage to target player equal to the number of cards in that player's hand.`
        if ((preg_match('/^([Tt]arget (?:player|opponent) loses) life equal to the number of cards in their hand$/', $sentence, $m)
                || preg_match('/^(CARDNAME deals) damage to (target (?:player|opponent)) equal to the number of cards in (?:that player\'s|their) hand$/', $sentence, $m))
            && ($found = self::effect(isset($m[2]) ? "{$m[1]} 1 damage to {$m[2]}" : "{$m[1]} 1 life")) !== null && in_array($found['target'] ?? null, ['player', 'opponent'], true) && ! isset($found['per'])) {
            return [['per' => 'their_hand'] + $found];
        }
        // `Each opponent loses life equal to the number of creatures you control.`: read as `… X life, where X is the number of …`.
        if (preg_match('/^((?:.+ )?(?:[Gg]ains?|[Ll]oses?|[Dd]raws?|[Mm]ills?)) (life|cards) equal to the number of ('.self::X_COUNT.')$/', $sentence, $m)
            || preg_match('/^(.+ deals?) (damage) equal to the number of ('.self::X_COUNT.') (?<to>to .+)$/', $sentence, $m)) {
            return self::sentenceEffects("{$m[1]} X {$m[2]}".(isset($m['to']) ? " {$m['to']}" : '').", where X is the number of {$m[3]}");
        }
        // `Draw a card for each creature you control.`, `Create a 1/1 … token for each creature card in your graveyard.`: counted as it resolves (see Game::perCount()).
        // `Create X 1/1 … tokens, where X is the number of lands you control.` reads the same way.
        if ((preg_match('/^(.+) for each (?:(other creature|creature|artifact|land|enchantment|(?:other )?[A-Z][a-z]+) you control|(creature |artifact |land |instant |sorcery )?(card) in your (graveyard|hand)|(opponent)(?: you have)?|(attacking creature)(?: you control)?)$/', $sentence, $m)
                || (preg_match('/^(.+), where X is the number of '.self::X_COUNT.'$/', $sentence, $m)
                    && str_contains($m[1], ' X ') && ($m[1] = preg_replace('/\b(create|draw|gain|loses?|deals?|mills?) X\b/i', '$1 1', $m[1], 1, $swapped)) !== null && $swapped === 1))
            && ($found = self::effect($m[1])) !== null && in_array($found['type'], ['draw', 'gain_life', 'lose_life', 'token', 'damage', 'mill'], true)
            && ! isset($found['per']) && is_int($found['amount'] ?? 1) && ($per = self::per($m)) !== null) {
            return [['per' => $per] + $found];
        }
        // `Target creature gets -X/-X until end of turn, where X is the number of Swamps you control.`: X counted as it resolves.
        if (preg_match('/^(.+ gets? [+-]X\/[+-](?:X|0) until end of turn), where X is the number of '.self::X_COUNT.'$/', $sentence, $m)
            && ($found = self::effect($m[1])) !== null && isset($found['xPump']) && ! isset($found['amount']) && ($per = self::per($m)) !== null) {
            return [['amount' => 1, 'per' => $per] + $found];
        }
        // `Target creature gets +1/+1 until end of turn for each Elf you control.`: an X pump, N for each, counted as it resolves.
        if (preg_match('/^(.+ gets?) ([+-])(\d+)\/([+-])(\d+) until end of turn for each (other creature|creature|artifact|land|enchantment|(?:other )?[A-Z][a-z]+) you control$/', $sentence, $m)
            && ($found = self::effect("{$m[1]} {$m[2]}X/{$m[4]}X until end of turn")) !== null && isset($found['xPump']) && ! isset($found['amount']) && ($per = self::per([2 => $m[6]])) !== null) {
            return [['amount' => 1, 'per' => $per, 'xPump' => [(int) ($m[2].$m[3]), (int) ($m[4].$m[5])]] + $found];
        }
        // Fire // Ice: `CARDNAME deals 2 damage divided as you choose among one or two targets.`; split evenly, the rest to the first (see Game::runSteps()).
        if (preg_match('/^CARDNAME deals (\w+) damage divided as you choose among (one or two|one, two, or three|any number of) targets$/i', $sentence, $m) && is_int($n = self::amount($m[1])) && $n >= 1) {
            $slots = min($n, match (strtolower($m[2])) {
                'one or two' => 2,
                'one, two, or three' => 3,
                default => 5,
            });

            return array_map(fn (int $i) => ['type' => 'divided', 'total' => $n, 'slot' => $i, 'target' => $i === 0 ? 'any' : '?any'], range(0, $slots - 1));
        }
        // Peppersmoke: `If you control a Faerie, draw a card.`; checked as it resolves (see Game::runSteps()).
        if (preg_match('/^If you control an? ([A-Z][a-z]+|artifact|enchantment|creature|planeswalker), (.+)$/', $sentence, $m) && ($then = self::sentenceEffects($m[2])) !== null
            && array_filter($then, fn (array $effect) => isset($effect['target'])) === []) {
            return array_map(fn (array $effect) => $effect + ['ifCondition' => 'controls '.ucfirst($m[1])], $then);
        }
        // `If it's your turn, scry 1.`, `If you control two or more artifacts, draw a card.`: a game condition checked as it resolves.
        if (preg_match('/^If (.+?), (.+)$/', $sentence, $m) && ($if = self::staticCondition($m[1])) !== null && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)
            && ($then = self::sentenceEffects($m[2])) !== null && array_filter($then, fn (array $effect) => isset($effect['target']) || isset($effect['ifCondition'])) === []) {
            return array_map(fn (array $effect) => $effect + ['ifCondition' => $if], $then);
        }
        // Tarkir: `If you cast CARDNAME during your main phase, …`; checked as it resolves.
        if (preg_match('/^If you cast CARDNAME during your main phase, (.+)$/', $sentence, $m) && ($then = self::sentenceEffects($m[1])) !== null) {
            return array_map(fn (array $effect) => $effect + ['ifCondition' => 'main_phase'], $then);
        }
        // `Whenever you cast an instant or sorcery spell, copy that spell.`: the spell that triggered it; see Game::trigger().
        if (preg_match('/^copy that spell$/i', $sentence)) {
            return [['type' => 'copy_spell', 'thatSpell' => true]];
        }
        // `For each creature you control, put a +1/+1 counter on it.`: as `Put a +1/+1 counter on each creature you control.`
        if (preg_match('/^for each (other )?creature you control, put (\w+) \+1\/\+1 counters? on (?:it|that creature)$/i', $sentence, $m)) {
            return self::sentenceEffects("Put {$m[2]} +1/+1 counter".($m[2] === 'a' ? '' : 's')." on each {$m[1]}creature you control");
        }
        // Support N (rule 701.41): a +1/+1 counter on each of up to N other target creatures.
        if (preg_match('/^support (\w+)$/i', $sentence, $m) && is_int($n = self::amount($m[1])) && $n >= 1 && $n <= 5) {
            return array_fill(0, $n, ['type' => 'counters', 'amount' => 1, 'target' => '?creature_other']);
        }
        // A bounce land's `When this land enters, return a land you control to its owner's hand.`
        if (preg_match("/^return an? (land|creature|nonland permanent) you control to its owner's hand$/i", $sentence, $m)) {
            return [['type' => 'return_own', 'filter' => str_replace(' ', '_', strtolower($m[1]))]];
        }
        // Stampeding Wildebeests: `return a green creature you control to its owner's hand`.
        if (preg_match("/^return an? (white|blue|black|red|green) creature you control to its owner's hand$/i", $sentence, $m)) {
            return [['type' => 'return_own', 'filter' => 'creature', 'color' => self::COLOR_WORDS[strtolower($m[1])]]];
        }
        // Dwarven Shortsword: `create a 2/2 red Dwarf creature token, then attach CARDNAME to it` (an Equipment carried in).
        if (preg_match('/^(create an? .+ creature token), then attach CARDNAME to it$/i', $sentence, $m) && ($found = self::sentenceEffects($m[1])) !== null
            && count($found) === 1 && $found[0]['type'] === 'token' && ($found[0]['amount'] ?? 1) === 1) {
            return [$found[0] + ['attachSelf' => true]];
        }
        // Strive: `any number of target creatures`, read as up to three (a choice the game offers as optional targets).
        if (preg_match('/\bany number of target /i', $sentence)) {
            return self::sentenceEffects(preg_replace_callback('/\b(a)ny number of target /i', fn (array $m) => ($m[1] === 'A' ? 'U' : 'u').'p to three target ', $sentence));
        }
        // "Tap up to two target creatures": optional targets, `?kind` (see Game::isLegalTarget()).
        if (preg_match('/^(tap|untap) up to (\w+) target (.+)$/i', $sentence, $m) && is_int($n = self::amount($m[2])) && $n >= 1 && $n <= 5
            && isset(self::TARGETS[$phrase = 'target '.preg_replace('/s\b/', '', strtolower($m[3]))]) && self::isPermanentTarget($phrase)) {
            return array_fill(0, $n, ['type' => strtolower($m[1]), 'target' => '?'.self::TARGETS[$phrase]]);
        }
        // `Up to two target creatures (each) get +2/+2 until end of turn.`: as `… target creatures gets …`.
        if (preg_match('/^(up to \w+ target creatures(?: [^.]*?)??) (?:each )?get (.+)$/i', $sentence, $m)) {
            return self::sentenceEffects($m[1].' gets '.preg_replace('/^(\S+ and) gain /', '$1 gains ', $m[2]));
        }
        // "Return up to two target creature cards from your graveyard to your hand": that many optional targets.
        if (preg_match('/^(.*)\bup to (two|three|four|five) target (.+)$/i', $sentence, $m) && is_int($n = self::amount($m[2]))
            && ($found = self::sentenceEffects($m[1].'target '.str_replace("to their owners' hands", "to its owner's hand", preg_replace('/^((?:\w+ )*?)(\w+)s\b/', '$1$2', $m[3])))) !== null
            && count($withTarget = array_keys(array_filter($found, fn (array $effect) => isset($effect['target'])))) === 1) {
            $effect = $found[$withTarget[0]];
            $effect['target'] = '?'.$effect['target'];
            array_splice($found, $withTarget[0], 1, array_fill(0, $n, $effect));

            return $found;
        }
        // "… to up to one target creature": the same effect with its one target optional.
        // "up to one other target …" is the optional form of "another target …".
        if (str_contains($sentence, 'up to one other target ') && ($found = self::sentenceEffects(preg_replace('/\bup to one other target /', 'another target ', $sentence, 1))) !== null
            && count($withTarget = array_keys(array_filter($found, fn (array $effect) => isset($effect['target'])))) === 1) {
            $found[$withTarget[0]]['target'] = '?'.$found[$withTarget[0]]['target'];

            return $found;
        }
        if (str_contains($sentence, 'up to one target ') && ($found = self::sentenceEffects(preg_replace('/\bup to one target /', 'target ', $sentence, 1))) !== null
            && count($withTarget = array_keys(array_filter($found, fn (array $effect) => isset($effect['target'])))) === 1) {
            $found[$withTarget[0]]['target'] = '?'.$found[$withTarget[0]]['target'];

            return $found;
        }
        $targets = implode('|', array_map(fn ($phrase) => preg_quote($phrase, '/'), array_keys(self::TARGETS)));
        // Fight (rule 701.14), or one-sided: "… deals damage equal to its power to …". The second effect uses the first one's target too.
        if (preg_match("/^target creature you control (fights|deals damage equal to its power to) ({$targets})$/i", $sentence, $m)
            && in_array($kind = self::TARGETS[strtolower($m[2])], ['creature', 'creature_opponent', 'creature_or_planeswalker', 'creature_or_planeswalker_opponent'], true)
            && ($m[1] === 'deals damage equal to its power to' || ! str_contains($kind, 'planeswalker'))) {
            return [['type' => 'chosen', 'target' => 'creature_you_control'], ['type' => 'fight', 'target' => $kind, 'mutual' => strtolower($m[1]) === 'fights']];
        }
        // `Target player draws two cards, then discards a card.`: that player chooses what to discard.
        if (preg_match('/^(target player|target opponent) draws (\w+) cards?, then discards (\w+) cards?$/i', $sentence, $m) && is_int($draw = self::amount($m[2])) && is_int($discard = self::amount($m[3]))) {
            return [['type' => 'draw', 'amount' => $draw, 'target' => self::TARGETS[strtolower($m[1])]], ['type' => 'discard', 'amount' => $discard, 'sameTarget' => true]];
        }
        if (preg_match('/^you draw (\w+) cards? and (?:you )?(gain|lose) (\w+) life$/i', $sentence, $m) && ($draw = self::amount($m[1])) !== null && ($life = self::amount($m[3])) !== null) {
            return [['type' => 'draw', 'amount' => $draw], strtolower($m[2]) === 'gain' ? ['type' => 'gain_life', 'amount' => $life] : ['type' => 'lose_life', 'amount' => $life, 'you' => true]];
        }
        // `You and target opponent each draw a card.`, `You and target player each gain 2 life.`: you first.
        if (preg_match('/^you and (target player|target opponent) each (draw|gain) (\w+) (cards?|life)$/i', $sentence, $m) && is_int($n = self::amount($m[3])) && (strtolower($m[2]) === 'draw') === str_starts_with(strtolower($m[4]), 'card')) {
            $type = strtolower($m[2]) === 'draw' ? 'draw' : 'gain_life';

            return [['type' => $type, 'amount' => $n], ['type' => $type, 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]]];
        }
        if (preg_match('/^(target player|target opponent) draws (\w+) cards? and loses (\w+) life$/i', $sentence, $m) && ($draw = self::amount($m[2])) !== null && ($life = self::amount($m[3])) !== null) {
            return [['type' => 'draw', 'amount' => $draw, 'target' => self::TARGETS[strtolower($m[1])]], ['type' => 'lose_life', 'amount' => $life, 'sameTarget' => true]];
        }
        if (preg_match('/^(.+?), then (.+)$/', $sentence, $match) && ($first = self::effect($match[1])) !== null && ($second = self::effect($match[2])) !== null) {
            return [$first, $second];
        }
        // Two whole effects joined by "and": "untap CARDNAME and put a +1/+1 counter on CARDNAME".
        $parts = explode(' and ', $sentence);
        for ($i = 1; $i < count($parts); $i++) {
            $first = self::effect(implode(' and ', array_slice($parts, 0, $i)));
            $second = $first === null ? null : self::effect(implode(' and ', array_slice($parts, $i)));
            if ($second !== null && ! isset($second['target'])) {
                return [$first, $second];
            }
        }

        return null;
    }

    /**
     * `that creature gets an additional +2/+2 …` after an effect on target
     * creature: the same effect on that creature again; `CARDNAME deals 3
     * damage to that creature's controller`.
     *
     * @param string  $text
     * @param array[] $effects So far.
     *
     * @return array[]|null
     */
    private static function thatCreature(string $text, array $effects): ?array
    {
        if (array_intersect(array_column($effects, 'target'), self::CREATURE_KINDS) === []) {
            return null;
        }
        if (preg_match("/^CARDNAME deals (\\w+) damage to that creature's controller$/", $text, $m) && is_int($n = self::amount($m[1]))) {
            return [['type' => 'damage', 'amount' => $n, 'sameTarget' => true, 'toController' => true]];
        }
        if (! preg_match('/^that creature (.+)$/', $text, $m)) {
            return null;
        }
        $found = self::effects('target creature '.preg_replace('/^gets an additional /', 'gets ', $m[1]));
        if ($found === null || count($found) !== 1 || ($found[0]['target'] ?? null) !== 'creature') {
            return null;
        }
        unset($found[0]['target']);

        return [$found[0] + ['sameTarget' => true]];
    }

    /**
     * A sentence that changes the effect before it: `It can't be
     * regenerated.` after destroy, `It gains haste until end of turn.`
     * after creating a token.
     *
     * @param string  $sentence
     * @param array[] $effects  So far; the last one may change.
     *
     * @return bool
     */
    private static function modifies(string $sentence, array &$effects): bool
    {
        $last = array_key_last($effects);
        if ($last === null) {
            return false;
        }
        // Excess damage (rule 120.4a): `If excess damage was dealt this way, draw a card.`; `Excess damage is dealt to that creature's controller instead.` See Game::runSteps().
        if ($effects[$last]['type'] === 'damage' && is_int($effects[$last]['amount'] ?? null) && in_array($effects[$last]['target'] ?? null, ['creature', 'creature_opponent'], true) && ! isset($effects[$last]['excess'])) {
            if ($sentence === "Excess damage is dealt to that creature's controller instead") {
                $effects[$last]['excess'] = 'controller';

                return true;
            }
            if (preg_match('/^If excess damage was dealt this way, (.+)$/', $sentence, $m) && ($then = self::sentenceEffects(ucfirst($m[1]))) !== null
                && array_filter($then, fn (array $effect) => isset($effect['target']) || isset($effect['sameTarget'])) === []) {
                $effects[$last]['excess'] = 'if';
                array_push($effects, ...array_map(fn (array $effect) => $effect + ['ifExcess' => true], $then));

                return true;
            }
        }
        // Wrath-style sweepers: `Exile all creatures. You gain 1 life for each creature exiled this way.`; see Game::applyEffect().
        if (preg_match('/^You gain (\w+) life for each (?:creature|permanent|artifact|enchantment|nonland permanent) (destroyed|exiled) this way$/', $sentence, $m) && is_int($n = self::amount($m[1]))
            && isset($effects[$last]['all']) && $effects[$last]['type'] === ($m[2] === 'destroyed' ? 'destroy' : 'exile') && ! isset($effects[$last]['gainEach'])) {
            $effects[$last]['gainEach'] = $n;

            return true;
        }
        // `Each opponent loses 3 life. You gain life equal to the life lost this way.`
        if ($sentence === 'You gain life equal to the life lost this way' && $effects[$last]['type'] === 'lose_life' && ($effects[$last]['each'] ?? null) === 'opponent' && is_int($effects[$last]['amount'])) {
            $effects[] = ['type' => 'gain_life', 'amount' => $effects[$last]['amount'], 'perOpponent' => true];

            return true;
        }
        // `Mill four cards. You may put a land card from among them into your hand.`: one of the milled cards; see Game's return_card.
        if (preg_match('/^(?:Then )?(?:you|You) may (?:put|return) (?:a|an|up to one) (creature|artifact|land|enchantment|instant or sorcery|permanent|nonland|creature or land)? ?card from among (?:them|the milled cards|cards milled this way) (?:into|to) your hand$/', $sentence, $m)
            && $effects[$last]['type'] === 'mill' && ! isset($effects[$last]['each']) && ! isset($effects[$last]['target'])) {
            $effects[] = ['type' => 'return_card', 'kind' => ($m[1] ?? '') ?: 'card', 'fromMilled' => true];

            return true;
        }
        // A manland: `… becomes a 3/3 … creature until end of turn. It's still a land.`
        if (preg_match("/^It's still (?:a land|an artifact)$/", $sentence) && ($effects[$last]['animate'] ?? false)) {
            return true;
        }
        // Tenacity: `Creatures you control get +1/+1 … until end of turn. Untap those creatures.`
        if ($sentence === 'Untap those creatures' && $effects[$last]['type'] === 'pump' && ($effects[$last]['each'] ?? null) === 'yours') {
            $effects[] = ['type' => 'untap', 'each' => 'yours'];

            return true;
        }
        // `Gain control of up to two target creatures until end of turn. Untap those creatures. They gain haste until end of turn.`:
        // the follow-up for each of them, right after it (see Game::resolve(), which reads `sameTarget` as the previous target).
        if (preg_match('/^(?:Untap those creatures|They gain (.+) until end of turn)$/', $sentence, $m)) {
            $follow = isset($m[1]) ? (($keywords = self::keywordList($m[1])) === null ? null : ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'sameTarget' => true])
                : ['type' => 'untap', 'sameTarget' => true];
            $targeted = [];
            for ($i = $last; $i >= 0 && (isset($effects[$i]['target']) || ($effects[$i]['sameTarget'] ?? false)); $i--) {
                if (isset($effects[$i]['target'])) {
                    $targeted[] = $i;
                }
            }
            if ($follow !== null && count($targeted) >= 2 && count(array_unique(array_map(fn (int $i) => $effects[$i]['type'], $targeted))) === 1) {
                $rebuilt = [];
                foreach ($effects as $i => $effect) {
                    $rebuilt[] = $effect;
                    $next = $effects[$i + 1] ?? null;
                    // After a targeted effect's own follow-ups.
                    if ($i >= min($targeted) && ($next === null || isset($next['target']))) {
                        $rebuilt[] = $follow;
                    }
                }
                $effects = $rebuilt;

                return true;
            }
        }
        // "If that creature would die this turn, exile it instead." after damage.
        if (preg_match('/^If (?:that creature(?: or planeswalker)?|that permanent|a creature dealt damage this way|it) would die this turn, exile it instead$/', $sentence)) {
            // Or after a targeted -N/-N: `Target creature gets -3/-3 until end of turn. If that creature would die this turn, exile it instead.`
            $damages = array_keys(array_filter($effects, fn (array $effect) => $effect['type'] === 'damage' || ($effect['type'] === 'pump' && isset($effect['target']) && ($effect['toughness'] ?? 0) < 0)));
            foreach ($damages as $i) {
                $effects[$i]['exileIfDies'] = true;
            }

            return $damages !== [];
        }
        if ($sentence === "If that spell is countered this way, exile it instead of putting it into its owner's graveyard" && $effects[$last]['type'] === 'counter') {
            $effects[$last]['exileCountered'] = true;

            return true;
        }
        if ($effects[$last]['type'] === 'copy_spell' && $sentence === 'You may choose new targets for the copy') {
            return true;
        }
        // "Destroy target creature. Its controller loses 2 life.", "… Its controller draws a card.", also after a permanent or a spell.
        if (isset($effects[$last]['target']) && ! in_array(ltrim($effects[$last]['target'], '?'), ['any', 'player', 'opponent', 'player_or_planeswalker'], true) && ! str_contains($effects[$last]['target'], 'card')
            && preg_match('/^Its controller (loses (\w+) life|mills (\w+) cards?|draws (\w+) cards?)$/', $sentence, $m) && is_int($n = self::amount($m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : $m[4])))) {
            $effects[] = ['type' => $m[2] !== '' ? 'lose_life' : (($m[3] ?? '') !== '' ? 'mill' : 'draw'), 'amount' => $n, 'sameTarget' => true, 'toController' => true];

            return true;
        }
        // Swords to Plowshares: `Exile target creature. Its controller gains life equal to its power.`
        if (isset($effects[$last]['target']) && in_array(ltrim($effects[$last]['target'], '?'), self::CREATURE_KINDS, true)
            && preg_match('/^(Its controller gains|You gain) life equal to (?:its|that creature\'s) (power|toughness)$/', $sentence, $m)) {
            $effects[] = ['type' => 'gain_life', 'amount' => 'target_'.$m[2], 'sameTarget' => true] + ($m[1] === 'You gain' ? [] : ['toController' => true]);

            return true;
        }
        // `Destroy target artifact or enchantment. Its controller gains 3 life.`
        if (isset($effects[$last]['target']) && ! str_contains($effects[$last]['target'], 'card') && ! in_array(ltrim($effects[$last]['target'], '?'), ['any', 'player', 'opponent', 'player_or_planeswalker', 'spell', 'creature_spell', 'noncreature_spell', 'spell_targets_your_creature', 'spell_targets_you', 'spell_targets_you_or_creature'], true)
            && preg_match('/^Its controller gains (\w+) life$/', $sentence, $m) && is_int($n = self::amount($m[1]))) {
            $effects[] = ['type' => 'gain_life', 'amount' => $n, 'sameTarget' => true, 'toController' => true];

            return true;
        }
        // Beast Within: `Destroy target permanent. Its controller creates a 3/3 green Beast creature token.`
        if (isset($effects[$last]['target']) && ! str_contains($effects[$last]['target'], 'card') && ! in_array(ltrim($effects[$last]['target'], '?'), ['any', 'player', 'opponent', 'player_or_planeswalker'], true)
            && preg_match('/^Its controller creates (.+)$/', $sentence, $m) && ($token = self::token('create '.$m[1])) !== null) {
            $effects[] = $token + ['sameTarget' => true, 'toController' => true];

            return true;
        }
        // `Search target opponent's graveyard, hand, and library for any number of cards with that name and exile them.`
        if ($effects[$last]['type'] === 'choose_name'
            && preg_match("/^Search (target opponent|target player)'s graveyard, hand, and library for (any number of|up to (\\w+)) cards with (?:that|the chosen) name and exile them$/", $sentence, $m)
            && ($m[2] === 'any number of' || is_int(self::amount($m[3])))) {
            $effects[$last] = ['type' => 'name_exile', 'target' => self::TARGETS[strtolower($m[1])], 'filter' => $effects[$last]['filter'], 'amount' => $m[2] === 'any number of' ? 99 : self::amount($m[3])];

            return true;
        }
        // `Return target creature card from your graveyard to the battlefield. It gains haste.`
        if ($effects[$last]['type'] === 'reanimate' && preg_match('/^(?:It|That creature) gains haste$/', $sentence)) {
            $effects[] = ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => ['haste'], 'sameTarget' => true];

            return true;
        }
        // Kiki-Jiki: `Create a token that's a copy of target creature you control. It gains haste.`
        if ($effects[$last]['type'] === 'copy_token' && preg_match('/^(?:It|That token) gains haste(?: until end of turn)?$/', $sentence)) {
            $effects[$last]['haste'] = true;

            return true;
        }
        // "It gains haste. Exile it at the beginning of the next end step." after a token or a creature returned to the battlefield.
        for ($made = $last; $made > 0 && $effects[$made]['type'] === 'pump' && ($effects[$made]['sameTarget'] ?? false); $made--);
        if (in_array($effects[$made]['type'], ['token', 'reanimate', 'copy_token'], true)
            && preg_match('/^(Exile|Sacrifice) (?:it|them|that token|those tokens|that creature) at the beginning of the next end step$/', $sentence, $m)) {
            $effects[$made]['endStep'] = strtolower($m[1]);

            return true;
        }
        if (preg_match('/^If you do, draw (\w+) cards?$/', $sentence, $m) && is_int($n = self::amount($m[1])) && $effects[$last]['type'] === 'discard' && ($effects[$last]['may'] ?? false)) {
            $effects[$last]['draw'] = $n;

            return true;
        }
        // `You may draw a card. If you do, discard a card.`: the game always draws (see TextParser::effect()), so it loots.
        if (preg_match('/^If you do, discard (\w+) cards?$/', $sentence, $m) && is_int($n = self::amount($m[1])) && $effects[$last]['type'] === 'draw' && ! isset($effects[$last]['per'])) {
            $effects[] = ['type' => 'discard', 'amount' => $n];

            return true;
        }
        // Ruthless Predation: `Target creature you control gets +1/+1 until end of turn. It fights target creature you don't control.`
        if (in_array($effects[$last]['target'] ?? null, ['creature_you_control', 'creature'], true)
            && preg_match("/^(?:Then )?(?:[Ii]t|[Tt]hat creature) fights (target creature you don't control|target creature an opponent controls|another target creature)$/", $sentence, $m)) {
            $effects[] = ['type' => 'fight', 'target' => $m[1] === 'another target creature' ? 'creature' : 'creature_opponent', 'mutual' => true];

            return true;
        }
        // `Put a +1/+1 counter on each creature you control. Those creatures gain trample until end of turn.`: the same creatures.
        if (preg_match('/^Those creatures ((?:gain|get) .+ until end of turn)$/', $sentence, $m) && ($effects[$last]['type'] ?? null) === 'counters' && ($effects[$last]['each'] ?? null) === 'yours'
            && ! ($effects[$last]['other'] ?? false) && ($team = self::effects('Creatures you control '.$m[1])) !== null) {
            array_push($effects, ...$team);

            return true;
        }
        // `Exile target card from a graveyard. If it was a creature card, you gain 2 life.`: checked against the card exiled.
        if (preg_match('/^If it was an? (creature|land|artifact|enchantment|instant|sorcery|noncreature|nonland) card, (.+)$/', $sentence, $m)
            && ($effects[$last]['type'] ?? null) === 'exile' && in_array(ltrim($effects[$last]['target'] ?? '', '?'), ['card_graveyard', 'creature_card_graveyard', 'card_graveyard_nonbasic', 'card_graveyard_opponent', 'creature_card_graveyard_opponent'], true)
            && ($then = self::effects($m[2])) !== null && array_filter($then, fn (array $effect) => isset($effect['target'])) === []) {
            foreach ($then as $effect) {
                $effects[] = $effect + ['ifItWas' => $m[1]];
            }

            return true;
        }
        // `Clash with an opponent. If you win, …`: those effects only when the clash is won.
        if (preg_match('/^If you win, (.+)$/', $sentence, $m) && in_array('clash', array_column($effects, 'type'), true)
            && (($won = self::effects($m[1])) !== null || ($won = self::thatCreature($m[1], $effects)) !== null)) {
            foreach ($won as $effect) {
                $effects[] = $effect + ['ifWon' => true];
            }

            return true;
        }
        // Fire Prophecy: `You may put a card from your hand on the bottom of your library. If you do, draw a card.`
        if ($sentence === 'You may put a card from your hand on the bottom of your library') {
            $effects[] = ['type' => 'bottom_draw', 'draw' => false];

            return true;
        }
        if ($sentence === 'If you do, draw a card' && ($effects[$last]['type'] ?? '') === 'bottom_draw') {
            $effects[$last]['draw'] = true;

            return true;
        }
        // The Pacts: `At the beginning of your next upkeep, pay {2}{G}{G}. If you don't, you lose the game.`; see Game::$pacts.
        if (preg_match("/^If you don't, you lose the game$/", $sentence) && $effects[$last]['type'] === 'pact') {
            $effects[$last]['lose'] = true;

            return true;
        }
        // `Prevent the next 3 damage … You gain life equal to the damage prevented this way.`: see Game::dealDamage().
        if ($sentence === 'You gain life equal to the damage prevented this way' && $effects[$last]['type'] === 'prevent') {
            $effects[$last]['gain'] = true;

            return true;
        }
        if ($sentence === 'Then shuffle' || $sentence === 'Then that player shuffles') {
            $effects[] = ['type' => 'shuffle'] + ($sentence === 'Then shuffle' ? [] : ['that' => true, 'sameTarget' => true]);

            return true;
        }
        if (preg_match("/^(?:It|They|CARDNAME) can't be regenerated$/", $sentence) && in_array($effects[$last]['type'], ['destroy', 'damage'], true)) {
            $effects[$last]['noRegen'] = true;

            return true;
        }
        // "Those creatures don't untap during their controller's next untap step." / "Put a stun counter on each of them." after tapping.
        $tapped = [];
        // `When this Aura enters, tap enchanted creature. It doesn't untap …` reads the same way.
        for ($i = $last; isset($effects[$i]) && $effects[$i]['type'] === 'tap' && (isset($effects[$i]['target']) || ($effects[$i]['enchanted'] ?? false)); $i--) {
            $tapped[] = $i;
        }
        $freeze = preg_match("/^(?:It|They|That creature|Those creatures|That permanent|Those permanents) (?:doesn't|don't) untap during (?:its|their) (?:controller's next untap step|controllers' next untap steps)$/i", $sentence);
        if ($tapped !== [] && ($freeze || preg_match('/^Put a stun counter on (?:it|that creature|that permanent|each of them)$/i', $sentence))) {
            foreach ($tapped as $i) {
                $effects[$i][$freeze ? 'freeze' : 'stun'] = $freeze ? true : 1;
            }

            return true;
        }
        if (preg_match('/^(?:It|They) gains? haste until end of turn$/', $sentence) && $effects[$last]['type'] === 'token') {
            $effects[$last]['haste'] = true;

            return true;
        }
        // `Target creature you control gets +1/+1 until end of turn. It endures 1.`: that creature, not the spell.
        if ((in_array($effects[$last]['target'] ?? null, self::CREATURE_KINDS, true) || ($effects[$last]['sameTarget'] ?? false))
            && preg_match('/^(?:It|That creature) endures (\w+)$/', $sentence, $m) && is_int($n = self::amount(strtolower($m[1])))) {
            $effects[] = ['type' => 'endure', 'amount' => $n, 'sameTarget' => true];

            return true;
        }
        // `If it's a Human, it also gains indestructible until end of turn.`: only when the same creature has that type as this resolves.
        if (preg_match("/^If it's an? ([A-Z][a-z]+), (it|that creature) also ((?:gets|gains) .+ until end of turn)$/", $sentence, $m) && ($subtype = self::subtypeOf($m[1])) !== null
            && (isset($effects[$last]['target']) || ($effects[$last]['sameTarget'] ?? false)) && self::modifies(ucfirst($m[2]).' '.$m[3], $effects)) {
            $effects[array_key_last($effects)]['ifTargetSubtype'] = $subtype;

            return true;
        }
        // `CARDNAME deals 2 damage to any target. If you control a Goblin, it deals 3 damage instead.`: the larger amount when the condition holds as it resolves.
        if (preg_match('/^If (.+?), (?:it|CARDNAME) deals (\w+) damage instead$/', $sentence, $m) && ($effects[$last]['type'] ?? null) === 'damage' && is_int($effects[$last]['amount'] ?? null)
            && is_int($n = self::amount($m[2])) && ($if = self::staticCondition($m[1])) !== null
            && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)) {
            $effects[$last] += ['insteadIf' => $if, 'insteadAmount' => $n];

            return true;
        }
        // `Draw a card. If you control three or more artifacts, draw two cards instead.`, `… put two +1/+1 counters on it instead.`: the same effect with the larger amount.
        if (preg_match('/^If (.+?), (.+) instead$/', $sentence, $m) && is_int($effects[$last]['amount'] ?? null) && ! isset($effects[$last]['insteadIf'])
            && ($if = self::staticCondition($m[1])) !== null && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)
            && ($then = self::sentenceEffects(isset($effects[$last]['target']) ? (string) preg_replace('/ on (?:it|that creature)$/', ' on '.array_search($effects[$last]['target'], self::TARGETS, true), $m[2]) : $m[2])) !== null
            && count($then) === 1 && is_int($then[0]['amount'] ?? null) && array_diff_key($then[0], ['amount' => true]) == array_diff_key($effects[$last], ['amount' => true])) {
            $effects[$last] += ['insteadIf' => $if, 'insteadAmount' => $then[0]['amount']];

            return true;
        }
        // `Target creature gets -1/-1 until end of turn. If you control a Zombie, that creature gets -3/-3 until end of turn instead.`: the larger pump.
        if (preg_match('/^If (.+?), (?:it|that creature) (gets [+-]\d+\/[+-]\d+ until end of turn) instead$/', $sentence, $m) && ($effects[$last]['type'] ?? null) === 'pump'
            && isset($effects[$last]['target']) && ! isset($effects[$last]['insteadIf']) && ($if = self::staticCondition($m[1])) !== null && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)
            && ($then = self::sentenceEffects('Target creature '.$m[2])) !== null && count($then) === 1 && ($then[0]['type'] ?? null) === 'pump' && ($then[0]['keywords'] ?? []) === []) {
            $effects[$last] += ['insteadIf' => $if, 'instead' => ['power' => $then[0]['power'], 'toughness' => $then[0]['toughness']]];

            return true;
        }
        // `If it's your turn, it also gains first strike …`, `If you control a Knight, it also gets …`: a condition about the game, checked as it resolves.
        if (preg_match("/^If (.+?), (it|that creature) also ((?:gets|gains) .+ until end of turn)$/", $sentence, $m) && ($if = self::staticCondition($m[1])) !== null
            && ! in_array($if, ['untapped', 'tapped', 'attacking', 'has_counter'], true)
            && (isset($effects[$last]['target']) || ($effects[$last]['sameTarget'] ?? false)) && self::modifies(ucfirst($m[2]).' '.$m[3], $effects)) {
            $effects[array_key_last($effects)]['ifCondition'] = $if;

            return true;
        }
        // "It gains haste until end of turn." after an effect with a target: the same creature. `It must be blocked this turn if able.` reads the same way.
        $sentence = (string) preg_replace('/^(It|That creature) must be blocked this turn if able$/', '$1 gains must be blocked if able until end of turn', $sentence);
        if ((isset($effects[$last]['target']) || ($effects[$last]['sameTarget'] ?? false))
            && preg_match('/^(?:Then )?(?:[Ii]t|[Tt]hat creature) (?:gets ([+-]\d+)\/([+-]\d+)(?: and gains (.+?))?|gains (.+?)) until end of turn$/', $sentence, $m)
            && ($keywords = self::keywordList(($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''), true)) !== null) {
            $effects[] = ['type' => 'pump', 'power' => (int) ($m[1] ?? 0), 'toughness' => (int) ($m[2] ?? 0), 'keywords' => $keywords, 'sameTarget' => true];

            return true;
        }

        return false;
    }

    /**
     * `flying and first strike` => keywords, or null when one is not a keyword.
     *
     * @param string $text
     *
     * @return string[]|null
     */
    private static function keywordList(string $text, bool $untilEndOfTurn = false): ?array
    {
        if (trim($text) === '') {
            return [];
        }
        // The Swords: `protection from black and from green`.
        $text = (string) preg_replace('/protection from (\w+) and from (\w+)/', 'protection from $1, protection from $2', $text);
        $words = array_map(fn ($word) => strtolower(trim($word)), preg_split('/,\s*(?:and\s+)?|\s+and\s+/', $text));
        foreach ($words as $word) {
            // See Game::protectedFrom().
            if (preg_match('/^protection from (white|blue|black|red|green|artifacts|creatures|enchantments|instants|sorceries|planeswalkers)$/', $word)) {
                continue;
            }
            // A granted ward: see Game::wardCost().
            if (preg_match('/^ward \{\d+\}$/', $word)) {
                continue;
            }
            // `gains protection from the color of your choice until end of turn`: the color is picked as it resolves (see Game::applyEffect()).
            // `It must be blocked this turn if able.`: read as a keyword until end of turn (see TextParser::sentenceEffects() and Game::blockersAllowed()).
            if (! in_array($word, CardDefinition::KEYWORDS, true) && ! ($untilEndOfTurn && in_array($word, ['protection from the color of your choice', 'must be blocked if able'], true))) {
                return null;
            }
        }

        return $words;
    }

    /**
     * An amount: `3`, `three`, `a` or `X`.
     *
     * @param string $text
     *
     * @return int|string|null An int, `X`, or null when it is not an amount.
     */
    private static function amount(string $text): int|string|null
    {
        $text = strtolower($text);
        if (ctype_digit($text)) {
            return (int) $text;
        }
        if ($text === 'x') {
            return 'X';
        }

        return self::NUMBERS[$text] ?? null;
    }

    /**
     * One sentence of an instant or sorcery as an effect.
     *
     * @param string $sentence Without its final period.
     *
     * @return array|null
     */
    public static function effect(string $sentence): ?array
    {
        $targets = implode('|', array_map(fn ($phrase) => preg_quote($phrase, '/'), array_keys(self::TARGETS)));
        $s = $sentence;

        // `you may draw a card`, `you may mill two cards`: the game always does.
        // Leonin Snarecaster: `you may tap target creature`; tapping is always worth it.
        if (preg_match('/^you may ((?:tap|untap) target .+)$/i', $s, $m) && ($effect = self::effect($m[1])) !== null && in_array($effect['type'], ['tap', 'untap'], true)) {
            return $effect;
        }
        if (preg_match('/^you may ((?:draw|mill) .+)$/i', $s, $m) && ($effect = self::effect($m[1])) !== null && in_array($effect['type'], ['draw', 'mill'], true)) {
            return $effect;
        }

        // Adapt and monstrosity (rules 701.46 and 701.37).
        if (preg_match('/^(adapt|monstrosity) (\d+)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'amount' => (int) $m[2], 'self' => true];
        }
        if ($s === 'sacrifice CARDNAME' || $s === 'Sacrifice CARDNAME' || $s === 'sacrifice it' || $s === 'Sacrifice it') {
            return ['type' => 'sacrifice', 'self' => true];
        }
        // Explore (rule 701.44).
        if ($s === 'CARDNAME explores') {
            return ['type' => 'explore', 'self' => true];
        }
        if (preg_match('/^target creature you control explores$/i', $s)) {
            return ['type' => 'explore', 'target' => 'creature_you_control'];
        }
        if (preg_match("/^CARDNAME deals (\\w+) damage to ({$targets})$/i", $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[2])]];
        }
        // Explore (the sorcery): `You may play an additional land this turn.`
        if (preg_match('/^you may play an additional land this turn$/i', $s)) {
            return ['type' => 'extra_land'];
        }
        // `When CARDNAME enters, it fights up to one target creature you don't control.` / `… you may have it fight target creature …`: choosing no target declines.
        if (preg_match("/^(?:(?:CARDNAME|it) fights (up to one )?|you may have (?:CARDNAME|it) fight (?:up to one )?)(target creature you don't control|target creature an opponent controls|another target creature|target creature)$/i", $s, $m)) {
            $optional = ($m[1] ?? '') !== '' || stripos($s, 'you may') === 0;

            return ['type' => 'fight', 'target' => ($optional ? '?' : '').self::TARGETS[strtolower($m[2])], 'mutual' => true, 'selfFight' => true];
        }
        // `Double the number of +1/+1 counters on target creature.`
        if (preg_match("/^double the number of \\+1\\/\\+1 counters on ({$targets}|CARDNAME)$/i", $s, $m)
            && ($m[1] === 'CARDNAME' || in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true))) {
            return ['type' => 'double_counters'] + ($m[1] === 'CARDNAME' ? ['self' => true] : ['target' => self::TARGETS[strtolower($m[1])]]);
        }
        // Infernal Phantom: `When CARDNAME dies, it deals damage equal to its power to any target.` (its power as it last was).
        if (preg_match("/^(?:CARDNAME|it) deals damage equal to its power to ({$targets})$/i", $s, $m)) {
            return ['type' => 'damage', 'amount' => 'self_power', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Rumbling Rockslide: `CARDNAME deals damage to target creature equal to the number of lands you control.`
        if (preg_match("/^CARDNAME deals damage to ({$targets}) equal to the number of (lands|creatures|artifacts|enchantments|Forests|Islands|Mountains|Plains|Swamps) you control$/i", $s, $m)) {
            return ['type' => 'damage', 'amount' => 'yours:'.$m[2], 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^CARDNAME deals (\w+) damage to each (opponent|player)$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => strtolower($m[2])];
        }
        // `CARDNAME deals 2 damage to each other creature.`: not CARDNAME itself.
        if (preg_match('/^CARDNAME deals (\w+) damage to each other creature$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => 'creature_other'];
        }
        if (preg_match('/^CARDNAME deals (\w+) damage to each creature( without flying| with flying| your opponents control)?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'damage', 'amount' => $n, 'each' => match ($m[2] ?? '') {
                ' without flying' => 'creature_without_flying',
                ' with flying' => 'creature_with_flying',
                ' your opponents control' => 'creature_opponents',
                default => 'creature',
            }];
        }
        if (preg_match('/^(?:you )?draw (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n];
        }
        // Slow cantrips: a one-shot triggered draw, not a draw now or in the draw step.
        if (strcasecmp($s, "draw a card at the beginning of the next turn's upkeep") === 0) {
            return ['type' => 'draw_next_upkeep'];
        }
        // `Each player draws a card.`, `Each opponent gains 2 life.`: in turn order; see Game::applyEffect(). "Each other player" is each opponent.
        if (preg_match('/^each (player|opponent|other player) (draws (\w+) cards?|gains (\w+) life)$/i', $s, $m) && ($n = self::amount($m[3] !== '' ? $m[3] : $m[4])) !== null) {
            return ['type' => $m[3] !== '' ? 'draw' : 'gain_life', 'amount' => $n, 'each' => strtolower($m[1]) === 'player' ? 'player' : 'opponent'];
        }
        if (preg_match('/^target (player|opponent) draws (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'draw', 'amount' => $n, 'target' => strtolower($m[1])];
        }
        // Baleful Mastery: `an opponent draws a card` (the next opponent; see Game::opponent()).
        if (preg_match('/^an opponent draws (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'draw', 'amount' => $n, 'opponent' => true];
        }
        if (preg_match('/^you gain (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gain_life', 'amount' => $n];
        }
        if (preg_match('/^target player gains (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gain_life', 'amount' => $n, 'target' => 'player'];
        }
        if (preg_match('/^(target player|target opponent) loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^each opponent loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'each' => 'opponent'];
        }
        if (preg_match('/^each player loses (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'each' => 'player'];
        }
        if (preg_match("/^destroy ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'destroy', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^exile ({$targets})( until CARDNAME leaves the battlefield)?$/i", $s, $m) && (self::isPermanentTarget($m[1]) || (in_array(self::TARGETS[strtolower($m[1])], ['card_graveyard', 'creature_card_graveyard', 'card_graveyard_nonbasic', 'card_graveyard_opponent', 'creature_card_graveyard_opponent'], true) && ($m[2] ?? '') === ''))) {
            return ['type' => 'exile', 'target' => self::TARGETS[strtolower($m[1])]] + (($m[2] ?? '') !== '' ? ['until' => true] : []);
        }
        // Hideaway's payoff: `You may play the exiled card without paying its mana cost if each player has no cards in hand.`
        if (preg_match('/^(?:you may )?play the exiled card without paying its mana cost(?: if (.+))?$/i', $s, $m)) {
            $if = match (strtolower($m[1] ?? '')) {
                '' => '',
                'your library has twenty or fewer cards in it' => 'library_le 20',
                'each player has no cards in hand' => 'no_hands',
                'creatures you control have total power 10 or greater' => 'total_power 10',
                'you attacked with three or more creatures this turn' => 'attackers 3',
                default => null,
            };
            if ($if !== null) {
                return ['type' => 'play_hidden'] + ($if === '' ? [] : ['if' => $if]);
            }
        }
        if (preg_match('/^at the beginning of your next upkeep, pay '.self::COST.'$/i', $s, $m) && ! str_contains($m[1], 'X')) {
            return ['type' => 'pact', 'cost' => $m[1]];
        }
        // `Prevent all damage target creature would deal this turn.`; see Game::dealDamage().
        if (preg_match('/^prevent all damage (?:target creature would deal|that would be dealt by target creature) this turn$/i', $s)) {
            return ['type' => 'prevent_dealing', 'target' => 'creature'];
        }
        if (preg_match('/^prevent all combat damage that would be dealt this turn$/i', $s)) {
            return ['type' => 'fog'];
        }
        // The Ring tempts you (rule 701.54): see Game::temptWithRing().
        if (preg_match('/^the ring tempts you$/i', $s)) {
            return ['type' => 'ring'];
        }
        if (preg_match("/^damage can't be prevented this turn$/i", $s)) {
            return ['type' => 'no_prevention'];
        }
        if (preg_match("/^prevent (the next (\\d+|one|two|three|four|five)|all) damage that would be dealt to ({$targets}|CARDNAME|you) this turn$/i", $s, $m)
            && (in_array(strtolower($m[3]), ['cardname', 'you'], true) || in_array(self::TARGETS[strtolower($m[3])] ?? '', ['any', ...self::CREATURE_KINDS], true))) {
            $amount = strtolower($m[1]) === 'all' ? 'all' : self::amount($m[2]);
            $who = strtolower($m[3]);

            return ['type' => 'prevent', 'amount' => $amount] + match ($who) {
                'cardname' => ['self' => true],
                'you' => ['you' => true],
                default => ['target' => self::TARGETS[$who]],
            };
        }
        if (preg_match('/^transform CARDNAME$/i', $s)) {
            return ['type' => 'transform', 'self' => true];
        }
        // A preparation card's later way to become prepared again; see CardDefinition::$prepare.
        if (preg_match('/^(?:CARDNAME|it) becomes prepared$/i', $s)) {
            return ['type' => 'prepare', 'self' => true];
        }
        // A Saga's last chapter: `Exile CARDNAME, then return it to the battlefield transformed under your control.`
        if (preg_match('/^exile CARDNAME, then return it to the battlefield transformed under (?:your|its owner\'s) control$/i', $s)) {
            return ['type' => 'exile_transformed', 'self' => true];
        }
        if (preg_match("/^(?:you may )?shuffle (?:CARDNAME|it) into its owner's library$/i", $s)) {
            return ['type' => 'shuffle_self', 'self' => true];
        }
        // `When CARDNAME dies, return it to the battlefield tapped under its owner's control.`: see Game::trigger().
        if (preg_match("/^return CARDNAME to the battlefield( tapped)?(?: under (?:its owner's|your) control)?$/i", $s, $m)) {
            return ['type' => 'return_self', 'self' => true] + (($m[1] ?? '') !== '' ? ['tapped' => true] : []);
        }
        if (preg_match("/^put CARDNAME on the bottom of its owner's library$/i", $s)) {
            return ['type' => 'bottom_self', 'self' => true];
        }
        // Haru-Onna: `you may return CARDNAME to its owner's hand` (it always does).
        if (preg_match("/^(?:you may )?return CARDNAME to its owner's hand$/i", $s)) {
            return ['type' => 'bounce', 'self' => true];
        }
        if (preg_match("/^return ({$targets}) to its owner's hand$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'bounce', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell|target spell with mana value [1-4] or less|target spell that targets (?:you or )?a creature you control|target spell that targets you)$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Manlands and animated artifacts: `Until end of turn, CARDNAME becomes a 4/4 white and blue Elemental creature with flying.`; see Game::isCreature().
        if (preg_match('/^(until end of turn, )?CARDNAME becomes an? (\d+)\/(\d+) [A-Za-z ]+? creature(?: with ([a-z ,]+?))?( until end of turn)?$/i', $s, $m)
            && ($m[1] !== '') !== (($m[5] ?? '') !== '') && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'base_pt', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'base' => [(int) $m[2], (int) $m[3]], 'self' => true, 'animate' => true];
        }
        // Signed X: `Target creature gets -X/-X until end of turn.`
        // `…, where X is the number of creatures you control.`: X counted as it resolves.
        if (preg_match("/^({$targets}|CARDNAME) gets ([+-])X\/([+-])(X|0) until end of turn(?:, where X is the number of (creatures|lands|artifacts|enchantments) you control)?$/i", $s, $m)
            && ($m[1] === 'CARDNAME' || in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true))) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => [], 'xPump' => [$m[2] === '-' ? -1 : 1, $m[4] === '0' ? 0 : ($m[3] === '-' ? -1 : 1)]]
                + (($m[5] ?? '') !== '' ? ['amount' => 'yours:'.strtolower($m[5])] : [])
                + ($m[1] === 'CARDNAME' ? ['self' => true] : ['target' => self::TARGETS[strtolower($m[1])]]);
        }
        // `Target creature has base power and toughness 0/1 until end of turn.` (rule 613.4b); see Game::power().
        if (preg_match("/^({$targets}) has base power and toughness (\\d+)\\/(\\d+) until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'base_pt', 'power' => 0, 'toughness' => 0, 'keywords' => [], 'base' => [(int) $m[2], (int) $m[3]], 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^({$targets}) gets ([+-]\\w+)\\/([+-]\\w+)(?: and gains (.+?))? until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            $power = self::signed($m[2]);
            $toughness = self::signed($m[3]);
            $keywords = self::keywordList($m[4] ?? '');
            if ($power !== null && $toughness !== null && $keywords !== null) {
                return ['type' => 'pump', 'power' => $power, 'toughness' => $toughness, 'keywords' => $keywords, 'target' => self::TARGETS[strtolower($m[1])]];
            }
        }
        if (preg_match('/^CARDNAME gets ([+-]\d+)\/([+-]\d+)(?: and gains (.+?))? until end of turn$/i', $s, $m)
            && ($keywords = self::keywordList($m[3] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'self' => true];
        }
        if (preg_match('/^CARDNAME gains (.+?) until end of turn$/i', $s, $m) && ($keywords = self::keywordList($m[1], true)) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'self' => true];
        }
        // Incubate (rule 701.53): an Incubator token with N +1/+1 counters and `{2}: Transform this artifact.`
        // `Put target creature on top of its owner's library.` / `… on the bottom …`; the owner's choice of top or bottom is top.
        if (preg_match("/^put ({$targets}) on (top|the bottom) of its owner's library$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'tuck', 'target' => self::TARGETS[strtolower($m[1])], 'bottom' => strtolower($m[2]) !== 'top'];
        }
        // Flicker: `Exile target creature you control, then return that card to the battlefield under its owner's control.`
        if (preg_match("/^exile ({$targets}), then return (?:that card|it) to the battlefield under (?:its owner's|your) control$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'flicker', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Scaled Herbalist: `You may put a land card from your hand onto the battlefield.`
        if (preg_match('/^(?:then )?(?:you may )?put a land card from your hand onto the battlefield( tapped)?$/i', $s, $m)) {
            return ['type' => 'land_from_hand', 'tapped' => ($m[1] ?? '') !== ''];
        }
        // Cackling Counterpart: `Create a token that's a copy of target creature you control.`
        // Kiki-style: `…, except it has haste`.
        if (preg_match("/^create a token that's a copy of ({$targets})(, except it has haste)?$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'copy_token', 'target' => self::TARGETS[strtolower($m[1])]] + (isset($m[2]) ? ['haste' => true] : []);
        }
        // Justice Strike: `Target creature deals damage to itself equal to its power.`
        if (preg_match("/^({$targets}) deals damage to itself equal to its power$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])] ?? '', self::CREATURE_KINDS, true)) {
            return ['type' => 'self_damage', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Put target card from a graveyard on the bottom of its owner's library.` (Junktroller)
        if (preg_match("/^put (target card from a graveyard|target card from your graveyard) on (top|the bottom) of (?:its owner's|your) library$/i", $s, $m)) {
            return ['type' => 'tuck', 'target' => self::TARGETS[strtolower($m[1])], 'bottom' => strtolower($m[2]) !== 'top'];
        }
        if (preg_match("/^({$targets})'s owner puts it on (?:their choice of )?the top or bottom of their library$/i", $s, $m) && self::isPermanentTarget($m[1])) {
            return ['type' => 'tuck', 'target' => self::TARGETS[strtolower($m[1])], 'bottom' => false];
        }
        // Amass (rule 701.47): `Amass Orcs 2`, or the older `Amass 2` (Zombies).
        if (preg_match('/^amass (?:([A-Z][a-z]+) )?(\w+)$/i', $s, $m) && is_int($n = self::amount($m[2])) && $n > 0) {
            return ['type' => 'amass', 'amount' => $n, 'subtype' => $m[1] !== '' ? ucfirst(preg_replace('/s$/', '', $m[1])) : 'Zombie'];
        }
        // Earthbend (TLA): see Game's `earthbend` effect.
        if (preg_match('/^earthbend (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'earthbend', 'amount' => $n];
        }
        if (preg_match('/^incubate (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'incubate', 'amount' => $n];
        }
        if (preg_match('/^take an extra turn after this one$/i', $s)) {
            return ['type' => 'extra_turn'];
        }
        // Connive (rule 701.50): draw a card, then discard a card; a nonland discard puts a +1/+1 counter on it.
        if (preg_match('/^(?:CARDNAME|it) connives$/i', $s)) {
            return ['type' => 'connive', 'self' => true];
        }
        // `Target creature you control connives.`: its controller (you) draws and discards.
        if (preg_match('/^(?:up to one )?target creature you control connives$/i', $s, $m)) {
            return ['type' => 'connive', 'target' => (str_starts_with(strtolower($s), 'up to one') ? '?' : '').self::TARGETS['target creature you control']];
        }
        if (preg_match('/^you lose (\w+) life$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'lose_life', 'amount' => $n, 'you' => true];
        }
        if (preg_match('/^(?:you may )?put (\w+) (spore|charge|age|time|ki|oil|verse|fade|quest|storage|page|lore) counters? on CARDNAME$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'counters', 'amount' => $n, 'self' => true, 'kind' => strtolower($m[2])];
        }
        if (preg_match("/^(?:you may )?put (\w+) \+1\/\+1 counters? on ({$targets}|CARDNAME)$/i", $s, $m) && ($n = self::amount($m[1])) !== null) {
            if ($m[2] === 'CARDNAME') {
                return ['type' => 'counters', 'amount' => $n, 'self' => true];
            }
            if (in_array(self::TARGETS[strtolower($m[2])], self::CREATURE_KINDS, true)) {
                return ['type' => 'counters', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[2])]];
            }
        }
        // `Put a -1/-1 counter on target creature.`
        if (preg_match("/^(?:you may )?put (\w+) -1\/-1 counters? on ({$targets})$/i", $s, $m) && is_int($n = self::amount($m[1]))
            && in_array(self::TARGETS[strtolower($m[2])], self::CREATURE_KINDS, true)) {
            return ['type' => 'counters', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[2])], 'kind' => '-1/-1'];
        }
        // `Put a +1/+1 counter on each creature you control.` / `… each other creature you control.`
        // `… each creature you control with flying.`: only those with the keyword.
        if (preg_match('/^[Pp]ut (\w+) \+1\/\+1 counters? on each (other )?(creature|[A-Z][a-z]+) you control( with (flying|reach|vigilance|trample|lifelink|deathtouch|haste|menace|an? \+1\/\+1 counter on it))?$/', ucfirst($s), $m) && is_int($n = self::amount(strtolower($m[1])))
            && ($m[3] === 'creature' || ($subtype = self::subtypeOf($m[3])) !== null)) {
            return ['type' => 'counters', 'amount' => $n, 'each' => 'yours'] + (($m[2] ?? '') !== '' ? ['other' => true] : []) + ($m[3] !== 'creature' ? ['subtype' => $subtype] : []) + (($m[5] ?? '') === '' ? [] : (str_contains($m[5], 'counter') ? ['withCounter' => true] : ['withKeyword' => $m[5]]));
        }
        // `Put a +1/+1 counter on each other attacking creature.`: whoever controls them.
        if (preg_match('/^[Pp]ut (\w+) \+1\/\+1 counters? on each (other )?attacking creature$/', ucfirst($s), $m) && is_int($n = self::amount(strtolower($m[1])))) {
            return ['type' => 'counters', 'amount' => $n, 'each' => 'attacking'] + (($m[2] ?? '') !== '' ? ['other' => true] : []);
        }
        // A ritual: `Add {R}{R}{R}.`
        if (preg_match('/^add ((\{[WUBRGC]\})\2*)$/i', $s, $m)) {
            return ['type' => 'add_mana', 'color' => strtoupper($m[2][1]), 'amount' => intdiv(strlen($m[1]), 3)];
        }
        if (preg_match("/^(tap|untap) ({$targets})$/i", $s, $m) && self::isPermanentTarget($m[2])) {
            return ['type' => strtolower($m[1]), 'target' => self::TARGETS[strtolower($m[2])]];
        }
        if (preg_match('/^(scry|surveil) (\d+)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'amount' => (int) $m[2]];
        }
        if (preg_match('/^regenerate (?:enchanted|equipped) creature$/i', $s)) {
            return ['type' => 'regenerate', 'enchanted' => true];
        }
        if (preg_match('/^regenerate CARDNAME$/i', $s)) {
            return ['type' => 'regenerate', 'self' => true];
        }
        if (preg_match("/^regenerate ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'regenerate', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Target opponent reveals their hand.` on its own: everyone sees it (see Game::log()).
        if (preg_match('/^(target opponent|target player) reveals (?:their|his or her) hand$/i', $s, $m)) {
            return ['type' => 'reveal_hand', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Look at target opponent's hand.`: in a two-player game only that player and you play, so it is shown like a reveal.
        if (preg_match("/^look at (target opponent|target player)'s hand$/i", $s, $m)) {
            return ['type' => 'reveal_hand', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^(tap|untap) CARDNAME$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'self' => true];
        }
        // `Goad target creature an opponent controls.` (rule 701.15): it attacks each combat if able until your next turn.
        if (preg_match("/^goad ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'goad', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // `Whenever CARDNAME blocks a creature, that creature doesn't untap during its controller's next untap step.`: tapped or not.
        if ($s === "CARDNAME doesn't untap during its controller's next untap step") {
            return ['type' => 'freeze', 'self' => true];
        }
        // `When CARDNAME enters, enchanted creature gains first strike until end of turn.`
        if (preg_match('/^enchanted creature (?:gets ([+-]\d+)\/([+-]\d+)(?: and gains (.+?))?|gains (.+?)) until end of turn$/i', $s, $m)
            && ($keywords = self::keywordList(($m[4] ?? '') !== '' ? $m[4] : ($m[3] ?? ''))) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => $keywords, 'enchanted' => true];
        }
        // `When this Aura enters, put a +1/+1 counter on enchanted creature.`
        if (preg_match('/^put (\w+) \+1\/\+1 counters? on enchanted creature$/i', $s, $m) && is_int($n = self::amount(strtolower($m[1])))) {
            return ['type' => 'counters', 'amount' => $n, 'enchanted' => true];
        }
        if (preg_match('/^(tap|untap) enchanted (?:creature|permanent|artifact|land)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'enchanted' => true];
        }
        if (preg_match('/^turn CARDNAME face up$/i', $s)) {
            return ['type' => 'face_up', 'self' => true];
        }
        // `create a tapped Treasure token`, `create two tapped 1/1 white Soldier creature tokens`.
        if (preg_match('/^create (\w+) tapped (.+)$/i', $s, $m) && ($token = self::effect("create {$m[1]} {$m[2]}")) !== null && $token['type'] === 'token' && ! isset($token['attacking'])) {
            return $token + ['tapped' => true];
        }
        if (($token = self::token($s)) !== null) {
            return $token;
        }
        if (preg_match('/^counter (target spell|target creature spell|target noncreature spell) unless its controller pays \{(\d+|X)\}$/i', $s, $m)) {
            return ['type' => 'counter', 'target' => self::TARGETS[strtolower($m[1])], 'unless' => '{'.$m[2].'}'];
        }
        $each = ['creatures you control' => 'yours', 'creatures your opponents control' => 'opponents', 'creatures you don\'t control' => 'opponents', 'creatures defending player controls' => 'opponents', 'all creatures' => 'all', 'creatures without flying' => 'without_flying', 'attacking creatures' => 'attacking', 'attacking creatures you control' => 'attacking'];
        $group = implode('|', array_keys($each));
        // Consume the Meek: "each creature" selects the same objects as "all creatures".
        $s = (string) preg_replace('/^(destroy|exile) each creature\b/i', '$1 all creatures', $s);
        // Culling Ritual, Pernicious Deed: `Destroy each nonland permanent with mana value 2 or less.`
        $s = (string) preg_replace(['/^(destroy|exile) each nonland permanent\b/i', '/^(destroy|exile) each artifact, creature, and enchantment\b/i'], ['$1 all nonland permanents', '$1 all artifacts, creatures, and enchantments'], $s);
        if (preg_match('/^(destroy|exile) (all creatures|all creatures you don\'t control|all creatures your opponents control|all artifacts|all enchantments|all nonland permanents|all artifacts and enchantments|all artifacts, creatures, and lands|all other creatures|all creatures with flying|all creatures without flying|all tapped creatures|all creatures with power \d+ or (?:less|greater)|all (?:creatures|nonland permanents|artifacts, creatures, and enchantments) with mana value \d+ or (?:less|greater)|all artifacts, creatures, and enchantments)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'all' => strtolower($m[2])];
        }
        // Evacuation, Aetherize-style sweepers: `Return all creatures to their owners' hands.`
        if (preg_match('/^return (all creatures|all creatures you don\'t control|all creatures your opponents control|all nonland permanents|all other creatures|all attacking creatures|all creatures with flying|all creatures without flying|all artifacts|all enchantments) to their owners\' hands$/i', $s, $m)) {
            return ['type' => 'bounce', 'all' => strtolower($m[1])];
        }
        // Time Stop, Ultima: `End the turn.`; see Game::endTheTurn().
        if (preg_match('/^end the turn$/i', $s)) {
            return ['type' => 'end_turn'];
        }
        // `Choose a creature type.` for a spell: the game picks the type most of your creatures share; see Game::choiceFor().
        if (preg_match('/^choose a creature type$/i', $s)) {
            return ['type' => 'choose_type'];
        }
        // `Creatures (you control) of the chosen type get +1/+1 until end of turn.` and `Elves you control get +2/+2 until end of turn.`
        if (preg_match('/^creatures( you control)? of the chosen type get ([+-]\d+)\/([+-]\d+)(?: and gain (.+?))? until end of turn$/i', $s, $m) && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'each' => $m[1] !== '' ? 'yours' : 'all', 'subtype' => 'chosen'];
        }
        // `Other Elves you control get +1/+1 until end of turn.` and `Elves you control gain hexproof until end of turn.`
        if (preg_match('/^([Oo]ther )?([A-Z][a-z]+s?) you control (?:get ([+-]\d+)\/([+-]\d+)(?: and gain (.+?))?|gain (.+?)) until end of turn$/', $s, $m) && ($type = self::subtypeOf($m[2])) !== null
            && ($keywords = self::keywordList(($m[6] ?? '') !== '' ? $m[6] : ($m[5] ?? ''))) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[3], 'toughness' => (int) $m[4], 'keywords' => $keywords, 'each' => 'yours', 'subtype' => $type] + ($m[1] !== '' ? ['other' => true] : []);
        }
        // `each other attacking creature gets +1/+0 until end of turn.`
        $s = (string) preg_replace('/^each other attacking creature gets /i', 'other attacking creatures get ', $s);
        // `Other attacking creatures you control get +1/+0 until end of turn.`: all but CARDNAME.
        $other = preg_match('/^other (creatures you control|attacking creatures you control|attacking creatures) (get|gain) /i', $s) === 1;
        $s = $other ? substr($s, 6) : $s;
        if (preg_match("/^({$group}) get ([+-]\\d+)\\/([+-]\\d+)(?: and gain (.+?))? until end of turn$/i", $s, $m)
            && ($keywords = self::keywordList($m[4] ?? '')) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[2], 'toughness' => (int) $m[3], 'keywords' => $keywords, 'each' => $each[strtolower($m[1])]] + ($other ? ['other' => true] : []);
        }
        // `Creatures you control get +X/+X until end of turn, where X is …` (see sentenceEffects()).
        if (preg_match("/^({$group}) get ([+-])X\\/([+-])(X|0) until end of turn$/i", $s, $m)) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => [], 'xPump' => [$m[2] === '-' ? -1 : 1, $m[4] === '0' ? 0 : ($m[3] === '-' ? -1 : 1)], 'each' => $each[strtolower($m[1])]] + ($other ? ['other' => true] : []);
        }
        if (preg_match("/^({$group}) gain (.+?) until end of turn$/i", $s, $m) && ($keywords = self::keywordList($m[2])) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'each' => $each[strtolower($m[1])]] + ($other ? ['other' => true] : []);
        }
        if ($other) {
            return null;
        }
        // Shrewd Hatchling: `Target creature can't block CARDNAME this turn.`
        if (preg_match("/^({$targets}) can't block CARDNAME this turn$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'cant_block_source', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Vortex Elemental: `Target creature blocks CARDNAME this turn if able.`
        if (preg_match("/^({$targets}) blocks CARDNAME this turn if able$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
            return ['type' => 'must_block_source', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // "Target creature can't block this turn": a restriction until end of turn.
        if (preg_match("/^({$targets}|CARDNAME|{$group}) (can't block|can't be blocked) this turn$/i", $s, $m)) {
            $restriction = [strtolower($m[2])];
            if ($m[1] === 'CARDNAME') {
                return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $restriction, 'self' => true];
            }
            if (isset($each[strtolower($m[1])])) {
                return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $restriction, 'each' => $each[strtolower($m[1])]];
            }
            if (in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)) {
                return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $restriction, 'target' => self::TARGETS[strtolower($m[1])]];
            }
        }
        // Memory's Journey: `Target player shuffles up to three target cards from their graveyard into their library.` The caster picks themself.
        if (preg_match('/^(?:target player shuffles|shuffle) up to (\w+) target cards? from (?:their|your) graveyard into (?:their|your) library$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'gy_shuffle', 'amount' => $n];
        }
        if (preg_match('/^(?:(?:then )?shuffle your graveyard into your library|target player shuffles their graveyard into their library)$/i', $s)) {
            return ['type' => 'gy_shuffle', 'amount' => null];
        }
        if (preg_match('/^(target player|target opponent) mills (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'mill', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^mill (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'mill', 'amount' => $n];
        }
        if (preg_match('/^each (opponent|player) mills (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'mill', 'amount' => $n, 'each' => strtolower($m[1])];
        }
        if (preg_match('/^discover (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'discover', 'amount' => $n];
        }
        // Goblin Piledriver: `it gets +2/+0 until end of turn for each other attacking Goblin`.
        // `it gets +1/+0 until end of turn for each other attacking creature`: any of them.
        if (preg_match('/^(?:CARDNAME|it) gets \+(\d+)\/\+(\d+) until end of turn for each other attacking ([A-Z][a-z]+|creature)$/', $s, $m) && ($type = $m[3] === 'creature' ? 'creature' : self::subtypeOf($m[3])) !== null) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => [], 'self' => true, 'perAttacking' => $type];
        }
        // `Whenever CARDNAME becomes blocked, it deals 2 damage to each creature blocking it.`
        if (preg_match('/^(?:CARDNAME|it) deals (\w+) damage to each creature blocking it$/', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'damage', 'amount' => $n, 'each' => 'blocking_self'];
        }
        // `Whenever CARDNAME attacks, it gets +1/+0 until end of turn for each Aura and Equipment attached to it.`
        if (preg_match('/^(?:CARDNAME|it) gets \+(\d+)\/\+(\d+) until end of turn for each (Aura|Equipment|Aura and Equipment) attached to it$/', $s, $m)) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => [], 'self' => true, 'perAttached' => str_replace(' and ', '|', $m[3])];
        }
        // `Whenever CARDNAME becomes blocked, it gets +1/+1 until end of turn for each creature blocking it.`
        if (preg_match('/^(?:CARDNAME|it) gets \+(\d+)\/\+(\d+) until end of turn for each creature blocking it$/', $s, $m)) {
            return ['type' => 'pump', 'power' => (int) $m[1], 'toughness' => (int) $m[2], 'keywords' => [], 'self' => true, 'perBlocking' => true];
        }
        // Drifter il-Dal: `At the beginning of your upkeep, sacrifice CARDNAME unless you pay {U}.`
        if (preg_match('/^sacrifice (?:CARDNAME|it) unless you pay ((?:\{[0-9WUBRGC]\})+)$/i', $s, $m)) {
            return ['type' => 'sac_unless', 'cost' => $m[1], 'self' => true];
        }
        // `When CARDNAME enters, it endures 2.`
        if (preg_match('/^(?:CARDNAME|it) endures (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'endure', 'amount' => $n, 'self' => true];
        }
        // `Target creature you control endures 1.`
        if (preg_match('/^target creature you control endures (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'endure', 'amount' => $n, 'target' => self::TARGETS['target creature you control']];
        }
        if (preg_match('/^you get ((?:\{E\})+)$/i', $s, $m)) {
            return ['type' => 'energy', 'amount' => substr_count($m[1], '{E}')];
        }
        // `Investigate twice.`: a Clue each time.
        if (preg_match('/^investigate(?: (twice|three times|(\w+) times))?$/i', $s, $m) && is_int($n = match ($m[1] ?? '') {
            '' => 1, 'twice' => 2, 'three times' => 3, default => self::amount($m[2])
        })) {
            return ['type' => 'token', 'amount' => $n, 'token' => self::ARTIFACT_TOKENS['Clue']];
        }
        if (preg_match('/^create (\w+) (Food|Clue|Treasure|Blood|Lander|Map|Junk) tokens?$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'token', 'amount' => $n, 'token' => self::ARTIFACT_TOKENS[ucfirst(strtolower($m[2]))]];
        }
        if (preg_match("/^gain control of ({$targets}) until end of turn$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])], ['artifact', ...self::CREATURE_KINDS], true)) {
            return ['type' => 'control', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match('/^attach (?:it|CARDNAME) to target creature you control$/', $s)) {
            return ['type' => 'attach', 'target' => 'creature_you_control'];
        }
        if (preg_match('/^(untap|tap) (that creature|that permanent|it)$/i', $s, $m)) {
            return ['type' => strtolower($m[1]), 'sameTarget' => true];
        }
        // `Return target land card from your graveyard to the battlefield tapped.`: any permanent card returns to the battlefield.
        if (preg_match("/^return ({$targets}) to (your hand|the battlefield)( tapped)?$/i", $s, $m)
            && in_array($kind = self::TARGETS[strtolower($m[1])], ['creature_card_yours', 'creature_card_yours_other', 'legendary_creature_card_yours', 'card_yours', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours', 'permanent_card_yours'], true)
            && ($m[2] === 'your hand' ? ! isset($m[3]) : ! in_array($kind, ['card_yours', 'instant_sorcery_card_yours'], true))) {
            return ['type' => $m[2] === 'your hand' ? 'bounce' : 'reanimate', 'target' => $kind] + (isset($m[3]) ? ['tapped' => true] : []);
        }
        // `Return target Elf card from your graveyard to your hand.`: any card with that subtype.
        if (preg_match('/^[Rr]eturn target ([A-Z][a-z]+) card from your graveyard to your hand$/', $s, $m)
            && ! in_array($m[1], ['Creature', 'Land', 'Artifact', 'Enchantment', 'Instant', 'Sorcery', 'Permanent', 'Legendary', 'Planeswalker', 'Nonland', 'Noncreature', 'Spirit', 'Historic', 'Basic', 'Nonbasic'], true)) {
            return ['type' => 'bounce', 'target' => "subtype_card_yours_{$m[1]}"];
        }
        // `Return target creature card with mana value 3 or less from your graveyard to the battlefield.`
        if (preg_match("/^return ({$targets}) to (your hand|the battlefield)$/i", $s, $m) && str_starts_with($kind = self::TARGETS[strtolower($m[1])], 'creature_card_yours_mv_le_')) {
            return ['type' => $m[2] === 'your hand' ? 'bounce' : 'reanimate', 'target' => $kind];
        }
        // Reanimate: `Put target creature card from a graveyard onto the battlefield under your control.`
        if (preg_match("/^put ({$targets}) onto the battlefield under your control$/i", $s, $m) && self::TARGETS[strtolower($m[1])] === 'creature_card_graveyard') {
            return ['type' => 'reanimate', 'target' => 'creature_card_graveyard'];
        }
        if (preg_match('/^empower jace (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1]))) {
            return ['type' => 'empower', 'amount' => $n];
        }
        // `Copy target instant or sorcery spell. You may choose new targets for the copy.`: the copy keeps its targets.
        if (preg_match("/^copy ({$targets})$/i", $s, $m) && in_array(self::TARGETS[strtolower($m[1])] ?? null, ['instant_sorcery_spell', 'instant_sorcery_spell_yours'], true)) {
            return ['type' => 'copy_spell', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        // Clash (rule 701.23); `If you win, …` follows (see TextParser::modifies()).
        if (preg_match('/^clash with an opponent$/i', $s)) {
            return ['type' => 'clash'];
        }
        // `Choose a nonland card name.`: the search after it uses the name; see TextParser::modifies().
        if (preg_match('/^choose an? (?:(nonland|creature|artifact) )?card name$/i', $s, $m)) {
            return ['type' => 'choose_name', 'filter' => ($m[1] ?? '') === '' ? 'any' : strtolower($m[1])];
        }
        // `When CARDNAME dies, put its counters on target creature you control.`: its +1/+1 counters, as modular moves them.
        if (preg_match('/^put its counters on target creature you control$/i', $s)) {
            return ['type' => 'counters', 'amount' => 'counters', 'target' => 'creature_you_control'];
        }
        // Ahn-Crop Champion: `untap all other creatures you control`; all but the source.
        if (preg_match('/^(tap|untap) all (other )?creatures (you control|your opponents control)$/i', $s, $m) && ($m[2] === '' || strtolower($m[3]) === 'you control')) {
            return ['type' => strtolower($m[1]), 'each' => strtolower($m[3]) === 'you control' ? 'yours' : 'theirs'] + ($m[2] !== '' ? ['other' => true] : []);
        }
        // `Whenever CARDNAME attacks, mobilize 1.` as an effect: see the Mobilize keyword (rule 702.181).
        if (preg_match('/^mobilize (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'mobilize', 'amount' => $n];
        }
        // Bolster (rule 701.39): counters on your creature with the least toughness.
        if (preg_match('/^bolster (\w+)$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'bolster', 'amount' => $n];
        }
        if (preg_match('/^proliferate$/i', $s)) {
            return ['type' => 'proliferate'];
        }
        if (preg_match("/^return CARDNAME to its owner's hand$/i", $s)) {
            return ['type' => 'bounce', 'self' => true];
        }
        // Surgical Extraction: `Choose target card in a graveyard other than a basic land card.`
        if (preg_match("/^choose ({$targets})$/i", $s, $m) && (self::TARGETS[strtolower($m[1])] ?? null) === 'card_graveyard_nonbasic') {
            return ['type' => 'choose_target', 'target' => 'card_graveyard_nonbasic'];
        }
        // `Search its owner's graveyard, hand, and library for any number of cards with the same name as that card and exile them.`
        if (preg_match("/^search its (?:owner|controller)'s graveyard, hand, and library for (?:any number of|all|up to four) cards with the same name as that (?:card|land|creature|permanent) and exile them$/i", $s)) {
            return ['type' => 'exile_named', 'sameTarget' => true];
        }
        // Bribery: `Search target opponent's library for a creature card and put that card onto the battlefield under your control.`
        if (preg_match("/^search target opponent's library for an? (.+?) card and put (?:that card|it) onto the battlefield under your control$/i", $s, $m) && ($filter = self::cardFilter(strtolower($m[1]))) !== null) {
            return ['type' => 'steal_search', 'filter' => $filter, 'target' => 'opponent'];
        }
        // Path to Exile: `Its controller may search their library for a basic land card, put that card onto the battlefield tapped, then shuffle.`
        if (preg_match('/^its controller may search their library for a basic land card, put (?:it|that card) (onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m)) {
            return ['type' => 'search', 'find' => 'basic land', 'to' => strtolower($m[1]) === 'onto the battlefield' ? 'battlefield' : 'tapped', 'theirs' => true, 'sameTarget' => true];
        }
        // Burnished Hart / Harrow: choose zero, one or two basics, then shuffle once.
        if (preg_match('/^Search your library for up to two basic land cards, put them onto the battlefield( tapped)?, then shuffle$/i', $s, $m)) {
            return ['type' => 'tutor', 'filter' => 'basic_land', 'count' => 2, 'to' => isset($m[1]) ? 'tapped' : 'battlefield', 'reveal' => false];
        }
        // Other legacy up-to searches retain their existing policy.
        if (preg_match('/^(?:you may )?search your library for up to (\w+) basic land cards, (?:reveal them, )?put them (into your hand|onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m) && is_int($n = self::amount($m[1])) && $n > 0) {
            return ['type' => 'search', 'find' => 'basic land', 'count' => $n, 'to' => match (strtolower($m[2])) {
                'into your hand' => 'hand',
                'onto the battlefield tapped' => 'tapped',
                default => 'battlefield',
            }];
        }
        // Rampant Growth / Evolving Wilds: mandatory search with a choice, including failure to find.
        if (preg_match('/^search your library for a basic land card, put (?:it|that card) onto the battlefield tapped, then shuffle$/i', $s)) {
            return ['type' => 'tutor', 'filter' => 'basic_land', 'to' => 'tapped', 'reveal' => false];
        }

        // Spider-Bot: `… search your library for a basic land card, reveal it, then shuffle and put that card on top.`
        if (preg_match('/^(?:you may )?search your library for an? (basic land|plains|island|swamp|mountain|forest) card, reveal it, then shuffle and put that card on top$/i', $s, $m)) {
            return ['type' => 'search', 'find' => strtolower($m[1]) === 'basic land' ? 'basic land' : ucfirst(strtolower($m[1])), 'to' => 'top'];
        }
        // Cultivate: `… for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle.`
        if (strcasecmp($s, 'Search your library for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle') === 0) {
            return ['type' => 'split_land_search'];
        }
        if (preg_match('/^you may search your library for up to two basic land cards, reveal those cards, put one onto the battlefield tapped and the other into your hand, then shuffle$/i', $s)) {
            return ['type' => 'search', 'find' => 'basic land', 'count' => 2, 'to' => 'split'];
        }
        if (preg_match('/^(?:you may )?search your library for an? (basic land|plains|island|swamp|mountain|forest) card, (?:reveal it, )?put it (into your hand|onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m)) {
            return ['type' => 'search', 'find' => strtolower($m[1]) === 'basic land' ? 'basic land' : ucfirst(strtolower($m[1])), 'to' => match (strtolower($m[2])) {
                'into your hand' => 'hand',
                'onto the battlefield tapped' => 'tapped',
                default => 'battlefield',
            }];
        }
        // The Harbingers: `… search your library for a Goblin card, reveal it, then shuffle and put that card on top.`
        if (preg_match('/^(?:you may )?search your library for an? (.+?) card, reveal it, then shuffle and put that card on top$/i', $s, $m) && ($filter = self::cardFilter($m[1])) !== null) {
            return ['type' => 'tutor', 'filter' => $filter, 'to' => 'top', 'reveal' => true];
        }
        // A tutor: `Search your library for a creature card, reveal it, put it into your hand, then shuffle.`
        // `… for a creature card with mana value 3 or less, …` and `… with power 2 or less, …`: as printed.
        if (preg_match('/^(?:you may )?search your library for an? (?:(.+?) )?card(?: with (mana value|power|toughness) (\d+) or (less|greater))?, (?:reveal it, )?put (?:it|that card) (into your hand|onto the battlefield tapped|onto the battlefield), then shuffle$/i', $s, $m)
            && ($filter = ($m[1] ?? '') === '' ? 'any' : self::cardFilter($m[1])) !== null) {
            if (($m[2] ?? '') !== '') {
                $filter = ($filter === 'any' ? '' : "{$filter}&").['mana value' => 'mv', 'power' => 'pow', 'toughness' => 'tou'][strtolower($m[2])].'_'.(strtolower($m[4]) === 'less' ? 'le' : 'ge')."_{$m[3]}";
            }

            return ['type' => 'tutor', 'filter' => $filter, 'reveal' => stripos($s, ', reveal it, ') !== false, 'to' => match (strtolower($m[5])) {
                'into your hand' => 'hand',
                'onto the battlefield tapped' => 'tapped',
                default => 'battlefield',
            }];
        }
        if (preg_match('/^sacrifice (?:it|CARDNAME) at end of combat$/i', $s)) {
            return ['type' => 'sacrifice_end_combat', 'self' => true];
        }
        if (preg_match('/^discard (\w+) cards?$/i', $s, $m) && ($n = self::amount($m[1])) !== null) {
            return ['type' => 'discard', 'amount' => $n];
        }
        // An edict: `Target player sacrifices a creature of their choice.`, `Each opponent sacrifices a creature or planeswalker.`
        if (preg_match("/^(target player|target opponent|each opponent|each player) sacrifices (a|an|one|two|three) (creature|artifact|enchantment|land|permanent|nonland permanent|creature or planeswalker|artifact or creature|attacking creature)s?(?: of their choice)?$/i", $s, $m)
            && is_int($n = self::amount($m[2] === 'an' ? 'a' : $m[2]))) {
            $who = strtolower($m[1]);

            return ['type' => 'edict', 'amount' => $n, 'kind' => strtolower($m[3])] + (str_starts_with($who, 'target') ? ['target' => self::TARGETS[$who]] : ['each' => substr($who, 5)]);
        }
        // Liliana of the Veil, Pale Wanderer: `Target player sacrifices a creature with the greatest power among creatures they control.`
        if (preg_match("/^(target player|target opponent|each opponent|each player) sacrifices (?:a|the) creature with the (greatest|least) (power|mana value) among (?:creatures|the creatures) (?:they control|that player controls|they controls)$/i", $s, $m)) {
            $who = strtolower($m[1]);

            return ['type' => 'edict', 'amount' => 1, 'kind' => 'creature', 'pick' => strtolower($m[2]).' '.strtolower($m[3])]
                + (str_starts_with($who, 'target') ? ['target' => self::TARGETS[$who]] : ['each' => substr($who, 5)]);
        }
        // Grisly Salvage-style: `Return a creature card from your graveyard to your hand.`; chosen as it resolves, so cards milled just before count.
        if (preg_match("/^(?:then )?(?:you may )?return (?:a|an|up to one) (creature|artifact|land|enchantment|instant or sorcery|permanent|nonland|creature or land)? ?card from your graveyard to your hand$/i", $s, $m)) {
            return ['type' => 'return_card', 'kind' => strtolower($m[1] ?? '') ?: 'card'];
        }
        // Graveyard hate: `Exile target player's graveyard.`, `Exile each opponent's graveyard.`, `Exile all graveyards.`
        if (preg_match("/^exile (?:all cards from )?(target player|target opponent)'s graveyard$/i", $s, $m)) {
            return ['type' => 'exile_graveyard', 'target' => self::TARGETS[strtolower($m[1])]];
        }
        if (preg_match("/^exile (each opponent's graveyard|all graveyards)$/i", $s, $m)) {
            return ['type' => 'exile_graveyard', 'each' => str_starts_with(strtolower($m[1]), 'each') ? 'opponent' : 'player'];
        }
        // Learn (rule 701.48): without Lessons outside the game, the choice is to discard a card and draw one, or not.
        if (preg_match('/^learn$/i', $s)) {
            return ['type' => 'discard', 'amount' => 1, 'may' => true, 'draw' => 1];
        }
        // `You may discard a card. If you do, draw a card.`: see TextParser::modifies() for the draw.
        if (preg_match('/^you may discard a card$/i', $s)) {
            return ['type' => 'discard', 'amount' => 1, 'may' => true];
        }
        // `Target player discards a card at random.`: see Game::applyEffect().
        if (preg_match('/^(target player|target opponent) discards (\w+) cards?( at random)?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'discard', 'amount' => $n, 'target' => self::TARGETS[strtolower($m[1])]] + (($m[3] ?? '') !== '' ? ['random' => true] : []);
        }
        if (preg_match('/^each (opponent|player) discards (\w+) cards?( at random)?$/i', $s, $m) && ($n = self::amount($m[2])) !== null) {
            return ['type' => 'discard', 'amount' => $n, 'each' => strtolower($m[1])] + (($m[3] ?? '') !== '' ? ['random' => true] : []);
        }
        if (preg_match("/^({$targets}) gains (.+?) until end of turn$/i", $s, $m)
            && in_array(self::TARGETS[strtolower($m[1])], self::CREATURE_KINDS, true)
            && ($keywords = self::keywordList($m[2], true)) !== null) {
            return ['type' => 'pump', 'power' => 0, 'toughness' => 0, 'keywords' => $keywords, 'target' => self::TARGETS[strtolower($m[1])]];
        }

        return null;
    }

    /**
     * `Create two 1/1 white Soldier creature tokens with flying` as an effect.
     *
     * @param string $sentence
     *
     * @return array|null
     */
    private static function token(string $sentence): ?array
    {
        $colors = ['white' => 'W', 'blue' => 'U', 'black' => 'B', 'red' => 'R', 'green' => 'G', 'colorless' => null];
        $color = implode('|', array_keys($colors));
        if (! preg_match("/^create (\\w+) (\\d+)\\/(\\d+) ((?:{$color})(?:(?:, | and )(?:{$color}))*) ([A-Z][A-Za-z' ]*?) (artifact )?creature tokens?(?: with (.+?))?( that's tapped and attacking| that are tapped and attacking| tapped and attacking)?$/i", $sentence, $m)
            || ($count = self::amount($m[1])) === null) {
            return null;
        }
        $keywords = self::keywordList($m[7] ?? '');
        if ($keywords === null) {
            return null;
        }
        $tokenColors = array_values(array_filter(array_map(fn ($word) => $colors[strtolower($word)] ?? null, preg_split('/, | and /', $m[4]))));
        $subtypes = preg_split('/\s+/', trim($m[5]));
        $artifact = ($m[6] ?? '') !== '';

        return [
            'type' => 'token',
            'amount' => $count,
            'token' => [
                'name' => implode(' ', $subtypes).' Token',
                'type' => 'Token '.($artifact ? 'Artifact ' : '').'Creature — '.implode(' ', $subtypes),
                'types' => $artifact ? ['Artifact', 'Creature'] : ['Creature'],
                'subtypes' => $subtypes,
                'colors' => $tokenColors,
                'power' => $m[2],
                'toughness' => $m[3],
                'text' => implode(', ', array_map('ucfirst', $keywords)),
                'manaCost' => null,
            ],
        ] + (($m[8] ?? '') !== '' ? ['attacking' => true] : []);
    }

    /**
     * `+3` or `-X` as a number; X is not supported here.
     *
     * @param string $text
     *
     * @return int|null
     */
    private static function signed(string $text): ?int
    {
        return preg_match('/^[+-]\d+$/', $text) ? (int) $text : null;
    }

    private static function isPermanentTarget(string $phrase): bool
    {
        return ! in_array(self::TARGETS[strtolower($phrase)], ['any', 'player', 'opponent', 'player_or_planeswalker', 'spell', 'creature_spell', 'noncreature_spell', 'spell_targets_your_creature', 'spell_targets_you', 'spell_targets_you_or_creature', 'instant_sorcery_spell', 'instant_sorcery_spell_yours', 'creature_card_yours', 'legendary_creature_card_yours', 'card_yours', 'card_graveyard_nonbasic', 'instant_sorcery_card_yours', 'artifact_card_yours', 'land_card_yours', 'enchantment_card_yours', 'permanent_card_yours', 'creature_card_graveyard', 'card_graveyard', 'creature_card_yours_other', 'card_graveyard_opponent', 'creature_card_graveyard_opponent'], true)
            && ! str_starts_with(self::TARGETS[strtolower($phrase)], 'creature_card_yours_mv_le_') && ! str_starts_with(self::TARGETS[strtolower($phrase)], 'spell_mv_le_');
    }
}
