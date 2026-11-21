<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandScanResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_scan_id',
        'domain',
        'fuzzer',
        'dns_a',
        'dns_mx',
        'dns_ns',
        'urlscan_status',
        'urlscan_error',
        'urlscan_checked_at',
        'urlscan_uuid',
        'urlscan_malicious',
        'urlscan_score',
        'urlscan_ip',
        'urlscan_country',
        'urlscan_screenshot',
        'urlscan_report_url',
    ];

    protected function casts(): array
    {
        return [
            'dns_a' => 'array',
            'dns_mx' => 'array',
            'dns_ns' => 'array',
            'urlscan_malicious' => 'boolean',
            'urlscan_score' => 'integer',
            'urlscan_checked_at' => 'datetime',
        ];
    }

    public function brandScan()
    {
        return $this->belongsTo(BrandScan::class);
    }
}
