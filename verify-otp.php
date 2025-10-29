<?php
require_once 'config.php';

if (!isset($_SESSION['verify_email'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_POST) {
    $otp = trim($_POST['otp']);
    $email = $_SESSION['verify_email'];
    
    if (verifyOTP($email, $otp)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_type'] = 'student';
        
        // Get student info
        $student = getStudentByEmail($email);
        if ($student) {
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['user_name'] = $student['fullname'];
        }
        
        // Redirect to student dashboard
        header('Location: student-dashboard.php');
        exit;
    } else {
        $error = 'Invalid OTP code or OTP has expired. Please check and try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - PLP SmartGrade</title>
    <style>
        /* Keep all your existing CSS styles from verify-otp.php */
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
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
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

        .back-link {
            display: inline-block;
            margin-top: 1.5rem;
            color: var(--plp-green);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .back-link:hover {
            color: var(--plp-dark-green);
            text-decoration: underline;
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

        @media (max-width: 480px) {
            .verify-container {
                padding: 1.5rem;
            }
            
            .otp-input {
                width: 40px;
                height: 50px;
                font-size: 1.2rem;
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
            <span class="email-display"><?php echo $_SESSION['verify_email']; ?></span>
        </p>
        
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="otpForm">
            <div class="form-group">
                <div class="otp-inputs">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" autofocus>
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                </div>
                <input type="hidden" id="otp" name="otp">
            </div>
            
            <button type="submit" class="btn">
                <i class="fas fa-check-circle"></i>
                Verify & Continue
            </button>
        </form>
        
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Login
        </a>
    </div>

    <script>
        // Auto-focus and move between OTP inputs
        const otpInputs = document.querySelectorAll('.otp-input');
        const otpHiddenInput = document.getElementById('otp');
        
        otpInputs.forEach((input, index) => {
            // Handle paste event
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = e.clipboardData.getData('text');
                if (/^\d{6}$/.test(pasteData)) {
                    pasteData.split('').forEach((char, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = char;
                        }
                    });
                    updateHiddenInput();
                    otpInputs[5].focus();
                }
            });
            
            // Handle input
            input.addEventListener('input', (e) => {
                if (e.target.value.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                updateHiddenInput();
            });
            
            // Handle backspace
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) {
                    otpInputs[index - 1].focus();
                }
            });
        });
        
        function updateHiddenInput() {
            let otpValue = '';
            otpInputs.forEach(input => {
                otpValue += input.value;
            });
            otpHiddenInput.value = otpValue;
        }
        
        // Focus first input on page load
        otpInputs[0].focus();
    </script>
</body>
</html>