<?php
	
if($_SERVER['REMOTE_ADDR']!= '172.16.67.28') { die("nope"); }
if(empty($_REQUEST['q'])) { die("question required"); }

$k = trim(file_get_contents(__DIR__.'/../../3rd/deepseek/.Token'));

require_once(__DIR__.'/../../3rd/deepseek/vendor/autoload.php');

use DeepSeek\DeepSeekClient;

$response = DeepSeekClient::build($k)
    ->query(urldecode($_REQUEST['q']))
    ->run();

echo "<pre>";
echo $response;

