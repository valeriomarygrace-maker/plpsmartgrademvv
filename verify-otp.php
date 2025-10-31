<?php
require_once 'config.php';

// DEBUG: Show OTP if it exists in session for testing
if (isset($_SESSION['debug_otp'])) {
    error_log("🔍 DEBUG OTP: " . $_SESSION['debug_otp'] . " for email: " . $_SESSION['debug_email']);
}

// Redirect if no verification email in session
if (!isset($_SESSION['verify_email'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$email = $_SESSION['verify_email'];

// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = sanitizeInput($_POST['otp']);
    
    // Track OTP attempts
    if (!isset($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = [];
    }
    if (!isset($_SESSION['otp_attempts'][$email])) {
        $_SESSION['otp_attempts'][$email] = [];
    }
    
    // Check rate limiting (max 5 attempts)
    if (count($_SESSION['otp_attempts'][$email]) >= 5) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } else {
        if (verifyOTP($email, $otp)) {
            // Regenerate session for security
            regenerateSession();
            
            $_SESSION['logged_in'] = true;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_type'] = 'student';
            $_SESSION['login_time'] = time();
            
            // Get student info
            $student = getStudentByEmail($email);
            if ($student) {
                $_SESSION['user_id'] = $student['id'];
                $_SESSION['user_name'] = $student['fullname'];
                $_SESSION['course'] = $student['course'] ?? '';
                $_SESSION['year_level'] = $student['year_level'] ?? '';
            }
            
            // Clear OTP attempts and debug data
            if (isset($_SESSION['otp_attempts'][$email])) {
                unset($_SESSION['otp_attempts'][$email]);
            }
            if (isset($_SESSION['debug_otp'])) {
                unset($_SESSION['debug_otp'], $_SESSION['debug_email']);
            }
            if (isset($_SESSION['verify_email'])) {
                unset($_SESSION['verify_email']);
            }
            
            error_log("✅ Login successful for: $email");
            header('Location: student-dashboard.php');
            exit;
        } else {
            // Log failed attempt
            $_SESSION['otp_attempts'][$email][] = time();
            
            // Clean old attempts (older than 15 minutes)
            $current_time = time();
            $_SESSION['otp_attempts'][$email] = array_filter(
                $_SESSION['otp_attempts'][$email],
                function($attempt_time) use ($current_time) {
                    return ($current_time - $attempt_time) < 900; // 15 minutes
                }
            );
            
            $error = 'Invalid OTP code or OTP has expired. Please check and try again.';
            error_log("❌ Failed OTP attempt for: $email with code: $otp");
        }
    }
}

// Get student info for display
$student = getStudentByEmail($email);
$fullname = $student ? $student['fullname'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - PLP SmartGrade</title>
    <style>
        :root {
            --plp-green: #006341;
            --plp-green-light: #008856;
            --plp-green-lighter: #e0f2e9;
            --plp-green-pale: #f5fbf8;
            --plp-green-gradient: linear-gradient(135deg, #006341 0%, #008856 100%);
            --plp-gold: #FFD700;
            --plp-dark-green: #004d33;
            --plp-light-green: #f8fcf9;
            --plp-pale-green: #e8f5e9;
            --text-dark: #2d3748;
            --text-medium: #4a5568;
            --text-light: #718096;
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --box-shadow: 0 4px 12px rgba(0, 99, 65, 0.1);
            --box-shadow-lg: 0 8px 24px rgba(0, 99, 65, 0.15);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --danger: #dc3545;
            --warning: #ffc107;
            --success: #28a745;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--plp-green-pale);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            color: var(--text-dark);
        }

        .verify-container {
            background: white;
            padding: 2.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .verify-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: var(--plp-green-gradient);
        }

        .logo {
            width: 70px;
            height: 70px;
            background: var(--plp-green-gradient);
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.2);
        }

        h1 {
            color: var(--plp-green);
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
            font-weight: 700;
        }

        .subtitle {
            color: var(--text-medium);
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.5;
        }

        .email-display {
            font-weight: 600;
            color: var(--plp-green);
            word-break: break-all;
        }

        .student-info {
            background: var(--plp-green-lighter);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            text-align: left;
        }

        .student-info h3 {
            color: var(--plp-green);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .student-info p {
            margin: 0.25rem 0;
            color: var(--text-medium);
        }

        .form-group {
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .otp-inputs {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .otp-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 1.5rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: bold;
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
            transform: scale(1.05);
        }

        .otp-input.filled {
            border-color: var(--plp-green);
            background-color: var(--plp-green-lighter);
        }

        .btn {
            width: 100%;
            padding: 1rem;
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--plp-green-light), var(--plp-green));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.2);
        }

        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .alert-error {
            background: #fff5f5;
            color: var(--danger);
            border-left: 4px solid var(--danger);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .back-link {
            display: inline-block;
            margin-top: 1.5rem;
            color: var(--plp-green);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--plp-green);
            border-radius: var(--border-radius);
        }

        .back-link:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.2);
            text-decoration: none;
        }

        .resend-link {
            display: block;
            margin-top: 1rem;
            color: var(--plp-green);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .resend-link:hover {
            color: var(--plp-dark-green);
            text-decoration: underline;
        }

        .attempts-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .debug-info {
            background: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-size: 0.85rem;
            text-align: center;
        }

        .timer {
            color: var(--plp-green);
            font-weight: 600;
            margin-top: 0.5rem;
        }

        @media (max-width: 480px) {
            .verify-container {
                padding: 1.5rem;
            }
            
            .otp-input {
                width: 40px;
                height: 50px;
                font-size: 1.2rem;
            }
            
            .logo {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="verify-container">
        <div class="logo">
            <i class="fas fa-shield-alt"></i>
        </div>
        <h1>OTP Verification</h1>
        <p class="subtitle">Enter the 6-digit verification code sent to<br>
            <span class="email-display"><?php echo htmlspecialchars($email); ?></span>
        </p>
        
        <?php if ($fullname): ?>
        <div class="student-info">
            <h3><i class="fas fa-user-graduate"></i> Student Information</h3>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($fullname); ?></p>
            <?php if (isset($student['course'])): ?>
                <p><strong>Course:</strong> <?php echo htmlspecialchars($student['course']); ?></p>
            <?php endif; ?>
            <?php if (isset($student['year_level'])): ?>
                <p><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Debug OTP Display (only shows if email failed) -->
        <?php if (isset($_SESSION['debug_otp'])): ?>
            <div class="debug-info">
                <i class="fas fa-bug"></i>
                <strong>DEBUG MODE:</strong> Email delivery is disabled. Use this OTP: 
                <strong style="font-size: 1.2em;"><?php echo $_SESSION['debug_otp']; ?></strong>
            </div>
        <?php endif; ?>
        
        <?php 
        // Show rate limiting warning
        $attempts = isset($_SESSION['otp_attempts'][$email]) ? count($_SESSION['otp_attempts'][$email]) : 0;
        if ($attempts >= 3): 
        ?>
            <div class="alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>
                    Multiple failed attempts (<?php echo $attempts; ?>/5). 
                    <?php if ($attempts >= 5): ?>
                        Too many failures. Please try again later.
                    <?php else: ?>
                        Too many failures may temporarily lock your account.
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="otpForm">
            <div class="form-group">
                <div class="otp-inputs">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" autofocus data-index="0">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" data-index="1">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" data-index="2">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" data-index="3">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" data-index="4">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" data-index="5">
                </div>
                <input type="hidden" id="otp" name="otp" required>
                <div class="timer" id="timer">
                    OTP expires in: <span id="countdown">10:00</span>
                </div>
            </div>
            
            <button type="submit" class="btn" id="submitBtn">
                <i class="fas fa-check-circle"></i>
                Verify & Continue
            </button>
        </form>
        
        <p style="text-align: center; margin-top: 1rem; color: var(--text-medium); font-size: 0.9rem;">
            Didn't receive the code? 
            <a href="login.php" class="resend-link">
                <i class="fas fa-redo"></i>
                Return to login to resend
            </a>
        </p>
        
        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Login
        </a>
    </div>

    <script>
        // Auto-focus and move between OTP inputs
        const otpInputs = document.querySelectorAll('.otp-input');
        const otpHiddenInput = document.getElementById('otp');
        const submitBtn = document.getElementById('submitBtn');
        const countdownElement = document.getElementById('countdown');
        
        // Set expiration time (10 minutes from now)
        const expirationTime = new Date().getTime() + 10 * 60 * 1000;
        
        // Update countdown timer
        function updateCountdown() {
            const now = new Date().getTime();
            const distance = expirationTime - now;
            
            if (distance < 0) {
                countdownElement.innerHTML = "EXPIRED";
                countdownElement.style.color = "var(--danger)";
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-clock"></i> OTP Expired';
                return;
            }
            
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            countdownElement.innerHTML = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (minutes < 1) {
                countdownElement.style.color = "var(--danger)";
            }
        }
        
        // Update countdown every second
        setInterval(updateCountdown, 1000);
        updateCountdown(); // Initial call
        
        function updateHiddenInput() {
            let otpValue = '';
            otpInputs.forEach(input => {
                otpValue += input.value;
                if (input.value) {
                    input.classList.add('filled');
                } else {
                    input.classList.remove('filled');
                }
            });
            otpHiddenInput.value = otpValue;
            
            // Enable/disable submit button based on OTP completeness
            submitBtn.disabled = otpValue.length !== 6;
        }
        
        otpInputs.forEach((input, index) => {
            // Handle paste event
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = e.clipboardData.getData('text').trim();
                if (/^\d{6}$/.test(pasteData)) {
                    pasteData.split('').forEach((char, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = char;
                        }
                    });
                    updateHiddenInput();
                    otpInputs[5].focus();
                    
                    // Auto-submit after paste
                    setTimeout(() => {
                        if (otpHiddenInput.value.length === 6) {
                            document.getElementById('otpForm').submit();
                        }
                    }, 100);
                }
            });
            
            // Handle input
            input.addEventListener('input', (e) => {
                const value = e.target.value;
                
                // Only allow digits
                if (value && !/^\d$/.test(value)) {
                    e.target.value = '';
                    return;
                }
                
                if (value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                updateHiddenInput();
            });
            
            // Handle backspace
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace') {
                    if (e.target.value.length === 0 && index > 0) {
                        otpInputs[index - 1].focus();
                    }
                    // Clear current input on backspace
                    setTimeout(updateHiddenInput, 0);
                }
                
                // Handle arrow keys for navigation
                if (e.key === 'ArrowLeft' && index > 0) {
                    otpInputs[index - 1].focus();
                    e.preventDefault();
                }
                if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                    e.preventDefault();
                }
            });
            
            // Prevent non-numeric input
            input.addEventListener('keypress', (e) => {
                if (!/^\d$/.test(e.key)) {
                    e.preventDefault();
                }
            });
        });
        
        // Focus first input on page load
        if (otpInputs.length > 0) {
            otpInputs[0].focus();
        }
        
        // Auto-submit when all OTP digits are entered
        otpInputs[5]?.addEventListener('input', function(e) {
            if (e.target.value.length === 1) {
                const allFilled = Array.from(otpInputs).every(input => input.value.length === 1);
                if (allFilled) {
                    // Small delay to ensure all inputs are processed
                    setTimeout(() => {
                        document.getElementById('otpForm').submit();
                    }, 100);
                }
            }
        });
        
        // Form submission handler
        document.getElementById('otpForm').addEventListener('submit', function(e) {
            const otpValue = otpHiddenInput.value;
            if (otpValue.length !== 6) {
                e.preventDefault();
                alert('Please enter a complete 6-digit OTP code.');
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        });
        
        // Initialize button state
        updateHiddenInput();
    </script>
</body>
</html>