<?php
// Supabase Configuration
$supabase_url = getenv('SUPABASE_URL') ?: 'https://xwvrgpxcceivakzrwwji.supabase.co';
$supabase_key = getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inh3dnJncHhjY2VpdmFrenJ3d2ppIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MjQ0NzQsImV4cCI6MjA3NzMwMDQ3NH0.ovd8v3lqsYtJU78D4iM6CyAyvi6jK4FUbYUjydFi4FM';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function supabaseFetch($table, $filters = [], $method = 'GET', $data = null) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/$table";
    
    // Build query string from filters
    $queryParams = [];
    foreach ($filters as $key => $value) {
        $queryParams[] = "$key=eq.$value";
    }
    
    if (!empty($queryParams)) {
        $url .= "?" . implode('&', $queryParams);
    }
    
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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
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
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode >= 400) {
        error_log("Supabase API Error: $error, HTTP Code: $httpCode, Response: $response");
        return false;
    }
    
    $result = json_decode($response, true);
    return $result;
}

// Helper function to insert data
function supabaseInsert($table, $data) {
    return supabaseFetch($table, [], 'POST', $data);
}

// Helper function to update data
function supabaseUpdate($table, $data, $filters) {
    return supabaseFetch($table, $filters, 'PATCH', $data);
}

function sendOTP($email, $otp) {
    // Get student data
    $student = getStudentByEmail($email);
    if (!$student) {
        error_log("Student not found for email: $email");
        return false;
    }

    $userType = 'Student';
    $fullname = $student['fullname'];

    // Store OTP in Supabase
    $otpData = [
        'email' => $email,
        'otp_code' => $otp,
        'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
        'is_used' => false
    ];
    
    $result = supabaseInsert('otp_verification', $otpData);
    
    if (!$result) {
        error_log("Failed to store OTP in database for email: $email");
        return false;
    }

    // Send email
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marygracevalerio177@gmail.com';
        $mail->Password   = 'swjx bwoj taxq tjdv'; // Your Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 2; // Enable verbose debug output
        $mail->Debugoutput = 'error_log'; // Send debug output to error log

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
        error_log("OTP email sent successfully to: $email");
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
}

// OTP verification function for Supabase
function verifyOTP($email, $otp) {
    $currentTime = date('Y-m-d H:i:s');
    
    // Find valid OTP using Supabase
    $otpRecords = supabaseFetch('otp_verification', [
        'email' => $email, 
        'otp_code' => $otp
    ]);
    
    if ($otpRecords && count($otpRecords) > 0) {
        $otpRecord = $otpRecords[0];
        
        // Check if OTP is already used
        if ($otpRecord['is_used']) {
            error_log("OTP already used for email: $email");
            return false;
        }
        
        // Check if OTP is expired
        if (strtotime($otpRecord['expires_at']) < time()) {
            error_log("OTP expired for email: $email");
            return false;
        }
        
        // Mark OTP as used
        $otpId = $otpRecord['id'];
        $result = supabaseUpdate('otp_verification', ['is_used' => true], ['id' => $otpId]);
        
        if ($result !== false) {
            error_log("OTP verified successfully for email: $email");
            return true;
        }
    }
    
    error_log("OTP verification failed for email: $email");
    return false;
}

// Check if student exists
function studentExists($email) {
    $students = supabaseFetch('students', ['email' => $email]);
    return $students && count($students) > 0;
}

// Get student by email
function getStudentByEmail($email) {
    $students = supabaseFetch('students', ['email' => $email]);
    return $students && count($students) > 0 ? $students[0] : null;
}

// Get student by ID
function getStudentById($id) {
    $students = supabaseFetch('students', ['id' => $id]);
    return $students && count($students) > 0 ? $students[0] : null;
}
?>