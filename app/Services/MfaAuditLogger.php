<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MfaAuditLogger
{
    /**
     * Record an MFA security event into the existing Audit Log (telescope_entries).
     *
     * Supported events:
     * mfa_enabled, mfa_disabled, mfa_success, mfa_failed,
     * mfa_recovery_used, mfa_locked, mfa_reset
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $event,
        ?User $actor,
        ?Request $request = null,
        array $meta = [],
        int $statusCode = 200
    ): void {
        if (! Schema::hasTable('telescope_entries')) {
            return;
        }

        try {
            $ipAddress = $request?->ip() ?: '127.0.0.1';
            $userAgent = (string) ($request?->userAgent() ?: 'CLI / System');
            $uri = $request ? ('/'.$request->path()) : '/artisan/mfa-reset';
            $method = $request ? strtoupper($request->method()) : 'CLI';

            $content = [
                'ip_address' => $ipAddress,
                'uri' => $uri,
                'method' => $method,
                'controller_action' => 'MFA::'.$event,
                'headers' => [
                    'user-agent' => $userAgent,
                ],
                'payload' => [],
                'response_status' => $statusCode,
                'user' => $actor ? [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ] : null,
                'mfa_event' => $event,
                'mfa_meta' => $meta,
            ];

            DB::table('telescope_entries')->insert([
                'uuid' => (string) Str::uuid(),
                'batch_id' => (string) Str::uuid(),
                'family_hash' => null,
                'should_display_on_index' => true,
                'type' => 'request',
                'content' => json_encode($content, JSON_UNESCAPED_SLASHES),
                'created_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never block auth/MFA flow if audit table insert fails
        }
    }
}
