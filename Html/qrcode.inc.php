<?php
	
require_once(__DIR__.'/../3rd/phpqrcode/qrlib.php');

// QRcode::png($text, $file, $ecc, $pixel_Size, $frame_Size);


// CreateQr('text', ['file'=>'pathtostore', 'size' => 'size in pixels', 'margin' => 'margin in pixels'])

function CreateQr($content, array $p = []) {
	
	$file = $p['file'] ?? false; 
	$size = $p['size'] ?? 10; // 1-30
	$margin = $p['margin'] ?? 1;
	$ecc = $p['ecc'] ?? 'H'; // L, M, Q and H.

//	QRcode::png($content, $file, $ecc, $pixel_Size, $frame_Size);
        QRcode::png($content, false, $ecc, $size, $margin);

}

//CreateQr('test me now');

//CreateQr('test me now', ['ecc' => 'M']);

//CreateQr('test me now', ['ecc' => 'Q']);

//CreateQr('this works', ['size' => 5]);



