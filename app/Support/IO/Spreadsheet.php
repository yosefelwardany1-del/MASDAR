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
     * B22: the file really is a spreadsheet (not a text file named .xlsx), and its header row's
     * columns; null when it cannot be read as one.
     *
     * @return list<string>|null
     */
    public static function header(string $path): ?array
    {
        try {
            if (! in_array(IOFactory::identify($path), ['Xlsx', 'Xls', 'Ods'], true)) {
                return null;
            }
            $first = IOFactory::load($path)->getActiveSheet()->rangeToArray('A1:'.'ZZ1', null, true, false, false)[0] ?? [];
        } catch (\Throwable) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($h) => trim((string) $h), $first), fn ($h) => $h !== ''));
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
