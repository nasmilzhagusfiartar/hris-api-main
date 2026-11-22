<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend stores table with type (head_office | store) and head_user_id (Kepala Toko)
        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                if (! Schema::hasColumn('stores', 'type')) {
                    $table->string('type', 30)->default('store')->after('code');
                    $table->index('type');
                }
                if (! Schema::hasColumn('stores', 'head_user_id')) {
                    $table->foreignId('head_user_id')
                        ->nullable()
                        ->after('is_active')
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }

        // 2. Extend teams table with store_id and make department & responsibilities nullable
        if (Schema::hasTable('teams')) {
            Schema::table('teams', function (Blueprint $table) {
                if (! Schema::hasColumn('teams', 'store_id')) {
                    $table->foreignId('store_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('stores')
                        ->nullOnDelete();
                }
                if (Schema::hasColumn('teams', 'department')) {
                    $table->string('department')->nullable()->change();
                }
                if (Schema::hasColumn('teams', 'responsibilities')) {
                    $table->json('responsibilities')->nullable()->change();
                }
            });
        }

        // 3. Ensure a single active Head Office location exists
        $headOfficeId = DB::table('stores')
            ->where('type', 'head_office')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $headOfficeId) {
            $headOfficeId = DB::table('stores')->insertGetId([
                'code' => 'HO-01',
                'type' => 'head_office',
                'name' => 'Head Office',
                'brand' => 'PT Hero Intiputra',
                'city' => 'Jakarta',
                'address' => 'Jakarta, Indonesia',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4. Move legacy teams without store_id into Head Office
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'store_id')) {
            DB::table('teams')
                ->whereNull('store_id')
                ->update(['store_id' => $headOfficeId]);
        }

        // 5. Place seeder accounts (super-admin, admin, hr, finance, security) in Head Office
        if (Schema::hasTable('users') && Schema::hasTable('employee_profiles') && Schema::hasTable('job_information')) {
            $hoEmails = [
                'superadmin@gmail.com',
                'admin@gmail.com',
                'hr@gmail.com',
                'finance@gmail.com',
                'security@gmail.com',
            ];

            $hoUserIds = DB::table('users')->whereIn('email', $hoEmails)->pluck('id');
            if ($hoUserIds->isNotEmpty()) {
                $hoEmpIds = DB::table('employee_profiles')->whereIn('user_id', $hoUserIds)->pluck('id');
                if ($hoEmpIds->isNotEmpty()) {
                    DB::table('job_information')
                        ->whereIn('employee_id', $hoEmpIds)
                        ->update(['store_id' => $headOfficeId]);
                }
            }

            // Manager: assign as Kepala Toko of first store if any store exists; otherwise Head Office
            $managerUserId = DB::table('users')->where('email', 'manager@gmail.com')->value('id');
            if ($managerUserId) {
                $firstStore = DB::table('stores')
                    ->where('type', 'store')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->first();

                $targetStoreId = $headOfficeId;
                if ($firstStore) {
                    $targetStoreId = $firstStore->id;
                    if (! $firstStore->head_user_id) {
                        DB::table('stores')
                            ->where('id', $firstStore->id)
                            ->update(['head_user_id' => $managerUserId]);
                    }
                }

                $mgrEmpId = DB::table('employee_profiles')->where('user_id', $managerUserId)->value('id');
                if ($mgrEmpId) {
                    DB::table('job_information')
                        ->where('employee_id', $mgrEmpId)
                        ->update(['store_id' => $targetStoreId]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'store_id')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropConstrainedForeignId('store_id');
            });
        }

        if (Schema::hasTable('stores')) {
            Schema::table('stores', function (Blueprint $table) {
                if (Schema::hasColumn('stores', 'head_user_id')) {
                    $table->dropConstrainedForeignId('head_user_id');
                }
                if (Schema::hasColumn('stores', 'type')) {
                    $table->dropIndex(['type']);
                    $table->dropColumn('type');
                }
            });
        }
    }
};
