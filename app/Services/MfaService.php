<?php

namespace App\Services;

use App\Exceptions\MfaVerificationException;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    private Google2FA $google2fa;

    public function __construct(
        private MfaAuditLogger $auditLogger
    ) {
        $this->google2fa = new Google2FA();
    }

    public function isGloballyEnabled(): bool
    {
        return (bool) config('mfa.enabled', true);
    }

    /**
     * Generate a temporary TOTP secret & local SVG QR code for user setup.
     *
     * @return array{secret: string, qr_code_svg: string, otpauth_url: string}
     */
    public function generateSetup(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $issuer = (string) config('mfa.issuer', 'HIP HRIS');
        $otpauthUrl = $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        Cache::put(
            $this->setupCacheKey($user->id),
            $secret,
            now()->addSeconds((int) config('mfa.setup_ttl_seconds', 900))
        );

        return [
            'secret' => $secret,
            'qr_code_svg' => $this->generateLocalSvgQrCode($otpauthUrl),
            'otpauth_url' => $otpauthUrl,
        ];
    }

    /**
     * Generate QR code strictly locally on the server as an SVG string.
     */
    public function generateLocalSvgQrCode(string $content): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220, 2),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);

        return $writer->writeString($content);
    }

    /**
     * Verify a 6-digit TOTP code with +/- 1 period window (+/- 30s).
     */
    public function verifyTotpCode(string $secret, string $code): bool
    {
        $normalized = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (! preg_match('/^\d{6}$/', $normalized)) {
            return false;
        }

        $window = (int) config('mfa.window', 1);

        return (bool) $this->google2fa->verifyKey($secret, $normalized, $window);
    }

    /**
     * Complete MFA enrollment: verify TOTP code against cached setup secret,
     * persist encrypted secret + 8 hashed recovery codes, return plain codes ONCE.
     *
     * @return array<int, string>
     */
    public function enable(User $user, string $code, ?Request $request = null): array
    {
        $pendingSecret = Cache::get($this->setupCacheKey($user->id));
        if (! is_string($pendingSecret) || $pendingSecret === '') {
            throw new \InvalidArgumentException('MFA setup session has expired. Please start setup again.', 422);
        }

        if (! $this->verifyTotpCode($pendingSecret, $code)) {
            throw new \InvalidArgumentException('Invalid verification code. Please check your authenticator app and try again.', 422);
        }

        $plainCodes = $this->generatePlainRecoveryCodes();
        $hashedCodes = array_map(static fn (string $plain): string => Hash::make($plain), $plainCodes);

        $user->forceFill([
            'mfa_secret' => $pendingSecret,
            'mfa_enabled_at' => now(),
            'mfa_recovery_codes' => $hashedCodes,
        ])->save();

        Cache::forget($this->setupCacheKey($user->id));

        $this->auditLogger->log('mfa_enabled', $user, $request, [
            'user_id' => $user->id,
            'email' => $user->email,
        ], 200);

        return $plainCodes;
    }

    /**
     * Disable MFA for the authenticated user (blocked if role is enforced).
     */
    public function disable(User $user, string $password, string $code, ?Request $request = null): void
    {
        if (! $user->hasMfaEnabled()) {
            throw new \InvalidArgumentException('MFA is not currently enabled on your account.', 422);
        }

        if ($user->requiresMfa()) {
            throw new \RuntimeException('MFA is enforced for your role and cannot be disabled.', 403);
        }

        if (! Hash::check($password, $user->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.', 422);
        }

        if (! $this->verifyTotpCode((string) $user->mfa_secret, $code)) {
            throw new \InvalidArgumentException('Invalid verification code.', 422);
        }

        $user->forceFill([
            'mfa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $this->auditLogger->log('mfa_disabled', $user, $request, [
            'user_id' => $user->id,
            'email' => $user->email,
        ], 200);
    }

    /**
     * Regenerate 8 recovery codes after verifying password + current TOTP code.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user, string $password, string $code): array
    {
        if (! $user->hasMfaEnabled()) {
            throw new \InvalidArgumentException('MFA must be enabled before regenerating recovery codes.', 422);
        }

        if (! Hash::check($password, $user->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.', 422);
        }

        if (! $this->verifyTotpCode((string) $user->mfa_secret, $code)) {
            throw new \InvalidArgumentException('Invalid verification code.', 422);
        }

        $plainCodes = $this->generatePlainRecoveryCodes();
        $hashedCodes = array_map(static fn (string $plain): string => Hash::make($plain), $plainCodes);

        $user->forceFill([
            'mfa_recovery_codes' => $hashedCodes,
        ])->save();

        return $plainCodes;
    }

    /**
     * Create a single-use, 5-minute temporary MFA login token in cache.
     */
    public function createLoginChallenge(User $user): string
    {
        $token = Str::random(64);
        $ttl = (int) config('mfa.token_ttl_seconds', 300);

        Cache::put($this->loginTokenCacheKey($token), [
            'user_id' => $user->id,
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl)->timestamp,
        ], now()->addSeconds($ttl));

        return $token;
    }

    /**
     * Check if a user is currently locked out due to 5 failed MFA attempts.
     *
     * @return int Remaining lockout minutes (0 if not locked).
     */
    public function getLockoutRemainingMinutes(int $userId): int
    {
        $lockedUntil = Cache::get($this->lockoutCacheKey($userId));
        if (! $lockedUntil) {
            return 0;
        }

        $remainingSeconds = (int) $lockedUntil - now()->timestamp;
        if ($remainingSeconds <= 0) {
            Cache::forget($this->lockoutCacheKey($userId));

            return 0;
        }

        return (int) ceil($remainingSeconds / 60);
    }

    /**
     * Verify a pending MFA login challenge using either a TOTP code or recovery code.
     *
     * @return array{user: User, used_recovery_code: bool}
     */
    public function verifyLoginChallenge(
        string $mfaToken,
        ?string $code,
        ?string $recoveryCode,
        ?Request $request = null
    ): array {
        $cacheKey = $this->loginTokenCacheKey($mfaToken);
        $challenge = Cache::get($cacheKey);

        if (! is_array($challenge) || empty($challenge['user_id'])) {
            throw new MfaVerificationException('Invalid or expired MFA token. Please sign in again.', 401);
        }

        $user = User::with(['roles', 'permissions'])->find($challenge['user_id']);
        if (! $user || ! $user->hasMfaEnabled()) {
            Cache::forget($cacheKey);
            throw new MfaVerificationException('Invalid MFA session. Please sign in again.', 401);
        }

        $lockoutRemaining = $this->getLockoutRemainingMinutes($user->id);
        if ($lockoutRemaining > 0) {
            Cache::forget($cacheKey);
            throw new MfaVerificationException(
                "Account is temporarily locked due to too many failed MFA attempts. Try again in {$lockoutRemaining} minute(s).",
                423,
                [
                    'locked' => true,
                    'remaining_attempts' => 0,
                    'lockout_minutes' => $lockoutRemaining,
                ]
            );
        }

        $verified = false;
        $usedRecoveryCode = false;

        if (! empty($recoveryCode)) {
            $verified = $this->consumeRecoveryCode($user, $recoveryCode);
            $usedRecoveryCode = $verified;
        } elseif (! empty($code)) {
            $verified = $this->verifyTotpCode((string) $user->mfa_secret, $code);
        }

        if ($verified) {
            // Single-use token: delete immediately
            Cache::forget($cacheKey);

            if ($usedRecoveryCode) {
                $this->auditLogger->log('mfa_recovery_used', $user, $request, [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'remaining_recovery_codes' => count($user->mfa_recovery_codes ?? []),
                ], 200);
            }

            $this->auditLogger->log('mfa_success', $user, $request, [
                'user_id' => $user->id,
                'email' => $user->email,
                'method' => $usedRecoveryCode ? 'recovery_code' : 'totp',
            ], 200);

            return [
                'user' => $user,
                'used_recovery_code' => $usedRecoveryCode,
            ];
        }

        // Handle failed attempt
        $maxAttempts = (int) config('mfa.max_attempts', 5);
        $attempts = ((int) ($challenge['attempts'] ?? 0)) + 1;
        $remaining = max(0, $maxAttempts - $attempts);

        $this->auditLogger->log('mfa_failed', $user, $request, [
            'user_id' => $user->id,
            'email' => $user->email,
            'attempts' => $attempts,
            'remaining_attempts' => $remaining,
        ], $attempts >= $maxAttempts ? 423 : 422);

        if ($attempts >= $maxAttempts) {
            $lockoutMinutes = (int) config('mfa.lockout_minutes', 15);
            Cache::forget($cacheKey);
            Cache::put(
                $this->lockoutCacheKey($user->id),
                now()->addMinutes($lockoutMinutes)->timestamp,
                now()->addMinutes($lockoutMinutes)
            );

            $this->auditLogger->log('mfa_locked', $user, $request, [
                'user_id' => $user->id,
                'email' => $user->email,
                'lockout_minutes' => $lockoutMinutes,
            ], 423);

            throw new MfaVerificationException(
                "Too many failed verification attempts. Your account has been locked for {$lockoutMinutes} minutes.",
                423,
                [
                    'locked' => true,
                    'remaining_attempts' => 0,
                    'lockout_minutes' => $lockoutMinutes,
                ]
            );
        }

        $expiresAt = (int) ($challenge['expires_at'] ?? now()->addSeconds(300)->timestamp);
        $ttlSeconds = max(10, $expiresAt - now()->timestamp);
        $challenge['attempts'] = $attempts;
        Cache::put($cacheKey, $challenge, now()->addSeconds($ttlSeconds));

        throw new MfaVerificationException(
            "Invalid verification code. {$remaining} attempt(s) remaining.",
            422,
            [
                'locked' => false,
                'remaining_attempts' => $remaining,
            ]
        );
    }

    /**
     * Reset MFA for a target user (Admin action or Artisan emergency command).
     */
    public function resetForUser(
        User $targetUser,
        ?User $performedBy = null,
        ?Request $request = null,
        string $via = 'admin_api'
    ): void {
        $targetUser->forceFill([
            'mfa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        Cache::forget($this->setupCacheKey($targetUser->id));
        Cache::forget($this->lockoutCacheKey($targetUser->id));

        $this->auditLogger->log('mfa_reset', $performedBy ?? $targetUser, $request, [
            'target_user_id' => $targetUser->id,
            'target_user_name' => $targetUser->name,
            'target_user_email' => $targetUser->email,
            'reset_by_id' => $performedBy?->id,
            'reset_by_name' => $performedBy?->name ?? 'Artisan CLI',
            'reset_by_email' => $performedBy?->email ?? 'cli@system',
            'via' => $via,
        ], 200);
    }

    /**
     * Verify and consume a single-use recovery code.
     */
    private function consumeRecoveryCode(User $user, string $inputCode): bool
    {
        $normalized = strtoupper(trim($inputCode));
        $hashedCodes = $user->mfa_recovery_codes ?? [];

        if (! is_array($hashedCodes) || empty($hashedCodes)) {
            return false;
        }

        foreach ($hashedCodes as $index => $hash) {
            if (is_string($hash) && Hash::check($normalized, $hash)) {
                unset($hashedCodes[$index]);
                $user->forceFill([
                    'mfa_recovery_codes' => array_values($hashedCodes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Generate 8 readable recovery codes (format: XXXX-XXXX).
     *
     * @return array<int, string>
     */
    private function generatePlainRecoveryCodes(): array
    {
        $count = (int) config('mfa.recovery_codes_count', 8);
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $part1 = strtoupper(Str::random(4));
            $part2 = strtoupper(Str::random(4));
            $codes[] = "{$part1}-{$part2}";
        }

        return $codes;
    }

    private function setupCacheKey(int $userId): string
    {
        return "mfa:setup:{$userId}";
    }

    private function loginTokenCacheKey(string $token): string
    {
        return "mfa:login:{$token}";
    }

    private function lockoutCacheKey(int $userId): string
    {
        return "mfa:lockout:{$userId}";
    }
}
