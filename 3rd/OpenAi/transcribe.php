<?php

function sendToOpenAI($filePath, $webhookUrl) {

    $apiKey = file_get_contents(__DIR__.'/.Token');

    $curl = curl_init();

    $postFields = [
        "file" => new CURLFile($filePath),
        "model" => "gpt-4o-transcribe"
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/audio/transcriptions",
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POSTFIELDS => $postFields
    ]);

    $response = curl_exec($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

	echo $response . '##'. $info;
	


    // Forward it to webhook regardless of success
    $payload = [
        "status" => ($info['http_code'] === 200) ? "ok" : "error",
        "transcription" => json_decode($response, true),
        "filename" => basename($filePath),
        "received_at" => time()
    ];

    forwardWebhook($payload, $webhookUrl);
}

function forwardWebhook($data, $url) {
    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $r = curl_exec($curl);
    curl_close($curl);
	return $r;
	
}

