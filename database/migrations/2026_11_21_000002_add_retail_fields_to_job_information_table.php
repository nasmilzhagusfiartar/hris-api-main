<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_information', function (Blueprint $table) {
            $table->string('department', 100)->nullable()->index()->after('team_id');
            $table->foreignId('store_id')->nullable()->after('department')->constrained('stores')->nullOnDelete();
            $table->string('vendor_name', 255)->nullable()->after('employment_type');
        });

        // Safe non-destructive mapping of legacy software departments on teams table to retail structure
        $legacyDepartmentMap = [
            'development' => 'it',
            'design' => 'marketing',
            'support' => 'store_operations',
            'management' => 'general_affairs',
        ];

        foreach ($legacyDepartmentMap as $old => $new) {
            DB::table('teams')->where('department', $old)->update(['department' => $new]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_information', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn(['department', 'vendor_name']);
        });
    }
};
