simple and inefficient local file based serach engine

files:
- translation.json
  return helper to get nice outputs
- search.inc.php
  includefile that loads the functions

- tests/search.php
  simple test/example


Example use:
```php
header('Content-Type: application/json');

include('search.inc.php');

$q = $_GET['q'] ?? '';
echo local_filesystem_search_json($q, '/var/www/html/');

```

WARNING:
 Miss use can be dangerous as this search has no limitations and does not help on injections either.
