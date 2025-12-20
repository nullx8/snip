<?php

/**
 * Deep-compare two JSON datasets (strings or arrays) and classify:
 * - match: exact same structure + values
 * - soft_fail: values match where comparable, but fields/items missing on one side
 * - hard_fail: at least one value mismatch (or decode/type mismatch)
 *
 * @param string|array|object $jsonA
 * @param string|array|object $jsonB
 * @param array $opts
 *   - strict_types (bool) default false
 *   - float_epsilon (float) default 1e-12
 *   - ignore_paths (array<string>) default []  (exact path matches, e.g. "$.payout.last_checked")
 *   - ignore_prefixes (array<string>) default [] (prefix matches, e.g. "$.debug")
 *   - compare_list_order (bool) default true  (if false, compares lists as sets)
 *
 * @return array{
 *   status:string,
 *   ok:bool,
 *   mismatches:array<int,array{path:string,a:mixed,b:mixed,reason:string}>,
 *   missing_in_a:array<int,string>,
 *   missing_in_b:array<int,string>,
 *   meta:array<string,mixed>
 * }
 */
function jsondiff_compare($jsonA, $jsonB, array $opts = []): array
{
    $opts = array_merge([
        'strict_types'       => false,
        'float_epsilon'      => 1e-12,
        'ignore_paths'       => [],
        'ignore_prefixes'    => [],
        'compare_list_order' => true,
    ], $opts);

    $a = jsondiff_to_array($jsonA);
    $b = jsondiff_to_array($jsonB);

    if ($a['_ok'] !== true || $b['_ok'] !== true) {
        return [
            'status'       => 'hard_fail',
            'ok'           => false,
            'mismatches'   => [[
                'path'   => '$',
                'a'      => $a['_error'] ?? 'ok',
                'b'      => $b['_error'] ?? 'ok',
                'reason' => 'json_decode_error',
            ]],
            'missing_in_a' => [],
            'missing_in_b' => [],
            'meta'         => ['decode_a' => $a, 'decode_b' => $b],
        ];
    }

    $res = [
        'mismatches'   => [],
        'missing_in_a' => [],
        'missing_in_b' => [],
    ];

    jsondiff_compare_node($a['data'], $b['data'], '$', $opts, $res);

    $status = 'match';
    if (count($res['mismatches']) > 0) {
        $status = 'hard_fail';
    } elseif (count($res['missing_in_a']) > 0 || count($res['missing_in_b']) > 0) {
        $status = 'soft_fail';
    }

    return [
        'status'       => $status,
        'ok'           => ($status === 'match'),
        'mismatches'   => $res['mismatches'],
        'missing_in_a' => $res['missing_in_a'],
        'missing_in_b' => $res['missing_in_b'],
        'meta'         => [
            'strict_types'       => $opts['strict_types'],
            'compare_list_order' => $opts['compare_list_order'],
        ],
    ];
}

/* ---------------- helpers ---------------- */

function jsondiff_to_array($v): array
{
    if (is_string($v)) {
        $data = json_decode($v, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['_ok' => false, '_error' => json_last_error_msg()];
        }
        return ['_ok' => true, 'data' => $data];
    }

    if (is_object($v)) {
        return ['_ok' => true, 'data' => json_decode(json_encode($v), true)];
    }

    if (is_array($v)) {
        return ['_ok' => true, 'data' => $v];
    }

    return ['_ok' => false, '_error' => 'unsupported_input_type'];
}

function jsondiff_should_ignore_path(string $path, array $opts): bool
{
    if (in_array($path, $opts['ignore_paths'], true)) return true;

    foreach ($opts['ignore_prefixes'] as $pfx) {
        if ($pfx !== '' && str_starts_with($path, $pfx)) return true;
    }
    return false;
}

function jsondiff_is_assoc(array $arr): bool
{
    $keys = array_keys($arr);
    return $keys !== range(0, count($arr) - 1);
}

function jsondiff_compare_node($a, $b, string $path, array $opts, array &$res): void
{
    if (jsondiff_should_ignore_path($path, $opts)) return;

    if (is_array($a) && is_array($b)) {
        $aAssoc = jsondiff_is_assoc($a);
        $bAssoc = jsondiff_is_assoc($b);

        if ($aAssoc !== $bAssoc) {
            $res['mismatches'][] = [
                'path' => $path,
                'a' => $a,
                'b' => $b,
                'reason' => 'type_mismatch_array_kind',
            ];
            return;
        }

        if ($aAssoc) {
            $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
            sort($keys);

            foreach ($keys as $k) {
                $p = $path . '.' . $k;

                $aHas = array_key_exists($k, $a);
                $bHas = array_key_exists($k, $b);

                if (!$aHas && $bHas) { $res['missing_in_a'][] = $p; continue; }
                if ($aHas && !$bHas) { $res['missing_in_b'][] = $p; continue; }

                jsondiff_compare_node($a[$k], $b[$k], $p, $opts, $res);
            }
            return;
        }

        // lists
        if ($opts['compare_list_order']) {
            $n = min(count($a), count($b));
            for ($i = 0; $i < $n; $i++) {
                jsondiff_compare_node($a[$i], $b[$i], $path . '[' . $i . ']', $opts, $res);
            }
            if (count($a) > count($b)) {
                for ($i = $n; $i < count($a); $i++) $res['missing_in_b'][] = $path . '[' . $i . ']';
            } elseif (count($b) > count($a)) {
                for ($i = $n; $i < count($b); $i++) $res['missing_in_a'][] = $path . '[' . $i . ']';
            }
            return;
        }

        // lists as sets (unordered)
        $normA = array_map('jsondiff_norm_set_item', $a);
        $normB = array_map('jsondiff_norm_set_item', $b);

        sort($normA);
        sort($normB);

        if ($normA !== $normB) {
            $res['mismatches'][] = [
                'path' => $path,
                'a' => $a,
                'b' => $b,
                'reason' => 'list_set_mismatch',
            ];
        }
        return;
    }

    if (!jsondiff_values_equal($a, $b, $opts)) {
        $res['mismatches'][] = [
            'path' => $path,
            'a' => $a,
            'b' => $b,
            'reason' => $opts['strict_types'] ? 'value_or_type_mismatch' : 'value_mismatch',
        ];
    }
}

function jsondiff_norm_set_item($v): string
{
    if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null) return 'null';
    return (string)$v;
}

function jsondiff_values_equal($a, $b, array $opts): bool
{
    if ($opts['strict_types']) return $a === $b;

    if (is_numeric($a) && is_numeric($b)) {
        $sa = (string)$a;
        $sb = (string)$b;

        if (preg_match('/^-?\d+$/', $sa) && preg_match('/^-?\d+$/', $sb)) {
            $na = ltrim($sa, '+');
            $nb = ltrim($sb, '+');
            $na = preg_replace('/^-?0+(\d)/', '$1', $na);
            $nb = preg_replace('/^-?0+(\d)/', '$1', $nb);
            if ($na === '-0') $na = '0';
            if ($nb === '-0') $nb = '0';
            return $na === $nb;
        }

        if (function_exists('bccomp')) {
            return bccomp($sa, $sb, 18) === 0;
        }

        return abs((float)$a - (float)$b) <= (float)$opts['float_epsilon'];
    }

    return $a == $b;
}