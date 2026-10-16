<?php
declare(strict_types=1);

/**
 * Idempotent schema upgrade. Creates missing tables from sql/schema.sql and
 * adds the 2.0 columns to `imports` on installations created with 1.x.
 */
final class PartPulseSchema
{
    private const IMPORT_COLUMNS = [
        'source_type' => "VARCHAR(20) NOT NULL DEFAULT 'xlsx'",
        'source_generated_at' => 'DATETIME NULL',
        'order_count' => 'INT UNSIGNED NULL',
        'history_added' => 'INT UNSIGNED NULL',
        'summary_json' => 'TEXT NULL',
    ];

    public static function ensure(PDO $pdo): bool
    {
        $changed = false;
        $schema = (string)file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
        $existing = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
            if (!preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?/i', $statement, $m)) continue;
            if (in_array(strtolower($m[1]), $existing, true)) continue;
            $pdo->exec($statement);
            $changed = true;
        }
        $q = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'imports'");
        $cols = array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
        foreach (self::IMPORT_COLUMNS as $name => $definition) {
            if (in_array($name, $cols, true)) continue;
            $pdo->exec("ALTER TABLE imports ADD COLUMN {$name} {$definition}");
            $changed = true;
        }
        return $changed;
    }
}
