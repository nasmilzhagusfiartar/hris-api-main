<?php

namespace App\Repositories;

use App\Interfaces\BreachCheckRepositoryInterface;
use App\Jobs\CheckAllBreachesJob;
use App\Models\BreachCheck;
use App\Models\User;
use App\Services\XposedOrNotService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BreachCheckRepository implements BreachCheckRepositoryInterface
{
    protected XposedOrNotService $xposedOrNotService;

    public function __construct(XposedOrNotService $xposedOrNotService)
    {
        $this->xposedOrNotService = $xposedOrNotService;
    }

    public function checkEmail(string $email): BreachCheck
    {
        $result = $this->xposedOrNotService->checkEmail($email);

        $user = User::where('email', $email)->first();

        return BreachCheck::updateOrCreate(
            ['email' => $email],
            [
                'user_id' => $user?->id,
                'is_breached' => $result['is_breached'],
                'breaches' => $result['breaches'],
                'checked_at' => now(),
            ]
        );
    }

    public function checkAll(): int
    {
        $count = User::whereNotNull('email')->count();

        CheckAllBreachesJob::dispatch();

        return $count;
    }

    public function getAllPaginated(?string $search, int $rowPerPage, ?string $onlyEmail = null): LengthAwarePaginator
    {
        return BreachCheck::query()
            ->when($onlyEmail !== null, function ($query) use ($onlyEmail) {
                $query->whereRaw('LOWER(email) = LOWER(?)', [trim($onlyEmail)]);
            })
            ->when($search, function ($query, $search) {
                $query->where('email', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($rowPerPage);
    }
}
