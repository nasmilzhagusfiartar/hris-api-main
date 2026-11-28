<?php

namespace App\Console\Commands;

use App\Models\BrandScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FailStaleBrandScansCommand extends Command
{
    public const TIMEOUT_MESSAGE = 'Scan timed out. Please try again.';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brand-scans:fail-stale {--minutes=15 : Mark pending/running brand scans older than this many minutes as failed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark stale pending or running brand scans as failed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $staleScans = BrandScan::query()
            ->whereIn('status', ['pending', 'running'])
            ->where('created_at', '<', $cutoff)
            ->get();

        $updated = 0;
        foreach ($staleScans as $scan) {
            $scan->update([
                'status' => 'failed',
                'error' => self::TIMEOUT_MESSAGE,
            ]);
            $updated++;
        }

        if ($updated > 0) {
            Log::warning("brand-scans:fail-stale marked {$updated} stale brand scan(s) as failed (cutoff: {$minutes}m).");
        }

        $this->info("Marked {$updated} stale brand scan(s) as failed.");

        return self::SUCCESS;
    }
}
