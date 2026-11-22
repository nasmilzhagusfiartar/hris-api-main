<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_HEAD_OFFICE = 'head_office';

    public const TYPE_STORE = 'store';

    public const TYPES = [
        self::TYPE_HEAD_OFFICE,
        self::TYPE_STORE,
    ];

    public const BRANDS = [
        'Toys City',
        'Intex Indonesia',
    ];

    public const DEFAULT_STORE_TEAMS = [
        ['name' => 'Store Management', 'description' => 'Kepala Toko, Supervisor, Personalia, Satpam'],
        ['name' => 'Cashier', 'description' => 'Kepala Kasir, Kasir'],
        ['name' => 'Warehouse', 'description' => 'Staff Gudang'],
        ['name' => 'E-Commerce', 'description' => 'Staff E-Commerce'],
        ['name' => 'IT Support', 'description' => 'EDP / IT Support'],
        ['name' => 'Sales Counter', 'description' => 'Sales Counter (Vendor)'],
    ];

    public const STORE_JOB_TITLES = [
        'Kepala Toko',
        'Supervisor',
        'Kepala Kasir',
        'Personalia',
        'Staff Gudang',
        'Staff E-Commerce',
        'Satpam',
        'Kasir',
        'EDP / IT Support',
        'Sales Counter (Vendor)',
    ];

    protected $fillable = [
        'code',
        'type',
        'name',
        'brand',
        'city',
        'address',
        'is_active',
        'head_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'store_id');
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
