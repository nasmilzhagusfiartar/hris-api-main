<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'status',
        'total_found',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'total_found' => 'integer',
        ];
    }

    public function results()
    {
        return $this->hasMany(BrandScanResult::class);
    }
}
