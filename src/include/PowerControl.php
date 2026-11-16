<?php
/* SPDX-License-Identifier: GPL-2.0-only */
declare(strict_types=1);

namespace DashboardPowerMode;

final class PowerControl
{
    private string $root;
    private string $config;
    private string $lock;
    private string $tips;
    private $writer;
    private $reader;

    public function __construct(
        string $root = '/sys/devices/system/cpu',
        string $config = '/boot/config/plugins/dashboard-power-mode/settings.json',
        string $lock = '/var/lock/dashboard-power-mode.lock',
        string $tips = '/boot/config/plugins/tips.and.tweaks/tips.and.tweaks.cfg',
        ?callable $writer = null,
        ?callable $reader = null
    ) {
        $this->root = rtrim($root, '/');
        $this->config = $config;
        $this->lock = $lock;
        $this->tips = $tips;
        $this->writer = $writer;
        $this->reader = $reader;
    }

    private function read(string $path): string
    {
        $value = $this->reader !== null ? ($this->reader)($path) : @file_get_contents($path);
        if ($value === false) {
            throw new \RuntimeException('Cannot read CPU control: '.basename($path));
        }
        return trim($value);
    }

    private function number(string $path): int
    {
        $value = $this->read($path);
        if (!ctype_digit($value) || (int)$value <= 0) {
            throw new \RuntimeException('Invalid CPU frequency reported by the kernel.');
        }
        return (int)$value;
    }

    private function write(string $path, string $value): void
    {
        if ($this->writer !== null) {
            ($this->writer)($path, $value);
        } elseif (@file_put_contents($path, $value."\n") === false) {
            throw new \RuntimeException('Cannot write CPU control: '.basename($path));
        }
    }

    private function settle(string $path, string $expected): string
    {
        // CPUFreq QoS updates can complete asynchronously after a successful sysfs write.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $actual = $this->read($path);
            if ($actual === $expected) return $actual;
            usleep(5000);
        }
        return $this->read($path);
    }

    private function policyPaths(): array
    {
        $paths = glob($this->root.'/cpufreq/policy[0-9]*', GLOB_ONLYDIR) ?: [];
        // Legacy CPUFreq layouts may expose only cpuN/cpufreq symlinks.
        if (!$paths) {
            foreach (glob($this->root.'/cpu[0-9]*/cpufreq', GLOB_ONLYDIR) ?: [] as $path) {
                $paths[] = realpath($path) ?: $path;
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_NATURAL);
        return array_values(array_filter($paths, static function ($path) {
            return is_readable($path.'/scaling_governor');
        }));
    }

    private function turboControls(array $paths): array
    {
        $intel = $this->root.'/intel_pstate/no_turbo';
        $global = $this->root.'/cpufreq/boost';
        if (is_readable($intel)) {
            return [['path' => $intel, 'inverted' => true]];
        }
        if (is_readable($global)) {
            return [['path' => $global, 'inverted' => false]];
        }
        $controls = [];
        foreach ($paths as $path) {
            if (!is_readable($path.'/boost')) {
                return []; // A server-wide toggle must cover every policy.
            }
            $controls[] = ['path' => $path.'/boost', 'inverted' => false];
        }
        return $controls;
    }

    private function uniform(array $values)
    {
        return $values && count(array_unique($values, SORT_REGULAR)) === 1 ? $values[0] : null;
    }

    public function status(): array
    {
        $paths = $this->policyPaths();
        $policies = [];
        $common = null;
        $governorWritable = (bool)$paths;
        $capWritable = (bool)$paths;
        foreach ($paths as $path) {
            $governors = preg_split('/\s+/', $this->read($path.'/scaling_available_governors'), -1, PREG_SPLIT_NO_EMPTY);
            $common = $common === null ? $governors : array_values(array_intersect($common, $governors));
            $governorWritable = $governorWritable && is_writable($path.'/scaling_governor');
            $hasCap = is_readable($path.'/scaling_min_freq') && is_readable($path.'/scaling_max_freq')
                && is_readable($path.'/cpuinfo_min_freq') && is_readable($path.'/cpuinfo_max_freq');
            $capWritable = $capWritable && $hasCap && is_writable($path.'/scaling_max_freq');
            $policies[] = [
                'id' => basename($path),
                'driver' => is_readable($path.'/scaling_driver') ? $this->read($path.'/scaling_driver') : 'unknown',
                'governors' => $governors,
                'governor' => $this->read($path.'/scaling_governor'),
                'min_khz' => $hasCap ? $this->number($path.'/scaling_min_freq') : null,
                'max_khz' => $hasCap ? $this->number($path.'/scaling_max_freq') : null,
                'hw_min_khz' => $hasCap ? $this->number($path.'/cpuinfo_min_freq') : null,
                'hw_max_khz' => $hasCap ? $this->number($path.'/cpuinfo_max_freq') : null,
            ];
        }
        $common = $common ?: [];
        sort($common);
        $controls = $this->turboControls($paths);
        $boost = [];
        $turboWritable = (bool)$controls;
        foreach ($controls as $control) {
            $value = $this->read($control['path']);
            $turboWritable = $turboWritable && is_writable($control['path']) && in_array($value, ['0', '1'], true);
            $boost[] = $value === ($control['inverted'] ? '0' : '1');
        }
        $min = $capWritable ? max(array_column($policies, 'min_khz')) : null;
        $max = $capWritable ? min(array_column($policies, 'hw_max_khz')) : null;
        $tips = is_readable($this->tips) ? @parse_ini_file($this->tips, false, INI_SCANNER_RAW) : [];
        $conflict = !empty($tips['CPU_GOVERNOR']) || ($tips['SCHEDULED'] ?? 'no') === 'yes'
            || ($tips['TURBO_SCHEDULE'] ?? 'no') === 'yes';
        return [
            'policies' => $policies,
            'drivers' => array_values(array_unique(array_column($policies, 'driver'))),
            'governors' => $common,
            'governor_supported' => (bool)$paths && $governorWritable && (bool)$common,
            'governor' => $this->uniform(array_column($policies, 'governor')),
            'presets' => [
                'efficiency' => in_array('powersave', $common, true) ? 'powersave' : null,
                'balanced' => in_array('ondemand', $common, true) ? 'ondemand'
                    : (in_array('balance_power', $common, true) ? 'balance_power' : null),
                'performance' => in_array('performance', $common, true) ? 'performance' : null,
            ],
            'turbo' => ['supported' => $turboWritable, 'enabled' => $this->uniform($boost)],
            'frequency' => [
                'supported' => (bool)$paths && $capWritable && $min <= $max,
                'min_khz' => $min, 'max_khz' => $max,
                'cap_khz' => $this->uniform(array_column($policies, 'max_khz')),
                'effective_caps_khz' => array_values(array_unique(array_filter(array_column($policies, 'max_khz')))),
            ],
            'tips_conflict' => $conflict,
            'saved' => $this->load(),
        ];
    }

    private function load(): array
    {
        if (!file_exists($this->config)) {
            return [];
        }
        $data = json_decode($this->read($this->config), true);
        if (!is_array($data) || array_diff(array_keys($data), ['governor', 'turbo', 'max_freq_khz'])) {
            throw new \RuntimeException('Saved power settings are invalid. Restore settings.json from backup or remove it.');
        }
        return $data;
    }

    private function save(array $data): void
    {
        $dir = dirname($this->config);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the plugin configuration directory.');
        }
        $temporary = @tempnam($dir, '.settings-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot save power settings.');
        }
        try {
            if (@file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n") === false) {
                throw new \RuntimeException('Cannot save power settings.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $this->config)) {
                throw new \RuntimeException('Cannot replace saved power settings.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function validate(array $values, array $status): void
    {
        if (!$values || array_diff(array_keys($values), ['governor', 'turbo', 'max_freq_khz'])) {
            throw new \InvalidArgumentException('Supply only governor, turbo, or max_freq_khz.');
        }
        if (array_key_exists('governor', $values)
            && (!$status['governor_supported'] || !is_string($values['governor'])
                || !in_array($values['governor'], $status['governors'], true))) {
            throw new \InvalidArgumentException('Governor is not available on every CPU policy.');
        }
        if (array_key_exists('turbo', $values) && (!$status['turbo']['supported'] || !is_bool($values['turbo']))) {
            throw new \InvalidArgumentException('Turbo control is unavailable or its value is invalid.');
        }
        if (array_key_exists('max_freq_khz', $values)) {
            $cap = $values['max_freq_khz'];
            // Some kernels lower cpuinfo_max_freq while turbo is off. Validate
            // the upper bound again after an explicitly requested turbo enable.
            $enablingTurbo = ($values['turbo'] ?? null) === true && $status['turbo']['enabled'] !== true;
            if (!$status['frequency']['supported'] || !is_int($cap)
                || $cap < $status['frequency']['min_khz'] || $cap <= 0 || $cap > 100000000
                || (!$enablingTurbo && $cap > $status['frequency']['max_khz'])) {
                throw new \InvalidArgumentException('Frequency cap must be within the displayed hardware range and above every policy minimum.');
            }
        }
    }

    /** Changed fields only; omitted controls are left alone. */
    public function apply(array $values, bool $persist = true): array
    {
        $handle = @fopen($this->lock, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException('Another power change is running. Try again.');
        }
        $snapshot = [];
        try {
            $before = $this->status();
            $this->validate($values, $before);
            $paths = $this->policyPaths();
            $controls = $this->turboControls($paths);
            // Turbo changes can also modify both policy limits. Capture those side effects.
            foreach ($paths as $path) {
                foreach (['scaling_governor', 'scaling_min_freq', 'scaling_max_freq'] as $file) {
                    if (is_readable($path.'/'.$file)) $snapshot[$path.'/'.$file] = $this->read($path.'/'.$file);
                }
            }
            foreach ($controls as $control) $snapshot[$control['path']] = $this->read($control['path']);
            try {
                if (array_key_exists('turbo', $values)) {
                    foreach ($controls as $control) {
                        $raw = $values['turbo'] !== $control['inverted'] ? '1' : '0';
                        $this->write($control['path'], $raw);
                        if ($this->read($control['path']) !== $raw) throw new \RuntimeException('Kernel did not accept the turbo setting.');
                    }
                    usleep(10000);
                    if ($values['turbo'] === true) $this->validate($values, $this->status());
                }
                if (isset($values['governor'])) {
                    foreach ($paths as $path) {
                        $this->write($path.'/scaling_governor', $values['governor']);
                        if ($this->read($path.'/scaling_governor') !== $values['governor']) {
                            throw new \RuntimeException('Kernel did not accept the governor.');
                        }
                    }
                }
                if (isset($values['max_freq_khz'])) {
                    foreach ($paths as $path) {
                        $this->write($path.'/scaling_max_freq', (string)$values['max_freq_khz']);
                        $expected = min($values['max_freq_khz'], $this->number($path.'/cpuinfo_max_freq'));
                        $accepted = (int)$this->settle($path.'/scaling_max_freq', (string)$expected);
                        if ($accepted < $this->number($path.'/scaling_min_freq') || $accepted > $values['max_freq_khz']) {
                            throw new \RuntimeException('Kernel did not accept the frequency ceiling.');
                        }
                    }
                }
                foreach ($paths as $path) {
                    if (isset($snapshot[$path.'/scaling_min_freq'])
                        && $this->read($path.'/scaling_min_freq') !== $snapshot[$path.'/scaling_min_freq']) {
                        throw new \RuntimeException('Kernel changed a CPU minimum frequency; change rejected to preserve your minimum settings.');
                    }
                }
                $after = $this->status();
                if ($persist) {
                    $this->save(array_replace($before['saved'], $values));
                    $after['saved'] = array_replace($before['saved'], $values);
                }
                return $after;
            } catch (\Throwable $error) {
                $failures = [];
                // Restore turbo first, then original bounds in an order that cannot cross.
                foreach ($controls as $control) {
                    try {
                        $this->write($control['path'], $snapshot[$control['path']]);
                        if ($this->read($control['path']) !== $snapshot[$control['path']]) {
                            throw new \RuntimeException('Turbo rollback readback mismatch.');
                        }
                    }
                    catch (\Throwable $ignored) { $failures[] = 'turbo'; }
                }
                foreach ($paths as $path) {
                    try {
                        if (isset($snapshot[$path.'/scaling_min_freq'], $snapshot[$path.'/scaling_max_freq'])) {
                            $oldMin = $snapshot[$path.'/scaling_min_freq'];
                            $oldMax = $snapshot[$path.'/scaling_max_freq'];
                            if ((int)$oldMax < $this->number($path.'/scaling_min_freq')) {
                                $this->write($path.'/scaling_min_freq', $oldMin);
                                $this->settle($path.'/scaling_min_freq', $oldMin);
                                $this->write($path.'/scaling_max_freq', $oldMax);
                                $this->settle($path.'/scaling_max_freq', $oldMax);
                            } else {
                                $this->write($path.'/scaling_max_freq', $oldMax);
                                $this->settle($path.'/scaling_max_freq', $oldMax);
                                $this->write($path.'/scaling_min_freq', $oldMin);
                                $this->settle($path.'/scaling_min_freq', $oldMin);
                            }
                        }
                        $this->write($path.'/scaling_governor', $snapshot[$path.'/scaling_governor']);
                        foreach (['scaling_governor', 'scaling_min_freq', 'scaling_max_freq'] as $file) {
                            if (isset($snapshot[$path.'/'.$file]) && $this->read($path.'/'.$file) !== $snapshot[$path.'/'.$file]) {
                                throw new \RuntimeException('Rollback readback mismatch.');
                            }
                        }
                    } catch (\Throwable $ignored) { $failures[] = basename($path); }
                }
                throw new \RuntimeException($error->getMessage().($failures
                    ? ' Rollback incomplete; inspect CPU settings: '.implode(', ', array_unique($failures))
                    : ' Previous CPU settings restored.'), 0, $error);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function restore(): array
    {
        $values = $this->load();
        // A new installation must not write CPU controls.
        if (!$values) return $this->status();
        if (($values['turbo'] ?? null) === false && isset($values['max_freq_khz']) && is_int($values['max_freq_khz'])) {
            // Retain the saved requested ceiling, but honor a turbo-disabled
            // kernel's narrower range when reapplying it at boot.
            $state = $this->status();
            if ($state['frequency']['supported']) {
                $values['max_freq_khz'] = min($values['max_freq_khz'], $state['frequency']['max_khz']);
            }
        }
        return $this->apply($values, false);
    }
}
