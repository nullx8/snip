<?php

require __DIR__ . '/../simpleS3.inc.php';



$cfg = [
  'access_key' => getenv('S3_ACCESS_KEY'),
  'secret_key' => getenv('S3_SECRET_KEY'),
  'region'     => getenv('S3_REGION'),              // e.g. nyc3
  'endpoint'   => getenv('S3_ENDPOINT'),            // e.g. nyc3.digitaloceanspaces.com
  'use_path_style' => false,
  'timeout' => 10,
];

// Bucket is just the bucket name
$bucket = getenv('S3_BUCKET');

// Key should be without leading slash (your function tolerates it, but keep it clean)
$key = 'testfile.json';

// Write
s3_write_file($bucket, $key, '{"hello":"world"}', $cfg, 'application/json');

// Read
echo s3_read_file($bucket, $key, $cfg);