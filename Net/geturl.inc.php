<?php
	
// expects 'CACHE' to be defined
if (!defined('CACHE')) {
    define('CACHE', __DIR__.'/../c');
}


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
	
function getUrl( $url, $cacheLifetime = 30, $expected = null, $timeout = 5, $cacheDir = CACHE ) {
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
            'data'     => $cached['data'],
            'error'    => null,
            'http'     => $cached['http'],
	    'cached'   => $cacheAge,
	    'cachedat' => $cacheFile
        ];
    }

    // cURL request
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Return cached data but KEEP the error message
    $returnCacheWithError = function($errorMsg, $httpCode) use ($hasCache, $cacheFile, $cacheAge) {
        if ($hasCache) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            return [
                'data'   => $cached['data'],    // keep cached data
                'error'  => $errorMsg,          // but show the error!
                'http'   => $httpCode,          // return failed http status
                'cached' => $cacheAge,           // cache age
		'cachedat' => $cacheFile
	];
        }
        return false;
    };

    // -------------------------
    // FAILURE CASES
    // -------------------------

    if ($response === false) {
        $fallback = $returnCacheWithError("CurlError: $curlErr", null);
        if ($fallback !== false) return $fallback;

        return [
            'data'   => null,
            'error'  => "CurlError: $curlErr",
            'http'   => null,
            'cached' => 0,
	    'cachedat' => $cacheFile
    	];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $fallback = $returnCacheWithError("HttpError: $httpCode", $httpCode);
        if ($fallback !== false) return $fallback;

        return [
            'data'   => $response,
            'error'  => "HttpError: $httpCode",
            'http'   => $httpCode,
            'cached' => 0,
    	    'cachedat' => $cacheFile
    	];
    }

    if ($expected !== null && is_string($expected)) {
        if (strpos($response, $expected) === false) {
            $fallback = $returnCacheWithError("ExpectedNotFound: '$expected'", $httpCode);
            if ($fallback !== false) return $fallback;

            return [
                'data'   => $response,
                'error'  => "ExpectedNotFound: '$expected'",
                'http'   => $httpCode,
		'cached' => 0,
		'cachedat' => $cacheFile
            ];
        }
    }

    if ($expected !== null && is_callable($expected)) {
        if (!$expected($response)) {
            $fallback = $returnCacheWithError("ExpectedCallbackReturnedFalse", $httpCode);
            if ($fallback !== false) return $fallback;

            return [
                'data'   => $response,
                'error'  => "ExpectedCallbackReturnedFalse",
                'http'   => $httpCode,
		'cached' => 0,
		'cachedat' => $cacheFile
            ];
        }
    }

    // -------------------------
    // SUCCESS → save cache
    // -------------------------

    $store = [
        'data' => $response,
        'http' => $httpCode
    ];
    file_put_contents($cacheFile, json_encode($store));

    return [
        'data'   => $response,
        'error'  => null,
        'http'   => $httpCode,
	'cached' => 0,
	'cachedat' => $cacheFile
    ];
}
