<?php

error_reporting(E_ALL); ini_set('display_errors', '1'); ini_set('display_startup_errors', '1');

if (!function_exists('fixerFxRate')) {
	require_once(__DIR__.'/../3rd/fixer.io/fixer.io.inc.php');
}

function fxRate($from, $to, $round = null) {
	return fixerFxRate($from, $to, $round);
}

print_r(fxRate('EUR','usD'));
