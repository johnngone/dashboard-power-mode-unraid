<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/include/PowerControl.php';
use DashboardPowerMode\PowerControl;

$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    $count++;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
}
function fails(callable $call, string $contains): void {
    try { $call(); } catch (Throwable $error) {
        check(strpos($error->getMessage(), $contains) !== false, 'expected error '.$contains.'; got '.$error->getMessage());
        return;
    }
    throw new RuntimeException('FAIL: expected exception '.$contains);
}
function put(string $path, string $value): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $value."\n");
}
function fixture(string $base, string $name, string $driver = 'intel_cpufreq', int $count = 2): string {
    $root = $base.'/'.$name;
    mkdir($root, 0700, true);
    for ($i = 0; $i < $count; $i++) {
        foreach ([
            'scaling_driver' => $driver,
            'scaling_available_governors' => 'conservative ondemand userspace powersave performance schedutil',
            'scaling_governor' => 'powersave', 'scaling_min_freq' => '800000', 'scaling_max_freq' => '4000000',
            'cpuinfo_min_freq' => '800000', 'cpuinfo_max_freq' => '4000000',
        ] as $file => $value) put($root.'/cpufreq/policy'.$i.'/'.$file, $value);
    }
    return $root;
}
function control(string $root, ?callable $writer = null): PowerControl {
    return new PowerControl($root, $root.'/config/settings.json', $root.'/power.lock', $root.'/tips.cfg', $writer);
}
function removeTree(string $root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($root);
}

$base = sys_get_temp_dir().'/dpm-tests-'.bin2hex(random_bytes(6));
mkdir($base, 0700);
try {
    $root = fixture($base, 'intel');
    put($root.'/intel_pstate/no_turbo', '0');
    $power = control($root);
    $initial = $power->status();
    check(count($initial['policies']) === 2 && $initial['governor'] === 'powersave', 'discover every policy');
    check($initial['turbo']['supported'] && $initial['turbo']['enabled'], 'inverted Intel turbo interface');
    check($initial['frequency']['min_khz'] === 800000 && $initial['frequency']['max_khz'] === 4000000, 'hardware range');
    $power->restore();
    check(!file_exists($root.'/config/settings.json'), 'first install is read-only');
    $state = $power->apply(['governor' => 'schedutil', 'turbo' => false, 'max_freq_khz' => 2000000]);
    check($state['governor'] === 'schedutil' && !$state['turbo']['enabled'] && $state['frequency']['cap_khz'] === 2000000, 'apply controls');
    check(trim(file_get_contents($root.'/intel_pstate/no_turbo')) === '1', 'turbo inversion write');
    check(trim(file_get_contents($root.'/cpufreq/policy1/scaling_governor')) === 'schedutil', 'all policies updated');
    check(trim(file_get_contents($root.'/cpufreq/policy0/scaling_min_freq')) === '800000', 'minimum unchanged');
    $power->apply(['governor' => 'performance']);
    check($power->status()['frequency']['cap_khz'] === 2000000 && !$power->status()['turbo']['enabled'], 'presets leave other controls unchanged');
    check($power->status()['saved'] === ['governor' => 'performance', 'turbo' => false, 'max_freq_khz' => 2000000], 'merge saved changed fields');
    put($root.'/cpufreq/policy0/scaling_governor', 'powersave');
    put($root.'/cpufreq/policy1/scaling_governor', 'powersave');
    check($power->restore()['governor'] === 'performance', 'saved settings restore');
    $saved = file_get_contents($root.'/config/settings.json');
    foreach ([['governor' => 'not-a-governor'], ['governor' => 'performance; reboot'], ['turbo' => 'yes'],
        ['max_freq_khz' => 700000], ['max_freq_khz' => 4100000], ['max_freq_khz' => 2000.5],
        ['max_freq_khz' => '2000000'], ['path' => '/etc/passwd'], []] as $bad) {
        fails(static function () use ($power, $bad) { $power->apply($bad); }, $bad && isset($bad['governor']) ? 'Governor' : ($bad && isset($bad['turbo']) ? 'Turbo' : ($bad && isset($bad['max_freq_khz']) ? 'Frequency' : 'Supply only')));
    }
    check(file_get_contents($root.'/config/settings.json') === $saved, 'invalid requests do not persist');
    put($root.'/tips.cfg', "CPU_GOVERNOR=\"powersave\"\nTURBO_SCHEDULE=\"no\"");
    $tipsBefore = file_get_contents($root.'/tips.cfg');
    check($power->status()['tips_conflict'], 'Tips and Tweaks conflict detected');
    $power->apply(['turbo' => true]);
    check(file_get_contents($root.'/tips.cfg') === $tipsBefore, 'Tips config untouched');
    $held = fopen($root.'/power.lock', 'c'); flock($held, LOCK_EX);
    fails(static function () use ($power) { $power->apply(['turbo' => false]); }, 'Another power change');
    flock($held, LOCK_UN); fclose($held);

    $amd = fixture($base, 'amd', 'amd-pstate');
    put($amd.'/cpufreq/boost', '1');
    check(!control($amd)->apply(['turbo' => false])['turbo']['enabled'], 'AMD global boost');
    check(trim(file_get_contents($amd.'/cpufreq/boost')) === '0', 'AMD boost is not inverted');
    $per = fixture($base, 'per-policy', 'amd-pstate-epp');
    put($per.'/cpufreq/policy0/boost', '1'); put($per.'/cpufreq/policy1/boost', '0');
    check(control($per)->status()['turbo']['enabled'] === null, 'mixed per-policy boost');
    check(control($per)->apply(['turbo' => true])['turbo']['enabled'] === true, 'per-policy boost applied');
    unlink($per.'/cpufreq/policy1/boost');
    check(!control($per)->status()['turbo']['supported'], 'partial boost coverage unsupported');

    $empty = fixture($base, 'empty', 'none', 0);
    check(!control($empty)->status()['governor_supported'] && !control($empty)->status()['frequency']['supported'], 'no CPUFreq is graceful');
    $unsupported = fixture($base, 'unsupported');
    check(!control($unsupported)->status()['turbo']['supported'], 'missing turbo interface');
    fails(static function () use ($unsupported) { control($unsupported)->apply(['turbo' => true]); }, 'Turbo');
    unlink($unsupported.'/cpufreq/policy0/scaling_max_freq');
    check(!control($unsupported)->status()['frequency']['supported'], 'missing cap interface');
    control($unsupported)->apply(['governor' => 'ondemand']);
    check(control($unsupported)->status()['governor'] === 'ondemand', 'governor works without frequency interface');

    $mixed = fixture($base, 'mixed');
    put($mixed.'/cpufreq/policy1/scaling_governor', 'performance');
    put($mixed.'/cpufreq/policy1/scaling_available_governors', 'powersave performance');
    put($mixed.'/cpufreq/policy1/scaling_min_freq', '1800000');
    put($mixed.'/cpufreq/policy1/cpuinfo_max_freq', '2800000');
    put($mixed.'/cpufreq/policy1/scaling_max_freq', '2800000');
    $state = control($mixed)->status();
    check($state['governor'] === null && $state['frequency']['cap_khz'] === null, 'mixed state reported');
    check($state['governors'] === ['performance', 'powersave'] && $state['presets']['balanced'] === null, 'common governors and unavailable balanced');
    check($state['frequency']['min_khz'] === 1800000 && $state['frequency']['max_khz'] === 2800000, 'heterogeneous common range');
    fails(static function () use ($mixed) { control($mixed)->apply(['governor' => 'ondemand']); }, 'Governor');
    put($mixed.'/cpufreq/policy1/scaling_min_freq', '4100000');
    check(!control($mixed)->status()['frequency']['supported'], 'empty intersection disables cap');

    $failure = fixture($base, 'failure');
    put($failure.'/intel_pstate/no_turbo', '0');
    $didFail = false;
    $writer = static function ($path, $value) use (&$didFail) {
        if (!$didFail && strpos($path, 'policy1/scaling_governor') !== false && $value === 'performance') {
            $didFail = true; throw new RuntimeException('Injected write failure');
        }
        put($path, $value);
    };
    fails(static function () use ($failure, $writer) { control($failure, $writer)->apply(['governor' => 'performance', 'turbo' => false]); }, 'Previous CPU settings restored');
    check(control($failure)->status()['governor'] === 'powersave' && control($failure)->status()['turbo']['enabled'], 'rollback across policies and turbo');
    check(!file_exists($failure.'/config/settings.json'), 'failed transaction not saved');
    $broken = static function ($path, $value) {
        if (strpos($path, 'scaling_governor') !== false) throw new RuntimeException('Persistent write failure');
        put($path, $value);
    };
    fails(static function () use ($failure, $broken) { control($failure, $broken)->apply(['governor' => 'performance']); }, 'Rollback incomplete');
    $ignore = static function ($path, $value) { /* simulate a driver rejecting governor without an I/O error */ };
    fails(static function () use ($failure, $ignore) { control($failure, $ignore)->apply(['governor' => 'performance']); }, 'Kernel did not accept');

    $clamp = fixture($base, 'clamp'); put($clamp.'/intel_pstate/no_turbo', '0');
    $clampingWriter = static function ($path, $value) use ($clamp) {
        put($path, $value);
        if ($path === $clamp.'/intel_pstate/no_turbo' && $value === '1') {
            for ($i = 0; $i < 2; $i++) put($clamp.'/cpufreq/policy'.$i.'/scaling_max_freq', '2500000');
        }
        if (basename($path) === 'scaling_max_freq' && trim(file_get_contents($clamp.'/intel_pstate/no_turbo')) === '1') put($path, (string)min((int)$value, 2500000));
    };
    $state = control($clamp, $clampingWriter)->apply(['turbo' => false, 'max_freq_khz' => 4000000]);
    check($state['frequency']['cap_khz'] === 2500000 && $state['saved']['max_freq_khz'] === 4000000, 'kernel clamp readback and requested saved ceiling');
    $minChange = fixture($base, 'minimum-side-effect'); put($minChange.'/intel_pstate/no_turbo', '0');
    $minWriter = static function ($path, $value) use ($minChange) {
        put($path, $value);
        if ($path === $minChange.'/intel_pstate/no_turbo' && $value === '1') {
            put($minChange.'/cpufreq/policy0/scaling_min_freq', '1000000');
        }
    };
    fails(static function () use ($minChange, $minWriter) { control($minChange, $minWriter)->apply(['turbo' => false]); }, 'minimum frequency');
    check(control($minChange)->status()['policies'][0]['min_khz'] === 800000, 'minimum side effect rolled back');
    $saveFailure = fixture($base, 'save-failure');
    $saveWriter = static function ($path, $value) use ($saveFailure) {
        put($path, $value);
        if (!file_exists($saveFailure.'/config')) put($saveFailure.'/config', 'blocks configuration directory');
    };
    fails(static function () use ($saveFailure, $saveWriter) { control($saveFailure, $saveWriter)->apply(['governor' => 'performance']); }, 'Cannot create');
    check(control($saveFailure)->status()['governor'] === 'powersave', 'configuration save failure rolls hardware back');
    $dynamic = fixture($base, 'dynamic-turbo'); put($dynamic.'/intel_pstate/no_turbo', '0');
    $dynamicWriter = static function ($path, $value) use ($dynamic) {
        put($path, $value);
        if ($path === $dynamic.'/intel_pstate/no_turbo') {
            for ($i = 0; $i < 2; $i++) {
                $policy = $dynamic.'/cpufreq/policy'.$i;
                put($policy.'/cpuinfo_max_freq', $value === '1' ? '2500000' : '4000000');
                if ($value === '1') put($policy.'/scaling_max_freq', (string)min(2500000, (int)trim(file_get_contents($policy.'/scaling_max_freq'))));
            }
        }
    };
    $dynamicPower = control($dynamic, $dynamicWriter);
    $dynamicPower->apply(['max_freq_khz' => 4000000]);
    $dynamicPower->apply(['turbo' => false]);
    check($dynamicPower->status()['frequency']['max_khz'] === 2500000, 'turbo changes reported cpuinfo range');
    check($dynamicPower->restore()['frequency']['cap_khz'] === 2500000, 'restore honors turbo-disabled range');
    check($dynamicPower->status()['saved']['max_freq_khz'] === 4000000, 'restore retains requested saved cap');
    check($dynamicPower->apply(['turbo' => true, 'max_freq_khz' => 4000000])['frequency']['cap_khz'] === 4000000, 'enable turbo and raise cap in one transaction');
    $dynamicPower->apply(['turbo' => false]);
    fails(static function () use ($dynamicPower) { $dynamicPower->apply(['turbo' => true, 'max_freq_khz' => 4100000]); }, 'Frequency cap');
    check(!$dynamicPower->status()['turbo']['enabled'], 'invalid post-turbo cap rolls back turbo');

    $delayed = fixture($base, 'delayed-qos');
    $pending = [];
    $delayedWriter = static function ($path, $value) use (&$pending) {
        if (basename($path) === 'scaling_max_freq') $pending[$path] = [$value, 2];
        else put($path, $value);
    };
    $delayedReader = static function ($path) use (&$pending) {
        if (isset($pending[$path]) && --$pending[$path][1] <= 0) {
            put($path, $pending[$path][0]); unset($pending[$path]);
        }
        return file_get_contents($path);
    };
    $delayedPower = new PowerControl($delayed, $delayed.'/config/settings.json', $delayed.'/power.lock', $delayed.'/tips.cfg', $delayedWriter, $delayedReader);
    check($delayedPower->apply(['max_freq_khz' => 2000000])['frequency']['cap_khz'] === 2000000, 'wait for delayed lower QoS limit');
    check($delayedPower->apply(['max_freq_khz' => 4000000])['frequency']['cap_khz'] === 4000000, 'wait for delayed higher QoS limit');
    put($clamp.'/config/settings.json', '{invalid');
    fails(static function () use ($clamp) { control($clamp)->restore(); }, 'Saved power settings are invalid');
    echo "PASS: $count power-control checks\n";
} finally {
    removeTree($base);
}
