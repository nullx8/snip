<?php
	
$k = trim(file_get_contents(__DIR__.'/../3rd/deepseek/.Token'));

require_once(__DIR__.'/../3rd/deepseek/vendor/autoload.php');

use DeepSeek\DeepSeekClient;
use GuzzleHttp\Promise\Utils;

$client = DeepSeekClient::build($k);

// Create multiple async requests
$promises = [
    'response1' => $client->query('Explain quantum computing')->runAsync(),
    'response2' => $client->query('What is machine learning?')->runAsync(),
    'response3' => $client->query('How does AI work?')->runAsync(),
];

// Wait for all promises to complete
$results = GuzzleHttp\Promise\Utils::unwrap($promises);

// Process results
foreach ($results as $key => $response) {
    echo "{$key}: " . $response . "\n\n";
}
