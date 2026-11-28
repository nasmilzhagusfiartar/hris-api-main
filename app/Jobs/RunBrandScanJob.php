<?php

namespace App\Jobs;

use App\Models\BrandScan;
use App\Models\BrandScanResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class RunBrandScanJob implements ShouldQueue
{
    use Queueable;

    public const DNSTWIST_NAMESERVERS = '1.1.1.1,8.8.8.8';
    public const DNSTWIST_THREADS = '40';
    public const DNSTWIST_FUZZERS = 'addition,bitsquatting,hyphenation,insertion,omission,repetition,replacement,subdomain,transposition,vowel-swap,tld-swap';
    public const SAFE_TIMEOUT_MESSAGE = 'Scan timed out. Please try again.';
    public const SAFE_FAILED_MESSAGE = 'Brand scan failed. Please try again later.';

    public static function tldFilePath(): string
    {
        return resource_path('dnstwist/tlds.txt');
    }

    public int $timeout = 300;
    public int $tries = 1;

    public int $brandScanId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $brandScanId)
    {
        $this->brandScanId = $brandScanId;
        $this->onQueue('scans');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $brandScan = BrandScan::find($this->brandScanId);
        if (!$brandScan) {
            Log::error("RunBrandScanJob: BrandScan ID {$this->brandScanId} not found");
            return;
        }

        try {
            $domain = trim($brandScan->domain);

            // Validasi domain dengan regex sebelum dieksekusi
            $pattern = '/^(?!:\/\/)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/';
            if (!preg_match($pattern, $domain)) {
                $brandScan->update([
                    'status' => 'failed',
                    'error' => "Invalid domain format: {$domain}",
                ]);
                return;
            }

            // Set status running
            $brandScan->update(['status' => 'running', 'error' => null]);

            Log::info("Executing dnstwist for domain {$domain}");

            // Process dengan argumen ARRAY (bukan string shell)
            $result = Process::timeout(270)->run([
                'dnstwist',
                '--registered',
                '--format',
                'json',
                '--nameservers',
                self::DNSTWIST_NAMESERVERS,
                '--threads',
                self::DNSTWIST_THREADS,
                '--fuzzers',
                self::DNSTWIST_FUZZERS,
                '--tld',
                self::tldFilePath(),
                $domain,
            ]);

            if (!$result->successful()) {
                $errorMessage = $result->errorOutput() ?: $result->output();
                Log::error("dnstwist process failed for {$domain}: {$errorMessage}", [
                    'brand_scan_id' => $this->brandScanId,
                    'exit_code' => $result->exitCode(),
                ]);
                $brandScan->update([
                    'status' => 'failed',
                    'error' => self::SAFE_FAILED_MESSAGE,
                ]);
                return;
            }

            $output = $result->output();
            $data = json_decode($output, true);

            if (!is_array($data)) {
                Log::error("dnstwist output invalid JSON for {$domain}: {$output}", [
                    'brand_scan_id' => $this->brandScanId,
                ]);
                $brandScan->update([
                    'status' => 'failed',
                    'error' => self::SAFE_FAILED_MESSAGE,
                ]);
                return;
            }

            // Buang entri yang fuzzer-nya "*original"
            $filtered = array_filter($data, function ($item) {
                return isset($item['fuzzer']) && $item['fuzzer'] !== '*original';
            });

            DB::transaction(function () use ($brandScan, $filtered) {
                // Preserve previous completed urlscan intelligence for matching domains
                $discoveredDomains = collect($filtered)
                    ->pluck('domain')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $previousUrlscans = [];
                if (!empty($discoveredDomains)) {
                    $previousUrlscans = BrandScanResult::query()
                        ->whereIn('domain', $discoveredDomains)
                        ->where('urlscan_status', 'done')
                        ->whereNotNull('urlscan_uuid')
                        ->orderByDesc('updated_at')
                        ->get()
                        ->keyBy('domain')
                        ->all();
                }

                $brandScan->results()->delete();

                $totalFound = 0;
                foreach ($filtered as $item) {
                    $itemDomain = $item['domain'] ?? null;
                    if (!$itemDomain) {
                        continue;
                    }

                    $dnsA = isset($item['dns_a']) ? (is_array($item['dns_a']) ? $item['dns_a'] : [$item['dns_a']]) : [];
                    $dnsMx = isset($item['dns_mx']) ? (is_array($item['dns_mx']) ? $item['dns_mx'] : [$item['dns_mx']]) : [];
                    $dnsNs = isset($item['dns_ns']) ? (is_array($item['dns_ns']) ? $item['dns_ns'] : [$item['dns_ns']]) : [];

                    $prev = $previousUrlscans[$itemDomain] ?? null;

                    BrandScanResult::create([
                        'brand_scan_id' => $brandScan->id,
                        'domain' => $itemDomain,
                        'fuzzer' => $item['fuzzer'] ?? 'unknown',
                        'dns_a' => array_values($dnsA),
                        'dns_mx' => array_values($dnsMx),
                        'dns_ns' => array_values($dnsNs),
                        'urlscan_status' => $prev?->urlscan_status,
                        'urlscan_uuid' => $prev?->urlscan_uuid,
                        'urlscan_malicious' => $prev?->urlscan_malicious,
                        'urlscan_score' => $prev?->urlscan_score,
                        'urlscan_ip' => $prev?->urlscan_ip,
                        'urlscan_country' => $prev?->urlscan_country,
                        'urlscan_screenshot' => $prev?->urlscan_screenshot,
                        'urlscan_report_url' => $prev?->urlscan_report_url,
                    ]);

                    $totalFound++;
                }

                $brandScan->update([
                    'status' => 'done',
                    'total_found' => $totalFound,
                    'error' => null,
                ]);
            });

            Log::info("dnstwist scan completed for {$domain}. Total results: " . count($filtered));
        } catch (\Throwable $e) {
            Log::error("RunBrandScanJob exception for {$this->brandScanId}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->markScanFailed($brandScan, $e);

            throw $e;
        }
    }

    /**
     * Handle a job failure (including timeouts and MaxAttemptsExceededException).
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("RunBrandScanJob failed hook triggered for BrandScan ID {$this->brandScanId}", [
            'exception' => $exception ? get_class($exception) : null,
            'error' => $exception?->getMessage(),
        ]);

        $brandScan = BrandScan::find($this->brandScanId);
        if (!$brandScan || $brandScan->status === 'done') {
            return;
        }

        $this->markScanFailed($brandScan, $exception);
    }

    private function markScanFailed(BrandScan $brandScan, ?\Throwable $exception): void
    {
        $brandScan->update([
            'status' => 'failed',
            'error' => $this->resolveSafeErrorMessage($exception),
        ]);
    }

    private function resolveSafeErrorMessage(?\Throwable $exception): string
    {
        if (
            $exception instanceof ProcessTimedOutException ||
            $exception instanceof SymfonyProcessTimedOutException ||
            $exception instanceof MaxAttemptsExceededException
        ) {
            return self::SAFE_TIMEOUT_MESSAGE;
        }

        $rawMessage = strtolower((string) $exception?->getMessage());
        if (str_contains($rawMessage, 'timeout') || str_contains($rawMessage, 'timed out')) {
            return self::SAFE_TIMEOUT_MESSAGE;
        }

        return self::SAFE_FAILED_MESSAGE;
    }
}
