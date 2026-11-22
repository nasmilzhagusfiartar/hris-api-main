<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_information') && Schema::hasColumn('job_information', 'work_location')) {
            DB::table('job_information')
                ->where('work_location', 'hybrid')
                ->update(['work_location' => 'event']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_information') && Schema::hasColumn('job_information', 'work_location')) {
            DB::table('job_information')
                ->where('work_location', 'event')
                ->update(['work_location' => 'hybrid']);
        }
    }
};
