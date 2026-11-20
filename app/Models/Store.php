<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    public const BRANDS = [
        'Toys City',
        'Intex Indonesia',
    ];

    protected $fillable = [
        'code',
        'name',
        'brand',
        'city',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function jobInformations()
    {
        return $this->hasMany(JobInformation::class, 'store_id');
    }

    public function activeJobInformations()
    {
        return $this->hasMany(JobInformation::class, 'store_id')
            ->where('status', 'active');
    }
}
