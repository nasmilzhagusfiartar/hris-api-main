<?php

namespace App\Services;

use App\Exceptions\UrlscanException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UrlscanService
{
    public const UNRESOLVABLE_MESSAGE = 'Domain could not be resolved. It may be unregistered or inactive, so it cannot be scanned.';

    public const RATE_LIMITED_MESSAGE = 'Scan limit reached. Please try again later.';

    public const MISCONFIGURED_MESSAGE = 'Scan service is not configured correctly.';

    public const UNAVAILABLE_MESSAGE = 'Scan service is temporarily unavailable. Please try again later.';

    protected string $apiKey;

    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = trim((string) config('osint.urlscan.api_key', ''));
        $this->baseUrl = rtrim((string) config('osint.urlscan.base_url', 'https://urlscan.io/api/v1'), '/');
    }

    /**
     * Check whether a domain resolves to at least one A or AAAA DNS record.
     */
    public function domainResolvesDns(string $domain): bool
    {
        $cleanDomain = strtolower(trim($domain));
        if ($cleanDomain === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $cleanDomain)) {
            return false;
        }

        $records = @dns_get_record($cleanDomain, DNS_A | DNS_AAAA);

        return is_array($records) && count($records) > 0;
    }

    /**
     * Submit domain to urlscan.io.
     *
     * @return array{uuid: string, report_url: string, screenshot: string}
     *
     * @throws UrlscanException
     */
    public function submitScan(string $domain): array
    {
        if ($this->apiKey === '') {
            Log::error('urlscan.io API key is not configured.', ['domain' => $domain]);
            throw new UrlscanException(
                self::MISCONFIGURED_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR
            );
        }

        $url = $this->baseUrl.'/scan/';

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'url' => "https://{$domain}",
                    'visibility' => 'public',
                ]);
        } catch (ConnectionException $e) {
            Log::error("urlscan.io connection error for {$domain}", [
                'error' => $e->getMessage(),
            ]);
            throw new UrlscanException(
                self::UNAVAILABLE_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR,
                null,
                null,
                $e
            );
        }

        if (! $response->successful()) {
            $this->handleErrorResponse($response, 'submit', $domain);
        }

        $uuid = $response->json('uuid');
        if (! is_string($uuid) || trim($uuid) === '') {
            Log::error("urlscan.io did not return a valid uuid for {$domain}", [
                'status' => $response->status(),
            ]);
            throw new UrlscanException(
                self::UNAVAILABLE_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR
            );
        }

        return [
            'uuid' => $uuid,
            'report_url' => "https://urlscan.io/result/{$uuid}/",
            'screenshot' => "https://urlscan.io/screenshots/{$uuid}.png",
        ];
    }

    /**
     * Fetch scan results from urlscan.io.
     *
     * @return array{status: string, ip?: string|null, country?: string|null, malicious?: bool|null, score?: int|null}
     *
     * @throws UrlscanException
     */
    public function fetchResult(string $uuid): array
    {
        if ($this->apiKey === '') {
            Log::error('urlscan.io API key is not configured.', ['uuid' => $uuid]);
            throw new UrlscanException(
                self::MISCONFIGURED_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR
            );
        }

        $url = $this->baseUrl.'/result/'.urlencode($uuid).'/';

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'API-Key' => $this->apiKey,
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::error("urlscan.io connection error fetching result for uuid {$uuid}", [
                'error' => $e->getMessage(),
            ]);
            throw new UrlscanException(
                self::UNAVAILABLE_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR,
                null,
                null,
                $e
            );
        }

        // HTTP 404 = still processing (keep pending)
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

        $this->handleErrorResponse($response, 'fetch', $uuid);
    }

    /**
     * Map urlscan.io non-2xx responses to structured UrlscanException without leaking raw third-party internals.
     *
     * @throws UrlscanException
     */
    private function handleErrorResponse(Response $response, string $operation, string $target): never
    {
        $status = $response->status();
        $apiMessage = trim((string) ($response->json('message') ?? ''));
        $apiDescription = trim((string) ($response->json('description') ?? ''));

        Log::warning("urlscan.io {$operation} returned HTTP {$status}", [
            'target' => $target,
            'status' => $status,
            'api_message' => $apiMessage,
            'api_description' => $apiDescription,
        ]);

        if ($status === 401 || $status === 403) {
            throw new UrlscanException(
                self::MISCONFIGURED_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR
            );
        }

        if ($status === 429) {
            $retryAfter = $this->extractRetryAfterSeconds($response);
            $message = $retryAfter !== null && $retryAfter > 0
                ? "Scan limit reached. Please try again in {$retryAfter} seconds."
                : self::RATE_LIMITED_MESSAGE;

            throw new UrlscanException(
                $message,
                429,
                UrlscanException::STATUS_RATE_LIMITED,
                $retryAfter
            );
        }

        if ($status === 400 || $status === 422) {
            $combined = strtolower($apiMessage.' '.$apiDescription);

            if (
                str_contains($combined, 'dns') ||
                str_contains($combined, 'could not resolve') ||
                str_contains($combined, 'nxdomain') ||
                str_contains($combined, 'name or service not known')
            ) {
                throw new UrlscanException(
                    self::UNRESOLVABLE_MESSAGE,
                    422,
                    UrlscanException::STATUS_UNRESOLVABLE
                );
            }

            $reason = $this->sanitizeRejectionReason($apiMessage, $apiDescription);

            throw new UrlscanException(
                $reason,
                422,
                UrlscanException::STATUS_REJECTED
            );
        }

        if ($status >= 500) {
            throw new UrlscanException(
                self::UNAVAILABLE_MESSAGE,
                503,
                UrlscanException::STATUS_ERROR
            );
        }

        throw new \RuntimeException("Unexpected urlscan.io response status {$status}.");
    }

    private function extractRetryAfterSeconds(Response $response): ?int
    {
        foreach (['Retry-After', 'X-Rate-Limit-Reset-After', 'X-RateLimit-Reset-After'] as $header) {
            $value = $response->header($header);
            if ($value !== null && $value !== '' && is_numeric(trim((string) $value))) {
                $seconds = (int) ceil((float) trim((string) $value));
                if ($seconds > 0) {
                    return $seconds;
                }
            }
        }

        return null;
    }

    private function sanitizeRejectionReason(string $apiMessage, string $apiDescription): string
    {
        $raw = $apiDescription !== '' ? $apiDescription : $apiMessage;
        $clean = trim(strip_tags($raw));

        if ($clean === '') {
            return 'Domain was rejected by the scan service.';
        }

        if (mb_strlen($clean) > 220) {
            $clean = mb_substr($clean, 0, 217).'...';
        }

        return $clean;
    }
}
