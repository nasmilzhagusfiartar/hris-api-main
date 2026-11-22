<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_information') && Schema::hasColumn('bank_information', 'account_type')) {
            Schema::table('bank_information', function (Blueprint $table) {
                $table->string('account_type')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_information') && Schema::hasColumn('bank_information', 'account_type')) {
            Schema::table('bank_information', function (Blueprint $table) {
                $table->string('account_type')->nullable(false)->default('savings')->change();
            });
        }
    }
};
