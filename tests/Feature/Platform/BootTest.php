<?php

namespace Tests\Feature\Platform;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BootTest extends TestCase
{
    use RefreshDatabase;

    public function test_suite_runs_on_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_users_status_is_checked_by_the_database(): void
    {
        $row = fn (string $email, string $status) => [
            'name' => 'Someone',
            'email' => $email,
            'password' => 'x',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('users')->insert($row('active@example.com', 'active'));
        $this->assertDatabaseHas('users', ['email' => 'active@example.com']);

        try {
            DB::table('users')->insert($row('banned@example.com', 'banned'));
            $this->fail('An unknown status was accepted.');
        } catch (QueryException $e) {
            // 23514 = check_violation: the database itself refused the value.
            $this->assertSame('23514', $e->getCode());
        }
    }
}
