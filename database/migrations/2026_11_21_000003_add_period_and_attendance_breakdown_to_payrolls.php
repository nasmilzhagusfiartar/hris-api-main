<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            if (! Schema::hasColumn('payrolls', 'period_start')) {
                $table->date('period_start')->nullable()->after('salary_month');
            }
            if (! Schema::hasColumn('payrolls', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start');
            }
            if (! Schema::hasColumn('payrolls', 'pay_date')) {
                $table->date('pay_date')->nullable()->after('period_end');
            }
        });

        Schema::table('payroll_details', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_details', 'working_days')) {
                $table->unsignedSmallInteger('working_days')->default(0)->after('final_salary');
            }
            if (! Schema::hasColumn('payroll_details', 'late_days')) {
                $table->unsignedSmallInteger('late_days')->default(0)->after('attended_days');
            }
            if (! Schema::hasColumn('payroll_details', 'permission_days')) {
                $table->unsignedSmallInteger('permission_days')->default(0)->after('sick_days');
            }
            if (! Schema::hasColumn('payroll_details', 'leave_days')) {
                $table->unsignedSmallInteger('leave_days')->default(0)->after('permission_days');
            }
            if (! Schema::hasColumn('payroll_details', 'deduction_amount')) {
                $table->decimal('deduction_amount', 12, 2)->default(0)->after('absent_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $cols = ['working_days', 'late_days', 'permission_days', 'leave_days', 'deduction_amount'];
            $existing = array_filter($cols, fn ($col) => Schema::hasColumn('payroll_details', $col));
            if (! empty($existing)) {
                $table->dropColumn($existing);
            }
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $cols = ['period_start', 'period_end', 'pay_date'];
            $existing = array_filter($cols, fn ($col) => Schema::hasColumn('payrolls', $col));
            if (! empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
