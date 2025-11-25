<?php


// Single Use file to be used in a Apple Shortcut to record voice and transscribe it for more use locally.
// to be called with HTTP Herader 'check' containing the last 6 digits of the OpenAI API key, and a Audio file as HTTP POST
// short gossip about its creation (no technical details here https://0x8.in.th/bye-bye-siri

$settings = parse_ini_file(__DIR__.'/.env');

if ((!isset($settings['APIKEY']))||(!isset($settings['LOGFILE']))||(!isset($settings['CACHE']))) {
	die('CONFIG ERROR: APIKEY,LOGFILE,CACHE expected in ./.env');
}

$apiKey = trim(file_get_contents(__DIR__.$settings['APIKEY']));

// primitive Security check (compares last 6 digits of API-Key with http check header
if (getallheaders()['check']!= substr($apiKey, -6)) {
	die('Thanks for playing');
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    echo "UPLOAD ERROR\n";
    var_dump($_FILES);
    exit;
}

$ts = time();
$ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
$audioFile = $settings['CACHE']."/s2t.{$ts}." . $ext;
$resultFile = $settings['CACHE']."/s2t.{$ts}.txt";
$resultLog = $settings['LOGFILE'];

move_uploaded_file($_FILES['audio']['tmp_name'], $audioFile);

$curl = curl_init();

// FORCE file object creation explicitly
$fileObject = curl_file_create($audioFile, mime_content_type($audioFile), basename($audioFile));

$postFields = [
    "model"           => "gpt-4o-mini-transcribe",
	"language"        => "en",
    "prompt"			=> "always return results in english language",
    "response_format" => "json",
    "file"            => $fileObject
];

curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.openai.com/v1/audio/transcriptions",
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $apiKey"
        // do NOT set Content-Type manually
    ],
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_VERBOSE => true,
]);

$response = curl_exec($curl);
$http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$err  = curl_error($curl);
curl_close($curl);

file_put_contents($resultFile, "HTTP: $http\nERR:$err\nRES:$response");

$out = array();
$out['http'] = $http;
$out['err'] = $err;
$out['ts'] = $ts;
$out['response'] = json_decode($response);

$text = json_decode($response);
if ((isset($text->text))&&(strlen($text->text)>1)) {
	$out = $text->text;
} else {
	$out['text'] = "?";
}

if ((strlen($err)>1)) {
	file_put_contents($resultLog, date('Y-m-d H:i:s')."\nHTTP: $http\nERR:$err\nRES:$response\n\n", FILE_APPEND | LOCK_EX);
}
else {
	file_put_contents($resultLog, date('Y-m-d H:i:s')."\n".$text->text."\n\n", FILE_APPEND | LOCK_EX);
}
touch($resultLog, (time()+1)); // makes sure the logfile is the newest one

die(json_encode($out));