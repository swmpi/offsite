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
 * Eval harness for RemoteClassifier.
 *
 *   php eval/run.php              accuracy + confusion matrix + failures
 *   php eval/run.php --verbose    also prints evidence for every case
 *   php eval/run.php --json       machine-readable, for diffing across runs
 *
 * Run it before and after any rule change. If the number goes down, the change
 * was worse, regardless of how good the idea felt.
 */

require_once __DIR__ . '/../lib/RemoteClassifier.php';

$opts    = getopt('', ['verbose', 'json', 'file:']);
$verbose = isset($opts['verbose']);
$asJson  = isset($opts['json']);
$file    = $opts['file'] ?? __DIR__ . '/labeled.json';

$raw = file_get_contents($file);
if ($raw === false) {
    fwrite(STDERR, "Could not read $file\n");
    exit(1);
}

$data  = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$cases = $data['cases'] ?? [];
if ($cases === []) {
    fwrite(STDERR, "No cases found in $file\n");
    exit(1);
}

$clf     = new RemoteClassifier();
$classes = [RemoteClassifier::REMOTE, RemoteClassifier::HYBRID, RemoteClassifier::ONSITE];

$confusion = [];
foreach ($classes as $a) {
    foreach ($classes as $b) {
        $confusion[$a][$b] = 0;
    }
}

$correct  = 0;
$failures = [];
$rows     = [];
$confSum  = ['right' => [], 'wrong' => []];

foreach ($cases as $case) {
    $expected = $case['expect'];
    $verdict  = $clf->classify($case['text'], $case['header'] ?? null);
    $actual   = $verdict['status'];
    $ok       = ($actual === $expected);

    if ($ok) {
        $correct++;
        $confSum['right'][] = $verdict['confidence'];
    } else {
        $confSum['wrong'][] = $verdict['confidence'];
        $failures[] = [
            'id'         => $case['id'],
            'expected'   => $expected,
            'actual'     => $actual,
            'confidence' => $verdict['confidence'],
            'note'       => $case['note'] ?? '',
            'evidence'   => array_map(
                fn($e) => "{$e['rule']}(T{$e['tier']}): \"{$e['phrase']}\"",
                $verdict['evidence']
            ),
            'header'     => $case['header'] ?? '',
        ];
    }

    if (isset($confusion[$expected][$actual])) {
        $confusion[$expected][$actual]++;
    }

    $rows[] = [
        'id' => $case['id'], 'expected' => $expected, 'actual' => $actual,
        'ok' => $ok, 'confidence' => $verdict['confidence'],
        'geo' => $verdict['geo'], 'conflicts' => $verdict['conflicts'],
        'evidence' => $verdict['evidence'],
    ];
}

$total    = count($cases);
$accuracy = $total > 0 ? $correct / $total : 0.0;

// Per-class precision / recall / F1
$metrics = [];
foreach ($classes as $c) {
    $tp = $confusion[$c][$c];
    $fn = array_sum($confusion[$c]) - $tp;
    $fp = 0;
    foreach ($classes as $other) {
        if ($other !== $c) {
            $fp += $confusion[$other][$c];
        }
    }
    $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
    $recall    = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
    $f1        = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
    $metrics[$c] = [
        'support' => $tp + $fn, 'precision' => $precision, 'recall' => $recall, 'f1' => $f1,
    ];
}

$avg = fn(array $xs) => $xs === [] ? 0.0 : array_sum($xs) / count($xs);

if ($asJson) {
    echo json_encode([
        'total' => $total, 'correct' => $correct, 'accuracy' => round($accuracy, 4),
        'metrics' => $metrics, 'confusion' => $confusion,
        'mean_confidence_correct' => round($avg($confSum['right']), 3),
        'mean_confidence_wrong'   => round($avg($confSum['wrong']), 3),
        'failures' => $failures, 'rows' => $verbose ? $rows : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($failures === [] ? 0 : 1);
}

$pct = fn(float $x) => str_pad(number_format($x * 100, 1) . '%', 6, ' ', STR_PAD_LEFT);

echo "\n";
echo "RemoteClassifier eval  ·  " . basename($file) . "\n";
echo str_repeat('=', 62), "\n";
echo "Accuracy   " . $pct($accuracy) . "   ($correct/$total)\n\n";

echo "Per class\n";
printf("  %-8s %8s %8s %8s %8s\n", 'class', 'support', 'prec', 'recall', 'f1');
foreach ($classes as $c) {
    printf(
        "  %-8s %8d %8s %8s %8s\n",
        $c, $metrics[$c]['support'],
        $pct($metrics[$c]['precision']), $pct($metrics[$c]['recall']), $pct($metrics[$c]['f1'])
    );
}

echo "\nConfusion matrix  (rows = expected, cols = predicted)\n";
printf("  %-10s", '');
foreach ($classes as $c) {
    printf("%9s", $c);
}
echo "\n";
foreach ($classes as $exp) {
    printf("  %-10s", $exp);
    foreach ($classes as $act) {
        $v = $confusion[$exp][$act];
        printf("%9s", $exp === $act ? "[$v]" : ($v ?: '·'));
    }
    echo "\n";
}

echo "\nConfidence   correct " . number_format($avg($confSum['right']), 2)
   . "   ·   wrong " . number_format($avg($confSum['wrong']), 2) . "\n";
echo "  (a well-behaved classifier is less confident when it is wrong)\n";

if ($failures !== []) {
    echo "\n" . count($failures) . " failure(s)\n";
    echo str_repeat('-', 62), "\n";
    foreach ($failures as $f) {
        echo "  {$f['id']}  expected {$f['expected']}, got {$f['actual']} (conf {$f['confidence']})\n";
        if ($f['note'] !== '') {
            echo "      case: {$f['note']}\n";
        }
        echo "      header: " . mb_substr($f['header'], 0, 70) . "\n";
        foreach ($f['evidence'] as $e) {
            echo "      fired: $e\n";
        }
        echo "\n";
    }
} else {
    echo "\nNo failures.\n";
}

if ($verbose) {
    echo "\nAll cases\n";
    echo str_repeat('-', 62), "\n";
    foreach ($rows as $r) {
        $mark = $r['ok'] ? ' ok ' : 'FAIL';
        echo "  [$mark] {$r['id']}  {$r['actual']}  conf {$r['confidence']}"
           . ($r['conflicts'] ? '  (conflicting signals)' : '') . "\n";
        if ($r['geo'] !== []) {
            echo "         geo: " . implode(' · ', $r['geo']) . "\n";
        }
        foreach ($r['evidence'] as $e) {
            echo "         {$e['rule']}(T{$e['tier']}): \"{$e['phrase']}\"\n";
        }
    }
}

echo "\n";
exit($failures === [] ? 0 : 1);
