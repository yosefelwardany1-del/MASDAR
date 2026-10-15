<?php

namespace App\Support\IO;

/**
 * Generates a CSV template for a resource directly from its schema — header,
 * a hint row (commented with '#', skipped on import), and a sample row. Always in sync
 * with the schema's fields/rules; nothing is hand-maintained.
 */
class CsvTemplateGenerator
{
    /** The three template rows (header, hint, sample) shared by CSV + XLSX output. */
    public function rows(ResourceSchema $schema): array
    {
        $header = [];
        $hints = [];
        $sample = [];

        foreach ($schema->fields() as $i => $field) {
            $header[] = $field->key;
            $hint = ($field->required ? 'REQUIRED' : 'optional');
            if ($field->hint) {
                $hint .= ' — '.$field->hint;
            }
            // First hint cell carries '#' so the import parser treats the whole row as a comment.
            $hints[] = ($i === 0 ? '# ' : '').$hint;
            $sample[] = $field->sample ?? '';
        }

        return [$header, $hints, $sample];
    }

    public function generate(ResourceSchema $schema): string
    {
        return Csv::write($this->rows($schema));
    }

    public function generateXlsx(ResourceSchema $schema): string
    {
        return Spreadsheet::write($this->rows($schema));
    }

    public function filename(ResourceSchema $schema, string $format = 'csv'): string
    {
        return $schema->key().'_import_template.'.$format;
    }
}
