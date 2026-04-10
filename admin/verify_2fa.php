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
$record = atfLoadRecord($conn, $pending['username']);
if (!is_array($record)) {
    closeDBConnection($conn);
    header('Location: setup_2fa.php');
    exit();
}

$secret = atfDecryptSecret((string) ($record['secret_encrypted'] ?? ''));
$errorMessage = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
    $code = trim((string) ($_POST['verification_code'] ?? ''));
    $throttleAction = scopedRateLimitAction('admin_2fa_verify', $pending['username']);
    $rateLimit = checkRateLimitDB($conn, $throttleAction, 5, 900);

    if (!$rateLimit['allowed']) {
        $errorMessage = $rateLimit['message'];
    } elseif (!validateCSRFToken($csrfToken)) {
        $errorMessage = 'Your session token expired. Please refresh and try again.';
    } else {
        $verification = atfVerifyTotpCode($secret, $code);
        $lastVerifiedSlice = isset($record['last_verified_time_slice']) ? (int) $record['last_verified_time_slice'] : null;

        if (!$verification['valid']) {
            recordAttemptDB($conn, $throttleAction);
            $errorMessage = 'That authenticator code is invalid. Please use the current 6-digit code from your app.';
        } elseif ($lastVerifiedSlice !== null && (int) $verification['time_slice'] === $lastVerifiedSlice) {
            recordAttemptDB($conn, $throttleAction);
            $errorMessage = 'That code was already used. Wait for the next authenticator code, then try again.';
        } else {
            atfRecordSuccessfulVerification($conn, $pending['username'], (int) $verification['time_slice']);
            resetRateLimitDB($conn, $throttleAction);
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
    <title>Admin 2FA Verification</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(91, 168, 84, 0.20), transparent 34%),
                linear-gradient(135deg, #eef5ec 0%, #f8fbf7 55%, #e8f0e5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            color: #143d11;
        }
        .panel {
            width: min(520px, 100%);
            background: rgba(255,255,255,0.96);
            border-radius: 28px;
            border: 1px solid rgba(73, 132, 65, 0.18);
            box-shadow: 0 24px 54px rgba(24, 56, 17, 0.14);
            overflow: hidden;
        }
        .hero {
            padding: 28px 28px 18px;
            background: linear-gradient(135deg, rgba(32,96,24,0.08), rgba(92,168,84,0.16));
            border-bottom: 1px solid rgba(32,96,24,0.08);
            text-align: center;
        }
        .hero img {
            width: 44px;
            height: 44px;
            margin-bottom: 12px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(38, 117, 29, 0.10);
            color: #206018;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 33px;
            color: #103c14;
        }
        .subtitle {
            margin: 0;
            color: #446548;
            font-size: 15px;
            line-height: 1.6;
        }
        .content {
            padding: 28px;
        }
        .info-card {
            background: linear-gradient(180deg, rgba(246,250,244,0.98), rgba(236,244,233,0.95));
            border: 1px solid rgba(73, 132, 65, 0.14);
            border-radius: 22px;
            padding: 20px 20px 18px;
            margin-bottom: 20px;
        }
        .info-card strong {
            color: #17491d;
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
            padding: 18px;
            border-radius: 18px;
            border: 2px solid rgba(33, 95, 26, 0.14);
            background: #fff;
            font-size: 30px;
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
        .helper {
            margin: 12px 0 0;
            color: #5e7662;
            font-size: 13px;
            line-height: 1.6;
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
            min-width: 160px;
            padding: 14px 18px;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
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
    </style>
</head>
<body>
    <div class="panel">
        <div class="hero">
            <img src="../img/cav.png" alt="CvSU Logo">
            <div class="badge">Admin 2FA Verification</div>
            <h1>Verify Admin Sign In</h1>
            <p class="subtitle">
                Enter the current 6-digit authenticator code for
                <strong><?php echo htmlspecialchars($pending['username']); ?></strong> to unlock the admin workspace.
            </p>
        </div>

        <div class="content">
            <div class="info-card">
                <strong>Protected account:</strong> <?php echo htmlspecialchars($pending['full_name']); ?><br>
                <strong>Username:</strong> <?php echo htmlspecialchars($pending['username']); ?>
            </div>

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
                    Codes refresh every 30 seconds. If the timer in your authenticator app rolls over,
                    enter the latest code shown there.
                </p>

                <div class="actions">
                    <button type="submit" class="primary-btn">Verify and Continue</button>
                    <a href="logout.php" class="secondary-btn">Back to Sign In</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
