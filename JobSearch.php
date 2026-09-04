<?php
declare(strict_types=1);

/*
 * Offsite — remote-role search over Hacker News hiring threads.
 * Copyright (C) 2026  Offsite contributors
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/HnClient.php';
require_once __DIR__ . '/PostingParser.php';
require_once __DIR__ . '/RemoteClassifier.php';

/**
 * Ties the pieces together: fetch a thread, parse each comment, classify its
 * work arrangement, keep the ones matching the keyword and the remote filter.
 */
final class JobSearch
{
    public function __construct(
        private HnClient $client,
        private PostingParser $parser,
        private RemoteClassifier $classifier
    ) {
    }

    /**
     * @param string $keyword       free text; blank matches everything
     * @param array<int,string> $allowStatuses which classifications to keep
     * @return array{results:array<int,array>, scanned:int, thread:?array, counts:array<string,int>}
     */
    public function search(
        string $keyword,
        array $allowStatuses = [RemoteClassifier::REMOTE],
        float $minConfidence = 0.0,
        int $threadIndex = 0,
        int $limit = 60
    ): array {
        $threads = $this->client->recentHiringThreads(4);
        if ($threads === []) {
            return ['results' => [], 'scanned' => 0, 'thread' => null, 'counts' => [], 'threads' => []];
        }

        $thread   = $threads[min($threadIndex, count($threads) - 1)];
        $postings = $this->client->threadPostings($thread['id']);

        $out = $this->searchIn($postings, $keyword, $allowStatuses, $minConfidence, $limit);
        $out['thread']  = $thread;
        $out['threads'] = $threads;
        return $out;
    }

    /**
     * Same pipeline, but over postings you already have. Used by the offline
     * demo mode and by the eval harness.
     *
     * @param array<int,array{id:string,text:string,author:string}> $postings
     */
    public function searchIn(
        array $postings,
        string $keyword,
        array $allowStatuses = [RemoteClassifier::REMOTE],
        float $minConfidence = 0.0,
        int $limit = 60
    ): array {
        $terms   = $this->tokenizeQuery($keyword);
        $results = [];
        $counts  = [
            RemoteClassifier::REMOTE => 0,
            RemoteClassifier::HYBRID => 0,
            RemoteClassifier::ONSITE => 0,
        ];

        foreach ($postings as $p) {
            $parsed = $this->parser->parse($p['text']);
            $verdict = $this->classifier->classify($parsed['body'], $parsed['header']);

            if (isset($counts[$verdict['status']])) {
                $counts[$verdict['status']]++;
            }

            if (!in_array($verdict['status'], $allowStatuses, true)) {
                continue;
            }
            if ($verdict['confidence'] < $minConfidence) {
                continue;
            }

            $score = $this->relevance($terms, $parsed);
            if ($terms !== [] && $score === 0) {
                continue;
            }

            $results[] = [
                'id'      => $p['id'],
                'author'  => $p['author'],
                'url'     => 'https://news.ycombinator.com/item?id=' . $p['id'],
                'parsed'  => $parsed,
                'verdict' => $verdict,
                'score'   => $score,
            ];
        }

        // Best keyword match first; break ties with classifier confidence.
        usort($results, function ($a, $b) {
            return [$b['score'], $b['verdict']['confidence']] <=> [$a['score'], $a['verdict']['confidence']];
        });

        return [
            'results' => array_slice($results, 0, $limit),
            'total'   => count($results),
            'scanned' => count($postings),
            'thread'  => null,
            'threads' => [],
            'counts'  => $counts,
        ];
    }

    /** @return array<int,string> */
    private function tokenizeQuery(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $q, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parts, fn($t) => mb_strlen($t) >= 2));
    }

    /**
     * Weighted match. A keyword in the role title matters more than the same
     * word buried in a paragraph about company culture.
     */
    private function relevance(array $terms, array $parsed): int
    {
        if ($terms === []) {
            return 1;
        }

        $role    = mb_strtolower((string) $parsed['role']);
        $company = mb_strtolower((string) $parsed['company']);
        $tech    = mb_strtolower(implode(' ', $parsed['tech']));
        $body    = mb_strtolower($parsed['body']);

        $score = 0;
        foreach ($terms as $t) {
            $t = preg_quote($t, '/');
            if ($role !== '' && preg_match("/$t/", $role))    { $score += 6; }
            if ($tech !== '' && preg_match("/\b$t\b/", $tech)) { $score += 5; }
            if ($company !== '' && preg_match("/$t/", $company)) { $score += 3; }
            if (preg_match_all("/\b$t\b/", $body, $m))         { $score += min(3, count($m[0])); }
        }
        return $score;
    }
}
