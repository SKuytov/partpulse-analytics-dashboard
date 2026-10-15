<?php
declare(strict_types=1);

/**
 * Streaming-safe reader for phpMyAdmin / mysqldump SQL exports.
 *
 * The dump is NEVER executed. The reader tokenizes the text, records the column
 * layout of CREATE TABLE statements and decodes the literal values of INSERT
 * statements for an explicit allow-list of tables. Every other statement
 * (DROP, ALTER, SET, triggers, procedures, views ...) is ignored.
 */
final class SqlDumpReader
{
    /** @var array<string, array<string, string>> table => [column => lower-case type] */
    private array $columns = [];
    private ?string $generatedAt = null;
    private string $dumpTimezone = '+00:00';
    private ?string $databaseName = null;

    /**
     * @param string[] $tables Allow-listed tables whose rows should be decoded.
     * @param callable(string $table, array<string, ?string> $row): void $onRow
     */
    public function read(string $path, array $tables, callable $onRow): void
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('The SQL export could not be read.');
        $sql = file_get_contents($path);
        if ($sql === false || $sql === '') throw new RuntimeException('The SQL export is empty.');
        if (str_starts_with($sql, "\xEF\xBB\xBF")) $sql = substr($sql, 3);
        $this->readString($sql, $tables, $onRow);
    }

    /** @param string[] $tables */
    public function readString(string $sql, array $tables, callable $onRow): void
    {
        $wanted = array_fill_keys(array_map('strtolower', $tables), true);
        $this->parseHeader(substr($sql, 0, 4096));
        foreach ($this->statements($sql) as [$start, $end]) {
            $head = ltrim(substr($sql, $start, 160));
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?\s*\(/i', $head, $m)) {
                $this->parseCreate(substr($sql, $start, $end - $start), strtolower($m[1]));
            } elseif (preg_match('/^SET\s+time_zone\s*=\s*["\']([+-]\d{2}:\d{2})["\']/i', $head, $m)) {
                $this->dumpTimezone = $m[1];
            } elseif (preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_$]+)`?/i', $head, $m)) {
                $table = strtolower($m[1]);
                if (isset($wanted[$table])) $this->parseInsert($sql, $start, $end, $table, $onRow);
            }
        }
    }

    /** @return array<string, array<string, string>> */
    public function columns(): array { return $this->columns; }
    public function generatedAt(): ?string { return $this->generatedAt; }
    public function dumpTimezone(): string { return $this->dumpTimezone; }
    public function databaseName(): ?string { return $this->databaseName; }

    public function columnType(string $table, string $column): ?string
    {
        return $this->columns[strtolower($table)][$column] ?? null;
    }

    private function parseHeader(string $head): void
    {
        if (preg_match('/^--\s*Generation Time:\s*(.+)$/mi', $head, $m)) {
            $text = str_replace(' at ', ' ', trim($m[1]));
            $ts = strtotime($text);
            if ($ts !== false) $this->generatedAt = date('Y-m-d H:i:s', $ts);
        } elseif (preg_match('/^--\s*Dump completed on\s+(\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}:\d{2})/mi', $head, $m)) {
            $this->generatedAt = date('Y-m-d H:i:s', strtotime($m[1]));
        }
        if (preg_match('/^--\s*Database:\s*`?([^`\s]+)`?/mi', $head, $m)) $this->databaseName = $m[1];
    }

    /**
     * Split the dump into top-level statements, honouring quotes and comments.
     * @return Generator<int, array{0:int,1:int}>
     */
    private function statements(string $sql): Generator
    {
        $len = strlen($sql);
        $i = 0;
        $start = 0;
        while ($i < $len) {
            $skip = strcspn($sql, "'\"`;-/#", $i);
            $i += $skip;
            if ($i >= $len) break;
            $c = $sql[$i];
            if ($c === ';') {
                yield [$start, $i];
                $i++;
                $start = $i;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $i = $this->skipQuoted($sql, $i, $c); continue; }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true)) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl + 1;
                if ($this->onlyWhitespace($sql, $start, $i)) $start = $i;
                continue;
            }
            if ($c === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl + 1;
                if ($this->onlyWhitespace($sql, $start, $i)) $start = $i;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $endC = strpos($sql, '*/', $i + 2);
                $i = $endC === false ? $len : $endC + 2;
                if ($this->onlyWhitespace($sql, $start, $i)) $start = $i;
                continue;
            }
            $i++;
        }
        if ($start < $len && trim(substr($sql, $start)) !== '') yield [$start, $len];
    }

    private function onlyWhitespace(string $sql, int $from, int $to): bool
    {
        // True when the current statement so far consists only of comments/whitespace.
        $chunk = substr($sql, $from, $to - $from);
        $chunk = preg_replace('~/\*.*?\*/~s', '', $chunk) ?? $chunk;
        $chunk = preg_replace('/^\s*(--[ \t].*|--|#.*)$/m', '', $chunk) ?? $chunk;
        return trim($chunk) === '';
    }

    private function skipQuoted(string $sql, int $i, string $q): int
    {
        $len = strlen($sql);
        $i++;
        while ($i < $len) {
            $i += strcspn($sql, $q . '\\', $i);
            if ($i >= $len) return $len;
            if ($sql[$i] === '\\') { $i += 2; continue; }
            if (($sql[$i + 1] ?? '') === $q) { $i += 2; continue; }
            return $i + 1;
        }
        return $len;
    }

    private function parseCreate(string $stmt, string $table): void
    {
        $open = strpos($stmt, '(');
        $close = strrpos($stmt, ')');
        if ($open === false || $close === false) return;
        $body = substr($stmt, $open + 1, $close - $open - 1);
        $cols = [];
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (preg_match('/^\s*`([^`]+)`\s+([a-zA-Z]+)/', $line, $m)) $cols[$m[1]] = strtolower($m[2]);
        }
        if ($cols) $this->columns[$table] = $cols;
    }

    private function parseInsert(string $sql, int $start, int $end, string $table, callable $onRow): void
    {
        $stmt = substr($sql, $start, $end - $start);
        if (!preg_match('/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?[A-Za-z0-9_$]+`?\s*(\(([^)]*)\))?\s*VALUES\s*/i', $stmt, $m)) return;
        if (!empty($m[2])) {
            $names = array_map(static fn($c) => trim($c, " \t\r\n`"), explode(',', $m[2]));
        } else {
            $names = array_keys($this->columns[$table] ?? []);
            if (!$names) throw new RuntimeException("INSERT for `{$table}` has no column list and no CREATE TABLE definition.");
        }
        $count = count($names);
        $i = strlen($m[0]);
        $len = strlen($stmt);
        while ($i < $len) {
            $i += strspn($stmt, " \t\r\n,", $i);
            if ($i >= $len) break;
            if ($stmt[$i] !== '(') {
                // ON DUPLICATE KEY UPDATE or similar trailing clause.
                break;
            }
            $i++;
            $values = [];
            while (true) {
                $i += strspn($stmt, " \t\r\n", $i);
                [$value, $i] = $this->readValue($stmt, $i);
                $values[] = $value;
                $i += strspn($stmt, " \t\r\n", $i);
                $c = $stmt[$i] ?? '';
                if ($c === ',') { $i++; continue; }
                if ($c === ')') { $i++; break; }
                throw new RuntimeException("Malformed INSERT row in `{$table}` near byte " . ($start + $i) . '.');
            }
            if (count($values) !== $count) throw new RuntimeException("Column count mismatch in `{$table}`.");
            $onRow($table, array_combine($names, $values));
        }
    }

    /** @return array{0:?string,1:int} */
    private function readValue(string $s, int $i): array
    {
        $c = $s[$i] ?? '';
        if ($c === "'" || $c === '"') {
            $out = '';
            $len = strlen($s);
            $i++;
            while ($i < $len) {
                $n = strcspn($s, $c . '\\', $i);
                $out .= substr($s, $i, $n);
                $i += $n;
                if ($i >= $len) break;
                if ($s[$i] === '\\') {
                    $e = $s[$i + 1] ?? '';
                    $out .= match ($e) {
                        '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1A",
                        default => $e,
                    };
                    $i += 2;
                    continue;
                }
                if (($s[$i + 1] ?? '') === $c) { $out .= $c; $i += 2; continue; }
                return [$out, $i + 1];
            }
            throw new RuntimeException('Unterminated string literal in SQL export.');
        }
        if (preg_match('/\G(?:_[a-z0-9]+)?(0x[0-9A-Fa-f]*|[bB]\'[01]*\'|NULL|[-+]?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?|[A-Za-z_][A-Za-z0-9_]*(?:\(\))?)/', $s, $m, 0, $i)) {
            $raw = $m[1];
            $next = $i + strlen($m[0]);
            if (strcasecmp($raw, 'NULL') === 0) return [null, $next];
            if (str_starts_with(strtolower($raw), '0x')) return [hex2bin(strlen($raw) % 2 ? '0' . substr($raw, 2) : substr($raw, 2)) ?: '', $next];
            if ($raw[0] === 'b' || $raw[0] === 'B') return [(string)bindec(trim(substr($raw, 1), "'")), $next];
            return [$raw, $next];
        }
        throw new RuntimeException('Unsupported value in SQL export near byte ' . $i . '.');
    }
}
