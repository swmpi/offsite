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
 * Classifies a job posting as remote / hybrid / onsite.
 *
 * Design notes:
 *  - Three-way, not boolean. "Hybrid" is the single biggest source of false
 *    positives in naive keyword matching, so it gets its own class.
 *  - Every decision carries the evidence that produced it. If the classifier
 *    is wrong you can see exactly which phrase fooled it, which is what makes
 *    the eval harness actionable.
 *  - Rules are tiered. The first tier that fires decides the class; lower
 *    tiers still contribute evidence and adjust confidence.
 */
final class RemoteClassifier
{
    public const REMOTE  = 'remote';
    public const HYBRID  = 'hybrid';
    public const ONSITE  = 'onsite';
    public const UNKNOWN = 'unknown';

    /**
     * Phrases where "remote" is a technical term, not a work arrangement.
     * HN postings are written by engineers, so a security company advertising
     * work on "remote code execution" is a real false positive, not a
     * hypothetical one. These get masked before any matching happens.
     */
    private const TECH_CONTEXT = [
        'remote code execution',
        'remote procedure call',
        'remote sensing',
        'remote desktop',
        'remote control',
        'remote server',
        'remote host',
        'remote repository',
        'remote branch',
        'remote attestation',
        'remote debugging',
        'remote shell',
        'remote access trojan',
        'remote telemetry',
        'remote patient monitoring',
    ];

    /** Tier 1: explicit denial. Beats everything else. */
    private const RE_NEGATION = [
        'no_remote'        => '/\b(?:no|not|never|zero)\s+(?:fully\s+|100%\s+|any\s+)?remote\b(?!\s*(?:work\s+)?(?:option|possibility)\s+(?:is\s+)?available\s+for\s+some)/i',
        'remote_field_no'  => '/\bremote\s*[:\-]\s*(?:no|none|nope|n\/a)\b/i',
        'not_a_remote'     => '/\bnot\s+(?:a\s+|an\s+)?remote\s+(?:position|role|job|opportunity)\b/i',
        'onsite_only'      => '/\b(?:on[\s\-]?site|in[\s\-]?office|in[\s\-]person)\s+only\b/i',
        'onsite_field'     => '/\|\s*onsite\s*(?:\||$)/i',
        'local_only'       => '/\blocal(?:ly\s+based)?\s+candidates?\s+only\b/i',
        'must_relocate'    => '/\bmust\s+(?:be\s+(?:able\s+|willing\s+)?to\s+)?relocate\b/i',
        'must_be_office'   => '/\bmust\s+(?:be\s+able\s+to\s+)?work\s+(?:from|in|out\s+of)\s+(?:our\s+|the\s+)?office\b/i',
        'no_wfh'           => '/\bno\s+(?:wfh|work[\s\-]from[\s\-]home)\b/i',
        'five_days_office' => '/\b(?:5|five)\s*days?\s*(?:a|per|\/)\s*week\s+(?:in\s+(?:the\s+)?office|on[\s\-]?site)\b/i',
        'not_looking'      => '/\bnot\s+(?:currently\s+)?(?:looking|hiring|open)\s+for\s+remote\b/i',
        'not_offering'     => '/\bnot\s+(?:offering|considering|able\s+to\s+offer)\s+remote\b/i',
    ];

    /** Tier 2: hybrid markers. Beat remote markers — "remote 2 days a week" is hybrid. */
    private const RE_HYBRID = [
        'hybrid_word'      => '/\bhybrid\b/i',
        'n_days_office'    => '/\b(?:one|two|three|four|[1-4])\s*(?:\+\s*)?days?\s*(?:a|per|\/)?\s*(?:week\s*)?(?:in\s+(?:the\s+)?office|on[\s\-]?site|in[\s\-]?office|at\s+(?:the\s+|our\s+)?office)\b/i',
        'office_n_days'    => '/\b(?:in\s+(?:the\s+)?office|on[\s\-]?site)\s+(?:one|two|three|four|[1-4])\s*(?:\+\s*)?days?\b/i',
        'remote_friendly'  => '/\bremote[\s\-]friendly\b/i',
        'partially_remote' => '/\b(?:partial(?:ly)?|partly|semi[\s\-]?)\s*remote\b/i',
        'mostly_remote'    => '/\bmostly\s+remote\b/i',
        'flexible_office'  => '/\bremote\s+(?:with|but)\s+[^.;|]{0,40}(?:office|on[\s\-]?site|in\s+person)\b/i',
        'office_first'     => '/\boffice[\s\-]first\b/i',
        'occasional'       => '/\b(?:occasional|periodic|quarterly|monthly)\s+(?:office|on[\s\-]?site)\s+(?:visits?|days?|attendance)\b/i',
    ];

    /** Tier 3: unambiguous remote. */
    private const RE_REMOTE_STRONG = [
        'fully_remote'    => '/\b(?:100%|fully|entirely|completely|all)[\s\-]remote\b/i',
        'remote_only'     => '/\bremote[\s\-](?:only|first)\b/i',
        'all_remote'      => '/\b(?:fully\s+)?distributed\s+(?:team|company|workforce)\b/i',
        'distributed_geo' => '/\bdistributed\s+(?:across|throughout|over)\s+[^.;|]{3,40}/i',
        'no_hq'           => '/\b(?:no|without\s+(?:a\s+)?)(?:head\s?quarters|hq|central\s+office|physical\s+office)\b/i',
        'wfa'             => '/\bwork\s+from\s+anywhere\b/i',
        'remote_field_yes'=> '/\bremote\s*[:\-]\s*(?:yes|yep|true|100%|full)\b/i',
        'anywhere'        => '/\banywhere\s+in\s+the\s+world\b/i',
        'globally_remote' => '/\b(?:globally|global(?:ly)?)\s+remote\b/i',
        // Deliberately no rule for the "| REMOTE |" pipe field here. The tier-0
        // header check already reads it, and having a body rule match the same
        // header text made header evidence masquerade as independent body
        // evidence, which blocked legitimate hybrid downgrades.
    ];

    /** Tier 4: weak signal. A bare mention, which could be anything. */
    private const RE_REMOTE_WEAK = [
        'bare_remote' => '/\bremote\b/i',
        'wfh'         => '/\b(?:wfh|work[\s\-]from[\s\-]home)\b/i',
        'telecommute' => '/\btele(?:commut|work)(?:e|ing|er)?\b/i',
    ];

    /** Geographic constraints. These annotate, they don't reclassify. */
    private const RE_GEO = [
        'remote_paren'  => '/\bremote\s*\(([^)]{1,45})\)/i',
        'region_only'   => '/\b(US|USA|U\.S\.A?\.?|EU|UK|EMEA|APAC|LATAM|Canada|Europe|North\s+America|Australia|India|Germany|Poland|Brazil)[\s\-]?(?:based\s+)?only\b/i',
        'must_be_in'    => '/\b(?:must\s+be\s+|candidates?\s+must\s+be\s+)?(?:located|based|residing|reside)\s+(?:in|within)\s+(?:the\s+)?([A-Z][A-Za-z\.\s]{1,28}?)(?=[,.;|]|\s+(?:and|or|to|for|with)\b|$)/',
        'timezone_band' => '/\bwithin\s+(?:\+\/?-?\s*)?(\d{1,2})\s*(?:hours?|hrs?)\s+of\s+([A-Za-z]{2,5}(?:[+-]\d{1,2})?)/i',
        'timezone_word' => '/\b((?:CET|CEST|GMT|UTC|EST|EDT|PST|PDT|CST|IST)(?:\s*[+-]\s*\d{1,2})?)\s*(?:\+\/?-?\s*\d\s*)?(?:timezones?|time\s?zones?)?\b/',
        'tz_overlap'    => '/\b(?:overlap|overlapping)\s+(?:with\s+)?[^.;|]{0,30}(?:timezone|time\s?zone|hours)\b/i',
        'visa_country'  => '/\b(?:work\s+authorization|authorized\s+to\s+work)\s+in\s+(?:the\s+)?([A-Za-z\.\s]{2,25}?)(?=[,.;|]|$)/i',
    ];

    /**
     * @return array{
     *   status:string, confidence:float, evidence:array<int,array{rule:string,phrase:string,tier:int}>,
     *   geo:array<int,string>, conflicts:bool
     * }
     */
    public function classify(string $rawText, ?string $headerLine = null): array
    {
        $text   = $this->normalize($rawText);
        $masked = $this->maskTechnicalContext($text);

        $evidence  = [];
        $status    = self::UNKNOWN;
        $confidence = 0.0;

        // Tier 0 — the HN pipe header is a semi-structured field and much more
        // reliable than prose. If it gives a clean answer, trust it.
        if ($headerLine !== null) {
            $fromHeader = $this->classifyHeader($headerLine);
            if ($fromHeader !== null) {
                $evidence[] = $fromHeader['evidence'];
                $status     = $fromHeader['status'];
                $confidence = $fromHeader['confidence'];
            }
        }

        $neg    = $this->matchSet($masked, self::RE_NEGATION, 1);
        $hyb    = $this->matchSet($masked, self::RE_HYBRID, 2);
        $strong = $this->matchSet($masked, self::RE_REMOTE_STRONG, 3);
        $weak   = $this->matchSet($masked, self::RE_REMOTE_WEAK, 4);

        $evidence = array_merge($evidence, $neg, $hyb, $strong, $weak);

        // Body rules can override a header only when the header was silent.
        if ($status === self::UNKNOWN) {
            if ($neg !== []) {
                $status = self::ONSITE;
                $confidence = 0.90;
            } elseif ($hyb !== []) {
                $status = self::HYBRID;
                $confidence = 0.80;
            } elseif ($strong !== []) {
                $status = self::REMOTE;
                $confidence = 0.88;
            } elseif ($weak !== []) {
                $status = self::REMOTE;
                $confidence = 0.45;
            } else {
                $status = self::ONSITE;
                $confidence = 0.35; // absence of any remote language, weakly onsite
            }
        } else {
            // Header spoke, but body contradiction should still cost confidence
            // and, for negation specifically, win outright — "REMOTE" in the
            // header followed by "this role is not remote" is a real pattern.
            if ($neg !== [] && $status !== self::ONSITE) {
                $status = self::ONSITE;
                $confidence = 0.75;
            } elseif ($hyb !== [] && $strong === [] && $status === self::REMOTE) {
                // A hybrid mention only overrules a remote header when the body
                // never states remote unambiguously. "Hybrid or Remote — fully
                // remote is fine" offers both, and remote is the one that matters
                // to someone filtering for it.
                $status = self::HYBRID;
                $confidence = 0.70;
            }
        }

        $conflicts = $this->hasConflict($neg, $hyb, $strong, $weak);
        if ($conflicts) {
            $confidence = max(0.30, $confidence - 0.15);
        }

        return [
            'status'     => $status,
            'confidence' => round($confidence, 2),
            'evidence'   => $evidence,
            'geo'        => $this->extractGeo($text),
            'conflicts'  => $conflicts,
        ];
    }

    /** The `Company | Role | Location | REMOTE | ...` convention. */
    private function classifyHeader(string $header): ?array
    {
        $fields = array_map('trim', explode('|', $header));

        foreach ($fields as $field) {
            $f = strtolower(trim($field, " \t.,;"));

            if ($f === 'remote' || $f === 'fully remote' || $f === '100% remote' || $f === 'remote ok') {
                return [
                    'status' => self::REMOTE,
                    'confidence' => 0.95,
                    'evidence' => ['rule' => 'header_field_remote', 'phrase' => trim($field), 'tier' => 0],
                ];
            }
            if ($f === 'onsite' || $f === 'on-site' || $f === 'on site' || $f === 'no remote') {
                return [
                    'status' => self::ONSITE,
                    'confidence' => 0.95,
                    'evidence' => ['rule' => 'header_field_onsite', 'phrase' => trim($field), 'tier' => 0],
                ];
            }
            // "SF or Remote", "Hybrid or Remote", "NYC / Remote" — an option is
            // still an option. Remote is on the table, so the seeker should see it.
            if (preg_match('/(?:\bor\b|\/)\s*(?:fully\s+|100%\s+)?remote\b/i', $f)
                || preg_match('/\bremote\s*(?:\bor\b|\/)/i', $f)) {
                return [
                    'status' => self::REMOTE,
                    'confidence' => 0.85,
                    'evidence' => ['rule' => 'header_field_remote_option', 'phrase' => trim($field), 'tier' => 0],
                ];
            }
            if ($f === 'hybrid' || str_starts_with($f, 'hybrid ')) {
                return [
                    'status' => self::HYBRID,
                    'confidence' => 0.92,
                    'evidence' => ['rule' => 'header_field_hybrid', 'phrase' => trim($field), 'tier' => 0],
                ];
            }
            // "REMOTE (US)" / "REMOTE (EU timezones)"
            if (preg_match('/^remote\s*\(([^)]+)\)$/i', $f, $m)) {
                return [
                    'status' => self::REMOTE,
                    'confidence' => 0.93,
                    'evidence' => ['rule' => 'header_field_remote_geo', 'phrase' => trim($field), 'tier' => 0],
                ];
            }
        }

        return null;
    }

    /** @return array<int,array{rule:string,phrase:string,tier:int}> */
    private function matchSet(string $text, array $patterns, int $tier): array
    {
        $out = [];
        foreach ($patterns as $rule => $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $out[] = [
                    'rule'   => $rule,
                    'phrase' => trim($m[0][0]),
                    'tier'   => $tier,
                    'offset' => $m[0][1],
                ];
            }
        }
        return $out;
    }

    /** True when tiers disagree in a way that should lower confidence. */
    private function hasConflict(array $neg, array $hyb, array $strong, array $weak): bool
    {
        $signals = 0;
        if ($neg !== [])    { $signals++; }
        if ($hyb !== [])    { $signals++; }
        if ($strong !== []) { $signals++; }
        return $signals > 1;
    }

    /** @return array<int,string> */
    private function extractGeo(string $text): array
    {
        $found = [];
        foreach (self::RE_GEO as $rule => $re) {
            if (preg_match_all($re, $text, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    $val = trim($m[1] ?? $m[0]);
                    $val = preg_replace('/\s+/', ' ', $val);
                    if ($val !== '' && mb_strlen($val) <= 45) {
                        $found[] = $val;
                    }
                }
            }
        }
        // Case-insensitive dedupe, preserving first-seen casing.
        $seen = [];
        $out  = [];
        foreach ($found as $f) {
            $k = mb_strtolower($f);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $f;
            }
        }
        return array_slice($out, 0, 4);
    }

    /** Replace "remote" used as jargon so it can't trigger a work-arrangement rule. */
    private function maskTechnicalContext(string $text): string
    {
        foreach (self::TECH_CONTEXT as $phrase) {
            // Spaces around the mask keep surrounding word boundaries intact.
            $text = preg_replace(
                '/' . preg_quote($phrase, '/') . '/i',
                ' ' . str_repeat('#', 8) . ' ',
                $text
            );
        }
        return $text;
    }

    private function normalize(string $text): string
    {
        // Block-level tags must become whitespace, not vanish. strip_tags()
        // alone turns "London<p>Remote-friendly" into "LondonRemote-friendly",
        // which destroys the \b word boundary every rule below depends on.
        // The eval caught this; it silently cost ~15 points of accuracy.
        $text = preg_replace('/<\/?(?:p|div|li|tr|h[1-6])\b[^>]*>/i', "\n", $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2019}"], ['-', '-', "'"], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        return trim($text);
    }
}
