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

namespace MTGPocket\Cards;

/**
 * Offline evidence inventory. Recognition and scoped regression evidence never
 * imply a whole-card certificate. This class does not run the parser or games.
 */
final class FunctionalCoverageLedger
{
    /** Newest printing per name, matching the pinned recognition audit. */
    public static function select(iterable $pools): array
    {
        $selected = [];
        $ranks = [];
        $versions = [];
        $sets = [];
        foreach ($pools as $pool) {
            if (! is_string($pool['setCode'] ?? null) || ! is_array($pool['cards'] ?? null)) {
                throw new \InvalidArgumentException('Each pool needs a setCode and cards array.');
            }
            if (isset($sets[$pool['setCode']])) {
                throw new \InvalidArgumentException('Duplicate set code.');
            }
            $sets[$pool['setCode']] = true;
            $rank = [$pool['releaseDate'] ?? '', $pool['setCode']];
            $versions[(string) ($pool['mtgjsonVersion'] ?? 'unknown')] = true;
            foreach ($pool['cards'] as $card) {
                if (! is_string($card['name'] ?? null) || $card['name'] === '') {
                    throw new \InvalidArgumentException('Each card needs a nonempty name.');
                }
                if (! isset($ranks[$card['name']]) || $rank > $ranks[$card['name']]) {
                    $selected[$card['name']] = ['setCode' => $pool['setCode'], 'card' => $card];
                    $ranks[$card['name']] = $rank;
                }
            }
        }
        ksort($selected, SORT_STRING);
        ksort($versions, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($selected as $entry) {
            hash_update($hash, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        return [
            'corpus' => ['pools' => count($sets), 'mtgjsonVersions' => array_keys($versions), 'selectedCardsSha256' => hash_final($hash)],
            'cards' => $selected,
        ];
    }

    /**
     * Reject drift before reusing saved recognition or scoped evidence.
     * Source hashes normalize CRLF so Windows and Linux use the same pins.
     * The caller supplies file bytes; this method performs no I/O.
     */
    public static function verifyInputs(string $recognitionJson, array $manifest, array $sourceBytes): array
    {
        if (($manifest['schemaVersion'] ?? null) !== 1
            || ! hash_equals($manifest['recognitionReportSha256'] ?? '', hash('sha256', $recognitionJson))
            || empty($manifest['sourcePins'])) {
            throw new \InvalidArgumentException('Unpinned or changed recognition report.');
        }
        foreach ($manifest['sourcePins'] as $path => $expected) {
            if (! isset($sourceBytes[$path]) || ! hash_equals($expected, hash('sha256', str_replace("\r\n", "\n", $sourceBytes[$path])))) {
                throw new \InvalidArgumentException('Changed or missing source: '.$path);
            }
        }

        return json_decode($recognitionJson, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Produces a per-name inventory and overlapping, explicitly labelled counts.
     * Schema 1 records partial evidence only, never issues whole-card certificates.
     */
    public static function report(array $selection, array $recognition, array $manifest): array
    {
        if (($manifest['schemaVersion'] ?? null) !== 1 || ! empty($manifest['wholeCardCertificates'])
            || ($recognition['schemaVersion'] ?? null) !== 1
            || $selection['corpus'] !== ($recognition['corpus'] ?? null)
            || $selection['corpus'] !== ($manifest['corpus'] ?? null)) {
            throw new \InvalidArgumentException('Corpus does not match the pinned report and manifest.');
        }
        $cards = $selection['cards'];
        $recognized = [];
        foreach ($recognition['recognizedNames'] as $name) {
            if (! isset($cards[$name]) || isset($recognized[$name])) {
                throw new \InvalidArgumentException('Unknown or duplicate recognized name.');
            }
            $recognized[$name] = true;
        }
        if (count($cards) !== $recognition['recognition']['total']
            || count($recognized) !== $recognition['recognition']['whole']
            || count($cards) - count($recognized) !== $recognition['recognition']['withUnreadText']) {
            throw new \InvalidArgumentException('Recognition counts do not partition the corpus.');
        }
        $rows = [];
        foreach ($cards as $name => $entry) {
            $rows[$name] = [
                'recognition' => isset($recognized[$name]) ? 'recognized_whole' : 'unread_text',
                'scopedEvidence' => [], 'explicitUnsupported' => [],
                'wholeCardCertificate' => null,
            ];
        }
        $ids = [];
        foreach (['scopedEvidence', 'explicitUnsupported'] as $category) {
            foreach ($manifest[$category] as $claim) {
                if (empty($claim['id']) || isset($ids[$claim['id']]) || empty($claim['scope']) || empty($claim['cards'])) {
                    throw new \InvalidArgumentException('Claims need unique IDs, scope and card names.');
                }
                $ids[$claim['id']] = true;
                if ($category === 'scopedEvidence') {
                    if (empty($claim['tests']) || empty($claim['limits'])) {
                        throw new \InvalidArgumentException('Partial evidence needs tests and explicit limits.');
                    }
                    foreach ($claim['tests'] as $test) {
                        [$file, $method] = explode('::', $test, 2) + [null, null];
                        if (! isset($manifest['sourcePins'][$file]) || ! is_string($method) || $method === '') {
                            throw new \InvalidArgumentException('Evidence tests must reference pinned sources.');
                        }
                    }
                }
                foreach ($claim['cards'] as $name) {
                    if (! isset($rows[$name]) || in_array($claim['id'], $rows[$name][$category], true)) {
                        throw new \InvalidArgumentException('Unknown or duplicate claim card: '.$name);
                    }
                    $rows[$name][$category][] = $claim['id'];
                }
            }
        }
        $withEvidence = $withGap = $overlap = 0;
        foreach ($rows as $row) {
            $withEvidence += (int) ($row['scopedEvidence'] !== []);
            $withGap += (int) ($row['explicitUnsupported'] !== []);
            $overlap += (int) ($row['scopedEvidence'] !== [] && $row['explicitUnsupported'] !== []);
        }
        if (empty($manifest['nextFamily']['cards']) || empty($manifest['nextFamily']['acceptance'])
            || count(array_unique($manifest['nextFamily']['cards'])) !== ($manifest['nextFamily']['measuredCandidateCount'] ?? null)) {
            throw new \InvalidArgumentException('Next family needs corpus names and measurable acceptance.');
        }
        foreach ($manifest['nextFamily']['cards'] as $name) {
            if (! isset($rows[$name])) {
                throw new \InvalidArgumentException('Next family card is outside the corpus.');
            }
        }

        return [
            'schemaVersion' => 1,
            'corpus' => $selection['corpus'],
            'provenance' => array_intersect_key($manifest, array_flip(['recognitionReportSha256', 'originalReportSha256', 'recognitionSource', 'engineSource', 'sourcePins', 'validation'])),
            'recognition' => $recognition['recognition'],
            'assessment' => [
                'recognizedWithoutWholeCardCertificate' => count($recognized),
                'cardsWithScopedEvidence' => $withEvidence,
                'cardsWithExplicitUnsupportedGap' => $withGap,
                'cardsWithBothEvidenceAndGap' => $overlap,
                'wholeCardCertificates' => 0,
                'remainingWholeCardReviews' => count($cards),
                'targetWholeCardReviews' => count($cards),
                'meaning' => 'Zero recorded whole-card certificates, not zero functioning cards. Partial tests never certify a whole card. No functional percentage is inferred.',
            ],
            'scopedEvidence' => $manifest['scopedEvidence'],
            'explicitUnsupported' => $manifest['explicitUnsupported'],
            'nextFamily' => $manifest['nextFamily'],
            'cards' => $rows,
        ];
    }
}
