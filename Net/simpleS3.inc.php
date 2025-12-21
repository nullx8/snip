<?php
/**
 * s3_simple.php — minimal S3/Spaces read/write without composer.
 * Requires: PHP 7.4+ (works on 8.x), ext-curl, ext-json
 *
 * CONFIG:
 * $s3Cfg = [
 *   'access_key' => 'DO000....',
 *   'secret_key' => 'xxxx....',
 *   'region'     => 'nyc3', // for Spaces: region == datacenter
 *   'endpoint'   => 'nyc3.digitaloceanspaces.com', // no scheme
 *   'use_path_style' => false, // true => https://endpoint/bucket/key
 *   'timeout'    => 10,
 * ];
 */

/* -------------------------- Public API -------------------------- */

if (!isset($s3Cfg)) {
	$s3Cfg = s3_config_from_env();
}

function s3Get(string $bucket, string $key, ?array $s3Cfg = null): string
{
    $s3Cfg = $s3Cfg ?? s3_config_from_env();

    $res = s3_request('GET', $bucket, $key, '', $s3Cfg, [
        'headers' => [],
    ]);

    if (!$res['ok']) {
        throw new RuntimeException("S3 read failed ({$res['status']}): {$res['error']}");
    }
    return $res['body'];
}

function s3Put(
    string $bucket,
    string $key,
    string $data,
    array $s3Cfg,
    string $contentType = 'application/octet-stream'
): bool {
    $res = s3_request('PUT', $bucket, $key, $data, $s3Cfg, [
        'headers' => [
            'content-type' => $contentType,
            // Keep it private unless you explicitly want public objects
            'x-amz-acl' => 'private',
        ],
    ]);

    if (!$res['ok']) {
        throw new RuntimeException("S3 write failed ({$res['status']}): {$res['error']}");
    }
    return true;
}

/**
 * Optional helper: load config from env vars.
 * Expected env vars:
 *   S3_ACCESS_KEY, S3_SECRET_KEY, S3_REGION, S3_ENDPOINT
 */
function s3_config_from_env(string $prefix = 'S3_'): array
{
    $get = fn($k, $d=null) => getenv($prefix.$k) !== false ? getenv($prefix.$k) : $d;

    $s3Cfg = [
        'access_key' => (string)$get('ACCESS_KEY'),
        'secret_key' => (string)$get('SECRET_KEY'),
        'region'     => (string)$get('REGION'),
        'endpoint'   => (string)$get('ENDPOINT'),
        'use_path_style' => filter_var($get('PATH_STYLE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'timeout'    => (int)$get('TIMEOUT', 10),
    ];

    foreach (['access_key','secret_key','region','endpoint'] as $k) {
        if ($s3Cfg[$k] === '') throw new InvalidArgumentException("Missing env {$prefix}{$k}");
    }
    return $s3Cfg;
}

/* -------------------------- Core Request -------------------------- */

function s3_request(
    string $method,
    string $bucket,
    string $key,
    string $body,
    array $s3Cfg,
    array $opt = []
): array {
    $method = strtoupper($method);
    $service = 's3';
    $region  = $s3Cfg['region'];
    $endpoint = $s3Cfg['endpoint'];
    $timeout = $s3Cfg['timeout'] ?? 10;
    $usePathStyle = (bool)($s3Cfg['use_path_style'] ?? false);

    // Build host + url
    $key = ltrim($key, '/');
    $canonicalUri = '/' . s3_uri_encode($key);

    if ($usePathStyle) {
        $host = $endpoint;
        $url  = "https://{$endpoint}/{$bucket}{$canonicalUri}";
        $canonicalUriForSigning = "/{$bucket}{$canonicalUri}";
    } else {
        $host = "{$bucket}.{$endpoint}";
        $url  = "https://{$host}{$canonicalUri}";
        $canonicalUriForSigning = $canonicalUri;
    }

    // Timestamps
    $amzDate = gmdate('Ymd\THis\Z'); // e.g. 20251220T081530Z
    $dateStamp = gmdate('Ymd');      // e.g. 20251220

    // Payload hash
    $payloadHash = hash('sha256', $body);

    // Headers (lowercase keys for canonicalization)
    $extraHeaders = [];
    foreach (($opt['headers'] ?? []) as $k => $v) {
        $extraHeaders[strtolower($k)] = trim((string)$v);
    }

    $headers = array_merge([
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ], $extraHeaders);

    // Canonical headers + signed headers
    ksort($headers);
    $canonicalHeaders = '';
    $signedHeadersArr = [];
    foreach ($headers as $k => $v) {
        $v = preg_replace('/\s+/', ' ', trim($v));
        $canonicalHeaders .= "{$k}:{$v}\n";
        $signedHeadersArr[] = $k;
    }
    $signedHeaders = implode(';', $signedHeadersArr);

    // No query string in this minimal version
    $canonicalQueryString = '';

    // Canonical request
    $canonicalRequest =
        $method . "\n" .
        $canonicalUriForSigning . "\n" .
        $canonicalQueryString . "\n" .
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign =
        "AWS4-HMAC-SHA256\n" .
        $amzDate . "\n" .
        $credentialScope . "\n" .
        hash('sha256', $canonicalRequest);

    $signingKey = s3_sigv4_key($s3Cfg['secret_key'], $dateStamp, $region, $service);
    $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

    $authorization =
        "AWS4-HMAC-SHA256 " .
        "Credential={$s3Cfg['access_key']}/{$credentialScope}, " .
        "SignedHeaders={$signedHeaders}, " .
        "Signature={$signature}";

    // Build curl headers
    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        // Keep original casing simple
        $curlHeaders[] = $k . ': ' . $v;
    }
    $curlHeaders[] = 'Authorization: ' . $authorization;

    // cURL
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$respHeaders) {
            $len = strlen($line);
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) return $len;
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
            return $len;
        },
    ]);

    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $bodyOut = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($status >= 200 && $status < 300);
    return [
        'ok' => $ok,
        'status' => $status,
        'headers' => $respHeaders,
        'body' => $bodyOut !== false ? (string)$bodyOut : '',
        'error' => $ok ? '' : ($curlErr ?: ($bodyOut !== false ? (string)$bodyOut : 'Unknown error')),
        'url' => $url,
    ];
}

/* -------------------------- SigV4 Helpers -------------------------- */

function s3_sigv4_key(string $secret, string $dateStamp, string $region, string $service): string
{
    $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
    $kRegion  = hash_hmac('sha256', $region,    $kDate, true);
    $kService = hash_hmac('sha256', $service,   $kRegion, true);
    return hash_hmac('sha256', 'aws4_request',  $kService, true);
}

/**
 * Encode key path per AWS canonical URI rules (RFC3986).
 * rawurlencode but keep '/' as path separator.
 */
function s3_uri_encode(string $path): string
{
    $parts = explode('/', $path);
    $enc = array_map(function($p) {
        return str_replace('%7E', '~', rawurlencode($p));
    }, $parts);
    return implode('/', $enc);
}