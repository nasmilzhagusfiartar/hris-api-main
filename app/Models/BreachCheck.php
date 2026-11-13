<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BreachCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'is_breached',
        'breaches',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_breached' => 'boolean',
            'breaches' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
