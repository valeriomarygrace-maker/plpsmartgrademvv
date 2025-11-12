<?php
require_once 'config.php';

// Initialize variables
$error = '';
$success = '';
$showSignupModal = false;
$email = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && isset($_POST['password']) && !isset($_POST['signup'])) {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    
    // Validate PLP email
    if (!isValidPLPEmail($email)) {
        $error = 'Your email address is not valid.';
    } else {
        // First, check if it's an admin
        $admin = getAdminByEmail($email);
        
        if ($admin) {
            // Admin login
            if (verifyPassword($password, $admin['password'])) {
                // Regenerate session for security
                regenerateSession();
                
                $_SESSION['logged_in'] = true;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_type'] = 'admin';
                $_SESSION['user_id'] = $admin['id'];
                $_SESSION['user_name'] = $admin['fullname'];
                $_SESSION['login_time'] = time();
                
                // Redirect to admin dashboard
                header('Location: admin-dashboard.php');
                exit;
            } else {
                $error = 'Invalid password. Please try again.';
            }
        } else {
            // Check if email exists in students table
            $student = getStudentByEmail($email);
            
            if ($student) {
                // Check if student has a password set
                if (empty($student['password'])) {
                    $error = 'No password set for this account.';
                    $showSignupModal = true;
                } elseif (verifyPassword($password, $student['password'])) {
                    // Regenerate session for security
                    regenerateSession();
                    
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['user_id'] = $student['id'];
                    $_SESSION['user_name'] = $student['fullname'];
                    $_SESSION['login_time'] = time();
                    
                    // Redirect to student dashboard
                    header('Location: student-dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid password. Please try again.';
                }
            } else {
                $error = 'Email not found in our system. Please sign up first.';
                $showSignupModal = true;
            }
        }
    }
}
    
// Handle signup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $student_number = sanitizeInput($_POST['student_number']);
    $fullname = sanitizeInput($_POST['fullname']);
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $year_level = 2;
    $semester = sanitizeInput($_POST['semester']);
    $section = sanitizeInput($_POST['section']);
    $course = 'BS Information Technology';
    
    // Basic validation
    if (empty($student_number) || empty($fullname) || empty($email) || empty($password) || empty($semester) || empty($section)) {
        $error = 'All fields are required.';
        $showSignupModal = true;
    } elseif (!isValidPLPEmail($email)) {
        $error = 'Please input your email address.';
        $showSignupModal = true;
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
        $showSignupModal = true;
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
        $showSignupModal = true;
    } else {
        // Check if student already exists
        $existingStudent = getStudentByEmail($email);
        if ($existingStudent) {
            $error = 'Email is already exists.';
            $showSignupModal = true;
        } else {
            // Insert new student with hashed password
            $studentData = [
                'student_number' => $student_number,
                'fullname' => $fullname,
                'email' => $email,
                'password' => hashPassword($password),
                'year_level' => $year_level,
                'semester' => $semester,
                'section' => $section,
                'course' => $course
            ];
            
            $result = supabaseInsert('students', $studentData);
            
            if ($result !== false) {
                $success = 'Registration successful! ';
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

        /* Updated Login Container Styles */
        .login-container {
            width: 410px;
            background-color: white;
            border-radius: var(--border-radius-lg);
            padding: 2rem 1.8rem;
            box-shadow: var(--box-shadow-lg);
            border: 1px solid rgba(0, 99, 65, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .login-container:hover {
            box-shadow: 0 10px 30px rgba(0, 99, 65, 0.15);
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--plp-green-gradient);
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.4rem;
        }

        .login-form h3 {
            text-align: center;
            color: var(--plp-green);
            margin-bottom: 0.5rem;
            font-size: 1.4rem;
            font-weight: 700;
            position: relative;
            padding-bottom: 0.8rem;
        }

        .login-form h3::after {
            content: "";
            display: block;
            width: 60px;
            height: 4px;
            background: var(--plp-green-light);
            margin: 0.5rem auto 0;
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
            font-size: 1rem;
            transition: var(--transition);
        }

        .login-form input {
            padding: 0.85rem 0.5rem 0.85rem 45px;
            border: 1px solid rgba(0, 99, 65, 0.2);
            border-radius: var(--border-radius);
            font-size: 1rem;
            width: 100%;
            transition: var(--transition);
            background-color: white;
            color: var(--text-dark);
            font-family: 'Poppins', sans-serif;
        }

        .login-form input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .login-form input:focus + .input-icon {
            color: var(--plp-green-light);
            transform: translateY(-50%) scale(1.1);
        }

        .login-form input::placeholder {
            color: var(--text-light);
            opacity: 0.7;
        }

        .login-btn {
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            padding: 0.9rem;
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
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover {
            background: linear-gradient(135deg, var(--plp-green-light), var(--plp-green));
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 99, 65, 0.3);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .signup-btn {
            background: transparent;
            color: var(--plp-green);
            border: 2px solid var(--plp-green);
            padding: 0.8rem;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
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
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 99, 65, 0.2);
        }

        /* Password Toggle Styles */
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--plp-green);
            cursor: pointer;
            font-size: 1.1rem;
            z-index: 3;
            transition: var(--transition);
            padding: 5px;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            background-color: var(--plp-green-lighter);
            transform: translateY(-50%) scale(1.1);
        }

        /* Alert Styles */
        .alert {
            padding: 0.9rem 1rem 0.9rem 3.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            text-align: left;
            position: relative;
            color: white;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* Modal Styles */
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
            padding: 1rem;
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
            max-height: 90vh;
            overflow-y: auto;
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
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-modal:hover {
            color: var(--plp-green);
            background-color: var(--plp-green-lighter);
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

        .signup-modal .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--plp-green);
            z-index: 2;
            font-size: 1rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                flex-direction: column;
                gap: 2rem;
            }
            
            .vertical-divider {
                width: 83%;
                height: 1.5px;
                background: linear-gradient(to right, transparent, var(--plp-green-light), transparent);
                margin: 0.5rem 0;
            }
            
            .logo {
                max-width: 150px;
            }
            
            .login-container {
                width: 100%;
                max-width: 100%;
                padding: 1.8rem 1.2rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .header p {
                font-size: 1rem;
                margin-bottom: 0.5rem;
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
            
            .login-form {
                gap: 1.2rem;
            }
            
            .login-form h3 {
                font-size: 1.3rem;
            }
            
            .login-form input {
                padding: 0.8rem 0.5rem 0.8rem 45px;
            }
        }

        @media (max-width: 480px) {
            .signup-modal {
                padding: 1rem;
            }
            
            .signup-modal h1 {
                font-size: 1.25rem;
            }
            
            .signup-modal input, 
            .signup-modal select {
                padding: 0.65rem 0.65rem 0.65rem 2.25rem;
            }
            
            .close-modal {
                top: 8px;
                right: 8px;
                font-size: 1.1rem;
                width: 28px;
                height: 28px;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .header p {
                font-size: 0.9rem;
            }
            
            .login-container {
                padding: 1.5rem 1rem;
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
                    <h3><?php echo isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin' ? 'Admin' : 'Student'; ?> Log In</h3>
                        
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
                            <input type="email" id="email" name="email" placeholder="Enter your email address" required value="<?php echo isset($_POST['email']) && !isset($_POST['signup']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>

                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="password-toggle" id="passwordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>

                        <button type="submit" class="login-btn">
                            <i class="fas fa-sign-in-alt"></i>
                            LOG IN
                        </button>
                                                
                        <button type="button" class="signup-btn" id="showSignupModal">
                            Register Account
                        </button>
                    </form>
                </div>
            </div>
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
                            <input type="email" id="signup_email" name="email" placeholder="Email Address" required value="<?php echo isset($_POST['email']) && isset($_POST['signup']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="signup_password" name="password" placeholder="Password" required>
                            <button type="button" class="password-toggle" id="signupPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                            <button type="button" class="password-toggle" id="confirmPasswordToggle">
                                <i class="fas fa-eye"></i>
                            </button>
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
            
            <p style="text-align: center; margin-top: 0.75rem; color: var(--text-medium); font-size: 0.85rem;">
                Already have an account? <a href="#" id="showLogin" style="color: var(--plp-green); text-decoration: none; font-weight: 500;">Login here</a>
            </p>
        </div>
    </div>

    <script>
        // Password toggle functionality
        function setupPasswordToggle(toggleId, passwordId) {
            const toggle = document.getElementById(toggleId);
            const passwordInput = document.getElementById(passwordId);
            
            if (toggle && passwordInput) {
                toggle.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    
                    // Toggle eye icon
                    const icon = this.querySelector('i');
                    if (type === 'text') {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            }
        }

        // Initialize password toggles
        setupPasswordToggle('passwordToggle', 'password');
        setupPasswordToggle('signupPasswordToggle', 'signup_password');
        setupPasswordToggle('confirmPasswordToggle', 'confirm_password');

        // Modal functionality
        const showSignupModalBtn = document.getElementById('showSignupModal');
        const signupModal = document.getElementById('signupModal');
        const closeSignupModal = document.getElementById('closeSignupModal');
        const showLogin = document.getElementById('showLogin');
        
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
        
        if (showLogin) {
            showLogin.addEventListener('click', function(e) {
                e.preventDefault();
                signupModal.classList.remove('active');
            });
        }
        
        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target === signupModal) {
                signupModal.classList.remove('active');
            }
        });
    </script>
</body>
</html>