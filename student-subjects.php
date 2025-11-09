<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Get student information
$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Fetch student data from Supabase
try {
    $student_data = supabaseFetch('students', ['id' => $userId, 'email' => $userEmail]);
    if (!$student_data || count($student_data) === 0) {
        $_SESSION['error_message'] = "Student account not found";
        header('Location: login.php');
        exit;
    }
    $student = $student_data[0];
} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: login.php');
    exit;
}

$success_message = '';
$error_message = '';

// Handle subject archiving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_subject'])) {
    $subject_id = $_POST['subject_id'];
    
    try {
        // Get the subject to archive
        $subject_to_archive = supabaseFetch('student_subjects', ['id' => $subject_id, 'student_id' => $student['id']]);
        
        if (!$subject_to_archive || count($subject_to_archive) === 0) {
            throw new Exception("Subject not found.");
        }
        
        $subject_to_archive = $subject_to_archive[0];
        
        // Check if already archived
        $already_archived = supabaseFetch('archived_subjects', [
            'student_id' => $student['id'], 
            'subject_id' => $subject_to_archive['subject_id']
        ]);
        
        if ($already_archived && count($already_archived) > 0) {
            throw new Exception("This subject is already archived.");
        }
        
        // Archive the subject
        $archive_data = [
            'student_id' => $subject_to_archive['student_id'],
            'subject_id' => $subject_to_archive['subject_id'],
            'professor_name' => $subject_to_archive['professor_name'],
            'archived_at' => date('Y-m-d H:i:s')
        ];
        
        $archived_subject = supabaseInsert('archived_subjects', $archive_data);
        
        if (!$archived_subject) {
            throw new Exception("Failed to archive subject.");
        }
        
        $archived_subject_id = $archived_subject['id'];
        
        // Get categories and scores to archive
        $categories = supabaseFetch('student_class_standing_categories', ['student_subject_id' => $subject_id]);
        
        $category_mapping = [];
        
        // Archive categories
        if ($categories && is_array($categories)) {
            foreach ($categories as $category) {
                $archived_category_data = [
                    'archived_subject_id' => $archived_subject_id,
                    'category_name' => $category['category_name'],
                    'category_percentage' => $category['category_percentage'],
                    'created_at' => $category['created_at']
                ];
                
                $archived_category = supabaseInsert('archived_class_standing_categories', $archived_category_data);
                
                if ($archived_category) {
                    $category_mapping[$category['id']] = $archived_category['id'];
                }
            }
        }
        
        // Archive scores
        if (!empty($category_mapping)) {
            foreach ($category_mapping as $old_category_id => $new_category_id) {
                $scores = supabaseFetch('student_subject_scores', ['category_id' => $old_category_id]);
                
                if ($scores && is_array($scores)) {
                    foreach ($scores as $score) {
                        $archived_score_data = [
                            'archived_category_id' => $new_category_id,
                            'score_type' => $score['score_type'],
                            'score_name' => $score['score_name'],
                            'score_value' => $score['score_value'],
                            'max_score' => $score['max_score'],
                            'score_date' => $score['score_date'],
                            'created_at' => $score['created_at']
                        ];
                        
                        supabaseInsert('archived_subject_scores', $archived_score_data);
                    }
                }
            }
        }

        // Archive exam scores (without category)
        $exam_scores = supabaseFetch('student_subject_scores', [
            'student_subject_id' => $subject_id,
            'category_id' => null
        ]);
        
        if ($exam_scores && is_array($exam_scores)) {
            // Create a special category for exam scores if it doesn't exist
            $exam_category = supabaseFetch('archived_class_standing_categories', [
                'archived_subject_id' => $archived_subject_id,
                'category_name' => 'Exam Scores'
            ]);
            
            if (!$exam_category || count($exam_category) === 0) {
                $exam_category_data = [
                    'archived_subject_id' => $archived_subject_id,
                    'category_name' => 'Exam Scores',
                    'category_percentage' => 40,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $exam_category = supabaseInsert('archived_class_standing_categories', $exam_category_data);
                $exam_category_id = $exam_category['id'];
            } else {
                $exam_category_id = $exam_category[0]['id'];
            }
            
            foreach ($exam_scores as $score) {
                $archived_score_data = [
                    'archived_category_id' => $exam_category_id,
                    'score_type' => $score['score_type'],
                    'score_name' => $score['score_name'],
                    'score_value' => $score['score_value'],
                    'max_score' => $score['max_score'],
                    'score_date' => $score['score_date'],
                    'created_at' => $score['created_at']
                ];
                
                supabaseInsert('archived_subject_scores', $archived_score_data);
            }
        }

        // Calculate and store performance data for archived subject
        $performance_data = calculateArchivedSubjectPerformance($archived_subject_id);
        if ($performance_data) {
            $performance_record = [
                'archived_subject_id' => $archived_subject_id,
                'overall_grade' => $performance_data['overall_grade'],
                'subject_grade' => $performance_data['subject_grade'],
                'class_standing' => $performance_data['class_standing'],
                'exams_score' => $performance_data['exams_score'],
                'risk_level' => $performance_data['risk_level'],
                'risk_description' => $performance_data['risk_description'],
                'calculated_at' => date('Y-m-d H:i:s')
            ];
            
            supabaseInsert('archived_subject_performance', $performance_record);
        }

        // Soft delete the active subject
        $update_result = supabaseUpdate('student_subjects', 
            ['deleted_at' => date('Y-m-d H:i:s')], 
            ['id' => $subject_id]
        );
        
        if ($update_result) {
            $success_message = 'Subject archived successfully with all scores and categories!';
            // Refresh the page
            header("Location: student-subjects.php");
            exit;
        } else {
            throw new Exception("Failed to delete active subject.");
        }
        
    } catch (Exception $e) {
        $error_message = 'Error archiving subject: ' . $e->getMessage();
        error_log("Archive error: " . $e->getMessage());
    }
}

// Handle new subject addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_code = trim($_POST['subject_code']);
    $professor_name = trim($_POST['professor_name']);
    
    if (empty($subject_code) || empty($professor_name)) {
        $error_message = 'Please fill all fields.';
    } else {
        try {
            // Check if subject exists in master list
            $subject_data = supabaseFetch('subjects', ['subject_code' => $subject_code]);
            if (!$subject_data || count($subject_data) === 0) {
                $error_message = 'Subject not found in course catalog. Please check the subject code.';
            } else {
                $subject_info = $subject_data[0];
                
                // Check if student already has this subject active
                $existing_subject = supabaseFetch('student_subjects', [
                    'student_id' => $student['id'],
                    'subject_id' => $subject_info['id'],
                    'deleted_at' => null
                ]);
                
                if ($existing_subject && count($existing_subject) > 0) {
                    $error_message = 'You already have this subject in your active list.';
                } else {
                    // Add subject to student's list
                    $insert_data = [
                        'student_id' => $student['id'],
                        'subject_id' => $subject_info['id'],
                        'professor_name' => $professor_name,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $result = supabaseInsert('student_subjects', $insert_data);
                    
                    if ($result) {
                        $success_message = 'Subject added successfully!';
                        
                        // Clear form
                        $_POST['subject_code'] = '';
                        $_POST['professor_name'] = '';
                        
                        // Refresh to show new subject
                        header("Location: student-subjects.php");
                        exit;
                    } else {
                        $error_message = 'Failed to add subject. Please try again.';
                    }
                }
            }
        } catch (Exception $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch active subjects with details
try {
    $student_subjects_data = supabaseFetch('student_subjects', [
        'student_id' => $student['id'], 
        'deleted_at' => null
    ]);
    
    $active_subjects = [];
    
    if ($student_subjects_data && is_array($student_subjects_data)) {
        foreach ($student_subjects_data as $subject_record) {
            $subject_data = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
            if ($subject_data && count($subject_data) > 0) {
                $subject_info = $subject_data[0];
                
                // Get performance data
                $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                $performance = $performance_data && count($performance_data) > 0 ? $performance_data[0] : null;
                
                $active_subjects[] = array_merge($subject_record, [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester'],
                    'overall_grade' => $performance['overall_grade'] ?? 0,
                    'subject_grade' => $performance['overall_grade'] ?? 0,
                    'risk_level' => $performance['risk_level'] ?? 'no-data',
                    'has_scores' => ($performance && $performance['overall_grade'] > 0)
                ]);
            }
        }
    }
    
    // Sort by subject code
    usort($active_subjects, function($a, $b) {
        return strcmp($a['subject_code'], $b['subject_code']);
    });
    
    $total_active = count($active_subjects);
    
} catch (Exception $e) {
    $active_subjects = [];
    $total_active = 0;
    error_log("Error fetching active subjects: " . $e->getMessage());
}

/**
 * Calculate performance for archived subject from scores
 */
function calculateArchivedSubjectPerformance($archived_subject_id) {
    try {
        // Get all categories for this archived subject
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        if (!$categories || !is_array($categories)) {
            return null;
        }
        
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
        $hasScores = false;
        
        // Calculate class standing from categories
        foreach ($categories as $category) {
            $scores = supabaseFetch('archived_subject_scores', [
                'archived_category_id' => $category['id'], 
                'score_type' => 'class_standing'
            ]);
            
            if ($scores && is_array($scores) && count($scores) > 0) {
                $hasScores = true;
                $categoryTotal = 0;
                $categoryMax = 0;
                
                foreach ($scores as $score) {
                    $categoryTotal += floatval($score['score_value']);
                    $categoryMax += floatval($score['max_score']);
                }
                
                if ($categoryMax > 0) {
                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                    $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                    $totalClassStanding += $weightedScore;
                }
            }
        }
        
        // Ensure Class Standing doesn't exceed 60%
        if ($totalClassStanding > 60) {
            $totalClassStanding = 60;
        }
        
        // Get exam scores from all categories for this archived subject
        foreach ($categories as $category) {
            $exam_scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id']]);
            
            if ($exam_scores && is_array($exam_scores)) {
                foreach ($exam_scores as $exam) {
                    if (floatval($exam['max_score']) > 0) {
                        $examPercentage = (floatval($exam['score_value']) / floatval($exam['max_score'])) * 100;
                        if ($exam['score_type'] === 'midterm_exam') {
                            $midtermScore = ($examPercentage * 20) / 100;
                            $hasScores = true;
                        } elseif ($exam['score_type'] === 'final_exam') {
                            $finalScore = ($examPercentage * 20) / 100;
                            $hasScores = true;
                        }
                    }
                }
            }
        }
        
        if (!$hasScores) {
            return [
                'overall_grade' => 0,
                'subject_grade' => 0,
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
        
        // Calculate risk level based on percentage grade
        $riskLevel = 'no-data';
        $riskDescription = 'No Data Inputted';

        if ($overallGrade >= 85) {
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
        } elseif ($overallGrade >= 80) {
            $riskLevel = 'medium';
            $riskDescription = 'Medium Risk';
        } elseif ($overallGrade >= 75) {
            $riskLevel = 'high';
            $riskDescription = 'High Risk';
        } else {
            $riskLevel = 'failed';
            $riskDescription = 'Failed';
        }
        
        return [
            'overall_grade' => $overallGrade,
            'subject_grade' => $overallGrade,
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --plp-green: #006341;
            --plp-green-light: #008856;
            --plp-green-lighter: #e0f2e9;
            --plp-green-pale: #f5fbf8;
            --plp-green-gradient: linear-gradient(135deg, #006341 0%, #008856 100%);
            --plp-gold: #FFD700;
            --plp-dark-green: #004d33;
            --plp-light-green: #f8fcf9;
            --plp-pale-green: #e8f5e9;
            --text-dark: #2d3748;
            --text-medium: #4a5568;
            --text-light: #718096;
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --box-shadow: 0 4px 12px rgba(0, 99, 65, 0.1);
            --box-shadow-lg: 0 8px 24px rgba(0, 99, 65, 0.15);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --danger: #dc3545;
            --warning: #ffc107;
            --success: #28a745;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--plp-green-pale);
            display: flex;
            min-height: 100vh;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .sidebar {
            width: 320px;
            background: white;
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            border-right: 1px solid rgba(0, 99, 65, 0.1);
        }

        .sidebar-header {
            text-align: center;
            border-bottom: 1px solid rgba(0, 99, 65, 0.1);
        }

        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo {
            width: 130px;
            height: 130px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 5px;
        }

        .portal-title {
            color: var(--plp-green);
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .student-email {
            color: var(--text-medium);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            word-break: break-all;
            padding: 0.5rem;
            border-radius: 6px;
            font-weight: 500;
        }

        .nav-menu {
            list-style: none;
            flex-grow: 0.30;
            margin-top: 0.7rem;
        }

        .nav-item {
            margin-bottom: 0.7rem;
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.50rem;
            color: var(--text-medium);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .nav-link:hover:not(.active) {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            transform: translateY(-3px);
        }

        .nav-link.active {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: var(--box-shadow);
        }

        .sidebar-footer {
            border-top: 3px solid rgba(0, 99, 65, 0.1);
        }

        .logout-btn {
            margin-top:1rem;
            background: transparent;
            color: var(--text-medium);
            padding: 0.75rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background: #fee2e2;
            color: #b91c1c;
            transform: translateX(5px);
        }

        .main-content {
            flex: 1;
            padding: 1rem 2.5rem; 
            background: var(--plp-green-pale);
            max-width: 100%;
            margin: 0 auto;
            width: 100%;
            overflow-y: auto;
        }

        .header {
            background: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
            background: var(--plp-green-gradient);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .subject-count {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            border-top: 4px solid var(--plp-green);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--plp-green-gradient);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--plp-green-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
        }

        .btn-secondary {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }

        .btn-secondary:hover {
            background: var(--plp-green-light);
            color: white;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 0.10rem;
        }

        .subject-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            transition: var(--transition);
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .subject-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--box-shadow-lg);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--plp-green);
            font-weight: 600;
        }

        .subject-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0.5rem 0;
            line-height: 1.3;
        }

        .credits {
            font-size: 0.85rem;
            color: var(--plp-green);
            font-weight: 1000;
            white-space: nowrap;
        }

        .subject-info {
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            color: var(--text-medium);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .info-item i {
            color: var(--plp-green);
            width: 14px;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .subject-performance {
            background: var(--plp-green-pale);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 3px solid var(--plp-green);
        }

        .performance-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.25rem;
        }

        .performance-label {
            font-size: 0.8rem;
            color: var(--text-medium);
        }

        .subject-actions {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            flex-wrap: wrap;
        }

        .btn-manage {
            background: var(--plp-green);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 500;
            flex: 1;
            min-width: 120px;
            justify-content: center;
            text-decoration: none;
        }

        .btn-manage:hover {
            background: var(--plp-green-light);
            transform: translateY(-2px);
        }

        .btn-archive {
            background: var(--plp-dark-green);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 500;
            flex: 1;
            min-width: 120px;
            justify-content: center;
        }

        .btn-archive:hover {
            background: var(--plp-green);
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-medium);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--plp-green-lighter);
        }

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #38a169;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #e53e3e;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .risk-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .risk-badge.low {
            background: #c6f6d5;
            color: #2f855a;
        }

        .risk-badge.medium {
            background: #fef5e7;
            color: #d69e2e;
        }

        .risk-badge.high {
            background: #fed7d7;
            color: #c53030;
        }

        .risk-badge.no-data {
            background: #e2e8f0;
            color: #718096;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            padding: 1rem;
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            max-width: 500px;
            width: 100%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-title {
            color: var(--plp-green);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            flex-wrap: wrap;
        }

        .modal-btn {
            font-size: 1rem;
            font-weight: 600;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            min-width: 120px;
            justify-content: center;
        }

        .modal-btn-cancel {
            background: #f1f5f9;
            color: var(--text-medium);
        }

        .modal-btn-cancel:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .modal-btn-confirm {
            background: var(--plp-green-gradient);
            color: white;
        }

        .modal-btn-confirm:hover {
            transform: translateY(-2px);
        }

        .modal-btn-danger {
            background: var(--danger);
            color: white;
        }

        .modal-btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            background: var(--plp-green);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            z-index: 3000;
            cursor: pointer;
            font-size: 1.2rem;
            transition: var(--transition);
        }

        .mobile-menu-toggle:hover {
            background: var(--plp-green-light);
            transform: scale(1.05);
        }

        /* Responsive Styles */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
                z-index: 2000;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                max-width: 100%;
                padding: 1rem;
                margin-top: 60px;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
                padding: 1rem;
            }
            
            .welcome {
                font-size: 1.3rem;
            }
            
            .subject-count {
                font-size: 0.8rem;
            }
            
            .subjects-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .subject-card {
                padding: 1.25rem;
            }
            
            .subject-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .subject-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-manage,
            .btn-archive {
                flex: none;
                width: 100%;
            }
            
            .modal-content {
                padding: 1.5rem;
                margin: 0.5rem;
            }
            
            .modal-title {
                font-size: 1.3rem;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-btn {
                width: 100%;
            }
            
            .card {
                padding: 1.25rem;
                margin-bottom: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }
            
            .header {
                padding: 0.75rem;
                margin-bottom: 1rem;
            }
            
            .welcome {
                font-size: 1.2rem;
            }
            
            .subject-count {
                font-size: 0.75rem;
                padding: 0.4rem 0.8rem;
            }
            
            .card {
                padding: 1rem;
                border-radius: var(--border-radius);
            }
            
            .subject-card {
                padding: 1rem;
            }
            
            .subject-name {
                font-size: 1rem;
            }
            
            .info-item {
                font-size: 0.8rem;
            }
            
            .modal-content {
                padding: 1rem;
            }
            
            .modal-title {
                font-size: 1.2rem;
                margin-bottom: 1rem;
            }
            
            .alert-success,
            .alert-error {
                padding: 0.75rem;
                font-size: 0.9rem;
            }
            
            .empty-state {
                padding: 2rem 1rem;
            }
            
            .empty-state i {
                font-size: 2.5rem;
            }
        }

        @media (max-width: 360px) {
            .main-content {
                padding: 0.5rem;
            }
            
            .header {
                padding: 0.5rem;
            }
            
            .welcome {
                font-size: 1.1rem;
            }
            
            .card {
                padding: 0.75rem;
            }
            
            .subject-card {
                padding: 0.75rem;
            }
            
            .btn-manage,
            .btn-archive {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            .modal-content {
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo">
                    <img src="plplogo.png" alt="PLP Logo">
                </div>
            </div>
            <div class="portal-title">PLPSMARTGRADE</div>
            <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="student-dashboard.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="student-profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    Profile
                </a>
            </li>
            <li class="nav-item">
                <a href="student-subjects.php" class="nav-link active">
                    <i class="fas fa-book"></i>
                    Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-archived-subject.php" class="nav-link">
                    <i class="fas fa-archive"></i>
                    Archived Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-semester-grades.php" class="nav-link">
                    <i class="fas fa-history"></i>
                    History Records
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <?php if ($success_message): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <div class="header">
            <div class="welcome">My Subjects</div>
            <div class="subject-count">
                <i class="fas fa-book"></i>
                <?php echo $total_active; ?> Active Subjects
            </div>
        </div>

        <!-- Add Subject Form -->
        <div class="card">
            <h3 style="color: var(--plp-green); font-size: 1.3rem; font-weight: 600; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-plus-circle"></i>
                Add New Subject
            </h3>
            <form method="POST">
                <div class="form-group">
                    <label for="subject_code" class="form-label">Subject Code</label>
                    <input type="text" id="subject_code" name="subject_code" class="form-input" 
                           value="<?php echo $_POST['subject_code'] ?? ''; ?>" 
                           placeholder="ex. IT 101, MATH 201" required>
                    <small style="color: var(--text-light); font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                        Enter the official subject code from your curriculum
                    </small>
                </div>
                
                <div class="form-group">
                    <label for="professor_name" class="form-label">Professor Name</label>
                    <input type="text" id="professor_name" name="professor_name" class="form-input" 
                           value="<?php echo $_POST['professor_name'] ?? ''; ?>" 
                           placeholder="ex. Dr. Juan Dela Cruz" required>
                </div>
                
                <button type="submit" name="add_subject" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add Subject
                </button>
            </form>
        </div>

        <!-- Active Subjects Grid -->
        <div class="card">
            <h3 style="color: var(--plp-green); font-size: 1.3rem; font-weight: 600; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <i class="fas fa-book-open"></i>
                Active Subjects
            </h3>
            
            <?php if (empty($active_subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <p>No active subjects found</p>
                    <small>Add subjects above to start tracking your grades and performance</small>
                </div>
            <?php else: ?>
                <div class="subjects-grid">
                    <?php foreach ($active_subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-header">
                                <div style="flex: 1;">
                                    <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                </div>
                                <div class="credits">
                                    <?php echo htmlspecialchars($subject['credits']); ?> CRDTS
                                </div>
                            </div>
                            
                            <div class="subject-info">
                                <div class="info-item">
                                    <i class="fas fa-user-tie"></i>
                                    <span><strong>Professor:</strong> <?php echo htmlspecialchars($subject['professor_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><strong>Semester:</strong> <?php echo htmlspecialchars($subject['semester']); ?></span>
                                </div>
                            </div>

                            <?php if ($subject['has_scores']): ?>
                                <div class="subject-performance">
                                    <div class="performance-value"><?php echo number_format($subject['overall_grade'], 1); ?>%</div>
                                    <div class="performance-label">Current Grade</div>
                                    <div class="risk-badge <?php echo $subject['risk_level']; ?>">
                                        <?php echo ucfirst($subject['risk_level']); ?> Risk
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="subject-performance" style="background: #f8fafc; border-left-color: #e2e8f0;">
                                    <div class="performance-value" style="color: var(--text-light);">--</div>
                                    <div class="performance-label">No scores added yet</div>
                                    <div class="risk-badge no-data">No Data</div>
                                </div>
                            <?php endif; ?>

                            <div class="subject-actions">
                                <a href="termevaluations.php?subject_id=<?php echo $subject['id']; ?>" class="btn-manage">
                                    <i class="fas fa-chart-line"></i>
                                    Manage
                                </a>
                                <form method="POST" style="display: inline; flex: 1;">
                                    <input type="hidden" name="subject_id" value="<?php echo $subject['id']; ?>">
                                    <button type="submit" name="archive_subject" class="btn-archive" 
                                            onclick="return confirm('Are you sure you want to archive this subject? This will move it to archived subjects with all its scores.')">
                                        <i class="fas fa-archive"></i>
                                        Archive
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div class="modal" id="archiveModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <i class="fas fa-archive"></i>
                Archive Subject
            </h3>
            
            <p style="color: var(--text-medium); margin-bottom: 1.5rem; line-height: 1.6;">
                Are you sure you want to archive this subject?<br>
                <strong>This action will:</strong>
            </p>
            
            <ul style="color: var(--text-medium); margin-bottom: 1.5rem; padding-left: 1.5rem;">
                <li>Move the subject to archived subjects</li>
                <li>Preserve all scores and categories</li>
                <li>Calculate final performance metrics</li>
                <li>Remove it from active subjects list</li>
            </ul>
            
            <p style="color: var(--text-light); font-size: 0.9rem;">
                You can always restore archived subjects later if needed.
            </p>
            
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelArchive">
                    Cancel
                </button>
                <button type="button" class="modal-btn modal-btn-danger" id="confirmArchive">
                    Yes, Archive
                </button>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <h3 style="color: var(--plp-green); font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem;">
                Confirm Logout
            </h3>
            <div style="color: var(--text-medium); margin-bottom: 2rem; line-height: 1.6;">
                Are you sure you want to logout? You'll need<br>
                to log in again to access your account.
            </div>
            <div style="display: flex; justify-content: center; gap: 1rem;">
                <button class="modal-btn modal-btn-cancel" id="cancelLogout" style="min-width: 120px;">
                    Cancel
                </button>
                <button class="modal-btn modal-btn-confirm" id="confirmLogout" style="min-width: 120px;">
                    Yes, Logout
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Mobile menu functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        });

        // Close sidebar when clicking on a link (mobile)
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // Close sidebar when clicking outside (mobile)
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && 
                sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Archive modal functionality
        let currentArchiveForm = null;

        // Add event listeners to all archive buttons
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            const archiveBtn = form.querySelector('button[name="archive_subject"]');
            if (archiveBtn) {
                archiveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    currentArchiveForm = form;
                    document.getElementById('archiveModal').classList.add('show');
                });
            }
        });

        // Handle archive confirmation
        document.getElementById('confirmArchive').addEventListener('click', () => {
            if (currentArchiveForm) {
                currentArchiveForm.submit();
            }
        });

        // Handle archive cancellation
        document.getElementById('cancelArchive').addEventListener('click', () => {
            document.getElementById('archiveModal').classList.remove('show');
            currentArchiveForm = null;
        });

        // Close archive modal when clicking outside
        document.getElementById('archiveModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('archiveModal')) {
                document.getElementById('archiveModal').classList.remove('show');
                currentArchiveForm = null;
            }
        });

        // Auto-hide success/error messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.1s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 100);
            });
        }, 5000);

        // Logout modal functionality
        const logoutBtn = document.querySelector('.logout-btn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogout = document.getElementById('confirmLogout');

        // Show modal when clicking logout button
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutModal.classList.add('show');
        });

        // Hide modal when clicking cancel
        cancelLogout.addEventListener('click', () => {
            logoutModal.classList.remove('show');
        });

        // Handle logout confirmation
        confirmLogout.addEventListener('click', () => {
            window.location.href = 'logout.php';
        });

        // Hide modal when clicking outside the modal content
        window.addEventListener('click', (e) => {
            if (e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });
    </script>
</body>
</html>