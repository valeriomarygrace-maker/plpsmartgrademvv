<?php
// Environment Configuration
$supabase_url = getenv('SUPABASE_URL') ?: 'https://xwvrgpxcceivakzrwwji.supabase.co';
$supabase_key = getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inh3dnJncHhjY2VpdmFrenJ3d2ppIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MjQ0NzQsImV4cCI6MjA3NzMwMDQ3NH0.ovd8v3lqsYtJU78D4iM6CyAyvi6jK4FUbYUjydFi4FM';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Simple Supabase API Helper Function
 */
function supabaseFetch($table, $filters = [], $method = 'GET', $data = null) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/$table";
    
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
        'Content-Type: ' . 'application/json',
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
    curl_close($ch);
    
    if ($httpCode >= 400) {
        return false;
    }
    
    $result = json_decode($response, true);
    
    if ($result === null && $httpCode === 200) {
        return true;
    }
    
    return $result;
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

function isValidPLPEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = substr(strrchr($email, "@"), 1);
    return strtolower($domain) === 'plpasig.edu.ph';
}

/**
 * Admin Functions
 */
function getAdminByEmail($email) {
    $admins = supabaseFetch('admins', ['email' => $email]);
    return $admins && count($admins) > 0 ? $admins[0] : null;
}

function requireAdminRole() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: login.php');
        exit;
    }
}

function requireStudentRole() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
        header('Location: login.php');
        exit;
    }
}

function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Additional Supabase Helper Functions
 */
function supabaseUpdate($table, $data, $filters = []) {
    return supabaseFetch($table, $filters, 'PATCH', $data);
}

function supabaseInsert($table, $data) {
    return supabaseFetch($table, [], 'POST', $data);
}

function supabaseFetchAll($table, $filters = []) {
    return supabaseFetch($table, $filters);
}

function getUnreadMessageCount($userId, $userType) {
    // Check if we already have the count in session
    if (isset($_SESSION['unread_message_count']) && $_SESSION['unread_message_count_time'] > time() - 30) {
        return $_SESSION['unread_message_count'];
    }
    
    if ($userType === 'student') {
        $filters = [
            'receiver_id' => $userId,
            'receiver_type' => 'student',
            'is_read' => 'false'
        ];
    } else {
        $filters = [
            'receiver_id' => $userId,
            'receiver_type' => 'admin', 
            'is_read' => 'false'
        ];
    }
    
    $messages = supabaseFetch('messages', $filters);
    $count = $messages ? count($messages) : 0;
    
    // Store in session for 30 seconds
    $_SESSION['unread_message_count'] = $count;
    $_SESSION['unread_message_count_time'] = time();
    
    return $count;
}

function refreshUnreadMessageCount($userId, $userType) {
    unset($_SESSION['unread_message_count']);
    unset($_SESSION['unread_message_count_time']);
    return getUnreadMessageCount($userId, $userType);
}

function getMessagesBetweenUsers($user1_id, $user1_type, $user2_id, $user2_type) {
    global $supabase_url, $supabase_key;
    
    // Get messages between two specific users
    $url = $supabase_url . "/rest/v1/messages?" . http_build_query([
        'or' => "(and(sender_id.eq.{$user1_id},sender_type.eq.{$user1_type},receiver_id.eq.{$user2_id},receiver_type.eq.{$user2_type}),and(sender_id.eq.{$user2_id},sender_type.eq.{$user2_type},receiver_id.eq.{$user1_id},receiver_type.eq.{$user1_type}))",
        'order' => 'created_at.asc'
    ]);
    
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
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true) ?: [];
    }
    
    return [];
}

function markMessagesAsRead($message_ids) {
    if (empty($message_ids)) return false;
    
    $data = ['is_read' => true];
    $filters = ['id' => 'in.(' . implode(',', $message_ids) . ')'];
    
    return supabaseUpdate('messages', $data, $filters);
}

function getConversationPartners($userId, $userType) {
    if ($userType === 'student') {
        // For students, get all admins they have conversations with
        $admins = supabaseFetchAll('admins');
        $partners = [];
        
        foreach ($admins as $admin) {
            // Get unread count for this specific admin
            $unread_filters = [
                'sender_id' => $admin['id'],
                'sender_type' => 'admin',
                'receiver_id' => $userId,
                'receiver_type' => 'student',
                'is_read' => 'false'
            ];
            
            $unread_messages = supabaseFetch('messages', $unread_filters);
            $unread_count = $unread_messages ? count($unread_messages) : 0;
            
            $partners[] = [
                'id' => $admin['id'],
                'name' => $admin['fullname'],
                'type' => 'admin',
                'unread_count' => $unread_count
            ];
        }
        return $partners;
    } else {
        // For admins, get all students they have conversations with
        $students = supabaseFetchAll('students');
        $partners = [];
        
        foreach ($students as $student) {
            // Get unread count for this specific student
            $unread_filters = [
                'sender_id' => $student['id'],
                'sender_type' => 'student',
                'receiver_id' => $userId,
                'receiver_type' => 'admin',
                'is_read' => 'false'
            ];
            
            $unread_messages = supabaseFetch('messages', $unread_filters);
            $unread_count = $unread_messages ? count($unread_messages) : 0;
            
            $partners[] = [
                'id' => $student['id'],
                'name' => $student['fullname'],
                'type' => 'student',
                'unread_count' => $unread_count
            ];
        }
        return $partners;
    }
}

/**
 * Log system activities
 */
function logSystemActivity($user_email, $user_type, $action, $description = '') {
    $log_data = [
        'user_email' => $user_email,
        'user_type' => $user_type,
        'action' => $action,
        'description' => $description,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ];
    
    return supabaseInsert('system_logs', $log_data);
}

/**
 * Get system logs with filters
 */
function getSystemLogs($filters = [], $limit = 50, $offset = 0) {
    global $supabase_url, $supabase_key;
    
    $query_params = [
        'order' => 'created_at.desc',
        'limit' => $limit,
        'offset' => $offset
    ];
    
    // Add filters if provided
    if (!empty($filters['user_type'])) {
        $query_params['user_type'] = 'eq.' . $filters['user_type'];
    }
    
    if (!empty($filters['action'])) {
        $query_params['action'] = 'eq.' . $filters['action'];
    }
    
    if (!empty($filters['date_from'])) {
        $query_params['created_at'] = 'gte.' . $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $query_params['created_at'] = 'lte.' . $filters['date_to'];
    }
    
    $url = $supabase_url . "/rest/v1/system_logs?" . http_build_query($query_params);
    
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
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true) ?: [];
    }
    
    return [];
}

/**
 * Count system logs for pagination
 */
function countSystemLogs($filters = []) {
    global $supabase_url, $supabase_key;
    
    $query_params = [
        'select' => 'count'
    ];
    
    // Add filters if provided
    if (!empty($filters['user_type'])) {
        $query_params['user_type'] = 'eq.' . $filters['user_type'];
    }
    
    if (!empty($filters['action'])) {
        $query_params['action'] = 'eq.' . $filters['action'];
    }
    
    if (!empty($filters['date_from'])) {
        $query_params['created_at'] = 'gte.' . $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $query_params['created_at'] = 'lte.' . $filters['date_to'];
    }
    
    $url = $supabase_url . "/rest/v1/system_logs?" . http_build_query($query_params);
    
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
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result[0]['count'] ?? 0;
    }
    
    return 0;
}
function getSemesterRiskData($student_id) {
    $data = [
        'first_semester' => [
            'high_risk_count' => 0,
            'low_risk_count' => 0,
            'total_subjects' => 0,
            'subjects' => []
        ],
        'second_semester' => [
            'high_risk_count' => 0,
            'low_risk_count' => 0,
            'total_subjects' => 0,
            'subjects' => []
        ],
        'total_archived_subjects' => 0,
        'total_high_risk' => 0,
        'total_low_risk' => 0,
        'total_active_subjects' => 0,
        'active_high_risk' => 0,
        'active_low_risk' => 0,
        'debug_info' => []
    ];
    
    try {
        // Get ALL archived subjects
        $archived_subjects = supabaseFetch('student_subjects', [
            'student_id' => $student_id,
            'archived' => 'true'
        ]);
        
        // Get ALL active subjects
        $active_subjects = supabaseFetch('student_subjects', [
            'student_id' => $student_id,
            'archived' => 'false',
            'deleted_at' => null
        ]);
        
        $data['debug_info']['total_archived_found'] = $archived_subjects ? count($archived_subjects) : 0;
        $data['debug_info']['total_active_found'] = $active_subjects ? count($active_subjects) : 0;
        
        // Process archived subjects (for pie chart)
        if ($archived_subjects && is_array($archived_subjects)) {
            foreach ($archived_subjects as $subject_record) {
                $subject_data = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
                
                if ($subject_data && count($subject_data) > 0) {
                    $subject_info = $subject_data[0];
                    
                    // Get performance data
                    $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                    
                    $is_high_risk = false;
                    $is_low_risk = false;
                    $final_grade = 0;
                    $risk_level = 'no-data';
                    
                    if ($performance_data && count($performance_data) > 0) {
                        $performance = $performance_data[0];
                        $final_grade = $performance['overall_grade'] ?? 0;
                        $risk_level = $performance['risk_level'] ?? 'no-data';
                        
                        // Determine risk level based on grade
                        if ($final_grade > 0) {
                            if ($final_grade >= 80) {
                                $is_low_risk = true;
                            } else {
                                $is_high_risk = true;
                            }
                        }
                    } else {
                        // If no performance data, try to calculate from scores
                        $calculated_grade = calculateSubjectGradeFromScores($subject_record['id']);
                        if ($calculated_grade > 0) {
                            $final_grade = $calculated_grade;
                            if ($calculated_grade >= 80) {
                                $is_low_risk = true;
                                $risk_level = 'low';
                            } else {
                                $is_high_risk = true;
                                $risk_level = 'high';
                            }
                        }
                    }
                    
                    // Add to archived counts
                    $data['total_archived_subjects']++;
                    if ($is_high_risk) {
                        $data['total_high_risk']++;
                    }
                    if ($is_low_risk) {
                        $data['total_low_risk']++;
                    }
                    
                    // Categorize by semester for archived subjects
                    $semester = strtolower($subject_info['semester']);
                    
                    if (strpos($semester, 'first') !== false || strpos($semester, '1') !== false) {
                        $data['first_semester']['total_subjects']++;
                        if ($is_high_risk) {
                            $data['first_semester']['high_risk_count']++;
                        }
                        if ($is_low_risk) {
                            $data['first_semester']['low_risk_count']++;
                        }
                    } elseif (strpos($semester, 'second') !== false || strpos($semester, '2') !== false) {
                        $data['second_semester']['total_subjects']++;
                        if ($is_high_risk) {
                            $data['second_semester']['high_risk_count']++;
                        }
                        if ($is_low_risk) {
                            $data['second_semester']['low_risk_count']++;
                        }
                    }
                    
                    // Add debug info for this subject
                    $data['debug_info']['archived_subjects'][] = [
                        'subject_code' => $subject_info['subject_code'],
                        'grade' => $final_grade,
                        'risk_level' => $risk_level,
                        'semester' => $subject_info['semester']
                    ];
                }
            }
        }
        
        // Process active subjects (for comparison)
        if ($active_subjects && is_array($active_subjects)) {
            foreach ($active_subjects as $subject_record) {
                $subject_data = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
                
                if ($subject_data && count($subject_data) > 0) {
                    $subject_info = $subject_data[0];
                    
                    // Get performance data
                    $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                    
                    $is_high_risk = false;
                    $is_low_risk = false;
                    
                    if ($performance_data && count($performance_data) > 0) {
                        $performance = $performance_data[0];
                        $final_grade = $performance['overall_grade'] ?? 0;
                        
                        // Determine risk level
                        if ($final_grade > 0) {
                            if ($final_grade >= 80) {
                                $is_low_risk = true;
                            } else {
                                $is_high_risk = true;
                            }
                        }
                    } else {
                        // If no performance data, try to calculate from scores
                        $calculated_grade = calculateSubjectGradeFromScores($subject_record['id']);
                        if ($calculated_grade > 0) {
                            if ($calculated_grade >= 80) {
                                $is_low_risk = true;
                            } else {
                                $is_high_risk = true;
                            }
                        }
                    }
                    
                    // Add to active counts
                    $data['total_active_subjects']++;
                    if ($is_high_risk) {
                        $data['active_high_risk']++;
                    }
                    if ($is_low_risk) {
                        $data['active_low_risk']++;
                    }
                    
                    // Add debug info for this subject
                    $data['debug_info']['active_subjects'][] = [
                        'subject_code' => $subject_info['subject_code'],
                        'grade' => $final_grade,
                        'risk_level' => $is_high_risk ? 'high' : ($is_low_risk ? 'low' : 'no-data')
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        $data['debug_info']['error'] = $e->getMessage();
        error_log("Error getting semester risk data: " . $e->getMessage());
    }
    
    return $data;
}

/**
 * Get risk overview data specifically for archived subjects
 */
function getArchivedSubjectsRiskOverview($student_id) {
    $data = [
        'high_risk' => 0,
        'low_risk' => 0,
        'no_data' => 0,
        'total_subjects' => 0,
        'subjects' => []
    ];
    
    try {
        // Get all archived subjects
        $archived_subjects = supabaseFetch('student_subjects', [
            'student_id' => $student_id,
            'archived' => 'true'
        ]);
        
        if ($archived_subjects && is_array($archived_subjects)) {
            $data['total_subjects'] = count($archived_subjects);
            
            foreach ($archived_subjects as $subject_record) {
                $subject_data = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
                
                if ($subject_data && count($subject_data) > 0) {
                    $subject_info = $subject_data[0];
                    
                    // Get performance data
                    $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                    
                    $final_grade = 0;
                    $risk_level = 'no-data';
                    
                    if ($performance_data && count($performance_data) > 0) {
                        $performance = $performance_data[0];
                        $final_grade = $performance['overall_grade'] ?? 0;
                        
                        // Determine risk level
                        if ($final_grade > 0) {
                            if ($final_grade >= 80) {
                                $risk_level = 'low';
                                $data['low_risk']++;
                            } else {
                                $risk_level = 'high';
                                $data['high_risk']++;
                            }
                        } else {
                            $data['no_data']++;
                        }
                    } else {
                        // If no performance data, try to calculate from scores
                        $calculated_grade = calculateSubjectGradeFromScores($subject_record['id']);
                        if ($calculated_grade > 0) {
                            if ($calculated_grade >= 80) {
                                $risk_level = 'low';
                                $data['low_risk']++;
                            } else {
                                $risk_level = 'high';
                                $data['high_risk']++;
                            }
                        } else {
                            $data['no_data']++;
                        }
                    }
                    
                    // Store subject details
                    $data['subjects'][] = [
                        'subject_code' => $subject_info['subject_code'],
                        'subject_name' => $subject_info['subject_name'],
                        'grade' => $final_grade,
                        'risk_level' => $risk_level,
                        'semester' => $subject_info['semester']
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error getting archived subjects risk overview: " . $e->getMessage());
    }
    
    return $data;
}
?>