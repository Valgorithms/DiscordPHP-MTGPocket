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
 * Graveyard casts, not abilities or copies, trigger their controller's permanents.
 *
 * @covers \MTGPocket\Game\CardDefinition
 * @covers \MTGPocket\Game\Game
 * @covers \MTGPocket\Game\TextParser
 */
final class GraveyardCastTriggerTest extends GameTestCase
{
    /** Real card wording, kept offline so tests never require an imported pool. */
    private const array MORE = [
        'Secrets of the Dead' => ['manaCost' => '{2}{U}', 'type' => 'Enchantment', 'colors' => ['U'], 'text' => 'Whenever you cast a spell from your graveyard, draw a card.'],
        'Burning Vengeance' => ['manaCost' => '{2}{R}', 'type' => 'Enchantment', 'colors' => ['R'], 'text' => 'Whenever you cast a spell from your graveyard, this enchantment deals 2 damage to any target.'],
        'Devoted Grafkeeper // Departed Soulkeeper' => ['manaCost' => '{W}{U}', 'type' => 'Creature — Human Peasant', 'colors' => ['W', 'U'], 'power' => '2', 'toughness' => '1', 'layout' => 'transform',
            'text' => "When this creature enters, mill two cards.\nWhenever you cast a spell from your graveyard, tap target creature you don't control.\nDisturb {1}{W}{U} (You may cast this card from your graveyard transformed for its disturb cost.)",
            'back' => ['name' => 'Departed Soulkeeper', 'manaCost' => null, 'type' => 'Creature — Spirit', 'colors' => ['W', 'U'], 'power' => '3', 'toughness' => '1', 'text' => "Flying\nThis creature can block only creatures with flying.\nIf Departed Soulkeeper would be put into a graveyard from anywhere, exile it instead."]],
        'Think Twice' => ['manaCost' => '{1}{U}', 'type' => 'Instant', 'colors' => ['U'], 'text' => "Draw a card.\nFlashback {2}{U} (You may cast this card from your graveyard for its flashback cost. Then exile it.)"],
        'Flame Jab' => ['manaCost' => '{R}', 'type' => 'Sorcery', 'colors' => ['R'], 'text' => "Flame Jab deals 1 damage to any target.\nRetrace (You may cast this card from your graveyard by discarding a land card in addition to paying its other costs.)"],
        'Dregscape Zombie' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Zombie', 'colors' => ['B'], 'power' => '2', 'toughness' => '1', 'text' => 'Unearth {B} ({B}: Return this card from your graveyard to the battlefield. It gains haste. Exile it at the beginning of the next end step or if it would leave the battlefield. Unearth only as a sorcery.)'],
        'Reassembling Skeleton' => ['manaCost' => '{1}{B}', 'type' => 'Creature — Skeleton Warrior', 'colors' => ['B'], 'power' => '1', 'toughness' => '1', 'text' => '{1}{B}: Return this card from your graveyard to the battlefield tapped.'],
        'Reverberate' => ['manaCost' => '{R}{R}', 'type' => 'Instant', 'colors' => ['R'], 'text' => 'Copy target instant or sorcery spell. You may choose new targets for the copy.'],
        'Artful Dodge' => ['manaCost' => '{U}', 'type' => 'Sorcery', 'colors' => ['U'], 'text' => "Target creature can't be blocked this turn.\nFlashback {U} (You may cast this card from your graveyard for its flashback cost. Then exile it.)"],
        'Momentary Blink' => ['manaCost' => '{1}{W}', 'type' => 'Instant', 'colors' => ['W'], 'text' => "Exile target creature you control, then return it to the battlefield under its owner's control.\nFlashback {3}{U} (You may cast this card from your graveyard for its flashback cost. Then exile it.)"],
        'Staggershock' => ['manaCost' => '{2}{R}', 'type' => 'Instant', 'colors' => ['R'], 'text' => "Staggershock deals 2 damage to any target.\nRebound (If you cast this spell from your hand, exile it as it resolves. At the beginning of your next upkeep, you may cast this card from exile without paying its mana cost.)"],
    ];

    private static function more(string $name): array
    {
        return ['uuid' => 'graveyard-cast-'.strtolower(preg_replace('/\W+/', '-', $name)), 'name' => $name, 'rarity' => 'common'] + self::MORE[$name];
    }

    private function put(int $seat, string $name, string $zone): int
    {
        return $this->game->addCard($seat, self::more($name), $zone)->id;
    }

    private static function casualtySpell(): array
    {
        // Synthetic combination isolates casualty's pre-trigger copy from the cast's actual zone.
        return ['uuid' => 'grave-casualty', 'name' => 'Grave Casualty', 'rarity' => 'common', 'manaCost' => '{1}{U}', 'type' => 'Instant', 'colors' => ['U'], 'text' => "Draw a card.\nFlashback {2}{U}\nCasualty 1"];
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

    private function assertRejectedWithoutMutation(callable $action): void
    {
        $before = $this->game->toArray();
        try {
            $action();
            $this->fail('The action must be rejected.');
        } catch (GameException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->game->toArray());
    }

    public function testRealCardWordingIsReadWhole(): void
    {
        foreach (array_keys(self::MORE) as $name) {
            $this->assertSame([], (new CardDefinition(self::more($name)))->unsupported, $name);
        }
        foreach (['Secrets of the Dead', 'Burning Vengeance'] as $name) {
            $this->assertSame('cast_from_graveyard', (new CardDefinition(self::more($name)))->triggered[0]['event']);
        }
    }

    public function testFlashbackTriggersAboveTheSpellButHandCastingDoesNot(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 5);
        $think = $this->put(0, 'Think Twice', GameObject::HAND);

        $game->cast(0, $think);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($think));

        $game->cast(0, $think, 0, [], 'fb');
        $this->assertCount(2, $game->stack);
        $this->assertSame($think, $game->stack[0]['object']);
        $this->assertSame("Secrets of the Dead's ability", $game->stack[1]['label']);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::STACK, $this->zone($think), 'The trigger resolves before Think Twice.');
        $this->assertCount(2, $game->players[0]->hand);
        $this->resolve();
        $this->assertCount(3, $game->players[0]->hand);
        $this->assertSame(GameObject::EXILE, $this->zone($think));
    }

    public function testCombinedEntryAndGraveyardCastWordingRemainsUnsupported(): void
    {
        $card = new CardDefinition([
            'uuid' => 'ominous-roost', 'name' => 'Ominous Roost', 'rarity' => 'uncommon', 'manaCost' => '{2}{U}', 'type' => 'Enchantment', 'colors' => ['U'],
            'text' => 'When this enchantment enters and whenever you cast a spell from your graveyard, create a 1/1 blue Bird creature token with flying and "This token can block only creatures with flying."',
        ]);
        $this->assertCount(1, $card->unsupported);
        $this->assertStringContainsString('enters and whenever you cast a spell from your graveyard', $card->unsupported[0]);
        $this->assertSame([], $card->triggered, 'Do not silently drop the entry event or part of the token ability.');
    }

    public function testDevotedGrafkeeperMillsThenTargetsOnlyAnOpponentsCreature(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Plains', 1);
        $this->lands(0, 'Island', 1);
        $mine = $this->battlefield(0, 'Grizzly Bears');
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $other = $this->battlefield(1, 'Hill Giant');
        $grafkeeper = $this->put(0, 'Devoted Grafkeeper // Departed Soulkeeper', GameObject::HAND);
        $library = count($game->players[0]->library);
        $game->cast(0, $grafkeeper);
        $this->resolve();
        $this->resolve();
        $this->assertSame($library - 2, count($game->players[0]->library));
        $this->assertCount(2, $game->players[0]->graveyard);

        $this->lands(0, 'Island', 3);
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $game->cast(0, $think, 0, [], 'fb');
        $this->assertSame('trigger', $game->decision(0));
        $this->assertSame(['creature_opponent'], $game->triggerAwaitingTargets()['kinds']);
        $this->assertRejectedWithoutMutation(fn () => $game->chooseTriggerTargets(0, ["o:{$mine}"]));
        $this->assertRejectedWithoutMutation(fn () => $game->chooseTriggerTargets(0, ['p:1']));
        $game->chooseTriggerTargets(0, ["o:{$theirs}"]);
        $this->resolve();
        $this->assertTrue($game->objects[$theirs]->tapped);
        $this->assertFalse($game->objects[$other]->tapped);
        $this->assertFalse($game->objects[$mine]->tapped);
        $this->assertSame(GameObject::STACK, $this->zone($think));
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testDisturbedSoulkeeperDoesNotRetainTheFrontFaceTrigger(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Plains', 1);
        $this->lands(0, 'Island', 2);
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $grafkeeper = $this->put(0, 'Devoted Grafkeeper // Departed Soulkeeper', GameObject::GRAVEYARD);
        $game->cast(0, $grafkeeper, 0, [], 'db');
        $this->assertCount(2, $game->stack, 'Disturb is a graveyard cast for Secrets of the Dead.');
        $this->resolve();
        $library = count($game->players[0]->library);
        $this->resolve();
        $this->assertSame('Departed Soulkeeper', $game->objects[$grafkeeper]->name());
        $this->assertTrue($game->objects[$grafkeeper]->transformed);
        $this->assertSame($library, count($game->players[0]->library), 'The back face does not mill on entry.');
        $this->assertSame([], $game->stack);
        $game = $this->reload();

        $this->lands(0, 'Island', 3);
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $game->cast(0, $think, 0, [], 'fb');
        $this->assertSame('priority', $game->decision(0), 'The back face has no tap-target trigger.');
        $this->assertCount(2, $game->stack, 'Only Secrets of the Dead triggers.');
        $this->resolve();
        $this->resolve();
        $this->assertFalse($game->objects[$theirs]->tapped);
        $this->assertCount(3, $game->players[0]->hand);
    }

    public function testRetraceTriggersForEveryPaidCastWithoutFlashback(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 2);
        $jab = $this->put(0, 'Flame Jab', GameObject::GRAVEYARD);
        $land = $this->hand(0, 'Forest');

        for ($cast = 1; $cast <= 2; $cast++) {
            $game->cast(0, $jab, 0, ['p:1'], 'rt');
            $this->assertCount(2, $game->stack);
            $this->assertFalse($game->stack[0]['flashback'] ?? false);
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($land), 'Retrace discards a land as a cost.');
            $this->assertCount(0, $game->players[0]->hand);
            $this->resolve();
            $this->assertCount(1, $game->players[0]->hand);
            $land = $game->players[0]->hand[0];
            $this->resolve();
            $this->assertSame(20 - $cast, $this->life(1));
            $this->assertSame(GameObject::GRAVEYARD, $this->zone($jab));
        }
    }

    public function testAnOpponentsGraveyardCastDoesNotTriggerYourPermanent(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(1, 'Island', 3);
        $think = $this->put(1, 'Think Twice', GameObject::GRAVEYARD);
        $game->pass(0);
        $game->cast(1, $think, 0, [], 'fb');
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertCount(0, $game->players[0]->hand);
        $this->assertCount(1, $game->players[1]->hand);
    }

    public function testGraveyardAbilitiesDoNotCountAsCastingSpells(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Swamp', 3);
        $zombie = $this->put(0, 'Dregscape Zombie', GameObject::GRAVEYARD);
        $skeleton = $this->put(0, 'Reassembling Skeleton', GameObject::GRAVEYARD);

        $game->unearth(0, $zombie);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($zombie));
        $this->assertCount(0, $game->players[0]->hand);

        $game->regrow(0, $skeleton);
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($skeleton));
        $this->assertTrue($game->objects[$skeleton]->tapped);
        $this->assertCount(0, $game->players[0]->hand);
    }

    public function testFailedCastsDoNotCreateTriggersOrMutateTheGame(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $this->assertRejectedWithoutMutation(fn () => $game->cast(0, $think, 0, [], 'fb'));

        $lands = $this->lands(0, 'Island', 3);
        $hand = $this->put(0, 'Think Twice', GameObject::HAND);
        $this->assertRejectedWithoutMutation(fn () => $game->cast(0, $hand, 0, [], 'fb'));
        $dodge = $this->put(0, 'Artful Dodge', GameObject::GRAVEYARD);
        $this->assertRejectedWithoutMutation(fn () => $game->cast(0, $dodge, 0, ["o:{$lands[0]}"], 'fb'));
        $this->assertSame([], $game->stack);
    }

    public function testTheTriggerStillResolvesWhenTheGraveyardSpellIsCountered(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 3);
        $this->lands(1, 'Island', 2);
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $counter = $this->hand(1, 'Counterspell');
        $game->cast(0, $think, 0, [], 'fb');
        $spell = $game->stack[0]['id'];
        $game->pass(0);
        $game->cast(1, $counter, 0, ["s:{$spell}"]);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($think));
        $this->assertCount(1, $game->stack);
        $game = $this->reload();
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand, 'Only Secrets of the Dead draws a card.');
        $this->assertSame([], $game->stack);
    }

    public function testRemovingTheTriggerSourceDoesNotRemoveThePendingTrigger(): void
    {
        $game = $this->newGame();
        $secrets = $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 3);
        $this->lands(1, 'Plains', 2);
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $disenchant = $game->addCard(1, ['uuid' => 'graveyard-cast-disenchant', 'name' => 'Disenchant', 'rarity' => 'common', 'manaCost' => '{1}{W}', 'type' => 'Instant', 'colors' => ['W'], 'text' => 'Destroy target artifact or enchantment.'], GameObject::HAND)->id;
        $game->cast(0, $think, 0, [], 'fb');
        $game->pass(0);
        $game->cast(1, $disenchant, 0, ["o:{$secrets}"]);
        $this->resolve();
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($secrets));
        $game = $this->reload();
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand, 'The already-triggered ability survives its source.');
        $this->assertSame(GameObject::STACK, $this->zone($think));
        $this->resolve();
        $this->assertCount(2, $game->players[0]->hand);
    }

    public function testDamageTargetSelectionAndResolutionSurviveSaveAndResume(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Burning Vengeance', GameObject::BATTLEFIELD);
        $lands = $this->lands(0, 'Island', 3);
        $giant = $this->battlefield(1, 'Hill Giant');
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $game->cast(0, $think, 0, [], 'fb');
        $this->assertSame('trigger', $game->decision(0));
        $game = $this->reload();
        $this->assertSame('trigger', $game->decision(0));
        $this->assertRejectedWithoutMutation(fn () => $game->chooseTriggerTargets(1, ["o:{$giant}"]));
        $this->assertRejectedWithoutMutation(fn () => $game->chooseTriggerTargets(0, ["o:{$lands[0]}"]));
        $game->chooseTriggerTargets(0, ["o:{$giant}"]);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(2, $game->objects[$giant]->damage);
        $this->assertSame(GameObject::STACK, $this->zone($think));
        $this->assertCount(0, $game->players[0]->hand);
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testDamageDoesNotFollowATargetIntoANewIncarnation(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Burning Vengeance', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 3);
        $this->lands(1, 'Plains', 2);
        $bears = $this->battlefield(1, 'Grizzly Bears');
        $incarnation = $game->objects[$bears]->incarnation;
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $blink = $this->put(1, 'Momentary Blink', GameObject::HAND);
        $game->cast(0, $think, 0, [], 'fb');
        $game->chooseTriggerTargets(0, ["o:{$bears}"]);
        $game->pass(0);
        $game->cast(1, $blink, 0, ["o:{$bears}"]);
        $this->resolve();
        $this->assertGreaterThan($incarnation, $game->objects[$bears]->incarnation);
        $game = $this->reload();
        $this->resolve();
        $this->assertSame(GameObject::BATTLEFIELD, $this->zone($bears));
        $this->assertSame(0, $game->objects[$bears]->damage);
        $this->assertSame(GameObject::STACK, $this->zone($think));
        $this->resolve();
        $this->assertCount(1, $game->players[0]->hand);
    }

    public function testCopyingAGraveyardSpellDoesNotTriggerAgain(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 3);
        $this->lands(0, 'Mountain', 2);
        $think = $this->put(0, 'Think Twice', GameObject::GRAVEYARD);
        $copy = $this->put(0, 'Reverberate', GameObject::HAND);
        $game->cast(0, $think, 0, [], 'fb');
        $game->cast(0, $copy, 0, ["s:{$game->stack[0]['id']}"]);
        $this->resolve();
        $this->assertCount(3, $game->stack, 'The original, its copy and one graveyard-cast trigger.');
        $this->resolve();
        $this->resolve();
        $this->resolve();
        $this->assertCount(3, $game->players[0]->hand);
        $this->assertSame([], $game->stack);
    }

    public function testACopyAlreadyOnTheStackDoesNotHideTheOriginalCastOrigin(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Island', 3);
        $fodder = $this->battlefield(0, 'Grizzly Bears');
        $card = self::casualtySpell();
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $spell = $game->addCard(0, $card, GameObject::GRAVEYARD)->id;
        $game->cast(0, $spell, 0, [], 'fb,casualty');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($fodder));
        $this->assertCount(3, $game->stack);
        $this->assertSame("Secrets of the Dead's ability", $game->stack[2]['label']);
        $this->resolve();
        $this->resolve();
        $this->resolve();
        $this->assertCount(3, $game->players[0]->hand);
        $this->assertSame(GameObject::EXILE, $this->zone($spell));
    }

    public function testAPermanentSacrificedToCastTheSpellIsNotThereToTrigger(): void
    {
        $game = $this->newGame();
        $this->lands(0, 'Island', 3);
        $grafkeeper = $this->put(0, 'Devoted Grafkeeper // Departed Soulkeeper', GameObject::BATTLEFIELD);
        $theirs = $this->battlefield(1, 'Grizzly Bears');
        $spell = $game->addCard(0, self::casualtySpell(), GameObject::GRAVEYARD)->id;
        $game->cast(0, $spell, 0, [], 'fb,casualty');
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($grafkeeper));
        $this->assertSame('priority', $game->decision(0));
        $this->assertCount(2, $game->stack, 'Only the spell and casualty copy, not the sacrificed Grafkeeper.');
        $this->resolve();
        $this->resolve();
        $this->assertFalse($game->objects[$theirs]->tapped);
        $this->assertCount(2, $game->players[0]->hand);
        $this->assertSame([], $game->stack);
    }

    public function testReboundFromExileDoesNotCountAsAGraveyardCast(): void
    {
        $game = $this->newGame();
        $this->put(0, 'Secrets of the Dead', GameObject::BATTLEFIELD);
        $this->lands(0, 'Mountain', 3);
        $shock = $this->put(0, 'Staggershock', GameObject::HAND);
        $game->cast(0, $shock, 0, ['p:1']);
        $this->resolve();
        $this->assertSame(GameObject::EXILE, $this->zone($shock));
        $this->passUntil(Step::Upkeep, 3);
        $hand = count($game->players[0]->hand);
        $game->cast(0, $shock, 0, ['p:1'], 'rb');
        $this->assertCount(1, $game->stack);
        $this->resolve();
        $this->assertSame(16, $this->life(1));
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($shock));
        $this->assertCount($hand, $game->players[0]->hand);
    }
}
