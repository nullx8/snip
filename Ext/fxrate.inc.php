<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors', '1'); ini_set('display_startup_errors', '1');

/*
if (!function_exists('fixerFxRate')) {
	require_once(__DIR__.'/../3rd/fixer.io/fixer.io.inc.php');
}

function fxRate($from, $to, $round = null) {
	return fixerFxRate($from, $to, $round);
}

// print_r(fxRate('EUR','usD'));
*/

/**
 * fxHtml outputs just the result with no data .. (good to include in html template code)
 *
 * example fxHtml('eur','usd', 1) just returns the exchange rate 
 */
function fxHtml($from, $to, $value = 1, ?int $round =2) {
	return round($value*fxRate($from, $to, null)['rate'], $round);
}

/**
 * Universal router:
 * amount_to = amount_from * rate
 *
 * Returns: ['timestamp'=>int, 'from'=>string, 'to'=>string, 'rate'=>float|null, (optional) 'error'=>string]
 */

function fxRate(string $from, string $to, ?int $round = null): array
{
    $fromU = strtoupper(trim($from));
    $toU   = strtoupper(trim($to));

    if ($fromU === '' || $toU === '') {
        return ['timestamp'=>time(),'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>'Currency code missing'];
    }

    if ($fromU === $toU) {
        return ['timestamp'=>time(),'from'=>$fromU,'to'=>$toU,'rate'=>1.0];
    }

    $isCrypto = static fn(string $c): bool => in_array($c, ['BTC','ETH'], true);
    $fromIsCrypto = $isCrypto($fromU);
    $toIsCrypto   = $isCrypto($toU);

    // ---- fiat <-> fiat: Fixer only
    if (!$fromIsCrypto && !$toIsCrypto) {
        _ensureFixerLoaded();
        $fx = fixerFxRate($fromU, $toU, 40);

        // normalize key to "rate" if provider returns "multiplier"
        if (is_array($fx) && !isset($fx['rate'])) {
            $r = _fxExtractRate($fx);
            if ($r !== null) $fx['rate'] = $r;
        }

        return $fx;
    }

    // ---- crypto involved: bridge through EUR
    _ensureCryptoLoaded();

    $cryptoCacheTime = 3600; // seconds (your provider shim may ignore/override)
    $ts = time();

    $fromCryptoEur = null; // EUR per 1 crypto
    $toCryptoEur   = null; // EUR per 1 crypto

    if ($fromIsCrypto) {
        $r = cryptoToEur($fromU, $cryptoCacheTime); // expects ['success'=>bool,'eur'=>float,'timestamp'=>int]
        if (!($r['success'] ?? false) || !isset($r['eur']) || !is_numeric($r['eur'])) {
            return ['timestamp'=>$r['timestamp'] ?? time(),'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>$r['error'] ?? 'cryptoToEur error (FROM)'];
        }
        $fromCryptoEur = (float)$r['eur'];
        $ts = (int)($r['timestamp'] ?? $ts);
    }

    if ($toIsCrypto) {
        $r = cryptoToEur($toU, $cryptoCacheTime);
        if (!($r['success'] ?? false) || !isset($r['eur']) || !is_numeric($r['eur'])) {
            return ['timestamp'=>$r['timestamp'] ?? time(),'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>$r['error'] ?? 'cryptoToEur error (TO)'];
        }
        $toCryptoEur = (float)$r['eur'];
        $ts = (int)($r['timestamp'] ?? $ts);
    }

    $rate = null;

    // crypto -> fiat
    if ($fromIsCrypto && !$toIsCrypto) {
        // crypto -> EUR
        if (!$fromCryptoEur || $fromCryptoEur <= 0) {
            return ['timestamp'=>$ts,'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>'Invalid crypto EUR price (FROM)'];
        }

        $rate = $fromCryptoEur;

        // EUR -> TO (only if TO != EUR)
        if ($toU !== 'EUR') {
            _ensureFixerLoaded();
            $fx = fixerFxRate('EUR', $toU, null); // don't round intermediate
            $eurTo = _fxExtractRate($fx);

            if ($eurTo === null) {
                return ['timestamp'=>$fx['timestamp'] ?? $ts,'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>$fx['error'] ?? 'Fixer error (EUR->TO)'];
            }

            $rate *= $eurTo;
            $ts = (int)($fx['timestamp'] ?? $ts); // prefer Fixer dataset timestamp when used
        }
    }

    // fiat -> crypto
    elseif (!$fromIsCrypto && $toIsCrypto) {
        // FROM -> EUR
        $fromToEur = 1.0;

        if ($fromU !== 'EUR') {
            _ensureFixerLoaded();
            $fx = fixerFxRate($fromU, 'EUR', null);
            $fromToEur = _fxExtractRate($fx);

            if ($fromToEur === null) {
                return ['timestamp'=>$fx['timestamp'] ?? $ts,'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>$fx['error'] ?? 'Fixer error (FROM->EUR)'];
            }

            $ts = (int)($fx['timestamp'] ?? $ts);
        }

        // EUR -> crypto = 1 / (crypto -> EUR)
        if (!$toCryptoEur || $toCryptoEur <= 0) {
            return ['timestamp'=>$ts,'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>'Invalid crypto EUR price (TO)'];
        }

        $rate = $fromToEur * (1.0 / $toCryptoEur);
    }

    // crypto -> crypto
    else {
        if (!$fromCryptoEur || $fromCryptoEur <= 0 || !$toCryptoEur || $toCryptoEur <= 0) {
            return ['timestamp'=>$ts,'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>'Invalid crypto EUR price (FROM/TO)'];
        }
        $rate = $fromCryptoEur / $toCryptoEur;
    }

    if ($rate === null || !is_finite($rate)) {
        return ['timestamp'=>$ts,'from'=>$fromU,'to'=>$toU,'rate'=>null,'error'=>'Could not compute rate'];
    }

    if ($round !== null) {
	    $rate = round((float)$rate, (int)$round);
    }

    return ['timestamp'=>$ts,'from'=>$fromU,'to'=>$toU,'rate'=>(float)$rate];
}

function _ensureFixerLoaded(): void
{
    if (!function_exists('fixerFxRate')) {
        require_once __DIR__ . '/../3rd/fixer.io/fixer.io.inc.php';
    }
    if (!function_exists('fixerFxRate')) {
        throw new RuntimeException('fixerFxRate() not available after include');
    }
}

function _ensureCryptoLoaded(): void
{
    if (!function_exists('cryptoToEur')) {
        require_once __DIR__ . '/../3rd/cryptocompare.com/cryptocompare.inc.php';
		function cryptoToEur(string $sym, int $cacheTime = 600): array {
			return cryptocompareToEur($sym, $cacheTime);
		}
	}

    if (!function_exists('cryptoToEur')) {
        throw new RuntimeException('cryptoToEur() not available after include');
    }
}

function _fxExtractRate(array $fx): ?float
{
    if (isset($fx['rate']) && is_numeric($fx['rate'])) return (float)$fx['rate'];
    if (isset($fx['multiplier']) && is_numeric($fx['multiplier'])) return (float)$fx['multiplier'];
    return null;
}
