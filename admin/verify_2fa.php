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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at 11% 17%, rgba(78, 161, 69, 0.25), transparent 31%),
                radial-gradient(circle at 86% 7%, rgba(136, 204, 122, 0.26), transparent 40%),
                linear-gradient(132deg, #ecf4ea 0%, #f6faf4 45%, #e4efe1 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 26px;
            color: #143d11;
            position: relative;
            overflow: hidden;
        }

        body::before,
        body::after {
            content: '';
            position: absolute;
            width: 440px;
            height: 440px;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            top: -210px;
            right: -130px;
            background: radial-gradient(circle at center, rgba(67, 151, 58, 0.34), rgba(67, 151, 58, 0));
        }

        body::after {
            bottom: -260px;
            left: -120px;
            background: radial-gradient(circle at center, rgba(112, 189, 102, 0.30), rgba(112, 189, 102, 0));
        }

        .panel {
            width: min(520px, 100%);
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            border: 1px solid rgba(73, 132, 65, 0.19);
            box-shadow:
                0 28px 72px rgba(21, 63, 16, 0.17),
                inset 0 1px 0 rgba(255, 255, 255, 0.75);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        .panel::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: 30px;
            border: 1px solid rgba(255, 255, 255, 0.30);
        }

        .hero {
            padding: 30px 30px 20px;
            background:
                radial-gradient(circle at top center, rgba(95, 177, 86, 0.24), rgba(95, 177, 86, 0) 67%),
                linear-gradient(140deg, rgba(24, 88, 18, 0.10), rgba(127, 200, 116, 0.17));
            border-bottom: 1px solid rgba(32,96,24,0.10);
            text-align: center;
        }

        .hero img {
            width: 48px;
            height: 48px;
            display: block;
            margin-bottom: 13px;
            margin-left: auto;
            margin-right: auto;
            filter: drop-shadow(0 8px 16px rgba(27, 89, 22, 0.20));
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(27, 101, 19, 0.12);
            color: #1b5f16;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .badge::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2f8f27;
            box-shadow: 0 0 0 4px rgba(47, 143, 39, 0.20);
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(32px, 5vw, 44px);
            letter-spacing: -0.03em;
            line-height: 1.08;
            color: #0f3c13;
        }

        .subtitle {
            margin: 0;
            color: #3f5f43;
            font-size: 17px;
            line-height: 1.6;
        }

        .content {
            padding: 30px;
        }

        .info-card {
            background: linear-gradient(180deg, rgba(246,250,244,0.98), rgba(233,243,229,0.95));
            border: 1px solid rgba(73, 132, 65, 0.15);
            border-radius: 20px;
            padding: 18px 18px 16px;
            margin-bottom: 14px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }

        .info-card strong {
            color: #17491d;
        }

        .security-note {
            margin: 0 0 22px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(30, 94, 24, 0.07);
            color: #2e5931;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .form-label {
            display: block;
            margin: 0 0 10px;
            font-size: 14px;
            font-weight: 800;
            color: #1f5623;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .code-input {
            width: 100%;
            padding: 18px 16px;
            border-radius: 18px;
            border: 2px solid rgba(33, 95, 26, 0.14);
            background: linear-gradient(180deg, #ffffff 0%, #f8fcf7 100%);
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
            font-size: 39px;
            font-weight: 700;
            letter-spacing: 0.34em;
            text-align: center;
            color: #123d15;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .code-input:focus {
            border-color: #2b8a22;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.15), 0 12px 28px rgba(32, 96, 24, 0.11);
        }

        .code-input.invalid {
            border-color: rgba(176, 52, 52, 0.45);
            box-shadow: 0 0 0 4px rgba(176, 52, 52, 0.12);
        }

        .helper {
            margin: 12px 0 0;
            color: #5e7662;
            font-size: 14px;
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
            margin-top: 24px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .primary-btn, .secondary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 172px;
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
            background: linear-gradient(135deg, #1d6217 0%, #43ab3a 100%);
            box-shadow: 0 16px 30px rgba(37, 105, 31, 0.20);
        }

        .secondary-btn {
            border: 1px solid rgba(33, 95, 26, 0.18);
            color: #1a4b1e;
            background: #f7faf6;
        }

        .primary-btn:hover, .secondary-btn:hover {
            transform: translateY(-1px);
        }

        .primary-btn:hover {
            box-shadow: 0 18px 32px rgba(37, 105, 31, 0.25);
        }

        @media (max-width: 640px) {
            body {
                padding: 16px;
            }

            .hero,
            .content {
                padding: 22px;
            }

            .subtitle {
                font-size: 15px;
            }

            .code-input {
                font-size: 34px;
                letter-spacing: 0.28em;
            }

            .primary-btn,
            .secondary-btn {
                flex: 1 1 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
            }
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

            <p class="security-note">This challenge protects administrator access. Use the latest one-time code from your authenticator app.</p>

            <form method="post" action="">
                <?php csrfTokenField(); ?>
                <label class="form-label" for="verification_code">Authenticator Code</label>
                <input
                    id="verification_code"
                    name="verification_code"
                    class="code-input<?php echo $errorMessage !== '' ? ' invalid' : ''; ?>"
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

    <script>
        (function () {
            const codeInput = document.getElementById('verification_code');
            if (!codeInput) {
                return;
            }

            const sanitize = (value) => String(value || '').replace(/\D+/g, '').slice(0, 6);

            const applySanitizedValue = () => {
                codeInput.value = sanitize(codeInput.value);
            };

            codeInput.addEventListener('input', applySanitizedValue);
            codeInput.addEventListener('paste', function () {
                window.requestAnimationFrame(applySanitizedValue);
            });
            codeInput.focus();
        })();
    </script>
</body>
</html>
