<?php
require_once 'config.php';

$error = '';
$success = '';
$showSignupModal = false;
$showOTPModal = false;
$otpError = '';

// Handle login
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && !isset($_POST['signup'])) {
        $email = trim($_POST['email']);
        
        // Validate PLP email
        if (!isValidPLPEmail($email)) {
            $error = 'Please use your valid @plpasig.edu.ph email address.';
        } else {
            // Check if email exists in students table using Supabase
            $student = getStudentByEmail($email);
            
            if (true) { // Bypass email for testing
        // Still store OTP in database
        $otpData = [
            'email' => $email,
            'otp_code' => $otp,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
            'is_used' => false
        ];
        $result = supabaseInsert('otp_verification', $otpData);
    }
        
    if ($result) {
        $_SESSION['verify_email'] = $email;
        $_SESSION['user_type'] = 'student';
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $userName;
        $_SESSION['debug_otp'] = $otp; // Store OTP in session for debugging
        
        // Show the OTP modal
        $showOTPModal = true;
        
        // Also show OTP on screen for testing
        $success = "OTP generated: $otp (Check console for debugging)";
        error_log("DEBUG OTP for $email: $otp");
    } else {
        $error = 'Failed to generate OTP. Please try again.';
    }
}


// Handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    $otp = trim($_POST['otp']);
    $email = $_SESSION['verify_email'] ?? '';
    
    if (empty($email)) {
        $otpError = 'Session expired. Please login again.';
        $showOTPModal = true;
    } elseif (verifyOTP($email, $otp)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_type'] = 'student';
        $_SESSION['user_id'] = $_SESSION['temp_user_id'] ?? null;
        $_SESSION['user_name'] = $_SESSION['temp_user_name'] ?? '';
        
        // Clean up temporary session data
        unset($_SESSION['verify_email']);
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['temp_user_name']);
        
        // Redirect to student dashboard
        header('Location: student-dashboard.php');
        exit;
    } else {
        $otpError = 'Invalid OTP code or OTP has expired. Please check your email and try again.';
        $showOTPModal = true;
    }
}
    
// Handle signup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $student_number = trim($_POST['student_number']);
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $year_level = 2;
    $semester = trim($_POST['semester']);
    $section = trim($_POST['section']);
    $course = 'BS Information Technology';
    
    // Basic validation
    if (empty($student_number) || empty($fullname) || empty($email) || empty($semester) || empty($section)) {
        $error = 'All fields are required.';
        $showSignupModal = true;
    } elseif (!isValidPLPEmail($email)) {
        $error = 'Please use your valid @plpasig.edu.ph email address.';
        $showSignupModal = true;
    } else {
        // Check if student already exists
        $existingStudent = getStudentByEmail($email);
        if ($existingStudent) {
            $error = 'A student with this email already exists.';
            $showSignupModal = true;
        } else {
            // Insert new student
            $studentData = [
                'student_number' => $student_number,
                'fullname' => $fullname,
                'email' => $email,
                'year_level' => $year_level,
                'semester' => $semester,
                'section' => $section,
                'course' => $course
            ];
            
            $result = supabaseInsert('students', $studentData);
            
            if ($result !== false) {
                $success = 'Registration successful! You can now login with your credentials.';
                $showSignupModal = false;
            } else {
                $error = 'Registration failed. Please try again.';
                $showSignupModal = true;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PAMANTASAN NG LUNGSOD NG PASIG - SMART GRADE AI</title>
    <style>
        /* Your existing CSS styles remain exactly the same */
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
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 2rem 1rem;
            color: var(--text-dark);
            line-height: 1.6;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(0, 99, 65, 0.03) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(0, 99, 65, 0.03) 0%, transparent 20%);
        }

        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--plp-green-gradient);
            z-index: 10;
        }
        
        .header {
            text-align: center;
            width: 100%;
            max-width: 1200px;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.8rem;
            margin-top: 0.5rem;
            letter-spacing: 0.5px;
        }
        
        .header p {
            font-size: 1.1rem;
            color: var(--text-medium);
            font-weight: 500;
            margin-bottom: 2rem;
        }
        
        .main-content-wrapper {
            display: flex;
            justify-content: center;
            width: 100%;
        }
        
        .main-content {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5rem;
            position: relative;
        }
        
        .vertical-divider {
            width: 5px;
            height: 380px;
            background: linear-gradient(to bottom, transparent, var(--plp-green-light), transparent);
            box-shadow: 0 0 15px rgba(0, 136, 86, 0.3);
            border-radius: 5px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .logo {
            width: 150%;
            max-width: 400px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.15));
            transition: transform 0.3s ease, filter 0.3s ease;
        }

        .login-container {
            max-width: 380px;
            background-color: white;
            border-radius: var(--border-radius-lg);
            padding: 2rem 3rem;
            box-shadow: var(--box-shadow-lg);
            border: 1px solid rgba(0, 99, 65, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .login-form h3 {
            text-align: center;
            color: var(--plp-green);
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            position: relative;
        }
        
        .login-form h3::after {
            content: "";
            display: block;
            width: 60px;
            height: 4px;
            background: var(--plp-green-light);
            margin: 0.8rem auto 0;
            border-radius: 3px;
        }
        
        .input-group {
            position: relative;
            width: 100%;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--plp-green);
            z-index: 2;
            font-size: 1.1rem;
        }
        
        .login-form input {
            padding: 0.9rem 1rem 0.9rem 45px;
            border: 1px solid rgba(0, 99, 65, 0.2);
            border-radius: var(--border-radius);
            font-size: 1rem;
            width: 100%;
            transition: var(--transition);
            background-color: white;
            color: var(--text-dark);
        }
        
        .login-form input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }
        
        .login-form input::placeholder {
            color: var(--text-light);
            opacity: 0.7;
        }
        
        .login-btn {
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 6px rgba(0, 99, 65, 0.2);
        }
        
        .login-btn:hover {
            background: linear-gradient(135deg, var(--plp-green-light), var(--plp-green));
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 99, 65, 0.3);
        }

        .signup-btn {
            background: transparent;
            color: var(--plp-green);
            border: 2px solid var(--plp-green);
            padding: 0.85rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .signup-btn:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 99, 65, 0.3);
        }
        
        .alert {
            padding: 0.9rem 1rem 0.9rem 3.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-size: 0.9rem;
            text-align: left;
            position: relative;
            color: white;
        }
        
        .alert-error {
            background-color: var(--danger);
            border-left: 4px solid #c53030;
        }
        
        .alert-success {
            background-color: var(--success);
            border-left: 4px solid #2f855a;
        }
        
        .alert-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2rem;
        }

        /* OTP Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .otp-modal, .signup-modal {
            background: white;
            padding: 2.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            overflow: hidden;
            transform: translateY(20px);
            transition: transform 0.4s ease;
        }

        .signup-modal {
            max-width: 500px;
            text-align: left;
        }

        .modal-overlay.active .otp-modal,
        .modal-overlay.active .signup-modal {
            transform: translateY(0);
        }

        .otp-modal::before, .signup-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: var(--plp-green-gradient);
        }

        .otp-modal h1, .signup-modal h1 {
            color: var(--plp-green);
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
            font-weight: 700;
            text-align: center;
        }

        .otp-subtitle {
            color: var(--text-medium);
            margin-bottom: 2rem;
            font-size: 1rem;
            line-height: 1.5;
            text-align: center;
        }

        .email-display {
            font-weight: 600;
            color: var(--plp-green);
            word-break: break-all;
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

        .verify-btn, .signup-submit-btn {
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

        .verify-btn:hover, .signup-submit-btn:hover {
            background: linear-gradient(135deg, var(--plp-green-light), var(--plp-green));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.2);
        }

        .modal-alert-error {
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

        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .close-modal:hover {
            color: var(--plp-green);
        }

        .back-to-login-btn {
            display: block;
            margin-top: 1rem;
            color: var(--plp-green);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            text-align: center;
            background: transparent;
            border: 2px solid var(--plp-green);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            width: 100%;
            font-size: 1rem;
        }

        .back-to-login-btn:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.2);
        }

        .signup-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
        }

        .form-group {
            flex: 1;
        }

        .signup-modal .input-group {
            width: 100%;
        }

        .signup-modal input, .signup-modal select {
            padding: 0.9rem 1rem 0.9rem 45px;
            border: 1px solid rgba(0, 99, 65, 0.2);
            border-radius: var(--border-radius);
            font-size: 1rem;
            width: 100%;
            transition: var(--transition);
            background-color: white;
            color: var(--text-dark);
        }

        .signup-modal input:focus, .signup-modal select:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }


        @media (max-width: 768px) {
            .main-content {
                flex-direction: column;
                gap: 2rem;
            }
            
            .vertical-divider {
                width: 80%;
                height: 2px;
                background: linear-gradient(to right, transparent, var(--plp-green-light), transparent);
                margin: 1rem 0;
            }
            
            .logo {
                max-width: 220px;
            }
            
            .login-container {
                width: 100%;
                max-width: 100%;
                padding: 2rem 1.5rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .header p {
                font-size: 1rem;
                margin-bottom: 2rem;
            }

            .otp-modal, .signup-modal {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .otp-input {
                width: 40px;
                height: 50px;
                font-size: 1.2rem;
            }

            .form-row {
                flex-direction: column;
                gap: 0.5rem;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="header">
        <h1>PLP SMARTGRADE</h1>
        <p>An Intelligent System for Academic Performance Prediction and Risk Assessment<br>across Major Subjects of Second Year BSIT College Students</p>

        
        <div class="main-content-wrapper">
            <div class="main-content">
                <div class="logo-container">
                    <img src="plplogo.png" class="logo" alt="PLP Logo">
                </div>
                
                <div class="vertical-divider"></div>
                
                <div class="login-container">
                    <form class="login-form" method="POST" id="loginForm">
                        <h3>Student Log In</h3>
                        
                        <?php if ($error && !isset($_POST['signup'])): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle alert-icon"></i>
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle alert-icon"></i>
                                <?php echo $success; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="input-group">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="email" name="email" placeholder="Enter your @plpasig.edu.ph" required value="<?php echo isset($_POST['email']) && !isset($_POST['signup']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                        <button type="submit" class="login-btn">
                            <i class="fas fa-sign-in-alt"></i>
                            LOG IN
                        </button>
                                                
                        <button type="button" class="signup-btn" id="showSignupModal">
                            <i class="fas fa-user-plus"></i>
                            SIGN UP
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- OTP Verification Modal -->
    <div class="modal-overlay <?php echo $showOTPModal ? 'active' : ''; ?>" id="otpModal">
        <div class="otp-modal">
            <button type="button" class="close-modal" id="closeOtpModal">
                <i class="fas fa-times"></i>
            </button>
            
            <div class="modal-logo">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>OTP Verification</h1>
            <p class="otp-subtitle">Enter the 6-digit verification code sent to<br>
                <span class="email-display"><?php echo isset($_SESSION['verify_email']) ? htmlspecialchars($_SESSION['verify_email']) : ''; ?></span>
            </p>
            
            <?php if (isset($otpError)): ?>
                <div class="modal-alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $otpError; ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="otpForm">
                <div class="otp-inputs">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric" autofocus>
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                    <input type="text" class="otp-input" maxlength="1" pattern="\d" inputmode="numeric">
                </div>
                <input type="hidden" id="otp" name="otp">
                
                <button type="submit" class="verify-btn">
                    <i class="fas fa-check-circle"></i>
                    Verify & Continue
                </button>
            </form>
            
            <p style="text-align: center; margin-top: 1rem; color: var(--text-medium); font-size: 0.9rem;">
                Didn't receive the OTP? Check your spam folder or <a href="#" onclick="document.getElementById('loginForm').submit(); return false;" style="color: var(--plp-green); text-decoration: none; font-weight: 500;">resend OTP</a>
            </p>
            
            <button type="button" class="back-to-login-btn" id="backToLogin">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </button>
        </div>
    </div>

    <!-- Sign Up Modal -->
    <div class="modal-overlay <?php echo $showSignupModal ? 'active' : ''; ?>" id="signupModal">
        <div class="signup-modal">
            <button type="button" class="close-modal" id="closeSignupModal">
                <i class="fas fa-times"></i>
            </button>
            <h1>Sign Up</h1>
            
            <?php if ($error && isset($_POST['signup'])): ?>
                <div class="modal-alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="signupForm" class="signup-form">
                <input type="hidden" name="signup" value="1">
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-id-card input-icon"></i>
                            <input type="text" id="student_number" name="student_number" placeholder="Student Number" required value="<?php echo isset($_POST['student_number']) ? htmlspecialchars($_POST['student_number']) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="fullname" name="fullname" placeholder="Full Name" required value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="signup_email" name="email" placeholder="Email (@plpasig.edu.ph)" required value="<?php echo isset($_POST['email']) && isset($_POST['signup']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-graduation-cap input-icon"></i>
                            <input type="text" id="year_level" value="2nd Year" readonly style="background-color: var(--plp-green-pale); cursor: not-allowed;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-calendar input-icon"></i>
                            <select id="semester" name="semester" required>
                                <option value="">Select Semester</option>
                                <option value="1st" <?php echo (isset($_POST['semester']) && $_POST['semester'] == '1st') ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd" <?php echo (isset($_POST['semester']) && $_POST['semester'] == '2nd') ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-users input-icon"></i>
                            <input type="text" id="section" name="section" placeholder="Section (e.g., A, B, C)" required value="<?php echo isset($_POST['section']) ? htmlspecialchars($_POST['section']) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-book input-icon"></i>
                            <input type="text" id="course" value="BS Information Technology" readonly style="background-color: var(--plp-green-pale); cursor: not-allowed;">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="signup-submit-btn">
                    <i class="fas fa-user-plus"></i>
                    Register Account
                </button>
            </form>
            
            <p style="text-align: center; margin-top: 1rem; color: var(--text-medium); font-size: 0.9rem;">
                Already have an account? <a href="#" id="showLogin" style="color: var(--plp-green); text-decoration: none; font-weight: 500;">Login here</a>
            </p>
        </div>
    </div>

    <script>
        // Auto-focus and move between OTP inputs
        const otpInputs = document.querySelectorAll('.otp-input');
        const otpHiddenInput = document.getElementById('otp');
        
        function updateHiddenInput() {
            let otpValue = '';
            otpInputs.forEach(input => {
                otpValue += input.value;
            });
            otpHiddenInput.value = otpValue;
        }
        
        if (otpInputs.length > 0) {
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
                        if (otpInputs[5]) {
                            otpInputs[5].focus();
                        }
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
            
            // Focus first input when modal opens
            const otpModal = document.getElementById('otpModal');
            if (otpModal.classList.contains('active')) {
                otpInputs[0].focus();
            }
        }
        
        // Modal functionality
        const showSignupModalBtn = document.getElementById('showSignupModal');
        const signupModal = document.getElementById('signupModal');
        const closeSignupModal = document.getElementById('closeSignupModal');
        const closeOtpModal = document.getElementById('closeOtpModal');
        const showLogin = document.getElementById('showLogin');
        const backToLogin = document.getElementById('backToLogin');
        
        if (showSignupModalBtn) {
            showSignupModalBtn.addEventListener('click', function() {
                signupModal.classList.add('active');
            });
        }
        
        if (closeSignupModal) {
            closeSignupModal.addEventListener('click', function() {
                signupModal.classList.remove('active');
            });
        }
        
        if (closeOtpModal) {
            closeOtpModal.addEventListener('click', function() {
                otpModal.classList.remove('active');
            });
        }
        
        if (showLogin) {
            showLogin.addEventListener('click', function(e) {
                e.preventDefault();
                signupModal.classList.remove('active');
            });
        }
        
        if (backToLogin) {
            backToLogin.addEventListener('click', function() {
                otpModal.classList.remove('active');
            });
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target === signupModal) {
                signupModal.classList.remove('active');
            }
            if (e.target === otpModal) {
                otpModal.classList.remove('active');
            }
        });
    </script>
</body>
</html>