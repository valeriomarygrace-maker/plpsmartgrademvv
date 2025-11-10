<?php
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Initialize variables
$success_message = '';
$error_message = '';
$student = null;
$archived_subjects = [];

try {
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Get student's archived subjects
try {
    $archived_result = supabaseFetch('student_subjects', [
        'student_id' => $student['id'],
        'archived' => 'true'
    ]);
    
    if ($archived_result) {
        foreach ($archived_result as $subject_record) {
            $subject_info = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
            if ($subject_info) {
                $subject_info = $subject_info[0];
                
                // Get subject performance data
                $performance_data = supabaseFetch('subject_performance', [
                    'student_subject_id' => $subject_record['id']
                ]);
                
                $archived_subjects[] = array_merge($subject_record, [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester'],
                    'performance' => $performance_data ? $performance_data[0] : null
                ]);
            }
        }
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Handle restore subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_subject'])) {
    $subject_record_id = $_POST['subject_record_id'];
    
    try {
        $update_data = [
            'archived' => false,
            'archived_at' => null
        ];
        
        $result = supabaseUpdate('student_subjects', $update_data, [
            'id' => $subject_record_id, 
            'student_id' => $student['id']
        ]);
        
        if ($result) {
            $success_message = 'Subject restored successfully!';
            header("Location: student-archived-subject.php");
            exit;
        } else {
            $error_message = 'Failed to restore subject.';
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Handle get subject details for modal
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_subject_details'])) {
    $subject_record_id = $_GET['subject_record_id'];
    
    try {
        // Get subject basic info
        $subject_record = supabaseFetch('student_subjects', [
            'id' => $subject_record_id,
            'student_id' => $student['id']
        ]);
        
        if ($subject_record && count($subject_record) > 0) {
            $subject_record = $subject_record[0];
            $subject_info = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
            
            if ($subject_info) {
                $subject_info = $subject_info[0];
                
                // Get performance data
                $performance_data = supabaseFetch('subject_performance', [
                    'student_subject_id' => $subject_record_id
                ]);
                
                // Get all scores for grade calculation
                $allScores = supabaseFetch('student_subject_scores', [
                    'student_subject_id' => $subject_record_id
                ]);
                
                if (!$allScores) $allScores = [];
                
                // Calculate grades (same logic as termevaluations.php)
                $midtermGrade = 0;
                $finalGrade = 0;
                $subjectGrade = 0;
                
                // Get midterm categories
                $midtermCategories = supabaseFetch('student_class_standing_categories', [
                    'student_subject_id' => $subject_record_id,
                    'term_type' => 'midterm'
                ]);
                
                // Get final categories
                $finalCategories = supabaseFetch('student_class_standing_categories', [
                    'student_subject_id' => $subject_record_id,
                    'term_type' => 'final'
                ]);
                
                // Calculate Midterm Grade
                if ($midtermCategories && count($midtermCategories) > 0) {
                    $midtermClassStanding = 0;
                    $midtermExamScore = 0;
                    
                    foreach ($midtermCategories as $category) {
                        $categoryScores = array_filter($allScores, function($score) use ($category) {
                            return $score['category_id'] == $category['id'] && $score['score_type'] === 'class_standing';
                        });
                        
                        $categoryTotal = 0;
                        $categoryMax = 0;
                        
                        foreach ($categoryScores as $score) {
                            if (strtolower($category['category_name']) === 'attendance') {
                                $scoreValue = ($score['score_name'] === 'Present') ? 1 : 0;
                                $categoryTotal += $scoreValue;
                                $categoryMax += 1;
                            } else {
                                $categoryTotal += floatval($score['score_value']);
                                $categoryMax += floatval($score['max_score']);
                            }
                        }
                        
                        if ($categoryMax > 0) {
                            $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                            $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                            $midtermClassStanding += $weightedScore;
                        }
                    }
                    
                    if ($midtermClassStanding > 60) {
                        $midtermClassStanding = 60;
                    }
                    
                    $midtermExams = array_filter($allScores, function($score) {
                        return $score['score_type'] === 'midterm_exam';
                    });
                    
                    if (!empty($midtermExams)) {
                        $midtermExam = reset($midtermExams);
                        if (floatval($midtermExam['max_score']) > 0) {
                            $midtermExamPercentage = (floatval($midtermExam['score_value']) / floatval($midtermExam['max_score'])) * 100;
                            $midtermExamScore = ($midtermExamPercentage * 40) / 100;
                        }
                    }
                    
                    $midtermGrade = $midtermClassStanding + $midtermExamScore;
                    if ($midtermGrade > 100) $midtermGrade = 100;
                }
                
                // Calculate Final Grade
                if ($finalCategories && count($finalCategories) > 0) {
                    $finalClassStanding = 0;
                    $finalExamScore = 0;
                    
                    foreach ($finalCategories as $category) {
                        $categoryScores = array_filter($allScores, function($score) use ($category) {
                            return $score['category_id'] == $category['id'] && $score['score_type'] === 'class_standing';
                        });
                        
                        $categoryTotal = 0;
                        $categoryMax = 0;
                        
                        foreach ($categoryScores as $score) {
                            if (strtolower($category['category_name']) === 'attendance') {
                                $scoreValue = ($score['score_name'] === 'Present') ? 1 : 0;
                                $categoryTotal += $scoreValue;
                                $categoryMax += 1;
                            } else {
                                $categoryTotal += floatval($score['score_value']);
                                $categoryMax += floatval($score['max_score']);
                            }
                        }
                        
                        if ($categoryMax > 0) {
                            $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                            $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                            $finalClassStanding += $weightedScore;
                        }
                    }
                    
                    if ($finalClassStanding > 60) {
                        $finalClassStanding = 60;
                    }
                    
                    $finalExams = array_filter($allScores, function($score) {
                        return $score['score_type'] === 'final_exam';
                    });
                    
                    if (!empty($finalExams)) {
                        $finalExam = reset($finalExams);
                        if (floatval($finalExam['max_score']) > 0) {
                            $finalExamPercentage = (floatval($finalExam['score_value']) / floatval($finalExam['max_score'])) * 100;
                            $finalExamScore = ($finalExamPercentage * 40) / 100;
                        }
                    }
                    
                    $finalGrade = $finalClassStanding + $finalExamScore;
                    if ($finalGrade > 100) $finalGrade = 100;
                }
                
                // Calculate Subject Grade
                $grades = array_filter([$midtermGrade, $finalGrade], function($grade) {
                    return $grade > 0;
                });
                
                if (!empty($grades)) {
                    $subjectGrade = array_sum($grades) / count($grades);
                    if ($subjectGrade > 100) $subjectGrade = 100;
                }
                
                // Prepare response
                $response = [
                    'success' => true,
                    'subject' => array_merge($subject_record, $subject_info),
                    'grades' => [
                        'subject_grade' => $subjectGrade,
                        'midterm_grade' => $midtermGrade,
                        'final_grade' => $finalGrade
                    ],
                    'performance' => $performance_data ? $performance_data[0] : null,
                    'has_data' => !empty($allScores)
                ];
                
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }
        }
        
        // If we reach here, subject not found
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Subject not found']);
        exit;
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
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

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .card-title {
            color: var(--plp-green);
            font-size: 1.4rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
            padding: 0;
            border: none;
        }

        .card-title i {
            font-size: 1.2rem;
            width: 32px;
            height: 32px;
            background: var(--plp-green-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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

        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .subject-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid #6c757d;
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
        }

        .subject-code {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 600;
        }

        .credits {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 1000;
        }

        .subject-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0.5rem 0;
            line-height: 1.3;
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
            color: #6c757d;
            width: 14px;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .info-item span {
            flex: 1;
        }

        .archived-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-weight: 600;
            background: #6c757d;
            color: white;
        }

        .subject-actions {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
        }

        .btn {
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
            text-decoration: none;
        }

        .btn-restore {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .btn-restore:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
        }

        .btn-details {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .btn-details:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
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

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
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
            max-width: 600px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
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

        .modal-btn-close {
            background: #f1f5f9;
            color: var(--text-medium);
        }

        .modal-btn-close:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .grades-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .grade-card {
            background: var(--plp-green-pale);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border: 1px solid var(--plp-green-lighter);
        }

        .grade-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.5rem;
        }

        .grade-label {
            font-size: 0.85rem;
            color: var(--text-medium);
            font-weight: 500;
        }

        .no-data-message {
            text-align: center;
            padding: 2rem;
            color: var(--text-light);
            font-style: italic;
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid #e53e3e;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid #38a169;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

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
                max-width: 100%;
                padding: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .card-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .subjects-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .subject-actions {
                flex-direction: column;
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
                <i class="fas fa-archive"></i>
                <?php echo count($archived_subjects); ?> Archived Subjects
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-archive"></i>
                    My Archived Subjects
                </div>
            </div>
            
            <?php if (empty($archived_subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived subjects found</p>
                    <small>Subjects you archive will appear here</small>
                </div>
            <?php else: ?>
                <div class="subjects-grid">
                    <?php foreach ($archived_subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="archived-badge">
                                Archived
                            </div>
                            
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
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span><strong>Archived:</strong> 
                                        <?php echo $subject['archived_at'] ? date('M j, Y', strtotime($subject['archived_at'])) : 'Unknown'; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="subject-actions">
                                <form action="student-archived-subject.php" method="POST" style="display: inline;">
                                    <input type="hidden" name="subject_record_id" value="<?php echo $subject['id']; ?>">
                                    <button type="submit" name="restore_subject" class="btn btn-restore">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                
                                <button type="button" class="btn btn-details" 
                                        onclick="openDetailsModal(<?php echo $subject['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Subject Details Modal -->
    <div class="modal" id="subjectDetailsModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <i class="fas fa-info-circle"></i>
                Subject Details
            </h3>
            
            <div id="modalContent">
            </div>
            
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-close" id="closeDetailsModal">
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
                <button class="modal-btn modal-btn-close" id="cancelLogout" style="min-width: 120px;">
                    Cancel
                </button>
                <button class="modal-btn btn-restore" id="confirmLogout" style="min-width: 120px;">
                    Yes, Logout
                </button>
            </div>
        </div>
    </div>

    <script>
        const detailsModal = document.getElementById('subjectDetailsModal');
        const closeDetailsModal = document.getElementById('closeDetailsModal');
        const modalContent = document.getElementById('modalContent');

        const logoutBtn = document.querySelector('.logout-btn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogout = document.getElementById('confirmLogout');

        // Open details modal
        function openDetailsModal(subjectId) {
            // Show loading state
            modalContent.innerHTML = `
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--plp-green);"></i>
                    <p style="margin-top: 1rem; color: var(--text-medium);">Loading subject details...</p>
                </div>
            `;
            
            detailsModal.classList.add('show');
            
            // Fetch subject details via AJAX
            fetch(`student-archived-subject.php?get_subject_details=1&subject_record_id=${subjectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displaySubjectDetails(data);
                    } else {
                        modalContent.innerHTML = `
                            <div class="alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                ${data.message || 'Failed to load subject details'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalContent.innerHTML = `
                        <div class="alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            Error loading subject details
                        </div>
                    `;
                });
        }

        // Display subject details in modal
        function displaySubjectDetails(data) {
            const subject = data.subject;
            const grades = data.grades;
            const hasData = data.has_data;
            
            if (hasData) {
                content += `
                    <div class="grades-overview">
                        <div class="grade-card">
                            <div class="grade-value">${grades.subject_grade > 0 ? grades.subject_grade.toFixed(1) + '%' : '--'}</div>
                            <div class="grade-label">Subject Grade</div>
                        </div>
                        <div class="grade-card">
                            <div class="grade-value">${grades.midterm_grade > 0 ? grades.midterm_grade.toFixed(1) + '%' : '--'}</div>
                            <div class="grade-label">Midterm Grade</div>
                        </div>
                        <div class="grade-card">
                            <div class="grade-value">${grades.final_grade > 0 ? grades.final_grade.toFixed(1) + '%' : '--'}</div>
                            <div class="grade-label">Final Grade</div>
                        </div>
                    </div>
                `;
            } else {
                content += `
                    <div class="no-data-message">
                        <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                        <p>No grade data available for this subject</p>
                        <small>This subject was archived without any grade records</small>
                    </div>
                `;
            }
            
            modalContent.innerHTML = content;
        }

        // Close details modal
        closeDetailsModal.addEventListener('click', () => {
            detailsModal.classList.remove('show');
        });

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
        const modals = [detailsModal, logoutModal];
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });

        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.1s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 100);
            });
        }, 5000);
    </script>
</body>
</html>