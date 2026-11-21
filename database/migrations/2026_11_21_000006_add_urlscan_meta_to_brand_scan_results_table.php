<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_scan_results', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_scan_results', 'urlscan_error')) {
                $table->text('urlscan_error')->nullable()->after('urlscan_status');
            }
            if (! Schema::hasColumn('brand_scan_results', 'urlscan_checked_at')) {
                $table->timestamp('urlscan_checked_at')->nullable()->after('urlscan_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_scan_results', function (Blueprint $table) {
            if (Schema::hasColumn('brand_scan_results', 'urlscan_checked_at')) {
                $table->dropColumn('urlscan_checked_at');
            }
            if (Schema::hasColumn('brand_scan_results', 'urlscan_error')) {
                $table->dropColumn('urlscan_error');
            }
        });
    }
};
