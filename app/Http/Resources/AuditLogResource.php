<?php

namespace App\Http\Resources;

use App\Helpers\UserAgentParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AuditLogResource extends JsonResource
{
    /**
     * Indonesian short month names for "13 Nov 2026, 20:09 WIB" formatting.
     */
    private const MONTHS_ID = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = is_array($this->content)
            ? $this->content
            : (json_decode((string) $this->content, true) ?: []);

        $headers = $content['headers'] ?? [];
        $rawUserAgent = (string) ($headers['user-agent'] ?? $headers['User-Agent'] ?? '');
        $parsedDevice = UserAgentParser::parse($rawUserAgent, is_array($headers) ? $headers : []);

        // Extract IP address (prefer X-Forwarded-For / X-Real-IP if behind proxy, fallback to ip_address)
        $forwardedFor = $headers['x-forwarded-for'] ?? $headers['x-real-ip'] ?? null;
        $ipAddress = is_string($forwardedFor) && trim($forwardedFor) !== ''
            ? trim(explode(',', $forwardedFor)[0])
            : ($content['ip_address'] ?? '127.0.0.1');

        // Format timestamp in Asia/Jakarta (WIB): "13 Nov 2026, 20:09 WIB"
        $createdAtUtc = $this->created_at
            ? Carbon::parse($this->created_at, 'UTC')->setTimezone('Asia/Jakarta')
            : now('Asia/Jakarta');

        $monthName = self::MONTHS_ID[(int) $createdAtUtc->format('n')] ?? $createdAtUtc->format('M');
        $formattedTime = sprintf(
            '%s %s %s, %s WIB',
            $createdAtUtc->format('d'),
            $monthName,
            $createdAtUtc->format('Y'),
            $createdAtUtc->format('H:i')
        );

        // User resolution (enriched via controller map if available)
        $contentUser = $content['user'] ?? null;
        $payload = $content['payload'] ?? [];
        $enrichedUser = $this->enriched_user ?? null;

        $userId = $contentUser['id'] ?? ($enrichedUser['id'] ?? null);
        $userName = $enrichedUser['name']
            ?? $contentUser['name']
            ?? ($payload['email'] ?? 'Guest / Unauthenticated');
        $userEmail = $enrichedUser['email']
            ?? $contentUser['email']
            ?? ($payload['email'] ?? '-');
        $userRoles = $enrichedUser['roles'] ?? [];
        $primaryRole = ! empty($userRoles)
            ? strtoupper((string) $userRoles[0]) === 'HR'
                ? 'HR'
                : ucfirst((string) $userRoles[0])
            : ($userId ? 'User' : 'Guest');

        $profilePhoto = null;
        if (! empty($enrichedUser['profile_photo'])) {
            $rawPhoto = $enrichedUser['profile_photo'];
            if (filter_var($rawPhoto, FILTER_VALIDATE_URL)) {
                $profilePhoto = $rawPhoto;
            } else {
                $cleanPath = preg_replace('#^/?storage/#', '', ltrim($rawPhoto, '/'));
                $profilePhoto = Storage::disk('public')->url($cleanPath);
            }
        }

        // Classify activity
        $method = strtoupper((string) ($content['method'] ?? 'GET'));
        $uri = (string) ($content['uri'] ?? '/');
        $statusCode = (int) ($content['response_status'] ?? 200);
        $controllerAction = (string) ($content['controller_action'] ?? '');

        $activity = self::classifyActivity($method, $uri, $statusCode, $controllerAction, $content);

        return [
            'id' => $this->uuid ?? (string) $this->sequence,
            'sequence' => (int) ($this->sequence ?? 0),
            'accessed_at' => $createdAtUtc->toIso8601String(),
            'accessed_at_formatted' => $formattedTime,
            'user' => [
                'id' => $userId,
                'name' => $userName,
                'email' => $userEmail,
                'role' => $primaryRole,
                'roles' => $userRoles,
                'profile_photo' => $profilePhoto,
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $rawUserAgent,
            'device' => $parsedDevice,
            'activity' => $activity,
            'request' => [
                'method' => $method,
                'uri' => $uri,
                'controller_action' => $controllerAction ?: '-',
                'status_code' => $statusCode,
                'duration_ms' => isset($content['duration']) ? (int) $content['duration'] : null,
                'memory_mb' => isset($content['memory']) ? (float) $content['memory'] : null,
                'hostname' => $content['hostname'] ?? '-',
                'origin' => $headers['origin'] ?? $headers['referer'] ?? '-',
                'response_message' => is_array($content['response'] ?? null)
                    ? ($content['response']['message'] ?? null)
                    : null,
            ],
        ];
    }

    /**
     * Classify HTTP request into structured audit activity.
     *
     * @param  array<string, mixed>  $content
     * @return array{type: string, label: string, description: string, module: string}
     */
    public static function classifyActivity(
        string $method,
        string $uri,
        int $statusCode,
        string $controllerAction,
        array $content = []
    ): array {
        $cleanPath = parse_url($uri, PHP_URL_PATH) ?: $uri;
        $module = self::detectModule($cleanPath);

        // 0. Explicit MFA security events
        if (! empty($content['mfa_event'])) {
            $event = (string) $content['mfa_event'];
            $meta = is_array($content['mfa_meta'] ?? null) ? $content['mfa_meta'] : [];

            return match ($event) {
                'mfa_enabled' => [
                    'type' => 'mfa_enabled',
                    'label' => 'MFA Enabled',
                    'description' => 'User enabled TOTP Multi-Factor Authentication',
                    'module' => 'MFA / Authentication',
                ],
                'mfa_disabled' => [
                    'type' => 'mfa_disabled',
                    'label' => 'MFA Disabled',
                    'description' => 'User disabled TOTP Multi-Factor Authentication',
                    'module' => 'MFA / Authentication',
                ],
                'mfa_success' => [
                    'type' => 'mfa_success',
                    'label' => 'MFA Verified',
                    'description' => ($meta['method'] ?? 'totp') === 'recovery_code'
                        ? 'MFA verification succeeded using a recovery code'
                        : 'MFA verification succeeded via TOTP code',
                    'module' => 'MFA / Authentication',
                ],
                'mfa_failed' => [
                    'type' => 'mfa_failed',
                    'label' => 'MFA Failed',
                    'description' => 'Failed MFA verification attempt ('.($meta['remaining_attempts'] ?? 0).' attempt(s) remaining)',
                    'module' => 'MFA / Authentication',
                ],
                'mfa_recovery_used' => [
                    'type' => 'mfa_recovery_used',
                    'label' => 'Recovery Code Used',
                    'description' => 'User signed in with a one-time MFA recovery code ('.($meta['remaining_recovery_codes'] ?? 0).' remaining)',
                    'module' => 'MFA / Authentication',
                ],
                'mfa_locked' => [
                    'type' => 'mfa_locked',
                    'label' => 'MFA Locked',
                    'description' => 'Account locked for '.($meta['lockout_minutes'] ?? 15).' minutes after 5 failed MFA attempts',
                    'module' => 'MFA / Authentication',
                ],
                'mfa_reset' => [
                    'type' => 'mfa_reset',
                    'label' => 'MFA Reset',
                    'description' => 'MFA reset for '.($meta['target_user_email'] ?? 'user').' by '.($meta['reset_by_name'] ?? 'Admin').' ('.($meta['via'] ?? 'admin_api').')',
                    'module' => 'MFA / Authentication',
                ],
                default => [
                    'type' => $event,
                    'label' => 'MFA Event',
                    'description' => "MFA security event: {$event}",
                    'module' => 'MFA / Authentication',
                ],
            };
        }

        // 1. Failed Login
        if (stripos($cleanPath, '/login') !== false && $statusCode >= 400) {
            return [
                'type' => 'failed_login',
                'label' => 'Failed Login',
                'description' => "Failed login attempt (HTTP {$statusCode})",
                'module' => 'Authentication',
            ];
        }

        // 2. Successful Login
        if (stripos($cleanPath, '/login') !== false && $method === 'POST') {
            return [
                'type' => 'login',
                'label' => 'Login',
                'description' => 'Successfully logged in to HIP HRIS',
                'module' => 'Authentication',
            ];
        }

        // 3. Logout
        if (stripos($cleanPath, '/logout') !== false && $method === 'POST') {
            return [
                'type' => 'logout',
                'label' => 'Logout',
                'description' => 'Logged out / ended user session',
                'module' => 'Authentication',
            ];
        }

        // 4. Delete actions
        if ($method === 'DELETE' || stripos($cleanPath, '/remove-member') !== false) {
            $desc = stripos($cleanPath, '/remove-member') !== false
                ? 'Removed member from team'
                : "Deleted data in {$module} module";

            return [
                'type' => 'delete',
                'label' => 'Delete',
                'description' => $desc,
                'module' => $module,
            ];
        }

        // 5. Update actions
        if (
            in_array($method, ['PUT', 'PATCH'], true) ||
            ($method === 'POST' && preg_match('#/(approve|reject|mark-as-paid|assign-permissions|check-out)#i', $cleanPath))
        ) {
            $desc = "Updated {$module} data";
            if (stripos($cleanPath, '/approve/') !== false) {
                $desc = 'Approved employee leave request';
            } elseif (stripos($cleanPath, '/reject/') !== false) {
                $desc = 'Rejected employee leave request';
            } elseif (stripos($cleanPath, '/mark-as-paid') !== false) {
                $desc = 'Marked payroll period as paid';
            } elseif (stripos($cleanPath, '/assign-permissions') !== false) {
                $desc = 'Updated role permissions';
            } elseif (stripos($cleanPath, '/check-out') !== false) {
                $desc = 'Clocked out';
            } elseif (preg_match('#/api/v1/me$#i', $cleanPath)) {
                $desc = 'Updated user account profile';
            }

            return [
                'type' => 'update',
                'label' => 'Update',
                'description' => $desc,
                'module' => $module,
            ];
        }

        // 6. Create actions
        if ($method === 'POST') {
            $desc = "Created new data in {$module} module";
            if (stripos($cleanPath, '/check-in') !== false) {
                $desc = 'Clocked in';
            } elseif (stripos($cleanPath, '/add-member') !== false) {
                $desc = 'Added member to team';
            } elseif (stripos($cleanPath, '/payrolls/generate') !== false) {
                $desc = 'Generated new payroll period';
            } elseif (stripos($cleanPath, '/breach-checks/check-all') !== false) {
                $desc = 'Triggered bulk email breach scan for all employees';
            } elseif (stripos($cleanPath, '/breach-checks') !== false) {
                $desc = 'Checked email data breach status';
            } elseif (stripos($cleanPath, '/brand-scans') !== false) {
                $desc = 'Triggered Brand Protection domain scan';
            } elseif (stripos($cleanPath, '/urlscan') !== false) {
                $desc = 'Triggered urlscan analysis on domain';
            }

            return [
                'type' => 'create',
                'label' => 'Create',
                'description' => $desc,
                'module' => $module,
            ];
        }

        // 7. Read / Access actions
        $desc = "Accessed {$module} data";
        if (stripos($cleanPath, '/export-excel') !== false) {
            $desc = 'Downloaded Payroll Excel report';
        } elseif (stripos($cleanPath, '/search') !== false) {
            $desc = 'Performed global dashboard search';
        } elseif (stripos($cleanPath, '/me') !== false) {
            $desc = 'Verified active user session';
        }

        return [
            'type' => 'access',
            'label' => 'Access',
            'description' => $desc,
            'module' => $module,
        ];
    }

    private static function detectModule(string $path): string
    {
        $map = [
            '/employees' => 'Employee',
            '/my-profile' => 'Employee Profile',
            '/teams' => 'Teams & Departments',
            '/my-team' => 'My Team',
            '/projects' => 'Project',
            '/project-tasks' => 'Project Tasks',
            '/attendances' => 'Attendance',
            '/my-attendance' => 'My Attendance',
            '/leave-requests' => 'Leave Requests',
            '/my-leave-requests' => 'My Leave Requests',
            '/payrolls' => 'Payroll',
            '/payroll-details' => 'Payroll Details',
            '/roles' => 'Roles & RBAC',
            '/permissions' => 'Permissions',
            '/cti/breach-checks' => 'Email Breach (CTI)',
            '/cti/brand-scan' => 'Brand Protection (CTI)',
            '/dashboard' => 'Dashboard',
            '/search' => 'Global Search',
            '/options' => 'System Options',
            '/me' => 'Session & Profile',
        ];

        foreach ($map as $prefix => $label) {
            if (stripos($path, $prefix) !== false) {
                return $label;
            }
        }

        return 'System';
    }
}

