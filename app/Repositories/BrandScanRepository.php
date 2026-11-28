<?php

namespace App\Repositories;

use App\Exceptions\BrandScanConflictException;
use App\Exceptions\UrlscanException;
use App\Interfaces\BrandScanRepositoryInterface;
use App\Jobs\RunBrandScanJob;
use App\Models\BrandScan;
use App\Models\BrandScanResult;
use App\Services\UrlscanService;

class BrandScanRepository implements BrandScanRepositoryInterface
{
    public const COOLDOWN_HOURS = 24;

    protected UrlscanService $urlscanService;

    public function __construct(UrlscanService $urlscanService)
    {
        $this->urlscanService = $urlscanService;
    }

    public function createScan(string $domain): BrandScan
    {
        $normalizedDomain = strtolower(trim($domain));

        $activeScan = BrandScan::with('results')
            ->where('domain', $normalizedDomain)
            ->whereIn('status', ['pending', 'running'])
            ->orderByDesc('id')
            ->first();

        if ($activeScan !== null) {
            throw new BrandScanConflictException($activeScan);
        }

        $brandScan = BrandScan::create([
            'domain' => $normalizedDomain,
            'status' => 'pending',
            'total_found' => 0,
        ]);

        RunBrandScanJob::dispatch($brandScan->id);

        return $brandScan;
    }

    public function getLatestScan(?string $domain = null): ?BrandScan
    {
        $query = BrandScan::with('results')->orderByDesc('id');

        if ($domain !== null && trim($domain) !== '') {
            $query->where('domain', strtolower(trim($domain)));
        }

        $brandScan = $query->first();

        if ($brandScan && $brandScan->relationLoaded('results')) {
            $this->backfillPreviousUrlscanResults($brandScan);
        }

        return $brandScan;
    }

    public function getScanById(string|int $id): BrandScan
    {
        $brandScan = BrandScan::with('results')->findOrFail($id);
        $this->backfillPreviousUrlscanResults($brandScan);

        return $brandScan;
    }

    /**
     * Backfill any missing urlscan intelligence from earlier scans of the same typosquatting domain.
     */
    private function backfillPreviousUrlscanResults(BrandScan $brandScan): void
    {
        $missingResults = $brandScan->results->filter(fn (BrandScanResult $r) => empty($r->urlscan_status));

        if ($missingResults->isEmpty()) {
            return;
        }

        $domains = $missingResults->pluck('domain')->unique()->values()->all();

        $previousScanned = BrandScanResult::query()
            ->whereIn('domain', $domains)
            ->where(function ($q) {
                $q->where(function ($done) {
                    $done->where('urlscan_status', 'done')
                        ->whereNotNull('urlscan_uuid');
                })->orWhere(function ($recent) {
                    $recent->whereIn('urlscan_status', [
                        UrlscanException::STATUS_UNRESOLVABLE,
                        UrlscanException::STATUS_REJECTED,
                    ])->where('urlscan_checked_at', '>=', now()->subHours(self::COOLDOWN_HOURS));
                });
            })
            ->orderByDesc('updated_at')
            ->get()
            ->keyBy('domain');

        if ($previousScanned->isEmpty()) {
            return;
        }

        foreach ($missingResults as $result) {
            $prev = $previousScanned->get($result->domain);
            if ($prev) {
                $result->update([
                    'urlscan_status' => $prev->urlscan_status,
                    'urlscan_error' => $prev->urlscan_error,
                    'urlscan_checked_at' => $prev->urlscan_checked_at,
                    'urlscan_uuid' => $prev->urlscan_uuid,
                    'urlscan_malicious' => $prev->urlscan_malicious,
                    'urlscan_score' => $prev->urlscan_score,
                    'urlscan_ip' => $prev->urlscan_ip,
                    'urlscan_country' => $prev->urlscan_country,
                    'urlscan_screenshot' => $prev->urlscan_screenshot,
                    'urlscan_report_url' => $prev->urlscan_report_url,
                ]);
            }
        }
    }

    public function submitUrlscan(string|int $resultId): BrandScanResult
    {
        $result = BrandScanResult::findOrFail($resultId);

        // 1. Cooldown check: do not re-scan unresolvable/rejected domains within 24 hours
        $cooldownCutoff = now()->subHours(self::COOLDOWN_HOURS);
        $recentBlocked = $this->findRecentUnscannableResult($result, $cooldownCutoff);

        if ($recentBlocked !== null) {
            $status = $recentBlocked->urlscan_status;
            $message = $recentBlocked->urlscan_error ?: (
                $status === UrlscanException::STATUS_UNRESOLVABLE
                    ? UrlscanService::UNRESOLVABLE_MESSAGE
                    : 'Domain was rejected by the scan service.'
            );

            if ($recentBlocked->id !== $result->id || $result->urlscan_status !== $status) {
                $result->update([
                    'urlscan_status' => $status,
                    'urlscan_error' => $message,
                    'urlscan_checked_at' => $recentBlocked->urlscan_checked_at ?? now(),
                ]);
            }

            throw new UrlscanException($message, 422, $status, null, $result->fresh());
        }

        // 2. Pre-flight DNS check (A/AAAA) to avoid wasting urlscan quota on unresolvable domains
        if (! $this->urlscanService->domainResolvesDns($result->domain)) {
            $result->update([
                'urlscan_status' => UrlscanException::STATUS_UNRESOLVABLE,
                'urlscan_error' => UrlscanService::UNRESOLVABLE_MESSAGE,
                'urlscan_checked_at' => now(),
            ]);

            throw new UrlscanException(
                UrlscanService::UNRESOLVABLE_MESSAGE,
                422,
                UrlscanException::STATUS_UNRESOLVABLE,
                null,
                $result->fresh()
            );
        }

        // 3. Submit to urlscan.io
        try {
            $submission = $this->urlscanService->submitScan($result->domain);
        } catch (UrlscanException $e) {
            if (in_array($e->scanStatus, [UrlscanException::STATUS_UNRESOLVABLE, UrlscanException::STATUS_REJECTED], true)) {
                $result->update([
                    'urlscan_status' => $e->scanStatus,
                    'urlscan_error' => $e->getMessage(),
                    'urlscan_checked_at' => now(),
                ]);

                throw $e->withResult($result->fresh());
            }

            throw $e;
        }

        $result->update([
            'urlscan_status' => 'pending',
            'urlscan_error' => null,
            'urlscan_checked_at' => now(),
            'urlscan_uuid' => $submission['uuid'],
            'urlscan_report_url' => $submission['report_url'],
            'urlscan_screenshot' => $submission['screenshot'],
        ]);

        return $result->fresh();
    }

    public function getUrlscan(string|int $resultId): BrandScanResult
    {
        $result = BrandScanResult::findOrFail($resultId);

        if (! $result->urlscan_uuid) {
            throw new UrlscanException(
                'URLScan has not been submitted yet for this result.',
                422,
                UrlscanException::STATUS_ERROR,
                null,
                $result
            );
        }

        $res = $this->urlscanService->fetchResult($result->urlscan_uuid);

        if (($res['status'] ?? '') === 'done') {
            $result->update([
                'urlscan_status' => 'done',
                'urlscan_error' => null,
                'urlscan_checked_at' => now(),
                'urlscan_ip' => $res['ip'] ?? null,
                'urlscan_country' => $res['country'] ?? null,
                'urlscan_malicious' => $res['malicious'] ?? false,
                'urlscan_score' => $res['score'] ?? 0,
            ]);
        } elseif (($res['status'] ?? '') === 'pending') {
            $result->update([
                'urlscan_status' => 'pending',
            ]);
        }

        return $result->fresh();
    }

    private function findRecentUnscannableResult(BrandScanResult $result, \Illuminate\Support\Carbon $cooldownCutoff): ?BrandScanResult
    {
        if (
            in_array($result->urlscan_status, [UrlscanException::STATUS_UNRESOLVABLE, UrlscanException::STATUS_REJECTED], true) &&
            $result->urlscan_checked_at !== null &&
            $result->urlscan_checked_at->gte($cooldownCutoff)
        ) {
            return $result;
        }

        return BrandScanResult::query()
            ->where('domain', $result->domain)
            ->whereIn('urlscan_status', [UrlscanException::STATUS_UNRESOLVABLE, UrlscanException::STATUS_REJECTED])
            ->where('urlscan_checked_at', '>=', $cooldownCutoff)
            ->orderByDesc('urlscan_checked_at')
            ->first();
    }
}
