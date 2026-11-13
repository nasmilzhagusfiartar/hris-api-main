<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UrlscanService
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('osint.urlscan.api_key', '');
        $this->baseUrl = rtrim(config('osint.urlscan.base_url', 'https://urlscan.io/api/v1'), '/');
    }

    /**
     * Submit domain to urlscan.io
     *
     * @param string $domain
     * @return array{uuid: string, report_url: string, screenshot: string}
     */
    public function submitScan(string $domain): array
    {
        $url = $this->baseUrl . '/scan/';

        $response = Http::timeout(20)
            ->withHeaders([
                'API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, [
                'url' => "https://{$domain}",
                'visibility' => 'public',
            ]);

        if (!$response->successful()) {
            Log::error("urlscan.io submit scan failed for {$domain}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("urlscan.io submission failed: " . ($response->json('message') ?? $response->body()));
        }

        $uuid = $response->json('uuid');
        if (!$uuid) {
            throw new \Exception("urlscan.io did not return a valid uuid");
        }

        return [
            'uuid' => $uuid,
            'report_url' => "https://urlscan.io/result/{$uuid}/",
            'screenshot' => "https://urlscan.io/screenshots/{$uuid}.png",
        ];
    }

    /**
     * Fetch scan results from urlscan.io
     *
     * @param string $uuid
     * @return array{status: string, ip?: string|null, country?: string|null, malicious?: bool|null, score?: int|null}
     */
    public function fetchResult(string $uuid): array
    {
        $url = $this->baseUrl . '/result/' . urlencode($uuid) . '/';

        $response = Http::timeout(20)
            ->withHeaders([
                'API-Key' => $this->apiKey,
            ])
            ->get($url);

        // HTTP 404 = masih diproses (tetap pending)
        if ($response->status() === 404) {
            return [
                'status' => 'pending',
            ];
        }

        if ($response->successful()) {
            $data = $response->json();

            $ip = $data['page']['ip'] ?? null;
            $country = $data['page']['country'] ?? null;
            $malicious = $data['verdicts']['overall']['malicious'] ?? false;
            $score = $data['verdicts']['overall']['score'] ?? 0;

            return [
                'status' => 'done',
                'ip' => $ip,
                'country' => $country,
                'malicious' => (bool) $malicious,
                'score' => (int) $score,
            ];
        }

        Log::error("urlscan.io fetch result failed for uuid {$uuid}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new \Exception("urlscan.io fetch result failed with status: " . $response->status());
    }
}
