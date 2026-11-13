<?php

namespace App\Interfaces;

interface DashboardRepositoryInterface
{
    public function getStatistics();
    public function getEmployeeStatistics(int $employeeId);
    public function getQuickActionCounts($user): array;
}
