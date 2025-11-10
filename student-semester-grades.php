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
$history_records = [];
$available_semesters = [];

try {
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Get all archived subjects for the student
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
                
                // Get subject performance data for final grade
                $performance_data = supabaseFetch('subject_performance', [
                    'student_subject_id' => $subject_record['id']
                ]);
                
                // Calculate subject grade
                $subject_grade = 0;
                if ($performance_data && isset($performance_data[0]['overall_grade'])) {
                    $subject_grade = $performance_data[0]['overall_grade'];
                } else {
                    // Fallback: Calculate grade from scores
                    $allScores = supabaseFetch('student_subject_scores', [
                        'student_subject_id' => $subject_record['id']
                    ]);
                    
                    if ($allScores) {
                        $midtermGrade = 0;
                        $finalGrade = 0;
                        
                        // Calculate Midterm Grade
                        $midtermCategories = supabaseFetch('student_class_standing_categories', [
                            'student_subject_id' => $subject_record['id'],
                            'term_type' => 'midterm'
                        ]);
                        
                        // Calculate Final Grade
                        $finalCategories = supabaseFetch('student_class_standing_categories', [
                            'student_subject_id' => $subject_record['id'],
                            'term_type' => 'final'
                        ]);
                        
                        // Initialize exam score variables
                        $midtermExamScore = 0;
                        $finalExamScore = 0;
                        
                        // Simplified grade calculation
                        if ($midtermCategories && count($midtermCategories) > 0) {
                            $midtermClassStanding = 0;
                            foreach ($midtermCategories as $category) {
                                $categoryScores = array_filter($allScores, function($score) use ($category) {
                                    return $score['category_id'] == $category['id'] && $score['score_type'] === 'class_standing';
                                });
                                
                                $categoryTotal = 0;
                                $categoryMax = 0;
                                
                                foreach ($categoryScores as $score) {
                                    $categoryTotal += floatval($score['score_value']);
                                    $categoryMax += floatval($score['max_score']);
                                }
                                
                                if ($categoryMax > 0) {
                                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                                    $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                                    $midtermClassStanding += $weightedScore;
                                }
                            }
                            
                            if ($midtermClassStanding > 60) $midtermClassStanding = 60;
                            
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
                        }
                        
                        if ($finalCategories && count($finalCategories) > 0) {
                            $finalClassStanding = 0;
                            foreach ($finalCategories as $category) {
                                $categoryScores = array_filter($allScores, function($score) use ($category) {
                                    return $score['category_id'] == $category['id'] && $score['score_type'] === 'class_standing';
                                });
                                
                                $categoryTotal = 0;
                                $categoryMax = 0;
                                
                                foreach ($categoryScores as $score) {
                                    $categoryTotal += floatval($score['score_value']);
                                    $categoryMax += floatval($score['max_score']);
                                }
                                
                                if ($categoryMax > 0) {
                                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                                    $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                                    $finalClassStanding += $weightedScore;
                                }
                            }
                            
                            if ($finalClassStanding > 60) $finalClassStanding = 60;
                            
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
                        }
                        
                        // Calculate Subject Grade
                        $grades = array_filter([$midtermGrade, $finalGrade], function($grade) {
                            return $grade > 0;
                        });
                        
                        if (!empty($grades)) {
                            $subject_grade = array_sum($grades) / count($grades);
                            if ($subject_grade > 100) $subject_grade = 100;
                        }
                    }
                }
                
                $history_records[] = [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'professor_name' => $subject_record['professor_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester'],
                    'subject_grade' => $subject_grade
                ];
            }
        }
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Get available semesters for filter
if (!empty($history_records)) {
    $available_semesters = array_unique(array_column($history_records, 'semester'));
    sort($available_semesters);
}

// Handle semester filter
$selected_semester = $_GET['semester'] ?? 'all';
$filtered_records = $history_records;

if ($selected_semester !== 'all') {
    $filtered_records = array_filter($history_records, function($record) use ($selected_semester) {
        return $record['semester'] === $selected_semester;
    });
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="history_records_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Subject Code</th>";
    echo "<th>Subject Name</th>";
    echo "<th>Professor</th>";
    echo "<th>Credits</th>";
    echo "<th>Semester</th>";
    echo "<th>Subject Grade</th>";
    echo "</tr>";
    
    foreach ($filtered_records as $record) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($record['subject_code']) . "</td>";
        echo "<td>" . htmlspecialchars($record['subject_name']) . "</td>";
        echo "<td>" . htmlspecialchars($record['professor_name']) . "</td>";
        echo "<td>" . htmlspecialchars($record['credits']) . "</td>";
        echo "<td>" . htmlspecialchars($record['semester']) . "</td>";
        echo "<td>" . ($record['subject_grade'] > 0 ? number_format($record['subject_grade'], 1) . '%' : 'N/A') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History Records - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --plp-green: #006341;
            --plp-green-light: #008856;
            --plp-green-lighter: #e0f2e9;
            --plp-green-pale: #f5fbf8;
            --plp-green-gradient: linear-gradient(135deg, #006341 0%, #008856 100%);
            --text-dark: #2d3748;
            --text-medium: #4a5568;
            --text-light: #718096;
            --border-radius: 8px;
            --box-shadow: 0 2px 8px rgba(0, 99, 65, 0.1);
            --transition: all 0.3s ease;
            --danger: #dc3545;
            --warning: #ffc107;
            --success: #28a745;
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
            line-height: 1.5;
        }

        .sidebar {
            width: 280px;
            background: white;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            border-right: 1px solid rgba(0, 99, 65, 0.1);
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 99, 65, 0.1);
        }

        .logo {
            width: 100px;
            height: 100px;
            margin: 0 auto;
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .portal-title {
            color: var(--plp-green);
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }

        .student-email {
            color: var(--text-medium);
            font-size: 0.8rem;
        }

        .nav-menu {
            list-style: none;
            margin: 1rem 0;
        }

        .nav-item {
            margin-bottom: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            color: var(--text-medium);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .nav-link:hover:not(.active) {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }

        .nav-link.active {
            background: var(--plp-green-gradient);
            color: white;
        }

        .logout-btn {
            background: transparent;
            color: var(--text-medium);
            padding: 0.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            background: #fee2e2;
            color: #b91c1c;
        }

        .main-content {
            flex: 1;
            padding: 1rem;
            background: var(--plp-green-pale);
        }

        .header {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            background: var(--plp-green-gradient);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome {
            font-size: 1.2rem;
            font-weight: 700;
        }

        .subject-count {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .card {
            background: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .filter-select {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            background: white;
            color: var(--text-dark);
        }

        .export-btn {
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .export-btn:hover {
            transform: translateY(-1px);
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 0.85rem;
        }

        .history-table th {
            background: var(--plp-green);
            color: white;
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
        }

        .history-table td {
            padding: 0.8rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .history-table tr:hover {
            background: var(--plp-green-pale);
        }

        .grade-cell {
            font-weight: 600;
            text-align: center;
        }

        .grade-excellent { color: var(--success); }
        .grade-good { color: var(--plp-green); }
        .grade-average { color: var(--warning); }
        .grade-poor { color: var(--danger); }

        .credits-cell {
            text-align: center;
            font-weight: 600;
            color: var(--plp-green);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-medium);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--plp-green-lighter);
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #e53e3e;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 0.8rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #38a169;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
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
                padding: 0.8rem;
            }
            
            .header {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }
            
            .controls-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                justify-content: space-between;
            }
            
            .history-table {
                font-size: 0.8rem;
            }
            
            .history-table th,
            .history-table td {
                padding: 0.6rem 0.4rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="plplogo.png" alt="PLP Logo">
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

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
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
            <div class="welcome">History Records</div>
            <div class="subject-count">
                <i class="fas fa-layer-group"></i>
                <?php echo count($filtered_records); ?> Records
            </div>
        </div>

        <div class="card">
            <div class="controls-container">
                <div class="filter-group">
                    <span class="filter-label">Filter by Semester:</span>
                    <select class="filter-select" id="semesterFilter" onchange="filterBySemester()">
                        <option value="all" <?php echo $selected_semester === 'all' ? 'selected' : ''; ?>>All Semesters</option>
                        <?php foreach ($available_semesters as $semester): ?>
                            <option value="<?php echo htmlspecialchars($semester); ?>" 
                                <?php echo $selected_semester === $semester ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($semester); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (!empty($filtered_records)): ?>
                <a href="student-semester-grades.php?export=excel&semester=<?php echo $selected_semester; ?>" class="export-btn">
                    <i class="fas fa-file-excel"></i>
                    Export to Excel
                </a>
                <?php endif; ?>
            </div>

            <?php if (empty($filtered_records)): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No history records found</p>
                    <small>Archived subjects with grades will appear here</small>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Professor</th>
                                <th>Credits</th>
                                <th>Semester</th>
                                <th>Subject Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filtered_records as $record): 
                                $grade_class = '';
                                if ($record['subject_grade'] > 0) {
                                    if ($record['subject_grade'] >= 90) $grade_class = 'grade-excellent';
                                    elseif ($record['subject_grade'] >= 80) $grade_class = 'grade-good';
                                    elseif ($record['subject_grade'] >= 75) $grade_class = 'grade-average';
                                    else $grade_class = 'grade-poor';
                                }
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['subject_code']); ?></td>
                                    <td><?php echo htmlspecialchars($record['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['professor_name']); ?></td>
                                    <td class="credits-cell"><?php echo htmlspecialchars($record['credits']); ?></td>
                                    <td><?php echo htmlspecialchars($record['semester']); ?></td>
                                    <td class="grade-cell <?php echo $grade_class; ?>">
                                        <?php echo $record['subject_grade'] > 0 ? number_format($record['subject_grade'], 1) . '%' : 'N/A'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filterBySemester() {
            const semester = document.getElementById('semesterFilter').value;
            const url = new URL(window.location.href);
            url.searchParams.set('semester', semester);
            window.location.href = url.toString();
        }

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