<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_information', function (Blueprint $table) {
            if (! Schema::hasColumn('job_information', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
                $table->index('end_date');
            }
        });

        // Deactivate existing users whose JobInformation status is inactive (resigned/inactive/terminated), excluding super-admin
        if (Schema::hasTable('job_information') && Schema::hasTable('employee_profiles')) {
            $inactiveUserIds = DB::table('job_information')
                ->join('employee_profiles', 'employee_profiles.id', '=', 'job_information.employee_id')
                ->whereIn('job_information.status', ['resigned', 'inactive', 'terminated'])
                ->whereNull('job_information.deleted_at')
                ->pluck('employee_profiles.user_id')
                ->all();

            if (! empty($inactiveUserIds)) {
                $superAdminUserIds = [];
                if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                    $superAdminRoleId = DB::table('roles')->where('name', 'super-admin')->value('id');
                    if ($superAdminRoleId) {
                        $superAdminUserIds = DB::table('model_has_roles')
                            ->where('role_id', $superAdminRoleId)
                            ->pluck('model_id')
                            ->all();
                    }
                }

                $targetIds = array_values(array_diff($inactiveUserIds, $superAdminUserIds));
                if (! empty($targetIds)) {
                    DB::table('users')
                        ->whereIn('id', $targetIds)
                        ->update(['is_active' => false]);

                    if (Schema::hasTable('personal_access_tokens')) {
                        DB::table('personal_access_tokens')
                            ->where('tokenable_type', \App\Models\User::class)
                            ->whereIn('tokenable_id', $targetIds)
                            ->delete();
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('job_information', function (Blueprint $table) {
            if (Schema::hasColumn('job_information', 'end_date')) {
                $table->dropIndex(['end_date']);
                $table->dropColumn('end_date');
            }
        });
    }
};
