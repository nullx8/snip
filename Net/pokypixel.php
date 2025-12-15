<?php
declare(strict_types=1);

/**
 * Traffic-triggered HTTP cache warmer.
 *
 * Guarantees:
 *  - Payload cache files are NEVER modified unless replaced with a verified-good response.
 *    (No touch(), no truncation, no empty writes on failure.)
 *
 * Env:
 *  - CACHE                 : base dir for cache payload files (fallback /tmp)
 *  - CRONJOBS_FILE         : path to cronjobs json (fallback: __DIR__/cronjobs.json)
 *  - TRAFFIC_CRON_PROB     : probability gate (default 0.03)
 *  - TRAFFIC_CRON_BUDGET_MS: time budget (default 300)
 *  - TRAFFIC_CRON_TIMEOUT_S: per-endpoint timeout (default 1.2)
 *  - TRAFFIC_CRON_DEBUG_REMOTE=1 : allow debug JSON from remote
 *
 * Params:
 *  - default: returns 1x1 PNG
 *  - ?debug=1|json : return JSON
 *  - ?force=1      : ignore TTL/retry/probability and 10x budget (for debugging)
 *  - ?budget_ms=N  : override runtime budget
 *  - ?job=a,b      : run only selected job names
 */

$CACHE_BASE = rtrim((string)(getenv('CACHE') ?: '/tmp'), '/');

// Payload cache files go directly into CACHE:
$CACHE_DIR = $CACHE_BASE;

// State/locks kept in subdirectory:
$STATE_DIR = $CACHE_BASE . '/traffic_cache_state';

$PROBABILITY = (float)(getenv('TRAFFIC_CRON_PROB') ?: '0.03');
$BUDGET_MS   = (int)(getenv('TRAFFIC_CRON_BUDGET_MS') ?: '300');
$TIMEOUT_S   = (float)(getenv('TRAFFIC_CRON_TIMEOUT_S') ?: '1.2');

// Jobs file override (nested repo friendly)
$JOBS_FILE = (string)(getenv('CRONJOBS_FILE') ?: '');
if ($JOBS_FILE === '') {
    $JOBS_FILE = __DIR__ . '/cronjobs.json';
} else {
    // allow relative paths from script dir
    if ($JOBS_FILE[0] !== '/' && !preg_match('~^[A-Za-z]:[\\\\/]~', $JOBS_FILE)) {
        $JOBS_FILE = __DIR__ . '/' . $JOBS_FILE;
    }
}

// Debug output: localhost-only unless enabled
$ALLOW_DEBUG_REMOTE = (string)(getenv('TRAFFIC_CRON_DEBUG_REMOTE') ?: '0') === '1';
$DEBUG_ALLOWLIST_IP = ['127.0.0.1', '::1'];

// ----------------- Params -----------------
$debug   = $_GET['debug'] ?? null;
$isDebug = $debug !== null && $debug !== '' && $debug !== '0';

$forceParam = $_GET['force'] ?? null;
$isForce = $forceParam !== null && $forceParam !== '' && $forceParam !== '0';

$jobFilter = [];
if (isset($_GET['job']) && $_GET['job'] !== '') {
    $parts = preg_split('/\s*,\s*/', (string)$_GET['job']);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $jobFilter[$p] = true; // match against job "name"
    }
}

// Force = debug mode: larger budget
$FORCE_BUDGET_MULT = 10;
$effectiveBudgetMs = $isForce ? ($BUDGET_MS * $FORCE_BUDGET_MULT) : $BUDGET_MS;

// Optional explicit override for nasty lag debugging
if (isset($_GET['budget_ms']) && ctype_digit((string)$_GET['budget_ms'])) {
    $effectiveBudgetMs = max(50, (int)$_GET['budget_ms']);
}

// ----------------- Default output: 1x1 PNG -----------------
if ($isDebug && !$ALLOW_DEBUG_REMOTE) {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($ip, $DEBUG_ALLOWLIST_IP, true)) {
        http_response_code(404);
        exit;
    }
}

if (!$isDebug) {
    header('Content-Type: image/png');
    header('Cache-Control: no-store, max-age=0');
    echo base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+0/0AAAAASUVORK5CYII='
    );

    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else @flush();
}

// ----------------- Probabilistic trigger (skipped if force) -----------------
if (!$isForce && php_sapi_name() !== 'cli') {
    $r = mt_rand() / mt_getrandmax();
    if ($r > $PROBABILITY) {
        if ($isDebug) {
            header('Content-Type: application/json');
            echo json_encode(['skipped' => true, 'reason' => 'probability'], JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}

ignore_user_abort(true);
@set_time_limit(0);

@mkdir($CACHE_DIR, 0775, true);
@mkdir($STATE_DIR, 0775, true);

// ----------------- Load jobs -----------------
$jobs = loadJobs($JOBS_FILE);
if ($jobs === null) {
    if ($isDebug) {
        header('Content-Type: application/json');
        echo json_encode(['error' => "Unable to load jobs file: {$JOBS_FILE}"], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// Filter jobs by name (if requested)
if (!empty($jobFilter)) {
    $jobs = array_values(array_filter($jobs, function($j) use ($jobFilter) {
        return isset($jobFilter[(string)($j['name'] ?? '')]);
    }));
}

// ----------------- Global lock -----------------
$globalLock = @fopen($STATE_DIR . '/__global.lock', 'c');
if (!$globalLock || !@flock($globalLock, LOCK_EX | LOCK_NB)) {
    if ($isDebug) {
        header('Content-Type: application/json');
        echo json_encode(['skipped' => true, 'reason' => 'locked'], JSON_UNESCAPED_SLASHES);
    }
    if ($globalLock) fclose($globalLock);
    exit;
}

$start = microtime(true);
$now   = time();
$results = [];

foreach ($jobs as $job) {
    if (((microtime(true) - $start) * 1000) > $effectiveBudgetMs) {
        $results[] = ['name' => $job['name'] ?? 'unknown', 'skipped' => true, 'reason' => 'budget'];
        break;
    }

    $origName = (string)($job['name'] ?? 'job');
    $nameSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $origName);

    $ttl   = max(1, (int)($job['ttl'] ?? 300));
    $retry = max(1, (int)($job['retry'] ?? 15));

    $fileBase = (string)($job['cache_file'] ?? '');
    if ($fileBase === '' ||
        str_contains($fileBase, '..') ||
        str_starts_with($fileBase, '/') ||
        str_contains($fileBase, '\\')
    ) {
        $results[] = ['name' => $origName, 'ok' => false, 'error' => 'Missing/invalid cache_file'];
        continue;
    }

    $cacheJson = $CACHE_DIR . '/' . $fileBase;
    $cacheMeta = $CACHE_DIR . '/' . $fileBase . '.meta.json';

    // Done/attempt tracked in STATE so failed tests never become "fresh".
    $doneFile    = $STATE_DIR . '/' . $nameSafe . '.done';
    $attemptFile = $STATE_DIR . '/' . $nameSafe . '.attempt';

    $lastDone = readTs($doneFile);
    if (!$isForce && $lastDone > 0 && ($now - $lastDone) < $ttl) {
        $results[] = ['name' => $origName, 'skipped' => true, 'reason' => 'fresh', 'cache_file' => $fileBase];
        continue;
    }

    $lastAttempt = readTs($attemptFile);
    if (!$isForce && $lastAttempt > 0 && ($now - $lastAttempt) < $retry) {
        $results[] = ['name' => $origName, 'skipped' => true, 'reason' => 'retry_wait', 'cache_file' => $fileBase];
        continue;
    }

    // Per-job lock
    $jobLockPath = $STATE_DIR . '/' . $nameSafe . '.lock';
    $jobLock = @fopen($jobLockPath, 'c');
    if (!$jobLock || !@flock($jobLock, LOCK_EX | LOCK_NB)) {
        $results[] = ['name' => $origName, 'skipped' => true, 'reason' => 'job_locked', 'cache_file' => $fileBase];
        if ($jobLock) fclose($jobLock);
        continue;
    }

    // mark attempt time (does NOT touch payload cache file)
    atomicWrite($attemptFile, (string)$now);

    $method  = strtoupper((string)($job['method'] ?? 'GET'));
    $url     = (string)($job['url'] ?? '');
    $headers = (array)($job['headers'] ?? []);
    $body    = $job['body'] ?? null;
    $test    = $job['test'] ?? null;

    $resp = httpJson($method, $url, $headers, $body, (float)($job['timeout'] ?? $TIMEOUT_S));

    // Require valid JSON (sanity)
    if ($resp['ok']) {
        json_decode($resp['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $resp['ok'] = false;
            $resp['error'] = 'Invalid JSON: ' . json_last_error_msg();
        }
    }

    // Apply job-specific test: only store cache if it passes.
    $testOk = $resp['ok'] ? passesTest($resp['body'], $test) : false;
    if ($resp['ok'] && !$testOk) {
        $resp['ok'] = false;
        $resp['error'] = 'Test condition failed';
    }

    // CRITICAL: only overwrite payload file on success.
    if ($resp['ok']) {
        atomicWrite($cacheJson, $resp['body']);     // replace atomically
        atomicWrite($doneFile, (string)$now);       // mark done only on success
    }

    // Meta is safe to update even on failures (doesn't break consumers)
    $meta = [
        'generated_at' => $now,
        'name'         => $origName,
        'url'          => $url,
        'method'       => $method,
        'http_code'    => $resp['http_code'],
        'ok'           => $resp['ok'],
        'bytes'        => strlen($resp['body'] ?? ''),
        'sha1'         => $resp['ok'] ? sha1($resp['body']) : null,
        'error'        => $resp['ok'] ? null : ($resp['error'] ?? 'unknown error'),
        'duration_ms'  => $resp['duration_ms'],
        'cache_file'   => $fileBase,
        'ttl'          => $ttl,
        'retry'        => $retry,
        'force'        => $isForce,
        'budget_ms'    => $effectiveBudgetMs,
    ];
    atomicWrite($cacheMeta, json_encode($meta, JSON_UNESCAPED_SLASHES));

    $results[] = $meta;

    fclose($jobLock);
}

fclose($globalLock);

if ($isDebug) {
    header('Content-Type: application/json');
    echo json_encode([
        'cache_base' => $CACHE_BASE,
        'cache_dir'  => $CACHE_DIR,
        'state_dir'  => $STATE_DIR,
        'jobs_file'  => $JOBS_FILE,
        'force'      => $isForce,
        'filtered'   => array_keys($jobFilter),
        'budget_ms'  => $effectiveBudgetMs,
        'results'    => $results,
    ], JSON_UNESCAPED_SLASHES);
}

exit;

// ----------------- Helpers -----------------

function loadJobs(string $path): ?array {
    $raw = @file_get_contents($path);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    if (isset($data['jobs']) && is_array($data['jobs'])) {
        $data = $data['jobs'];
    }

    $jobs = [];
    foreach ($data as $j) {
        if (!is_array($j)) continue;
        if (empty($j['url'])) continue;

        $jobs[] = [
            'name'       => $j['name'] ?? 'job',
            'ttl'        => $j['ttl'] ?? 300,
            'retry'      => $j['retry'] ?? 15,
            'timeout'    => $j['timeout'] ?? null,   // optional per-job timeout
            'method'     => $j['method'] ?? 'GET',
            'url'        => $j['url'],
            'headers'    => $j['headers'] ?? [],
            'body'       => $j['body'] ?? null,
            'cache_file' => $j['cache_file'] ?? '',
            'test'       => $j['test'] ?? null,
        ];
    }
    return $jobs;
}

function readTs(string $path): int {
    $v = @file_get_contents($path);
    if ($v === false) return 0;
    $n = (int)trim($v);
    return $n > 0 ? $n : 0;
}

function passesTest(string $body, $test): bool {
    if ($test === null) return true;

    if (is_string($test)) {
        return $test === '' ? true : (strpos($body, $test) !== false);
    }

    if (is_array($test)) {
        if (isset($test['contains']) && is_string($test['contains'])) {
            $s = $test['contains'];
            return $s === '' ? true : (strpos($body, $s) !== false);
        }
        if (isset($test['regex']) && is_string($test['regex'])) {
            $re = $test['regex'];
            if ($re === '') return true;
            return @preg_match($re, $body) === 1;
        }
    }

    return false;
}

function httpJson(string $method, string $url, array $headers, $body, float $timeout): array {
    $t0 = microtime(true);

    if ($url === '') {
        return ['ok'=>false,'http_code'=>0,'body'=>'','error'=>'Missing url','duration_ms'=>0];
    }
    if (!function_exists('curl_init')) {
        $dt = (int)round((microtime(true) - $t0) * 1000);
        return ['ok'=>false,'http_code'=>0,'body'=>'','error'=>'cURL not available','duration_ms'=>$dt];
    }

    $ch = curl_init($url);

    $hdrs = array_merge(
        ['User-Agent: TrafficCacheWarm/1.0', 'X-Cache-Warm: 1', 'Accept: application/json'],
        $headers
    );

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_HTTPHEADER     => $hdrs,
    ];

    $method = strtoupper($method);

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if (is_array($body)) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
            $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_HTTPHEADER] = $hdrs;
        } elseif (is_string($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if (is_array($body)) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
            $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts[CURLOPT_HTTPHEADER] = $hdrs;
        } elseif (is_string($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    }

    curl_setopt_array($ch, $opts);

    $out  = curl_exec($ch);
    $err  = $out === false ? curl_error($ch) : null;
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $dt = (int)round((microtime(true) - $t0) * 1000);

    return [
        'ok'          => ($out !== false && $code >= 200 && $code < 300),
        'http_code'   => $code,
        'body'        => $out !== false ? (string)$out : '',
        'error'       => $err,
        'duration_ms' => $dt,
    ];
}

function atomicWrite(string $path, string $data): void {
    $tmp = $path . '.' . getmypid() . '.tmp';
    @file_put_contents($tmp, $data, LOCK_EX);
    @rename($tmp, $path);
}