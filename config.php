<?php
require_once 'config.php';

$error = '';
$success = '';
$showSignupModal = false;
$showOTPModal = false;
$otpError = '';

if ($_POST) {
    // Handle login
    if (isset($_POST['email']) && !isset($_POST['signup'])) {
        $email = trim($_POST['email']);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@plpasig.edu.ph')) {
            $error = 'Please use your @plpasig.edu.ph email address.';
        } else {
            // Check if email exists in students table using Supabase
            $student = getStudentByEmail($email);
            
            if ($student) {
                $userId = $student['id'];
                $userName = $student['fullname'];
                
                $otp = generateOTP();
                
                if (sendOTP($email, $otp)) {
                    $_SESSION['verify_email'] = $email;
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = $userName;
                    
                    // Show the OTP modal
                    $showOTPModal = true;
                } else {
                    $error = 'Failed to send OTP. Please try again.';
                }
            } else {
                $error = 'Email not found in our system. Please make sure you are registered as a student.';
            }
        }
    }
    
    // Handle signup
    if (isset($_POST['signup'])) {
        $student_number = trim($_POST['student_number']);
        $fullname = trim($_POST['fullname']);
        $email = trim($_POST['email']);
        $year_level = 2; // Automatically set to 2nd year
        $semester = trim($_POST['semester']);
        $section = trim($_POST['section']);
        $course = 'BS Information Technology'; // Automatically set to BSIT
        
        // Validate email domain
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@plpasig.edu.ph')) {
            $error = 'Please use your @plpasig.edu.ph email address.';
            $showSignupModal = true;
        } else {
            // Check if email already exists using Supabase
            if (studentExists($email, $student_number)) {
                $error = 'Email or student number already exists. Please use login instead.';
                $showSignupModal = true;
            } else {
                // Insert new student using Supabase
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
                
                if ($result) {
                    $success = 'Registration successful! You can now login with your credentials.';
                } else {
                    $error = 'Registration failed. Please try again.';
                    $showSignupModal = true;
                }
            }
        }
    }
}

// Check if we're processing OTP verification
if (isset($_POST['otp'])) {
    $otp = trim($_POST['otp']);
    $email = $_SESSION['verify_email'];
    
    if (verifyOTP($email, $otp)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_type'] = 'student';
        $_SESSION['user_id'] = $_SESSION['user_id']; // Already set during login
        
        // Redirect to student dashboard
        header('Location: student-dashboard.php');
        exit;
    } else {
        $otpError = 'Invalid OTP code or OTP has expired. Please check and try again.';
        $showOTPModal = true;
    }
}
?>