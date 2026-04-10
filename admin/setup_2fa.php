<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/admin_two_factor_service.php';

if (!empty($_SESSION['admin_id']) || !empty($_SESSION['admin_username'])) {
    header('Location: index.php');
    exit();
}

$pending = atfGetPendingSession();
if ($pending === null) {
    header('Location: ../index.html');
    exit();
}

if (!atfIsEnabled()) {
    atfFinalizeAdminLogin($pending['username'], $pending['full_name']);
    header('Location: index.php');
    exit();
}

$conn = getDBConnection();
atfEnsureTable($conn);
$existingRecord = atfLoadRecord($conn, $pending['username']);
if (is_array($existingRecord)) {
    closeDBConnection($conn);
    header('Location: verify_2fa.php');
    exit();
}

if (empty($_SESSION['admin_2fa_setup_secret'])) {
    $_SESSION['admin_2fa_setup_secret'] = atfGenerateSecret();
}

$secret = (string) $_SESSION['admin_2fa_setup_secret'];
$formattedSecret = atfFormatSecret($secret);
$otpauthUri = atfBuildOtpAuthUri($pending['username'], $secret);
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' . rawurlencode($otpauthUri);
$errorMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
    $code = trim((string) ($_POST['verification_code'] ?? ''));
    $throttleAction = scopedRateLimitAction('admin_2fa_verify', $pending['username'] . '|setup');
    $rateLimit = checkRateLimitDB($conn, $throttleAction, 5, 900);

    if (!$rateLimit['allowed']) {
        $errorMessage = $rateLimit['message'];
    } elseif (!validateCSRFToken($csrfToken)) {
        $errorMessage = 'Your session token expired. Please refresh and try again.';
    } else {
        $verification = atfVerifyTotpCode($secret, $code);
        if (!$verification['valid']) {
            recordAttemptDB($conn, $throttleAction);
            $errorMessage = 'That authenticator code is invalid. Please use the current 6-digit code from your app.';
        } elseif (!atfStoreSecret($conn, $pending['username'], $secret, (int) $verification['time_slice'])) {
            $errorMessage = 'Unable to finish admin 2FA setup right now. Please try again.';
        } else {
            resetRateLimitDB($conn, $throttleAction);
            unset($_SESSION['admin_2fa_setup_secret']);
            atfFinalizeAdminLogin($pending['username'], $pending['full_name']);
            closeDBConnection($conn);
            header('Location: index.php');
            exit();
        }
    }
}

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin 2FA Setup</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(93, 165, 84, 0.22), transparent 36%),
                linear-gradient(135deg, #edf4eb 0%, #f8fbf7 55%, #e7efe4 100%);
            color: #143d11;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
        }
        .panel {
            width: min(760px, 100%);
            background: rgba(255,255,255,0.95);
            border: 1px solid rgba(73, 132, 65, 0.18);
            border-radius: 28px;
            box-shadow: 0 24px 54px rgba(24, 56, 17, 0.14);
            overflow: hidden;
        }
        .hero {
            padding: 28px 34px 18px;
            background: linear-gradient(135deg, rgba(32,96,24,0.08), rgba(92,168,84,0.16));
            border-bottom: 1px solid rgba(32,96,24,0.08);
        }
        .hero-top {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }
        .hero-top img {
            width: 42px;
            height: 42px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(38, 117, 29, 0.10);
            color: #206018;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 4vw, 36px);
            line-height: 1.05;
            color: #0f3d13;
        }
        .subtitle {
            margin: 0;
            color: #446548;
            font-size: 15px;
            line-height: 1.6;
        }
        .content {
            padding: 30px 34px 34px;
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
            gap: 22px;
        }
        .card {
            background: linear-gradient(180deg, rgba(246,250,244,0.98), rgba(236,244,233,0.95));
            border: 1px solid rgba(73, 132, 65, 0.14);
            border-radius: 22px;
            padding: 22px;
        }
        .card h2 {
            margin: 0 0 14px;
            font-size: 18px;
            color: #184b1f;
        }
        .steps {
            margin: 0;
            padding-left: 20px;
            color: #335537;
            line-height: 1.8;
        }
        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }
        .qr-card {
            margin: 18px 0 16px;
            padding: 16px;
            border-radius: 20px;
            background: #fff;
            border: 1px solid rgba(35, 92, 29, 0.12);
            text-align: center;
        }
        .qr-frame {
            width: 236px;
            height: 236px;
            margin: 0 auto 12px;
            padding: 8px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(32,96,24,0.08), rgba(92,168,84,0.18));
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-frame img {
            width: 220px;
            height: 220px;
            display: block;
            border-radius: 14px;
            background: #fff;
        }
        .qr-caption {
            margin: 0;
            color: #456547;
            font-size: 13px;
            line-height: 1.6;
        }
        .meta-pill {
            display: inline-flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 16px;
            background: #f8fbf7;
            border: 1px solid rgba(73, 132, 65, 0.18);
            color: #345639;
            font-size: 13px;
            font-weight: 600;
        }
        .secret-box, .uri-box {
            width: 100%;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid rgba(35, 92, 29, 0.18);
            background: #fff;
            color: #103912;
            font-family: Consolas, 'Courier New', monospace;
        }
        .secret-box {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.22em;
            text-align: center;
            margin: 12px 0 14px;
        }
        .uri-box {
            font-size: 12px;
            line-height: 1.6;
            word-break: break-all;
        }
        .helper {
            margin: 10px 0 0;
            color: #5e7662;
            font-size: 13px;
            line-height: 1.6;
        }
        .form-label {
            display: block;
            margin: 0 0 10px;
            font-size: 13px;
            font-weight: 700;
            color: #1f5623;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .code-input {
            width: 100%;
            padding: 16px 18px;
            border-radius: 18px;
            border: 2px solid rgba(33, 95, 26, 0.14);
            background: #fff;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.36em;
            text-align: center;
            color: #123d15;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .code-input:focus {
            border-color: #2b8a22;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.16);
        }
        .error-message {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(203, 70, 70, 0.10);
            border: 1px solid rgba(203, 70, 70, 0.18);
            color: #9f2d2d;
            font-size: 13px;
            font-weight: 600;
        }
        .actions {
            margin-top: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .primary-btn, .secondary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 170px;
            padding: 14px 18px;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .primary-btn {
            border: none;
            color: #fff;
            background: linear-gradient(135deg, #206018 0%, #3da336 100%);
            box-shadow: 0 16px 30px rgba(37, 105, 31, 0.20);
        }
        .secondary-btn {
            border: 1px solid rgba(33, 95, 26, 0.18);
            color: #1c4f20;
            background: #f7faf6;
        }
        .primary-btn:hover, .secondary-btn:hover {
            transform: translateY(-1px);
        }
        @media (max-width: 720px) {
            .content {
                grid-template-columns: 1fr;
            }
            .panel {
                border-radius: 22px;
            }
            .hero, .content {
                padding-left: 22px;
                padding-right: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="panel">
        <div class="hero">
            <div class="hero-top">
                <img src="../img/cav.png" alt="CvSU Logo">
                <span class="badge">Admin 2FA Setup</span>
            </div>
            <h1>Protect the Admin Panel</h1>
            <p class="subtitle">
                Finish the one-time authenticator setup for <strong><?php echo htmlspecialchars($pending['username']); ?></strong>
                before the admin session is unlocked.
            </p>
        </div>

        <div class="content">
            <section class="card">
                <h2>1. Add this account to your authenticator app</h2>
                <ol class="steps">
                    <li>Open Microsoft Authenticator, Google Authenticator, or another TOTP app.</li>
                    <li>Scan the QR code below. If your app does not support scanning, choose <strong>Enter a setup key</strong>.</li>
                    <li>Use the secret below and keep the type set to <strong>Time based</strong>.</li>
                </ol>

                <div class="meta-row">
                    <span class="meta-pill">Issuer: ASPLAN Admin</span>
                    <span class="meta-pill">Account: <?php echo htmlspecialchars($pending['username']); ?></span>
                </div>

                <div class="qr-card">
                    <div class="qr-frame">
                        <img
                            src="<?php echo htmlspecialchars($qrImageUrl); ?>"
                            alt="Admin 2FA QR code"
                            loading="eager"
                            referrerpolicy="no-referrer"
                        >
                    </div>
                    <p class="qr-caption">
                        Scan this QR code with your authenticator app for the fastest setup.
                        If the image does not load, use the manual setup key below.
                    </p>
                </div>

                <div class="secret-box"><?php echo htmlspecialchars($formattedSecret); ?></div>
                <p class="helper">If your authenticator app supports direct import links, you can also use this URI:</p>
                <div class="uri-box"><?php echo htmlspecialchars($otpauthUri); ?></div>
            </section>

            <section class="card">
                <h2>2. Enter the 6-digit code to finish setup</h2>
                <form method="post" action="">
                    <?php csrfTokenField(); ?>
                    <label class="form-label" for="verification_code">Authenticator Code</label>
                    <input
                        id="verification_code"
                        name="verification_code"
                        class="code-input"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        required
                    >

                    <?php if ($errorMessage !== ''): ?>
                        <div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
                    <?php endif; ?>

                    <p class="helper">
                        This setup expires after 10 minutes for security. If the code changes while you are typing,
                        use the newest 6-digit code shown by your authenticator app.
                    </p>

                    <div class="actions">
                        <button type="submit" class="primary-btn">Finish Admin 2FA Setup</button>
                        <a href="logout.php" class="secondary-btn">Back to Sign In</a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</body>
</html>
