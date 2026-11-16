<?php
/* SPDX-License-Identifier: GPL-2.0-only */
declare(strict_types=1);

namespace DashboardPowerMode;

final class CpuFrequency
{
    /** Sampled frequencies reported by Linux, keyed by logical CPU number. */
    public static function read(string $path = '/proc/cpuinfo'): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) return [];
        $cpu = null;
        $values = [];
        foreach (explode("\n", $contents) as $line) {
            if (trim($line) === '') {
                $cpu = null;
            } elseif (preg_match('/^processor\s*:\s*(\d+)\s*$/', $line, $match)) {
                $cpu = (int)$match[1];
            } elseif ($cpu !== null && preg_match('/^cpu MHz\s*:\s*(\d+(?:\.\d+)?)\s*$/', $line, $match)) {
                $khz = (int)round((float)$match[1] * 1000);
                if ($khz > 0) $values[$cpu] = $khz;
            }
        }
        return $values;
    }
}
