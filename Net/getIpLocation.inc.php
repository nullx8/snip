<?php

// includes getIpLocation

function getIpLocation($ip, $hl = en) {
	// just a workarround for now
	include(__DIR__.'/geturl.inc.php');
	return getUrl();	
}
