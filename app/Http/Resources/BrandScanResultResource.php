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
        $dnsA = is_array($this->dns_a) ? array_values($this->dns_a) : [];
        $dnsMx = is_array($this->dns_mx) ? array_values($this->dns_mx) : [];
        $dnsNs = is_array($this->dns_ns) ? array_values($this->dns_ns) : [];
        $isResolvable = count($dnsA) > 0;
        $cooldownActive = in_array($this->urlscan_status, ['unresolvable', 'rejected'], true)
            && $this->urlscan_checked_at !== null
            && $this->urlscan_checked_at->gt(now()->subHours(24));

        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'fuzzer' => $this->fuzzer,
            'dns_a' => $dnsA,
            'dns_mx' => $dnsMx,
            'dns_ns' => $dnsNs,
            'is_resolvable' => $isResolvable,
            'urlscan_status' => $this->urlscan_status,
            'urlscan_error' => $this->urlscan_error,
            'urlscan_checked_at' => $this->urlscan_checked_at?->toIso8601String(),
            'urlscan_cooldown_active' => $cooldownActive,
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
