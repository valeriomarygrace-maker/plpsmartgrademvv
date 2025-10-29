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

// Initialize variables
$student = null;
$semester_grades = [];
$selected_semester = $_GET['semester'] ?? '';
$error_message = '';

try {
    // Get student info
    $stmt = $pdo->prepare("SELECT * FROM students WHERE email = ?");
    $stmt->execute([$_SESSION['user_email']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    } else {
        // Get all semesters with archived subjects
        $semester_stmt = $pdo->prepare("
            SELECT DISTINCT s.semester 
            FROM archived_subjects a 
            JOIN subjects s ON a.subject_id = s.id 
            WHERE a.student_id = ? 
            ORDER BY 
                CASE 
                    WHEN s.semester = 'First Semester' THEN 1
                    WHEN s.semester = 'Second Semester' THEN 2
                    WHEN s.semester = 'Summer' THEN 3
                    ELSE 4
                END
        ");
        $semester_stmt->execute([$student['id']]);
        $semesters = $semester_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If no semester selected, use the first one
        if (empty($selected_semester) && !empty($semesters)) {
            $selected_semester = $semesters[0];
        }
        
        // Get archived subjects for the selected semester with calculated performance
        if ($selected_semester) {
            $grades_stmt = $pdo->prepare("
                SELECT 
                    a.id as archived_subject_id,
                    s.subject_code,
                    s.subject_name,
                    s.credits,
                    s.semester,
                    a.professor_name,
                    a.schedule,
                    a.archived_at,
                    COALESCE(ap.overall_grade, 0) as overall_grade,
                    COALESCE(ap.gpa, 0) as gpa,
                    COALESCE(ap.class_standing, 0) as class_standing,
                    COALESCE(ap.exams_score, 0) as exams_score,
                    COALESCE(ap.risk_level, 'no-data') as risk_level,
                    COALESCE(ap.risk_description, 'No Data Inputted') as risk_description,
                    CASE 
                        WHEN ap.overall_grade IS NOT NULL AND ap.overall_grade > 0 THEN 1 
                        ELSE 0 
                    END as has_scores
                FROM archived_subjects a
                JOIN subjects s ON a.subject_id = s.id
                LEFT JOIN archived_subject_performance ap ON a.id = ap.archived_subject_id
                WHERE a.student_id = ? AND s.semester = ?
                ORDER BY s.subject_code
            ");
            $grades_stmt->execute([$student['id'], $selected_semester]);
            $semester_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no performance data exists in archived_subject_performance, calculate it from scores
            foreach ($semester_grades as &$subject) {
                if (!$subject['has_scores']) {
                    // Calculate performance from archived scores
                    $calculated_performance = calculateArchivedSubjectPerformance($subject['archived_subject_id'], $pdo);
                    if ($calculated_performance && $calculated_performance['has_scores']) {
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
        }
    }
} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in student-semester-grades.php: " . $e->getMessage());
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
    <title>Semester Grades - PLP SmartGrade</title>
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

        /* Semester Selector */
        .semester-selector {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .semester-btn {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-medium);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .semester-btn:hover {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            transform: translateY(-2px);
        }

        .semester-btn.active {
            background: var(--plp-green-gradient);
            color: white;
            border-color: var(--plp-green);
            box-shadow: var(--box-shadow);
        }

        /* Grades Table */
        .grades-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
        }

        .grades-table th {
            background: var(--plp-green-pale);
            color: var(--plp-green);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .grades-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .grades-table tr:last-child td {
            border-bottom: none;
        }

        .grades-table tr:hover {
            background: var(--plp-green-pale);
        }

        .subject-code {
            font-weight: 600;
            color: var(--plp-green);
        }

        .subject-name {
            color: var(--text-dark);
        }

        .credits {
            text-align: center;
            font-weight: 600;
            color: var(--plp-green);
        }

        .grade {
            text-align: center;
            font-weight: 700;
        }

        .grade-excellent {
            color: var(--success);
        }

        .grade-good {
            color: var(--info);
        }

        .grade-average {
            color: var(--warning);
        }

        .grade-poor {
            color: var(--danger);
        }

        .grade-no-data {
            color: var(--text-light);
            font-style: italic;
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

        /* Alert styles */
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

        /* Responsive */
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
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .semester-selector {
                justify-content: center;
            }
            
            .grades-table-container {
                overflow-x: auto;
            }
            
            .grades-table {
                min-width: 600px;
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
                <a href="student-archived-subject.php" class="nav-link">
                    <i class="fas fa-archive"></i>
                    Archived Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-semester-grades.php" class="nav-link active">
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
        <div class="header">
            <div class="welcome">History Records</div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($semesters)): ?>
            <!-- Semester Selector -->
            <div class="semester-selector">
                <?php foreach ($semesters as $semester): ?>
                    <a href="?semester=<?php echo urlencode($semester); ?>" 
                       class="semester-btn <?php echo $selected_semester === $semester ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo htmlspecialchars($semester); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Grades Table -->
            <div class="grades-table-container">
                <?php if (!empty($semester_grades)): ?>
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Description</th>
                                <th>Professor</th>
                                <th>Schedule</th>
                                <th>Credits</th>
                                <th>Final Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semester_grades as $subject): ?>
                                <tr>
                                    <td>
                                        <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                                    </td>
                                    <td>
                                        <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($subject['professor_name']); ?></td>
                                    <td><?php echo htmlspecialchars($subject['schedule']); ?></td>
                                    <td class="credits"><?php echo htmlspecialchars($subject['credits']); ?></td>
                                    <td class="grade 
                                        <?php if ($subject['has_scores']): ?>
                                            <?php 
                                            if ($subject['overall_grade'] >= 90) echo 'grade-excellent';
                                            elseif ($subject['overall_grade'] >= 80) echo 'grade-good';
                                            elseif ($subject['overall_grade'] >= 75) echo 'grade-average';
                                            else echo 'grade-poor';
                                            ?>
                                        <?php else: ?>
                                            grade-no-data
                                        <?php endif; ?>
                                    ">
                                        <?php echo $subject['has_scores'] ? number_format($subject['overall_grade'], 1) . '%' : 'No Data'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No grades found for this semester</p>
                        <small>Grades will appear here once subjects are archived with performance data</small>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No semester history available</p>
                <small>Complete and archive subjects to see your semester grades history</small>
                <br>
                <a href="student-subjects.php" class="semester-btn active" style="margin-top: 1rem;">
                    <i class="fas fa-book"></i> Go to Active Subjects
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-error');
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