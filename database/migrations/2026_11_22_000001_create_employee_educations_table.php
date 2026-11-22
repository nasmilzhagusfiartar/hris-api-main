<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_educations')) {
            return;
        }

        Schema::create('employee_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employee_profiles')->onDelete('cascade');
            $table->string('level', 30);
            $table->string('institution');
            $table->string('major')->nullable();
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->decimal('gpa', 3, 2)->nullable();
            $table->boolean('is_highest')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'is_highest']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_educations');
    }
};
