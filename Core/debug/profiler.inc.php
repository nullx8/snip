<?php
	
/*
	Profiling 
	
	include this file 
	
	add profiler('mark', 'note'); // for trackpoints 
	
	at the end do echo nl2br(profiler('report')); for results 
	
*/

function profiler($action, $label = '') {
    static $points = [];
    static $log = [];

    if ($action === 'start') {
        $points = [];
        $log = [];
        $points['start'] = microtime(true);
        return;
    }

    if ($action === 'mark') {
        $now = microtime(true);
        $last = end($points);
        $elapsed = ($now - $last) * 1000; // ms
        $points[$label] = microtime(true);

        $log[] = [
            'label' => $label,
            'time'  => $elapsed
        ];
        return $elapsed;
    }

    if ($action === 'report') {
        if (empty($points)) return "Profiler was not started.\n";

        $total = (end($points) - $points['start']) * 1000;

        $out = "=== PROFILER REPORT ===\n";
        foreach ($log as $entry) {
            $out .= sprintf("%-20s: %.3f ms\n", $entry['label'], $entry['time']);
        }
        $out .= "-----------------------\n";
        $out .= sprintf("Total: %.3f ms\n", $total);

        return $out;
    }
}
profiler('start');