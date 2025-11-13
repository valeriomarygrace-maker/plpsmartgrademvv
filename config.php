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
 * Simple Supabase API Helper Function
 */
function supabaseFetch($table, $filters = [], $method = 'GET', $data = null) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/$table";
    
    // Build query string from filters
    $queryParams = [];
    foreach ($filters as $key => $value) {
        if ($value !== null) {
            $queryParams[] = "$key=eq.$value";
        }
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
        error_log("cURL Error: $error");
        return false;
    }
    
    if ($httpCode >= 400) {
        error_log("HTTP Error $httpCode for table: $table");
        return false;
    }
    
    // For DELETE requests, return success if no error
    if ($method === 'DELETE' && $httpCode === 200) {
        return true;
    }
    
    $result = json_decode($response, true);
    
    // Handle empty responses
    if ($result === null && $httpCode === 200) {
        return true;
    }
    
    return $result;
}

/**
 * Insert data into Supabase table
 */
function supabaseInsert($table, $data) {
    $result = supabaseFetch($table, [], 'POST', $data);
    
    if ($result && isset($result[0])) {
        return $result[0]; // Return the inserted record
    }
    
    return $result;
}

/**
 * Update data in Supabase table
 */
function supabaseUpdate($table, $data, $filters) {
    $result = supabaseFetch($table, $filters, 'PATCH', $data);
    
    if ($result && isset($result[0])) {
        return $result[0]; // Return the updated record
    }
    
    return $result;
}

/**
 * Delete data from Supabase table
 */
function supabaseDelete($table, $filters) {
    return supabaseFetch($table, $filters, 'DELETE');
}

/**
 * Fetch all records from a table with optional ordering
 */
function supabaseFetchAll($table, $orderBy = null) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/$table";
    
    if ($orderBy) {
        $url .= "?order=$orderBy";
    }
    
    $ch = curl_init();
    $headers = [
        'apikey: ' . $supabase_key,
        'Authorization: Bearer ' . $supabase_key,
        'Content-Type: ' . 'application/json'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("cURL Error: $error");
        return false;
    }
    
    if ($httpCode >= 400) {
        error_log("HTTP Error $httpCode for table: $table");
        return false;
    }
    
    $result = json_decode($response, true);
    return $result ?: [];
}

/**
 * Password Hashing and Verification
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hashedPassword) {
    return password_verify($password, $hashedPassword);
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
 * Subject Functions
 */
function getSubjectById($id) {
    $subjects = supabaseFetch('subjects', ['id' => $id]);
    return $subjects && count($subjects) > 0 ? $subjects[0] : null;
}

function getSubjectsBySemester($semester) {
    return supabaseFetch('subjects', ['semester' => $semester]);
}

function getStudentSubjects($student_id) {
    return supabaseFetch('student_subjects', ['student_id' => $student_id]);
}

/**
 * Admin Functions
 */
function getAdminByEmail($email) {
    $admins = supabaseFetch('admins', ['email' => $email]);
    return $admins && count($admins) > 0 ? $admins[0] : null;
}

function getAdminById($id) {
    $admins = supabaseFetch('admins', ['id' => $id]);
    return $admins && count($admins) > 0 ? $admins[0] : null;
}

function adminExists($email) {
    $admins = supabaseFetch('admins', ['email' => $email]);
    return $admins && count($admins) > 0;
}

function requireAdminRole() {
    if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
        header('Location: login.php');
        exit;
    }
}

/**
 * Log user login activity
 */
function logUserLogin($user_email, $user_type, $user_id) {
    $log_data = [
        'user_email' => $user_email,
        'user_type' => $user_type,
        'user_id' => $user_id,
        'action' => 'login',
        'description' => 'User logged into the system',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    return supabaseInsert('system_logs', $log_data);
}

/**
 * Log user logout activity
 */
function logUserLogout($user_email, $user_type, $user_id) {
    $log_data = [
        'user_email' => $user_email,
        'user_type' => $user_type,
        'user_id' => $user_id,
        'action' => 'logout',
        'description' => 'User logged out of the system',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    return supabaseInsert('system_logs', $log_data);
}

/**
 * Get system logs with filters
 */
function getSystemLogs($filters = [], $limit = 100, $offset = 0) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/system_logs";
    
    // Build query parameters
    $queryParams = [];
    if (!empty($filters)) {
        foreach ($filters as $key => $value) {
            if ($value !== null) {
                $queryParams[] = "$key=eq.$value";
            }
        }
    }
    
    // Add ordering and pagination
    $queryParams[] = "order=created_at.desc";
    $queryParams[] = "limit=$limit";
    $queryParams[] = "offset=$offset";
    
    if (!empty($queryParams)) {
        $url .= "?" . implode('&', $queryParams);
    }
    
    $ch = curl_init();
    $headers = [
        'apikey: ' . $supabase_key,
        'Authorization: Bearer ' . $supabase_key,
        'Content-Type: ' . 'application/json'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("cURL Error: $error");
        return false;
    }
    
    if ($httpCode >= 400) {
        error_log("HTTP Error $httpCode for system_logs");
        return false;
    }
    
    $result = json_decode($response, true);
    return $result ?: [];
}

/**
 * Get login/logout statistics
 */
function getLoginStatistics($days = 30) {
    $startDate = date('Y-m-d H:i:s', strtotime("-$days days"));
    
    // Get total logins
    $loginLogs = getSystemLogs(['action' => 'login'], 1000);
    $logoutLogs = getSystemLogs(['action' => 'logout'], 1000);
    
    $stats = [
        'total_logins' => is_array($loginLogs) ? count($loginLogs) : 0,
        'total_logouts' => is_array($logoutLogs) ? count($logoutLogs) : 0,
        'recent_logins' => 0,
        'recent_logouts' => 0,
        'unique_students' => 0,
        'unique_admins' => 0
    ];
    
    // Count recent activities
    if (is_array($loginLogs)) {
        foreach ($loginLogs as $log) {
            if (strtotime($log['created_at']) >= strtotime($startDate)) {
                $stats['recent_logins']++;
            }
        }
    }
    
    if (is_array($logoutLogs)) {
        foreach ($logoutLogs as $log) {
            if (strtotime($log['created_at']) >= strtotime($startDate)) {
                $stats['recent_logouts']++;
            }
        }
    }
    
    // Get unique users
    if (is_array($loginLogs)) {
        $students = [];
        $admins = [];
        
        foreach ($loginLogs as $log) {
            if ($log['user_type'] === 'student') {
                $students[$log['user_email']] = true;
            } elseif ($log['user_type'] === 'admin') {
                $admins[$log['user_email']] = true;
            }
        }
        
        $stats['unique_students'] = count($students);
        $stats['unique_admins'] = count($admins);
    }
    
    return $stats;
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

function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireStudentRole() {
    if (!isLoggedIn() || $_SESSION['user_type'] !== 'student') {
        header('Location: login.php');
        exit;
    }
}

/**
 * Utility Functions
 */
function formatDate($date_string, $format = 'M j, Y') {
    if (empty($date_string)) {
        return 'N/A';
    }
    return date($format, strtotime($date_string));
}

function calculateGrade($score, $max_score) {
    if ($max_score <= 0) return 0;
    return ($score / $max_score) * 100;
}

/**
 * Log user actions to system logs
 */
function logUserAction($user_email, $user_type, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $log_data = [
        'user_email' => $user_email,
        'user_type' => $user_type,
        'action' => $action,
        'description' => $description,
        'ip_address' => $ip_address,
        'user_agent' => $user_agent,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    return supabaseInsert('system_logs', $log_data);
}

/**
 * Messaging Functions
 */

/**
 * Get unread message count for user
 */
function getUnreadMessageCount($user_id, $user_type) {
    $filters = [
        'receiver_id' => $user_id,
        'receiver_type' => $user_type,
        'is_read' => false
    ];
    
    $messages = supabaseFetch('messages', $filters);
    return $messages ? count($messages) : 0;
}

/**



?>