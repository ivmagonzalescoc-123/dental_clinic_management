<?php
$in = __DIR__ . '/../capstone_dental.sql';
$out = __DIR__ . '/../archive/capstone_dental_no_timeslot_2026-02-25.sql';
if (!file_exists($in)) {
    fwrite(STDERR, "Input SQL not found: $in\n");
    exit(2);
}
$fin = fopen($in, 'r');
$fout = fopen($out, 'w');
if (!$fin || !$fout) {
    fwrite(STDERR, "Failed to open files.\n");
    exit(3);
}
while (($line = fgets($fin)) !== false) {
    if (stripos($line, 'timeslot') !== false) continue;
    fwrite($fout, $line);
}
fclose($fin);
fclose($fout);
echo "WROTE: $out\n";
