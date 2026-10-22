<?php

/**
 * Turns PHPUnit JUnit failures into GitHub annotations, so failures are
 * visible on the run summary without opening the raw log.
 */
declare(strict_types=1);

$file = $argv[1] ?? 'junit.xml';
$log = $argv[2] ?? null;
$escape = static fn (string $s): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);

$xml = is_file($file) ? @simplexml_load_file($file) : false;

if ($xml === false) {
    // PHPUnit died before writing a report (fatal error): surface the output tail.
    $tail = $log !== null && is_file($log)
        ? implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -60))
        : 'No JUnit report and no log available.';
    printf("::error title=PHPUnit crashed::%s\n", $escape(mb_substr($tail, 0, 6000)));
    exit(0);
}

$root = getcwd().'/';

foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
    $node = $case->failure ?? $case->error;
    $message = mb_substr(trim((string) $node), 0, 1500);
    $path = str_replace($root, '', (string) $case['file']);
    $title = (string) $case['class'].'::'.(string) $case['name'];

    printf("::error file=%s,line=%d,title=%s::%s\n", $path, (int) $case['line'], $escape($title), $escape($message));
}
