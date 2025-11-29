<?php

function local_filesystem_search_json($query, $root = '.', $translationFile = 'translations.json') {
    $q = strtolower(trim($query));
    if ($q === '') {
        return json_encode([]);
    }

    // Load translations
    $translations = [];
    if (file_exists($translationFile)) {
        $translations = json_decode(file_get_contents($translationFile), true) ?: [];
    }

    // Scan for real files
    $pattern = $root . '/*.{html,htm,php,txt}';
    $files = glob($pattern, GLOB_BRACE);

    $results = [];

    foreach ($files as $filePath) {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            continue;
        }

        if (strpos(strtolower($content), $q) === false) {
            continue;
        }

        $filename = basename($filePath);

        // Extract file title (fallback to filename)
        $title = $filename;
        if (preg_match('/<title>(.*?)<\/title>/i', $content, $m)) {
            $title = trim($m[1]);
        }

        // Translation override?
        if (isset($translations[$filename]['index'])) {
            $t = $translations[$filename]['index'];

            if (!empty($t['title'])) {
                $title = $t['title'];
            }

            $links = !empty($t['links']) ? $t['links'] : [$filename];

            foreach ($links as $link) {
                $results[] = [
                    'title'         => $title,
                    'url'           => $link,
                    'original_file' => $filename,
                    'override'      => true
                ];
            }
            continue;
        }

        // Default result: real file
        $results[] = [
            'title'         => $title,
            'url'           => $filename,
            'original_file' => $filename,
            'override'      => false
        ];
    }

    return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

