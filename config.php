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
 * Improved Supabase API Helper Function
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
        error_log("HTTP Error $httpCode for table: $table, URL: $url");
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
        'Content-Type: application/json'
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
 * OTP Functions
 */
function createOTP($email, $otp_code, $expires_minutes = 10) {
    $expires_at = date('Y-m-d H:i:s', time() + ($expires_minutes * 60));
    
    $otp_data = [
        'email' => $email,
        'otp_code' => $otp_code,
        'expires_at' => $expires_at,
        'is_used' => false
    ];
    
    return supabaseInsert('otp_verification', $otp_data);
}

function verifyOTP($email, $otp_code) {
    $otp_records = supabaseFetch('otp_verification', [
        'email' => $email,
        'otp_code' => $otp_code,
        'is_used' => false
    ]);
    
    if (!$otp_records || count($otp_records) === 0) {
        return false;
    }
    
    $otp_record = $otp_records[0];
    
    // Check if OTP is expired
    if (strtotime($otp_record['expires_at']) < time()) {
        return false;
    }
    
    // Mark OTP as used
    supabaseUpdate('otp_verification', ['is_used' => true], ['id' => $otp_record['id']]);
    
    return true;
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

function getGPA($grade) {
    if ($grade >= 89) return 1.00;
    if ($grade >= 82) return 2.00;
    if ($grade >= 79) return 2.75;
    return 3.00;
}

function getRiskLevel($gpa) {
    if ($gpa == 1.00) return 'low';
    if ($gpa == 2.50 || $gpa == 2.75) return 'medium';
    return 'high';
}


if (isset($_SESSION['created']) && (time() - $_SESSION['created'] > 28800)) {
    session_destroy();
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header('Location: login.php');
        exit;
    }
}

if (rand(1, 100) === 1) { 
    cleanExpiredOTPs();
}

function cleanExpiredOTPs() {
    $current_time = date('Y-m-d H:i:s');
}

/**
 * Calculate performance for archived subject from scores
 */
function calculateArchivedSubjectPerformance($archived_subject_id) {
    try {
        // Get all categories for this archived subject
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        if (empty($categories)) {
            return null;
        }
        
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
        $hasScores = false;
        
        // Calculate class standing from categories
        foreach ($categories as $category) {
            $scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id'], 'score_type' => 'class_standing']);
            
            if (!empty($scores)) {
                $hasScores = true;
                $categoryTotal = 0;
                $categoryMax = 0;
                
                foreach ($scores as $score) {
                    $categoryTotal += $score['score_value'];
                    $categoryMax += $score['max_score'];
                }
                
                if ($categoryMax > 0) {
                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                    $weightedScore = ($categoryPercentage * $category['category_percentage']) / 100;
                    $totalClassStanding += $weightedScore;
                }
            }
        }
        
        // Ensure Class Standing doesn't exceed 60%
        if ($totalClassStanding > 60) {
            $totalClassStanding = 60;
        }
        
        // Get exam scores
        $midterm_exams = supabaseFetch('archived_subject_scores', ['score_type' => 'midterm_exam']);
        $final_exams = supabaseFetch('archived_subject_scores', ['score_type' => 'final_exam']);
        $exam_scores = array_merge($midterm_exams ?: [], $final_exams ?: []);
        
        foreach ($exam_scores as $exam) {
            if ($exam['max_score'] > 0) {
                $examPercentage = ($exam['score_value'] / $exam['max_score']) * 100;
                if ($exam['score_type'] === 'midterm_exam') {
                    $midtermScore = ($examPercentage * 20) / 100;
                } elseif ($exam['score_type'] === 'final_exam') {
                    $finalScore = ($examPercentage * 20) / 100;
                }
            }
        }
        
        if (!$hasScores && $midtermScore == 0 && $finalScore == 0) {
            return [
                'overall_grade' => 0,
                'gpa' => 0,
                'class_standing' => 0,
                'exams_score' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        // Calculate overall grade
        $overallGrade = $totalClassStanding + $midtermScore + $finalScore;
        if ($overallGrade > 100) {
            $overallGrade = 100;
        }
        
        // Calculate GPA and risk level
        $gpa = getGPA($overallGrade);
        $riskLevel = getRiskLevel($gpa);
        $riskDescription = ucfirst($riskLevel) . ' Risk';
        
        return [
            'overall_grade' => $overallGrade,
            'gpa' => $gpa,
            'class_standing' => $totalClassStanding,
            'exams_score' => $midtermScore + $finalScore,
            'risk_level' => $riskLevel,
            'risk_description' => $riskDescription,
            'has_scores' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating archived subject performance: " . $e->getMessage());
        return null;
    }
}
?>