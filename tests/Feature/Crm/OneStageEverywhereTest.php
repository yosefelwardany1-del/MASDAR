<?php

namespace Tests\Feature\Crm;

use App\Models\Party;
use App\Models\User;
use App\Services\Crm\CrmQueries;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B11: the client list, its filter, the export, the client page and the Pipeline show one stage. */
class OneStageEverywhereTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_a_client_typed_as_an_inquiry_with_a_confirmed_order_shows_its_document_stage_everywhere(): void
    {
        app()->setLocale('en');
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $manager = User::where('email', 'manager@example.test')->sole();
        $manager->forceFill(['locale' => 'en'])->save();
        $gulf = Party::where('name', 'Gulf Home Trading LLC')->sole();
        // Typed long ago, before its PI: the documents are the latest signal.
        DB::table('parties')->where('id', $gulf->id)->update(['stage' => 'inquiry', 'created_at' => '2025-01-01 09:00:00']);
        $this->assertSame(0, DB::table('client_stage_changes')->where('party_id', $gulf->id)->count());

        $now = app(CrmQueries::class)->stagesFor([$gulf->id])[$gulf->id];
        $this->assertSame('documents', $now['by']);
        $this->assertNotSame('inquiry', $now['stage']);
        $label = app(CrmQueries::class)->stages()[$now['stage']]['label'];

        // The list shows it, and its filter finds it there and not under the typed stage.
        $this->actingAs($manager)->get(route('clients.index', ['stage' => $now['stage']]))->assertOk()->assertSee($gulf->name)->assertSee($label);
        $this->actingAs($manager)->get(route('clients.index', ['stage' => 'inquiry']))->assertOk()->assertDontSee($gulf->name)->assertSee('Basra Trading House');
        $this->actingAs($manager)->get(route('clients.show', $gulf))->assertSee($label);

        // The export says the same.
        $csv = $this->actingAs($manager)->get(route('io.export', 'clients'))->assertOk()->getContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = $rows[0];
        $row = collect($rows)->first(fn ($r) => in_array($gulf->code, $r, true));
        $this->assertSame($now['stage'], $row[array_search('stage', $header, true)]);
    }
}
