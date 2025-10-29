    <?php
    require_once 'config.php';
    require_once 'ml-helpers.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
        header('Location: login.php');
        exit;
    }

    $success_message = '';
    $error_message = '';

    try {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE email = ?");
        $stmt->execute([$_SESSION['user_email']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            $error_message = 'Student record not found.';
        }
    } catch (PDOException $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }

    $subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

    if ($subject_id <= 0) {
        header('Location: student-subjects.php');
        exit;
    }

    try {
        $subject_stmt = $pdo->prepare("
            SELECT ss.*, s.subject_code, s.subject_name, s.credits, s.semester 
            FROM student_subjects ss 
            JOIN subjects s ON ss.subject_id = s.id 
            WHERE ss.id = ? AND ss.student_id = ?
        ");
        $subject_stmt->execute([$subject_id, $student['id']]);
        $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subject) {
            header('Location: student-subjects.php');
            exit;
        }
    } catch (PDOException $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }

    $categories = [];
    $classStandings = [];
    $midtermExam = [];
    $finalExam = [];
    $allScores = [];

    // Get class standing categories for this subject
    try {
        $categories_stmt = $pdo->prepare("SELECT * FROM student_class_standing_categories WHERE student_subject_id = ? ORDER BY created_at");
        $categories_stmt->execute([$subject_id]);
        $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $categories = [];
    }

    // Calculate total allocated percentage and remaining
    $totalClassStandingPercentage = 0;
    foreach ($categories as $category) {
        $totalClassStandingPercentage += $category['category_percentage'];
    }
    $remainingAllocation = 60 - $totalClassStandingPercentage;
    $canAddCategory = ($remainingAllocation > 0);

    // Get student's scores for this subject
    try {
        $scores_stmt = $pdo->prepare("
            SELECT s.*, c.category_name 
            FROM student_subject_scores s 
            LEFT JOIN student_class_standing_categories c ON s.category_id = c.id 
            WHERE s.student_subject_id = ? 
            ORDER BY s.score_type, s.score_date, s.created_at
        ");
        $scores_stmt->execute([$subject_id]);
        $allScores = $scores_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $scores_stmt = $pdo->prepare("
                SELECT * FROM student_subject_scores 
                WHERE student_subject_id = ? 
                ORDER BY score_type, score_date, created_at
            ");
            $scores_stmt->execute([$subject_id]);
            $allScores = $scores_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($allScores as &$score) {
                $score['category_name'] = '';
            }
        } catch (PDOException $e2) {
            $allScores = [];
        }
    }

    // FIXED: Properly filter scores by score_type - ensure exam scores don't appear in class standings
    $classStandings = array_filter($allScores, function($score) {
        return $score['score_type'] === 'class_standing';
    });

    $midtermExam = array_filter($allScores, function($score) {
        return $score['score_type'] === 'midterm_exam';
    });

    $finalExam = array_filter($allScores, function($score) {
        return $score['score_type'] === 'final_exam';
    });

    // Include ML helpers
    require_once 'ml-helpers.php';

    // Log behavioral data when scores are added/updated
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_standing']) || isset($_POST['update_score']) || isset($_POST['add_exam']) || isset($_POST['add_attendance'])) {
            // Log the activity
            InterventionSystem::logBehavior(
                $student['id'], 
                'grade_update', 
                [
                    'subject_id' => $subject_id,
                    'subject_name' => $subject['subject_name'],
                    'action' => isset($_POST['add_standing']) ? 'add_score' : 'update_score'
                ],
                $pdo
            );
        }
    }

    $hasScores = !empty($classStandings) || !empty($midtermExam) || !empty($finalExam);

    // Initialize variables
    $totalClassStanding = 0;
    $midtermScore = 0;
    $finalScore = 0;
    $overallGrade = 0;
    $gpa = 0;
    $riskLevel = 'no-data';
    $riskDescription = 'No Data Inputted';
    $interventionNeeded = false;

    // If no scores are inputted, set everything to 0 and show "No Data"
    if (!$hasScores) {
        $overallGrade = 0;
        $gpa = 0;
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
    } else {
        // Calculate category totals and overall class standing (MAX 60%)
        $categoryTotals = [];
        foreach ($categories as $category) {
            $categoryTotals[$category['id']] = [
                'name' => $category['category_name'],
                'percentage' => $category['category_percentage'],
                'scores' => [],
                'total_score' => 0,
                'max_possible' => 0,
                'percentage_score' => 0,
                'weighted_score' => 0
            ];
        }

        // Process class standings - MULTIPLE SCORES THROUGHOUT SEMESTER
        if (is_array($classStandings)) {
            foreach ($classStandings as $standing) {
                if ($standing['category_id'] && isset($categoryTotals[$standing['category_id']])) {
                    $categoryId = $standing['category_id'];
                    $categoryTotals[$categoryId]['scores'][] = $standing;
                    
                    // For attendance, treat "Present" as 1 point and "Absent" as 0
                    if (strtolower($categoryTotals[$categoryId]['name']) === 'attendance') {
                        $scoreValue = ($standing['score_name'] === 'Present') ? 1 : 0;
                        $categoryTotals[$categoryId]['total_score'] += $scoreValue;
                        $categoryTotals[$categoryId]['max_possible'] += 1;
                    } else {
                        $categoryTotals[$categoryId]['total_score'] += $standing['score_value'];
                        $categoryTotals[$categoryId]['max_possible'] += $standing['max_score'];
                    }
                }
            }
        }

        // Calculate weighted scores for each category (MAX 60% TOTAL)
        foreach ($categoryTotals as $categoryId => $category) {
            if ($category['max_possible'] > 0) {
                // Calculate percentage score for this category based on ALL accumulated scores
                $percentageScore = ($category['total_score'] / $category['max_possible']) * 100;
                $categoryTotals[$categoryId]['percentage_score'] = $percentageScore;
                
                // Calculate weighted contribution based on accumulated performance
                $categoryTotals[$categoryId]['weighted_score'] = ($percentageScore * $category['percentage']) / 100;
                $totalClassStanding += $categoryTotals[$categoryId]['weighted_score'];
            }
        }

        // Ensure Class Standing doesn't exceed 60%
        if ($totalClassStanding > 60) {
            $totalClassStanding = 60;
        }

        // Calculate exam scores (MAX 40% TOTAL - 20% each)
        // Students input these ONCE per semester for midterm and final
        if (!empty($midtermExam)) {
            $midterm = reset($midtermExam);
            if ($midterm['max_score'] > 0) {
                $midtermPercentage = ($midterm['score_value'] / $midterm['max_score']) * 100;
                $midtermScore = ($midtermPercentage * 20) / 100;
            }
        }

        if (!empty($finalExam)) {
            $final = reset($finalExam);
            if ($final['max_score'] > 0) {
                $finalPercentage = ($final['score_value'] / $final['max_score']) * 100;
                $finalScore = ($finalPercentage * 20) / 100;
            }
        }

        // Calculate overall grade: Class Standing (60%) + Exams (40%)
        $overallGrade = $totalClassStanding + $midtermScore + $finalScore;

        // Ensure overall grade doesn't exceed 100%
        if ($overallGrade > 100) {
            $overallGrade = 100;
        }

        // Calculate GPA based on Final Grade only (1.00-3.00 scale)
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
        if ($gpa == 1.75) {
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
            $interventionNeeded = false;
        } elseif ($gpa == 2.00 || $gpa == 2.75) {
            $riskLevel = 'medium';
            $riskDescription = 'Medium Risk';
            $interventionNeeded = false;
        } elseif ($gpa == 3.00) {
            $riskLevel = 'high';
            $riskDescription = 'High Risk';
            $interventionNeeded = true;
        }
    }

    // Get behavioral insights and recommendations
    $behavioralInsights = [];
    $interventions = [];
    $recommendations = [];

    if ($hasScores) {
        // Get behavioral insights
        $behavioralInsights = InterventionSystem::getBehavioralInsights($student['id'], $subject_id, $pdo);
        
        // Get interventions based on risk level
        $interventions = InterventionSystem::getInterventions($student['id'], $subject_id, $riskLevel, $pdo);
        
        // Get recommendations based on performance
        $recommendations = InterventionSystem::getRecommendations($student['id'], $subject_id, $overallGrade, $pdo);
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_category'])) {
            $category_name = trim($_POST['category_name']);
            $category_percentage = floatval($_POST['category_percentage']);
            
            // Validate
            if (empty($category_name) || $category_percentage <= 0) {
                $error_message = 'Please fill all fields with valid values.';
            } elseif ($category_percentage > $remainingAllocation) {
                $error_message = 'Cannot add category. Remaining allocation is only ' . $remainingAllocation . '%.';
            } else {
                try {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO student_class_standing_categories (student_subject_id, category_name, category_percentage) 
                        VALUES (?, ?, ?)
                    ");
                    if ($insert_stmt->execute([$subject_id, $category_name, $category_percentage])) {
                        $success_message = 'Category added successfully!';
                        header("Location: subject-management.php?subject_id=$subject_id");
                        exit;
                    }
                } catch (PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
        
        elseif (isset($_POST['add_standing'])) {
            $category_id = intval($_POST['category_id']);
            $score_name = trim($_POST['score_name']);
            $score_value = floatval($_POST['score_value']);
            $max_score = floatval($_POST['max_score']);
            $score_date = $_POST['score_date'];
            
            // Validate
            if (empty($score_name) || $score_value < 0 || $max_score <= 0 || empty($score_date)) {
                $error_message = 'Please fill all fields with valid values.';
            } elseif ($score_value > $max_score) {
                $error_message = 'Score value cannot exceed maximum score.';
            } else {
                try {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO student_subject_scores (student_subject_id, category_id, score_type, score_name, score_value, max_score, score_date) 
                        VALUES (?, ?, 'class_standing', ?, ?, ?, ?)
                    ");
                    if ($insert_stmt->execute([$subject_id, $category_id, $score_name, $score_value, $max_score, $score_date])) {
                        $success_message = 'Score added successfully!';
                        header("Location: subject-management.php?subject_id=$subject_id");
                        exit;
                    }
                } catch (PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
        
        // Handle attendance submission
        elseif (isset($_POST['add_attendance'])) {
            $category_id = intval($_POST['category_id']);
            $attendance_date = $_POST['attendance_date'];
            $attendance_status = $_POST['attendance_status'];
            
            // Validate
            if (empty($attendance_date)) {
                $error_message = 'Please select a date.';
            } else {
                try {
                    // Check if attendance already exists for this date
                    $check_stmt = $pdo->prepare("
                        SELECT id FROM student_subject_scores 
                        WHERE student_subject_id = ? AND category_id = ? AND score_date = ?
                    ");
                    $check_stmt->execute([$subject_id, $category_id, $attendance_date]);
                    
                    if ($check_stmt->fetch()) {
                        $error_message = 'Attendance already recorded for this date.';
                    } else {
                        // For attendance, score_name is the status (Present/Absent)
                        // score_value is 1 for Present, 0 for Absent
                        // max_score is always 1
                        $score_value = ($attendance_status === 'present') ? 1 : 0;
                        
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO student_subject_scores (student_subject_id, category_id, score_type, score_name, score_value, max_score, score_date) 
                            VALUES (?, ?, 'class_standing', ?, ?, 1, ?)
                        ");
                        if ($insert_stmt->execute([$subject_id, $category_id, ucfirst($attendance_status), $score_value, $attendance_date])) {
                            $success_message = 'Attendance recorded successfully!';
                            header("Location: subject-management.php?subject_id=$subject_id");
                            exit;
                        }
                    }
                } catch (PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
        
        elseif (isset($_POST['update_score'])) {
            $score_id = intval($_POST['score_id']);
            $score_value = floatval($_POST['score_value']);
            
            // Get the score to validate against max_score
            try {
                $score_stmt = $pdo->prepare("SELECT max_score FROM student_subject_scores WHERE id = ? AND student_subject_id = ?");
                $score_stmt->execute([$score_id, $subject_id]);
                $score_data = $score_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($score_data && $score_value > $score_data['max_score']) {
                    $error_message = 'Score value cannot exceed maximum score of ' . $score_data['max_score'];
                } else {
                    $update_stmt = $pdo->prepare("UPDATE student_subject_scores SET score_value = ? WHERE id = ? AND student_subject_id = ?");
                    if ($update_stmt->execute([$score_value, $score_id, $subject_id])) {
                        $success_message = 'Score updated successfully!';
                        header("Location: subject-management.php?subject_id=$subject_id");
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        }
        
        elseif (isset($_POST['delete_score'])) {
            $score_id = intval($_POST['score_id']);
            
            try {
                $delete_stmt = $pdo->prepare("DELETE FROM student_subject_scores WHERE id = ? AND student_subject_id = ?");
                if ($delete_stmt->execute([$score_id, $subject_id])) {
                    $success_message = 'Score deleted successfully!';
                    header("Location: subject-management.php?subject_id=$subject_id");
                    exit;
                }
            } catch (PDOException $e) {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        }
        
        elseif (isset($_POST['delete_category'])) {
            $category_id = intval($_POST['category_id']);
            
            try {
                // First delete all scores in this category
                $delete_scores_stmt = $pdo->prepare("DELETE FROM student_subject_scores WHERE category_id = ?");
                $delete_scores_stmt->execute([$category_id]);
                
                // Then delete the category
                $delete_category_stmt = $pdo->prepare("DELETE FROM student_class_standing_categories WHERE id = ? AND student_subject_id = ?");
                if ($delete_category_stmt->execute([$category_id, $subject_id])) {
                    $success_message = 'Category deleted successfully!';
                    header("Location: subject-management.php?subject_id=$subject_id");
                    exit;
                }
            } catch (PDOException $e) {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        }
        
        elseif (isset($_POST['add_exam'])) {
            $exam_type = $_POST['exam_type'];
            $score_value = floatval($_POST['score_value']);
            $max_score = floatval($_POST['max_score']);
            
            // Validate exam score
            if ($score_value < 0 || $max_score <= 0) {
                $error_message = 'Score value and maximum score must be positive numbers.';
            } elseif ($score_value > $max_score) {
                $error_message = 'Score value cannot exceed maximum score.';
            } else {
                $exam_name = $exam_type === 'midterm_exam' ? 'Midterm Exam' : 'Final Exam';
                
                try {
                    // Delete existing exam score if any
                    $delete_stmt = $pdo->prepare("DELETE FROM student_subject_scores WHERE student_subject_id = ? AND score_type = ?");
                    $delete_stmt->execute([$subject_id, $exam_type]);
                    
                    // Insert new exam score with custom max_score
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO student_subject_scores (student_subject_id, score_type, score_name, score_value, max_score) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    if ($insert_stmt->execute([$subject_id, $exam_type, $exam_name, $score_value, $max_score])) {
                        $success_message = $exam_name . ' score added successfully!';
                        header("Location: subject-management.php?subject_id=$subject_id");
                        exit;
                    }
                } catch (PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Subject Management</title>
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

            /* Additional styles for the subject management page */
            .class-title {
                font-size: 1.5rem;
                font-weight: 700;
                letter-spacing: 0.5px;
            }

            .header-actions {
                display: flex;
                gap: 1rem;
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
                border-radius: 10px;
                padding: 8px;
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

            /* NEW: Simplified Performance Overview */
            .performance-overview {
                margin-bottom: 2rem;
            }

            .performance-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .performance-card {
                background: white;
                padding: 1.25rem;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                text-align: center;
                border-left: 4px solid var(--plp-green);
            }

            .performance-value {
                font-size: 1.8rem;
                font-weight: 700;
                color: var(--plp-green);
                margin-bottom: 0.25rem;
            }

            .performance-label {
                font-size: 0.85rem;
                color: var(--text-medium);
                font-weight: 500;
            }

            /* Risk badges */
            .risk-badge {
                display: inline-block;
                padding: 0.3rem 0.8rem;
                border-radius: 15px;
                font-size: 0.75rem;
                font-weight: 600;
                text-align: center;
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

            /* NEW: Combined Insights Section */
            .insights-section {
                margin-bottom: 2rem;
            }

            .insights-tabs {
                display: flex;
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                overflow: hidden;
                margin-bottom: 1rem;
            }

            .insight-tab {
                flex: 1;
                padding: 1rem;
                text-align: center;
                background: var(--plp-green-pale);
                border: none;
                cursor: pointer;
                transition: var(--transition);
                font-weight: 500;
                color: var(--text-medium);
                border-bottom: 3px solid transparent;
            }

            .insight-tab.active {
                background: white;
                color: var(--plp-green);
                border-bottom: 3px solid var(--plp-green);
            }

            .insight-tab:hover:not(.active) {
                background: var(--plp-green-lighter);
            }

            .insight-content {
                display: none;
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                padding: 1.5rem;
            }

            .insight-content.active {
                display: block;
            }

            .insight-list {
                list-style: none;
            }

            .insight-item {
                padding: 0.75rem 0;
                border-bottom: 1px solid var(--plp-green-lighter);
                display: flex;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .insight-item:last-child {
                border-bottom: none;
            }

            .insight-icon {
                color: var(--plp-green);
                font-size: 1rem;
                margin-top: 0.2rem;
                flex-shrink: 0;
            }

            .insight-text {
                flex-grow: 1;
                font-size: 0.9rem;
            }

            .insight-priority {
                display: inline-block;
                padding: 0.2rem 0.5rem;
                border-radius: 10px;
                font-size: 0.7rem;
                font-weight: 600;
                margin-left: 0.5rem;
            }

            .priority-high {
                background: #fed7d7;
                color: #c53030;
            }

            .priority-medium {
                background: #fef5e7;
                color: #d69e2e;
            }

            .priority-low {
                background: #c6f6d5;
                color: #2f855a;
            }

            /* NEW: Simplified Category Section */
            .category-section {
                margin-bottom: 2rem;
            }

            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
                font-weight: 600;
                color: var(--plp-green);
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .allocation-info {
                display: flex;
                align-items: center;
                gap: 1rem;
            }

            .remaining-allocation {
                background: var(--plp-green-lighter);
                color: var(--plp-green);
                padding: 0.5rem 1rem;
                border-radius: 20px;
                font-weight: 600;
                font-size: 0.9rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .allocation-full {
                background: #fee2e2;
                color: #dc2626;
                padding: 0.5rem 1rem;
                border-radius: 20px;
                font-weight: 600;
                font-size: 0.9rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            /* NEW: Compact category grid layout */
            .category-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 1rem;
            }

            .category-card {
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                overflow: hidden;
                transition: var(--transition);
                display: flex;
                flex-direction: column;
                height: 100%;
            }

            .category-card:hover {
                transform: translateY(-2px);
                box-shadow: var(--box-shadow-lg);
            }

            .category-card-header {
                padding: 1rem;
                background: var(--plp-green-pale);
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid var(--plp-green-lighter);
            }

            .category-name {
                font-weight: 600;
                color: var(--text-dark);
                font-size: 0.95rem;
            }

            .category-percentage-badge {
                background: var(--plp-green);
                color: white;
                padding: 0.3rem 0.8rem;
                border-radius: 15px;
                font-size: 0.75rem;
                font-weight: 600;
            }

            .category-content {
                padding: 1rem;
                flex-grow: 1;
                display: flex;
                flex-direction: column;
            }

            .scores-list {
                max-height: 150px;
                overflow-y: auto;
                margin-bottom: 1rem;
            }

            .score-item {
                background: var(--plp-green-pale);
                padding: 0.75rem;
                border-radius: 8px;
                margin-bottom: 0.5rem;
                border-left: 3px solid var(--plp-green);
            }

            .score-main {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0.25rem;
            }

            .score-name {
                font-weight: 500;
                color: var(--text-dark);
                font-size: 0.85rem;
            }

            .score-value {
                font-weight: 600;
                color: var(--plp-green);
                font-size: 0.85rem;
            }

            .score-details {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 0.7rem;
                color: var(--text-light);
            }

            .score-actions {
                display: flex;
                gap: 0.5rem;
            }

            .action-btn {
                background: none;
                border: none;
                cursor: pointer;
                padding: 0.25rem;
                border-radius: 4px;
                transition: var(--transition);
                font-size: 0.75rem;
            }

            .edit-btn {
                color: var(--info);
            }

            .edit-btn:hover {
                background: var(--info);
                color: white;
            }

            .delete-btn {
                color: var(--danger);
            }

            .delete-btn:hover {
                background: var(--danger);
                color: white;
            }

            .category-actions {
                padding: 1rem;
                border-top: 1px solid var(--plp-green-lighter);
                text-align: center;
            }

            /* Empty states */
            .empty-state {
                text-align: center;
                padding: 2rem;
                color: var(--text-medium);
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
            }

            .empty-scores {
                text-align: center;
                padding: 1rem;
                color: var(--text-light);
            }

            /* Standings Section */
            .standings-section {
                margin-bottom: 2rem;
            }

            .management-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
            }

            .management-card {
                background: white;
                padding: 1.25rem;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                text-align: center;
                cursor: pointer;
                transition: var(--transition);
                border: 2px solid transparent;
            }

            .management-card:hover {
                transform: translateY(-2px);
                box-shadow: var(--box-shadow-lg);
                border-color: var(--plp-green);
            }

            .major-exam-card h3 {
                color: var(--plp-green);
                margin-bottom: 0.5rem;
                font-size: 1rem;
            }

            .major-exam-badge {
                background: var(--plp-green);
                color: white;
                padding: 0.3rem 0.8rem;
                border-radius: 15px;
                font-size: 0.75rem;
                font-weight: 600;
                display: inline-block;
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
                padding: 1.5rem;
                border-radius: var(--border-radius-lg);
                box-shadow: var(--box-shadow-lg);
                max-width: 450px;
                width: 90%;
                transform: translateY(20px);
                transition: transform 0.3s ease;
                position: relative;
            }

            .modal.show .modal-content {
                transform: translateY(0);
            }

            .close {
                position: absolute;
                top: 1rem;
                right: 1.5rem;
                font-size: 1.5rem;
                cursor: pointer;
                color: var(--text-light);
            }

            .close:hover {
                color: var(--text-dark);
            }

            .modal-title {
                color: var(--plp-green);
                font-size: 1.1rem;
                font-weight: 600;
                margin-bottom: 1rem;
            }

            .form-group {
                margin-bottom: 1rem;
            }

            .form-label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 500;
                color: var(--text-dark);
                font-size: 0.9rem;
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

            .modal-actions {
                display: flex;
                justify-content: flex-end;
                gap: 1rem;
                margin-top: 1rem;
            }

            .modal-btn {
                padding: 0.6rem 1.2rem;
                border: none;
                border-radius: 50px;
                font-weight: 600;
                cursor: pointer;
                transition: var(--transition);
                font-family: 'Poppins', sans-serif;
                font-size: 0.9rem;
            }

            .modal-btn-cancel {
                background: #f1f5f9;
                color: var(--text-medium);
            }

            .modal-btn-cancel:hover {
                background: #e2e8f0;
            }

            .modal-btn-confirm {
                background: var(--plp-green-gradient);
                color: white;
            }

            .modal-btn-confirm:hover {
                transform: translateY(-2px);
            }

            /* Alert styles */
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
                font-size: 0.9rem;
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
                font-size: 0.9rem;
            }

            /* NEW: Attendance-specific styles */
            .attendance-status {
                display: flex;
                gap: 1rem;
                margin-bottom: 1rem;
            }

            .radio-group {
                display: flex;
                gap: 1rem;
            }

            .radio-option {
                display: flex;
                align-items: center;
                gap: 0.5rem;
                cursor: pointer;
                font-size: 0.9rem;
            }

            .radio-option input[type="radio"] {
                margin: 0;
            }

            .attendance-badge {
                display: inline-block;
                padding: 0.3rem 0.8rem;
                border-radius: 15px;
                font-size: 0.75rem;
                font-weight: 600;
                text-align: center;
            }

            .attendance-badge.present {
                background: #c6f6d5;
                color: #2f855a;
            }

            .attendance-badge.absent {
                background: #fed7d7;
                color: #c53030;
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
                    padding: 1rem;
                }
                
                .header {
                    flex-direction: column;
                    gap: 1rem;
                    text-align: center;
                }
                
                .section-header {
                    flex-direction: column;
                    gap: 1rem;
                    align-items: flex-start;
                }
                
                .category-grid {
                    grid-template-columns: 1fr;
                }
                
                .management-grid {
                    grid-template-columns: 1fr;
                }
                
                .performance-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .insights-tabs {
                    flex-direction: column;
                }
                
                .allocation-info {
                    flex-direction: column;
                    gap: 0.5rem;
                    align-items: flex-start;
                }
            }

            @media (max-width: 480px) {
                .performance-grid {
                    grid-template-columns: 1fr;
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
                <div class="class-title">
                    <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                </div>
                <div class="header-actions">
                    <a href="student-subjects.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- Simplified Performance Overview -->
            <div class="performance-overview">
                <div class="performance-grid">
                    <div class="performance-card">
                        <div class="performance-label">Final Grade</div>
                        <?php if ($hasScores): ?>
                            <div class="performance-value"><?php echo number_format($overallGrade, 1); ?>%</div>
                            <div class="risk-badge <?php echo $riskLevel; ?>">
                                <?php echo $riskDescription; ?>
                            </div>
                        <?php else: ?>
                            <div class="performance-value" style="color: var(--text-light);">--</div>
                            <div class="risk-badge no-data">
                                No Data
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="performance-card">
                        <div class="performance-label">GPA</div>
                        <?php if ($hasScores): ?>
                            <div class="performance-value"><?php echo number_format($gpa, 2); ?></div>
                                <div class="performance-label">
                                    <?php 
                                    if ($gpa == 1.00) echo 'Excellent Performance';
                                    elseif ($gpa <= 1.50) echo 'Good Performance';
                                    elseif ($gpa <= 1.75) echo 'Need Improvement';
                                    elseif ($gpa <= 2.50) echo 'Need Improvement';
                                    elseif ($gpa <= 2.75) echo 'Consult w/ professor';
                                    elseif ($gpa == 3.00) echo 'Consult w/ professor';
                                    else echo 'Consult';
                                    ?>
                                </div>
                        <?php else: ?>
                            <div class="performance-value" style="color: var(--text-light);">--</div>
                            <div class="performance-label">No GPA calculated</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="performance-card">
                        <div class="performance-label">Class Standing</div>
                        <?php if ($hasScores): ?>
                            <div class="performance-value"><?php echo number_format($totalClassStanding, 1); ?>%</div>
                            <div class="performance-label">of <?php echo $totalClassStandingPercentage; ?>%</div>
                        <?php else: ?>
                            <div class="performance-value" style="color: var(--text-light);">--</div>
                            <div class="performance-label">No scores added</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="performance-card">
                        <div class="performance-label">Exams</div>
                        <?php if ($hasScores): ?>
                            <div class="performance-value"><?php echo number_format($midtermScore + $finalScore, 1); ?>%</div>
                            <div class="performance-label">of 40%</div>
                        <?php else: ?>
                            <div class="performance-value" style="color: var(--text-light);">--</div>
                            <div class="performance-label">No exam scores</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Class Standing Categories Section -->
            <div class="category-section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-layer-group"></i>
                        Class Standing Categories
                    </div>
                    <?php if ($canAddCategory): ?>
                        <div class="allocation-info">
                            <span class="remaining-allocation">
                                <i class="fas fa-percentage"></i>
                                Remaining: <strong><?php echo $remainingAllocation; ?>%</strong>
                            </span>
                            <button class="btn btn-primary" onclick="openAddCategoryModal()">
                                <i class="fas fa-plus-circle"></i> Add Category
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="allocation-full">
                            <i class="fas fa-check-circle"></i> Allocation Full (60%)
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($categories)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open" style="font-size: 2.5rem; color: var(--text-light); margin-bottom: 1rem;"></i>
                        <h3 style="color: var(--text-dark); margin-bottom: 0.5rem; font-size: 1.1rem;">No Categories Created</h3>
                        <?php if ($canAddCategory): ?>
                            <button class="btn btn-primary" onclick="openAddCategoryModal()">
                                <i class="fas fa-plus-circle"></i> Create First Category
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="category-grid">
                        <?php foreach ($categories as $category): ?>
                            <?php 
                            $categoryScores = array_filter($classStandings, function($score) use ($category) {
                                return $score['category_id'] == $category['id'];
                            });
                            $hasScores = !empty($categoryScores);
                            $categoryTotal = $categoryTotals[$category['id']] ?? null;
                            $isAttendance = strtolower($category['category_name']) === 'attendance';
                            ?>
                            
                            <div class="category-card <?php echo $hasScores ? 'has-scores' : 'no-scores'; ?>">
                                <div class="category-card-header">
                                    <div class="category-info">
                                        <div class="category-name">
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                            <?php if ($isAttendance): ?>
                                                <i class="fas fa-user-check" style="margin-left: 0.5rem; color: var(--plp-green);"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="category-percentage-badge">
                                        <?php echo $category['category_percentage']; ?>%
                                    </div>
                                </div>
                                
                                <div class="category-content">
                                    <div class="scores-section">                              
                                        <div class="scores-list">
                                            <?php if (empty($categoryScores)): ?>
                                                <div class="empty-scores">
                                                    <i class="fas fa-clipboard-list" style="font-size: 1.5rem; color: var(--text-light); margin-bottom: 0.5rem;"></i>
                                                    <p style="color: var(--text-light); margin-bottom: 0.25rem; font-weight: 500; font-size: 0.9rem;">No scores added yet</p>
                                                    <small style="color: var(--text-light); font-size: 0.8rem;">Click "Add Score" to start tracking</small>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($categoryScores as $score): ?>
                                                    <div class="score-item">
                                                        <div class="score-main">
                                                            <div class="score-name"><?php echo htmlspecialchars($score['score_name']); ?></div>
                                                            <div class="score-value">
                                                                <?php if ($isAttendance): ?>
                                                                    <span class="attendance-badge <?php echo strtolower($score['score_name']); ?>">
                                                                        <?php echo $score['score_name']; ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="actual-score"><?php echo $score['score_value']; ?></span>
                                                                    <span class="score-separator">/</span>
                                                                    <span class="max-score"><?php echo $score['max_score']; ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="score-details">
                                                            <?php if ($score['score_date']): ?>
                                                                <div class="score-date">
                                                                    <i class="fas fa-calendar"></i>
                                                                    <?php echo date('M j, Y', strtotime($score['score_date'])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="score-actions">
                                                                <?php if (!$isAttendance): ?>
                                                                    <button class="action-btn edit-btn" onclick="event.stopPropagation(); openEditModal(<?php echo $score['id']; ?>, '<?php echo htmlspecialchars($score['score_name']); ?>', <?php echo $score['score_value']; ?>, <?php echo $score['max_score']; ?>)">
                                                                        <i class="fas fa-edit"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="delete_score" value="1">
                                                                    <input type="hidden" name="score_id" value="<?php echo $score['id']; ?>">
                                                                    <button type="submit" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this score?')">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="category-actions">
                                    <?php if ($isAttendance): ?>
                                        <button class="btn-primary" onclick="openAddAttendanceModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>')">
                                            <i class="fas fa-plus"></i> Add Attendance
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-primary" onclick="openAddScoreModal(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>')">
                                            <i class="fas fa-plus"></i> Add Score
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Major Exams Section -->
            <div class="standings-section">
                <div class="section-title">
                    <i class="fas fa-layer-group"></i>
                    Major Exams
                </div>
                <br>
                <div class="management-grid">
                    <!-- Midterm Exam -->
                    <div class="management-card major-exam-card" onclick="openExamModal('midterm_exam')">
                        <h3>MIDTERM EXAM</h3>
                        <div class="major-exam-badge">20%</div>
                        <?php if (!empty($midtermExam)): ?>
                            <?php 
                            $midterm = reset($midtermExam);
                            // Ensure we don't have duplicate entries
                            if (is_array($midterm) && isset($midterm['score_name']) && $midterm['score_name'] !== 'midterm_exam_exam'):
                            ?>
                                <p style="color: var(--text-medium); margin-top: 0.5rem; font-size: 0.9rem;">
                                    Score: <?php echo $midterm['score_value']; ?>/<?php echo $midterm['max_score']; ?>
                                </p>
                                <p style="color: var(--text-light); font-size: 0.75rem; margin-top: 0.3rem;">
                                    Weighted: <?php echo number_format($midtermScore, 1); ?>%
                                </p>
                            <?php else: ?>
                                <p style="color: var(--text-light); margin-top: 0.5rem; font-size: 0.9rem;">
                                    Click to add score
                                </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="color: var(--text-light); margin-top: 0.5rem; font-size: 0.9rem;">
                                Click to add score
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Final Exam -->
                    <div class="management-card major-exam-card" onclick="openExamModal('final_exam')">
                        <h3>FINAL EXAM</h3>
                        <div class="major-exam-badge">20%</div>
                        <?php if (!empty($finalExam)): ?>
                            <?php 
                            $final = reset($finalExam);
                            // Ensure we don't have duplicate entries
                            if (is_array($final) && isset($final['score_name']) && $final['score_name'] !== 'final_exam_exam'):
                            ?>
                                <p style="color: var(--text-medium); margin-top: 0.5rem; font-size: 0.9rem;">
                                    Score: <?php echo $final['score_value']; ?>/<?php echo $final['max_score']; ?>
                                </p>
                                <p style="color: var(--text-light); font-size: 0.75rem; margin-top: 0.3rem;">
                                    Weighted: <?php echo number_format($finalScore, 1); ?>%
                                </p>
                            <?php else: ?>
                                <p style="color: var(--text-light); margin-top: 0.5rem; font-size: 0.9rem;">
                                    Click to add score
                                </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="color: var(--text-light); margin-top: 0.5rem; font-size: 0.9rem;">
                                Click to add score
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($hasScores): ?>
                <div class="insights-section">
                    <div class="insights-tabs">
                        <button class="insight-tab active" data-tab="insights">
                            <i class="fas fa-lightbulb"></i> Behavioral
                        </button>
                        <button class="insight-tab" data-tab="interventions">
                            <i class="fas fa-hands-helping"></i> Intervention
                        </button>
                        <button class="insight-tab" data-tab="recommendations">
                            <i class="fas fa-graduation-cap"></i> Recommendations
                        </button>
                    </div>

                    <!-- Behavioral Insights Tab -->
                    <div class="insight-content active" id="insights-tab">
                        <?php if (!empty($behavioralInsights)): ?>
                            <ul class="insight-list">
                                <?php foreach ($behavioralInsights as $insight): ?>
                                    <li class="insight-item">
                                        <i class="fas fa-lightbulb insight-icon"></i>
                                        <div class="insight-text">
                                            <?php echo htmlspecialchars($insight['message']); ?>
                                            <?php if (isset($insight['priority'])): ?>
                                                <span class="insight-priority priority-<?php echo $insight['priority']; ?>">
                                                    <?php echo ucfirst($insight['priority']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-scores">
                                <p>No behavioral insights available yet.</p>
                                <small>Continue adding scores to generate insights.</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Interventions Tab -->
                    <div class="insight-content" id="interventions-tab">
                        <?php if (!empty($interventions)): ?>
                            <ul class="insight-list">
                                <?php foreach ($interventions as $intervention): ?>
                                    <li class="insight-item">
                                        <i class="fas fa-tasks insight-icon"></i>
                                        <div class="insight-text">
                                            <?php echo htmlspecialchars($intervention['message']); ?>
                                            <?php if (isset($intervention['priority'])): ?>
                                                <span class="insight-priority priority-<?php echo $intervention['priority']; ?>">
                                                    <?php echo ucfirst($intervention['priority']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-scores">
                                <p>No interventions needed at this time.</p>
                                <small>Your performance is on track!</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recommendations Tab -->
                    <div class="insight-content" id="recommendations-tab">
                        <?php if (!empty($recommendations)): ?>
                            <ul class="insight-list">
                                <?php foreach ($recommendations as $recommendation): ?>
                                    <li class="insight-item">
                                        <i class="fas fa-bullseye insight-icon"></i>
                                        <div class="insight-text">
                                            <?php echo htmlspecialchars($recommendation['message']); ?>
                                            <?php if (isset($recommendation['priority'])): ?>
                                                <span class="insight-priority priority-<?php echo $recommendation['priority']; ?>">
                                                    <?php echo ucfirst($recommendation['priority']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-scores">
                                <p>No recommendations available yet.</p>
                                <small>Continue adding scores to get personalized recommendations.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Add Category Modal -->
        <?php if ($canAddCategory): ?>
            <div class="modal" id="addCategoryModal">
                <div class="modal-content">
                    <span class="close" onclick="closeAddCategoryModal()">&times;</span>
                    <h3 class="modal-title">Add Class Standing Category</h3>
                    <form id="categoryForm" method="POST">
                        <input type="hidden" name="add_category" value="1">
                        
                        <div class="form-group">
                            <label for="category_name" class="form-label">Category Name</label>
                            <input type="text" id="category_name" name="category_name" class="form-input" required placeholder="ex. Quizzes, Assignments, Projects, Attendance">
                        </div>
                        
                        <div class="form-group">
                            <label for="category_percentage" class="form-label">Percentage Weight</label>
                            <input type="number" id="category_percentage" name="category_percentage" class="form-input" min="1" max="<?php echo 60 - $totalClassStandingPercentage; ?>" required placeholder="Enter percentage">
                            <p style="text-align: left; margin-top: 0.5rem; color: var(--text-medium); font-size: 0.85rem;">
                                Remaining allocation: <?php echo 60 - $totalClassStandingPercentage; ?>%
                            </p>
                        </div>
                        
                        <div class="modal-actions">
                            <button type="button" class="modal-btn modal-btn-cancel" onclick="closeAddCategoryModal()">Cancel</button>
                            <button type="submit" class="modal-btn modal-btn-confirm">Add Category</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Add Score Modal -->
        <div class="modal" id="addScoreModal">
            <div class="modal-content">
                <span class="close" onclick="closeAddScoreModal()">&times;</span>
                <h3 class="modal-title" id="addScoreModalTitle">Add Score</h3>
                <form id="scoreForm" method="POST">
                    <input type="hidden" name="add_standing" value="1">
                    <input type="hidden" name="category_id" id="scoreCategoryId">
                    
                    <div class="form-group">
                        <label for="score_name" class="form-label">Score Name</label>
                        <input type="text" id="score_name" name="score_name" class="form-input" required placeholder="ex. Quiz 1, Assignment 2, Project Proposal">
                    </div>
                    
                    <div class="form-group">
                        <label for="score_value" class="form-label">Your Score</label>
                        <input type="number" id="score_value" name="score_value" class="form-input" min="0" step="0.1" required placeholder="Enter your score">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_score" class="form-label">Maximum Score</label>
                        <input type="number" id="max_score" name="max_score" class="form-input" min="1" step="0.1" required placeholder="Enter total possible score">
                    </div>
                    
                    <div class="form-group">
                        <label for="score_date" class="form-label">Date</label>
                        <input type="date" id="score_date" name="score_date" class="form-input" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="modal-btn modal-btn-cancel" onclick="closeAddScoreModal()">Cancel</button>
                        <button type="submit" class="modal-btn modal-btn-confirm">Add Score</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Attendance Modal -->
        <div class="modal" id="addAttendanceModal">
            <div class="modal-content">
                <span class="close" onclick="closeAddAttendanceModal()">&times;</span>
                <h3 class="modal-title" id="addAttendanceModalTitle">Add Attendance</h3>
                <form id="attendanceForm" method="POST">
                    <input type="hidden" name="add_attendance" value="1">
                    <input type="hidden" name="category_id" id="attendanceCategoryId">
                    
                    <div class="form-group">
                        <label for="attendance_date" class="form-label">Date</label>
                        <input type="date" id="attendance_date" name="attendance_date" class="form-input" max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <div class="attendance-status">
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="attendance_status" value="present" checked>
                                    <span>Present</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="attendance_status" value="absent">
                                    <span>Absent</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="modal-btn modal-btn-cancel" onclick="closeAddAttendanceModal()">Cancel</button>
                        <button type="submit" class="modal-btn modal-btn-confirm">Add Attendance</button>
                    </div>
                </form>
            </div>
        </div>
        

        <!-- Edit Score Modal -->
        <div class="modal" id="editScoreModal">
            <div class="modal-content">
                <span class="close" id="closeEditModal">&times;</span>
                <h3 class="modal-title">Update Score</h3>
                <form id="editScoreForm" method="POST">
                    <input type="hidden" name="update_score" value="1">
                    <input type="hidden" name="score_id" id="editScoreId">
                    
                    <div class="form-group">
                        <label for="edit_score_name" class="form-label">Score Name</label>
                        <input type="text" id="edit_score_name" class="form-input" readonly style="background: #f5f5f5;">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_score_value" class="form-label">Your Score</label>
                        <input type="number" id="edit_score_value" name="score_value" class="form-input" min="0" step="0.1" required>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="modal-btn modal-btn-cancel" id="cancelEdit">Cancel</button>
                        <button type="submit" class="modal-btn modal-btn-confirm">Update Score</button>
                    </div>
                </form>
                
                <form id="deleteScoreForm" method="POST" style="margin-top: 1rem; border-top: 1px solid #eee; padding-top: 1rem;">
                    <input type="hidden" name="delete_score" value="1">
                    <input type="hidden" name="score_id" id="deleteScoreId">
                    <button type="submit" class="modal-btn" style="background: var(--danger); color: white; width: 100%;" onclick="return confirm('Are you sure you want to delete this score?')">
                        Delete Score
                    </button>
                </form>
            </div>
        </div>

        <!-- Add Exam Score Modal -->
        <div class="modal" id="examModal">
            <div class="modal-content">
                <span class="close" id="closeExamModal">&times;</span>
                <h3 class="modal-title" id="examModalTitle">Add Exam Score</h3>
                <form id="examForm" method="POST">
                    <input type="hidden" name="add_exam" value="1">
                    <input type="hidden" name="exam_type" id="examType">
                    
                    <div class="form-group">
                        <label for="exam_score" class="form-label">Your Exam Score</label>
                        <input type="number" id="exam_score" name="score_value" class="form-input" min="0" step="0.1" required placeholder="Enter your score">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_score" class="form-label">Maximum Score</label>
                        <input type="number" id="max_score" name="max_score" class="form-input" min="1" step="0.1" required placeholder="Enter total possible score">
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="modal-btn modal-btn-cancel" id="cancelExam">Cancel</button>
                        <button type="submit" class="modal-btn modal-btn-confirm">Save Score</button>
                    </div>
                </form>
            </div>
        </div>

    <script>
        // Set today's date as default for score date and disable future dates
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            
            // Set today as default value and max date
            const dateInputs = ['score_date', 'attendance_date'];
            dateInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.value = today;
                    input.max = today;
                }
            });

            // Tab functionality
            const tabs = document.querySelectorAll('.insight-tab');
            const contents = document.querySelectorAll('.insight-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    contents.forEach(c => c.classList.remove('active'));

                    // Add active class to clicked tab
                    tab.classList.add('active');

                    // Show corresponding content
                    const tabId = tab.getAttribute('data-tab');
                    document.getElementById(`${tabId}-tab`).classList.add('active');
                });
            });
        });

        function openAddCategoryModal() {
            document.getElementById('addCategoryModal').classList.add('show');
        }

        function closeAddCategoryModal() {
            document.getElementById('addCategoryModal').classList.remove('show');
        }

        function openAddScoreModal(categoryId, categoryName) {
            document.getElementById('scoreCategoryId').value = categoryId;
            document.getElementById('addScoreModalTitle').textContent = 'Add Score to ' + categoryName;
            
            // Reset date to today and set max
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('score_date').value = today;
            document.getElementById('score_date').max = today;
            
            document.getElementById('addScoreModal').classList.add('show');
        }

        function closeAddScoreModal() {
            document.getElementById('addScoreModal').classList.remove('show');
        }

        function openAddAttendanceModal(categoryId, categoryName) {
            document.getElementById('attendanceCategoryId').value = categoryId;
            document.getElementById('addAttendanceModalTitle').textContent = 'Add Attendance to ' + categoryName;
            
            // Reset date to today and set max
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('attendance_date').value = today;
            document.getElementById('attendance_date').max = today;
            
            document.getElementById('addAttendanceModal').classList.add('show');
        }

        function closeAddAttendanceModal() {
            document.getElementById('addAttendanceModal').classList.remove('show');
        }

        function openEditModal(scoreId, scoreName, scoreValue, maxScore) {
            document.getElementById('editScoreId').value = scoreId;
            document.getElementById('deleteScoreId').value = scoreId;
            document.getElementById('edit_score_name').value = scoreName;
            document.getElementById('edit_score_value').value = scoreValue;
            document.getElementById('edit_score_value').max = maxScore;
            
            document.getElementById('editScoreModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editScoreModal').classList.remove('show');
        }

        function openExamModal(examType) {
            document.getElementById('examType').value = examType;
            document.getElementById('examModalTitle').textContent = 
                examType === 'midterm_exam' ? 'Add Midterm Exam Score' : 'Add Final Exam Score';
            
            // Reset form values first
            document.getElementById('exam_score').value = '';
            document.getElementById('max_score').value = '';
            
            <?php if (!empty($midtermExam)): ?>
            if (examType === 'midterm_exam') {
                const midterm = <?php echo json_encode(reset($midtermExam)); ?>;
                // Only populate if it's a valid exam entry (not a duplicate)
                if (midterm && midterm.score_name && midterm.score_name !== 'midterm_exam_exam') {
                    document.getElementById('exam_score').value = midterm.score_value;
                    document.getElementById('max_score').value = midterm.max_score;
                }
            }
            <?php endif; ?>
            
            <?php if (!empty($finalExam)): ?>
            if (examType === 'final_exam') {
                const final = <?php echo json_encode(reset($finalExam)); ?>;
                if (final && final.score_name && final.score_name !== 'final_exam_exam') {
                    document.getElementById('exam_score').value = final.score_value;
                    document.getElementById('max_score').value = final.max_score;
                }
            }
            <?php endif; ?>
            
            document.getElementById('examModal').classList.add('show');
        }

        function closeExamModal() {
            document.getElementById('examModal').classList.remove('show');
        }

        // Close modals when clicking outside
        window.addEventListener('click', (e) => {
            <?php if ($canAddCategory): ?>
            if (e.target === document.getElementById('addCategoryModal')) {
                closeAddCategoryModal();
            }
            <?php endif; ?>
            if (e.target === document.getElementById('addScoreModal')) {
                closeAddScoreModal();
            }
            if (e.target === document.getElementById('addAttendanceModal')) {
                closeAddAttendanceModal();
            }
            if (e.target === document.getElementById('editScoreModal')) {
                closeEditModal();
            }
            if (e.target === document.getElementById('examModal')) {
                closeExamModal();
            }
        });

        // Close modal event listeners
        document.getElementById('closeEditModal').addEventListener('click', closeEditModal);
        document.getElementById('cancelEdit').addEventListener('click', closeEditModal);
        document.getElementById('closeExamModal').addEventListener('click', closeExamModal);
        document.getElementById('cancelExam').addEventListener('click', closeExamModal);

        // Form validation
        document.getElementById('scoreForm').addEventListener('submit', function(e) {
            const scoreValue = parseFloat(document.getElementById('score_value').value);
            const maxScore = parseFloat(document.getElementById('max_score').value);
            
            if (scoreValue > maxScore) {
                e.preventDefault();
                alert('Score value cannot exceed maximum score.');
                return;
            }
        });

        document.getElementById('editScoreForm').addEventListener('submit', function(e) {
            const scoreValue = parseFloat(document.getElementById('edit_score_value').value);
            const maxScore = parseFloat(document.getElementById('edit_score_value').max);
            
            if (scoreValue > maxScore) {
                e.preventDefault();
                alert('Score value cannot exceed maximum score of ' + maxScore);
            }
        });

        document.getElementById('examForm').addEventListener('submit', function(e) {
            const examScore = parseFloat(document.getElementById('exam_score').value);
            const maxScore = parseFloat(document.getElementById('max_score').value);
            
            if (examScore < 0 || maxScore <= 0) {
                e.preventDefault();
                alert('Score value and maximum score must be positive numbers.');
            } else if (examScore > maxScore) {
                e.preventDefault();
                alert('Score value cannot exceed maximum score.');
            }
        });

        document.getElementById('categoryForm').addEventListener('submit', function(e) {
            const percentage = parseFloat(document.getElementById('category_percentage').value);
            const remaining = <?php echo $remainingAllocation; ?>;
            
            if (percentage > remaining) {
                e.preventDefault();
                alert('Cannot add category. Percentage exceeds remaining allocation of ' + remaining + '%.');
            }
        });

        // Real-time validation for category percentage input
        document.getElementById('category_percentage').addEventListener('input', function() {
            const percentage = parseFloat(this.value) || 0;
            const remaining = <?php echo $remainingAllocation; ?>;
            
            if (percentage > remaining) {
                this.style.borderColor = 'var(--danger)';
                this.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
            } else {
                this.style.borderColor = '';
                this.style.boxShadow = '';
            }
        });
    </script>
    </body>
    </html>