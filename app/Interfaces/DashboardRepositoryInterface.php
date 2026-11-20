<?php

namespace App\Interfaces;

interface DashboardRepositoryInterface
{
    public function getStatistics($user = null);
    public function getEmployeeStatistics(int $employeeId);
    public function getQuickActionCounts($user): array;
    public function getSecurityStatistics(): array;
}
