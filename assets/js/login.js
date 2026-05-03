/**
 * PEAS - Pre-Enrollment Assessment System
 * Login Page JavaScript (Unified Login)
 */

// Resolve endpoint URLs relative to the current page directory.
function resolveAppUrl(relativePath) {
    return new URL(relativePath, window.location.href).href;
}

function setErrorModalTitle(title) {
    const titleEl = document.getElementById('errorModalTitle');
    if (titleEl) {
        titleEl.textContent = String(title || 'Login Error');
    }
}

let loginPageInitialized = false;

function initializeLoginPage() {
    if (loginPageInitialized) {
        return;
    }

    loginPageInitialized = true;
    initializeEventListeners();
    initializePasswordStrength(); // Initialize password strength indicator
    fetchCSRFToken(); // Fetch CSRF token as early as possible
    showSessionLimitNotificationFromUrl();
    showAdminSessionReplacementNoticeFromUrl();
}

// Initialize as soon as DOM is ready so form handlers are attached
// before users can submit in slower/incognito page loads.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLoginPage);
} else {
    initializeLoginPage();
}

/**
 * Show a timeout notice when redirected after reaching server session limit.
 */
function showSessionLimitNotificationFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const expiredFromUrl = params.get('session_expired') === '1';
    const expiredFromStorage = sessionStorage.getItem('session_expired_notice') === '1';
    const noticeAlreadyShown = sessionStorage.getItem('session_timeout_notice_shown') === '1';

    if (noticeAlreadyShown) {
        sessionStorage.removeItem('session_timeout_notice_shown');
        sessionStorage.removeItem('session_expired_notice');
        sessionStorage.removeItem('session_expired_limit');

        params.delete('session_expired');
        params.delete('limit');
        const cleanedNoticeQuery = params.toString();
        const cleanNoticeUrl = window.location.pathname + (cleanedNoticeQuery ? `?${cleanedNoticeQuery}` : '') + window.location.hash;
        window.history.replaceState({}, document.title, cleanNoticeUrl);
        return;
    }

    if (!expiredFromUrl && !expiredFromStorage) {
        return;
    }

    let rawLimit = parseInt(params.get('limit') || '0', 10);
    if (!(Number.isFinite(rawLimit) && rawLimit > 0)) {
        rawLimit = parseInt(sessionStorage.getItem('session_expired_limit') || '0', 10);
    }

    const limitSeconds = Number.isFinite(rawLimit) && rawLimit > 0 ? rawLimit : 0;

    const errorMessageEl = document.getElementById('errorMessage');
    if (!errorMessageEl) {
        return;
    }

    setErrorModalTitle('Session Expired');

    errorMessageEl.textContent = limitSeconds > 0
        ? `Session limit reached. You were logged out after ${limitSeconds} seconds of inactivity. Please log in again.`
        : 'Session limit reached. You were logged out due to inactivity. Please log in again.';

    showModal('errorModal');

    sessionStorage.removeItem('session_expired_notice');
    sessionStorage.removeItem('session_expired_limit');

    // Keep the URL clean so the notice appears only once on refresh/navigation.
    params.delete('session_expired');
    params.delete('limit');
    const cleanedQuery = params.toString();
    const cleanUrl = window.location.pathname + (cleanedQuery ? `?${cleanedQuery}` : '') + window.location.hash;
    window.history.replaceState({}, document.title, cleanUrl);
}

function showAdminSessionReplacementNoticeFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const replacedFromUrl = params.get('admin_session_replaced') === '1';
    const replacedFromStorage = sessionStorage.getItem('admin_session_replaced_notice') === '1';
    const noticeAlreadyShown = sessionStorage.getItem('admin_session_replaced_notice_shown') === '1';

    if (noticeAlreadyShown) {
        sessionStorage.removeItem('admin_session_replaced_notice_shown');
        sessionStorage.removeItem('admin_session_replaced_notice');

        params.delete('admin_session_replaced');
        const cleanedNoticeQuery = params.toString();
        const cleanNoticeUrl = window.location.pathname + (cleanedNoticeQuery ? `?${cleanedNoticeQuery}` : '') + window.location.hash;
        window.history.replaceState({}, document.title, cleanNoticeUrl);
        return;
    }

    if (!replacedFromUrl && !replacedFromStorage) {
        return;
    }

    const errorMessageEl = document.getElementById('errorMessage');
    if (!errorMessageEl) {
        return;
    }

    setErrorModalTitle('Session Replaced');

    errorMessageEl.textContent = 'This admin account was signed in on another device. Please log in again to continue.';
    showModal('errorModal');

    sessionStorage.removeItem('admin_session_replaced_notice');

    params.delete('admin_session_replaced');
    const cleanedQuery = params.toString();
    const cleanUrl = window.location.pathname + (cleanedQuery ? `?${cleanedQuery}` : '') + window.location.hash;
    window.history.replaceState({}, document.title, cleanUrl);
}

/**
 * Fetch CSRF token from server and inject into form
 */
function fetchCSRFToken() {
    return fetch(resolveAppUrl('auth/get_csrf_token.php'), {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Failed to fetch CSRF token');
        }
        return response.json();
    })
    .then(data => {
        if (data.success && data.token) {
            const csrfInput = document.getElementById('csrf_token');
            if (csrfInput) {
                csrfInput.value = data.token;
                return true;
            }
        } else {
            console.error('Invalid CSRF token response:', data);
        }
        return false;
    })
    .catch(error => {
        // Retry once in case of transient network/session lock issues.
        return fetch(resolveAppUrl('auth/get_csrf_token.php'), {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to fetch CSRF token');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.token) {
                const csrfInput = document.getElementById('csrf_token');
                if (csrfInput) {
                    csrfInput.value = data.token;
                    return true;
                }
            }
            throw error;
        })
        .catch(() => {
        console.error('Error fetching CSRF token:', error);
        // Show user-friendly error
        const errorMsg = document.createElement('div');
        errorMsg.style.cssText = 'background: #dc3545; color: white; padding: 10px; text-align: center; position: fixed; top: 0; left: 0; right: 0; z-index: 10001;';
        errorMsg.textContent = 'Security token could not be loaded. Please refresh the page.';
        document.body.insertBefore(errorMsg, document.body.firstChild);
        return false;
        });
    });
}

/**
 * Initialize all event listeners
 */
function initializeEventListeners() {
    // Login form submission
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', handleLoginSubmit);
    }

    // Forgot password form
    const forgotEmailForm = document.getElementById('forgotEmailForm');
    if (forgotEmailForm) {
        forgotEmailForm.addEventListener('submit', handleForgotPasswordSubmit);
    }

    // Verify code button
    const verifyCodeBtn = document.getElementById('verifyCodeBtn');
    if (verifyCodeBtn) {
        verifyCodeBtn.addEventListener('click', verifyForgotCode);
    }

    // Confirm password button
    const confirmPasswordBtn = document.getElementById('confirmPasswordBtn');
    if (confirmPasswordBtn) {
        confirmPasswordBtn.addEventListener('click', submitForgotPassword);
    }

    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.setProperty('display', 'none', 'important');
                document.body.classList.remove('modal-open');
            }
        });
    });

    // Keyboard accessibility for modals
    document.addEventListener('keydown', handleModalKeyPress);

    // Create account guard against registration window/disable rules
    initializeCreateAccountGuard();
}

function initializeCreateAccountGuard() {
    const createAccountLink = document.getElementById('createAccountLink');
    if (!createAccountLink) {
        return;
    }

    createAccountLink.addEventListener('click', function (event) {
        event.preventDefault();

        const targetUrl = createAccountLink.getAttribute('href') || 'forms/student_input_form_1.html';

        fetch(resolveAppUrl('auth/registration_window_status.php'), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Unable to check registration status');
            }
            return response.json();
        })
        .then(data => {
            if (data && data.open === true) {
                window.location.href = new URL(targetUrl, window.location.href).href;
                return;
            }

            const errorMessageEl = document.getElementById('errorMessage');
            if (errorMessageEl) {
                setErrorModalTitle('Registration Closed');
                errorMessageEl.textContent = (data && data.message)
                    ? data.message
                    : 'Registration is currently blocked. Please try again later.';
            }
            showModal('errorModal');
        })
        .catch(() => {
            // Fall back to existing server-side enforcement if status check is unavailable.
            window.location.href = new URL(targetUrl, window.location.href).href;
        });
    });
}

/**
 * Handle keyboard events for modal accessibility
 */
function handleModalKeyPress(e) {
    // Close modal on Escape key
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show, .modal[style*="display: flex"], .modal[style*="display: block"]');
        openModals.forEach(modal => {
            modal.style.setProperty('display', 'none', 'important');
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');
        });
    }
}

/**
 * Select role (Student or Adviser)
 */
function selectRole(role) {
    if (role === 'student') {
        document.getElementById('roleSelectionModal').style.display = 'none';
    } else if (role === 'adviser') {
        window.location.href = 'adviser/login.php';
    }
}

/**
 * Handle login form submission
 */
function handleLoginSubmit(e) {
    e.preventDefault();

    setErrorModalTitle('Login Error');
    
    const form = e.target;
    const submitButton = form.querySelector('button[type="submit"]');
    const originalText = submitButton.textContent;
    const csrfToken = document.getElementById('csrf_token').value;
    
    // Check if CSRF token is present
    if (!csrfToken) {
        // Try to fetch it again
        fetchCSRFToken();
        document.getElementById('errorMessage').textContent = 'Security token missing. Please try again in a moment.';
        showModal('errorModal');
        return;
    }
    
    // Disable button and show loading state
    submitButton.disabled = true;
    submitButton.innerHTML = 'Logging in... <span class="spinner"></span>';
    
    const formData = new FormData(form);
    
    fetch(resolveAppUrl('auth/unified_login_process.php'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.status === 'success') {
            // Show user type specific message
            const userTypeLabel = data.user_type ? data.user_type.charAt(0).toUpperCase() + data.user_type.slice(1) : '';
            submitButton.textContent = 'Success! Redirecting' + (userTypeLabel ? ' to ' + userTypeLabel + ' Portal' : '') + '...';
            window.location.href = data.redirect;
        } else if (data.status === 'pending') {
            showModal('pendingModal');
            submitButton.disabled = false;
            submitButton.textContent = originalText;
        } else if (data.status === 'rejected') {
            showModal('rejectedModal');
            submitButton.disabled = false;
            submitButton.textContent = originalText;
        } else if (data.status === 'error') {
            document.getElementById('errorMessage').textContent = data.message;
            showModal('errorModal');
            submitButton.disabled = false;
            submitButton.textContent = originalText;
        } else if (data.status === 'rate_limited') {
            document.getElementById('errorMessage').textContent = data.message || 'Too many login attempts. Please try again later.';
            showModal('errorModal');
            submitButton.disabled = false;
            submitButton.textContent = originalText;
        }
    })
    .catch(error => {
        console.error('Login error:', error);
        document.getElementById('errorMessage').textContent = 'An error occurred while processing your request. Please try again.';
        showModal('errorModal');
        submitButton.disabled = false;
        submitButton.textContent = originalText;
    });
}

/**
 * Open forgot password modal
 */
function openForgotPasswordModal() {
    const modal = document.getElementById('forgotPasswordModal');
    modal.classList.add('show');
    document.body.classList.add('modal-open');
    
    // Refresh CSRF token
    fetchCSRFToken();
    
    // Reset form
    sessionStorage.removeItem('resetPasswordStudentId');
    sessionStorage.removeItem('resetPasswordCode');
    
    // Clear all messages and hide them
    const forgotMsg = document.getElementById('forgotPasswordMessage');
    const verifyMsg = document.getElementById('verifyCodeMessage');
    const resetMsg = document.getElementById('resetPasswordMessage');
    
    forgotMsg.textContent = '';
    forgotMsg.style.display = 'none';
    forgotMsg.style.color = '';
    
    if (verifyMsg) {
        verifyMsg.textContent = '';
        verifyMsg.style.display = 'none';
    }
    
    if (resetMsg) {
        resetMsg.textContent = '';
        resetMsg.style.display = 'none';
    }
    
    // Reset step visibility
    document.getElementById('forgot-step-1').style.display = 'flex';
    document.getElementById('forgot-step-2').style.display = 'none';
    document.getElementById('forgot-step-3').style.display = 'none';
    
    // Clear input values
    document.getElementById('forgot-student-id').value = '';
    document.getElementById('forgot-code').value = '';
    document.getElementById('forgot-new-password').value = '';
    document.getElementById('forgot-confirm-password').value = '';
    
    // Reset submit button text and state
    const submitBtn = document.querySelector('#forgotEmailForm button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Verification Code';
    }
    
    updateStepIndicator(1);
    
    // Focus on first input
    setTimeout(() => {
        document.getElementById('forgot-student-id').focus();
    }, 100);
}

/**
 * Close forgot password modal
 */
function closeForgotModal() {
    const modal = document.getElementById('forgotPasswordModal');
    modal.classList.remove('show');
    document.body.classList.remove('modal-open');
    
    // Clear stored data
    sessionStorage.removeItem('resetPasswordStudentId');
    sessionStorage.removeItem('resetPasswordCode');
    
    // Reset form fields
    document.getElementById('forgot-student-id').value = '';
    document.getElementById('forgot-code').value = '';
    document.getElementById('forgot-new-password').value = '';
    document.getElementById('forgot-confirm-password').value = '';
    
    // Reset steps visibility
    document.getElementById('forgot-step-1').style.display = 'flex';
    document.getElementById('forgot-step-2').style.display = 'none';
    document.getElementById('forgot-step-3').style.display = 'none';
    
    // Clear messages
    document.getElementById('forgotPasswordMessage').textContent = '';
    document.getElementById('verifyCodeMessage').textContent = '';
    document.getElementById('resetPasswordMessage').textContent = '';
}

/**
 * Update step indicator in forgot password modal
 */
function updateStepIndicator(activeStep) {
    const steps = document.querySelectorAll('#step1Indicator > div, #step2Indicator > div, #step3Indicator > div');
    
    steps.forEach((step, index) => {
        const stepNumber = index + 1;
        if (stepNumber === activeStep) {
            step.style.background = '#206018';
            step.style.color = 'white';
            step.style.boxShadow = '0 4px 12px rgba(32, 96, 24, 0.3)';
        } else if (stepNumber < activeStep) {
            step.style.background = '#4CAF50';
            step.style.color = 'white';
            step.style.boxShadow = 'none';
        } else {
            step.style.background = '#e9ecef';
            step.style.color = '#6c757d';
            step.style.boxShadow = 'none';
        }
    });
}

/**
 * Handle forgot password form submission (Step 1)
 */
function handleForgotPasswordSubmit(e) {
    e.preventDefault();
    
    const studentId = document.getElementById('forgot-student-id').value.trim();
    const messageDiv = document.getElementById('forgotPasswordMessage');
    const submitButton = e.target.querySelector('button[type="submit"]');
    
    messageDiv.style.display = 'block';
    
    // Validation
    if (!studentId) {
        messageDiv.textContent = 'Please enter your Student ID.';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    if (!/^\d+$/.test(studentId)) {
        messageDiv.textContent = 'Please enter a valid Student ID (numbers only).';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    // Disable button and show loading
    submitButton.disabled = true;
    submitButton.textContent = 'Sending code...';
    messageDiv.textContent = 'Sending verification code...';
    messageDiv.style.color = '#206018';
    
    fetch(resolveAppUrl('auth/forgot_password.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'student_id=' + encodeURIComponent(studentId),
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageDiv.textContent = 'A 4-digit code has been sent to your registered email.';
            messageDiv.style.color = '#206018';
            // Store student ID for next step
            sessionStorage.setItem('resetPasswordStudentId', studentId);
            // Transition to step 2
            setTimeout(() => {
                document.getElementById('forgot-step-1').style.display = 'none';
                document.getElementById('forgot-step-2').style.display = 'flex';
                updateStepIndicator(2);
                document.getElementById('forgot-code').focus();
            }, 1500);
        } else {
            messageDiv.textContent = data.message || 'Failed to send code. Please try again.';
            messageDiv.style.color = '#dc3545';
            submitButton.disabled = false;
            submitButton.textContent = 'Send Verification Code';
        }
    })
    .catch(error => {
        console.error('Forgot password error:', error);
        messageDiv.textContent = 'An error occurred. Please try again later.';
        messageDiv.style.color = '#dc3545';
        submitButton.disabled = false;
        submitButton.textContent = 'Send Verification Code';
    });
}

/**
 * Verify code (Step 2)
 */
function verifyForgotCode(event) {
    if (event) event.preventDefault();
    
    const code = document.getElementById('forgot-code').value.trim();
    const studentId = sessionStorage.getItem('resetPasswordStudentId');
    const messageDiv = document.getElementById('verifyCodeMessage');
    const button = document.getElementById('verifyCodeBtn');
    
    // Validation
    if (!code || code.length !== 4) {
        messageDiv.textContent = 'Please enter the 4-digit code.';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    if (!/^\d{4}$/.test(code)) {
        messageDiv.textContent = 'Code must be 4 digits.';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    if (!studentId) {
        messageDiv.textContent = 'Session expired. Please start over.';
        messageDiv.style.color = '#dc3545';
        setTimeout(() => {
            closeForgotModal();
            openForgotPasswordModal();
        }, 2000);
        return;
    }
    
    // Disable button and show loading
    button.disabled = true;
    button.textContent = 'Verifying...';
    messageDiv.textContent = 'Verifying code...';
    messageDiv.style.color = '#206018';
    
    const formData = new FormData();
    formData.append('student_id', studentId);
    formData.append('code', code);
    
    fetch(resolveAppUrl('auth/verify_code.php'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            messageDiv.textContent = 'Code verified successfully!';
            messageDiv.style.color = '#206018';
            // Store verification data
            sessionStorage.setItem('resetPasswordCode', code);
            // Transition to step 3
            setTimeout(() => {
                document.getElementById('forgot-step-2').style.display = 'none';
                document.getElementById('forgot-step-3').style.display = 'flex';
                updateStepIndicator(3);
                document.getElementById('forgot-new-password').focus();
            }, 1000);
        } else {
            messageDiv.textContent = data.message || 'Invalid verification code. Please try again.';
            messageDiv.style.color = '#dc3545';
            button.disabled = false;
            button.textContent = 'Verify Code';
        }
    })
    .catch(error => {
        console.error('Verification error:', error);
        messageDiv.textContent = 'An error occurred. Please try again.';
        messageDiv.style.color = '#dc3545';
        button.disabled = false;
        button.textContent = 'Verify Code';
    });
}

/**
 * Submit new password (Step 3)
 */
function submitForgotPassword(event) {
    if (event) event.preventDefault();
    
    const studentId = sessionStorage.getItem('resetPasswordStudentId');
    const code = sessionStorage.getItem('resetPasswordCode');
    const newPassword = document.getElementById('forgot-new-password').value;
    const confirmPassword = document.getElementById('forgot-confirm-password').value;
    const messageDiv = document.getElementById('resetPasswordMessage');
    const button = document.getElementById('confirmPasswordBtn');
    
    // Validation checks
    if (!studentId || !code) {
        messageDiv.textContent = 'Session expired. Please start over.';
        messageDiv.style.color = '#dc3545';
        setTimeout(() => {
            closeForgotModal();
            openForgotPasswordModal();
        }, 2000);
        return;
    }
    
    if (!newPassword || !confirmPassword) {
        messageDiv.textContent = 'Please fill in both password fields.';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    if (newPassword !== confirmPassword) {
        messageDiv.textContent = 'Passwords do not match.';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    // Password strength validation
    if (newPassword.length < 8) {
        messageDiv.textContent = 'Password must be at least 8 characters long.';
        messageDiv.style.color = '#dc3545';
        return;
    }
    
    // Disable button and show loading
    button.disabled = true;
    button.textContent = 'Resetting Password...';
    messageDiv.textContent = 'Resetting your password...';
    messageDiv.style.color = '#206018';
    
    const formData = new FormData();
    formData.append('student_id', studentId);
    formData.append('code', code);
    formData.append('password', newPassword);
    
    fetch(resolveAppUrl('auth/reset_password.php'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            messageDiv.textContent = 'Password reset successful!';
            messageDiv.style.color = '#206018';
            // Clear sensitive data
            sessionStorage.removeItem('resetPasswordStudentId');
            sessionStorage.removeItem('resetPasswordCode');
            // Show success message
            setTimeout(() => {
                const successMessage = document.createElement('div');
                successMessage.style.textAlign = 'center';
                successMessage.style.marginTop = '20px';
                successMessage.innerHTML = `
                    <div style="color: #206018; font-size: 1.2em; font-weight: bold;">Password Reset Successful!</div>
                    <div style="color: #206018; margin-top: 10px;">You can now log in with your new password.</div>
                `;
                document.getElementById('forgot-step-3').innerHTML = '';
                document.getElementById('forgot-step-3').appendChild(successMessage);
                updateStepIndicator(1);
                // Close modal after delay
                setTimeout(() => {
                    closeForgotModal();
                }, 2500);
            }, 500);
        } else {
            messageDiv.textContent = data.message || 'Failed to reset password. Please try again.';
            messageDiv.style.color = '#dc3545';
            button.disabled = false;
            button.textContent = 'Reset Password';
        }
    })
    .catch(error => {
        console.error('Reset password error:', error);
        messageDiv.textContent = 'An error occurred. Please try again.';
        messageDiv.style.color = '#dc3545';
        button.disabled = false;
        button.textContent = 'Reset Password';
    });
}

/**
 * Open developers modal
 */
function openDevelopersModal() {
    document.body.classList.add('modal-open');
    document.getElementById('developersModal').style.setProperty('display', 'flex', 'important');
    
    // Focus trap for accessibility
    const modal = document.getElementById('developersModal');
    const closeButton = modal.querySelector('button');
    if (closeButton) {
        setTimeout(() => closeButton.focus(), 100);
    }
}

/**
 * Show a modal by ID
 */
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.setProperty('display', 'block', 'important');
        modal.classList.add('show');
        document.body.classList.add('modal-open');
        
        // Focus on first focusable element
        const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) {
            setTimeout(() => focusable.focus(), 100);
        }
    }
}

/**
 * Close modal by ID
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.setProperty('display', 'none', 'important');
        modal.classList.remove('show');
        document.body.classList.remove('modal-open');
    }
}

/**
 * Check password strength
 * @param {string} password - The password to check
 * @returns {object} - Strength level and score
 */
function checkPasswordStrength(password) {
    let score = 0;
    const checks = {
        length: password.length >= 8,
        lowercase: /[a-z]/.test(password),
        uppercase: /[A-Z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[^A-Za-z0-9]/.test(password)
    };
    
    // Calculate score
    if (checks.length) score += 20;
    if (password.length >= 12) score += 10;
    if (checks.lowercase) score += 15;
    if (checks.uppercase) score += 15;
    if (checks.number) score += 20;
    if (checks.special) score += 20;
    
    // Determine strength level
    let strength = 'weak';
    if (score >= 70) strength = 'strong';
    else if (score >= 40) strength = 'medium';
    
    return {
        strength: strength,
        score: score,
        checks: checks
    };
}

/**
 * Update password strength indicator
 * @param {string} password - The password to evaluate
 * @param {string} targetId - ID prefix for the strength indicator elements
 */
function updatePasswordStrength(password, targetId = 'forgot') {
    const strengthBar = document.getElementById(`${targetId}-strength-bar`);
    const strengthText = document.getElementById(`${targetId}-strength-text`);
    const requirementsList = document.getElementById(`${targetId}-requirements`);
    
    if (!strengthBar || !strengthText) return;
    
    if (!password) {
        strengthBar.style.display = 'none';
        strengthText.style.display = 'none';
        if (requirementsList) requirementsList.style.display = 'none';
        return;
    }
    
    strengthBar.style.display = 'block';
    strengthText.style.display = 'flex';
    if (requirementsList) requirementsList.style.display = 'block';
    
    const result = checkPasswordStrength(password);
    const fill = strengthBar.querySelector('.password-strength-fill');
    
    // Update bar
    fill.className = 'password-strength-fill strength-' + result.strength;
    
    // Update text
    strengthText.className = 'password-strength-text ' + result.strength;
    const emoji = result.strength === 'strong' ? '💪' : result.strength === 'medium' ? '👍' : '⚠️';
    strengthText.innerHTML = `${emoji} <span>Password Strength: ${result.strength.charAt(0).toUpperCase() + result.strength.slice(1)}</span>`;
    
    // Update requirements checklist
    if (requirementsList) {
        const items = requirementsList.querySelectorAll('li');
        if (items.length >= 5) {
            items[0].className = result.checks.length ? 'met' : '';
            items[1].className = result.checks.lowercase ? 'met' : '';
            items[2].className = result.checks.uppercase ? 'met' : '';
            items[3].className = result.checks.number ? 'met' : '';
            items[4].className = result.checks.special ? 'met' : '';
        }
    }
}

/**
 * Initialize password strength indicators
 */
function initializePasswordStrength() {
    const newPasswordInput = document.getElementById('forgot-new-password');
    
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function(e) {
            updatePasswordStrength(e.target.value, 'forgot');
        });
        
        newPasswordInput.addEventListener('focus', function() {
            const requirements = document.getElementById('forgot-requirements');
            if (requirements) {
                requirements.style.display = 'block';
            }
        });
    }
}

/**
 * ========================================================================
 * SESSION TIMEOUT WARNING SYSTEM
 * ========================================================================
 * Monitors user activity and warns before session expiration
 */

// Session timeout configuration (in milliseconds)
const SESSION_CONFIG = {
    WARNING_TIME: 15 * 60 * 1000,  // 15 minutes - show warning
    TIMEOUT_TIME: 20 * 60 * 1000,  // 20 minutes - auto logout
    CHECK_INTERVAL: 60 * 1000      // Check every minute
};

let lastActivityTime = Date.now();
let sessionWarningShown = false;
let sessionCheckInterval = null;
let logoutTimer = null;

/**
 * Reset activity timer on user interaction
 */
function resetActivityTimer() {
    lastActivityTime = Date.now();
    sessionWarningShown = false;
    
    // Clear any existing logout timer
    if (logoutTimer) {
        clearTimeout(logoutTimer);
        logoutTimer = null;
    }
    
    // Hide warning modal if visible
    const warningModal = document.getElementById('sessionWarningModal');
    if (warningModal && warningModal.style.display === 'flex') {
        warningModal.style.display = 'none';
    }
}

/**
 * Check session timeout status
 */
function checkSessionTimeout() {
    const now = Date.now();
    const inactiveTime = now - lastActivityTime;
    
    // If past timeout limit, logout immediately
    if (inactiveTime >= SESSION_CONFIG.TIMEOUT_TIME) {
        handleSessionExpired();
        return;
    }
    
    // If past warning time and warning not shown yet, show warning
    if (inactiveTime >= SESSION_CONFIG.WARNING_TIME && !sessionWarningShown) {
        showSessionWarning();
    }
}

/**
 * Show session timeout warning modal
 */
function showSessionWarning() {
    sessionWarningShown = true;
    const warningModal = document.getElementById('sessionWarningModal');
    
    if (!warningModal) {
        createSessionWarningModal();
        return;
    }
    
    warningModal.style.display = 'flex';
    
    // Calculate remaining time
    const remainingTime = Math.floor((SESSION_CONFIG.TIMEOUT_TIME - (Date.now() - lastActivityTime)) / 1000);
    updateWarningCountdown(remainingTime);
    
    // Set auto-logout timer
    logoutTimer = setTimeout(() => {
        handleSessionExpired();
    }, SESSION_CONFIG.TIMEOUT_TIME - (Date.now() - lastActivityTime));
}

/**
 * Create session warning modal dynamically
 */
function createSessionWarningModal() {
    const modal = document.createElement('div');
    modal.id = 'sessionWarningModal';
    modal.className = 'role-modal';
    modal.style.display = 'flex';
    modal.innerHTML = `
        <div class="role-modal-content" style="max-width: 450px; text-align: center;">
            <div style="font-size: 48px; color: #ffc107; margin-bottom: 20px;">⚠️</div>
            <h2 style="color: #206018; margin-bottom: 15px;">Session Timeout Warning</h2>
            <p style="font-size: 16px; color: #666; margin-bottom: 20px;">
                Your session will expire soon due to inactivity.
            </p>
            <p style="font-size: 18px; font-weight: bold; color: #dc3545; margin-bottom: 25px;">
                Time remaining: <span id="sessionCountdown">5:00</span>
            </p>
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button onclick="extendSession()" class="btn btn-primary" style="padding: 12px 30px; font-size: 16px; background: #206018; border: none; border-radius: 5px; color: white; cursor: pointer;">
                    Continue Session
                </button>
                <button onclick="logoutNow()" class="btn btn-secondary" style="padding: 12px 30px; font-size: 16px; background: #6c757d; border: none; border-radius: 5px; color: white; cursor: pointer;">
                    Logout Now
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Update countdown every second
    const countdownInterval = setInterval(() => {
        if (modal.style.display !== 'flex') {
            clearInterval(countdownInterval);
            return;
        }
        const remainingTime = Math.floor((SESSION_CONFIG.TIMEOUT_TIME - (Date.now() - lastActivityTime)) / 1000);
        if (remainingTime <= 0) {
            clearInterval(countdownInterval);
        } else {
            updateWarningCountdown(remainingTime);
        }
    }, 1000);
}

/**
 * Update countdown display
 */
function updateWarningCountdown(seconds) {
    const countdownEl = document.getElementById('sessionCountdown');
    if (countdownEl) {
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        countdownEl.textContent = `${minutes}:${secs.toString().padStart(2, '0')}`;
    }
}

/**
 * Extend session - user clicked continue
 */
function extendSession() {
    resetActivityTimer();
    // Optional: make AJAX call to server to extend PHP session
    fetch('auth/extend_session.php', {
        method: 'POST',
        credentials: 'same-origin'
    }).catch(err => console.log('Session extension skipped'));
}

/**
 * Handle session expired - auto logout
 */
function handleSessionExpired() {
    // Clear intervals
    if (sessionCheckInterval) {
        clearInterval(sessionCheckInterval);
    }
    
    // Show expiration message
    alert('Your session has expired due to inactivity. Please login again.');
    
    // Redirect to logout or refresh page
    window.location.href = 'auth/signout.php';
}

/**
 * Logout immediately when user clicks logout now
 */
function logoutNow() {
    if (sessionCheckInterval) {
        clearInterval(sessionCheckInterval);
    }
    window.location.href = 'auth/signout.php';
}

/**
 * Initialize session timeout monitoring
 * Call this only after user logs in successfully
 */
function initializeSessionTimeout() {
    // Track user activity
    const activityEvents = ['mousedown', 'keypress', 'scroll', 'touchstart', 'click'];
    activityEvents.forEach(event => {
        document.addEventListener(event, resetActivityTimer, true);
    });
    
    // Start periodic session check
    sessionCheckInterval = setInterval(checkSessionTimeout, SESSION_CONFIG.CHECK_INTERVAL);
    
    // Initial activity timestamp
    resetActivityTimer();
}

// Note: initializeSessionTimeout() should be called after successful login
// For now, it's commented out since this is the login page
// Uncomment the line below if you want to test it on this page
// initializeSessionTimeout();

/**
 * Toggle password visibility
 * @param {string} fieldId - The ID of the password input field
 */
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.closest('.password-wrapper').querySelector('.password-toggle');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.setAttribute('aria-label', 'Hide password');
    } else {
        field.type = 'password';
        button.setAttribute('aria-label', 'Show password');
    }
}
