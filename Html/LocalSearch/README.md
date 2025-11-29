simple and inefficient local file based serach engine

files:
- translation.json
  return helper to get nice outputs
- search.inc.php
  includefile that loads the functions


Example use:
header('Content-Type: application/json');

include('search.inc.php');

$q = $_GET['q'] ?? '';
echo local_filesystem_search_json($q);

