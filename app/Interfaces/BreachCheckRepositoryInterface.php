<?php

namespace App\Interfaces;

use App\Models\BreachCheck;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BreachCheckRepositoryInterface
{
    public function checkEmail(string $email): BreachCheck;

    public function checkAll(): int;

    public function getAllPaginated(?string $search, int $rowPerPage): LengthAwarePaginator;
}
