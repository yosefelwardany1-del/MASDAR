<?php

namespace Tests\Feature\Crm;

use App\Models\Lookup;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Database\Seeders\MasdarReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B12: the stages from "PI sent" on follow the documents, also on an installation whose list predates the flag. */
class DocumentStagesTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_a_person_cannot_move_a_client_to_a_document_stage_even_when_the_flag_is_missing(): void
    {
        app()->setLocale('en');
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $manager = User::where('email', 'manager@example.test')->sole();
        $manager->forceFill(['locale' => 'en'])->save();
        // As on the live system: the client stages were made before "follows the documents" was added.
        DB::table('lookups')->where('list', 'client_stage')->update(['meta' => '{}']);
        $basra = Party::where('name', 'Basra Trading House')->sole(); // no PI

        $this->actingAs($manager)->get(route('crm.pipeline'))->assertOk()
            ->assertDontSee('<option value="delivered"', false)->assertSee('<option value="lead"', false);
        $this->actingAs($manager)->post(route('crm.pipeline.move'), ['party_id' => $basra->id, 'stage' => 'delivered'])
            ->assertSessionHasErrors(['stage' => 'Delivered follows the documents: it is reached by issuing, confirming and shipping the client\'s PI, not set by hand.']);
        $this->actingAs($manager)->post(route('crm.pipeline.move'), ['party_id' => $basra->id, 'stage' => 'lead'])->assertSessionHasNoErrors();
        $this->assertSame('lead', $basra->fresh()->stage);

        // The next deploy's seeder puts the missing flags back.
        $this->seed(MasdarReferenceSeeder::class);
        $this->assertSame(1, (int) Lookup::where('list', 'client_stage')->where('code', 'delivered')->sole()->meta['from_documents']);
    }
}
