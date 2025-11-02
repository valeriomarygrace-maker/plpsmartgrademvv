<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'student') {
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

// Handle subject restoration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_subject'])) {
    $archived_subject_id = $_POST['archived_subject_id'];
    
    try {
        // Get the archived subject details
        $archived_subject_data = supabaseFetch('archived_subjects', ['id' => $archived_subject_id, 'student_id' => $student['id']]);
        if (!$archived_subject_data || count($archived_subject_data) === 0) {
            throw new Exception("Archived subject not found.");
        }
        
        $archived_subject = $archived_subject_data[0];
        
        // Check if subject already exists in active subjects
        $existing_subject = supabaseFetch('student_subjects', [
            'student_id' => $student['id'], 
            'subject_id' => $archived_subject['subject_id'],
            'deleted_at' => null
        ]);
        
        if ($existing_subject && count($existing_subject) > 0) {
            throw new Exception("This subject is already in your active subjects.");
        }
        
        // Restore to student_subjects
        $restore_data = [
            'student_id' => $archived_subject['student_id'],
            'subject_id' => $archived_subject['subject_id'],
            'professor_name' => $archived_subject['professor_name'],
            'schedule' => $archived_subject['schedule'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $restored_subject = supabaseInsert('student_subjects', $restore_data);
        
        if (!$restored_subject) {
            throw new Exception("Failed to restore subject.");
        }
        
        $restored_subject_id = $restored_subject['id'];
        
        // Get archived categories
        $archived_categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        $category_mapping = [];
        
        // Restore categories
        if ($archived_categories && is_array($archived_categories)) {
            foreach ($archived_categories as $archived_category) {
                $category_data = [
                    'student_subject_id' => $restored_subject_id,
                    'category_name' => $archived_category['category_name'],
                    'category_percentage' => $archived_category['category_percentage'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $new_category = supabaseInsert('student_class_standing_categories', $category_data);
                
                if ($new_category) {
                    $category_mapping[$archived_category['id']] = $new_category['id'];
                }
            }
        }
        
        // Restore scores
        if (!empty($category_mapping)) {
            foreach ($category_mapping as $old_category_id => $new_category_id) {
                $archived_scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $old_category_id]);
                
                if ($archived_scores && is_array($archived_scores)) {
                    foreach ($archived_scores as $score) {
                        // For exam scores, category_id should be NULL
                        $category_id_for_score = (strpos($score['score_type'], 'exam') !== false) ? NULL : $new_category_id;
                        
                        $score_data = [
                            'student_subject_id' => $restored_subject_id,
                            'category_id' => $category_id_for_score,
                            'score_type' => $score['score_type'],
                            'score_name' => $score['score_name'],
                            'score_value' => $score['score_value'],
                            'max_score' => $score['max_score'],
                            'score_date' => $score['score_date'],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        supabaseInsert('student_subject_scores', $score_data);
                    }
                }
            }
        }
        
        // Also restore any exam scores that might be in separate categories
        $all_archived_scores = supabaseFetch('archived_subject_scores');
        if ($all_archived_scores && is_array($all_archived_scores)) {
            foreach ($all_archived_scores as $score) {
                // Check if this score belongs to our archived subject through its category
                $score_category = supabaseFetch('archived_class_standing_categories', ['id' => $score['archived_category_id']]);
                if ($score_category && count($score_category) > 0 && $score_category[0]['archived_subject_id'] == $archived_subject_id) {
                    // For exam scores, add directly without category
                    if (strpos($score['score_type'], 'exam') !== false) {
                        $exam_score_data = [
                            'student_subject_id' => $restored_subject_id,
                            'category_id' => NULL,
                            'score_type' => $score['score_type'],
                            'score_name' => $score['score_name'],
                            'score_value' => $score['score_value'],
                            'max_score' => $score['max_score'],
                            'score_date' => $score['score_date'],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        supabaseInsert('student_subject_scores', $exam_score_data);
                    }
                }
            }
        }
        
        // Delete from archived tables
        // Delete scores first
        if ($archived_categories && is_array($archived_categories)) {
            foreach ($archived_categories as $category) {
                supabaseDelete('archived_subject_scores', ['archived_category_id' => $category['id']]);
            }
        }
        
        // Delete categories
        supabaseDelete('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        // Delete performance data
        supabaseDelete('archived_subject_performance', ['archived_subject_id' => $archived_subject_id]);
        
        // Finally delete the archived subject
        $delete_result = supabaseDelete('archived_subjects', ['id' => $archived_subject_id]);
        
        if ($delete_result) {
            $success_message = 'Subject restored successfully with all records!';
            // Refresh the page
            header("Location: student-archived-subject.php");
            exit;
        } else {
            throw new Exception("Failed to delete archived subject.");
        }
        
    } catch (Exception $e) {
        $error_message = 'Error restoring subject: ' . $e->getMessage();
        error_log("Restore error: " . $e->getMessage());
    }
}

// Fetch archived subjects with calculated performance data
try {
    // Get all archived subjects for this student
    $archived_subjects_data = supabaseFetch('archived_subjects', ['student_id' => $student['id']]);
    
    $archived_subjects = [];
    
    if ($archived_subjects_data && is_array($archived_subjects_data)) {
        foreach ($archived_subjects_data as $archived_subject) {
            // Get subject details
            $subject_data = supabaseFetch('subjects', ['id' => $archived_subject['subject_id']]);
            $subject_info = $subject_data && count($subject_data) > 0 ? $subject_data[0] : null;
            
            if ($subject_info) {
                // Get performance data
                $performance_data = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject['id']]);
                $performance = $performance_data && count($performance_data) > 0 ? $performance_data[0] : null;
                
                // If no performance data exists, calculate it from scores
                if (!$performance) {
                    $calculated_performance = calculateArchivedSubjectPerformance($archived_subject['id']);
                    if ($calculated_performance) {
                        $performance = $calculated_performance;
                    } else {
                        $performance = [
                            'overall_grade' => 0,
                            'gpa' => 0,
                            'class_standing' => 0,
                            'exams_score' => 0,
                            'risk_level' => 'no-data',
                            'risk_description' => 'No Data Inputted',
                            'has_scores' => false
                        ];
                    }
                } else {
                    $performance['has_scores'] = ($performance['overall_grade'] > 0);
                }
                
                // Combine all data
                $archived_subjects[] = array_merge($archived_subject, [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester'],
                    'overall_grade' => $performance['overall_grade'] ?? 0,
                    'gpa' => $performance['gpa'] ?? 0,
                    'class_standing' => $performance['class_standing'] ?? 0,
                    'exams_score' => $performance['exams_score'] ?? 0,
                    'risk_level' => $performance['risk_level'] ?? 'no-data',
                    'risk_description' => $performance['risk_description'] ?? 'No Data Inputted',
                    'has_scores' => $performance['has_scores'] ?? false
                ]);
            }
        }
    }
    
    // Sort by archived date descending
    usort($archived_subjects, function($a, $b) {
        return strtotime($b['archived_at']) - strtotime($a['archived_at']);
    });
    
    $total_archived = count($archived_subjects);
    
} catch (Exception $e) {
    $archived_subjects = [];
    $total_archived = 0;
    error_log("Error fetching archived subjects: " . $e->getMessage());
}

/**
 * Calculate performance for archived subject from scores (Supabase version)
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
        
        // Calculate GPA and risk level - using the same logic as subject-management.php
        $gpa = 0;
        $riskLevel = 'no-data';
        $riskDescription = 'No Data Inputted';
        
        // GPA calculation (same as subject-management.php)
        if ($overallGrade >= 89) {
            $gpa = 1.00; // Low Risk
        } elseif ($overallGrade >= 82) {
            $gpa = 2.00; // Medium Risk  
        } elseif ($overallGrade >= 79) {
            $gpa = 2.75; // Medium Risk
        } else {
            $gpa = 3.00; // High Risk
        }

        // Calculate risk level based on GPA
        if ($gpa == 1.00) {
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
        } elseif ($gpa == 2.00 || $gpa == 2.75) {
            $riskLevel = 'medium';
            $riskDescription = 'Medium Risk';
        } elseif ($gpa == 3.00) {
            $riskLevel = 'high';
            $riskDescription = 'High Risk';
        }
        
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Subjects - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Your existing CSS styles remain exactly the same */
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
            overflow: hidden;
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
            overflow-y: auto;
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
            height: 100vh;
        }

        .header {
            background: white;
            padding: 0.6rem 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem; 
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
            background: var(--plp-green-gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 99, 65, 0.2);
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

        .subject-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-weight: 600;
            background: var(--plp-green);
            color: white;
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
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

        .subject-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
        }

        .btn-restore {
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
        }

        .btn-restore:hover {
            background: var(--plp-green-light);
            transform: translateY(-2px);
        }

        .btn-view {
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
        }

        .btn-view:hover {
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
            z-index: 1000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            padding: 2.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            max-width: 700px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-title {
            color: var(--plp-green);
            font-size: 1.50rem;
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
        }

        .modal-btn-cancel {
            background: #f1f5f9;
            color: var(--text-medium);
        }

        .modal-btn-cancel:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .performance-overview {
            margin-bottom: 1.5rem;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            text-align: center;
            padding: 1rem;
            background: var(--plp-green-pale);
            border-radius: var(--border-radius);
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--text-light);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .main-content {
                padding: 1.5rem;
                height: auto;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .subjects-grid {
                grid-template-columns: 1fr;
            }
            
            .subject-actions {
                flex-direction: column;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 1.5rem;
                margin: 1rem;
            }
        }
    </style>
</head>
<body>
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
                <a href="student-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-archived-subject.php" class="nav-link active">
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
            <li class="nav-item">
                <a href="student-reminder.php" class="nav-link">
                    <i class="fas fa-bell"></i>
                    Reminder
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
            <div class="welcome">Archived Subjects</div>
            <div class="subject-count">
                <i class="fas fa-layer-group"></i>
                <?php echo $total_archived; ?> Archived Subjects
            </div>
        </div>

        <div class="card">
            <?php if (empty($archived_subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived subjects found</p>
                    <small>Subjects will appear here once you archive them from your active subjects list</small>
                    <br>
                    <a href="student-subjects.php" class="btn-restore" style="margin-top: 1rem; border-radius: 10px; text-decoration: none;">
                        <i class="fas fa-book"></i> Go to Active Subjects
                    </a>
                </div>
            <?php else: ?>
                <div class="subjects-grid">
                    <?php foreach ($archived_subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-status">Archived</div>
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
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><strong>Schedule:</strong> <?php echo htmlspecialchars($subject['schedule']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><strong>Semester:</strong> <?php echo htmlspecialchars($subject['semester']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-chart-line"></i>
                                    <span><strong>Final Grade:</strong> 
                                        <?php if ($subject['has_scores']): ?>
                                            <?php echo number_format($subject['overall_grade'], 1); ?>%
                                            <span class="risk-badge <?php echo $subject['risk_level']; ?>">
                                                <?php echo ucfirst($subject['risk_level']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-light);">No Data</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span><strong>Archived:</strong> <?php echo date('M j, Y g:i A', strtotime($subject['archived_at'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="subject-actions">
                                <form action="student-archived-subject.php" method="POST" style="display: inline;">
                                    <input type="hidden" name="archived_subject_id" value="<?php echo $subject['id']; ?>">
                                    <button type="submit" name="restore_subject" class="btn-restore" onclick="return confirm('Are you sure you want to restore this subject?')">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                <button type="button" class="btn-view" onclick="openViewModal(
                                    '<?php echo htmlspecialchars($subject['subject_code']); ?>',
                                    '<?php echo htmlspecialchars($subject['subject_name']); ?>',
                                    '<?php echo htmlspecialchars($subject['credits']); ?>',
                                    '<?php echo htmlspecialchars($subject['professor_name']); ?>',
                                    '<?php echo htmlspecialchars($subject['schedule']); ?>',
                                    '<?php echo htmlspecialchars($subject['semester']); ?>',
                                    '<?php echo date('F j, Y g:i A', strtotime($subject['archived_at'])); ?>',
                                    <?php echo $subject['overall_grade'] ?? 0; ?>,
                                    <?php echo $subject['gpa'] ?? 0; ?>,
                                    <?php echo $subject['class_standing'] ?? 0; ?>,
                                    <?php echo $subject['exams_score'] ?? 0; ?>,
                                    '<?php echo $subject['risk_level'] ?? 'no-data'; ?>',
                                    '<?php echo addslashes($subject['risk_description'] ?? 'No Data Inputted'); ?>',
                                    <?php echo $subject['has_scores'] ? 'true' : 'false'; ?>
                                )">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <i class="fas fa-info-circle"></i>
                Archived Subject Details
            </h3>
            
            <div style="flex: 1; overflow: hidden; display: flex; flex-direction: column;">
                <!-- Performance Overview -->
                <div class="performance-overview">
                    <h4 style="color: var(--plp-green); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-line"></i>
                        Final Performance Summary
                    </h4>
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Overall Grade</div>
                            <div class="detail-value" id="view_overall_grade" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="risk-badge no-data" id="view_risk_badge" style="display: none; padding: 0.4rem 1rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">No Data</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">GPA</div>
                            <div class="detail-value" id="view_gpa" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="detail-label" id="view_risk_description" style="font-size: 0.85rem; color: var(--text-medium); margin-top: 0.5rem;">No Data Inputted</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Class Standing</div>
                            <div class="detail-value" id="view_class_standing" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="detail-label" style="font-size: 0.85rem; color: var(--text-medium);">of 60%</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label">Exams</div>
                            <div class="detail-value" id="view_exams_score" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="detail-label" style="font-size: 0.85rem; color: var(--text-medium);">of 40%</div>
                        </div>
                    </div>
                </div>
                
                <!-- Subject Details -->
                <h4 style="color: var(--plp-green); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-book"></i>
                    Subject Information
                </h4>
                <div class="details-grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                    <div class="detail-item" style="padding: 0.75rem;">
                        <div class="detail-label">Subject Code</div>
                        <div class="detail-value" id="view_subject_code" style="font-weight: 600;"></div>
                    </div>
                    <div class="detail-item" style="padding: 0.75rem;">
                        <div class="detail-label">Subject Name</div>
                        <div class="detail-value" id="view_subject_name" style="font-weight: 600;"></div>
                    </div>
                    <div class="detail-item" style="padding: 0.75rem;">
                        <div class="detail-label">Credits</div>
                        <div class="detail-value" id="view_credits" style="font-weight: 600;"></div>
                    </div>
                    <div class="detail-item" style="padding: 0.75rem;">
                        <div class="detail-label">Semester</div>
                        <div class="detail-value" id="view_semester" style="font-weight: 600;"></div>
                    </div>
                    <div class="detail-item" style="grid-column: 1 / -1; padding: 0.75rem;">
                        <div class="detail-label">Professor</div>
                        <div class="detail-value" id="view_professor" style="font-weight: 600;"></div>
                    </div>
                    <div class="detail-item" style="grid-column: 1 / -1; padding: 0.75rem;">
                        <div class="detail-label">Schedule</div>
                        <div class="detail-value" id="view_schedule" style="font-weight: 600;"></div>
                    </div>
                    <div class="detail-item" style="grid-column: 1 / -1; padding: 0.75rem;">
                        <div class="detail-label">Archived Date</div>
                        <div class="detail-value" id="view_archived_date" style="font-weight: 600;"></div>
                    </div>
                </div>
            </div>
                        
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="closeViewModal">
                    Close
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function openViewModal(
            subjectCode, subjectName, credits, professor, schedule, semester, archivedDate,
            overallGrade = 0, gpa = 0, classStanding = 0, examsScore = 0, riskLevel = 'no-data', 
            riskDescription = 'No Data Inputted', hasScores = false
        ) {
            // Set basic subject details
            document.getElementById('view_subject_code').textContent = subjectCode;
            document.getElementById('view_subject_name').textContent = subjectName;
            document.getElementById('view_credits').textContent = credits + ' Credits';
            document.getElementById('view_professor').textContent = professor;
            document.getElementById('view_schedule').textContent = schedule;
            document.getElementById('view_semester').textContent = semester;
            document.getElementById('view_archived_date').textContent = archivedDate;
            
            // Set performance data
            const overallGradeNum = parseFloat(overallGrade) || 0;
            const gpaNum = parseFloat(gpa) || 0;
            const classStandingNum = parseFloat(classStanding) || 0;
            const examsScoreNum = parseFloat(examsScore) || 0;
            
            document.getElementById('view_overall_grade').textContent = hasScores ? overallGradeNum.toFixed(1) + '%' : '--';
            document.getElementById('view_gpa').textContent = hasScores ? gpaNum.toFixed(2) : '--';
            document.getElementById('view_class_standing').textContent = hasScores ? classStandingNum.toFixed(1) + '%' : '--';
            document.getElementById('view_exams_score').textContent = hasScores ? examsScoreNum.toFixed(1) + '%' : '--';
            document.getElementById('view_risk_description').textContent = riskDescription;
            
            // Set risk badge
            const riskBadge = document.getElementById('view_risk_badge');
            if (hasScores && riskLevel !== 'no-data') {
                riskBadge.textContent = riskLevel.charAt(0).toUpperCase() + riskLevel.slice(1);
                riskBadge.className = 'risk-badge ' + riskLevel;
                riskBadge.style.display = 'inline-block';
            } else {
                riskBadge.textContent = 'No Data';
                riskBadge.className = 'risk-badge no-data';
                riskBadge.style.display = 'inline-block';
            }
            
            document.getElementById('viewModal').classList.add('show');
        }

        document.getElementById('closeViewModal').addEventListener('click', function() {
            document.getElementById('viewModal').classList.remove('show');
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === document.getElementById('viewModal')) {
                document.getElementById('viewModal').classList.remove('show');
            }
        });

        // Auto-hide success/error messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 300);
            });
        }, 5000);
    </script>
</body>
</html>