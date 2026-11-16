<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/include/CpuFrequency.php';
use DashboardPowerMode\CpuFrequency;
$path = tempnam(sys_get_temp_dir(), 'dpm-frequency-');
try {
    file_put_contents($path, "processor : 0\ncpu MHz : 800.125\n\nprocessor : 3\ncpu MHz : 2400.500\n\nprocessor : 4\ncpu MHz : 0\n\nprocessor : 5\nmodel name : Synthetic CPU\n\n");
    if (CpuFrequency::read($path) !== [0 => 800125, 3 => 2400500]) throw new RuntimeException('CPU frequencies or logical IDs are wrong.');
    file_put_contents($path, "processor : 0\n\ncpu MHz : 2000\n");
    if (CpuFrequency::read($path) !== []) throw new RuntimeException('A frequency must belong to its processor record.');
    file_put_contents($path, '');
    if (CpuFrequency::read($path) !== [] || CpuFrequency::read($path.'.missing') !== []) throw new RuntimeException('Missing telemetry must be graceful.');
    echo "PASS: sampled GHz parser, sparse CPU IDs, missing and invalid readings\n";
} finally {
    unlink($path);
}
