<?php

namespace App\Repositories;

use App\Interfaces\BrandScanRepositoryInterface;
use App\Jobs\RunBrandScanJob;
use App\Models\BrandScan;
use App\Models\BrandScanResult;
use App\Services\UrlscanService;

class BrandScanRepository implements BrandScanRepositoryInterface
{
    protected UrlscanService $urlscanService;

    public function __construct(UrlscanService $urlscanService)
    {
        $this->urlscanService = $urlscanService;
    }

    public function createScan(string $domain): BrandScan
    {
        $brandScan = BrandScan::create([
            'domain' => strtolower(trim($domain)),
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
            ->where('urlscan_status', 'done')
            ->whereNotNull('urlscan_uuid')
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

        $submission = $this->urlscanService->submitScan($result->domain);

        $result->update([
            'urlscan_status' => 'pending',
            'urlscan_uuid' => $submission['uuid'],
            'urlscan_report_url' => $submission['report_url'],
            'urlscan_screenshot' => $submission['screenshot'],
        ]);

        return $result->fresh();
    }

    public function getUrlscan(string|int $resultId): BrandScanResult
    {
        $result = BrandScanResult::findOrFail($resultId);

        if (!$result->urlscan_uuid) {
            throw new \Exception('URLScan has not been submitted yet for this result');
        }

        $res = $this->urlscanService->fetchResult($result->urlscan_uuid);

        if (($res['status'] ?? '') === 'done') {
            $result->update([
                'urlscan_status' => 'done',
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
}
