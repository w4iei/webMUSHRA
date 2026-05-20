<?php
/*************************************************************************
         (C) Copyright AudioLabs 2017

This source code is protected by copyright law and international treaties. This source code is made available to You subject to the terms and conditions of the Software License for the webMUSHRA.js Software. Said terms and conditions have been made available to You prior to Your download of this source code. By downloading this source code You agree to be bound by the above mentionend terms and conditions, which can also be found here: https://www.audiolabs-erlangen.de/resources/webMUSHRA. Any unauthorised use of this source code may result in severe civil and criminal penalties, and will be prosecuted to the maximum extent possible under law.

**************************************************************************/

function sanitize($string = '', $is_filename = FALSE)
{
    $value = strtolower(is_scalar($string) ? (string) $string : '');
    $pattern = $is_filename ? '/[^a-z0-9._~-]+/' : '/[^a-z0-9_-]+/';
    $sanitized = preg_replace($pattern, '-', $value);
    $sanitized = trim((string) $sanitized, '-.');

    return $sanitized === '' ? 'session' : $sanitized;
}

function array_value($value)
{
    return is_array($value) ? array_values($value) : array();
}

function string_value($value)
{
    return is_scalar($value) ? (string) $value : '';
}

function csv_value($value)
{
    $string = string_value($value);

    if ($string !== '' && preg_match('/^[=+\-@\t\r]/', $string)) {
        return "'" . $string;
    }

    return $string;
}

function extract_pair_id($trialId)
{
    $trialIdString = string_value($trialId);
    if ($trialIdString !== '' && preg_match('/^[0-9]+$/', $trialIdString)) {
        return (string) intval($trialIdString);
    }

    if (preg_match('/(?:^|_)pair([0-9]+)(?:_|$)/', $trialIdString, $matches)) {
        return (string) intval($matches[1]);
    }

    return '';
}

function trial_responses($trial)
{
    return is_object($trial) && is_array($trial->responses ?? null) ? $trial->responses : array();
}

function lss_rating_count($trials)
{
    foreach ($trials as $trial) {
        if (($trial->type ?? null) != 'likert_single_stimulus') {
            continue;
        }

        foreach (trial_responses($trial) as $response) {
            $ratings = array_value($response->stimulusRating ?? null);
            if (count($ratings) > 0) {
                return count($ratings);
            }
        }
    }

    return 1;
}

function position_values($value, $size)
{
    $values = array_value($value);
    $columns = array();

    for ($i = 0; $i < $size; $i++) {
        $columns[] = csv_value($values[$i] ?? '');
    }

    return $columns;
}

function dataset_rows($trials, $type, $header, $participantValues, $rowBuilder)
{
    $rows = array($header);

    foreach ($trials as $trial) {
        if (($trial->type ?? null) != $type) {
            continue;
        }

        foreach (trial_responses($trial) as $response) {
            $rows[] = array_merge($participantValues, $rowBuilder($trial, $response));
        }
    }

    return $rows;
}

function write_csv_rows($filename, $rows)
{
    if (count($rows) <= 1) {
        return false;
    }

    $hasHeader = is_file($filename) && filesize($filename) > 0;
    $fp = fopen($filename, 'a');
    if ($fp === false) {
        throw new RuntimeException('Unable to open file for writing: ' . $filename);
    }

    for ($i = $hasHeader ? 1 : 0; $i < count($rows); $i++) {
        fputcsv($fp, $rows[$i], ',', '"', '');
    }

    fclose($fp);

    return true;
}

function results_directory($resultsRoot, $testId)
{
    $root = $resultsRoot ?: dirname(__DIR__) . '/results';
    if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create results root: ' . $root);
    }

    $rootPath = realpath($root);
    if ($rootPath === false) {
        throw new RuntimeException('Unable to resolve results root: ' . $root);
    }

    $directory = $rootPath . DIRECTORY_SEPARATOR . sanitize($testId, FALSE);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create results directory: ' . $directory);
    }

    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false) {
        throw new RuntimeException('Unable to resolve results directory: ' . $directory);
    }

    $rootPrefix = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($resolvedDirectory . DIRECTORY_SEPARATOR, $rootPrefix) !== 0) {
        throw new RuntimeException('Resolved results path escaped the results root.');
    }

    return $resolvedDirectory;
}

function request_is_authorized($server)
{
    $remoteAddress = $server['REMOTE_ADDR'] ?? '';
    if ($remoteAddress !== '' && filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }

    $host = preg_replace('/:\d+$/', '', string_value($server['HTTP_HOST'] ?? ''));
    $origin = string_value($server['HTTP_ORIGIN'] ?? '');
    $referer = string_value($server['HTTP_REFERER'] ?? '');
    $source = $origin !== '' ? $origin : $referer;
    $sourceHost = parse_url($source, PHP_URL_HOST);

    return $host !== '' && is_string($sourceHost) && strcasecmp($sourceHost, $host) === 0;
}

function is_rate_limited($server, $rateLimitRoot = null, $windowSeconds = 60, $maxRequests = 60)
{
    $directory = $rateLimitRoot ?: sys_get_temp_dir() . '/webmushra-rate-limit';
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $remoteAddress = string_value($server['REMOTE_ADDR'] ?? 'unknown');
    $filename = $directory . '/' . sha1($remoteAddress) . '.json';
    $now = time();
    $state = array('windowStart' => $now, 'count' => 0);

    $fp = fopen($filename, 'c+');
    if ($fp === false) {
        return false;
    }
    flock($fp, LOCK_EX);

    $content = stream_get_contents($fp);
    if ($content !== '' && $content !== false) {
        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded['windowStart']) && isset($decoded['count'])) {
            $state = $decoded;
        }
    }

    if (($now - (int) $state['windowStart']) >= $windowSeconds) {
        $state = array('windowStart' => $now, 'count' => 0);
    }

    $state['count'] = (int) $state['count'] + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    flock($fp, LOCK_UN);
    fclose($fp);

    return $state['count'] > $maxRequests;
}

function build_csv_datasets($session)
{
    if (!is_object($session)) {
        return array();
    }

    $testId = string_value($session->testId ?? '');
    if ($testId === '') {
        return array();
    }

    $participant = is_object($session->participant ?? null) ? $session->participant : new stdClass();
    $participantNames = array_value($participant->name ?? null);
    $participantResponses = array_value($participant->response ?? null);
    $trials = array_value($session->trials ?? null);
    $participantCount = count($participantNames);

    $participantHeader = array('session_test_id');
    foreach ($participantNames as $name) {
        $participantHeader[] = string_value($name);
    }

    $participantValues = array(csv_value($testId));
    for ($i = 0; $i < $participantCount; $i++) {
        $participantValues[] = csv_value($participantResponses[$i] ?? '');
    }

    $uuid = csv_value($session->uuid ?? '');
    $ratingCount = lss_rating_count($trials);
    $lssHeader = array_merge($participantHeader, array('trial_id'));
    if ($ratingCount > 1) {
        for ($i = 0; $i < $ratingCount; $i++) {
            $lssHeader[] = 'stimuli_rating' . ($i + 1);
        }
    } else {
        $lssHeader[] = 'stimuli_rating';
    }
    $lssHeader[] = 'stimuli';
    $lssHeader[] = 'rating_time';

    return array(
        'mushra' => dataset_rows(
            $trials,
            'mushra',
            array_merge($participantHeader, array('session_uuid', 'trial_id', 'rating_stimulus', 'rating_score', 'rating_time', 'rating_comment')),
            $participantValues,
            function ($trial, $response) use ($uuid) {
                return array(
                    $uuid,
                    csv_value($trial->id ?? ''),
                    csv_value($response->stimulus ?? ''),
                    csv_value($response->score ?? ''),
                    csv_value($response->time ?? ''),
                    csv_value($response->comment ?? ''),
                );
            }
        ),
        'paired_comparison' => dataset_rows(
            $trials,
            'paired_comparison',
            array_merge($participantHeader, array('trial_id', 'pair_id', 'choice_reference', 'choice_non_reference', 'choice_answer', 'choice_time', 'choice_comment')),
            $participantValues,
            function ($trial, $response) {
                return array(
                    csv_value($trial->id ?? ''),
                    csv_value(extract_pair_id($trial->id ?? '')),
                    csv_value($response->reference ?? ''),
                    csv_value($response->nonReference ?? ''),
                    csv_value($response->answer ?? ''),
                    csv_value($response->time ?? ''),
                    csv_value($response->comment ?? ''),
                );
            }
        ),
        'paired_distance' => dataset_rows(
            $trials,
            'paired_distance',
            array_merge($participantHeader, array('trial_id', 'pair_id', 'stimulus_1', 'stimulus_2', 'distance', 'distance_comment', 'distance_time')),
            $participantValues,
            function ($trial, $response) {
                return array(
                    csv_value($trial->id ?? ''),
                    csv_value(extract_pair_id($trial->id ?? '')),
                    csv_value($response->stimulus1 ?? ''),
                    csv_value($response->stimulus2 ?? ''),
                    csv_value($response->distance ?? ''),
                    csv_value($response->comment ?? ''),
                    csv_value($response->time ?? ''),
                );
            }
        ),
        'bs1116' => dataset_rows(
            $trials,
            'bs1116',
            array_merge($participantHeader, array('trial_id', 'rating_reference', 'rating_non_reference', 'rating_reference_score', 'rating_non_reference_score', 'rating_time', 'choice_comment')),
            $participantValues,
            function ($trial, $response) {
                return array(
                    csv_value($trial->id ?? ''),
                    csv_value($response->reference ?? ''),
                    csv_value($response->nonReference ?? ''),
                    csv_value($response->referenceScore ?? ''),
                    csv_value($response->nonReferenceScore ?? ''),
                    csv_value($response->time ?? ''),
                    csv_value($response->comment ?? ''),
                );
            }
        ),
        'lms' => dataset_rows(
            $trials,
            'likert_multi_stimulus',
            array_merge($participantHeader, array('trial_id', 'stimuli_rating', 'stimuli', 'rating_time')),
            $participantValues,
            function ($trial, $response) {
                return array(
                    csv_value($trial->id ?? ''),
                    csv_value($response->stimulusRating ?? ''),
                    csv_value($response->stimulus ?? ''),
                    csv_value($response->time ?? ''),
                );
            }
        ),
        'lss' => dataset_rows(
            $trials,
            'likert_single_stimulus',
            $lssHeader,
            $participantValues,
            function ($trial, $response) use ($ratingCount) {
                $ratings = array_value($response->stimulusRating ?? null);
                $values = array(csv_value($trial->id ?? ''));

                for ($i = 0; $i < $ratingCount; $i++) {
                    $values[] = csv_value($ratings[$i] ?? '');
                }

                $values[] = csv_value($response->stimulus ?? '');
                $values[] = csv_value($response->time ?? '');

                return $values;
            }
        ),
        'spatial_localization' => dataset_rows(
            $trials,
            'localization',
            array_merge($participantHeader, array('trial_id', 'name', 'stimulus', 'position_x', 'position_y', 'position_z')),
            $participantValues,
            function ($trial, $response) {
                return array_merge(
                    array(
                        csv_value($trial->id ?? ''),
                        csv_value($response->name ?? ''),
                        csv_value($response->stimulus ?? ''),
                    ),
                    position_values($response->position ?? null, 3)
                );
            }
        ),
        'spatial_asw' => dataset_rows(
            $trials,
            'asw',
            array_merge($participantHeader, array('trial_id', 'name', 'stimulus', 'position_outerRight_x', 'position_outerRight_y', 'position_outerRight_z', 'position_innerRight_x', 'position_innerRight_y', 'position_innerRight_z', 'position_innerLeft_x', 'position_innerLeft_y', 'position_innerLeft_z', 'position_outerLeft_x', 'position_outerLeft_y', 'position_outerLeft_z')),
            $participantValues,
            function ($trial, $response) {
                return array_merge(
                    array(
                        csv_value($trial->id ?? ''),
                        csv_value($response->name ?? ''),
                        csv_value($response->stimulus ?? ''),
                    ),
                    position_values($response->position_outerRight ?? null, 3),
                    position_values($response->position_innerRight ?? null, 3),
                    position_values($response->position_innerLeft ?? null, 3),
                    position_values($response->position_outerLeft ?? null, 3)
                );
            }
        ),
        'spatial_hwd' => dataset_rows(
            $trials,
            'hwd',
            array_merge($participantHeader, array('trial_id', 'name', 'stimulus', 'position_outerRight_x', 'position_outerRight_y', 'position_outerRight_z', 'position_innerRight_x', 'position_innerRight_y', 'position_innerRight_z', 'position_innerLeft_x', 'position_innerLeft_y', 'position_innerLeft_z', 'position_outerLeft_x', 'position_outerLeft_y', 'position_outerLeft_z', 'height', 'depth')),
            $participantValues,
            function ($trial, $response) {
                return array_merge(
                    array(
                        csv_value($trial->id ?? ''),
                        csv_value($response->name ?? ''),
                        csv_value($response->stimulus ?? ''),
                    ),
                    position_values($response->position_outerRight ?? null, 3),
                    position_values($response->position_innerRight ?? null, 3),
                    position_values($response->position_innerLeft ?? null, 3),
                    position_values($response->position_outerLeft ?? null, 3),
                    array(
                        csv_value($response->height ?? ''),
                        csv_value($response->depth ?? ''),
                    )
                );
            }
        ),
        'spatial_lev' => dataset_rows(
            $trials,
            'lev',
            array_merge($participantHeader, array('trial_id', 'name', 'stimulus', 'position_center_x', 'position_center_y', 'position_center_z', 'position_height_x', 'position_height_y', 'position_height_z', 'position_width1_x', 'position_width1_y', 'position_width1_z', 'position_width2_x', 'position_width2_y', 'position_width2_z')),
            $participantValues,
            function ($trial, $response) {
                return array_merge(
                    array(
                        csv_value($trial->id ?? ''),
                        csv_value($response->name ?? ''),
                        csv_value($response->stimulus ?? ''),
                    ),
                    position_values($response->position_center ?? null, 3),
                    position_values($response->position_height ?? null, 3),
                    position_values($response->position_width1 ?? null, 3),
                    position_values($response->position_width2 ?? null, 3)
                );
            }
        ),
    );
}

function write_results($post, $resultsRoot = null)
{
    $sessionParam = $post['sessionJSON'] ?? null;
    if (!is_string($sessionParam) || $sessionParam === '') {
        return array();
    }

    $session = json_decode($sessionParam);
    $datasets = build_csv_datasets($session);
    if ($datasets === array()) {
        return array();
    }

    $directory = results_directory($resultsRoot, $session->testId ?? '');
    $writtenFiles = array();

    foreach ($datasets as $name => $rows) {
        $filename = $directory . '/' . $name . '.csv';
        if (write_csv_rows($filename, $rows)) {
            $writtenFiles[] = $filename;
        }
    }

    return $writtenFiles;
}

function handle_write_request($post, $server, $resultsRoot = null, $rateLimitRoot = null)
{
    if (!request_is_authorized($server)) {
        return array('status' => 403, 'message' => 'Forbidden', 'files' => array());
    }

    if (is_rate_limited($server, $rateLimitRoot)) {
        return array('status' => 429, 'message' => 'Too many requests', 'files' => array());
    }

    try {
        $writtenFiles = write_results($post, $resultsRoot);
        if (count($writtenFiles) === 0) {
            return array(
                'status' => 500,
                'message' => 'No result files were written. Check the server results directory or bind mount.',
                'files' => array(),
            );
        }

        return array('status' => 200, 'message' => '', 'files' => $writtenFiles);
    } catch (RuntimeException $exception) {
        return array('status' => 500, 'message' => 'Unable to write results', 'files' => array());
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = handle_write_request($_POST, $_SERVER);
    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => $result['status'] === 200 ? 'ok' : 'error',
        'message' => $result['message'],
        'fileCount' => count($result['files']),
    ));
}
