<?php

namespace Tests\Feature\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The schema keeps the design's shape however it grows: one company, no floating-point
 * numbers, no stored balances (spec I1 — every balance is a SUM over rows).
 */
class SchemaGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> "table.column" for every column matching the condition */
    private function columns(string $where, array $bindings = []): array
    {
        return array_map(
            fn ($row) => "{$row->table_name}.{$row->column_name}",
            DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema() AND {$where} ORDER BY table_name, column_name", $bindings),
        );
    }

    public function test_no_table_has_company_id(): void
    {
        $this->assertSame([], $this->columns("column_name = 'company_id'"), 'one company: there is no company layer');
    }

    public function test_no_float_columns(): void
    {
        $this->assertSame([], $this->columns("data_type IN ('real', 'double precision')"), 'money and quantities are numeric, never float');
    }

    public function test_no_stored_balance_columns(): void
    {
        $this->assertSame([], $this->columns("column_name ILIKE '%balance%'"), 'balances are computed, never stored (spec I1)');
    }
}
