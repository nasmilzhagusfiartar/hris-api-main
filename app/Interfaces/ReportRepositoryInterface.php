<?php

namespace App\Interfaces;

use App\Models\User;

interface ReportRepositoryInterface
{
    public function getOverview(?User $user = null): array;

    public function getAttendanceReport(array $filters = [], ?User $user = null): array;

    public function getPayrollReport(array $filters = [], ?User $user = null): array;

    public function getEmployeeReport(array $filters = [], ?User $user = null): array;

    public function getActivityLog(?User $user = null): array;
}
