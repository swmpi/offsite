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

/**
 * Thin client for the Hacker News Algolia API.
 *
 * No key, no auth. Two endpoints are all we need:
 *   /search              — find the monthly "Who is hiring?" threads
 *   /items/{id}          — pull an entire thread including all comments
 *
 * Threads run to several hundred comments and change once a month, so every
 * response is cached to disk. Without the cache you re-download ~2 MB on every
 * keystroke, which is both slow and rude to a free API.
 */
final class HnClient
{
    // search_by_date, not search: the plain endpoint orders by relevance,
    // which returned a 2020 thread first and made the whole app serve
    // six-year-old postings. Date order is what "recent" here means.
    private const SEARCH = 'https://hn.algolia.com/api/v1/search_by_date';
    private const ITEM   = 'https://hn.algolia.com/api/v1/items/';

    public function __construct(
        private string $cacheDir,
        private int $ttlSeconds = 21600, // 6 hours
        private int $timeout = 20
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * The most recent hiring threads, newest first.
     *
     * @return array<int,array{id:string,title:string,created_at:string}>
     */
    public function recentHiringThreads(int $count = 3): array
    {
        $url = self::SEARCH . '?' . http_build_query([
            'query'       => 'Ask HN: Who is hiring?',
            'tags'        => 'story,author_whoishiring',
            'hitsPerPage' => max(1, min($count, 12)),
        ]);

        $data = $this->getJson($url, 'threads_' . $count);
        $out  = [];

        foreach ($data['hits'] ?? [] as $hit) {
            // The same account also posts "Who wants to be hired?" and
            // "Freelancer? Seeking freelancer?" — filter to hiring only.
            if (stripos($hit['title'] ?? '', 'who is hiring') === false) {
                continue;
            }
            $out[] = [
                'id'         => (string) $hit['objectID'],
                'title'      => (string) $hit['title'],
                'created_at' => (string) ($hit['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Every top-level comment in a thread. Replies are discussion, not
     * postings, so only depth-1 children are returned.
     *
     * @return array<int,array{id:string,text:string,author:string,created_at:string}>
     */
    public function threadPostings(string $threadId): array
    {
        $data = $this->getJson(self::ITEM . urlencode($threadId), 'thread_' . $threadId);
        $out  = [];

        foreach ($data['children'] ?? [] as $child) {
            $text = $child['text'] ?? null;
            if ($text === null || trim($text) === '') {
                continue; // deleted or flagged
            }
            $out[] = [
                'id'         => (string) ($child['id'] ?? ''),
                'text'       => (string) $text,
                'author'     => (string) ($child['author'] ?? 'unknown'),
                'created_at' => (string) ($child['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    private function getJson(string $url, string $cacheKey): array
    {
        $path = $this->cacheDir . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $cacheKey) . '.json';

        if (is_file($path) && (time() - filemtime($path)) < $this->ttlSeconds) {
            $cached = json_decode((string) file_get_contents($path), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $body = $this->fetch($url);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            // Serve stale rather than fail if we have anything at all.
            if (is_file($path)) {
                $stale = json_decode((string) file_get_contents($path), true);
                if (is_array($stale)) {
                    return $stale;
                }
            }
            throw new RuntimeException('Hacker News returned a response that could not be read.');
        }

        file_put_contents($path, json_encode($data));
        return $data;
    }

    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'offsite/1.0 (student project; contact via GitHub)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new RuntimeException('Could not reach Hacker News: ' . $err);
            }
            if ($code >= 400) {
                throw new RuntimeException("Hacker News returned HTTP $code.");
            }
            return (string) $body;
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => $this->timeout,
            'header'  => "Accept: application/json\r\nUser-Agent: offsite/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Could not reach Hacker News.');
        }
        return $body;
    }
}
