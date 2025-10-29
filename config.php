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
    
    if ($error) {
        error_log("Supabase CURL Error: $error");
        return false;
    }
    
    if ($httpCode >= 400) {
        error_log("Supabase HTTP Error: $httpCode, Response: $response");
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
        return false;
    }

    // Simple email sending that works on Render
    $subject = "PLP SmartGrade - OTP Verification Code";
    
    $message = "
PLP SmartGrade - OTP Verification

Hello $fullname,

Your One-Time Password (OTP) is: $otp

This OTP will expire in 10 minutes.

If you didn't request this OTP, please ignore this email.

--
Pamantasan ng Lungsod ng Pasig
    ";
    
    $headers = "From: PLP SmartGrade <noreply@plpsmartgrade.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($email, $subject, $message, $headers);
}

function sendOTPEmailSimple($email, $otp, $fullname, $userType) {
    $subject = 'PLP SmartGrade - OTP Verification Code';
    
    $message = "
PLP SmartGrade - OTP Verification

Hello $fullname,

You are attempting to login as a $userType.

Your One-Time Password (OTP) is: $otp

This OTP will expire in 10 minutes.

If you didn't request this OTP, please ignore this email.

--
Pamantasan ng Lungsod ng Pasig
© " . date('Y') . " PLP SmartGrade. All rights reserved.
    ";
    
    $headers = "From: PLP SmartGrade <noreply@plpsmartgrade.com>\r\n";
    $headers .= "Reply-To: noreply@plpsmartgrade.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    if (mail($email, $subject, $message, $headers)) {
        error_log("OTP email sent successfully via mail() to: $email");
        return true;
    } else {
        error_log("Failed to send OTP email via mail() to: $email");
        
        // Try PHPMailer as backup
        return sendOTPWithPHPMailer($email, $otp, $fullname, $userType);
    }
}

function sendOTPWithPHPMailer($email, $otp, $fullname, $userType) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings - SIMPLIFIED for Render
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marygracevalerio177@gmail.com';
        $mail->Password   = 'swjx bwoj taxq tjdv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Disable strict SSL verification for Render
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Timeout settings
        $mail->Timeout = 15;

        // Recipients
        $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
        $mail->addAddress($email);

        // Simple plain text content
        $mail->isHTML(false);
        $mail->Subject = 'PLP SmartGrade - OTP Verification Code';
        
        $mail->Body = "PLP SmartGrade OTP Verification\n\nHello $fullname,\n\nYour OTP code is: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you didn't request this OTP, please ignore this email.";

        if ($mail->send()) {
            error_log("OTP email sent successfully via PHPMailer to: $email");
            return true;
        }
        
        error_log("PHPMailer send() returned false for: $email");
        return false;
        
    } catch (Exception $e) {
        error_log("PHPMailer Exception for $email: " . $e->getMessage());
        return false;
    }
}

function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
}

// OTP verification function for Supabase
function verifyOTP($email, $otp) {
    // Get all OTP records for this email
    $otpRecords = supabaseFetch('otp_verification', ['email' => $email]);
    
    if (!$otpRecords || count($otpRecords) === 0) {
        error_log("No OTP records found for email: $email");
        return false;
    }
    
    // Find the most recent unused OTP that matches
    $validOtp = null;
    foreach ($otpRecords as $record) {
        if (!$record['is_used'] && $record['otp_code'] === $otp) {
            $validOtp = $record;
            break;
        }
    }
    
    if (!$validOtp) {
        error_log("No valid unused OTP found for email: $email");
        return false;
    }
    
    // Check if OTP is expired
    if (strtotime($validOtp['expires_at']) < time()) {
        error_log("OTP expired for email: $email");
        return false;
    }
    
    // Mark OTP as used
    $updateResult = supabaseUpdate('otp_verification', 
        ['is_used' => true], 
        ['id' => $validOtp['id']]
    );
    
    if ($updateResult !== false) {
        error_log("OTP verified successfully for email: $email");
        return true;
    }
    
    error_log("Failed to mark OTP as used for email: $email");
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

// Validate PLP email
function isValidPLPEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) && str_ends_with($email, '@plpasig.edu.ph');
}
?>