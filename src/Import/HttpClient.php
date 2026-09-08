<?php

declare(strict_types=1);

namespace Versandkostenretter\Import;

use RuntimeException;
use Versandkostenretter\Import\SourceException;

// Compile-time dependency: when this file is loaded before the autoloader is
// registered (e.g. via auto_prepend_file or manual require), the interface
// must already exist or PHP fatals at parse time. Guarded require makes the
// load order irrelevant.
if (!interface_exists(SourceFetcher::class)) {
    require_once __DIR__ . '/SourceFetcher.php';
}

/**
 * Minimal HTTP fetcher for the importer: HTTPS-only, fixed timeouts,
 * bounded retries with backoff, strict content-type and redirect policy.
 *
 * Fail-closed: any problem throws; callers must treat a throw as "no data".
 */
final class HttpClient implements SourceFetcher
{
    /** @var array<int, array{code:int,body:string,content_type:string,url:string}> */
    private array $attempts = [];

    public function __construct(
        private int $connectTimeout = 10,
        private int $totalTimeout = 30,
        private int $maxAttempts = 3,
        private string $userAgent = 'Versandkostenretter-Importer/1.0 (+https://versandkostenretter.de)'
    ) {
    }

    /**
     * GET an HTTPS URL. Follows at most 3 redirects but only to HTTPS targets
     * on the SAME host (Shopify collections can bounce between host forms);
     * anything else is rejected.
     *
     * @return array{code:int, body:string, content_type:string, url:string}
     */
    public function get(string $url): array
    {
        $isLoopbackHttp = (bool) preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#i', $url);
        if (!$isLoopbackHttp && !preg_match('#^https://#i', $url)) {
            throw new SourceException('Only https:// URLs are allowed.');
        }
        if (str_contains($url, "\r") || str_contains($url, "\n")) {
            throw new SourceException('Invalid URL (control characters).');
        }

        $this->attempts = [];
        $current = $url;
        $redirects = 0;

        while (true) {
            $response = $this->singleGet($current);
            $this->attempts[] = $response;

            if ($response['code'] >= 300 && $response['code'] < 400) {
                $location = $response['headers']['location'] ?? null;
                if (!is_string($location) || $location === '') {
                    throw new SourceException("Redirect without Location header (HTTP {$response['code']}).");
                }
                $next = $this->absolutize($current, $location);
                if (!preg_match('#^https://#i', $next)
                    || parse_url($next, PHP_URL_HOST) !== parse_url($current, PHP_URL_HOST)) {
                    throw new SourceException('Redirect to a different host or non-HTTPS target rejected.');
                }
                if (++$redirects > 3) {
                    throw new SourceException('Too many redirects.');
                }
                $current = $next;
                continue;
            }

            if ($response['code'] >= 500 || $response['code'] === 429) {
                // Transient (429/5xx): als Exception mit Status + Retry-After
                // melden; die Retry-Politik liegt im RetryingSourceFetcher.
                throw SourceException::http($response['code'], $this->retryAfterOf($response));
            }
            if ($response['code'] !== 200) {
                throw SourceException::http($response['code']);
            }
            return $response;
        }
    }

    /**
   /**
     * Retry-After aus der Response extrahieren: sekunden-basiert (nicht
     * HTTP-Datum), parsebar, auf 1..30s geklemmt; sonst null.
     *
     * @param array{code:int, headers:array<string,string>} $response
     */
    private function retryAfterOf(array $response): ?int
    {
        $ra = $response['headers']['retry-after'] ?? null;
        if (is_string($ra) && preg_match('/^\s*\d+\s*$/', $ra)) {
            return max(1, min(30, (int) $ra));
        }
        return null;
    }

/** @return array{code:int, body:string, content_type:string, url:string, headers:array<string,string>} */
    private function singleGet(string $url): array
    {
        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'follow_location' => 0,           // redirects handled explicitly
                    'max_redirects' => 0,
                    'timeout' => $this->totalTimeout,
                    'user_agent' => $this->userAgent,
                    'header' => "Accept: application/json\r\n",
                    'ignore_errors' => true,           // we inspect the status ourselves
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $connectReport = ['timeout' => $this->connectTimeout];
            $body = @file_get_contents($url, false, $ctx);
            $code = 0;
            $headers = [];
            $contentType = '';
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $code = (int) $m[1];
                    $headers = []; // last status line wins
                    continue;
                }
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }
            $contentType = $headers['content-type'] ?? '';

            if ($body !== false) {
                return [
                    'code' => $code,
                    'body' => $body,
                    'content_type' => $contentType,
                    'url' => $url,
                    'headers' => $headers,
                ];
            }

            $lastError = error_get_last()['message'] ?? 'fetch failed';
            if ($attempt < $this->maxAttempts) {
                sleep(2 ** ($attempt - 1)); // 1s, 2s backoff
            }
        }

        throw new SourceException('Fetch failed after retries: ' . $this->sanitize($lastError));
    }

    private function absolutize(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $host = $p['host'] ?? '';
        if ($host === '') {
            throw new SourceException('Cannot resolve redirect target.');
        }
        if ($location[0] === '/') {
            return 'https://' . $host . $location;
        }
        $path = $p['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        return 'https://' . $host . $dir . $location;
    }

    /** Strip anything that might contain internal details from an error. */
    private function sanitize(string $message): string
    {
        return (string) preg_replace('/(file_get_contents\([^)]*\):\s*)?failed to open stream.*/i', 'network error', $message);
    }

    /** @return array<int, array{code:int,body:string,content_type:string,url:string}> */
    public function attempts(): array
    {
        return $this->attempts;
    }
}
