<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('password');
                $table->index('is_active');
            }
        });

        // Deactivate existing users whose EmployeeProfile is already soft-deleted (excluding super-admin)
        if (Schema::hasTable('employee_profiles')) {
            $softDeletedUserIds = DB::table('employee_profiles')
                ->whereNotNull('deleted_at')
                ->whereNotIn('user_id', function ($q) {
                    $q->select('user_id')
                        ->from('employee_profiles')
                        ->whereNull('deleted_at');
                })
                ->pluck('user_id')
                ->all();

            if (! empty($softDeletedUserIds)) {
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

                $targetIds = array_values(array_diff($softDeletedUserIds, $superAdminUserIds));
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
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_active')) {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            }
        });
    }
};
