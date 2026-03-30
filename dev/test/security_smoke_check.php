<?php
/**
 * Security smoke checks for recent hardening updates.
 * Usage: php dev/test/security_smoke_check.php
 */

$root = dirname(__DIR__, 2);

$checks = [
    [
        'name' => 'No session password storage',
        'expect' => 0,
        'files' => [
            'auth/unified_login_process.php',
            'auth/check_auto_login.php',
            'auth/check_remember_me.php',
            'auth/login_process.php',
            'student/acc_mng.php',
            'student/checklist_stud.php',
        ],
        'pattern' => "/\\\$_SESSION\\['password'\\]/",
    ],
    [
        'name' => 'Main checklist save validates CSRF',
        'expectMin' => 1,
        'files' => ['save_checklist.php'],
        'pattern' => '/validateCSRFToken\\(/',
    ],
    [
        'name' => 'API checklist save validates CSRF',
        'expectMin' => 1,
        'files' => ['api/save_checklist.php'],
        'pattern' => '/validateCSRFToken\\(/',
    ],
    [
        'name' => 'Checklist pages submit csrf_token',
        'expectMin' => 2,
        'files' => [
            'adviser/checklist.php',
            'program_coordinator/checklist.php',
        ],
        'pattern' => "/formData\\.append\\('csrf_token'/",
    ],
    [
        'name' => 'Config provides secure cookie helpers',
        'expectMin' => 1,
        'files' => ['config/config.php'],
        'pattern' => '/function setAppCookie\\(/',
    ],
    [
        'name' => 'Config enforces secure session cookie params',
        'expectMin' => 1,
        'files' => ['config/config.php'],
        'pattern' => '/session_set_cookie_params\\(/',
    ],
];

$failed = 0;

foreach ($checks as $check) {
    $count = 0;
    foreach ($check['files'] as $relativePath) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            continue;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        if (preg_match_all($check['pattern'], $content, $matches)) {
            $count += count($matches[0]);
        }
    }

    $ok = true;
    if (isset($check['expect'])) {
        $ok = ($count === (int)$check['expect']);
    }
    if (isset($check['expectMin'])) {
        $ok = ($count >= (int)$check['expectMin']);
    }

    echo ($ok ? '[PASS] ' : '[FAIL] ') . $check['name'] . ' (matches=' . $count . ')' . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

if ($failed > 0) {
    echo PHP_EOL . 'Security smoke check failed: ' . $failed . ' check(s).' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Security smoke check passed.' . PHP_EOL;
exit(0);
