<?php
declare(strict_types=1);

function cryptocompareToEur(string $sym, int $cacheTime = 600): array
{
    static $memo = []; // per-request memo

    $sym = strtoupper(trim($sym));
    if ($sym === '') {
        return ['success'=>false,'timestamp'=>time(),'eur'=>null,'error'=>'Empty symbol'];
    }

    if (isset($memo[$sym])) {
        return $memo[$sym];
    }

    $url  = 'https://min-api.cryptocompare.com/data/price?fsym=' . rawurlencode($sym) . '&tsyms=EUR';
    $resp = getUrl($url, $cacheTime, 'EUR', 5);

    $data = json_decode($resp['data'] ?? '', true);

    if (!is_array($data) || !isset($data['EUR']) || !is_numeric($data['EUR'])) {
        return $memo[$sym] = ['success'=>false,'timestamp'=>time(),'eur'=>null,'error'=>"Bad response for {$sym}"];
    }

    return $memo[$sym] = ['success'=>true,'timestamp'=>time(),'eur'=>(float)$data['EUR'],'error'=>null];
}
