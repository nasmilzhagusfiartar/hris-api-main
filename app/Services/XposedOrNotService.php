<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XposedOrNotService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('osint.xposedornot.base_url', 'https://api.xposedornot.com/v1'), '/');
    }

    /**
     * Check email against XposedOrNot API with 429 retry + backoff
     *
     * @param string $email
     * @return array{is_breached: bool, breaches: array<string>}
     */
    public function checkEmail(string $email): array
    {
        $url = $this->baseUrl . '/check-email/' . urlencode($email);

        $maxAttempts = 3;
        $attempt = 0;
        $backoffSeconds = 2;

        do {
            $attempt++;
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'HRIS-OSINT-Checker/1.0',
                    ])
                    ->get($url);

                if ($response->status() === 429) {
                    Log::warning("XposedOrNot rate limit (429) hit for email: {$email}. Attempt {$attempt} of {$maxAttempts}.");
                    if ($attempt < $maxAttempts) {
                        sleep($backoffSeconds);
                        $backoffSeconds *= 2;
                        continue;
                    }
                    throw new \Exception("XposedOrNot API rate limit exceeded after {$maxAttempts} attempts.");
                }

                // HTTP 404 = tidak ditemukan di breach (AMAN), bukan error.
                if ($response->status() === 404) {
                    return [
                        'is_breached' => false,
                        'breaches' => [],
                    ];
                }

                if ($response->successful()) {
                    $data = $response->json();
                    // Bocor: {"breaches": [["Breach1","Breach2"]]} -> ambil breaches[0]
                    $rawBreaches = $data['breaches'] ?? [];
                    if (!empty($rawBreaches) && isset($rawBreaches[0]) && is_array($rawBreaches[0])) {
                        $breaches = $rawBreaches[0];
                    } elseif (is_array($rawBreaches)) {
                        $breaches = $rawBreaches;
                    } else {
                        $breaches = [];
                    }

                    return [
                        'is_breached' => !empty($breaches),
                        'breaches' => array_values($breaches),
                    ];
                }

                throw new \Exception("XposedOrNot API returned status: " . $response->status());
            } catch (\Exception $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                sleep($backoffSeconds);
                $backoffSeconds *= 2;
            }
        } while ($attempt < $maxAttempts);

        return [
            'is_breached' => false,
            'breaches' => [],
        ];
    }
}
