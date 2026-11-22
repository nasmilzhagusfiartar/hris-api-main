<?php

namespace App\Interfaces;

interface EmployeeProfileRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?string $status,
        ?string $type,
        ?string $workLocation,
        ?string $projectId,
        ?int $limit,
        bool $execute,
        ?string $department = null,
        ?string $storeId = null,
        ?string $vendorName = null
    );

    public function getAllPaginated(
        ?string $search,
        ?string $status,
        ?string $type,
        ?string $workLocation,
        ?string $projectId,
        int $rowPerPage,
        ?string $department = null,
        ?string $storeId = null,
        ?string $vendorName = null
    );

    public function getById(
        string $id
    );

    public function getMyProfile();

    public function create(
        array $data
    );

    public function update(
        string $id,
        array $data
    );

    public function delete(
        string $id
    );

    public function getStatistics(?int $storeId = null);

    public function getPerformanceStatistics(string $employeeId);

    public function getMyTeam();

    public function getMyTeamMembers();

    public function getMyTeamProjects();
}
