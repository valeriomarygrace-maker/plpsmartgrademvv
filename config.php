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
        'Content-Type: ' . ($method === 'GET' ? 'application/json' : 'application/json'),
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
    curl_close($ch);
    
    if ($httpCode >= 400) {
        error_log("HTTP Error $httpCode for table: $table");
        return false;
    }
    
    return json_decode($response, true);
}

function supabaseInsert($table, $data) {
    return supabaseFetch($table, [], 'POST', $data);
}

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
 * Send OTP via Supabase Edge Function
 */
function sendOTP($email, $otp) {
    error_log("🔐 Generating OTP for: $email - Code: $otp");
    
    try {
        $student = getStudentByEmail($email);
        if (!$student) {
            error_log("❌ Student not found for email: $email");
            return false;
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
            error_log("❌ FAILED to store OTP in Supabase");
            return false;
        }
        
        // For testing - display OTP on screen instead of email
        $_SESSION['debug_otp'] = $otp;
        error_log("✅ OTP stored successfully: $otp");
        
        return true; // Always return true for testing
        
    } catch (Exception $e) {
        error_log("❌ OTP sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send OTP using Supabase Edge Function
 */
function sendOTPViaEdgeFunction($email, $fullname, $otp) {
    global $supabase_url, $supabase_key;
    
    $function_url = $supabase_url . '/functions/v1/send-otp';
    
    $data = [
        'email' => $email,
        'otp' => $otp,
        'fullname' => $fullname
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $function_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $supabase_key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        if ($result['success']) {
            error_log("✅ OTP email sent successfully via Edge Function");
            return true;
        }
    }
    
    error_log("❌ Edge Function failed - HTTP $httpCode: $response");
    return false;
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

function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
?>