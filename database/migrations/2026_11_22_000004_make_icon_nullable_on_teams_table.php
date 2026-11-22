<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'icon')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('icon')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'icon')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('icon')->nullable(false)->default('')->change();
            });
        }
    }
};
