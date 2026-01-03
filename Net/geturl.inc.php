<?php
	
/*
	USAGE
	
		$r = GetUrl('url'); 
		- returns eveything
		- 30 sec cache

		$r = GetUrl('url',false, 'OK');
		- expect 'OK' or fails
		- 30 sec cache
		
		$r = GetUrl('url','OK', 15);
		- expect 'OK' or fails
		- 15 sec cache

		$r = GetUrl('url','OK', 15);
		- expect 'OK' or fails
		- 15 sec cache
				 
		

*/

if (!defined('CACHE')) {
    $dc = getenv('CACHE');
    if (strlen($dc)>2) {
	    define('CACHE', getenv('CACHE'));
	}
}
if (!defined('CACHE')) {
    // fallback to hardcoded 
    define('CACHE', __DIR__.'/../c');
}

function getUrl( $url, $cacheLifetime = 30, $expected = null, $timeout = 5, $cacheDir = CACHE , $auth = null) {
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }

    // Cache key
    $cacheKey  = md5($url . serialize($expected)) . ".cache";
    $cacheFile = $cacheDir . "/" . $cacheKey;

    // Cache status
    $hasCache = file_exists($cacheFile);
    $cacheAge = $hasCache ? time() - filemtime($cacheFile) : 0;

    // Valid cache → return normally
    if ($hasCache && $cacheAge <= $cacheLifetime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        return [
            'data'      => $cached['data'],
            'error'     => null,
	    'url'	=> $url,
	    'http'      => $cached['http'],
            'cached'    => $cacheAge,
            'cacheFile' => $cacheFile
        ];
    }

    // Return cached data but KEEP the error message
    $returnCacheWithError = function($errorMsg, $httpCode) use ($hasCache, $cacheFile, $cacheAge) {
        if ($hasCache) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            return [
                'data'      => $cached['data'],
                'error'     => $errorMsg,
//	        'url'       => $url,
                'http'      => $httpCode,
                'cached'    => $cacheAge,
                'cacheFile' => $cacheFile
            ];
        }
        return false;
    };

    // ------------------------------------------------------------
    // FETCH (HTTP(S) via cURL OR S3 via s3Get)
    // ------------------------------------------------------------
    $response = null;
    $curlErr  = null;
    $httpCode = null;

    $scheme = parse_url($url, PHP_URL_SCHEME);
    $isS3   = (is_string($scheme) && strtolower($scheme) === 's3');

    if ($isS3) {
        // s3://bucket/key
        $p = parse_url($url);
        $bucket = $p['host'] ?? '';
        $key    = ltrim($p['path'] ?? '', '/');

        if ($bucket === '' || $key === '') {
            // treat as transport-ish error (same behavior as CurlError path)
            $response = false;
            $curlErr  = "S3Error: InvalidS3Url";
            $httpCode = null;
        } else {
            try {
                require_once __DIR__ . '/simpleS3.inc.php';

                // Optional: allow passing cfg via $auth ONLY for s3 calls
                $s3Cfg = is_array($auth) ? $auth : null;

                $s3Res = s3Get($bucket, $key, $s3Cfg);

                // Normalize s3Get response into the same variables getUrl already uses
                if (is_array($s3Res)) {
                    $response = $s3Res['data']   ?? ($s3Res['body'] ?? null);
                    $httpCode = $s3Res['status'] ?? ($s3Res['http'] ?? null);
                    $curlErr  = $s3Res['error']  ?? null;
                } else {
                    // If s3Get ever returns raw string
                    $response = $s3Res;
                    $httpCode = 200;
                    $curlErr  = null;
                }

                // If s3Get gives no status at all, treat it like a curl failure
                if ($httpCode === null) {
                    $response = false;
                    $curlErr  = "S3Error: " . ($curlErr ?: 'UnknownS3Failure');
                }

            } catch (Throwable $e) {
                $response = false;
                $curlErr  = "S3Error: " . $e->getMessage();
                $httpCode = null;
            }
        }

    } else {
        // -------------------------
        // HTTP(S) cURL request (your original code)
        // -------------------------
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($auth) {
            $authorizationHeader = "Authorization: Bearer " . $auth;
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($authorizationHeader));
        }

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    // -------------------------
    // FAILURE CASES (unchanged behavior)
    // -------------------------
    if ($response === false) {
        $fallback = $returnCacheWithError("CurlError: $curlErr", null);
        if ($fallback !== false) return $fallback;

        return [
            'data'      => null,
            'error'     => "CurlError: $curlErr",
            'url'       => $url,
            'http'      => null,
            'cached'    => 0,
            'cacheFile' => $cacheFile
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $fallback = $returnCacheWithError("HttpError: $httpCode", $httpCode);
        if ($fallback !== false) return $fallback;

        return [
            'data'      => $response,
            'error'     => "HttpError: $httpCode",
            'url'       => $url,
            'http'      => $httpCode,
            'cached'    => 0,
            'cacheFile' => $cacheFile
        ];
    }

    if ($expected !== null && is_string($expected)) {
        if (strpos($response, $expected) === false) {
            $fallback = $returnCacheWithError("ExpectedNotFound: '$expected'", $httpCode);
            if ($fallback !== false) return $fallback;

            return [
                'data'      => $response,
                'error'     => "ExpectedNotFound: '$expected'",
                'url'       => $url,
                'http'      => $httpCode,
                'cached'    => 0,
                'cacheFile' => $cacheFile
            ];
        }
    }

    if ($expected !== null && is_callable($expected)) {
        if (!$expected($response)) {
            $fallback = $returnCacheWithError("ExpectedCallbackReturnedFalse", $httpCode);
            if ($fallback !== false) return $fallback;

            return [
                'data'      => $response,
                'error'     => "ExpectedCallbackReturnedFalse",
                'url'       => $url,
                'http'      => $httpCode,
                'cached'    => 0,
                'cacheFile' => $cacheFile
            ];
        }
    }

    // -------------------------
    // SUCCESS → save cache (unchanged behavior)
    // -------------------------
    $store = [
        'data' => $response,
        'http' => $httpCode
    ];
    file_put_contents($cacheFile, json_encode($store));

    return [
        'data'      => $response,
        'error'     => null,
        'url'       => $url,
        'http'      => $httpCode,
        'cached'    => 0,
        'cacheFile' => $cacheFile
    ];
}
