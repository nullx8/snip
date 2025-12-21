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

// Write
$t = time();
echo "<pre>s3Put (non existing file ".$t.".json)<br />";
print_r(s3Put(getenv('S3_BUCKET'), $t.'.json', '{"time":"'.$t.'"}', null, 'application/json'));

// Read
echo "<hr>s3Get<br>";
print_r(s3Get(getenv('S3_BUCKET'), $t.'.json',$sfg));

echo "<hr>geturl<br>";
require_once('geturl.inc.php');
print_r(getUrl('s3://eqmesh-env/'.$t.'.json'));

print_r(getUrl('s3://'.getenv('S3_BUCKET').'/'.$t.'.json'));

echo "</pre>";