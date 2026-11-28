<?php

namespace Tests\Feature;

use App\Console\Commands\FailStaleBrandScansCommand;
use App\Jobs\CheckAllBreachesJob;
use App\Jobs\RunBrandScanJob;
use App\Models\BrandScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class BrandScanQueueAndStaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $perm = Permission::firstOrCreate(['name' => 'cti.brand-protection.view', 'guard_name' => 'sanctum']);
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'sanctum']);
        $role->givePermissionTo($perm);

        $this->admin = User::factory()->create([
            'email' => 'cti-queue-admin@example.com',
            'is_active' => true,
        ]);
        $this->admin->assignRole($role);
    }

    public function test_failed_hook_marks_brand_scan_as_failed_with_safe_message_on_max_attempts_exceeded(): void
    {
        $scan = BrandScan::create([
            'domain' => 'herointiputra.com',
            'status' => 'running',
            'total_found' => 0,
        ]);

        $job = new RunBrandScanJob($scan->id);
        $job->failed(new MaxAttemptsExceededException('App\\Jobs\\RunBrandScanJob has been attempted too many times.'));

        $scan->refresh();
        $this->assertSame('failed', $scan->status);
        $this->assertSame(RunBrandScanJob::SAFE_TIMEOUT_MESSAGE, $scan->error);
        $this->assertStringNotContainsString('MaxAttemptsExceeded', (string) $scan->error);
    }

    public function test_process_timeout_marks_brand_scan_as_failed_without_internal_cli_details(): void
    {
        $scan = BrandScan::create([
            'domain' => 'herointiputra.com',
            'status' => 'pending',
            'total_found' => 0,
        ]);

        Process::fake(function () {
            $symfonyProcess = new SymfonyProcess(['dnstwist', 'herointiputra.com']);
            throw new ProcessTimedOutException($symfonyProcess, ProcessTimedOutException::TYPE_GENERAL);
        });

        $job = new RunBrandScanJob($scan->id);

        $caught = null;
        try {
            $job->handle();
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected a timeout exception to be rethrown.');
        $this->assertTrue(
            $caught instanceof ProcessTimedOutException ||
            $caught instanceof \Illuminate\Process\Exceptions\ProcessTimedOutException
        );

        $scan->refresh();
        $this->assertSame('failed', $scan->status);
        $this->assertSame(RunBrandScanJob::SAFE_TIMEOUT_MESSAGE, $scan->error);
        $this->assertStringNotContainsString('dnstwist', (string) $scan->error);
    }

    public function test_process_non_zero_exit_marks_brand_scan_as_failed_with_safe_message(): void
    {
        $scan = BrandScan::create([
            'domain' => 'herointiputra.com',
            'status' => 'pending',
            'total_found' => 0,
        ]);

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'Traceback (most recent call last): internal python stack', exitCode: 1),
        ]);

        $job = new RunBrandScanJob($scan->id);
        $job->handle();

        $scan->refresh();
        $this->assertSame('failed', $scan->status);
        $this->assertSame(RunBrandScanJob::SAFE_FAILED_MESSAGE, $scan->error);
        $this->assertStringNotContainsString('Traceback', (string) $scan->error);
    }

    public function test_heavy_scan_jobs_are_dispatched_to_scans_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/cti/brand-scans', [
            'domain' => 'herointiputra.com',
        ]);

        $response->assertStatus(202);

        Queue::assertPushedOn('scans', RunBrandScanJob::class);

        CheckAllBreachesJob::dispatch();
        Queue::assertPushedOn('scans', CheckAllBreachesJob::class);
    }

    public function test_stale_command_marks_old_pending_and_running_scans_as_failed(): void
    {
        $oldRunning = BrandScan::create([
            'domain' => 'herointiputra.com',
            'status' => 'running',
            'total_found' => 0,
        ]);
        $oldRunning->forceFill(['created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)])->saveQuietly();

        $oldPending = BrandScan::create([
            'domain' => 'hip.co.id',
            'status' => 'pending',
            'total_found' => 0,
        ]);
        $oldPending->forceFill(['created_at' => now()->subMinutes(16), 'updated_at' => now()->subMinutes(16)])->saveQuietly();

        $freshRunning = BrandScan::create([
            'domain' => 'toyscity.id',
            'status' => 'running',
            'total_found' => 0,
        ]);
        $freshRunning->forceFill(['created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5)])->saveQuietly();

        $oldDone = BrandScan::create([
            'domain' => 'example.com',
            'status' => 'done',
            'total_found' => 2,
        ]);
        $oldDone->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)])->saveQuietly();

        $this->artisan('brand-scans:fail-stale')
            ->assertExitCode(0);

        $oldRunning->refresh();
        $oldPending->refresh();
        $freshRunning->refresh();
        $oldDone->refresh();

        $this->assertSame('failed', $oldRunning->status);
        $this->assertSame(FailStaleBrandScansCommand::TIMEOUT_MESSAGE, $oldRunning->error);

        $this->assertSame('failed', $oldPending->status);
        $this->assertSame(FailStaleBrandScansCommand::TIMEOUT_MESSAGE, $oldPending->error);

        $this->assertSame('running', $freshRunning->status);
        $this->assertNull($freshRunning->error);

        $this->assertSame('done', $oldDone->status);
    }

    public function test_duplicate_scan_for_same_domain_while_running_returns_409_with_existing_scan(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $existing = BrandScan::create([
            'domain' => 'herointiputra.com',
            'status' => 'running',
            'total_found' => 0,
        ]);

        $conflict = $this->postJson('/api/v1/cti/brand-scans', [
            'domain' => 'HEROINtiPutra.com',
        ]);

        $conflict->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.domain', 'herointiputra.com')
            ->assertJsonPath('data.status', 'running');

        Queue::assertNothingPushed();

        // Different domain should still succeed
        $otherDomain = $this->postJson('/api/v1/cti/brand-scans', [
            'domain' => 'toyscity.id',
        ]);

        $otherDomain->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.domain', 'toyscity.id');

        Queue::assertPushedOn('scans', RunBrandScanJob::class);
    }
}
