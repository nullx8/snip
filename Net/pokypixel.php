<?php
declare(strict_types=1);
/*
 * _SESSION['pokypixel_queue'] array() must be set 
 * as a fallback a pokypixel.json can be used
 *
 * example array 

 $_SESSION['pokypixel_queue'] = [
  ['id' => 'node_1', 'url' => 'https://httpbin.org/get?test1', 'maxAge' => 160],
  ['id' => 'node_2', 'url' => 'https://httpbin.org/get?test2', 'maxAge' => 160],
  ['id' => 'stats',  'url' => 'https://httpbin.org/get?stat1',  'maxAge' => 120],
];

 */

if ((isset($_GET['me']))&&($_GET['me']=='true')) {
	 // script alled itself, instant kill
	 flush();
	 die("OK");
}
$debug = false; // set to true for detailed outputs

function finish_request(): void
{
    // Try the “real” function for the current SAPI first
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();   // php-fpm
        return;
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request(); // LiteSpeed/LSAPI
        return;
    }

    // Fallback: best-effort flush (not as strong as the real functions)
    @ob_end_flush();
    @ob_flush();
    flush();
}


$gif = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00"
     . "\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00"
     . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01"
     . "\x00\x3b";

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($debug) {
	header('Content-Type: application/json; charset=utf-8');
	echo "Processing...\n\r";
}
else {
	header('Content-Type: image/gif');
	echo $gif;

	finish_request();
}


ignore_user_abort(true);
set_time_limit(120);


// ------------------------------------------------------------
// SESSION: read state, then RELEASE LOCK before network work
// ------------------------------------------------------------
session_start();


if ((isset($_SESSION['pokypixel_queue']))&&(is_array($_SESSION['pokypixel_queue']))) {
	$queue = $_SESSION['pokypixel_queue'];
}
else {
	// dummy host in case session is empty
echo "fake";
	$thost = 'https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'].'/?me=true';
echo $thost;
	$queue = [
		['id' => 'dummy', 'url' => $thost, 'maxAge' => 6000],
	];
}

// Work budget per call (keeps endpoint fast)
$maxScan = min(count($queue), 30);
$maxMs   = 250;

// Backoff tuning (prevents one broken item from being hammered every call)
$baseBackoffSec = 10;
$maxBackoffSec  = 900;


echo "next";

$state = $_SESSION['pump_state'] ?? [
  'cursor' => 0,
  'fails'  => [], // id => ['n'=>int,'until'=>ts]
];

session_write_close(); // IMPORTANT: don't block the whole app while pumping

$count = count($queue);
if ($count === 0) {
  echo json_encode(['ok' => true, 'note' => 'empty queue']);
  exit;
}

$started = microtime(true);
$scanned = 0;

$cursor = (int)($state['cursor'] ?? 0);
$cursor = ($cursor < 0 || $cursor >= $count) ? 0 : $cursor;

$result = [
  'ok' => true,
  'refreshed' => false,
  'scanned' => 0,
  'processed' => null,
  'cursor_before' => $cursor,
];

for ($step = 0; $step < $maxScan; $step++) {
  if (((microtime(true) - $started) * 1000) > $maxMs) break;

  $idx  = ($cursor + $step) % $count;
  $item = $queue[$idx];
  $id   = $item['id'];
  $url  = $item['url'];
  $maxAge = (int)$item['maxAge'];

  $scanned++;

  // Per-item backoff (session-based, user dependent)
  $fail = $state['fails'][$id] ?? null;
  if ($fail && time() < (int)($fail['until'] ?? 0)) {
    continue;
  }

  // You MUST call getUrl() (no pre-check)
  if (!function_exists('getUrl')) { require_once(__DIR__.'/geturl.inc.php'); }
  $resp = getUrl($url, $maxAge); // adapt to your signature
  syslog(LOG_INFO, "Pokypixel Fetch:".$url."[". $maxAge."]");
  //  print_r($resp);
    
  $http  = (int)($resp['http'] ?? 0);
  $error = (string)($resp['error'] ?? '');
  $age   = (int)($resp['cached'] ?? PHP_INT_MAX);
  $mode  = (string)($resp['mode'] ?? ''); // <-- recommended field

  $result['processed'] = [
    'id' => $id,
    'index' => $idx,
    'http' => $http,
    'cached' => $age,
    'mode' => $mode,
    'error' => $error,
  ];
  $result['scanned'] = $scanned;

  // Decide outcome
/*  if ($mode === 'refreshed') {
    $result['refreshed'] = true;
*/
  if ($age <3) {
    // Clear failure state on success
    unset($state['fails'][$id]);

    // Cursor strategy:
    // move to next item so next call continues forward
    $state['cursor'] = ($idx + 1) % $count;

    // Persist updated state (re-open session briefly)
    session_start();
    $_SESSION['pump_state'] = $state;
    session_write_close();

    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Not refreshed → decide if this item is "problematic"
  // Your rule: cached > maxAge usually indicates timeout/error/stale served
  $problem =
    ($error !== '') ||
    ($http < 200 || $http >= 300) ||
    ($age > $maxAge);

  if ($problem) {
    $n = (int)($state['fails'][$id]['n'] ?? 0) + 1;
    $delay = min($maxBackoffSec, $baseBackoffSec * (2 ** min($n, 8)));

    $state['fails'][$id] = [
      'n' => $n,
      'until' => time() + $delay,
    ];

    // keep scanning next items; don't let one broken item block the queue
    continue;
  }

  // cache_hit (or equivalent) → keep scanning
}

// No refresh triggered this run; advance cursor by scanned steps
$state['cursor'] = ($cursor + $scanned) % $count;

session_start();
$_SESSION['pump_state'] = $state;
header('PokyPixel-state: '.$state);
session_write_close();

header('PokyPixel-result: '.json_encode($result));

if ($debug){
	$result['cursor_after'] = $state['cursor'];
	$result['note'] = 'no refresh triggered (all fresh / backoff / budget hit)';
	echo json_encode($result, JSON_UNESCAPED_SLASHES);
}
