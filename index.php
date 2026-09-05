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

require_once __DIR__ . '/lib/HnClient.php';
require_once __DIR__ . '/lib/PostingParser.php';
require_once __DIR__ . '/lib/RemoteClassifier.php';
require_once __DIR__ . '/lib/JobSearch.php';

$keyword    = trim((string) ($_GET['q'] ?? ''));
$threadIdx  = max(0, (int) ($_GET['thread'] ?? 0));
$minConf    = (float) ($_GET['conf'] ?? 0.0);
$demo       = isset($_GET['demo']);
$statuses   = $_GET['status'] ?? [RemoteClassifier::REMOTE];
if (!is_array($statuses)) {
    $statuses = [RemoteClassifier::REMOTE];
}
$statuses = array_values(array_intersect($statuses, [
    RemoteClassifier::REMOTE, RemoteClassifier::HYBRID, RemoteClassifier::ONSITE,
]));
if ($statuses === []) {
    $statuses = [RemoteClassifier::REMOTE];
}

/*
 * Sent before any output. The page loads one same-origin stylesheet and runs
 * no script of its own, so it can afford to deny everything and name the two
 * exceptions. That policy is also what contains an injected URL scheme if one
 * ever reaches an href.
 */
header("Content-Security-Policy: default-src 'none'; style-src 'self'; "
     . "img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$search = new JobSearch(
    new HnClient(__DIR__ . '/cache'),
    new PostingParser(),
    new RemoteClassifier()
);

$error   = null;
$outcome = null;
$ran     = isset($_GET['q']) || $demo;

if ($ran) {
    try {
        if ($demo) {
            $fixtures = json_decode((string) file_get_contents(__DIR__ . '/eval/labeled.json'), true);
            $postings = array_map(fn($c) => [
                'id' => $c['id'], 'text' => $c['text'], 'author' => 'fixture', 'created_at' => '',
            ], $fixtures['cases'] ?? []);
            $outcome = $search->searchIn($postings, $keyword, $statuses, $minConf);
        } else {
            $outcome = $search->search($keyword, $statuses, $minConf, $threadIdx);
        }
    } catch (Throwable $e) {
        // RuntimeException is what this app throws on purpose, and those
        // messages are written for the reader. Anything else is a bug, and its
        // message carries internals — a TypeError names the absolute path of
        // the file that raised it. Log those; show the visitor nothing.
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Something went wrong while reading the thread.';

        if (!$e instanceof RuntimeException) {
            error_log('offsite: ' . $e);
        }
    }
}

/** Wrap each matched phrase so the reader can see what drove the verdict. */
function highlight(string $text, array $evidence, int $maxLen = 420): string
{
    // The placeholder tokens below are built from \x02 and \x03. A posting is
    // a stranger's text and may contain those bytes itself, which would let it
    // collide with a token and claim a highlight the classifier never matched.
    // Nothing legitimate needs them, so drop the C0 controls except tab and
    // newline — nl2br still needs the newline.
    // No /u here on purpose: these are ASCII bytes, and a unicode-mode match
    // returns null on malformed input, which would fail open.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;

    $short = mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen) . '…' : $text;
    $html  = htmlspecialchars($short, ENT_QUOTES, 'UTF-8');

    // Longest phrase first, so "not a remote position" wins over the bare
    // "remote" sitting inside it. Matches are swapped for placeholder tokens
    // rather than markup, otherwise a later phrase can match inside an earlier
    // <mark> and produce nested tags.
    usort($evidence, fn($a, $b) => mb_strlen($b['phrase']) <=> mb_strlen($a['phrase']));

    $slots = [];
    $seen  = [];

    foreach ($evidence as $e) {
        $phrase = trim($e['phrase']);
        if ($phrase === '' || isset($seen[mb_strtolower($phrase)])) {
            continue;
        }
        $seen[mb_strtolower($phrase)] = true;

        $needle = '/' . preg_quote(htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8'), '/') . '/i';
        if (!preg_match($needle, $html, $m)) {
            continue;
        }

        $idx     = count($slots);
        $token   = "\x02$idx\x03";
        $slots[] = '<mark class="ev ev-t' . (int) $e['tier'] . '">' . $m[0] . '</mark>';
        $html    = preg_replace($needle, $token, $html, 1) ?? $html;
    }

    foreach ($slots as $i => $markup) {
        $html = str_replace("\x02$i\x03", $markup, $html);
    }

    return nl2br($html);
}

function esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$statusLabel = [
    RemoteClassifier::REMOTE => 'Remote',
    RemoteClassifier::HYBRID => 'Hybrid',
    RemoteClassifier::ONSITE => 'Onsite',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Offsite - Remote Roles from Hacker News</title>
<link rel="icon" href="favicon.ico" sizes="48x48">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<header class="masthead">
  <p class="wordmark">Offsite</p>
  <h1>Remote roles in the <span class="src"><span class="nb">Hacker News</span> hiring threads</span></h1>
  <p class="lede">
  <a href="https://news.ycombinator.com/submitted?id=whoishiring">
    Hacker News posts hiring threads every month.</a> Nobody agrees on a format, and
    the word “remote” shows up in postings that aren’t remote at all. This web app reads
    each posting, decides between remote, hybrid and onsite, and shows you the
    exact words it based that on.
  </p>
</header>

<form class="controls" method="get" action="index.php">
  <?php if ($demo): ?><input type="hidden" name="demo" value="1"><?php endif; ?>

  <div class="row-search">
    <label class="field">
      <span>Keyword</span>
      <input type="search" name="q" value="<?= esc($keyword) ?>"
             placeholder="rust, django, security, designer…" autofocus>
    </label>
    <button type="submit">Search postings</button>
  </div>

  <fieldset class="row-filters">
    <legend>Show</legend>
    <?php foreach ($statusLabel as $key => $label): ?>
      <label class="check c-<?= $key ?>">
        <input type="checkbox" name="status[]" value="<?= $key ?>"
          <?= in_array($key, $statuses, true) ? 'checked' : '' ?>>
        <?= $label ?>
      </label>
    <?php endforeach; ?>

    <label class="field conf">
      <span>Minimum confidence</span>
      <select name="conf">
        <?php foreach (['0.0' => 'Any', '0.5' => 'Fairly sure', '0.8' => 'Very sure'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= abs((float)$v - $minConf) < 0.001 ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php if (!$demo && $outcome && !empty($outcome['threads'])): ?>
      <label class="field">
        <span>Thread</span>
        <select name="thread">
          <?php foreach ($outcome['threads'] as $i => $t): ?>
            <option value="<?= $i ?>" <?= $i === $threadIdx ? 'selected' : '' ?>>
              <?= esc(substr($t['created_at'], 0, 7)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
  </fieldset>
</form>

<?php if ($error !== null): ?>
  <div class="notice notice-error">
    <p><strong>Could not load postings.</strong> <?= esc($error) ?></p>
    <p>Hacker News may be unreachable from this machine. Try the
      <a href="?demo=1">offline sample</a>, which runs the same classifier over
      bundled test postings.</p>
  </div>
<?php endif; ?>

<?php if ($outcome !== null): ?>
  <?php $c = $outcome['counts']; ?>
  <section class="summary">
    <p>
      Read <strong><?= (int) $outcome['scanned'] ?></strong> postings<?php
        if (!$demo && $outcome['thread']) {
          echo ' from ' . esc($outcome['thread']['title']);
        } elseif ($demo) {
          echo ' from the bundled sample';
        }
      ?>.
      Classified <strong><?= $c[RemoteClassifier::REMOTE] ?? 0 ?></strong> remote,
      <strong><?= $c[RemoteClassifier::HYBRID] ?? 0 ?></strong> hybrid,
      <strong><?= $c[RemoteClassifier::ONSITE] ?? 0 ?></strong> onsite.
      Showing <strong><?= count($outcome['results']) ?></strong> that match your filters.
    </p>
  </section>

  <?php if ($outcome['results'] === []): ?>
    <div class="notice">
      <p><strong>Nothing matched.</strong></p>
      <p>Try a broader keyword, drop the confidence floor, or tick Hybrid to
         include split arrangements.</p>
    </div>
  <?php endif; ?>

  <ol class="results">
    <?php foreach ($outcome['results'] as $r):
      $v = $r['verdict']; $p = $r['parsed']; ?>
      <li class="posting s-<?= esc($v['status']) ?>">
        <div class="posting-head">
          <h2><?= esc($p['company'] ?? 'Unnamed company') ?></h2>
          <span class="verdict"><?= esc($statusLabel[$v['status']] ?? $v['status']) ?></span>
          <span class="conf-pct" title="How sure the classifier is">
            <?= number_format($v['confidence'] * 100, 0) ?>%
          </span>
        </div>

        <dl class="facts">
          <?php if ($p['role']): ?><div><dt>Role</dt><dd><?= esc($p['role']) ?></dd></div><?php endif; ?>
          <?php if ($p['location']): ?><div><dt>Location</dt><dd><?= esc($p['location']) ?></dd></div><?php endif; ?>
          <?php if ($p['salary']): ?><div><dt>Pay</dt><dd><?= esc($p['salary']) ?></dd></div><?php endif; ?>
          <?php if ($v['geo']): ?>
            <div><dt>Where you must be</dt><dd><?= esc(implode(' · ', $v['geo'])) ?></dd></div>
          <?php endif; ?>
        </dl>

        <?php if ($p['tech']): ?>
          <ul class="tech">
            <?php foreach ($p['tech'] as $t): ?><li><?= esc($t) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <div class="excerpt"><?= highlight($p['body'], $v['evidence']) ?></div>

        <?php if ($v['evidence']): ?>
          <details class="why"<?= $v['conflicts'] ? ' open' : '' ?>>
            <summary>
              <?= $v['conflicts']
                    ? 'Signals disagreed — see what fired'
                    : 'Why it was classified this way' ?>
            </summary>
            <ul>
              <?php foreach ($v['evidence'] as $e): ?>
                <li>
                  <code><?= esc($e['rule']) ?></code>
                  matched <q><?= esc($e['phrase']) ?></q>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>

        <?php if (!$demo): ?>
          <p class="links">
            <a href="<?= esc($r['url']) ?>">Read on Hacker News</a>
            <?php if ($p['apply_url']): ?>
              <a href="<?= esc($p['apply_url']) ?>">Company link</a>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
<?php elseif ($error === null): ?>
  <section class="notice notice-start">
    <p><strong>Search a hiring thread to begin.</strong></p>
    <p>Leave the keyword blank to see every remote posting in the current month,
       or try <a href="?q=rust">rust</a>, <a href="?q=django">django</a>, or
       <a href="?q=designer">designer</a>. No network access?
       <a href="?demo=1">Run the offline sample.</a></p>
  </section>
<?php endif; ?>

<footer>
  <p>Postings come from the 
    <a href="https://hn.algolia.com/api">
     Hacker News Algolia API</a> and belong to the people who
     posted them. Classification is done locally with pattern rules.
  <p>Offsite is free software under the
     <a href="https://www.gnu.org/licenses/gpl-3.0.html">GNU GPL v3</a>,
     and comes with no warranty. <a href="LICENSE">Read the license.</a>
     <a href="https://github.com/swmpi/offsite">Source on GitHub.</a></p>
</footer>

</body>
</html>
