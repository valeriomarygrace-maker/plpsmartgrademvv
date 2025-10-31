<?php
require_once 'vendor/autoload.php'; // Make sure to include PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

/**
 * Input Sanitization Function
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate 6-digit OTP
 */
function generateOTP() {
    return sprintf("%06d", random_int(1, 999999));
}

/**
 * Send OTP - FIXED VERSION THAT ACTUALLY SENDS EMAILS
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
        
        error_log("💾 Storing OTP: $otp");
        
        $result = supabaseInsert('otp_verification', $otpData);
        
        if (!$result) {
            error_log("❌ FAILED to store OTP in Supabase");
            return false;
        }
        
        // ✅ ACTUALLY SEND THE EMAIL
        if (sendOTPEmail($email, $otp, $fullname)) {
            error_log("✅ OTP email sent successfully to: $email");
            return true;
        } else {
            error_log("❌ FAILED to send OTP email to: $email");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ OTP sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send OTP Email using PHPMailer
 */
function sendOTPEmail($email, $otp, $fullname) {
    $mail = new PHPMailer(true);
    
    try {
        // Enable verbose debugging
        $mail->SMTPDebug = 0; // Set to 2 for debugging, 0 for production
        $mail->Debugoutput = 'error_log';
        
        // Server settings - Gmail SMTP
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'marygracevalerio177@gmail.com'; // Your Gmail
        $mail->Password = 'swjx bwoj taxq tjdv'; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('noreply@plpasig.edu.ph', 'PLP SmartGrade');
        $mail->addAddress($email, $fullname);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your PLP SmartGrade OTP Code';
        $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f5f5f5; padding: 20px; }
                    .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
                    .header { color: #006341; text-align: center; margin-bottom: 20px; }
                    .otp-code { font-size: 32px; font-weight: bold; color: #006341; text-align: center; margin: 20px 0; padding: 15px; background: #f0f9f5; border-radius: 8px; }
                    .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>PLP SmartGrade OTP Verification</h2>
                    </div>
                    
                    <p>Hello <strong>$fullname</strong>,</p>
                    
                    <p>Your One-Time Password (OTP) for login is:</p>
                    
                    <div class='otp-code'>$otp</div>
                    
                    <p>This OTP will expire in <strong>10 minutes</strong>.</p>
                    
                    <p>If you didn't request this OTP, please ignore this email.</p>
                    
                    <div class='footer'>
                        <p>PLP - SmartGrade System<br>
                        Pamantasan ng Lungsod ng Pasig</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Plain text version for non-HTML email clients
        $mail->AltBody = "PLP SmartGrade OTP Code: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you didn't request this OTP, please ignore this email.";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("❌ PHPMailer Error: {$mail->ErrorInfo}");
        
        // Fallback: Store OTP in session for manual retrieval
        $_SESSION['debug_otp'] = $otp;
        $_SESSION['debug_email'] = $email;
        error_log("✅ OTP stored in session for debugging: $otp");
        
        return false;
    }
}

/**
 * Verify OTP
 */
function verifyOTP($email, $otp) {
    try {
        // Validate OTP format
        if (!preg_match('/^\d{6}$/', $otp)) {
            error_log("❌ Invalid OTP format: $otp");
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
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("❌ OTP verification error: " . $e->getMessage());
        return false;
    }
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    $domain = substr(strrchr($email, "@"), 1);
    return strtolower($domain) === 'plpasig.edu.ph';
}

/**
 * Session Security Functions
 */
function regenerateSession() {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

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

function supabaseInsert($table, $data) {
    return supabaseFetch($table, [], 'POST', $data);
}

function supabaseUpdate($table, $data, $filters) {
    return supabaseFetch($table, $filters, 'PATCH', $data);
}

// Check session expiration on every request
if (isset($_SESSION['created']) && (time() - $_SESSION['created'] > 28800)) {
    session_destroy();
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header('Location: login.php');
        exit;
    }
}
?>