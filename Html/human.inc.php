<?php

// ToDo remove Sessions and change to array style 
/*
like this

function HumanAgo(int $timestamp, array $p = [])
{
	$lang = $p['hl'] ?? 'en';
	$depth = int $p['depth'] ?? 2;
	$short = bool $p['short'] ?? false;
	$justweekday = bool['justweekday'] ?? false;
}

to be called like humanago(time(), ['hl'=>'en', 'depth'=>1]);
 */

function HumanAgo(int $timestamp, int $depth = 2, string $lang = 'en', bool $short = false): string
{
    $maps = [
        'en' => [
            'units' => [
                'year'   => ['year', 'years', 'y'],
                'month'  => ['month', 'months', 'mo'],
                'week'   => ['week', 'weeks', 'w'],
                'day'    => ['day', 'days', 'd'],
                'hour'   => ['hour', 'hours', 'h'],
                'minute' => ['minute', 'minutes', 'm'],
                'second' => ['second', 'seconds', 's'],
            ],
            'now' => 'Just now',
            'past_prefix' => '', 'past_suffix' => ' ago',
            'fut_prefix'  => 'in ', 'fut_suffix'  => '',
        ],
        'de' => [
            'units' => [
                'year'   => ['Jahr', 'Jahre', 'J'],
                'month'  => ['Monat', 'Monate', 'Mon'],
                'week'   => ['Woche', 'Wochen', 'Wo'],
                'day'    => ['Tag', 'Tage', 'T'],
                'hour'   => ['Stunde', 'Stunden', 'Std'],
                'minute' => ['Minute', 'Minuten', 'Min'],
                'second' => ['Sekunde', 'Sekunden', 'Sek'],
            ],
            'now' => 'Gerade eben',
            'past_prefix' => 'vor ', 'past_suffix' => '',
            'fut_prefix'  => 'in ',  'fut_suffix'  => '',
        ],
    ];

   	if ((isset($_SESSION['hl']))&&(($_SESSION['hl']=="en")||($_SESSION['hl']=="de"))) {
		$lang = $maps[$_SESSION['hl']];
	}
	else {
	    $lang = $maps[$lang] ?? $maps['en'];
	}
    $units = $lang['units'];

    $diff = $timestamp - time(); // >0 future, <0 past
    $future = $diff > 0;
    $secs = abs($diff);

    if ($secs < 1) return $lang['now'];

    $lengths = [
        'year' => 31536000,
        'month' => 2592000,
        'week' => 604800,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
        'second' => 1,
    ];

    $parts = [];
    foreach ($lengths as $u => $s) {
        if (($v = intdiv($secs, $s)) > 0) {
            if ($short) {
                $parts[] = "{$v}{$units[$u][2]}"; // short label
            } else {
                $word = ($v === 1) ? $units[$u][0] : $units[$u][1];
                $parts[] = "$v $word";
            }
            $secs -= $v * $s;
            if (count($parts) >= $depth) break;
        }
    }

    $text = implode($short ? ' ' : ', ', $parts);

    return $future
        ? $lang['fut_prefix'] . $text . $lang['fut_suffix']
        : $lang['past_prefix'] . $text . $lang['past_suffix'];
}

function humanDate($unix, $lang = 'en', $filter = 'none') {
    // Short weekday names
    $days = [
        'en' => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
        'de' => ['So','Mo','Di','Mi','Do','Fr','Sa'],
    ];
    $dayslong = [
        'en' => ['Sunday','Monday','Tuesday','Wedesday','Thursday','Friday','Saturday'],
        'de' => ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'],
    ];

    // Fallback to English if invalid language passed
    if (!isset($days[$lang])) {
        $lang = 'en';
    }

    // Extract weekday number (0–6)
    $weekdayIndex = date('w', $unix);

    if ($filter == 'dayonly') {
	    $days = $dayslong;
		return $days[$lang][$weekdayIndex];
	}
	if ($lang == "de") {
	    // Create formatted date (e.g., 14.02.2025 14:33)
		$dateString = date('d.m.Y H:i', $unix);
		// Combine: Mo 2025-02-14
		return $days[$lang][$weekdayIndex] . ' ' . $dateString;
	}
	else {
	    // Create formatted date (e.g., 2025-02-14 14:33)
		$dateString = date('Y-m-d H:i', $unix);
		// Combine: Mo 2025-02-14
		return $days[$lang][$weekdayIndex] . ' ' . $dateString;
	}
}

function HumanShortNumber($num, $minConvert = 1000) {
    // No conversion below threshold
    if ($num < $minConvert) {
        return (string)$num;
    }

    $units = [
        12 => 't',   // trillion
        9  => 'b',   // billion
        6  => 'm',   // million
        3  => 'k',   // thousand
    ];

    foreach ($units as $power => $suffix) {
        if ($num >= pow(10, $power)) {
            // Keep one decimal if needed (e.g., 1.2m)
            $value = $num / pow(10, $power);
            $formatted = (intval($value) == $value)
                ? intval($value) 
                : round($value, 1);
            return $formatted . $suffix;
        }
    }

    return (string)$num;
}
