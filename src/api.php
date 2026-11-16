<?php
/* SPDX-License-Identifier: GPL-2.0-only */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function dpm_error(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Unraid's global prepend checks CSRF on every POST before this file runs.
// Refuse service under a PHP configuration which would bypass that protection.
$prepend = realpath((string)ini_get('auto_prepend_file'));
if ($prepend !== realpath('/usr/local/emhttp/webGui/include/local_prepend.php') || $prepend === false) {
    dpm_error(503, 'Unraid web authentication and CSRF setup is required.');
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['unraid_login'])) dpm_error(401, 'Sign in to Unraid first.');
session_write_close();

require_once __DIR__.'/include/PowerControl.php';
$power = new \DashboardPowerMode\PowerControl();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode($power->status(), JSON_THROW_ON_ERROR);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $values = $_POST;
        if (array_key_exists('turbo', $values)) {
            if (!in_array($values['turbo'], ['0', '1'], true)) throw new InvalidArgumentException('Invalid turbo value.');
            $values['turbo'] = $values['turbo'] === '1';
        }
        if (array_key_exists('max_freq_khz', $values)) {
            $cap = $values['max_freq_khz'];
            if (!is_string($cap) || !ctype_digit($cap) || strlen($cap) > 10) throw new InvalidArgumentException('Invalid frequency cap.');
            $values['max_freq_khz'] = (int)$cap;
        }
        echo json_encode($power->apply($values), JSON_THROW_ON_ERROR);
    } else {
        header('Allow: GET, POST');
        dpm_error(405, 'Use GET or POST.');
    }
} catch (InvalidArgumentException $error) {
    dpm_error(422, $error->getMessage());
} catch (Throwable $error) {
    error_log('dashboard-power-mode: '.$error->getMessage());
    dpm_error(409, $error->getMessage());
}
