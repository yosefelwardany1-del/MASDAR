<?php

namespace App\Support\IO;

/** Minimal, dependency-free CSV read/write helpers (RFC-4180 via fputcsv/fgetcsv). */
class Csv
{
    /** @param array<int,array<int,scalar|null>> $rows */
    public static function write(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, CsvSanitizer::row($row)); // neutralize formula injection
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return $out;
    }

    /** The header row's columns, trimmed; [] when there is none (B22). @return list<string> */
    public static function header(string $content): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = fgetcsv($fh);
        fclose($fh);

        return is_array($header) ? array_values(array_filter(array_map(fn ($h) => trim((string) $h), $header), fn ($h) => $h !== '')) : [];
    }

    /**
     * Parse CSV into header-keyed associative rows. Blank lines and rows whose first
     * cell starts with '#' (template hint/comment rows) are skipped.
     *
     * @return array<int,array<string,string>>
     */
    public static function parseAssoc(string $content): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);

        $header = fgetcsv($fh);
        if ($header === false || $header === null) {
            fclose($fh);

            return [];
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        while (($line = fgetcsv($fh)) !== false) {
            $first = trim((string) ($line[0] ?? ''));
            if ($first === '' && count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }
            if (str_starts_with($first, '#')) {
                continue; // comment / hint row
            }
            $assoc = [];
            foreach ($header as $idx => $col) {
                $assoc[$col] = $line[$idx] ?? '';
            }
            $rows[] = $assoc;
        }
        fclose($fh);

        return $rows;
    }
}
