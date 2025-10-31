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
 * Input Sanitization Function
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Supabase Magic Link Authentication
 */
function sendMagicLink($email) {
    global $supabase_url, $supabase_key;
    
    $redirect_url = getBaseUrl() . '/auth-callback.php';
    
    $data = [
        'email' => $email,
        'options' => [
            'emailRedirectTo' => $redirect_url
        ]
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $supabase_url . '/auth/v1/magiclink',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $supabase_key,
            'apikey: ' . $supabase_key
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("🔐 Magic Link attempt for: $email - HTTP Code: $httpCode");
    
    return $httpCode === 200;
}

/**
 * Get base URL for redirects
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host;
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