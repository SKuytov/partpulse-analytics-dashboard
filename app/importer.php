<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class PartPulseImporter
{
    private const SHEETS = [
        'Статус Разпределение' => ['Статус', 'Брой', '% от Общото'],
        'SLA Нарушения' => ['#', 'Артикул', 'Статус', 'Сграда', 'Приоритет', 'Дни в Статус', 'Праг (дни)', 'Заявител'],
        'Разходи по Сграда' => ['Код Сграда', 'Сграда', 'Разходи (EUR)', 'Поръчки', '% от Общото'],
    ];

    public function __construct(private PDO $pdo, private array $config) {}

    public function import(array $file): int
    {
        $this->validateUpload($file);
        $snapshotDate = $this->extractDate($file['name']);
        $dir = rtrim($this->config['upload_dir'], DIRECTORY_SEPARATOR);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Could not create uploads directory.');
        $safeName = sprintf('%s_%s_%s.xlsx', $snapshotDate, date('His'), bin2hex(random_bytes(4)));
        $storedPath = $dir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $storedPath)) throw new RuntimeException('The uploaded workbook could not be stored.');

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($storedPath);
            foreach (self::SHEETS as $sheet => $headers) $this->assertSheet($book->getSheetByName($sheet), $sheet, $headers);
            $hash = hash_file('sha256', $storedPath);

            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare('SELECT id FROM imports WHERE file_hash = ? LIMIT 1');
            $check->execute([$hash]);
            if ($check->fetchColumn()) throw new RuntimeException('This exact workbook has already been imported.');

            $insert = $this->pdo->prepare('INSERT INTO imports (snapshot_date, original_name, stored_name, file_hash, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$snapshotDate, basename($file['name']), $safeName, $hash, (int)$file['size'], (string)($_SESSION['admin_user'] ?? 'admin')]);
            $importId = (int)$this->pdo->lastInsertId();

            $this->insertStatuses($importId, $book->getSheetByName('Статус Разпределение'));
            $this->insertSla($importId, $book->getSheetByName('SLA Нарушения'));
            $this->insertBuildings($importId, $book->getSheetByName('Разходи по Сграда'));
            $this->pdo->commit();
            $book->disconnectWorksheets();
            return $importId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            @unlink($storedPath);
            throw $e;
        }
    }

    private function validateUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Check the PHP upload limits and retry.');
        if (($file['size'] ?? 0) < 1 || $file['size'] > $this->config['max_upload_bytes']) throw new RuntimeException('Workbook is empty or exceeds the configured size limit.');
        if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('Only .xlsx workbooks are accepted.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'];
        if (!in_array($mime, $allowed, true)) throw new RuntimeException('The uploaded file is not a valid XLSX workbook.');
    }

    private function extractDate(string $name): string
    {
        if (!preg_match('/(20\d{2})[-_](\d{2})[-_](\d{2})/', $name, $m)) throw new RuntimeException('The filename must contain a date such as 2026-10-16.');
        $date = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        [$y, $mo, $d] = array_map('intval', [$m[1], $m[2], $m[3]]);
        if (!checkdate($mo, $d, $y)) throw new RuntimeException('The filename contains an invalid date.');
        return $date;
    }

    private function assertSheet(?Worksheet $sheet, string $name, array $expected): void
    {
        if (!$sheet) throw new RuntimeException("Missing worksheet: {$name}");
        $actual = array_map(static fn($v) => trim((string)$v), $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', null, true, false)[0]);
        if (array_slice($actual, 0, count($expected)) !== $expected) throw new RuntimeException("Unexpected columns in worksheet: {$name}");
    }

    private function rows(Worksheet $sheet): iterable
    {
        $data = $sheet->toArray(null, true, true, false);
        array_shift($data);
        foreach ($data as $row) if (count(array_filter($row, static fn($v) => $v !== null && $v !== '')) > 0) yield $row;
    }

    private function insertStatuses(int $id, Worksheet $sheet): void
    {
        $q = $this->pdo->prepare('INSERT INTO status_metrics (import_id, status_name, order_count, percentage) VALUES (?, ?, ?, ?)');
        foreach ($this->rows($sheet) as $r) $q->execute([$id, trim((string)$r[0]), (int)$r[1], (float)$r[2]]);
    }

    private function insertSla(int $id, Worksheet $sheet): void
    {
        $q = $this->pdo->prepare('INSERT INTO sla_violations (import_id, order_no, article, status_name, building_code, priority, days_in_status, threshold_days, requester) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($this->rows($sheet) as $r) $q->execute([$id, (int)$r[0], trim((string)$r[1]), trim((string)$r[2]), trim((string)$r[3]), trim((string)$r[4]), (int)$r[5], (int)$r[6], trim((string)$r[7])]);
    }

    private function insertBuildings(int $id, Worksheet $sheet): void
    {
        $q = $this->pdo->prepare('INSERT INTO building_costs (import_id, building_code, building_name, costs_eur, order_count, percentage) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($this->rows($sheet) as $r) $q->execute([$id, trim((string)$r[0]), trim((string)$r[1]), (float)$r[2], (int)$r[3], (float)$r[4]]);
    }
}
