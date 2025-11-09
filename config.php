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
 * Enhanced Supabase API Helper Function with Better Error Handling
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
        CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes
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
    
    // Enhanced error logging
    if ($error) {
        error_log("cURL Error for table $table: $error");
        return false;
    }
    
    if ($httpCode >= 400) {
        error_log("HTTP Error $httpCode for table: $table, Method: $method, URL: $url");
        if ($data) {
            error_log("Request Data: " . json_encode($data));
        }
        if ($response) {
            error_log("Response: " . $response);
        }
        return false;
    }
    
    // For DELETE requests, return success if no error
    if ($method === 'DELETE' && ($httpCode === 200 || $httpCode === 204)) {
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
 * Insert data into Supabase table with enhanced error handling
 */
function supabaseInsert($table, $data) {
    // Log the insert operation for debugging
    error_log("Inserting into $table: " . json_encode($data));
    
    $result = supabaseFetch($table, [], 'POST', $data);
    
    if ($result && isset($result[0])) {
        error_log("Insert successful for $table, ID: " . ($result[0]['id'] ?? 'unknown'));
        return $result[0]; // Return the inserted record
    }
    
    if ($result === true) {
        error_log("Insert returned true for $table");
        return true;
    }
    
    error_log("Insert failed for $table");
    return false;
}

/**
 * Update data in Supabase table
 */
function supabaseUpdate($table, $data, $filters) {
    error_log("Updating $table with filters: " . json_encode($filters) . " data: " . json_encode($data));
    
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
    error_log("Deleting from $table with filters: " . json_encode($filters));
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
 * Archive Subject Helper Functions
 */
function canArchiveSubject($subject_id, $student_id) {
    try {
        // Check if subject exists and belongs to student
        $subject = supabaseFetch('student_subjects', ['id' => $subject_id, 'student_id' => $student_id]);
        if (!$subject || count($subject) === 0) {
            return ['success' => false, 'message' => 'Subject not found'];
        }
        
        // Check if already archived
        if (!empty($subject[0]['deleted_at'])) {
            return ['success' => false, 'message' => 'Subject already deleted'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error checking subject: ' . $e->getMessage()];
    }
}

/**
 * Enhanced Archive Subject Function
 */
function archiveSubjectWithData($subject_record_id, $student_id) {
    try {
        error_log("Starting archive process for subject ID: $subject_record_id, student ID: $student_id");
        
        // Check if subject can be archived
        $canArchive = canArchiveSubject($subject_record_id, $student_id);
        if (!$canArchive['success']) {
            throw new Exception($canArchive['message']);
        }
        
        // Get subject data
        $subject_data = supabaseFetch('student_subjects', ['id' => $subject_record_id, 'student_id' => $student_id]);
        if (!$subject_data || count($subject_data) === 0) {
            throw new Exception("Subject not found.");
        }
        
        $subject_to_archive = $subject_data[0];
        error_log("Subject to archive: " . json_encode($subject_to_archive));
        
        // Ensure schedule has a value
        $schedule = !empty($subject_to_archive['schedule']) ? $subject_to_archive['schedule'] : 'Not Set';
        
        // Archive the main subject record
        $archived_subject_data = [
            'student_id' => $subject_to_archive['student_id'],
            'subject_id' => $subject_to_archive['subject_id'],
            'professor_name' => $subject_to_archive['professor_name'],
            'schedule' => $schedule,
            'archived_at' => date('Y-m-d H:i:s')
        ];
        
        error_log("Creating archived subject with data: " . json_encode($archived_subject_data));
        $archived_subject = supabaseInsert('archived_subjects', $archived_subject_data);
        
        if (!$archived_subject) {
            throw new Exception("Failed to create archived subject record.");
        }
        
        $archived_subject_id = $archived_subject['id'];
        error_log("Archived subject created with ID: $archived_subject_id");
        
        // Archive categories and scores
        $categories = supabaseFetch('student_class_standing_categories', ['student_subject_id' => $subject_record_id]);
        error_log("Found " . (is_array($categories) ? count($categories) : 0) . " categories to archive");
        
        if ($categories && is_array($categories)) {
            foreach ($categories as $category) {
                $archived_category_data = [
                    'archived_subject_id' => $archived_subject_id,
                    'category_name' => $category['category_name'],
                    'category_percentage' => $category['category_percentage'],
                    'archived_at' => date('Y-m-d H:i:s')
                ];
                
                $archived_category = supabaseInsert('archived_class_standing_categories', $archived_category_data);
                
                if ($archived_category) {
                    $archived_category_id = $archived_category['id'];
                    error_log("Archived category ID: $archived_category_id");
                    
                    // Archive scores for this category
                    $scores = supabaseFetch('student_subject_scores', ['category_id' => $category['id']]);
                    error_log("Found " . (is_array($scores) ? count($scores) : 0) . " scores for category {$category['id']}");
                    
                    if ($scores && is_array($scores)) {
                        foreach ($scores as $score) {
                            $archived_score_data = [
                                'archived_category_id' => $archived_category_id,
                                'score_type' => $score['score_type'],
                                'score_name' => $score['score_name'],
                                'score_value' => $score['score_value'],
                                'max_score' => $score['max_score'],
                                'score_date' => $score['score_date'],
                                'archived_at' => date('Y-m-d H:i:s')
                            ];
                            
                            $score_result = supabaseInsert('archived_subject_scores', $archived_score_data);
                            
                            if (!$score_result) {
                                error_log("Failed to archive score: " . $score['score_name']);
                            }
                        }
                    }
                } else {
                    error_log("Failed to archive category: " . $category['category_name']);
                }
            }
        }
        
        // Archive exam scores (scores without category_id)
        $exam_scores = supabaseFetch('student_subject_scores', [
            'student_subject_id' => $subject_record_id, 
            'category_id' => NULL
        ]);

        error_log("Found " . (is_array($exam_scores) ? count($exam_scores) : 0) . " exam scores to archive");
        
        if ($exam_scores && is_array($exam_scores)) {
            // Create a special category for exam scores in archived table
            $exam_category_data = [
                'archived_subject_id' => $archived_subject_id,
                'category_name' => 'Exam Scores',
                'category_percentage' => 0,
                'archived_at' => date('Y-m-d H:i:s')
            ];
            
            $exam_category = supabaseInsert('archived_class_standing_categories', $exam_category_data);
            
            if ($exam_category) {
                $exam_category_id = $exam_category['id'];
                foreach ($exam_scores as $score) {
                    $archived_exam_score_data = [
                        'archived_category_id' => $exam_category_id,
                        'score_type' => $score['score_type'],
                        'score_name' => $score['score_name'],
                        'score_value' => $score['score_value'],
                        'max_score' => $score['max_score'],
                        'score_date' => $score['score_date'],
                        'archived_at' => date('Y-m-d H:i:s')
                    ];
                    
                    supabaseInsert('archived_subject_scores', $archived_exam_score_data);
                }
            }
        }
                
        // Archive performance data if it exists
        $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record_id]);
        error_log("Found " . (is_array($performance_data) ? count($performance_data) : 0) . " performance records");
        
        if ($performance_data && count($performance_data) > 0) {
            $performance = $performance_data[0];
            $archived_performance_data = [
                'archived_subject_id' => $archived_subject_id,
                'overall_grade' => $performance['overall_grade'] ?? 0,
                'gpa' => $performance['gpa'] ?? 0,
                'class_standing' => $performance['class_standing'] ?? 0,
                'exams_score' => $performance['exams_score'] ?? 0,
                'risk_level' => $performance['risk_level'] ?? 'no-data',
                'risk_description' => $performance['risk_description'] ?? 'No Data Inputted',
                'archived_at' => date('Y-m-d H:i:s')
            ];
            
            supabaseInsert('archived_subject_performance', $archived_performance_data);
        }
        
        // Delete from active tables (in reverse order to respect foreign keys)
        error_log("Deleting active records...");
        supabaseDelete('student_subject_scores', ['student_subject_id' => $subject_record_id]);
        supabaseDelete('student_class_standing_categories', ['student_subject_id' => $subject_record_id]);
        supabaseDelete('subject_performance', ['student_subject_id' => $subject_record_id]);
        
        // Finally delete the subject record
        $delete_result = supabaseDelete('student_subjects', ['id' => $subject_record_id]);
        
        if ($delete_result) {
            error_log("Subject archive completed successfully");
            return ['success' => true, 'message' => 'Subject archived successfully with all records preserved!'];
        } else {
            throw new Exception("Failed to delete subject from active records.");
        }
        
    } catch (Exception $e) {
        error_log("Archive error: " . $e->getMessage());
        error_log("Archive error trace: " . $e->getTraceAsString());
        return ['success' => false, 'message' => 'Database error during archiving: ' . $e->getMessage()];
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

/**
 * Session Management and Security
 */
if (isset($_SESSION['created']) && (time() - $_SESSION['created'] > 28800)) {
    session_destroy();
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        header('Location: login.php');
        exit;
    }
}

// Clean expired OTPs occasionally
if (rand(1, 100) === 1) { 
    cleanExpiredOTPs();
}

function cleanExpiredOTPs() {
    $current_time = date('Y-m-d H:i:s');
    // This would typically delete expired OTPs, but we'll keep it simple for now
}
?>