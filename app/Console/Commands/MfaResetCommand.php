<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MfaService;
use Illuminate\Console\Command;

class MfaResetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mfa:reset {email : The email address of the user whose MFA should be reset}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Emergency command to reset MFA and clear MFA lockout for a user by email';

    /**
     * Execute the console command.
     */
    public function handle(MfaService $mfaService): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' was not found.");

            return self::FAILURE;
        }

        $mfaService->resetForUser($user, null, null, 'artisan');

        $this->info("MFA has been reset and any MFA lockout cleared for {$user->name} ({$user->email}).");

        return self::SUCCESS;
    }
}
