<?php

/**
 * Turns PHPUnit JUnit failures into GitHub annotations, so failures are
 * visible on the run summary without opening the raw log.
 */
declare(strict_types=1);

$file = $argv[1] ?? 'junit.xml';

if (! is_file($file)) {
    fwrite(STDERR, "No JUnit report at {$file}\n");
    exit(0);
}

$xml = simplexml_load_file($file);
$escape = static fn (string $s): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);
$root = getcwd().'/';

foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
    $node = $case->failure ?? $case->error;
    $message = mb_substr(trim((string) $node), 0, 1500);
    $path = str_replace($root, '', (string) $case['file']);
    $title = (string) $case['class'].'::'.(string) $case['name'];

    printf("::error file=%s,line=%d,title=%s::%s\n", $path, (int) $case['line'], $escape($title), $escape($message));
}
