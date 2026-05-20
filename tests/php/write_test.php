<?php

require_once __DIR__ . '/../../service/write.php';

function assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assert_true($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function read_csv_rows($filename)
{
    $rows = array();
    $handle = fopen($filename, 'r');

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

function remove_directory($directory)
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . '/' . $item;
        if (is_dir($path)) {
            remove_directory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

$resultsRoot = sys_get_temp_dir() . '/webmushra_php_tests_' . uniqid();
$rateLimitRoot = sys_get_temp_dir() . '/webmushra_php_rate_tests_' . uniqid();

assert_same(array(), write_results(array(), $resultsRoot), 'Missing sessionJSON should be ignored.');
assert_same(array(), write_results(array('sessionJSON' => '{broken'), $resultsRoot), 'Invalid JSON should be ignored.');

$emptySession = array(
    'testId' => 'Empty Session',
    'uuid' => 'empty-uuid',
    'participant' => array(
        'name' => array('email'),
        'response' => array('listener@example.com'),
    ),
    'trials' => array(),
);
assert_same(array(), write_results(array('sessionJSON' => json_encode($emptySession)), $resultsRoot), 'Empty trials should not write files.');

$session = array(
    'testId' => 'PHP 8.4 Regression Test',
    'uuid' => 'session-123',
    'participant' => array(
        'name' => array('email', 'age'),
        'response' => array('=listener@example.com', '31'),
    ),
    'trials' => array(
        array(
            'type' => 'mushra',
            'id' => 'm1',
            'responses' => array(
                array(
                    'stimulus' => 'codec-a',
                    'score' => 85,
                    'time' => 11,
                    'comment' => 'good',
                ),
            ),
        ),
        array(
            'type' => 'paired_comparison',
            'id' => 'pc1',
            'responses' => array(
                array(
                    'reference' => 'ref',
                    'nonReference' => 'cmp',
                    'answer' => 'cmp',
                    'time' => 8,
                    'comment' => 'preferred',
                ),
            ),
        ),
        array(
            'type' => 'bs1116',
            'id' => 'bs1',
            'responses' => array(
                array(
                    'reference' => 'ref',
                    'nonReference' => 'cmp',
                    'referenceScore' => 5,
                    'nonReferenceScore' => 2,
                    'time' => 9,
                    'comment' => 'clear difference',
                ),
            ),
        ),
        array(
            'type' => 'likert_multi_stimulus',
            'id' => 'lms1',
            'responses' => array(
                array(
                    'stimulusRating' => 4,
                    'stimulus' => 'stim-a',
                    'time' => 7,
                ),
            ),
        ),
        array(
            'type' => 'likert_single_stimulus',
            'id' => 'lss1',
            'responses' => array(
                array(
                    'stimulusRating' => array(2, 5),
                    'stimulus' => 'stim-b',
                    'time' => 6,
                ),
            ),
        ),
        array(
            'type' => 'localization',
            'id' => 'loc1',
            'responses' => array(
                array(
                    'name' => 'front',
                    'stimulus' => 'stim-c',
                    'position' => array(1, 2, 3),
                ),
            ),
        ),
        array(
            'type' => 'asw',
            'id' => 'asw1',
            'responses' => array(
                array(
                    'name' => 'wide',
                    'stimulus' => 'stim-d',
                    'position_outerRight' => array(1, 2, 3),
                    'position_innerRight' => array(4, 5, 6),
                    'position_innerLeft' => array(7, 8, 9),
                    'position_outerLeft' => array(10, 11, 12),
                ),
            ),
        ),
        array(
            'type' => 'hwd',
            'id' => 'hwd1',
            'responses' => array(
                array(
                    'name' => 'depth',
                    'stimulus' => 'stim-e',
                    'position_outerRight' => array(1, 2, 3),
                    'position_innerRight' => array(4, 5, 6),
                    'position_innerLeft' => array(7, 8, 9),
                    'position_outerLeft' => array(10, 11, 12),
                    'height' => 13,
                    'depth' => 14,
                ),
            ),
        ),
        array(
            'type' => 'lev',
            'id' => 'lev1',
            'responses' => array(
                array(
                    'name' => 'elevated',
                    'stimulus' => 'stim-f',
                    'position_center' => array(1, 2, 3),
                    'position_height' => array(4, 5, 6),
                    'position_width1' => array(7, 8, 9),
                    'position_width2' => array(10, 11, 12),
                ),
            ),
        ),
    ),
);

$writtenFiles = write_results(array('sessionJSON' => json_encode($session)), $resultsRoot);
assert_same(9, count($writtenFiles), 'Expected one CSV per supported trial type in the fixture.');

$sessionDirectory = $resultsRoot . '/' . sanitize($session['testId']);
$lssRows = read_csv_rows($sessionDirectory . '/lss.csv');
assert_same(
    array('session_test_id', 'email', 'age', 'trial_id', 'stimuli_rating1', 'stimuli_rating2', 'stimuli', 'rating_time'),
    $lssRows[0],
    'LSS header should be derived from the first LSS response, not the first trial in the session.'
);
assert_same(
    array('PHP 8.4 Regression Test', "'=listener@example.com", '31', 'lss1', '2', '5', 'stim-b', '6'),
    $lssRows[1],
    'LSS row should preserve participant data and escape CSV formulas.'
);

$mushraRows = read_csv_rows($sessionDirectory . '/mushra.csv');
assert_same(
    array('PHP 8.4 Regression Test', "'=listener@example.com", '31', 'session-123', 'm1', 'codec-a', '85', '11', 'good'),
    $mushraRows[1],
    'MUSHRA row should be written unchanged.'
);

write_results(array('sessionJSON' => json_encode($session)), $resultsRoot);
$mushraRows = read_csv_rows($sessionDirectory . '/mushra.csv');
assert_same(3, count($mushraRows), 'Appending results should not duplicate the CSV header.');
assert_true($mushraRows[1] === $mushraRows[2], 'Second write should append another data row.');

$emptyHeaderSession = $session;
$emptyHeaderSession['testId'] = 'Headerless File';
$headerlessDirectory = $resultsRoot . '/' . sanitize($emptyHeaderSession['testId']);
mkdir($headerlessDirectory, 0777, true);
touch($headerlessDirectory . '/mushra.csv');
write_results(array('sessionJSON' => json_encode($emptyHeaderSession)), $resultsRoot);
$headerlessRows = read_csv_rows($headerlessDirectory . '/mushra.csv');
assert_same('session_test_id', $headerlessRows[0][0], 'An empty existing CSV should still receive a header row.');

$pathSession = $session;
$pathSession['testId'] = '../Attempted Escape';
$pathFiles = write_results(array('sessionJSON' => json_encode($pathSession)), $resultsRoot);
assert_true(
    strpos($pathFiles[0], realpath($resultsRoot) . DIRECTORY_SEPARATOR) === 0,
    'Sanitized result paths must stay inside the results root.'
);

$allowedServer = array(
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_HOST' => 'example.com',
    'HTTP_ORIGIN' => 'https://example.com',
);
$deniedServer = array(
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_HOST' => 'example.com',
    'HTTP_ORIGIN' => 'https://attacker.example',
);

$allowedResult = handle_write_request(array('sessionJSON' => json_encode($session)), $allowedServer, $resultsRoot, $rateLimitRoot);
assert_same(200, $allowedResult['status'], 'Same-origin requests should be accepted.');
assert_true(count($allowedResult['files']) > 0, 'Accepted requests should report at least one written file.');

$emptyWriteResult = handle_write_request(array('sessionJSON' => json_encode($emptySession)), $allowedServer, $resultsRoot, $rateLimitRoot);
assert_same(500, $emptyWriteResult['status'], 'Requests that write no result files should be reported as failures.');
assert_same(
    'No result files were written. Check the server results directory or bind mount.',
    $emptyWriteResult['message'],
    'Zero-write failures should return a concrete diagnostic message.'
);

$deniedResult = handle_write_request(array('sessionJSON' => json_encode($session)), $deniedServer, $resultsRoot, $rateLimitRoot);
assert_same(403, $deniedResult['status'], 'Cross-origin requests should be rejected.');

for ($i = 0; $i < 60; $i++) {
    $lastRateResult = handle_write_request(array('sessionJSON' => json_encode($session)), $allowedServer, $resultsRoot, $rateLimitRoot);
}
assert_same(429, $lastRateResult['status'], 'Rate limiting should trigger after repeated requests in the same window.');

remove_directory($resultsRoot);
remove_directory($rateLimitRoot);
echo "write.php regression tests passed\n";
