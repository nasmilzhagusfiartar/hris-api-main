<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandScanResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'fuzzer' => $this->fuzzer,
            'dns_a' => is_array($this->dns_a) ? array_values($this->dns_a) : [],
            'dns_mx' => is_array($this->dns_mx) ? array_values($this->dns_mx) : [],
            'dns_ns' => is_array($this->dns_ns) ? array_values($this->dns_ns) : [],
            'urlscan_status' => $this->urlscan_status,
            'urlscan_uuid' => $this->urlscan_uuid,
            'urlscan_malicious' => $this->urlscan_malicious !== null ? (bool) $this->urlscan_malicious : null,
            'urlscan_score' => $this->urlscan_score !== null ? (int) $this->urlscan_score : null,
            'urlscan_ip' => $this->urlscan_ip,
            'urlscan_country' => $this->urlscan_country,
            'urlscan_screenshot' => $this->urlscan_screenshot,
            'urlscan_report_url' => $this->urlscan_report_url,
        ];
    }
}
