<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Helpers\UserAgentParser;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\PaginateResource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Middleware\PermissionMiddleware;

class AuditLogController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(PermissionMiddleware::using(['audit-log.view']), only: ['index']),
        ];
    }
    /**
     * Get paginated audit user & session activity logs (read-only).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:150',
            'activity_type' => 'nullable|string|in:all,login,logout,failed_login,create,update,delete,access,mfa_enabled,mfa_disabled,mfa_success,mfa_failed,mfa_recovery_used,mfa_locked,mfa_reset,account_deactivated,account_reactivated',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'row_per_page' => 'nullable|integer|min:5|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        try {
            $perPage = (int) ($validated['row_per_page'] ?? 15);
            $search = isset($validated['search']) ? trim($validated['search']) : '';
            $activityType = $validated['activity_type'] ?? 'all';
            $startDate = $validated['start_date'] ?? null;
            $endDate = $validated['end_date'] ?? null;

            $query = $this->buildBaseQuery();

            // Apply date range filter (Asia/Jakarta WIB -> UTC in telescope_entries)
            if ($startDate) {
                $startUtc = Carbon::parse($startDate, 'Asia/Jakarta')->startOfDay()->setTimezone('UTC');
                $query->where('created_at', '>=', $startUtc->format('Y-m-d H:i:s'));
            }
            if ($endDate) {
                $endUtc = Carbon::parse($endDate, 'Asia/Jakarta')->endOfDay()->setTimezone('UTC');
                $query->where('created_at', '<=', $endUtc->format('Y-m-d H:i:s'));
            }

            // Apply search filter (User name, User email, Payload email, IP address, URI)
            if ($search !== '') {
                $like = '%'.$search.'%';

                // Also find matching user IDs by name/email in users table
                $matchingUserIds = User::query()
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->pluck('id')
                    ->all();

                $query->where(function (Builder $q) use ($like, $matchingUserIds) {
                    $q->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(content, '$.user.name'))) LIKE LOWER(?)", [$like])
                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(content, '$.user.email'))) LIKE LOWER(?)", [$like])
                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(content, '$.payload.email'))) LIKE LOWER(?)", [$like])
                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(content, '$.ip_address'))) LIKE LOWER(?)", [$like])
                        ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(content, '$.uri'))) LIKE LOWER(?)", [$like]);

                    if (! empty($matchingUserIds)) {
                        $placeholders = implode(',', array_fill(0, count($matchingUserIds), '?'));
                        $q->orWhereRaw(
                            "CAST(JSON_UNQUOTE(JSON_EXTRACT(content, '$.user.id')) AS UNSIGNED) IN ({$placeholders})",
                            $matchingUserIds
                        );
                    }
                });
            }

            // Apply activity type filter
            if ($activityType && $activityType !== 'all') {
                $this->applyActivityFilter($query, $activityType);
            }

            // Order newest first
            $paginator = $query
                ->orderByDesc('sequence')
                ->paginate($perPage);

            // Enrich current page items with User details (name, email, roles, profile_photo)
            $items = collect($paginator->items());
            $userIds = [];
            $emails = [];

            foreach ($items as $item) {
                $decoded = json_decode((string) $item->content, true) ?: [];
                $item->content = $decoded;

                if (! empty($decoded['user']['id'])) {
                    $userIds[] = (int) $decoded['user']['id'];
                }
                if (! empty($decoded['user']['email'])) {
                    $emails[] = strtolower((string) $decoded['user']['email']);
                }
                if (! empty($decoded['payload']['email']) && is_string($decoded['payload']['email'])) {
                    $emails[] = strtolower($decoded['payload']['email']);
                }
            }

            $usersById = [];
            $usersByEmail = [];

            if (! empty($userIds) || ! empty($emails)) {
                $users = User::with('roles')
                    ->where(function ($q) use ($userIds, $emails) {
                        if (! empty($userIds)) {
                            $q->whereIn('id', array_unique($userIds));
                        }
                        if (! empty($emails)) {
                            $q->orWhereIn('email', array_unique($emails));
                        }
                    })
                    ->get();

                foreach ($users as $u) {
                    $formatted = [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'profile_photo' => $u->profile_photo,
                        'roles' => $u->roles->pluck('name')->values()->all(),
                    ];
                    $usersById[$u->id] = $formatted;
                    $usersByEmail[strtolower($u->email)] = $formatted;
                }
            }

            foreach ($items as $item) {
                $decoded = $item->content;
                $uid = isset($decoded['user']['id']) ? (int) $decoded['user']['id'] : null;
                $uEmail = strtolower((string) ($decoded['user']['email'] ?? $decoded['payload']['email'] ?? ''));

                if ($uid && isset($usersById[$uid])) {
                    $item->enriched_user = $usersById[$uid];
                } elseif ($uEmail !== '' && isset($usersByEmail[$uEmail])) {
                    $item->enriched_user = $usersByEmail[$uEmail];
                } else {
                    $item->enriched_user = null;
                }
            }

            $paginatedData = PaginateResource::make($paginator, AuditLogResource::class)->toArray($request);

            // Attach current active session info (parsed on-the-fly)
            $currentUser = Auth::user()?->load('roles');
            $rawCurrentUa = (string) $request->userAgent();
            $flatHeaders = [];
            foreach ($request->headers->all() as $k => $v) {
                $flatHeaders[strtolower($k)] = is_array($v) ? ($v[0] ?? '') : $v;
            }

            $paginatedData['current_session'] = [
                'ip_address' => $request->ip() ?: '127.0.0.1',
                'user_agent' => $rawCurrentUa,
                'device' => UserAgentParser::parse($rawCurrentUa, $flatHeaders),
                'accessed_at_formatted' => Carbon::now('Asia/Jakarta')->format('d M Y, H:i').' WIB',
                'user' => $currentUser ? [
                    'id' => $currentUser->id,
                    'name' => $currentUser->name,
                    'email' => $currentUser->email,
                    'roles' => $currentUser->roles->pluck('name')->values()->all(),
                ] : null,
            ];

            return ResponseHelper::jsonResponse(
                true,
                'Audit logs retrieved successfully',
                $paginatedData,
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::serverError($e);
        }
    }

    /**
     * Cross-DB JSON string extraction expression (supports MySQL and SQLite).
     */
    private function jsonVal(string $path): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "json_extract(content, '{$path}')";
        }

        return "JSON_UNQUOTE(JSON_EXTRACT(content, '{$path}'))";
    }

    /**
     * Base query on telescope_entries for HTTP requests excluding preflight/polling noise.
     */
    private function buildBaseQuery(): Builder
    {
        $methodExpr = $this->jsonVal('$.method');
        $uriExpr = $this->jsonVal('$.uri');

        return DB::table('telescope_entries')
            ->where('type', 'request')
            ->whereRaw("{$methodExpr} != 'OPTIONS'")
            ->whereRaw("{$uriExpr} NOT LIKE '%/api/v1/health%'")
            ->whereRaw("{$uriExpr} NOT LIKE '%/cti/audit-logs%'");
    }

    /**
     * Filter query by classified activity type.
     */
    private function applyActivityFilter(Builder $query, string $activityType): void
    {
        $methodExpr = $this->jsonVal('$.method');
        $uriExpr = $this->jsonVal('$.uri');
        $statusExpr = DB::getDriverName() === 'sqlite'
            ? "CAST(json_extract(content, '$.response_status') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(content, '$.response_status')) AS UNSIGNED)";

        switch ($activityType) {
            case 'failed_login':
                $query->whereRaw("({$uriExpr} LIKE '%/login%' AND {$statusExpr} >= 400)");
                break;

            case 'login':
                $query->whereRaw("({$uriExpr} LIKE '%/login%' AND {$methodExpr} = 'POST' AND {$statusExpr} < 400)");
                break;

            case 'logout':
                $query->whereRaw("({$uriExpr} LIKE '%/logout%' AND {$methodExpr} = 'POST')");
                break;

            case 'delete':
                $query->whereRaw("({$methodExpr} = 'DELETE' OR {$uriExpr} LIKE '%/remove-member%')");
                break;

            case 'update':
                $query->whereRaw(
                    "({$methodExpr} IN ('PUT', 'PATCH') OR ({$methodExpr} = 'POST' AND ({$uriExpr} LIKE '%/approve/%' OR {$uriExpr} LIKE '%/reject/%' OR {$uriExpr} LIKE '%/mark-as-paid%' OR {$uriExpr} LIKE '%/assign-permissions%' OR {$uriExpr} LIKE '%/check-out%')))"
                );
                break;

            case 'create':
                $query->whereRaw(
                    "({$methodExpr} = 'POST' AND {$uriExpr} NOT LIKE '%/login%' AND {$uriExpr} NOT LIKE '%/logout%' AND {$uriExpr} NOT LIKE '%/remove-member%' AND {$uriExpr} NOT LIKE '%/approve/%' AND {$uriExpr} NOT LIKE '%/reject/%' AND {$uriExpr} NOT LIKE '%/mark-as-paid%' AND {$uriExpr} NOT LIKE '%/assign-permissions%' AND {$uriExpr} NOT LIKE '%/check-out%')"
                );
                break;

            case 'access':
                $query->whereRaw("{$methodExpr} = 'GET'");
                break;

            case 'mfa_enabled':
            case 'mfa_disabled':
            case 'mfa_success':
            case 'mfa_failed':
            case 'mfa_recovery_used':
            case 'mfa_locked':
            case 'mfa_reset':
            case 'account_deactivated':
            case 'account_reactivated':
                $mfaEventExpr = $this->jsonVal('$.mfa_event');
                $query->whereRaw("{$mfaEventExpr} = ?", [$activityType]);
                break;
        }
    }
}

