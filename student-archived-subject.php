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

// Get student information from database
$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Fetch student data
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND email = ?");
$stmt->execute([$userId, $userEmail]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $_SESSION['error_message'] = "Student account not found";
    header('Location: login.php');
    exit;
}

$success_message = '';
$error_message = '';

// Handle subject restoration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_subject'])) {
    $archived_subject_id = $_POST['archived_subject_id'];
    
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Get the archived subject details
        $get_archived_stmt = $pdo->prepare("
            SELECT * FROM archived_subjects 
            WHERE id = ? AND student_id = ?
        ");
        $get_archived_stmt->execute([$archived_subject_id, $student['id']]);
        $archived_subject = $get_archived_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$archived_subject) {
            throw new Exception("Archived subject not found.");
        }
        
        // Check if subject already exists in active subjects
        $check_stmt = $pdo->prepare("
            SELECT id FROM student_subjects 
            WHERE student_id = ? AND subject_id = ?
        ");
        $check_stmt->execute([$student['id'], $archived_subject['subject_id']]);
        
        if ($check_stmt->fetch()) {
            throw new Exception("This subject is already in your active subjects.");
        }
        
        // Restore to student_subjects
        $restore_stmt = $pdo->prepare("
            INSERT INTO student_subjects (student_id, subject_id, professor_name, schedule) 
            VALUES (?, ?, ?, ?)
        ");
        $restore_stmt->execute([
            $archived_subject['student_id'],
            $archived_subject['subject_id'],
            $archived_subject['professor_name'],
            $archived_subject['schedule']
        ]);
        
        $restored_subject_id = $pdo->lastInsertId();
        
        // Get archived categories
        $archived_categories_stmt = $pdo->prepare("
            SELECT * FROM archived_class_standing_categories 
            WHERE archived_subject_id = ?
        ");
        $archived_categories_stmt->execute([$archived_subject_id]);
        $archived_categories = $archived_categories_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $category_mapping = [];
        
        // Restore categories
        foreach ($archived_categories as $archived_category) {
            $restore_category_stmt = $pdo->prepare("
                INSERT INTO student_class_standing_categories 
                (student_subject_id, category_name, category_percentage) 
                VALUES (?, ?, ?)
            ");
            $restore_category_stmt->execute([
                $restored_subject_id,
                $archived_category['category_name'],
                $archived_category['category_percentage']
            ]);
            
            $new_category_id = $pdo->lastInsertId();
            $category_mapping[$archived_category['id']] = $new_category_id;
        }
        
        // Restore scores
        foreach ($category_mapping as $old_category_id => $new_category_id) {
            $archived_scores_stmt = $pdo->prepare("
                SELECT * FROM archived_subject_scores 
                WHERE archived_category_id = ?
            ");
            $archived_scores_stmt->execute([$old_category_id]);
            $archived_scores = $archived_scores_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($archived_scores as $score) {
                // For exam scores, category_id should be NULL
                $category_id_for_score = (strpos($score['score_type'], 'exam') !== false) ? NULL : $new_category_id;
                
                $restore_score_stmt = $pdo->prepare("
                    INSERT INTO student_subject_scores 
                    (student_subject_id, category_id, score_type, score_name, score_value, max_score, score_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $restore_score_stmt->execute([
                    $restored_subject_id,
                    $category_id_for_score,
                    $score['score_type'],
                    $score['score_name'],
                    $score['score_value'],
                    $score['max_score'],
                    $score['score_date']
                ]);
            }
        }
        
        // Restore performance data if exists
        $archived_performance_stmt = $pdo->prepare("
            SELECT * FROM archived_subject_performance 
            WHERE archived_subject_id = ?
        ");
        $archived_performance_stmt->execute([$archived_subject_id]);
        $archived_performance = $archived_performance_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($archived_performance) {
            // Ensure subject_performance table exists
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS subject_performance (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        student_subject_id INT NOT NULL,
                        overall_grade DECIMAL(5,2) DEFAULT 0,
                        gpa DECIMAL(3,2) DEFAULT 0,
                        class_standing DECIMAL(5,2) DEFAULT 0,
                        exams_score DECIMAL(5,2) DEFAULT 0,
                        risk_level VARCHAR(20) DEFAULT 'no-data',
                        risk_description VARCHAR(255) DEFAULT 'No Data Inputted',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (student_subject_id) REFERENCES student_subjects(id) ON DELETE CASCADE
                    )
                ");
                
                $restore_performance_stmt = $pdo->prepare("
                    INSERT INTO subject_performance 
                    (student_subject_id, overall_grade, gpa, class_standing, exams_score, risk_level, risk_description) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $restore_performance_stmt->execute([
                    $restored_subject_id,
                    $archived_performance['overall_grade'],
                    $archived_performance['gpa'],
                    $archived_performance['class_standing'],
                    $archived_performance['exams_score'],
                    $archived_performance['risk_level'],
                    $archived_performance['risk_description']
                ]);
            } catch (PDOException $e) {
                // Silently continue if performance table operations fail
                error_log("Performance restoration skipped: " . $e->getMessage());
            }
        }
        
        // Delete from archived tables
        $delete_scores_stmt = $pdo->prepare("
            DELETE FROM archived_subject_scores 
            WHERE archived_category_id IN (
                SELECT id FROM archived_class_standing_categories WHERE archived_subject_id = ?
            )
        ");
        $delete_scores_stmt->execute([$archived_subject_id]);
        
        $delete_categories_stmt = $pdo->prepare("
            DELETE FROM archived_class_standing_categories 
            WHERE archived_subject_id = ?
        ");
        $delete_categories_stmt->execute([$archived_subject_id]);
        
        $delete_performance_stmt = $pdo->prepare("
            DELETE FROM archived_subject_performance 
            WHERE archived_subject_id = ?
        ");
        $delete_performance_stmt->execute([$archived_subject_id]);
        
        $delete_subject_stmt = $pdo->prepare("
            DELETE FROM archived_subjects 
            WHERE id = ?
        ");
        $delete_subject_stmt->execute([$archived_subject_id]);
        
        // Commit transaction
        $pdo->commit();
        
        $success_message = 'Subject restored successfully with all records!';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = 'Error restoring subject: ' . $e->getMessage();
    }
}

// Fetch archived subjects with calculated performance data
try {
    $archived_subjects_stmt = $pdo->prepare("
        SELECT 
            a.*, 
            s.subject_code, 
            s.subject_name, 
            s.credits, 
            s.semester,
            p.overall_grade,
            p.gpa,
            p.class_standing,
            p.exams_score,
            p.risk_level,
            p.risk_description,
            CASE 
                WHEN p.overall_grade IS NOT NULL AND p.overall_grade > 0 THEN 1 
                ELSE 0 
            END as has_scores
        FROM archived_subjects a
        JOIN subjects s ON a.subject_id = s.id
        LEFT JOIN archived_subject_performance p ON a.id = p.archived_subject_id
        WHERE a.student_id = ?
        ORDER BY a.archived_at DESC
    ");
    $archived_subjects_stmt->execute([$student['id']]);
    $archived_subjects = $archived_subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no performance data exists in archived_subject_performance, calculate it from scores
    foreach ($archived_subjects as &$subject) {
        if (!$subject['has_scores']) {
            // Calculate performance from archived scores
            $calculated_performance = calculateArchivedSubjectPerformance($subject['id'], $pdo);
            if ($calculated_performance) {
                $subject['overall_grade'] = $calculated_performance['overall_grade'];
                $subject['gpa'] = $calculated_performance['gpa'];
                $subject['class_standing'] = $calculated_performance['class_standing'];
                $subject['exams_score'] = $calculated_performance['exams_score'];
                $subject['risk_level'] = $calculated_performance['risk_level'];
                $subject['risk_description'] = $calculated_performance['risk_description'];
                $subject['has_scores'] = $calculated_performance['has_scores'];
            }
        }
    }
    unset($subject); // break the reference
    
    $total_archived = count($archived_subjects);
    
} catch (PDOException $e) {
    $archived_subjects = [];
    $total_archived = 0;
    error_log("Error fetching archived subjects: " . $e->getMessage());
}

/**
 * Calculate performance for archived subject from scores
 */
function calculateArchivedSubjectPerformance($archived_subject_id, $pdo) {
    try {
        // Get all categories for this archived subject
        $categories_stmt = $pdo->prepare("
            SELECT * FROM archived_class_standing_categories 
            WHERE archived_subject_id = ?
        ");
        $categories_stmt->execute([$archived_subject_id]);
        $categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($categories)) {
            return null;
        }
        
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
        $hasScores = false;
        
        // Calculate class standing from categories
        foreach ($categories as $category) {
            $scores_stmt = $pdo->prepare("
                SELECT * FROM archived_subject_scores 
                WHERE archived_category_id = ? AND score_type = 'class_standing'
            ");
            $scores_stmt->execute([$category['id']]);
            $scores = $scores_stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
        $exam_categories_stmt = $pdo->prepare("
            SELECT ac.id FROM archived_class_standing_categories ac
            JOIN archived_subject_scores ass ON ac.id = ass.archived_category_id
            WHERE ac.archived_subject_id = ? AND ass.score_type IN ('midterm_exam', 'final_exam')
            GROUP BY ac.id
        ");
        $exam_categories_stmt->execute([$archived_subject_id]);
        $exam_categories = $exam_categories_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($exam_categories as $exam_category) {
            $exam_scores_stmt = $pdo->prepare("
                SELECT * FROM archived_subject_scores 
                WHERE archived_category_id = ? AND score_type IN ('midterm_exam', 'final_exam')
            ");
            $exam_scores_stmt->execute([$exam_category['id']]);
            $exam_scores = $exam_scores_stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
        
        // Calculate GPA and risk level - USE THE SAME LOGIC AS subject-management.php
        $gpa = 0;
        $riskLevel = 'no-data';
        $riskDescription = 'No Data Inputted';
        
        // This is the same GPA calculation as in subject-management.php
        if ($overallGrade >= 89) {
            $gpa = 1.00; // Low Risk
        } elseif ($overallGrade >= 82) {
            $gpa = 2.00; // Medium Risk  
        } elseif ($overallGrade >= 79) {
            $gpa = 2.75; // Medium Risk
        } else {
            $gpa = 3.00; // High Risk
        }

        // Calculate risk level based on GPA - same as subject-management.php
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
        
    } catch (PDOException $e) {
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
            overflow: hidden; /* Prevent body scroll */
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
            overflow-y: auto; /* Allow sidebar scrolling if needed */
        }

        /* Hide scrollbar for Chrome, Safari and Opera */
        .sidebar::-webkit-scrollbar,
        .modal-content::-webkit-scrollbar,
        body::-webkit-scrollbar {
            display: none;
        }

        /* Hide scrollbar for IE, Edge and Firefox */
        .sidebar,
        .modal-content,
        body {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
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
            overflow-y: auto; /* Allow main content scrolling */
            height: 100vh; /* Fixed height to prevent body scroll */
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
            overflow-y: auto; /* Allow modal content scrolling */
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
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .modal-btn-confirm:hover {
            transform: translateY(-2px);
        }

        /* Logout Modal Styles */
        .logout-modal {
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

        .logout-modal.show {
            display: flex;
            opacity: 1;
        }

        .logout-modal-content {
            background: white;
            padding: 2.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            max-width: 450px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            text-align: center;
        }

        .logout-modal.show .logout-modal-content {
            transform: translateY(0);
        }

        .logout-modal-icon {
            font-size: 3rem;
            color: var(--plp-green);
            margin-bottom: 1rem;
        }

        .logout-modal-title {
            color: var(--plp-green);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .logout-modal-message {
            color: var(--text-medium);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .logout-modal-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .logout-modal-btn {
            font-size: 1rem;
            font-weight: 600;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            min-width: 120px;
        }

        .logout-modal-cancel {
            background: #f1f5f9;
            color: var(--text-medium);
            border: 2px solid transparent;
        }

        .logout-modal-cancel:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .logout-modal-confirm {
            background: var(--plp-green-gradient);
            color: white;
            border: 2px solid transparent;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .logout-modal-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
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
            animation: slideIn 0.3s ease;
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
            animation: slideIn 0.3s ease;
        }

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Cards */
        .card {
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        /* Improved Card Header */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
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

        /* Buttons */
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
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Improved Subject Actions */
        .subject-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            pointer-events: auto;
            z-index: 2;
            position: relative;
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

        /* Subjects Grid */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 0.10rem;
        }

        /* Subject Card Improvements */
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
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--plp-green);
            font-weight: 600;
        }

        .credits {
            font-size: 0.85rem;
            color: var(--plp-green);
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
            color: var(--plp-green);
            width: 14px;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .info-item span {
            flex: 1;
        }

        /* Status badge */
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

        .archived-date {
            font-size: 0.75rem;
            color: var(--text-light);
            margin-top: 0.5rem;
            font-style: italic;
        }

        .semester-badge {
            font-size: 0.8rem;
            color: var(--plp-green);
            background: var(--plp-green-lighter);
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-weight: 500;
        }

        /* View Details Modal */
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
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

        /* Risk Badge Colors */
        .risk-badge.low {
            background: #c6f6d5 !important;
            color: #2f855a !important;
        }

        .risk-badge.medium {
            background: #fef5e7 !important;
            color: #d69e2e !important;
        }

        .risk-badge.high {
            background: #fed7d7 !important;
            color: #c53030 !important;
        }

        .risk-badge.failed {
            background: #fed7d7 !important;
            color: #c53030 !important;
        }

        .risk-badge.no-data {
            background: #e2e8f0 !important;
            color: #718096 !important;
        }

        /* Hover effects for performance cards */
        .performance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }
        
        /* Risk badge styles for better visibility */
        .risk-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
                overflow: auto; /* Allow body scroll on mobile */
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .main-content {
                max-width: 100%;
                padding: 1.5rem;
                height: auto; /* Auto height on mobile */
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
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-btn {
                width: 100%;
                justify-content: center;
            }
            
            .logout-modal-actions {
                flex-direction: column;
            }
            
            .logout-modal-btn {
                width: 100%;
                justify-content: center;
            }
            
            .subject-actions {
                flex-direction: column;
            }
            
            .subject-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .credits {
                align-self: flex-start;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .card-actions {
                flex-direction: column;
                gap: 0.75rem;
                align-items: stretch;
            }
            
            .subject-count {
                justify-content: center;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .subject-status {
                position: relative;
                top: auto;
                right: auto;
                align-self: flex-start;
                margin-bottom: 0.5rem;
            }
        }
        
        /* Improved Modal Scrolling - Hide scrollbars */
        .modal-content::-webkit-scrollbar {
            width: 0px;
            background: transparent;
        }

        .modal-content::-webkit-scrollbar-track {
            background: transparent;
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: transparent;
        }

        .modal-content::-webkit-scrollbar-thumb:hover {
            background: transparent;
        }

        /* Compact layout for better space usage */
        .detail-item {
            min-height: auto;
            padding: 0.75rem;
        }

        .detail-label {
            font-size: 0.75rem;
            margin-bottom: 0.2rem;
        }

        .detail-value {
            font-size: 0.9rem;
            line-height: 1.3;
        }

        /* Better performance card layout */
        .performance-overview .details-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .performance-overview .detail-item {
            text-align: center;
            padding: 1rem;
        }

        /* Responsive improvements for modal */
        @media (max-width: 768px) {
            .modal-content {
                margin: 0.5rem;
                padding: 1.5rem;
                max-height: 90vh;
            }
            
            .performance-overview .details-grid {
                grid-template-columns: 1fr;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-height: 700px) {
            .modal-content {
                max-height: 95vh;
            }
            
            .performance-overview {
                margin-bottom: 1rem;
            }
            
            .detail-item {
                padding: 0.5rem;
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
                    <a href="student-subjects.php" class="btn btn-primary" style="margin-top: 1rem; border-radius: 10px;">
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

    <!-- Logout Confirmation Modal -->
    <div class="logout-modal" id="logoutModal">
        <div class="logout-modal-content">
            <h3 class="logout-modal-title">Confirm Logout</h3>
            <p class="logout-modal-message">
                Are you sure you want to logout? You'll need<br>
                to log in again to access your account.
            </p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-modal-btn logout-modal-cancel" id="cancelLogout">
                    Cancel
                </button>
                <button type="button" class="logout-modal-btn logout-modal-confirm" id="confirmLogout">
                    Yes, Logout
                </button>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content" style="max-width: 700px; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column;">
            <h3 class="modal-title">
                <i class="fas fa-info-circle"></i>
                Archived Subject Details
            </h3>
            
            <div style="flex: 1; overflow: hidden; display: flex; flex-direction: column;">
                <!-- Performance Overview -->
                <div class="performance-overview" style="margin-bottom: 1.5rem;">
                    <h4 style="color: var(--plp-green); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-line"></i>
                        Final Performance Summary
                    </h4>
                    <div class="details-grid" style="grid-template-columns: repeat(2, 1fr); gap: 0.75rem;">
                        <div class="detail-item" style="text-align: center; padding: 1rem;">
                            <div class="detail-label">Overall Grade</div>
                            <div class="detail-value" id="view_overall_grade" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="risk-badge no-data" id="view_risk_badge" style="display: none; padding: 0.4rem 1rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">No Data</div>
                        </div>
                        
                        <div class="detail-item" style="text-align: center; padding: 1rem;">
                            <div class="detail-label">GPA</div>
                            <div class="detail-value" id="view_gpa" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="detail-label" id="view_risk_description" style="font-size: 0.85rem; color: var(--text-medium); margin-top: 0.5rem;">No Data Inputted</div>
                        </div>
                        
                        <div class="detail-item" style="text-align: center; padding: 1rem;">
                            <div class="detail-label">Class Standing</div>
                            <div class="detail-value" id="view_class_standing" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="detail-label" style="font-size: 0.85rem; color: var(--text-medium);">of 60%</div>
                        </div>
                        
                        <div class="detail-item" style="text-align: center; padding: 1rem;">
                            <div class="detail-label">Exams</div>
                            <div class="detail-value" id="view_exams_score" style="font-size: 1.4rem; font-weight: 700; color: var(--plp-green); margin: 0.5rem 0;">--</div>
                            <div class="detail-label" style="font-size: 0.85rem; color: var(--text-medium;">of 40%</div>
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
                        
            <div class="modal-actions" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--plp-green-lighter);">
                <button type="button" class="modal-btn modal-btn-cancel" id="closeViewModal">
                    Close
                </button>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Close View Modal functionality
            const closeViewModalBtn = document.getElementById('closeViewModal');
            const viewModal = document.getElementById('viewModal');
            
            if (closeViewModalBtn && viewModal) {
                closeViewModalBtn.addEventListener('click', function() {
                    viewModal.classList.remove('show');
                });
            }

            // Logout Modal functionality
            const confirmLogoutBtn = document.getElementById('confirmLogout');
            const cancelLogoutBtn = document.getElementById('cancelLogout');
            const logoutModal = document.getElementById('logoutModal');
            
            if (confirmLogoutBtn) {
                confirmLogoutBtn.addEventListener('click', function() {
                    window.location.href = 'logout.php';
                });
            }
            
            if (cancelLogoutBtn && logoutModal) {
                cancelLogoutBtn.addEventListener('click', function() {
                    logoutModal.classList.remove('show');
                });
            }

            // Close modals when clicking outside
            window.addEventListener('click', function(e) {
                if (viewModal && e.target === viewModal) {
                    viewModal.classList.remove('show');
                }
                if (logoutModal && e.target === logoutModal) {
                    logoutModal.classList.remove('show');
                }
            });

            // Close modals with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (viewModal) viewModal.classList.remove('show');
                    if (logoutModal) logoutModal.classList.remove('show');
                }
            });

            // Auto-hide success/error messages after 5 seconds
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

            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });

        function openViewModal(
            subjectCode, subjectName, credits, professor, schedule, semester, archivedDate,
            overallGrade = 0, gpa = 0, classStanding = 0, examsScore = 0, riskLevel = 'no-data', 
            riskDescription = 'No Data Inputted', hasScores = false
        ) {
            // Validate data
            if (!subjectCode || !subjectName) {
                console.error('Missing subject data');
                alert('Error: Missing subject information');
                return;
            }

            // Set basic subject details
            document.getElementById('view_subject_code').textContent = subjectCode;
            document.getElementById('view_subject_name').textContent = subjectName;
            document.getElementById('view_credits').textContent = credits + ' Credits';
            document.getElementById('view_professor').textContent = professor;
            document.getElementById('view_schedule').textContent = schedule;
            document.getElementById('view_semester').textContent = semester;
            document.getElementById('view_archived_date').textContent = archivedDate;
            
            // Set performance data - handle both string and number inputs
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
    </script>
</body>
</html>