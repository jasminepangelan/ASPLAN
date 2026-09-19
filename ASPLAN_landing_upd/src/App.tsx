import { useState, useEffect } from "react";

const EyeIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
    <circle cx="12" cy="12" r="3" />
  </svg>
);

const EyeOffIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
    <line x1="1" y1="1" x2="23" y2="23" />
  </svg>
);

const UserIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
    <circle cx="12" cy="7" r="4" />
  </svg>
);

const LockIcon = () => (
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
);

const ForgotPasswordModal = ({ onClose, resolveAppUrl }: { onClose: () => void, resolveAppUrl: (path: string) => string }) => {
  const [step, setStep] = useState(1);
  const [studentId, setStudentId] = useState("");
  const [code, setCode] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [message, setMessage] = useState("");
  const [isError, setIsError] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [showPwd1, setShowPwd1] = useState(false);
  const [showPwd2, setShowPwd2] = useState(false);

  const handleSendCode = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage("");
    if (!studentId || !/^\d+$/.test(studentId)) {
      setMessage("Please enter a valid Student ID (numbers only).");
      setIsError(true);
      return;
    }
    setIsLoading(true);
    try {
      const response = await fetch(resolveAppUrl('auth/forgot_password.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'student_id=' + encodeURIComponent(studentId),
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (data.success) {
        setMessage("A 4-digit code has been sent to your registered email.");
        setIsError(false);
        setTimeout(() => setStep(2), 1500);
      } else {
        setMessage(data.message || 'Failed to send code. Please try again.');
        setIsError(true);
      }
    } catch (e) {
      setMessage("An error occurred. Please try again later.");
      setIsError(true);
    } finally {
      setIsLoading(false);
    }
  };

  const handleVerifyCode = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage("");
    if (!code || !/^\d{4}$/.test(code)) {
      setMessage("Code must be 4 digits.");
      setIsError(true);
      return;
    }
    setIsLoading(true);
    try {
      const formData = new FormData();
      formData.append('student_id', studentId);
      formData.append('code', code);
      const response = await fetch(resolveAppUrl('auth/verify_code.php'), {
        method: 'POST', body: formData, credentials: 'same-origin'
      });
      const data = await response.json();
      if (data.success) {
        setMessage("Code verified successfully!");
        setIsError(false);
        setTimeout(() => { setMessage(""); setStep(3); }, 1000);
      } else {
        setMessage(data.message || 'Invalid verification code. Please try again.');
        setIsError(true);
      }
    } catch (e) {
      setMessage("An error occurred. Please try again.");
      setIsError(true);
    } finally {
      setIsLoading(false);
    }
  };

  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage("");
    if (!newPassword || newPassword !== confirmPassword) {
      setMessage("Passwords do not match.");
      setIsError(true);
      return;
    }
    if (newPassword.length < 8) {
      setMessage("Password must be at least 8 characters long.");
      setIsError(true);
      return;
    }
    setIsLoading(true);
    try {
      const formData = new FormData();
      formData.append('student_id', studentId);
      formData.append('code', code);
      formData.append('password', newPassword);
      const response = await fetch(resolveAppUrl('auth/reset_password.php'), {
        method: 'POST', body: formData, credentials: 'same-origin'
      });
      const data = await response.json();
      if (data.success) {
        setMessage("Password Reset Successful! You can now log in.");
        setIsError(false);
        setTimeout(() => onClose(), 2500);
      } else {
        setMessage(data.message || 'Failed to reset password. Please try again.');
        setIsError(true);
      }
    } catch (e) {
      setMessage("An error occurred. Please try again.");
      setIsError(true);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[2000] flex items-center justify-center p-4"
      style={{ background: "rgba(0, 0, 0, 0.75)", backdropFilter: "blur(5px)" }}
    >
      <div
        style={{
          background: "linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%)",
          color: "#333",
          maxWidth: "420px",
          minWidth: "380px",
          width: "100%",
          boxShadow: "0 20px 60px rgba(0, 0, 0, 0.15), 0 8px 32px rgba(0, 0, 0, 0.1)",
          borderRadius: "16px",
          padding: "18px 22px 14px 22px",
        }}
      >
        <div style={{ display: "flex", flexDirection: "column", alignItems: "center", marginTop: "8px", marginBottom: "12px", borderBottom: "1px solid #e9ecef", paddingBottom: "10px" }}>
          <h2 style={{ color: "#206018", fontSize: "1.5rem", fontWeight: 700, margin: "0 0 4px 0", textAlign: "center", letterSpacing: "-0.5px" }}>Reset Password</h2>
          <p style={{ color: "#6c757d", fontSize: "0.9rem", margin: 0, textAlign: "center", fontWeight: 400 }}>Follow the steps to recover your account</p>
        </div>

        <div style={{ display: "flex", justifyContent: "center", alignItems: "center", marginBottom: "12px", gap: "10px" }}>
          <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
            <div style={{ width: "28px", height: "28px", borderRadius: "50%", background: step >= 1 ? "#206018" : "#e9ecef", color: step >= 1 ? "white" : "#6c757d", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 600, fontSize: "13px", transition: "all 0.3s ease" }}>1</div>
          </div>
          <div style={{ width: "24px", height: "2px", background: step >= 2 ? "#206018" : "#e9ecef", borderRadius: "1px" }} />
          <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
            <div style={{ width: "28px", height: "28px", borderRadius: "50%", background: step >= 2 ? "#206018" : "#e9ecef", color: step >= 2 ? "white" : "#6c757d", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 600, fontSize: "13px", transition: "all 0.3s ease" }}>2</div>
          </div>
          <div style={{ width: "24px", height: "2px", background: step >= 3 ? "#206018" : "#e9ecef", borderRadius: "1px" }} />
          <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
            <div style={{ width: "28px", height: "28px", borderRadius: "50%", background: step >= 3 ? "#206018" : "#e9ecef", color: step >= 3 ? "white" : "#6c757d", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 600, fontSize: "13px", transition: "all 0.3s ease" }}>3</div>
          </div>
        </div>

        <div style={{ color: "#333", width: "100%", minHeight: "100px", display: "flex", flexDirection: "column", justifyContent: "flex-start" }}>
          {step === 1 && (
            <form onSubmit={handleSendCode} style={{ display: "flex", flexDirection: "column", gap: "12px" }}>
              <div style={{ textAlign: "center", marginBottom: "4px" }}>
                <h3 style={{ color: "#206018", fontSize: "1.1rem", fontWeight: 600, margin: "0 0 4px 0" }}>Enter Your Student ID</h3>
                <p style={{ color: "#6c757d", fontSize: "0.9rem", margin: 0 }}>We&apos;ll send a verification code to your registered email</p>
              </div>
              <input type="text" value={studentId} onChange={(e) => setStudentId(e.target.value)} placeholder="Enter Student ID" required pattern="[0-9]+" style={{ width: "100%", padding: "10px 12px", border: "2px solid #e9ecef", borderRadius: "12px", fontSize: "15px", fontWeight: 500, background: "#fff", transition: "all 0.3s ease", boxSizing: "border-box", outline: "none" }} />
              <div style={{ minHeight: "20px", fontSize: "14px", fontWeight: 500, textAlign: "center", color: isError ? "#dc3545" : "#206018" }}>{message}</div>
              <button type="submit" disabled={isLoading} style={{ width: "100%", padding: "11px", background: "linear-gradient(135deg, #206018 0%, #4CAF50 100%)", color: "white", border: "none", borderRadius: "12px", fontSize: "15px", fontWeight: 600, cursor: isLoading ? "not-allowed" : "pointer", opacity: isLoading ? 0.7 : 1, transition: "all 0.3s ease", boxShadow: "0 4px 16px rgba(32, 96, 24, 0.3)" }}>
                {isLoading ? "Sending code..." : "Send Verification Code"}
              </button>
            </form>
          )}

          {step === 2 && (
            <form onSubmit={handleVerifyCode} style={{ display: "flex", flexDirection: "column", gap: "12px", alignItems: "center" }}>
              <div style={{ textAlign: "center", marginBottom: "4px" }}>
                <h3 style={{ color: "#206018", fontSize: "1.1rem", fontWeight: 600, margin: "0 0 4px 0" }}>Enter Verification Code</h3>
                <p style={{ color: "#6c757d", fontSize: "0.9rem", margin: 0 }}>Check your CvSU email for the 4-digit code</p>
              </div>
              <input type="text" maxLength={4} value={code} onChange={(e) => setCode(e.target.value)} placeholder="0000" pattern="[0-9]{4}" style={{ width: "140px", padding: "16px", border: "2px solid #e9ecef", borderRadius: "12px", fontSize: "24px", fontWeight: 700, textAlign: "center", letterSpacing: "8px", background: "#fff", margin: "0 auto", transition: "all 0.3s ease", outline: "none" }} />
              <div style={{ minHeight: "20px", fontSize: "14px", fontWeight: 500, textAlign: "center", color: isError ? "#dc3545" : "#206018" }}>{message}</div>
              <button type="submit" disabled={isLoading} style={{ width: "100%", padding: "11px", background: "linear-gradient(135deg, #206018 0%, #4CAF50 100%)", color: "white", border: "none", borderRadius: "12px", fontSize: "15px", fontWeight: 600, cursor: isLoading ? "not-allowed" : "pointer", opacity: isLoading ? 0.7 : 1, transition: "all 0.3s ease", boxShadow: "0 4px 16px rgba(32, 96, 24, 0.3)" }}>
                {isLoading ? "Verifying..." : "Verify Code"}
              </button>
            </form>
          )}

          {step === 3 && (
            <form onSubmit={handleResetPassword} style={{ display: "flex", flexDirection: "column", gap: "4px" }}>
              <div style={{ textAlign: "center", marginBottom: "0" }}>
                <h3 style={{ color: "#206018", fontSize: "1rem", fontWeight: 600, margin: "0" }}>Create New Password</h3>
                <p style={{ color: "#6c757d", fontSize: "0.85rem", margin: 0 }}>Choose a strong password for your account</p>
              </div>

              <div className="relative mt-2">
                <input type={showPwd1 ? "text" : "password"} value={newPassword} onChange={(e) => setNewPassword(e.target.value)} placeholder="New Password" minLength={8} required style={{ width: "100%", padding: "10px 14px", border: "2px solid #e9ecef", borderRadius: "10px", fontSize: "13px", background: "#fff", transition: "all 0.3s ease", boxSizing: "border-box", outline: "none" }} />
                <button type="button" onClick={() => setShowPwd1(!showPwd1)} style={{ position: "absolute", right: "10px", top: "50%", transform: "translateY(-50%)", background: "none", border: "none", cursor: "pointer", color: "#6b7280" }}>
                  {showPwd1 ? <EyeOffIcon /> : <EyeIcon />}
                </button>
              </div>

              <div className="relative mt-2">
                <input type={showPwd2 ? "text" : "password"} value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} placeholder="Confirm Password" minLength={8} required style={{ width: "100%", padding: "10px 14px", border: "2px solid #e9ecef", borderRadius: "10px", fontSize: "13px", background: "#fff", transition: "all 0.3s ease", boxSizing: "border-box", outline: "none" }} />
                <button type="button" onClick={() => setShowPwd2(!showPwd2)} style={{ position: "absolute", right: "10px", top: "50%", transform: "translateY(-50%)", background: "none", border: "none", cursor: "pointer", color: "#6b7280" }}>
                  {showPwd2 ? <EyeOffIcon /> : <EyeIcon />}
                </button>
              </div>

              <div style={{ minHeight: "16px", fontSize: "13px", fontWeight: 500, textAlign: "center", color: isError ? "#dc3545" : "#206018", marginTop: "4px" }}>{message}</div>

              <button type="submit" disabled={isLoading} style={{ width: "100%", padding: "10px", background: "linear-gradient(135deg, #206018 0%, #4CAF50 100%)", color: "white", border: "none", borderRadius: "10px", fontSize: "14px", fontWeight: 600, cursor: isLoading ? "not-allowed" : "pointer", opacity: isLoading ? 0.7 : 1, transition: "all 0.3s ease", boxShadow: "0 4px 16px rgba(32, 96, 24, 0.3)", marginTop: "4px" }}>
                {isLoading ? "Resetting Password..." : "Reset Password"}
              </button>
            </form>
          )}
        </div>

        <div style={{ display: "flex", justifyContent: "center", marginTop: "8px", paddingTop: "8px", borderTop: "1px solid #e9ecef" }}>
          <button onClick={onClose} style={{ padding: "10px 22px", background: "#6c757d", color: "white", border: "none", borderRadius: "8px", fontSize: "13px", fontWeight: 500, cursor: "pointer", transition: "all 0.3s ease" }}>Cancel</button>
        </div>
      </div>
    </div>
  );
};

export default function App() {
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [emailFocused, setEmailFocused] = useState(false);
  const [passwordFocused, setPasswordFocused] = useState(false);

  // Backend Integration State
  const [csrfToken, setCsrfToken] = useState("");
  const [isLoggingIn, setIsLoggingIn] = useState(false);
  const [modalType, setModalType] = useState<"none" | "error" | "pending" | "rejected" | "about" | "forgot">("none");
  const [errorMessage, setErrorMessage] = useState("");

  const resolveAppUrl = (path: string) => {
    return new URL(path, window.location.href).href;
  };

  useEffect(() => {
    // Fetch CSRF Token
    fetch(resolveAppUrl('auth/get_csrf_token.php'), {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then(r => r.json())
      .then(data => {
        if (data.success && data.token) setCsrfToken(data.token);
      })
      .catch(err => console.error("Failed to fetch CSRF", err));

    // Check auto login
    fetch(resolveAppUrl('auth/check_auto_login.php'), { method: 'GET', credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data.redirect) window.location.href = resolveAppUrl(data.redirect);
      })
      .catch(() => { });
  }, []);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!csrfToken) {
      setErrorMessage("Security token missing. Please refresh the page.");
      setModalType("error");
      return;
    }

    setIsLoggingIn(true);
    const formData = new FormData();
    formData.append("username", email);
    formData.append("password", password);
    formData.append("csrf_token", csrfToken);
    if (rememberMe) formData.append("remember_me", "1");

    try {
      const response = await fetch(resolveAppUrl('auth/unified_login_process.php'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });
      const data = await response.json();

      if (data.status === 'success') {
        window.location.href = resolveAppUrl(data.redirect);
      } else if (data.status === 'pending') {
        setModalType("pending");
      } else if (data.status === 'rejected') {
        setModalType("rejected");
      } else if (data.status === 'error' || data.status === 'rate_limited') {
        setErrorMessage(data.message || 'An error occurred.');
        setModalType("error");
      }
    } catch (err) {
      setErrorMessage("An error occurred while processing your request.");
      setModalType("error");
    } finally {
      setIsLoggingIn(false);
    }
  };

  const handleSignUp = () => {
    window.location.href = resolveAppUrl("forms/student_input_form_1.html");
  };

  const closeModal = () => setModalType("none");

  return (
    <div
      className="size-full min-h-screen relative flex flex-col items-center justify-center overflow-hidden"
      style={{ fontFamily: "'Inter', sans-serif" }}
    >
      {/* Background image */}
      <div
        className="absolute inset-0 bg-emerald-950"
        style={{
          backgroundImage: `url(img/drone_cvsu_1.jpg)`,
          backgroundSize: "cover",
          backgroundPosition: "center",
        }}
      />

      {/* Dark gradient overlay */}
      <div
        className="absolute inset-0"
        style={{
          background:
            "linear-gradient(135deg, rgba(10,31,17,0.82) 0%, rgba(17,17,17,0.78) 100%)",
        }}
      />

      {/* Radial green glow behind card */}
      <div
        className="absolute"
        style={{
          width: "700px",
          height: "500px",
          top: "50%",
          left: "50%",
          transform: "translate(-50%, -50%)",
          background:
            "radial-gradient(ellipse at center, rgba(16,185,129,0.18) 0%, transparent 70%)",
          pointerEvents: "none",
        }}
      />

      {/* Main Card */}
      <div
        className="relative z-10 w-full flex rounded-3xl overflow-hidden"
        style={{
          maxWidth: "960px",
          minHeight: "580px",
          boxShadow:
            "0 32px 80px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.06)",
          margin: "0 16px",
        }}
      >
        {/* Left Column — Branding */}
        <div
          className="relative flex flex-col items-center justify-center p-12 hidden md:flex"
          style={{
            width: "50%",
            background:
              "linear-gradient(160deg, #0A3320 0%, #062213 60%, #041a0e 100%)",
            flexShrink: 0,
          }}
        >
          {/* Decorative radial glow */}
          <div
            className="absolute inset-0 pointer-events-none"
            style={{
              background:
                "radial-gradient(ellipse at 50% 40%, rgba(16,185,129,0.13) 0%, transparent 65%)",
            }}
          />

          {/* Logo mark */}
          <div className="relative z-10 flex flex-col items-center text-center gap-6">
            <div
              className="flex items-center justify-center rounded-2xl mb-2"
              style={{
                width: "80px",
                height: "80px",
                background:
                  "linear-gradient(135deg, rgba(16,185,129,0.2) 0%, rgba(4,120,87,0.35) 100%)",
                border: "1px solid rgba(16,185,129,0.35)",
                boxShadow: "0 8px 32px rgba(16,185,129,0.2)",
              }}
            >
              <img
                src="img/cav.png"
                alt="CvSU Logo"
                style={{
                  width: "100%",
                  height: "100%",
                  objectFit: "contain",
                  borderRadius: "16px"
                }}
              />
            </div>

            {/* Brand name */}
            <div>
              <h1
                style={{
                  fontFamily: "'Outfit', sans-serif",
                  fontSize: "48px",
                  fontWeight: 900,
                  color: "#ffffff",
                  lineHeight: 1,
                  letterSpacing: "-1px",
                  margin: 0,
                }}
              >
                ASPLAN
              </h1>
              {/* Divider */}
              <div
                className="mx-auto my-4"
                style={{
                  width: "40px",
                  height: "2px",
                  background:
                    "linear-gradient(90deg, transparent, #10B981, transparent)",
                }}
              />
              <p
                style={{
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "13px",
                  fontWeight: 400,
                  color: "rgba(167, 243, 208, 0.75)",
                  letterSpacing: "0.08em",
                  textTransform: "uppercase",
                  margin: 0,
                }}
              >
                Automated Study Plan Generator
              </p>
            </div>

            {/* Tagline */}
            <div
              className="mt-4 px-6 py-4 rounded-2xl text-center"
              style={{
                background: "rgba(16,185,129,0.08)",
                border: "1px solid rgba(16,185,129,0.15)",
              }}
            >
              <p
                style={{
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "14px",
                  fontWeight: 400,
                  color: "rgba(255,255,255,0.55)",
                  lineHeight: 1.6,
                  margin: 0,
                }}
              >
                Personalized academic paths,<br />intelligently crafted for you.
              </p>
            </div>

            {/* Decorative dots */}
            <div className="flex gap-2 mt-2">
              {[1, 2, 3].map((i) => (
                <div
                  key={i}
                  style={{
                    width: i === 2 ? "20px" : "6px",
                    height: "6px",
                    borderRadius: "99px",
                    background:
                      i === 2
                        ? "#10B981"
                        : "rgba(16,185,129,0.3)",
                  }}
                />
              ))}
            </div>
          </div>
        </div>

        {/* Right Column — Login Form (Glassmorphism) */}
        <div
          className="glass-panel flex flex-col justify-center p-10 w-full"
          style={{ width: "50%", minWidth: "min(100%, 420px)" }}
        >
          {/* Header */}
          <div className="mb-8">
            <h2
              style={{
                fontFamily: "'Outfit', sans-serif",
                fontSize: "28px",
                fontWeight: 700,
                color: "#ffffff",
                margin: "0 0 6px 0",
                letterSpacing: "-0.3px",
              }}
            >
              Welcome Back
            </h2>
            <p
              style={{
                fontFamily: "'Inter', sans-serif",
                fontSize: "14px",
                color: "rgba(255,255,255,0.5)",
                margin: 0,
              }}
            >
              Please enter your details to sign in
            </p>
          </div>

          <form onSubmit={handleLogin} className="flex flex-col gap-4">
            {/* Email / Username field */}
            <div className="flex flex-col gap-1.5">
              <label
                style={{
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "13px",
                  fontWeight: 500,
                  color: "rgba(255,255,255,0.7)",
                }}
              >
                Student Number
              </label>
              <div className="relative">
                <span
                  className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                  style={{
                    color: emailFocused
                      ? "#10B981"
                      : "rgba(255,255,255,0.35)",
                    transition: "color 0.2s",
                  }}
                >
                  <UserIcon />
                </span>
                <input
                  type="text"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  onFocus={() => setEmailFocused(true)}
                  onBlur={() => setEmailFocused(false)}
                  placeholder="ex. 220100123"
                  className="input-field w-full pl-9 pr-4 py-3 rounded-xl text-white placeholder-white/25 transition-all"
                  style={{
                    fontFamily: "'Inter', sans-serif",
                    fontSize: "14px",
                    background: "rgba(255,255,255,0.07)",
                    border: emailFocused
                      ? "1px solid #10B981"
                      : "1px solid rgba(255,255,255,0.12)",
                    boxShadow: emailFocused
                      ? "0 0 0 3px rgba(16,185,129,0.12)"
                      : "none",
                    outline: "none",
                    transition: "border 0.2s, box-shadow 0.2s",
                  }}
                />
              </div>
            </div>

            {/* Password field */}
            <div className="flex flex-col gap-1.5">
              <label
                style={{
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "13px",
                  fontWeight: 500,
                  color: "rgba(255,255,255,0.7)",
                }}
              >
                Password
              </label>
              <div className="relative">
                <span
                  className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                  style={{
                    color: passwordFocused
                      ? "#10B981"
                      : "rgba(255,255,255,0.35)",
                    transition: "color 0.2s",
                  }}
                >
                  <LockIcon />
                </span>
                <input
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  onFocus={() => setPasswordFocused(true)}
                  onBlur={() => setPasswordFocused(false)}
                  placeholder="Enter your password"
                  className="w-full pl-9 pr-10 py-3 rounded-xl text-white placeholder-white/25 transition-all"
                  style={{
                    fontFamily: "'Inter', sans-serif",
                    fontSize: "14px",
                    background: "rgba(255,255,255,0.07)",
                    border: passwordFocused
                      ? "1px solid #10B981"
                      : "1px solid rgba(255,255,255,0.12)",
                    boxShadow: passwordFocused
                      ? "0 0 0 3px rgba(16,185,129,0.12)"
                      : "none",
                    outline: "none",
                    transition: "border 0.2s, box-shadow 0.2s",
                  }}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((p) => !p)}
                  className="absolute right-3 top-1/2 -translate-y-1/2"
                  style={{
                    color: "rgba(255,255,255,0.4)",
                    background: "none",
                    border: "none",
                    cursor: "pointer",
                    padding: 0,
                    display: "flex",
                    alignItems: "center",
                    transition: "color 0.2s",
                  }}
                  onMouseEnter={(e) =>
                    (e.currentTarget.style.color = "#10B981")
                  }
                  onMouseLeave={(e) =>
                    (e.currentTarget.style.color = "rgba(255,255,255,0.4)")
                  }
                >
                  {showPassword ? <EyeOffIcon /> : <EyeIcon />}
                </button>
              </div>
            </div>

            {/* Remember me + Forgot password */}
            <div className="flex items-center justify-between mt-1">
              <label
                className="flex items-center gap-2 cursor-pointer select-none"
                style={{
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "13px",
                  color: "rgba(255,255,255,0.55)",
                }}
              >
                <input
                  type="checkbox"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  style={{ accentColor: "#10B981" }}
                />
                Remember me
              </label>
              <button
                type="button"
                onClick={() => setModalType("forgot")}
                style={{
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "13px",
                  color: "#10B981",
                  background: "none",
                  border: "none",
                  cursor: "pointer",
                  padding: 0,
                  fontWeight: 500,
                }}
              >
                Forgot password?
              </button>
            </div>

            {/* Login button */}
            <button
              type="submit"
              disabled={isLoggingIn}
              className="btn-primary mt-2 w-full py-3 rounded-xl text-white font-semibold"
              style={{
                fontFamily: "'Outfit', sans-serif",
                fontSize: "15px",
                fontWeight: 600,
                letterSpacing: "0.02em",
                cursor: isLoggingIn ? "not-allowed" : "pointer",
                border: "none",
                background: isLoggingIn ? "#065f46" : undefined,
                opacity: isLoggingIn ? 0.7 : 1,
              }}
            >
              {isLoggingIn ? "Logging in..." : "Log In"}
            </button>

            {/* Sign up link */}
            <p
              className="text-center mt-1"
              style={{
                fontFamily: "'Inter', sans-serif",
                fontSize: "13px",
                color: "rgba(255,255,255,0.45)",
              }}
            >
              Don&apos;t have an account?{" "}
              <button
                type="button"
                onClick={handleSignUp}
                style={{
                  background: "none",
                  border: "none",
                  padding: 0,
                  cursor: "pointer",
                  color: "#10B981",
                  fontWeight: 600,
                  fontFamily: "'Inter', sans-serif",
                  fontSize: "13px",
                }}
              >
                Sign up
              </button>
            </p>
          </form>
        </div>
      </div>

      {/* Footer link */}
      <div className="relative z-10 mt-6">
        <button
          type="button"
          onClick={() => setModalType("about")}
          style={{
            fontFamily: "'Inter', sans-serif",
            fontSize: "12px",
            color: "rgba(255,255,255,0.35)",
            background: "none",
            border: "none",
            cursor: "pointer",
            letterSpacing: "0.04em",
            transition: "color 0.2s",
          }}
          onMouseEnter={(e) =>
            (e.currentTarget.style.color = "rgba(255,255,255,0.65)")
          }
          onMouseLeave={(e) =>
            (e.currentTarget.style.color = "rgba(255,255,255,0.35)")
          }
        >
          About Us
        </button>
      </div>

      {/* Simple Modals */}
      {modalType !== "none" && modalType !== "about" && (
        <div
          className="fixed inset-0 z-[2000] flex items-center justify-center p-4"
          style={{ background: "rgba(0, 0, 0, 0.75)", backdropFilter: "blur(5px)" }}
        >
          <div
            className="bg-white rounded-2xl p-8 max-w-md w-full relative shadow-2xl text-center"
            style={{ color: "#111" }}
          >
            {modalType === "error" && (
              <>
                <h3 className="text-2xl font-bold mb-2 text-red-600">Login Error</h3>
                <p className="text-gray-600 mb-6">{errorMessage}</p>
              </>
            )}
            {modalType === "pending" && (
              <>
                <h3 className="text-2xl font-bold mb-2 text-yellow-600">Account Pending</h3>
                <p className="text-gray-600 mb-6">Your account is pending approval. Please wait for the admin to approve your registration.</p>
              </>
            )}
            {modalType === "rejected" && (
              <>
                <h3 className="text-2xl font-bold mb-2 text-red-600">Account Rejected</h3>
                <p className="text-gray-600 mb-6">Your account was rejected. Please contact admin for more information.</p>
              </>
            )}
            {modalType === "forgot" && (
              <ForgotPasswordModal onClose={closeModal} resolveAppUrl={resolveAppUrl} />
            )}

            <button
              onClick={closeModal}
              className="px-6 py-2 bg-emerald-600 text-white rounded-lg font-semibold hover:bg-emerald-700 transition-colors"
            >
              Close
            </button>
          </div>
        </div>
      )}

      {/* About Us Modal */}
      {modalType === "about" && (
        <div
          className="fixed inset-0 flex items-center justify-center z-[2000]"
          style={{ background: "rgba(0, 0, 0, 0.85)", backdropFilter: "blur(12px)" }}
        >
          <div
            className="relative text-center mx-auto overflow-y-auto block"
            style={{
              background: "linear-gradient(135deg, rgba(20,20,20,0.95) 0%, rgba(10,30,15,0.98) 100%)",
              width: "95%",
              maxWidth: "1000px",
              maxHeight: "90vh",
              borderRadius: "30px",
              padding: "50px 40px",
              boxShadow: "0 30px 60px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255,255,255,0.1)",
              border: "1px solid rgba(255,255,255,0.05)"
            }}
          >
            <button
              onClick={closeModal}
              className="absolute flex items-center justify-center transition-all duration-300 rounded-full text-white hover:bg-white/20 hover:rotate-90"
              style={{
                top: "20px", right: "25px", background: "rgba(255,255,255,0.1)",
                width: "40px", height: "40px", fontSize: "24px"
              }}
            >
              &times;
            </button>

            <div style={{ marginBottom: "40px" }}>
              <h2 style={{ color: "#fff", margin: "0 0 8px 0", fontSize: "36px", fontWeight: 300, letterSpacing: "2px", textTransform: "uppercase" }}>
                The <strong style={{ color: "#4CAF50", fontWeight: 800 }}>Researchers</strong>
              </h2>
              <div style={{ width: "60px", height: "3px", background: "#4CAF50", margin: "0 auto 15px", borderRadius: "3px", boxShadow: "0 0 10px #4CAF50" }} />
              <p style={{ color: "#a0aec0", margin: 0, fontSize: "15px", fontWeight: 400, letterSpacing: "0.5px" }}>
                Researchers and Developers behind ASPLAN
              </p>
            </div>

            <div className="flex flex-wrap justify-center gap-[30px]">
              {/* Person 1 */}
              <div
                className="flex-1 min-w-[250px] p-[35px_25px] rounded-[20px] transition-all duration-300 hover:scale-[1.05] hover:z-10 hover:border-[#4CAF50] hover:shadow-[0_20px_40px_rgba(32,96,24,0.4)]"
                style={{ background: "rgba(255, 255, 255, 0.03)", border: "1px solid rgba(255,255,255,0.08)", backdropFilter: "blur(10px)" }}
              >
                <img src="img/tiozon.png" alt="Stephen L. Tiozon" style={{ width: "140px", height: "140px", borderRadius: "50%", objectFit: "cover", margin: "0 auto 20px", display: "block", border: "2px solid rgba(76, 175, 80, 0.5)", boxShadow: "0 0 20px rgba(76, 175, 80, 0.2)" }} />
                <h3 style={{ margin: "0 0 6px", color: "#fff", fontSize: "22px", fontWeight: 600, letterSpacing: "0.5px" }}>Stephen L. Tiozon</h3>
                <p style={{ margin: "0 0 15px", color: "#4CAF50", fontWeight: 500, fontSize: "12px", textTransform: "uppercase", letterSpacing: "1.5px" }}>Lead Programmer</p>
                <p style={{ margin: 0, color: "#8e9cae", fontSize: "14px", lineHeight: 1.6, fontWeight: 300 }}>Crafting robust architectures and seamless experiences through innovative coding.</p>
              </div>

              {/* Person 2 */}
              <div
                className="flex-1 min-w-[250px] p-[35px_25px] rounded-[20px] transition-all duration-300 hover:scale-[1.05] hover:z-10 hover:border-[#4CAF50] hover:shadow-[0_20px_40px_rgba(32,96,24,0.4)]"
                style={{ background: "rgba(255, 255, 255, 0.03)", border: "1px solid rgba(255,255,255,0.08)", backdropFilter: "blur(10px)" }}
              >
                <img src="img/vasquez.png" alt="Justine Carl A. Vasquez" style={{ width: "140px", height: "140px", borderRadius: "50%", objectFit: "cover", margin: "0 auto 20px", display: "block", border: "2px solid rgba(76, 175, 80, 0.5)", boxShadow: "0 0 20px rgba(76, 175, 80, 0.2)" }} />
                <h3 style={{ margin: "0 0 6px", color: "#fff", fontSize: "22px", fontWeight: 600, letterSpacing: "0.5px" }}>Justine Carl A. Vasquez</h3>
                <p style={{ margin: "0 0 15px", color: "#4CAF50", fontWeight: 500, fontSize: "12px", textTransform: "uppercase", letterSpacing: "1.5px" }}>UI/UX Designer</p>
                <p style={{ margin: 0, color: "#8e9cae", fontSize: "14px", lineHeight: 1.6, fontWeight: 300 }}>Transforming complex requirements into elegant, intuitive, and beautiful interfaces.</p>
              </div>

              {/* Person 3 */}
              <div
                className="flex-1 min-w-[250px] p-[35px_25px] rounded-[20px] transition-all duration-300 hover:scale-[1.05] hover:z-10 hover:border-[#4CAF50] hover:shadow-[0_20px_40px_rgba(32,96,24,0.4)]"
                style={{ background: "rgba(255, 255, 255, 0.03)", border: "1px solid rgba(255,255,255,0.08)", backdropFilter: "blur(10px)" }}
              >
                <img src="img/salazar.png" alt="Vincent Angelo T. Salazar" style={{ width: "140px", height: "140px", borderRadius: "50%", objectFit: "cover", margin: "0 auto 20px", display: "block", border: "2px solid rgba(76, 175, 80, 0.5)", boxShadow: "0 0 20px rgba(76, 175, 80, 0.2)" }} />
                <h3 style={{ margin: "0 0 6px", color: "#fff", fontSize: "22px", fontWeight: 600, letterSpacing: "0.5px" }}>Vincent Angelo T. Salazar</h3>
                <p style={{ margin: "0 0 15px", color: "#4CAF50", fontWeight: 500, fontSize: "12px", textTransform: "uppercase", letterSpacing: "1.5px" }}>Project Manager</p>
                <p style={{ margin: 0, color: "#8e9cae", fontSize: "14px", lineHeight: 1.6, fontWeight: 300 }}>Ensuring strategic alignment, timely delivery, and operational excellence.</p>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
