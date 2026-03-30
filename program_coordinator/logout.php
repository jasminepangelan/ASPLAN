<?php
require_once __DIR__ . '/../config/config.php';

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

if ($useLaravelAuthBridge) {
    $bridgeUrl = laravelBridgeUrl('/api/signout');
	$payloadJson = json_encode([
		'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
		'user_type' => $_SESSION['user_type'] ?? 'program_coordinator',
	]);

	if (function_exists('curl_init')) {
		$ch = curl_init($bridgeUrl);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payloadJson,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		]);
		curl_exec($ch);
		curl_close($ch);
	} else {
		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\n",
				'content' => $payloadJson,
				'timeout' => 5,
			],
		]);
		@file_get_contents($bridgeUrl, false, $context);
	}
}

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

if (isset($_COOKIE['remember_me'])) {
	clearAppCookie('remember_me', '/');
	clearAppCookie('remember_me', '/PEAS/');
	unset($_COOKIE['remember_me']);
}

session_unset();
session_destroy();

header('Location: ../index.html');
exit();
?>
