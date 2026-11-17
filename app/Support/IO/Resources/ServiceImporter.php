<?php

namespace App\Support\IO\Resources;

use App\Support\IO\ImportResult;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The customImporter() of a ServiceBackedSchema: validates each row, resolves its code to the
 * record it updates (or none, to add one) and writes it through the schema's writer. A bad row
 * is reported and skipped; the rest of the file still imports. A preview ($commit = false)
 * writes nothing. Each row is its own transaction (the writers open one), so a refused row
 * never takes the rows before it with it.
 */
class ServiceImporter
{
    public function __construct(private ServiceBackedSchema $schema) {}

    /** @param array<int, array<string, mixed>> $rows header-keyed rows */
    public function import(array $rows, bool $commit): ImportResult
    {
        $result = new ImportResult(totalRows: count($rows));
        $user = auth()->user();
        if (! $user) {
            $result->errors[] = ['row' => 0, 'messages' => [__('An import needs a signed-in user.')]];

            return $result;
        }

        $this->schema->beginImport();
        $fieldKeys = array_map(fn ($f) => $f->key, $this->schema->fields());
        $seen = [];

        foreach ($rows as $i => $raw) {
            $rowNum = $i + 2; // 1 = header
            $data = [];
            foreach ($fieldKeys as $key) {
                if (array_key_exists($key, $raw)) {
                    $data[$key] = trim((string) $raw[$key]);
                }
            }
            if (implode('', $data) === '') {
                $result->skipped++;

                continue;
            }

            $validator = Validator::make($data, array_intersect_key($this->schema->rules(), $data + ['name' => '']));
            if ($validator->fails()) {
                $result->errors[] = ['row' => $rowNum, 'messages' => $validator->errors()->all()];

                continue;
            }

            $code = $data['code'] ?? '';
            if ($code !== '' && isset($seen[$code])) {
                $result->errors[] = ['row' => $rowNum, 'messages' => [__('Code :code is already on row :row of this file.', ['code' => $code, 'row' => $seen[$code]])]];

                continue;
            }
            if ($code !== '') {
                $seen[$code] = $rowNum;
            }

            try {
                $existing = $code !== '' ? $this->schema->findForImport($code, $user) : null;
                if ($code !== '' && ! $existing) {
                    throw new RuntimeException(__('There is no :code to update. Leave the code empty to add a new one.', ['code' => $code]));
                }
                $attrs = $this->schema->toAttributes($data);
                if ($commit) {
                    $this->schema->writeRow($attrs, $existing, $user);
                }
                $existing ? $result->updated++ : $result->created++;
                if ($existing) {
                    $result->notices[] = ['row' => $rowNum, 'messages' => [__('Updates :code.', ['code' => $code])]];
                }
            } catch (ValidationException $e) {
                $result->errors[] = ['row' => $rowNum, 'messages' => $e->validator->errors()->all()];
            } catch (RuntimeException $e) {
                $result->errors[] = ['row' => $rowNum, 'messages' => [$e->getMessage()]];
            }
        }

        return $result;
    }
}
