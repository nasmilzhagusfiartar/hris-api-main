<?php

namespace App\Jobs;

use App\Models\BrandScan;
use App\Models\BrandScanResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RunBrandScanJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public int $brandScanId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $brandScanId)
    {
        $this->brandScanId = $brandScanId;
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
            $result = Process::timeout(290)->run([
                'dnstwist',
                '--registered',
                '--format',
                'json',
                '--threads',
                '20',
                $domain,
            ]);

            if (!$result->successful()) {
                $errorMessage = $result->errorOutput() ?: $result->output();
                Log::error("dnstwist process failed for {$domain}: {$errorMessage}");
                $brandScan->update([
                    'status' => 'failed',
                    'error' => $errorMessage ?: 'dnstwist process exited with error code ' . $result->exitCode(),
                ]);
                return;
            }

            $output = $result->output();
            $data = json_decode($output, true);

            if (!is_array($data)) {
                Log::error("dnstwist output invalid JSON for {$domain}: {$output}");
                $brandScan->update([
                    'status' => 'failed',
                    'error' => 'Failed to parse dnstwist output as JSON',
                ]);
                return;
            }

            // Buang entri yang fuzzer-nya "*original"
            $filtered = array_filter($data, function ($item) {
                return isset($item['fuzzer']) && $item['fuzzer'] !== '*original';
            });

            DB::transaction(function () use ($brandScan, $filtered) {
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

                    BrandScanResult::create([
                        'brand_scan_id' => $brandScan->id,
                        'domain' => $itemDomain,
                        'fuzzer' => $item['fuzzer'] ?? 'unknown',
                        'dns_a' => array_values($dnsA),
                        'dns_mx' => array_values($dnsMx),
                        'dns_ns' => array_values($dnsNs),
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
                'trace' => $e->getTraceAsString(),
            ]);

            $brandScan->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
