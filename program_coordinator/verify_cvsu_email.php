<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ' . buildAppRelativeUrl('/index.html'));
    exit();
}

$conn = getDBConnection();
$username = (string) ($_SESSION['username'] ?? '');
$sessionEmail = (string) ($_SESSION['program_coordinator_email_verification_email'] ?? $_SESSION['program_coordinator_email'] ?? '');
$requiresVerification = false;

if ($username !== '' && $sessionEmail !== '' && function_exists('cevApplySessionRequirement')) {
    $requiresVerification = cevApplySessionRequirement($conn, $username, $sessionEmail);
}

closeDBConnection($conn);

if (!$requiresVerification && empty($_SESSION['program_coordinator_email_verification_required'])) {
    header('Location: index.php');
    exit();
}

$coordinatorEmail = htmlspecialchars((string) ($_SESSION['program_coordinator_email_verification_email'] ?? $_SESSION['program_coordinator_email'] ?? ''), ENT_QUOTES, 'UTF-8');
$coordinatorName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Program Coordinator'));
$coordinatorName = htmlspecialchars($coordinatorName, ENT_QUOTES, 'UTF-8');
$verificationNotice = trim((string) ($_SESSION['program_coordinator_email_verification_notice'] ?? ''));
$autoSend = !empty($_SESSION['program_coordinator_email_verification_autosend']);
unset($_SESSION['program_coordinator_email_verification_autosend'], $_SESSION['program_coordinator_email_verification_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify CvSU Email | ASPLAN</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
            background:
                linear-gradient(135deg, rgba(13,57,16,.92), rgba(32,96,24,.86)),
                url('../pix/drone111.jpg') center center / cover no-repeat fixed;
            color: #173118;
        }
        .verify-card {
            width: min(100%, 520px);
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(255,255,255,.65);
            border-radius: 22px;
            box-shadow: 0 28px 60px rgba(8, 28, 10, 0.34);
            padding: 32px 28px 24px;
            text-align: center;
        }
        .verify-badge {
            width: 74px;
            height: 74px;
            margin: 0 auto 16px;
            border-radius: 22px;
            background: linear-gradient(135deg, #206018, #3d9a34);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 32px;
            font-weight: 800;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 32px;
            line-height: 1.08;
            color: #165919;
        }
        .subtext {
            margin: 0 auto 18px;
            max-width: 420px;
            color: #4d6551;
            line-height: 1.55;
            font-size: 15px;
        }
        .email-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 22px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #eef6ee;
            color: #1c5b20;
            font-weight: 600;
            border: 1px solid #d0e3d0;
            word-break: break-all;
        }
        .status-box {
            display: none;
            margin-bottom: 16px;
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
            line-height: 1.45;
            text-align: left;
        }
        .status-box.show { display: block; }
        .status-box.info { background: #edf6ed; color: #1d5e21; border: 1px solid #cfe2cf; }
        .status-box.error { background: #fff2f0; color: #b3261e; border: 1px solid #f3c5bf; }
        .status-box.success { background: #eef9ef; color: #1d6a28; border: 1px solid #c9e9cf; }
        .otp-label {
            display: block;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #2b472c;
            margin-bottom: 8px;
        }
        .otp-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #c8d8c8;
            border-radius: 14px;
            padding: 15px 16px;
            font-size: 24px;
            letter-spacing: 10px;
            text-align: center;
            font-weight: 700;
            color: #1a3a1b;
            background: #fbfefb;
            outline: none;
        }
        .otp-input:focus {
            border-color: #2c7a2a;
            box-shadow: 0 0 0 4px rgba(44, 122, 42, 0.14);
        }
        .action-row {
            display: flex;
            gap: 12px;
            margin-top: 18px;
            flex-wrap: wrap;
        }
        .action-row button,
        .secondary-link {
            flex: 1 1 160px;
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid transparent;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease;
        }
        .primary-btn {
            background: linear-gradient(135deg, #206018 0%, #33962d 100%);
            color: #fff;
            box-shadow: 0 14px 24px rgba(32, 96, 24, 0.22);
        }
        .secondary-btn,
        .secondary-link {
            background: #f2f5f2;
            color: #234125;
            border-color: #d3ddd3;
        }
        .secondary-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        .helper-note {
            margin-top: 16px;
            color: #5f7461;
            font-size: 13px;
            line-height: 1.5;
        }
        @media (max-width: 560px) {
            .verify-card { padding: 26px 18px 20px; }
            h1 { font-size: 28px; }
            .otp-input { letter-spacing: 7px; font-size: 22px; }
            .action-row { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <div class="verify-badge">OTP</div>
        <h1>Verify Your CvSU Email</h1>
        <p class="subtext">
            Hi <?php echo $coordinatorName; ?>. Before you continue in the coordinator workspace, please confirm that your CvSU email address is real and reachable.
        </p>
        <div class="email-chip"><?php echo $coordinatorEmail; ?></div>

        <div id="verifyStatus" class="status-box<?php echo $verificationNotice !== '' ? ' show info' : ''; ?>">
            <?php echo htmlspecialchars($verificationNotice, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <label for="otpCode" class="otp-label">Enter the 6-digit verification code</label>
        <input id="otpCode" class="otp-input" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000">

        <div class="action-row">
            <button type="button" class="primary-btn" id="verifyOtpBtn">Verify Email</button>
            <button type="button" class="secondary-btn" id="resendOtpBtn">Resend OTP</button>
        </div>
        <div class="action-row">
            <a class="secondary-link" href="../auth/signout.php">Sign Out</a>
        </div>

        <p class="helper-note">
            We only require this extra step for program coordinator accounts using a <strong>@cvsu.edu.ph</strong> email address.
        </p>
    </div>

    <script>
        const statusBox = document.getElementById('verifyStatus');
        const otpInput = document.getElementById('otpCode');
        const verifyBtn = document.getElementById('verifyOtpBtn');
        const resendBtn = document.getElementById('resendOtpBtn');
        const autoSend = <?php echo $autoSend ? 'true' : 'false'; ?>;

        function setStatus(type, message) {
            statusBox.className = 'status-box show ' + type;
            statusBox.textContent = message;
        }

        function setBusy(button, busyText, originalText, busy) {
            button.disabled = busy;
            button.textContent = busy ? busyText : originalText;
        }

        function postForm(url, payload) {
            const body = new URLSearchParams(payload);
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body
            }).then(r => r.json());
        }

        function sendOtp(forceResend = false) {
            setBusy(resendBtn, 'Sending...', 'Resend OTP', true);
            setStatus('info', forceResend ? 'Sending a new verification code...' : 'Sending your verification code...');

            postForm('send_cvsu_email_verification.php', { resend: forceResend ? '1' : '0' })
                .then(data => {
                    if (data.success) {
                        setStatus('success', data.message || 'A verification code has been sent to your CvSU email.');
                    } else {
                        setStatus('error', data.message || 'Unable to send the verification code right now.');
                    }
                })
                .catch(() => {
                    setStatus('error', 'Unable to send the verification code right now.');
                })
                .finally(() => setBusy(resendBtn, 'Sending...', 'Resend OTP', false));
        }

        function verifyOtp() {
            const code = otpInput.value.trim();
            if (!/^\d{6}$/.test(code)) {
                setStatus('error', 'Please enter the 6-digit verification code.');
                return;
            }

            setBusy(verifyBtn, 'Verifying...', 'Verify Email', true);
            setStatus('info', 'Verifying your CvSU email...');

            postForm('verify_cvsu_email_otp.php', { code })
                .then(data => {
                    if (data.success) {
                        setStatus('success', data.message || 'Your CvSU email has been verified.');
                        setTimeout(() => {
                            window.location.href = data.redirect || 'index.php';
                        }, 900);
                    } else {
                        setStatus('error', data.message || 'Verification failed. Please try again.');
                    }
                })
                .catch(() => {
                    setStatus('error', 'Verification failed. Please try again.');
                })
                .finally(() => setBusy(verifyBtn, 'Verifying...', 'Verify Email', false));
        }

        verifyBtn.addEventListener('click', verifyOtp);
        resendBtn.addEventListener('click', () => sendOtp(true));
        otpInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                verifyOtp();
            }
        });

        if (autoSend) {
            sendOtp(false);
        }
    </script>
</body>
</html>
