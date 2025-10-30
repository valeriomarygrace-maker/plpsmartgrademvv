<?php
// Environment Configuration
$supabase_url = getenv('SUPABASE_URL') ?: 'https://xwvrgpxcceivakzrwwji.supabase.co';
$supabase_key = getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inh3dnJncHhjY2VpdmFrenJ3d2ppIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MjQ0NzQsImV4cCI6MjA3NzMwMDQ3NH0.ovd8v3lqsYtJU78D4iM6CyAyvi6jK4FUbYUjydFi4FM';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Include PHPMailer
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Supabase API Helper Function
 */
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
        error_log("❌ cURL Error: $error");
        return false;
    }
    
    if ($httpCode >= 400) {
        error_log("❌ HTTP Error $httpCode for table: $table");
        return false;
    }
    
    $result = json_decode($response, true);
    return $result;
}

/**
 * Insert data into Supabase
 */
function supabaseInsert($table, $data) {
    return supabaseFetch($table, [], 'POST', $data);
}

/**
 * Update data in Supabase
 */
function supabaseUpdate($table, $data, $filters) {
    return supabaseFetch($table, $filters, 'PATCH', $data);
}

/**
 * Generate 6-digit OTP
 */
function generateOTP() {
    return sprintf("%06d", random_int(1, 999999));
}

/**
 * Send OTP via Email
 */
function sendOTP($email, $otp) {
    error_log("🔐 Attempting to send OTP to: $email");
    
    try {
        $student = getStudentByEmail($email);
        if (!$student) {
            error_log("❌ Student not found for email: $email");
            return false;
        }

        $fullname = $student['fullname'];
        error_log("✅ Student found: $fullname");

        // Store OTP in Supabase
        $otpData = [
            'email' => $email,
            'otp_code' => $otp,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
            'is_used' => false
        ];
        
        error_log("💾 Storing OTP: " . substr($otp, 0, 3) . "***");
        
        $result = supabaseInsert('otp_verification', $otpData);
        
        if (!$result) {
            error_log("❌ FAILED to store OTP in Supabase");
            return false;
        }
        
        error_log("✅ OTP stored successfully");

        // Send email
        return sendOTPEmail($email, $fullname, $otp);
        
    } catch (Exception $e) {
        error_log("❌ OTP sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send OTP Email
 */
function sendOTPEmail($email, $fullname, $otp) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marygracevalerio177@gmail.com';
        $mail->Password   = getenv('SMTP_PASSWORD') ?: 'swjx bwoj taxq tjdv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPDebug  = 0; // Set to 0 in production
        $mail->Timeout    = 30;

        $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
        $mail->addAddress($email);
        $mail->addReplyTo('noreply@plpasig.edu.ph', 'PLP SmartGrade');

        $mail->isHTML(true);
        $mail->Subject = 'PLP SmartGrade - OTP Verification Code';
        $mail->Body    = createOTPEmailTemplate($fullname, $otp);
        $mail->AltBody = "Your PLP SmartGrade OTP code is: $otp. This code expires in 10 minutes.";

        if ($mail->send()) {
            error_log("✅ Email sent successfully to: $email");
            return true;
        } else {
            throw new Exception($mail->ErrorInfo);
        }
        
    } catch (Exception $e) {
        error_log("❌ Email sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Create OTP Email Template
 */
function createOTPEmailTemplate($fullname, $otp) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: #006341; color: white; padding: 20px; text-align: center; }
            .content { padding: 30px; }
            .otp-code { font-size: 32px; font-weight: bold; color: #006341; text-align: center; letter-spacing: 8px; margin: 20px 0; padding: 15px; background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 5px; }
            .footer { background: #f8f9fa; padding: 15px; text-align: center; color: #6c757d; font-size: 12px; }
            .warning { color: #dc3545; font-size: 14px; margin-top: 20px; padding: 10px; background: #fff5f5; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>PLP SmartGrade</h2>
                <p>One-Time Password Verification</p>
            </div>
            <div class='content'>
                <p>Hello <strong>$fullname</strong>,</p>
                <p>Your verification code for PLP SmartGrade is:</p>
                <div class='otp-code'>$otp</div>
                <p>This code will expire in <strong>10 minutes</strong>.</p>
                <div class='warning'>
                    <strong>Security Notice:</strong> Never share this code with anyone. PLP staff will never ask for your OTP.
                </div>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " Pamantasan ng Lungsod ng Pasig. All rights reserved.</p>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Verify OTP
 */
function verifyOTP($email, $otp) {
    try {
        // Validate OTP format
        if (!validateOTPFormat($otp)) {
            error_log("❌ Invalid OTP format: $otp");
            return false;
        }

        // Check rate limiting
        if (!checkOTPAttempts($email)) {
            error_log("🚫 Rate limit exceeded for: $email");
            return false;
        }

        // Get OTP records for this email
        $otpRecords = supabaseFetch('otp_verification', ['email' => $email]);
        
        if (!$otpRecords || count($otpRecords) === 0) {
            error_log("❌ No OTP records found for: $email");
            return false;
        }
        
        // Find the most recent valid OTP
        $validOtp = null;
        foreach ($otpRecords as $record) {
            if (!$record['is_used'] && $record['otp_code'] === $otp) {
                $validOtp = $record;
                break;
            }
        }
        
        if (!$validOtp) {
            error_log("❌ Invalid OTP or already used for: $email");
            return false;
        }
        
        // Check expiration
        if (strtotime($validOtp['expires_at']) < time()) {
            error_log("❌ OTP expired for: $email");
            return false;
        }
        
        // Mark OTP as used
        $updateResult = supabaseUpdate('otp_verification', 
            ['is_used' => true], 
            ['id' => $validOtp['id']]
        );
        
        if ($updateResult !== false) {
            error_log("✅ OTP verified successfully for: $email");
            logOTPEvent($email, 'OTP_VERIFIED', true);
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("❌ OTP verification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Rate Limiting for OTP attempts
 */
function checkOTPAttempts($email) {
    $maxAttempts = 5;
    $lockoutTime = 15 * 60; // 15 minutes
    
    if (!isset($_SESSION['otp_attempts'])) {
        $_SESSION['otp_attempts'] = [];
    }
    
    $currentTime = time();
    $userAttempts = $_SESSION['otp_attempts'][$email] ?? [];
    
    // Remove old attempts
    $userAttempts = array_filter($userAttempts, function($attemptTime) use ($currentTime, $lockoutTime) {
        return ($currentTime - $attemptTime) < $lockoutTime;
    });
    
    if (count($userAttempts) >= $maxAttempts) {
        error_log("🚫 Rate limit exceeded for: $email - " . count($userAttempts) . " attempts");
        return false;
    }
    
    $userAttempts[] = $currentTime;
    $_SESSION['otp_attempts'][$email] = $userAttempts;
    
    return true;
}

/**
 * Input Validation Functions
 */
function validateEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    $domain = substr(strrchr($email, "@"), 1);
    if (strtolower($domain) !== 'plpasig.edu.ph') {
        return false;
    }
    
    return true;
}

function validateOTPFormat($otp) {
    return preg_match('/^\d{6}$/', $otp);
}

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Student Functions
 */
function getStudentByEmail($email) {
    $students = supabaseFetch('students', ['email' => $email]);
    return $students && count($students) > 0 ? $students[0] : null;
}

function getStudentById($id) {
    $students = supabaseFetch('students', ['id' => $id]);
    return $students && count($students) > 0 ? $students[0] : null;
}

function studentExists($email) {
    $students = supabaseFetch('students', ['email' => $email]);
    return $students && count($students) > 0;
}

function isValidPLPEmail($email) {
    return validateEmail($email);
}

/**
 * Session Security Functions
 */
function regenerateSession() {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

function checkSessionExpiration() {
    if (isset($_SESSION['created']) && (time() - $_SESSION['created'] > 28800)) { // 8 hours
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

/**
 * Logging Function
 */
function logOTPEvent($email, $event, $success = true) {
    $logEntry = date('Y-m-d H:i:s') . " - " . 
                ($success ? "SUCCESS" : "FAILED") . 
                " - $event - $email" . PHP_EOL;
    
    file_put_contents('otp_logs.txt', $logEntry, FILE_APPEND | LOCK_EX);
}

// Check session expiration on every request
checkSessionExpiration();
?>