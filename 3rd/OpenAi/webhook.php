<?php
$raw = file_get_contents("php://input");

file_put_contents(
    __DIR__ . "/c/log_" . time() . ".json",
    $raw . "\n",
    FILE_APPEND
);

print_r($raw);

// Optional: parse response
$data = json_decode($raw, true);

// Example: save extracted text
if (isset($data['transcription']['text'])) {
    file_put_contents(
        __DIR__ . "/c/transcriptions.txt",
        $data['transcription']['text'] . "\n------\n",
        FILE_APPEND
    );
}

echo "OK";

