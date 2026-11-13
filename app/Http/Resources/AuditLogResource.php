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

        // 1. Failed Login
        if (stripos($cleanPath, '/login') !== false && $statusCode >= 400) {
            return [
                'type' => 'failed_login',
                'label' => 'Login Gagal',
                'description' => "Percobaan login gagal (HTTP {$statusCode})",
                'module' => 'Autentikasi',
            ];
        }

        // 2. Successful Login
        if (stripos($cleanPath, '/login') !== false && $method === 'POST') {
            return [
                'type' => 'login',
                'label' => 'Login',
                'description' => 'Login berhasil ke dalam sistem HIP HRIS',
                'module' => 'Autentikasi',
            ];
        }

        // 3. Logout
        if (stripos($cleanPath, '/logout') !== false && $method === 'POST') {
            return [
                'type' => 'logout',
                'label' => 'Logout',
                'description' => 'Logout / mengakhiri sesi pengguna',
                'module' => 'Autentikasi',
            ];
        }

        // 4. Delete actions
        if ($method === 'DELETE' || stripos($cleanPath, '/remove-member') !== false) {
            $desc = stripos($cleanPath, '/remove-member') !== false
                ? 'Menghapus anggota dari tim'
                : "Menghapus data pada modul {$module}";

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
            $desc = "Memperbarui data {$module}";
            if (stripos($cleanPath, '/approve/') !== false) {
                $desc = 'Menyetujui pengajuan cuti karyawan';
            } elseif (stripos($cleanPath, '/reject/') !== false) {
                $desc = 'Menolak pengajuan cuti karyawan';
            } elseif (stripos($cleanPath, '/mark-as-paid') !== false) {
                $desc = 'Menandai periode payroll sebagai telah dibayar';
            } elseif (stripos($cleanPath, '/assign-permissions') !== false) {
                $desc = 'Memperbarui hak akses (permission) role';
            } elseif (stripos($cleanPath, '/check-out') !== false) {
                $desc = 'Melakukan presensi pulang (Check-Out)';
            } elseif (preg_match('#/api/v1/me$#i', $cleanPath)) {
                $desc = 'Memperbarui profil akun pengguna';
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
            $desc = "Menambahkan data baru pada modul {$module}";
            if (stripos($cleanPath, '/check-in') !== false) {
                $desc = 'Melakukan presensi masuk (Check-In)';
            } elseif (stripos($cleanPath, '/add-member') !== false) {
                $desc = 'Menambahkan anggota ke dalam tim';
            } elseif (stripos($cleanPath, '/payrolls/generate') !== false) {
                $desc = 'Men-generate data payroll periode baru';
            } elseif (stripos($cleanPath, '/breach-checks/check-all') !== false) {
                $desc = 'Menjalankan scan kebocoran email seluruh karyawan';
            } elseif (stripos($cleanPath, '/breach-checks') !== false) {
                $desc = 'Mengecek kebocoran data email (Data Breach)';
            } elseif (stripos($cleanPath, '/brand-scans') !== false) {
                $desc = 'Menjalankan pemindaian domain Brand Protection';
            } elseif (stripos($cleanPath, '/urlscan') !== false) {
                $desc = 'Menjalankan analisis URLScan pada domain';
            }

            return [
                'type' => 'create',
                'label' => 'Create',
                'description' => $desc,
                'module' => $module,
            ];
        }

        // 7. Read / Access actions
        $desc = "Mengakses data {$module}";
        if (stripos($cleanPath, '/export-excel') !== false) {
            $desc = 'Mengunduh laporan Excel Payroll';
        } elseif (stripos($cleanPath, '/search') !== false) {
            $desc = 'Melakukan pencarian global di dashboard';
        } elseif (stripos($cleanPath, '/me') !== false) {
            $desc = 'Memverifikasi sesi pengguna aktif';
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
            '/employees' => 'Karyawan',
            '/my-profile' => 'Profil Karyawan',
            '/teams' => 'Tim & Departemen',
            '/my-team' => 'Tim Saya',
            '/projects' => 'Proyek',
            '/project-tasks' => 'Tugas Proyek',
            '/attendances' => 'Absensi',
            '/my-attendance' => 'Absensi Saya',
            '/leave-requests' => 'Cuti & Izin',
            '/my-leave-requests' => 'Cuti Saya',
            '/payrolls' => 'Payroll',
            '/payroll-details' => 'Detail Payroll',
            '/roles' => 'Role & RBAC',
            '/permissions' => 'Permission',
            '/cti/breach-checks' => 'Email Breach (CTI)',
            '/cti/brand-scan' => 'Brand Protection (CTI)',
            '/dashboard' => 'Dashboard',
            '/search' => 'Pencarian Global',
            '/options' => 'Referensi Sistem',
            '/me' => 'Sesi & Profil',
        ];

        foreach ($map as $prefix => $label) {
            if (stripos($path, $prefix) !== false) {
                return $label;
            }
        }

        return 'Sistem';
    }
}

