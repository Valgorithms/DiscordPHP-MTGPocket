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

namespace MTGPocket\Exports;

use MTGPocket\Cards\BasicLands;
use MTGPocket\Models\CardCounts;
use MTGPocket\Models\Deck;

/**
 * Decks and collections as files other programs open:
 *
 * - `arena`: MTG Arena's import text (`Commander`, `Deck` and `Sideboard`
 *   sections of `N Name (SET) number`), which Moxfield and Archidekt also read.
 * - `frogtown`: a plain decklist (`N Name`, a blank line, then the
 *   sideboard), the MTGO text that Frogtown, Cockatrice and Forge import.
 * - `tts`: a Tabletop Simulator saved object, a deck of cards whose faces
 *   are Scryfall images, one pile each for the main deck, side deck and
 *   commander.
 *
 * @since 0.6.0
 */
final class Exporter
{
    /**
     * Format => what it is for.
     *
     * @var array<string, string>
     */
    public const array FORMATS = [
        'arena' => 'MTG Arena',
        'tts' => 'Tabletop Simulator',
        'frogtown' => 'Frogtown',
    ];

    /**
     * Scryfall's image of the usual Magic card back.
     */
    public const string CARD_BACK = 'https://backs.scryfall.io/large/0/a/0aeebaf5-8c7d-4636-9e82-8c27447861f7.jpg';

    /**
     * Layouts whose second face is printed on the back of the card.
     *
     * @var string[]
     */
    private const array DOUBLE_FACED = ['transform', 'modal_dfc', 'reversible_card', 'double_faced_token', 'meld'];

    /**
     * Layouts whose two halves are both on the front, so Arena wants the
     * whole `A // B` name.
     *
     * @var string[]
     */
    private const array SPLIT = ['split', 'aftermath'];

    /**
     * @param \Closure(string): array $card Card data by deck or collection key.
     */
    public function __construct(private readonly \Closure $card)
    {
    }

    /**
     * A deck as a file.
     *
     * @param Deck   $deck
     * @param string $format One of {@see FORMATS}.
     *
     * @throws \InvalidArgumentException For an unknown format.
     *
     * @return array{0: string, 1: string} File name and contents.
     */
    public function deck(Deck $deck, string $format): array
    {
        $commander = $deck->commander === null ? [] : [$deck->commander => 1];
        $main = $deck->main->toArray();
        $side = $deck->side->toArray();

        return [self::fileName($deck->name, $format), match (self::check($format)) {
            'arena' => self::sections([
                'Commander' => $this->lines($commander, true),
                'Deck' => $this->lines($main, true),
                'Sideboard' => $this->lines($side, true),
            ]),
            'frogtown' => self::plain($this->lines($commander + $main, false), $this->lines($side, false)),
            'tts' => $this->tabletop([
                [$deck->name, $main, false],
                ["{$deck->name} (side deck)", $side, false],
                ["{$deck->name} (commander)", $commander, true],
            ]),
        }];
    }

    /**
     * Cards a player owns as a file.
     *
     * @param CardCounts $cards  In the order they should be listed.
     * @param string     $title  Names the file and, for Tabletop Simulator, the deck.
     * @param string     $format One of {@see FORMATS}.
     *
     * @throws \InvalidArgumentException For an unknown format.
     *
     * @return array{0: string, 1: string} File name and contents.
     */
    public function collection(CardCounts $cards, string $title, string $format): array
    {
        $cards = $cards->toArray();

        return [self::fileName($title, $format), match (self::check($format)) {
            'arena' => self::sections(['' => $this->lines($cards, true)]),
            'frogtown' => self::plain($this->lines($cards, false), []),
            'tts' => $this->tabletop([[$title, $cards, false]]),
        }];
    }

    /**
     * Throws for an unknown format.
     *
     * @param string $format
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    public static function check(string $format): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException("Cards cannot be exported as **{$format}**. Pick ".implode(', ', self::FORMATS).'.');
        }

        return $format;
    }

    /**
     * A file name: the title with only safe characters, and the format's extension.
     *
     * @param string $title
     * @param string $format
     *
     * @return string
     */
    public static function fileName(string $title, string $format): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $title), '-') ?: 'cards';

        return $base.($format === 'tts' ? '.json' : ($format === 'arena' ? '-arena.txt' : '.txt'));
    }

    /**
     * One line per card: `N Name`, with ` (SET) number` for Arena.
     *
     * @param array<string, int> $cards
     * @param bool               $arena
     *
     * @return string[]
     */
    private function lines(array $cards, bool $arena): array
    {
        $lines = [];
        foreach ($cards as $key => $count) {
            $data = ($this->card)((string) $key);
            $line = "{$count} ".($arena ? self::arenaName($data) : $data['name']);
            if ($arena && isset($data['setCode']) && ! BasicLands::isBasic((string) $key)) {
                $line .= " ({$data['setCode']}) ".($data['number'] ?? '');
            }
            $lines[] = trim($line);
        }

        return $lines;
    }

    /**
     * The name Arena knows a card by: only the front face of a
     * double-faced or adventure card, both halves of a split card.
     *
     * @param array $card
     *
     * @return string
     */
    private static function arenaName(array $card): string
    {
        $name = (string) $card['name'];

        return in_array($card['layout'] ?? '', self::SPLIT, true) ? $name : explode(' // ', $name)[0];
    }

    /**
     * Arena text: each non-empty section under its heading, blank lines between.
     *
     * @param array<string, string[]> $sections Heading ('' for none) => lines.
     *
     * @return string
     */
    private static function sections(array $sections): string
    {
        $blocks = [];
        foreach ($sections as $heading => $lines) {
            if ($lines !== []) {
                $blocks[] = ($heading === '' ? '' : "{$heading}\n").implode("\n", $lines);
            }
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * MTGO text: the main deck, then a blank line and the sideboard.
     *
     * @param string[] $main
     * @param string[] $side
     *
     * @return string
     */
    private static function plain(array $main, array $side): string
    {
        return implode("\n", $main).($side === [] ? '' : "\n\n".implode("\n", $side))."\n";
    }

    /**
     * A Tabletop Simulator saved object: one pile of cards per non-empty
     * group, side by side. Every printing is its own one-card custom deck,
     * so each copy shows its Scryfall image.
     *
     * @param array<array{0: string, 1: array<string, int>, 2: bool}> $piles Name, cards, face up.
     *
     * @return string JSON.
     */
    private function tabletop(array $piles): string
    {
        $objects = [];
        $nextId = 1;
        $x = 0.0;
        foreach ($piles as [$name, $cards, $faceUp]) {
            if ($cards === []) {
                continue;
            }

            $transform = self::transform($x, $faceUp);
            $x += 3.0;
            $contained = $deckIds = $customDeck = [];
            foreach ($cards as $key => $count) {
                $card = $this->tabletopCard((string) $key, $nextId, $transform);
                $customDeck += $card['CustomDeck'];
                for ($n = 0; $n < $count; $n++) {
                    $contained[] = $card;
                    $deckIds[] = $card['CardID'];
                }
            }

            if (count($contained) === 1) {
                $objects[] = $contained[0];
                continue;
            }

            $objects[] = [
                'Name' => 'DeckCustom',
                'Nickname' => $name,
                'Transform' => $transform,
                'DeckIDs' => $deckIds,
                'CustomDeck' => $customDeck,
                'ContainedObjects' => $contained,
            ];
        }

        return json_encode(['ObjectStates' => $objects], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * A Tabletop Simulator card. A double-faced card gets a second state
     * showing its back face.
     *
     * @param string $key
     * @param int    $nextId    The next free custom deck number; moved past the ones used.
     * @param array  $transform
     *
     * @return array
     */
    private function tabletopCard(string $key, int &$nextId, array $transform): array
    {
        $data = ($this->card)($key);
        $faces = explode(' // ', (string) $data['name']);
        $doubleFaced = in_array($data['layout'] ?? '', self::DOUBLE_FACED, true) && isset($data['scryfallId']);

        $card = self::tabletopFace($faces[0], (string) ($data['type'] ?? ''), self::image($data, 'front'), $transform, $nextId++);
        if ($doubleFaced) {
            $card['States'] = ['2' => self::tabletopFace($faces[1] ?? $faces[0], (string) ($data['type'] ?? ''), self::image($data, 'back'), $transform, $nextId++)];
        } elseif (count($faces) > 1) {
            $card['Nickname'] = (string) $data['name'];
        }

        return $card;
    }

    /**
     * One face of a Tabletop Simulator card.
     *
     * @param string $name
     * @param string $type
     * @param string $image
     * @param array  $transform
     * @param int    $deckNumber
     *
     * @return array
     */
    private static function tabletopFace(string $name, string $type, string $image, array $transform, int $deckNumber): array
    {
        return [
            'Name' => 'Card',
            'Nickname' => $name,
            'Description' => $type,
            'Transform' => $transform,
            'CardID' => $deckNumber * 100,
            'CustomDeck' => [(string) $deckNumber => [
                'FaceURL' => $image,
                'BackURL' => self::CARD_BACK,
                'NumWidth' => 1,
                'NumHeight' => 1,
                'BackIsHidden' => true,
                'UniqueBack' => false,
                'Type' => 0,
            ]],
        ];
    }

    /**
     * A card's large Scryfall image. Basic lands, which have no printing of
     * their own, and cards no pool knows any more are looked up by name.
     *
     * @param array  $card
     * @param string $face `front` or `back`.
     *
     * @return string
     */
    private static function image(array $card, string $face): string
    {
        $id = $card['scryfallId'] ?? null;
        if (is_string($id) && strlen($id) >= 2) {
            return "https://cards.scryfall.io/large/{$face}/{$id[0]}/{$id[1]}/{$id}.jpg";
        }

        return 'https://api.scryfall.com/cards/named?format=image&version=large&exact='.rawurlencode(explode(' // ', (string) $card['name'])[0]);
    }

    /**
     * Where a pile lies on the table; face down unless `$faceUp`.
     *
     * @param float $x
     * @param bool  $faceUp
     *
     * @return array
     */
    private static function transform(float $x, bool $faceUp): array
    {
        return [
            'posX' => $x, 'posY' => 1.0, 'posZ' => 0.0,
            'rotX' => 0.0, 'rotY' => 180.0, 'rotZ' => $faceUp ? 0.0 : 180.0,
            'scaleX' => 1.0, 'scaleY' => 1.0, 'scaleZ' => 1.0,
        ];
    }
}
