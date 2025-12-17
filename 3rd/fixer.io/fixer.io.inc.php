<?php
//
// DO NOT CALL THIS FILE DIRECTLY!
// use ../../Math/fxrate.inc.php
// and call fxRate($from,$to, $round = null);


// error_reporting(E_ALL); ini_set('display_errors', '1'); ini_set('display_startup_errors', '1');

// if (!defined('CACHE')) { die("No Direct access"); }

if (!is_file(__DIR__.'/.Token')) {
	die("requires .Token file with APIKey");
}
include(__DIR__.'/../../Net/geturl.inc.php');


/**
 * Returns a multiplier to convert FROM -> TO using a dataset quoted in dataset["base"] (e.g. EUR).
 * For an amount: amount_in_to = amount_in_from * multiplier
 *
 * Usage:
 *   fxRate('USD', 'aed', $fx);
 */


/*
$fx dataset is expected to be conform with the dataset from fixer.io

Example:
{
  "success":true,
  "timestamp":1765900748,
  "base":"EUR",
  "date":"2025-12-16",
  "rates":{
    "AED":4.322635,
    "AFN":77.965275,
    "ALL":96.493658,
    "AMD":449.342038,
    "ANG":2.107354,
    "AOA":1079.334475,
    "ARS":1711.38357,
    "AUD":1.772859
    }
    }

$fx = json_decode(the json result)

*/


function fixerFxRate(string $from, string $to, $scale = null) {
    static $apiKey = null;
    static $fixerFeed = null;

	if ($apiKey === null) {
		$apiKey = trim((string)@file_get_contents(__DIR__.'/.Token'));
		if ($apiKey === '') {
			return [
				'timestamp'  => null,
				'from'       => strtoupper($from),
                'to'         => strtoupper($to),
                'multiplier' => null,
                'error'      => 'Missing API key (.Token)',
            ];
        }
    }

	if ($fixerFeed === null) {
		$cacheTime = 64800; // 18 hours (64800) for a free account
		$resp = getUrl(
			'https://data.fixer.io/api/latest?access_key='.$apiKey.'&format=1',
			$cacheTime,
			'rates',
			5
		);
//        print_r($resp['cached']);
		$fixerFeed = json_decode($resp['data'] ?? '', true);
        if (!is_array($fixerFeed)) {
            $fixerFeed = ['success' => false, 'error' => 'Invalid JSON'];
        }
    }
    
//	print_r($fixerFeed['cached']); // prints the cache age for debugging
	
	return _fixerFxRate($from, $to, $fixerFeed, $scale);
}

function _fixerFxRate(string $from, string $to, array $dataset, ?int $scale = null): array
{
    $from = strtoupper(trim($from));
    $to   = strtoupper(trim($to));

    $base = strtoupper((string)($dataset['base'] ?? ''));
    $ts   = $dataset['timestamp'] ?? null;

    if (!($dataset['success'] ?? false)) {
        return [
            'timestamp'  => $ts,
            'from'       => $from,
            'to'         => $to,
            'rate'		 => null,
            'error'      => 'Dataset not successful (success != true).',
        ];
    }

    if ($base === '') {
        return [
            'timestamp'  => $ts,
            'from'       => $from,
            'to'         => $to,
            'rate'		 => null,
            'error'      => 'Missing dataset base currency.',
        ];
    }

    $rates = $dataset['rates'] ?? [];
    if (!is_array($rates)) $rates = [];

    // Rate means: 1 BASE = rate CURRENCY
    $fromRate = ($from === $base) ? 1.0 : ($rates[$from] ?? null);
    $toRate   = ($to   === $base) ? 1.0 : ($rates[$to]   ?? null);

    if ($fromRate === null) {
        return [
            'timestamp'  => $ts,
            'from'       => $from,
            'to'         => $to,
            'rate'		 => null,
            'error'      => "Missing rate for FROM currency: {$from}",
        ];
    }
    if ($toRate === null) {
        return [
            'timestamp'  => $ts,
            'from'       => $from,
            'to'         => $to,
            'rate'		 => null,
            'error'      => "Missing rate for TO currency: {$to}",
        ];
    }
    if (!is_numeric($fromRate) || (float)$fromRate == 0.0) {
        return [
            'timestamp'  => $ts,
            'from'       => $from,
            'to'         => $to,
            'rate'		 => null,
            'error'      => "Invalid FROM rate for {$from}",
        ];
    }

    $multiplier = (float)$toRate / (float)$fromRate;

    if ($scale !== null) {
        $multiplier = round($multiplier, $scale);
    }

    return [
        'timestamp'  => $ts,
        'from'       => $from,
        'to'         => $to,
        'rate' => $multiplier,
    ];
}