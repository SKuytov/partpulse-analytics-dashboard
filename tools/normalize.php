<?php
declare(strict_types=1);
/**
 * PartPulse Normalizer — command-line tool.
 *
 * Converts a raw PartPulse Orders phpMyAdmin export (.sql) into a sanitized
 * package (partpulse-normalized-YYYY-MM-DD.zip) that can be uploaded to the
 * dashboard. Nothing is executed and nothing is sent anywhere.
 *
 * Usage:
 *   php tools/normalize.php partpulse_orders.sql [--date=2026-10-16] [--out=folder] [--source-tz=UTC] [--tz=Europe/Sofia] [--csv]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/Normalizer.php';
ini_set('memory_limit', '1024M');

$args = $argv;
array_shift($args);
$opts = ['date' => null, 'out' => null, 'source-tz' => 'UTC', 'tz' => 'Europe/Sofia', 'csv' => false];
$files = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
    else $files[] = $a;
}
if (!$files || isset($opts['help'])) {
    fwrite(STDERR, "PartPulse Normalizer " . PartPulseNormalizer::TOOL_VERSION . "\nUsage: php normalize.php EXPORT.sql [--date=YYYY-MM-DD] [--out=DIR] [--source-tz=UTC] [--tz=Europe/Sofia] [--csv]\n");
    exit(2);
}
$exit = 0;
foreach ($files as $in) {
    try {
        $in = realpath($in) ?: $in;
        if (!is_file($in)) throw new RuntimeException("File not found: {$in}");
        $t = microtime(true);
        echo "Reading  " . basename($in) . ' (' . number_format(filesize($in) / 1048576, 1) . " MB)\n";
        $n = new PartPulseNormalizer((string)$opts['source-tz'], (string)$opts['tz']);
        $data = $n->fromSqlFile($in, is_string($opts['date']) ? $opts['date'] : null, basename($in));
        $date = $data['manifest']['snapshot_date'];
        $dir = is_string($opts['out']) ? $opts['out'] : dirname($in);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $zip = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . "partpulse-normalized-{$date}.zip";
        $n->writeZip($data, $zip);
        if ($opts['csv']) {
            $csvDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . "partpulse-normalized-{$date}";
            if (!is_dir($csvDir)) mkdir($csvDir, 0775, true);
            foreach (PartPulseNormalizer::FILES as $file => $cols) file_put_contents($csvDir . DIRECTORY_SEPARATOR . $file, "\xEF\xBB\xBF" . PartPulseNormalizer::toCsv($cols, $data['tables'][$file]));
            file_put_contents($csvDir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($data['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            echo "CSV      {$csvDir}\n";
        }
        $c = $data['manifest']['counts'];
        printf("Snapshot %s (export generated %s)\n", $date, $data['manifest']['source_generated_at']);
        printf("Orders   %d   History %d   Assignments %d   Suppliers %d   Users %d\n", $c['orders.csv'], $c['order_history.csv'], $c['assignments.csv'], $c['suppliers.csv'], $c['users.csv']);
        printf("Removed  %s\n", $data['manifest']['removed']);
        printf("Created  %s  (%.1fs)\n\n", $zip, microtime(true) - $t);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR    " . $e->getMessage() . "\n\n");
        $exit = 1;
    }
}
exit($exit);
