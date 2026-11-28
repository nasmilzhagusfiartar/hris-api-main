<?php

namespace App\Jobs;

use App\Interfaces\BreachCheckRepositoryInterface;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckAllBreachesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 840;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('scans');
    }

    /**
     * Execute the job.
     */
    public function handle(BreachCheckRepositoryInterface $breachCheckRepository): void
    {
        try {
            Log::info('Starting breach check for all users');

            $users = User::whereNotNull('email')->get(['id', 'email']);
            $total = $users->count();

            Log::info("Found {$total} users to check for breaches");

            $checked = 0;
            foreach ($users as $user) {
                try {
                    $breachCheckRepository->checkEmail($user->email);
                    $checked++;
                } catch (\Throwable $e) {
                    Log::error("Failed to check breach for user {$user->id} ({$user->email}): " . $e->getMessage());
                }

                // Rate limiting delay >= 1.1 detik per email (1.2s = 1200000 microseconds)
                usleep(1200000);
            }

            Log::info("Breach check for all users completed. Successfully checked: {$checked}/{$total}");
        } catch (\Throwable $e) {
            Log::error('CheckAllBreachesJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('CheckAllBreachesJob marked as failed', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
