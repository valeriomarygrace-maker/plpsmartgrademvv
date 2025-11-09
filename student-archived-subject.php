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
        
        // Delete the archived subject
        $delete_result = supabaseDelete('archived_subjects', ['id' => $archived_subject_id]);
        
        if ($delete_result) {
            $success_message = 'Subject restored successfully!';
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

// Fetch archived subjects
try {
    $archived_subjects_data = supabaseFetch('archived_subjects', ['student_id' => $student['id']]);
    
    $archived_subjects = [];
    
    if ($archived_subjects_data && is_array($archived_subjects_data)) {
        foreach ($archived_subjects_data as $archived_subject) {
            // Get subject details
            $subject_data = supabaseFetch('subjects', ['id' => $archived_subject['subject_id']]);
            $subject_info = $subject_data && count($subject_data) > 0 ? $subject_data[0] : null;
            
            if ($subject_info) {
                $archived_performance_data = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject['id']]);
                $archived_performance = $archived_performance_data && count($archived_performance_data) > 0 ? $archived_performance_data[0] : null;
                
                $calculated_performance = calculateArchivedSubjectPerformance($archived_subject['id']);
                
                $final_performance = $archived_performance ? [
                    'midterm_grade' => $archived_performance['midterm_grade'] ?? $archived_performance['class_standing'] ?? 0,
                    'final_grade' => $archived_performance['final_grade'] ?? $archived_performance['exams_score'] ?? 0,
                    'subject_grade' => $archived_performance['subject_grade'] ?? $archived_performance['overall_grade'] ?? 0,
                    'risk_level' => $archived_performance['risk_level'] ?? 'no-data',
                    'risk_description' => $archived_performance['risk_description'] ?? 'No Data Inputted',
                    'has_scores' => ($archived_performance['subject_grade'] ?? $archived_performance['overall_grade'] ?? 0) > 0
                ] : $calculated_performance;
                
                // DEBUG: Log the performance data
                error_log("Archived Subject {$archived_subject['id']}: " . 
                         "Subject Grade: {$final_performance['subject_grade']}, " .
                         "Midterm: {$final_performance['midterm_grade']}, " .
                         "Final: {$final_performance['final_grade']}");
                
                // Combine all data
                $archived_subjects[] = array_merge($archived_subject, [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester'],
                    'midterm_grade' => $final_performance['midterm_grade'] ?? 0,
                    'final_grade' => $final_performance['final_grade'] ?? 0,
                    'subject_grade' => $final_performance['subject_grade'] ?? 0,
                    'risk_level' => $final_performance['risk_level'] ?? 'no-data',
                    'risk_description' => $final_performance['risk_description'] ?? 'No Data Inputted',
                    'has_scores' => $final_performance['has_scores'] ?? false,
                    'has_archived_performance' => !empty($archived_performance)
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
 * Calculate performance for archived subject from scores
 */
function calculateArchivedSubjectPerformance($archived_subject_id) {
    try {
        // Get all categories for this archived subject
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        if (!$categories || !is_array($categories)) {
            return [
                'midterm_grade' => 0,
                'final_grade' => 0,
                'subject_grade' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        $midtermGrade = 0;
        $finalGrade = 0;
        $subjectGrade = 0;
        $hasScores = false;
        
        // Separate midterm and final categories
        $midtermCategories = array_filter($categories, function($cat) {
            return isset($cat['term_type']) && $cat['term_type'] === 'midterm';
        });
        
        $finalCategories = array_filter($categories, function($cat) {
            return isset($cat['term_type']) && $cat['term_type'] === 'final';
        });
        
        // Calculate Midterm Grade
        if (!empty($midtermCategories)) {
            $midtermClassStandingTotal = 0;
            
            foreach ($midtermCategories as $category) {
                // Skip exam scores category for class standing calculation
                if (strtolower($category['category_name']) === 'exam scores') {
                    continue;
                }
                
                $scores = supabaseFetch('archived_subject_scores', [
                    'archived_category_id' => $category['id']
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
                        $midtermClassStandingTotal += $weightedScore;
                    }
                }
            }
            
            // Ensure Class Standing doesn't exceed 60%
            if ($midtermClassStandingTotal > 60) {
                $midtermClassStandingTotal = 60;
            }
            
            // Calculate midterm exam score
            $midtermExamScore = 0;
            foreach ($midtermCategories as $category) {
                if (strtolower($category['category_name']) === 'exam scores') {
                    $exam_scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id']]);
                    
                    if ($exam_scores && is_array($exam_scores)) {
                        foreach ($exam_scores as $exam) {
                            if (floatval($exam['max_score']) > 0 && $exam['score_type'] === 'midterm_exam') {
                                $examPercentage = (floatval($exam['score_value']) / floatval($exam['max_score'])) * 100;
                                $midtermExamScore = ($examPercentage * 40) / 100;
                                $hasScores = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            $midtermGrade = $midtermClassStandingTotal + $midtermExamScore;
            if ($midtermGrade > 100) {
                $midtermGrade = 100;
            }
        }
        
        // Calculate Final Grade
        if (!empty($finalCategories)) {
            $finalClassStandingTotal = 0;
            
            foreach ($finalCategories as $category) {
                // Skip exam scores category for class standing calculation
                if (strtolower($category['category_name']) === 'exam scores') {
                    continue;
                }
                
                $scores = supabaseFetch('archived_subject_scores', [
                    'archived_category_id' => $category['id']
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
                        $finalClassStandingTotal += $weightedScore;
                    }
                }
            }
            
            // Ensure Class Standing doesn't exceed 60%
            if ($finalClassStandingTotal > 60) {
                $finalClassStandingTotal = 60;
            }
            
            // Calculate final exam score
            $finalExamScore = 0;
            foreach ($finalCategories as $category) {
                if (strtolower($category['category_name']) === 'exam scores') {
                    $exam_scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id']]);
                    
                    if ($exam_scores && is_array($exam_scores)) {
                        foreach ($exam_scores as $exam) {
                            if (floatval($exam['max_score']) > 0 && $exam['score_type'] === 'final_exam') {
                                $examPercentage = (floatval($exam['score_value']) / floatval($exam['max_score'])) * 100;
                                $finalExamScore = ($examPercentage * 40) / 100;
                                $hasScores = true;
                                break;
                            }
                        }
                    }
                }
            }
            
            $finalGrade = $finalClassStandingTotal + $finalExamScore;
            if ($finalGrade > 100) {
                $finalGrade = 100;
            }
        }
        
        // Calculate Subject Grade (average of midterm and final)
        if ($midtermGrade > 0 && $finalGrade > 0) {
            $subjectGrade = ($midtermGrade + $finalGrade) / 2;
        } elseif ($midtermGrade > 0) {
            $subjectGrade = $midtermGrade;
        } elseif ($finalGrade > 0) {
            $subjectGrade = $finalGrade;
        }
        
        if ($subjectGrade > 100) {
            $subjectGrade = 100;
        }
        
        if (!$hasScores) {
            return [
                'midterm_grade' => 0,
                'final_grade' => 0,
                'subject_grade' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        // Calculate risk level based on subject grade
        $riskLevel = 'no-data';
        $riskDescription = 'No Data Inputted';

        if ($subjectGrade >= 85) {
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
        } elseif ($subjectGrade >= 80) {
            $riskLevel = 'medium';
            $riskDescription = 'Moderate Risk';
        } elseif ($subjectGrade > 0) {
            $riskLevel = 'high';
            $riskDescription = 'High Risk';
        }
        
        return [
            'midterm_grade' => $midtermGrade,
            'final_grade' => $finalGrade,
            'subject_grade' => $subjectGrade,
            'risk_level' => $riskLevel,
            'risk_description' => $riskDescription,
            'has_scores' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating archived subject performance: " . $e->getMessage());
        return [
            'midterm_grade' => 0,
            'final_grade' => 0,
            'subject_grade' => 0,
            'risk_level' => 'no-data',
            'risk_description' => 'Error calculating grades',
            'has_scores' => false
        ];
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
            line-height: 1.2;
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
            padding: 0.3rem 0.8rem;
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

        .subject-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            flex-wrap: wrap;
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
            flex: 1;
            min-width: 120px;
            justify-content: center;
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
            flex: 1;
            min-width: 120px;
            justify-content: center;
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

        .risk-badge.failed {
            background: #7f1d1d;
            color: #fecaca;
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
            max-width: 700px;
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
            max-width: 700px;
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

        .performance-overview {
            margin-bottom: 1.5rem;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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

        .grade-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--plp-green);
            margin: 0.5rem 0;
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

        .grade-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--plp-green);
            margin: 0.5rem 0;
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

        /* Enhanced Responsive Styles */
        @media (max-width: 1200px) {
            .main-content {
                padding: 1rem 2rem;
            }
            
            .subjects-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 1.25rem;
            }
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 280px;
            }
            
            .main-content {
                margin-left: 280px;
                max-width: calc(100% - 280px);
            }
            
            .card {
                padding: 1.5rem;
            }
        }

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
            
            .btn-restore,
            .btn-view {
                flex: none;
                width: 100%;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
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
            
            .detail-item {
                padding: 0.75rem;
            }
            
            .detail-value {
                font-size: 0.9rem;
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
            
            .btn-restore,
            .btn-view {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            .modal-content {
                padding: 0.75rem;
            }
        }
        /* Add these styles to your existing CSS */
        .overview-section {
            margin-bottom: 2rem;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .overview-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            text-align: center;
            transition: var(--transition);
        }

        .overview-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .subject-grade-card {
            border-left: 4px solid var(--plp-green);
        }

        .overview-label {
            font-size: 0.8rem;
            color: var(--text-light);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .overview-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--plp-green);
            margin: 0.5rem 0;
        }

        .overview-description {
            font-size: 0.85rem;
            color: var(--text-medium);
        }

        .terms-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .term-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            cursor: pointer;
            transition: var(--transition);
        }

        .term-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .term-card.midterm {
            border-left: 4px solid #3B82F6;
        }

        .term-card.final {
            border-left: 4px solid #10B981;
        }

        .term-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .term-card.midterm .term-title {
            color: #3B82F6;
        }

        .term-card.final .term-title {
            color: #10B981;
        }

        .term-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--plp-green);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-light);
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
                                    <i class="fas fa-clock"></i>
                                    <span><strong>Archived:</strong> <?php echo date('M j, Y g:i A', strtotime($subject['archived_at'])); ?></span>
                                </div>
                                
                                <!-- Grade Summary -->
                                <div class="info-item">
                                    <i class="fas fa-chart-line"></i>
                                    <span>
                                        <strong>Final Grade:</strong> 
                                        <?php if ($subject['has_scores']): ?>
                                            <span style="color: var(--plp-green); font-weight: 600;">
                                                <?php echo number_format($subject['subject_grade'], 1); ?>%
                                            </span>
                                            <span class="risk-badge <?php echo $subject['risk_level']; ?>" style="margin-left: 0.5rem;">
                                                <?php echo $subject['risk_description']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-light);">No grades recorded</span>
                                        <?php endif; ?>
                                    </span>
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
                                    <?php echo $subject['subject_grade'] ?? 0; ?>,
                                    <?php echo $subject['midterm_grade'] ?? 0; ?>,
                                    <?php echo $subject['final_grade'] ?? 0; ?>,
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
        <div class="modal-content" style="max-width: 800px;">
            <h3 class="modal-title">
                <i class="fas fa-chart-line"></i>
                Subject Performance Overview
            </h3>
            
            <div class="overview-section">
                <div class="overview-grid">
                    <div class="overview-card subject-grade-card">
                        <div class="overview-label">SUBJECT GRADE</div>
                        <div class="overview-value" id="modal_subject_grade">
                            --
                        </div>
                        <div class="overview-description" id="modal_subject_risk">
                            No grades calculated
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="overview-label">MIDTERM GRADE</div>
                        <div class="overview-value" id="modal_midterm_grade">
                            --
                        </div>
                        <div class="overview-description" id="modal_midterm_desc">
                            No midterm data
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="overview-label">FINAL GRADE</div>
                        <div class="overview-value" id="modal_final_grade">
                            --
                        </div>
                        <div class="overview-description" id="modal_final_desc">
                            No final data
                        </div>
                    </div>
                </div>
            </div>

            <div class="terms-container">
                <!-- Midterm Card -->
                <div class="term-card midterm">
                    <div class="term-title">
                        <i class="fas fa-chart-bar"></i> MIDTERM
                    </div>
                    <div class="term-stats">
                        <div class="stat-item">
                            <div class="stat-value">60%</div>
                            <div class="stat-label">Class Standing</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">40%</div>
                            <div class="stat-label">Midterm Exam</div>
                        </div>
                    </div>
                </div>

                <!-- Final Card -->
                <div class="term-card final">
                    <div class="term-title">
                        <i class="fas fa-chart-line"></i> FINAL
                    </div>
                    <div class="term-stats">
                        <div class="stat-item">
                            <div class="stat-value">60%</div>
                            <div class="stat-label">Class Standing</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">40%</div>
                            <div class="stat-label">Final Exam</div>
                        </div>
                    </div>
                </div>
            </div>
                        
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="closeViewModal">
                    <i class="fas fa-times"></i> Close
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

        function openViewModal(
            subjectGrade = 0, midtermGrade = 0, finalGrade = 0, riskLevel = 'no-data', 
            riskDescription = 'No Data Inputted', hasScores = false
        ) {
            console.log('Opening modal with data:', { subjectGrade, midtermGrade, finalGrade, riskLevel, riskDescription, hasScores });
            
            // Set grade data
            const subjectGradeNum = parseFloat(subjectGrade) || 0;
            const midtermGradeNum = parseFloat(midtermGrade) || 0;
            const finalGradeNum = parseFloat(finalGrade) || 0;
            
            // Update overview cards
            const subjectGradeElement = document.getElementById('modal_subject_grade');
            const midtermGradeElement = document.getElementById('modal_midterm_grade');
            const finalGradeElement = document.getElementById('modal_final_grade');
            
            subjectGradeElement.textContent = hasScores && subjectGradeNum > 0 ? subjectGradeNum.toFixed(1) + '%' : '--';
            midtermGradeElement.textContent = hasScores && midtermGradeNum > 0 ? midtermGradeNum.toFixed(1) + '%' : '--';
            finalGradeElement.textContent = hasScores && finalGradeNum > 0 ? finalGradeNum.toFixed(1) + '%' : '--';
            
            // Update descriptions and risk badges
            const subjectRiskElement = document.getElementById('modal_subject_risk');
            const midtermDescElement = document.getElementById('modal_midterm_desc');
            const finalDescElement = document.getElementById('modal_final_desc');
            
            // Clear previous content
            subjectRiskElement.innerHTML = '';
            midtermDescElement.textContent = '';
            finalDescElement.textContent = '';
            
            if (hasScores && subjectGradeNum > 0) {
                // Create risk badge for subject grade
                const riskBadge = document.createElement('span');
                riskBadge.className = 'risk-badge ' + riskLevel;
                riskBadge.textContent = riskDescription;
                riskBadge.style.marginTop = '0.5rem';
                riskBadge.style.display = 'inline-block';
                
                subjectRiskElement.appendChild(riskBadge);
            } else {
                subjectRiskElement.textContent = 'No grades calculated';
            }
            
            // Update midterm description
            if (hasScores && midtermGradeNum > 0) {
                midtermDescElement.textContent = getTermGradeDescription(midtermGradeNum);
            } else {
                midtermDescElement.textContent = 'No midterm data';
            }
            
            // Update final description
            if (hasScores && finalGradeNum > 0) {
                finalDescElement.textContent = getTermGradeDescription(finalGradeNum);
            } else {
                finalDescElement.textContent = 'No final data';
            }
            
            // Show modal
            document.getElementById('viewModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Helper function to get term grade description
        function getTermGradeDescription(grade) {
            if (grade >= 90) return 'Excellent';
            if (grade >= 85) return 'Very Good';
            if (grade >= 80) return 'Good';
            if (grade >= 75) return 'Satisfactory';
            return 'Needs Improvement';
        }

        // Close modal when clicking close button
        document.getElementById('closeViewModal').addEventListener('click', function() {
            closeViewModal();
        });

        // Close modal when clicking outside
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeViewModal();
            }
        });

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('show');
            document.body.style.overflow = '';
        }

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