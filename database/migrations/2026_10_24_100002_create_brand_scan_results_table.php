<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('brand_scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_scan_id')->constrained('brand_scans')->cascadeOnDelete();
            $table->string('domain')->index();
            $table->string('fuzzer');
            $table->json('dns_a')->nullable();
            $table->json('dns_mx')->nullable();
            $table->json('dns_ns')->nullable();
            $table->string('urlscan_status')->nullable()->index();
            $table->string('urlscan_uuid')->nullable()->index();
            $table->boolean('urlscan_malicious')->nullable();
            $table->integer('urlscan_score')->nullable();
            $table->string('urlscan_ip')->nullable();
            $table->string('urlscan_country')->nullable();
            $table->text('urlscan_screenshot')->nullable();
            $table->text('urlscan_report_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_scan_results');
    }
};
