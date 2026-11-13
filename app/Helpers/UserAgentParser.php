<?php

namespace App\Helpers;

class UserAgentParser
{
    /**
     * Parse raw User-Agent string (and optional HTTP headers) into browser, OS, and device type.
     *
     * @param  string|null  $userAgent
     * @param  array<string, mixed>  $headers
     * @return array{
     *   browser: string,
     *   browser_version: string|null,
     *   browser_label: string,
     *   os: string,
     *   os_version: string|null,
     *   os_label: string,
     *   device_type: string,
     *   summary: string
     * }
     */
    public static function parse(?string $userAgent, array $headers = []): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return [
                'browser' => 'Unknown',
                'browser_version' => null,
                'browser_label' => 'Unknown Browser',
                'os' => 'Unknown',
                'os_version' => null,
                'os_label' => 'Unknown OS',
                'device_type' => 'Desktop',
                'summary' => 'Unknown Browser • Unknown OS',
            ];
        }

        [$os, $osVersion, $osLabel] = self::detectOs($ua, $headers);
        [$browser, $browserVersion, $browserLabel] = self::detectBrowser($ua);
        $deviceType = self::detectDeviceType($ua, $os, $headers);

        return [
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'browser_label' => $browserLabel,
            'os' => $os,
            'os_version' => $osVersion,
            'os_label' => $osLabel,
            'device_type' => $deviceType,
            'summary' => "{$browserLabel} • {$osLabel} ({$deviceType})",
        ];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private static function detectOs(string $ua, array $headers = []): array
    {
        // Android
        if (preg_match('/Android\s+([0-9]+(?:\.[0-9]+)?)/i', $ua, $m)) {
            $ver = $m[1];

            return ['Android', $ver, "Android {$ver}"];
        }
        if (stripos($ua, 'Android') !== false) {
            return ['Android', null, 'Android'];
        }

        // iOS / iPadOS
        if (preg_match('/iPad.*OS\s+([0-9_]+)/i', $ua, $m)) {
            $ver = str_replace('_', '.', $m[1]);
            $major = explode('.', $ver)[0] ?? $ver;

            return ['iPadOS', $major, "iPadOS {$major}"];
        }
        if (preg_match('/(?:iPhone|iPod).*OS\s+([0-9_]+)/i', $ua, $m)) {
            $ver = str_replace('_', '.', $m[1]);
            $major = explode('.', $ver)[0] ?? $ver;

            return ['iOS', $major, "iOS {$major}"];
        }

        // Windows
        if (preg_match('/Windows NT\s+([0-9.]+)/i', $ua, $m)) {
            $ntVersion = $m[1];
            // Check Client Hints if available for Windows 11 detection (platformVersion >= 13)
            $platformVersionHint = $headers['sec-ch-ua-platform-version'] ?? null;
            if (is_string($platformVersionHint)) {
                $cleanVer = trim($platformVersionHint, '"\' ');
                $majorHint = (int) explode('.', $cleanVer)[0];
                if ($majorHint >= 13) {
                    return ['Windows', '11', 'Windows 11'];
                }
                if ($majorHint > 0) {
                    return ['Windows', '10', 'Windows 10'];
                }
            }

            $winMap = [
                '10.0' => '10/11',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
            ];
            $ver = $winMap[$ntVersion] ?? $ntVersion;

            return ['Windows', $ver, "Windows {$ver}"];
        }
        if (stripos($ua, 'Windows') !== false) {
            return ['Windows', null, 'Windows'];
        }

        // macOS
        if (preg_match('/Mac OS X\s+([0-9_.\s]+)/i', $ua, $m)) {
            $ver = str_replace('_', '.', trim($m[1]));
            $parts = explode('.', $ver);
            $shortVer = isset($parts[0], $parts[1]) ? "{$parts[0]}.{$parts[1]}" : $parts[0];

            return ['macOS', $shortVer, "macOS {$shortVer}"];
        }
        if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) {
            return ['macOS', null, 'macOS'];
        }

        // ChromeOS
        if (stripos($ua, 'CrOS') !== false) {
            return ['ChromeOS', null, 'ChromeOS'];
        }

        // Linux distros
        if (stripos($ua, 'Ubuntu') !== false) {
            return ['Linux', 'Ubuntu', 'Ubuntu Linux'];
        }
        if (stripos($ua, 'Fedora') !== false) {
            return ['Linux', 'Fedora', 'Fedora Linux'];
        }
        if (stripos($ua, 'Linux') !== false || stripos($ua, 'X11') !== false) {
            return ['Linux', null, 'Linux'];
        }

        return ['Other', null, 'Other OS'];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private static function detectBrowser(string $ua): array
    {
        // Order matters: Edge, Opera, Samsung, Brave, Vivaldi, Chrome, Firefox, Safari, CLI tools
        $patterns = [
            'Edge' => '/(?:Edg|EdgA|EdgiOS|Edge)\/([0-9]+)/i',
            'Opera' => '/(?:OPR|Opera)\/([0-9]+)/i',
            'Samsung Internet' => '/SamsungBrowser\/([0-9]+(?:\.[0-9]+)?)/i',
            'Vivaldi' => '/Vivaldi\/([0-9]+)/i',
            'Brave' => '/Brave\/([0-9]+)/i',
            'UC Browser' => '/UCBrowser\/([0-9]+)/i',
            'Firefox' => '/(?:Firefox|FxiOS)\/([0-9]+)/i',
            'Chrome' => '/(?:Chrome|CriOS)\/([0-9]+)/i',
            'Safari' => '/Version\/([0-9]+(?:\.[0-9]+)?).*Safari/i',
            'Postman' => '/PostmanRuntime\/([0-9]+(?:\.[0-9]+)?)/i',
            'Insomnia' => '/insomnia\/([0-9]+(?:\.[0-9]+)?)/i',
            'cURL' => '/curl\/([0-9]+(?:\.[0-9]+)?)/i',
            'PowerShell' => '/PowerShell\/([0-9]+(?:\.[0-9]+)?)/i',
            'Python Requests' => '/python-requests\/([0-9]+(?:\.[0-9]+)?)/i',
            'Axios' => '/axios\/([0-9]+(?:\.[0-9]+)?)/i',
        ];

        foreach ($patterns as $name => $regex) {
            if (preg_match($regex, $ua, $m)) {
                $ver = $m[1];

                return [$name, $ver, "{$name} {$ver}"];
            }
        }

        if (stripos($ua, 'MozillaPowerShell') !== false || stripos($ua, 'WindowsPowerShell') !== false) {
            return ['PowerShell', null, 'PowerShell'];
        }

        if (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
            return ['Safari', null, 'Safari'];
        }

        return ['Browser', null, 'Web Client'];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private static function detectDeviceType(string $ua, string $os, array $headers = []): string
    {
        // Tablet checks
        if (
            preg_match('/iPad|Tablet|PlayBook|Silk|(?:Android(?!.*Mobile))/i', $ua)
        ) {
            return 'Tablet';
        }

        // Mobile checks
        $chMobile = $headers['sec-ch-ua-mobile'] ?? null;
        if ($chMobile === '?1' || $chMobile === '1') {
            return 'Mobile';
        }

        if (
            in_array($os, ['Android', 'iOS'], true) ||
            preg_match('/Mobile|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)
        ) {
            return 'Mobile';
        }

        return 'Desktop';
    }
}

