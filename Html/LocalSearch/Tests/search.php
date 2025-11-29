<?php

// Find ME

include('../search.inc.php');

$q = $_GET['q'] ?? ''; 
echo local_filesystem_search_json($q,'../../');
