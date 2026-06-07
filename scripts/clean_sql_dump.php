<?php
$path = dirname(__DIR__) . '/capstone_dental.sql';
$lines = file($path, FILE_IGNORE_NEW_LINES);
$out = [];

foreach ($lines as $line) {
    if (preg_match('/^\s*(--|#)/', $line)) {
        continue;
    }
    $line = preg_replace('/\/\*.*?\*\//', '', $line);
    $line = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $line);
    $out[] = rtrim($line);
}

file_put_contents($path, implode(PHP_EOL, $out) . PHP_EOL);

echo 'Lines in: ' . count($lines) . PHP_EOL;
echo 'Lines out: ' . count($out) . PHP_EOL;
