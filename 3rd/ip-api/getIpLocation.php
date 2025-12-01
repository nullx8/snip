<?php

// just a quick test, this needs some cleaning up!!
// cache control
// language limitations
// abuse checks

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// forced caching
require(__DIR__.'/../../Net/geturl.inc.php');

$hl = $_REQUEST['hl'] ?? 'en';

if (isset($_REQUEST['ip'])) {
    $ip = $_REQUEST['ip'];
    $r = json_encode(GetUrl(
        'http://ip-api.com/json/'.$ip.
        '?fields=status,message,country,countryCode,region,regionName,city,zip,timezone,org,as,reverse,hosting,query&lang='.$hl,
        604800,
        'country'
    ));
} else {
    $r = json_encode(["error" => "no IP!"]);
}
//print_r($r);
die($r);