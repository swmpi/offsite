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
 * Pulls structured fields out of a free-text HN hiring comment.
 *
 * There is no schema here. A loose convention exists —
 *   Company | Role | Location | REMOTE | Salary
 * — and maybe two thirds of posters follow some version of it. The rest write
 * paragraphs. So the parser tries the header first and falls back to scanning
 * the body, and every field is nullable because sometimes it genuinely isn't
 * there. Guessing is worse than admitting the field is missing.
 */
final class PostingParser
{
    private const TECH = [
        'TypeScript', 'JavaScript', 'Python', 'Golang', 'Go', 'Rust', 'Java', 'Kotlin',
        'Swift', 'Ruby', 'Rails', 'Django', 'FastAPI', 'Flask', 'React', 'Vue', 'Svelte',
        'Angular', 'Node.js', 'Node', 'Next.js', 'PHP', 'Laravel', 'Elixir', 'Phoenix',
        'Scala', 'Clojure', 'Haskell', 'C++', 'C#', '.NET', 'PostgreSQL', 'Postgres',
        'MySQL', 'MongoDB', 'Redis', 'Kafka', 'AWS', 'GCP', 'Azure', 'Kubernetes',
        'Docker', 'Terraform', 'GraphQL', 'Tailwind', 'PyTorch', 'TensorFlow', 'LLM',
    ];

    private const ROLE_HINTS = [
        'engineer', 'developer', 'designer', 'scientist', 'manager', 'architect',
        'analyst', 'devops', 'sre', 'full-stack', 'fullstack', 'frontend', 'front-end',
        'backend', 'back-end', 'founding', 'staff', 'senior', 'junior', 'principal',
        'intern', 'lead', 'director', 'researcher', 'pm', 'product', 'role',
    ];

    /**
     * @return array{
     *   company:?string, role:?string, location:?string, salary:?string,
     *   tech:array<int,string>, header:string, body:string, apply_url:?string
     * }
     */
    public function parse(string $rawHtml): array
    {
        $text   = $this->toText($rawHtml);
        $lines  = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        $header = $lines[0] ?? '';

        $fields = array_values(array_filter(array_map('trim', explode('|', $header)), fn($f) => $f !== ''));
        $hasPipes = count($fields) >= 2;

        $role = $this->findRole($fields, $text);

        return [
            'company'   => $hasPipes ? $this->cleanCompany($fields[0]) : $this->guessCompany($lines),
            'role'      => $role,
            'location'  => $this->findLocation($fields, $role),
            'salary'    => $this->findSalary($text),
            'tech'      => $this->findTech($text),
            'apply_url' => $this->findUrl($rawHtml),
            'header'    => $header,
            'body'      => $text,
        ];
    }

    private function cleanCompany(string $s): ?string
    {
        $s = trim(preg_replace('/\s*\(.*?\)\s*$/', '', $s));
        $s = trim($s, " \t-–—:•");
        // Strip a leading URL if the poster led with one.
        $s = preg_replace('#^https?://\S+\s*#i', '', $s);
        if ($s === '' || mb_strlen($s) > 60) {
            return null;
        }
        return $s;
    }

    private function guessCompany(array $lines): ?string
    {
        $first = $lines[0] ?? '';
        // Common shapes: "Acme Corp - Senior Engineer" or "Acme Corp (YC S21)"
        if (preg_match('/^([A-Z][\w&.\' ]{1,40}?)\s*(?:[-–—:]|\(|,)/', $first, $m)) {
            return trim($m[1]);
        }
        $words = preg_split('/\s+/', $first);
        $guess = implode(' ', array_slice($words, 0, 3));
        return $guess !== '' ? trim($guess, " \t-–—:") : null;
    }

    private function findRole(array $fields, string $text): ?string
    {
        // Prefer a header field that reads like a job title.
        foreach (array_slice($fields, 1) as $f) {
            $low = mb_strtolower($f);
            foreach (self::ROLE_HINTS as $hint) {
                if (str_contains($low, $hint) && mb_strlen($f) <= 70) {
                    return trim($f, " \t-–—:");
                }
            }
        }
        // Otherwise scan the body for a title-shaped phrase.
        if (preg_match('/\b((?:Senior|Staff|Principal|Lead|Junior|Founding)?\s*(?:Software|Backend|Frontend|Full[\s\-]?Stack|Data|ML|Platform|Security|Mobile)?\s*(?:Engineer|Developer|Scientist|Designer|Manager))\b/i', $text, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        return null;
    }

    private function findLocation(array $fields, ?string $role = null): ?string
    {
        $skip = '/^(remote|onsite|on-site|hybrid|full[\s\-]?time|part[\s\-]?time|contract|visa|no visa|intern(ship)?)/i';

        foreach (array_slice($fields, 1) as $f) {
            if (preg_match($skip, $f)) {
                continue;
            }
            // A job title is capitalised the same way a city is, so pattern
            // matching alone can't tell "Quant Developer" from "Quebec City".
            // Exclude the field already claimed as the role, and anything that
            // reads like a title.
            if ($role !== null && strcasecmp(trim($f), $role) === 0) {
                continue;
            }
            $low = mb_strtolower($f);
            foreach (self::ROLE_HINTS as $hint) {
                if (str_contains($low, $hint)) {
                    continue 2;
                }
            }
            // City-ish: a capitalised word, optionally "City, ST" or "City, Country"
            if (preg_match('/^[A-Z][A-Za-z\.\- ]{2,28}(?:,\s*[A-Z][A-Za-z\.\- ]{1,24})?$/', trim($f))) {
                return trim($f);
            }
        }
        return null;
    }

    private function findSalary(string $text): ?string
    {
        $patterns = [
            // $120k - $160k  |  $120,000-$160,000  |  £70k–£90k  |  €60k to €80k
            '/[\$£€]\s?\d{2,3}(?:,\d{3})?\s?k?\s?(?:-|–|—|to)\s?[\$£€]?\s?\d{2,3}(?:,\d{3})?\s?k\b/i',
            '/[\$£€]\s?\d{2,3},\d{3}\s?(?:-|–|—|to)\s?[\$£€]?\s?\d{2,3},\d{3}/',
            '/[\$£€]\s?\d{2,3}\s?k\s?(?:-|–|—|to)\s?[\$£€]?\s?\d{2,3}\s?k/i',
            // hourly
            '/[\$£€]\s?\d{2,3}(?:\.\d{2})?\s?(?:\/|per\s+)h(?:r|our)\b/i',
            // single figure with a currency and a k
            '/[\$£€]\s?\d{2,3}\s?k\b(?!\s?(?:-|–|to))/i',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m)) {
                return preg_replace('/\s+/', ' ', trim($m[0]));
            }
        }
        return null;
    }

    /** @return array<int,string> */
    private function findTech(string $text): array
    {
        $found = [];
        foreach (self::TECH as $t) {
            $re = '/(?<![\w#+.])' . preg_quote($t, '/') . '(?![\w#+])/i';
            if (preg_match($re, $text)) {
                $found[$t] = true;
            }
        }
        // "Go" is a common English word; only keep it with corroboration.
        if (isset($found['Go']) && !preg_match('/\b(?:golang|go\s+(?:developer|engineer)|written\s+in\s+go|go,)/i', $text)) {
            unset($found['Go']);
        }
        if (isset($found['Golang'])) {
            unset($found['Go']);
        }
        if (isset($found['Node.js'])) {
            unset($found['Node']);
        }
        if (isset($found['Postgres']) && isset($found['PostgreSQL'])) {
            unset($found['Postgres']);
        }
        return array_slice(array_keys($found), 0, 10);
    }

    private function findUrl(string $html): ?string
    {
        if (preg_match('/href="([^"]+)"/i', $html, $m)) {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (filter_var($url, FILTER_VALIDATE_URL) && $this->isWebUrl($url)) {
                return $url;
            }
        }
        return null;
    }

    /**
     * The posting is a stranger's HTML, and this URL ends up in an href.
     * FILTER_VALIDATE_URL is not a safety check — it accepts, among others,
     * "javascript://%0aalert(1)", where the // opens a JS comment that the
     * newline closes. Escaping does not help in an href, so the scheme itself
     * has to be on an allowlist.
     */
    private function isWebUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function toText(string $html): string
    {
        $t = preg_replace('/<p>/i', "\n", $html);
        $t = preg_replace('/<br\s*\/?>/i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\n{3,}/', "\n\n", $t);
        return trim($t);
    }
}
