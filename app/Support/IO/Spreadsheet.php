<?php

namespace App\Support\IO;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet as PhpSpreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/** XLSX read/write (PhpSpreadsheet), mirroring the CSV helper's row/assoc shape. */
class Spreadsheet
{
    /** @param array<int,array<int,scalar|null>> $rows */
    public static function write(array $rows): string
    {
        $ss = new PhpSpreadsheet;
        $sheet = $ss->getActiveSheet();
        $r = 1;
        foreach ($rows as $row) {
            // Neutralize formula injection before writing string cells.
            $sheet->fromArray(CsvSanitizer::row(array_values((array) $row)), null, 'A'.$r);
            $r++;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $content = file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    /**
     * Parse the first worksheet into header-keyed rows. Blank rows and '#'-prefixed
     * (template hint) rows are skipped — identical semantics to Csv::parseAssoc.
     *
     * @return array<int,array<string,string>>
     */
    public static function parseAssoc(string $path): array
    {
        $data = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        if (empty($data)) {
            return [];
        }
        $header = array_map(fn ($h) => trim((string) $h), $data[0]);

        $rows = [];
        foreach (array_slice($data, 1) as $line) {
            $first = trim((string) ($line[0] ?? ''));
            if ($first === '' && count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (str_starts_with($first, '#')) {
                continue;
            }
            $assoc = [];
            foreach ($header as $idx => $col) {
                $assoc[$col] = (string) ($line[$idx] ?? '');
            }
            $rows[] = $assoc;
        }

        return $rows;
    }
}
