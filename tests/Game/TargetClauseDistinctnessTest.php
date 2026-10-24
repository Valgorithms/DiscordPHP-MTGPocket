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
use MTGPocket\Tutorial\PracticeBot;

/** Synthetic clause-boundary controls; not whole-card certificates. */
final class TargetClauseDistinctnessTest extends GameTestCase
{
    private function start(int $actor): int
    {
        $this->newGame();
        if ($actor === 1) {
            $this->passUntil(Step::PrecombatMain, $this->game->turn + 1);
        }

        return $this->game->addCard($actor, self::card('Grizzly Bears'), GameObject::BATTLEFIELD)->id;
    }

    private function definition(string $text, string $type = 'Sorcery'): array
    {
        return ['name' => 'Clause Probe', 'manaCost' => '{U}', 'type' => $type, 'colors' => ['U'], 'text' => $text];
    }

    private function unchanged(callable $action): void
    {
        $before = $this->game->toArray();
        try {
            $action();
            $this->fail('Repeated target in one clause must reject.');
        } catch (GameException) {
            $this->assertSame($before, $this->game->toArray());
        }
    }

    /** @dataProvider actors */
    public function testCastRejectsWithinClauseBeforePayingButSeparateClausesMayReuse(int $actor): void
    {
        $a = $this->start($actor);
        $mana = $this->lands($actor, 'Island', 1)[0];
        $same = $this->game->addCard($actor, $this->definition('Tap up to two target creatures.'), GameObject::HAND)->id;
        $this->unchanged(fn () => $this->game->cast($actor, $same, 0, ["o:{$a}", "o:{$a}"]));
        $this->assertFalse($this->game->objects[$mana]->tapped);
        $separate = $this->game->addCard($actor, $this->definition('Tap target creature. Untap target creature.'), GameObject::HAND)->id;
        $this->assertSame([], $this->game->objects[$separate]->definition()->unsupported);
        $this->game->cast($actor, $separate, 0, ["o:{$a}", "o:{$a}"]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertFalse($this->game->objects[$a]->tapped);
        $this->assertTrue($this->game->objects[$mana]->tapped);
    }

    /** @dataProvider actors */
    public function testTriggerRejectsDuplicateWithoutRemovingPendingTrigger(int $actor): void
    {
        $a = $this->start($actor);
        $this->lands($actor, 'Island', 1);
        $card = $this->definition('When this enchantment enters, tap up to two target creatures.', 'Enchantment');
        $source = $this->game->addCard($actor, $card, GameObject::HAND)->id;
        $this->assertSame([], (new CardDefinition($card))->unsupported);
        $this->game->cast($actor, $source);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->unchanged(fn () => $this->game->chooseTriggerTargets($actor, ["o:{$a}", "o:{$a}"]));
        $this->game = Game::fromArray($this->game->toArray());
        $this->unchanged(fn () => $this->game->chooseTriggerTargets($actor, ["o:{$a}", "o:{$a}"]));
        $this->game->chooseTriggerTargets($actor, ["o:{$a}", '-']);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertTrue($this->game->objects[$a]->tapped);
    }

    /** @dataProvider actors */
    public function testPracticeSelectsDifferentTargetsOnlyWithinTheirClause(int $actor): void
    {
        $a = $this->start($actor);
        $b = $this->game->addCard($actor, self::card('Ornithopter'), GameObject::BATTLEFIELD)->id;
        $choose = new \ReflectionMethod(PracticeBot::class, 'targets');
        $single = new CardDefinition($this->definition('Untap up to two target creatures.'));
        $targets = $choose->invoke(null, $this->game, $actor, $single->targetKinds(), $single->spellEffects());
        $this->assertEqualsCanonicalizing(["o:{$a}", "o:{$b}"], $targets);
        $separate = new CardDefinition($this->definition('Untap target creature. Untap target creature.'));
        $this->assertSame(["o:{$a}", "o:{$a}"], $choose->invoke(null, $this->game, $actor, $separate->targetKinds(), $separate->spellEffects()));
        (new \ReflectionMethod(Game::class, 'moveTo'))->invoke($this->game, $this->game->objects[$b], GameObject::GRAVEYARD);
        $this->assertSame(["o:{$a}", '-'], $choose->invoke(null, $this->game, $actor, $single->targetKinds(), $single->spellEffects()));
    }

    public static function actors(): array
    {
        return [[0], [1]];
    }

    /** @dataProvider actors */
    public function testSelectedModesKeepSeparateGroupsAndAllowCrossClauseReuse(int $actor): void
    {
        $a = $this->start($actor);
        $b = $this->game->addCard($actor, self::card('Ornithopter'), GameObject::BATTLEFIELD)->id;
        $this->lands($actor, 'Island', 1);
        $data = $this->definition("Choose one or both —\n• Tap up to two target creatures.\n• Untap up to two target creatures.");
        $card = new CardDefinition($data);
        $this->assertSame([], $card->unsupported);
        $this->assertSame([0, 0, 2, 2], Game::targetGroups($card->spellEffects([0, 1])));
        $source = $this->game->addCard($actor, $data, GameObject::HAND)->id;
        $this->unchanged(fn () => $this->game->cast($actor, $source, 0, ["o:{$a}", "o:{$b}", "o:{$a}", "o:{$a}"], ['modes' => [0, 1]]));
        $this->game->cast($actor, $source, 0, ["o:{$a}", "o:{$b}", "o:{$a}", "o:{$b}"], ['modes' => [0, 1]]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertFalse($this->game->objects[$a]->tapped);
        $this->assertFalse($this->game->objects[$b]->tapped);
    }

    /** @dataProvider actors */
    public function testSupportClauseUsesDistinctTargetsAndPracticeForcedFallbackDoesToo(int $actor): void
    {
        $a = $this->start($actor);
        $b = $this->game->addCard($actor, self::card('Ornithopter'), GameObject::BATTLEFIELD)->id;
        $this->lands($actor, 'Island', 1);
        $data = $this->definition('Support 2.');
        $source = $this->game->addCard($actor, $data, GameObject::HAND)->id;
        $this->assertSame([], $this->game->objects[$source]->definition()->unsupported);
        $this->unchanged(fn () => $this->game->cast($actor, $source, 0, ["o:{$a}", "o:{$a}"]));
        $this->game->cast($actor, $source, 0, ["o:{$a}", "o:{$b}"]);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertSame([1, 1], [$this->game->objects[$a]->counter('+1/+1'), $this->game->objects[$b]->counter('+1/+1')]);
        $harmful = new CardDefinition($this->definition('Tap up to two target creatures.'));
        $choose = new \ReflectionMethod(PracticeBot::class, 'targets');
        $targets = $choose->invoke(null, $this->game, $actor, $harmful->targetKinds(), $harmful->spellEffects(), null, true);
        $this->assertSame(['-', '-'], $targets);
        // Isolate the mandatory/forced policy using the same two-slot clause.
        $effects = $harmful->spellEffects();
        foreach ($effects as &$effect) {
            $effect['target'] = 'creature';
        }
        unset($effect);
        $forced = $choose->invoke(null, $this->game, $actor, ['creature', 'creature'], $effects, null, true);
        $this->assertEqualsCanonicalizing(["o:{$a}", "o:{$b}"], $forced);
    }

    /** @dataProvider actors */
    public function testSeparateActivatedClausesMayTargetSameObjectAndPayCosts(int $actor): void
    {
        $a = $this->start($actor);
        $mana = $this->lands($actor, 'Island', 2);
        $data = $this->definition('{U}, Sacrifice this enchantment: Untap target creature. Untap target creature.', 'Enchantment');
        $source = $this->game->addCard($actor, $data, GameObject::HAND)->id;
        $this->assertSame([], $this->game->objects[$source]->definition()->unsupported);
        $this->game->cast($actor, $source);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->game->objects[$a]->tapped = true;
        $this->game->activate($actor, $source, 0, ["o:{$a}", "o:{$a}"]);
        $this->assertSame(GameObject::GRAVEYARD, $this->zone($source));
        $this->assertTrue($this->game->objects[$mana[0]]->tapped);
        $this->assertTrue($this->game->objects[$mana[1]]->tapped);
        $this->game->pass($this->game->priority);
        $this->game->pass($this->game->priority);
        $this->assertFalse($this->game->objects[$a]->tapped);
    }
}
