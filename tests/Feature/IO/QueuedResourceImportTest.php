<?php

namespace Tests\Feature\IO;

use App\Jobs\IO\RunResourceImportJob;
use App\Models\ImportRun;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsRoles;
use Tests\Fixtures\TestItem;
use Tests\Fixtures\TestItemSchema;
use Tests\TestCase;

/**
 * Queued imports. ImportProcessor::processRows() is a plain foreach; a big enough file
 * would hit the request timeout, and because the processor writes row by row, a timeout
 * would leave half the file imported with nobody told which half.
 *
 * The two claims that matter most, and are easiest to break later:
 *   A. UNDER the threshold, behaviour is EXACTLY what it was — synchronous, no job, no
 *      new dependency on a worker being up.
 *   B. A run never gets stuck in 'running': it settles on completed or failed, even
 *      when the worker dies.
 */
class QueuedResourceImportTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        TestItem::createTable();
        config(['io.resources' => [TestItemSchema::class]]);

        Storage::fake('local');

        $this->owner = User::factory()->create()->assignRole(Roles::MANAGER);
    }

    /** An items CSV with $count data rows. */
    private function csv(int $count): UploadedFile
    {
        $lines = ['sku,name,pages'];

        for ($i = 1; $i <= $count; $i++) {
            $lines[] = "BK-{$i},Book {$i},{$i}";
        }

        return UploadedFile::fake()->createWithContent('items.csv', implode("\n", $lines));
    }

    /** Run the job the way the queue would, with its dependencies injected. */
    private function work(int $runId): void
    {
        app()->call([new RunResourceImportJob($runId), 'handle']);
    }

    // ---- A. under the threshold, nothing changed -------------------------

    public function test_a_small_import_still_runs_inside_the_request(): void
    {
        Queue::fake();
        config(['io.queue_threshold' => 200]);

        $response = $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(3)]);

        $response->assertRedirect();
        Queue::assertNothingPushed();

        // Written, now, in the request — the pre-Phase-2 contract.
        $this->assertSame(3, TestItem::count());

        $run = ImportRun::latest('id')->first();
        $this->assertSame(ImportRun::COMPLETED, $run->status);
        $this->assertSame(3, $run->created_count);
    }

    public function test_a_small_preview_answers_in_the_response_and_writes_nothing(): void
    {
        Queue::fake();
        config(['io.queue_threshold' => 200]);

        $response = $this->actingAs($this->owner)
            ->post(route('io.preview', 'test-items'), ['file' => $this->csv(3)]);

        $response->assertOk()->assertJsonPath('created', 3);

        Queue::assertNothingPushed();
        $this->assertSame(0, TestItem::count());
        // A synchronous preview changed nothing, so it leaves no history row.
        $this->assertSame(0, ImportRun::count());
    }

    public function test_a_threshold_of_zero_disables_queuing_entirely(): void
    {
        // The escape hatch for an install with no worker running.
        Queue::fake();
        config(['io.queue_threshold' => 0]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        Queue::assertNothingPushed();
        $this->assertSame(10, TestItem::count());
    }

    // ---- Over the threshold, it queues ----------------------------------

    public function test_a_large_import_is_queued_and_writes_nothing_in_the_request(): void
    {
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('status', ImportRun::QUEUED)
            ->assertJsonPath('total_rows', 10);

        // The request itself imported nobody — that is the whole point.
        $this->assertSame(0, TestItem::count());

        $run = ImportRun::latest('id')->first();
        $this->assertSame(ImportRun::QUEUED, $run->status);
        $this->assertFalse($run->is_preview);
        $this->assertNotNull($run->upload_path);

        Bus::assertDispatched(RunResourceImportJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_the_preview_queues_on_the_same_threshold_as_the_commit(): void
    {
        // Queuing only the commit would MOVE the timeout, not remove it: a big file
        // times out on validation alone, which is where an answer is most needed.
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.preview', 'test-items'), ['file' => $this->csv(10)])
            ->assertStatus(202);

        $run = ImportRun::latest('id')->first();
        $this->assertTrue($run->is_preview);
        Bus::assertDispatched(RunResourceImportJob::class);
    }

    public function test_the_upload_is_stored_on_the_private_disk(): void
    {
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        // An import file is a spreadsheet of people's addresses and salaries. It must
        // never be reachable over HTTP.
        Storage::disk('local')->assertExists($run->upload_path);
        $this->assertStringStartsWith('imports/', $run->upload_path);
    }

    // ---- The job itself --------------------------------------------------

    public function test_the_job_imports_the_rows_and_settles_the_run(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        $this->work($run->id);

        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status);
        $this->assertSame(10, $run->created_count);
        $this->assertSame(10, $run->processed_rows);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(10, TestItem::count());
    }

    public function test_a_queued_preview_validates_without_writing(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.preview', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        $this->work($run->id);

        $run->refresh();
        $this->assertSame(ImportRun::COMPLETED, $run->status);
        $this->assertSame(10, $run->created_count);   // "would create"
        $this->assertSame(0, TestItem::count());  // but wrote nothing
    }

    public function test_the_job_stamps_the_actor_so_the_audit_trail_is_not_anonymous(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        // A worker starts with nobody authenticated, and every writer attributes its
        // rows (and its audit entries) to auth()->id().
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        auth()->logout();

        $this->work($run->id);

        $this->assertSame($this->owner->id, auth()->id());
    }

    public function test_a_run_whose_file_has_gone_fails_rather_than_hanging(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();
        Storage::disk('local')->delete($run->upload_path);

        $this->work($run->id);

        $run->refresh();
        $this->assertSame(ImportRun::FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertFalse($run->inProgress());
    }

    // ---- B. a run never gets stuck in 'running' -------------------------

    public function test_a_crashed_worker_leaves_the_run_failed_not_running(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();
        $run->forceFill(['status' => ImportRun::RUNNING])->save();

        // The queue's own failure hook — a timeout or an OOM kill.
        (new RunResourceImportJob($run->id))->failed(new \RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(ImportRun::FAILED, $run->status);
        $this->assertFalse($run->inProgress());
        $this->assertStringContainsString('worker died', json_encode($run->errors));
    }

    public function test_the_job_is_unique_per_run_so_a_double_submit_processes_once(): void
    {
        $job = new RunResourceImportJob(42);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());
        // Two different runs must BOTH proceed — uniqueness is per run, not per resource.
        $this->assertNotSame($job->uniqueId(), (new RunResourceImportJob(43))->uniqueId());
    }

    public function test_a_finished_run_is_not_reprocessed(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();
        $this->work($run->id);
        $this->assertSame(10, TestItem::count());

        // A retry of an already-settled run must not run the file again: the rows the
        // first pass wrote are deleted, and a second pass would write them back.
        TestItem::query()->delete();
        $this->work($run->id);
        $this->assertSame(0, TestItem::count());
    }

    // ---- Progress --------------------------------------------------------

    public function test_progress_advances_while_the_run_is_in_flight(): void
    {
        // The sync queue driver would otherwise run the job during the POST; these
        // tests drive it by hand so they can observe it.
        Bus::fake();
        config(['io.queue_threshold' => 5, 'io.progress_every' => 2]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        $this->work($run->id);

        $this->assertSame(10, $run->fresh()->processed_rows);
    }

    public function test_percent_never_reads_100_while_still_running(): void
    {
        $run = new ImportRun([
            'status' => ImportRun::RUNNING, 'total_rows' => 10, 'processed_rows' => 10,
        ]);

        // A bar that hits 100% and then keeps spinning reads as a hang.
        $this->assertSame(99, $run->progressPercent());
        $this->assertTrue($run->inProgress());
    }

    // ---- The status endpoint --------------------------------------------

    public function test_the_owner_can_poll_their_run(): void
    {
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        $this->actingAs($this->owner)
            ->getJson(route('io.run-status', $run))
            ->assertOk()
            ->assertJsonPath('status', ImportRun::QUEUED)
            ->assertJsonPath('in_progress', true);
    }

    public function test_somebody_else_cannot_read_your_import_errors(): void
    {
        Bus::fake();
        config(['io.queue_threshold' => 5]);

        $this->actingAs($this->owner)
            ->post(route('io.import', 'test-items'), ['file' => $this->csv(10)]);

        $run = ImportRun::latest('id')->first();

        $colleague = User::factory()->create()->assignRole(Roles::MANAGER);

        // Same permissions — still not their upload. Error messages quote row contents back.
        $this->actingAs($colleague)
            ->getJson(route('io.run-status', $run))
            ->assertForbidden();
    }

    public function test_a_poll_does_not_carry_the_error_blob_while_running(): void
    {
        $run = ImportRun::create([
            'user_id' => $this->owner->id,
            'resource' => 'test-items',
            'status' => ImportRun::RUNNING,
            'total_rows' => 100,
            'processed_rows' => 10,
            'errors' => [['row' => 2, 'messages' => ['nope']]],
        ]);

        // Polled every two seconds; the blob can be thousands of rows.
        $this->assertSame([], $run->toStatusArray()['errors']);

        $run->forceFill(['status' => ImportRun::COMPLETED])->save();
        $this->assertCount(1, $run->fresh()->toStatusArray()['errors']);
    }
}
