<?php
// Supabase Configuration
$supabase_url = getenv('SUPABASE_URL') ?: 'https://xwvrgpxcceivakzrwwji.supabase.co';
$supabase_key = getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inh3dnJncHhjY2VpdmFrenJ3d2ppIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MjQ0NzQsImV4cCI6MjA3NzMwMDQ3NH0.ovd8v3lqsYtJU78D4iM6CyAyvi6jK4FUbYUjydFi4FM';
$supabase_secret = getenv('SUPABASE_SECRET') ?: 'your-service-role-key';

// For direct PostgreSQL connection (optional)
$db_host = getenv('DB_HOST') ?: 'db.your-project-ref.supabase.co';
$db_port = getenv('DB_PORT') ?: '5432';
$db_name = getenv('DB_NAME') ?: 'postgres';
$db_user = getenv('DB_USER') ?: 'postgres';
$db_pass = getenv('DB_PASS') ?: '';

// Choose connection method: 'rest' or 'pgsql'
$connection_method = getenv('DB_METHOD') ?: 'rest';

// Initialize database connection based on method
if ($connection_method === 'pgsql') {
    // PostgreSQL direct connection
    try {
        $pdo = new PDO("pgsql:host=$db_host;port=$db_port;dbname=$db_name", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        error_log("Supabase PostgreSQL connection failed: " . $e->getMessage());
        $pdo = null;
    }
} else {
    // REST API connection (no PDO needed)
    $pdo = null;
}

session_start();

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Supabase REST API helper functions
function supabaseFetch($table, $query = '', $method = 'GET', $data = null) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/$table";
    if ($query) $url .= "?$query";
    
    $ch = curl_init();
    $headers = [
        'apikey: ' . $supabase_key,
        'Authorization: Bearer ' . $supabase_key,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        error_log("Supabase API Error: HTTP $httpCode - $response");
        return null;
    }
    
    return json_decode($response, true);
}

// Helper function to insert data
function supabaseInsert($table, $data) {
    return supabaseFetch($table, '', 'POST', $data);
}

// Helper function to update data
function supabaseUpdate($table, $data, $condition) {
    return supabaseFetch($table, $condition, 'PATCH', $data);
}

// Helper function to delete data
function supabaseDelete($table, $condition) {
    return supabaseFetch($table, $condition, 'DELETE');
}

// Updated sendOTP function for Supabase
function sendOTP($email, $otp) {
    $userType = '';
    $fullname = '';
    
    // Check if student exists using Supabase
    $student = supabaseFetch('students', "email=eq.$email", 'GET');
    if ($student && count($student) > 0) {
        $userType = 'Student';
        $fullname = $student[0]['fullname'];
    }
    
    // Store OTP in Supabase
    $otpData = [
        'email' => $email,
        'otp_code' => $otp,
        'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        'is_used' => false
    ];
    
    $result = supabaseInsert('otp_verification', $otpData);
    
    if (!$result) {
        error_log("Failed to store OTP in Supabase");
        return false;
    }

    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marygracevalerio177@gmail.com';
        $mail->Password   = getenv('SMTP_PASSWORD') ?: 'swjx bwoj taxq tjdv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'PLP SmartGrade - OTP Verification';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #2c3e50;'>Email Verification</h2>
                <div style='background: #f8f9fa; padding: 20px; border-radius: 5px;'>
                    <p>Hello, <strong>$fullname</strong>! You are logging in as a <strong>$userType</strong>.</p>
                    <p>Your OTP code is:</p>
                    <div style='font-size: 32px; font-weight: bold; color: #e74c3c; text-align: center; letter-spacing: 5px; padding: 15px; background: #fff; border: 2px dashed #bdc3c7; border-radius: 5px; margin: 15px 0;'>
                        $otp
                    </div>
                    <p>This code will expire in 10 minutes.</p>
                    <p style='font-size: 12px; color: #7f8c8d;'>If you didn't request this OTP, please ignore this email.</p>
                </div>
                <div style='margin-top: 20px; padding-top: 20px; border-top: 1px solid #ecf0f1; font-size: 12px; color: #95a5a6;'>
                    © " . date('Y') . " Pamantasan ng Lungsod ng Pasig. All rights reserved.
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
}

// Student-related functions for Supabase
function getStudentByEmail($email) {
    return supabaseFetch('students', "email=eq.$email", 'GET');
}

function getStudentById($id) {
    return supabaseFetch('students', "id=eq.$id", 'GET');
}

function verifyOTP($email, $otp) {
    $currentTime = date('Y-m-d H:i:s');
    
    // Find valid OTP
    $otpRecords = supabaseFetch('otp_verification', "email=eq.$email&otp_code=eq.$otp&is_used=eq.false&expires_at=gt.$currentTime", 'GET');
    
    if ($otpRecords && count($otpRecords) > 0) {
        // Mark OTP as used
        $otpId = $otpRecords[0]['id'];
        supabaseUpdate('otp_verification', ['is_used' => true], "id=eq.$otpId");
        return true;
    }
    
    return false;
}

// Subject and grade management functions
function getStudentSubjects($studentId) {
    return supabaseFetch('student_subjects', "student_id=eq.$studentId", 'GET');
}

function getSubjectById($subjectId) {
    return supabaseFetch('subjects', "id=eq.$subjectId", 'GET');
}

function getStudentGrades($studentSubjectId) {
    return supabaseFetch('student_subject_scores', "student_subject_id=eq.$studentSubjectId", 'GET');
}

// Authentication check function
function checkAuth() {
    if (!isset($_SESSION['student_id']) && !isset($_SESSION['admin_logged_in'])) {
        header('Location: login.php');
        exit();
    }
}

// Admin authentication check
function checkAdminAuth() {
    if (!isset($_SESSION['admin_logged_in'])) {
        header('Location: admin_login.php');
        exit();
    }
}

// Error handling function
function handleError($message, $redirect = null) {
    error_log($message);
    if ($redirect) {
        header("Location: $redirect");
        exit();
    }
    return false;
}

// Success response function
function handleSuccess($message, $redirect = null) {
    if ($redirect) {
        $_SESSION['success_message'] = $message;
        header("Location: $redirect");
        exit();
    }
    return true;
}

// Initialize required tables (run once)
function initializeSupabaseTables() {
    // This function would contain SQL to create tables if they don't exist
    // Since you've already created tables in Supabase UI, this is optional
}

// CORS headers for API requests
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

?>