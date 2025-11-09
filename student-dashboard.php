<?php
require_once 'config.php';
require_once 'ml-helpers.php';

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
$active_subjects = [];
$recent_scores = [];
$performance_metrics = [];
$semester_risk_data = [];
$error_message = '';

try {
    // Get student info using Supabase
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    } else {
        // Get active subjects
        $student_subjects_data = supabaseFetch('student_subjects', [
            'student_id' => $student['id'], 
            'deleted_at' => null
        ]);
        
        if ($student_subjects_data && is_array($student_subjects_data)) {
            foreach ($student_subjects_data as $subject_record) {
                $subject_data = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
                if ($subject_data && count($subject_data) > 0) {
                    $subject_info = $subject_data[0];
                    
                    // Get performance data for this subject
                    $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                    $performance = $performance_data && count($performance_data) > 0 ? $performance_data[0] : null;
                    
                    $active_subjects[] = array_merge($subject_record, [
                        'subject_code' => $subject_info['subject_code'],
                        'subject_name' => $subject_info['subject_name'],
                        'credits' => $subject_info['credits'],
                        'semester' => $subject_info['semester'],
                        'overall_grade' => $performance['overall_grade'] ?? 0,
                        'subject_grade' => $performance['overall_grade'] ?? 0, // Changed from gwa to subject_grade
                        'risk_level' => $performance['risk_level'] ?? 'no-data',
                        'has_scores' => ($performance && $performance['overall_grade'] > 0)
                    ]);
                }
            }
        }
        
        // Get recent scores (last 5 scores for current student)
        $recent_scores = getRecentScoresForStudent($student['id'], 3);
        
        // Calculate overall performance metrics
        $performance_metrics = calculateOverallPerformanceMetrics($student['id']); // Renamed to avoid conflict
        
        // Get semester risk data for the graph
        $semester_risk_data = getSemesterRiskData($student['id']);
        
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in student-dashboard.php: " . $e->getMessage());
}

/**
 * Get recent scores for a student
 */
function getRecentScoresForStudent($student_id, $limit = 3) {
    $recent_scores = [];
    
    try {
        // Get all the student's subjects
        $student_subjects_data = supabaseFetch('student_subjects', [
            'student_id' => $student_id, 
            'deleted_at' => null
        ]);
        
        if ($student_subjects_data && is_array($student_subjects_data)) {
            $student_subject_ids = array_column($student_subjects_data, 'id');
            
            if (!empty($student_subject_ids)) {
                // Get scores only for this student's subjects
                $all_scores = [];
                foreach ($student_subject_ids as $subject_id) {
                    $scores = supabaseFetch('student_subject_scores', ['student_subject_id' => $subject_id]);
                    if ($scores && is_array($scores)) {
                        $all_scores = array_merge($all_scores, $scores);
                    }
                }
                
                if (!empty($all_scores)) {
                    // Sort by creation date descending
                    usort($all_scores, function($a, $b) {
                        $dateA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
                        $dateB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
                        return $dateB - $dateA;
                    });
                    
                    // Take the specified limit
                    $recent_scores = array_slice($all_scores, 0, $limit);
                    
                    // Get subject names for display
                    foreach ($recent_scores as &$score) {
                        $subject_data = supabaseFetch('student_subjects', ['id' => $score['student_subject_id']]);
                        if ($subject_data && count($subject_data) > 0) {
                            $student_subject = $subject_data[0];
                            $subject_info = supabaseFetch('subjects', ['id' => $student_subject['subject_id']]);
                            if ($subject_info && count($subject_info) > 0) {
                                $score['subject_code'] = $subject_info[0]['subject_code'];
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error getting recent scores: " . $e->getMessage());
    }
    
    return $recent_scores;
}

/**
 * Calculate overall performance metrics (RENAMED to avoid conflict)
 */
function calculateOverallPerformanceMetrics($student_id) {
    $metrics = [
        'total_subjects' => 0,
        'subjects_with_scores' => 0,
        'average_grade' => 0,
        'average_subject_grade' => 0, // Changed from average_gwa
        'low_risk_count' => 0,
        'medium_risk_count' => 0,
        'high_risk_count' => 0
    ];
    
    try {
        // Get all student subjects with performance data
        $student_subjects = supabaseFetch('student_subjects', ['student_id' => $student_id, 'deleted_at' => null]);
        
        if ($student_subjects && is_array($student_subjects)) {
            $metrics['total_subjects'] = count($student_subjects);
            $total_grade = 0;
            $total_subject_grade = 0; // Changed from total_gwa
            $subjects_with_data = 0;
            
            foreach ($student_subjects as $subject_record) {
                $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                
                if ($performance_data && count($performance_data) > 0) {
                    $performance = $performance_data[0];
                    if ($performance['overall_grade'] > 0) {
                        $metrics['subjects_with_scores']++;
                        $total_grade += $performance['overall_grade'];
                        $total_subject_grade += $performance['overall_grade']; // Use percentage grade directly
                        $subjects_with_data++;
                        
                        // Count risk levels
                        switch ($performance['risk_level']) {
                            case 'low':
                                $metrics['low_risk_count']++;
                                break;
                            case 'medium':
                                $metrics['medium_risk_count']++;
                                break;
                            case 'high':
                                $metrics['high_risk_count']++;
                                break;
                        }
                    }
                }
            }
            
            if ($subjects_with_data > 0) {
                $metrics['average_grade'] = round($total_grade / $subjects_with_data, 1);
                $metrics['average_subject_grade'] = round($total_subject_grade / $subjects_with_data, 1); // Changed from average_gwa
            }
        }
        
    } catch (Exception $e) {
        error_log("Error calculating performance metrics: " . $e->getMessage());
    }
    
    return $metrics;
}

/**
 * Get unique professors count for the student
 */
function getUniqueProfessors($student_id) {
    $professors = [];
    
    try {
        // Get active subjects
        $active_subjects = supabaseFetch('student_subjects', ['student_id' => $student_id, 'deleted_at' => null]);
        if ($active_subjects && is_array($active_subjects)) {
            foreach ($active_subjects as $subject) {
                if (!empty($subject['professor_name'])) {
                    $professors[$subject['professor_name']] = true;
                }
            }
        }
        
        // Get archived subjects
        $archived_subjects = supabaseFetch('archived_subjects', ['student_id' => $student_id]);
        if ($archived_subjects && is_array($archived_subjects)) {
            foreach ($archived_subjects as $subject) {
                if (!empty($subject['professor_name'])) {
                    $professors[$subject['professor_name']] = true;
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error getting professors count: " . $e->getMessage());
    }
    
    return array_keys($professors);
}

/**
 * Get semester risk data for bar chart - UPDATED FOR DUAL RISK DISPLAY
 */
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
        'total_low_risk' => 0
    ];
    
    try {
        // Get all archived subjects for this student
        $archived_subjects = supabaseFetch('archived_subjects', ['student_id' => $student_id]);
        
        if ($archived_subjects && is_array($archived_subjects)) {
            $data['total_archived_subjects'] = count($archived_subjects);
            
            foreach ($archived_subjects as $archived_subject) {
                // Get subject info to determine semester
                $subject_data = supabaseFetch('subjects', ['id' => $archived_subject['subject_id']]);
                
                if ($subject_data && count($subject_data) > 0) {
                    $subject_info = $subject_data[0];
                    $semester = strtolower($subject_info['semester']);
                    
                    // Get performance data for archived subject
                    $performance_data = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject['id']]);
                    
                    $is_high_risk = false;
                    $is_low_risk = false;
                    $final_grade = 0;
                    $risk_level = 'no-data';
                    
                    if ($performance_data && count($performance_data) > 0) {
                        $performance = $performance_data[0];
                        $final_grade = $performance['overall_grade'];
                        $risk_level = $performance['risk_level'];
                        
                        // Use the same risk level definition as archived subjects page
                        $is_high_risk = ($risk_level === 'high' || $risk_level === 'failed');
                        $is_low_risk = ($risk_level === 'low' || $risk_level === 'medium');
                    } else {
                        // Calculate performance if not stored (same as archived subjects page)
                        $calculated_performance = calculateArchivedSubjectPerformance($archived_subject['id']);
                        if ($calculated_performance) {
                            $final_grade = $calculated_performance['overall_grade'];
                            $risk_level = $calculated_performance['risk_level'];
                            $is_high_risk = ($risk_level === 'high' || $risk_level === 'failed');
                            $is_low_risk = ($risk_level === 'low' || $risk_level === 'medium');
                        }
                    }
                    
                    if ($is_high_risk) {
                        $data['total_high_risk']++;
                    }
                    if ($is_low_risk) {
                        $data['total_low_risk']++;
                    }
                    
                    // Categorize by semester
                    if (strpos($semester, 'first') !== false || strpos($semester, '1') !== false) {
                        $data['first_semester']['total_subjects']++;
                        if ($is_high_risk) {
                            $data['first_semester']['high_risk_count']++;
                        }
                        if ($is_low_risk) {
                            $data['first_semester']['low_risk_count']++;
                        }
                        $data['first_semester']['subjects'][] = [
                            'subject_code' => $subject_info['subject_code'],
                            'subject_name' => $subject_info['subject_name'],
                            'final_grade' => $final_grade,
                            'risk_level' => $risk_level,
                            'is_high_risk' => $is_high_risk,
                            'is_low_risk' => $is_low_risk
                        ];
                    } elseif (strpos($semester, 'second') !== false || strpos($semester, '2') !== false) {
                        $data['second_semester']['total_subjects']++;
                        if ($is_high_risk) {
                            $data['second_semester']['high_risk_count']++;
                        }
                        if ($is_low_risk) {
                            $data['second_semester']['low_risk_count']++;
                        }
                        $data['second_semester']['subjects'][] = [
                            'subject_code' => $subject_info['subject_code'],
                            'subject_name' => $subject_info['subject_name'],
                            'final_grade' => $final_grade,
                            'risk_level' => $risk_level,
                            'is_high_risk' => $is_high_risk,
                            'is_low_risk' => $is_low_risk
                        ];
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error getting semester risk data: " . $e->getMessage());
    }
    
    return $data;
}

/**
 * Calculate performance for archived subject (same as in student-archived-subject.php)
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
                'subject_grade' => 0, // Changed from gwa
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
            'subject_grade' => $overallGrade, // Use percentage grade directly
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
    <title>Dashboard - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* CSS remains the same */
        /* ... existing CSS ... */
    </style>
</head>
<body>
    <!-- HTML structure remains the same -->
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
                <a href="student-dashboard.php" class="nav-link active">
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
        <?php if ($error_message): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <div class="header">
            <div class="welcome">Welcome, <?php echo htmlspecialchars(explode(' ', $student['fullname'])[0]); ?>!</div>
        </div>

        <!-- Academic Statistics -->
        <div class="dashboard-grid">
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-value"><?php echo $performance_metrics['total_subjects']; ?></div>
                    <div class="metric-label">Subjects</div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?php echo count(getUniqueProfessors($student['id'])); ?></div>
                    <div class="metric-label">Professors</div>
                </div>
            </div>
        </div>

        <div class="three-column-grid">
            <!-- Active Subjects -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-book-open"></i>
                        Active Subjects
                    </div>
                    <a href="student-subjects.php" style="color: var(--plp-green); text-decoration: none; font-size: 0.9rem;">
                        View All
                    </a>
                </div>
                <?php if (!empty($active_subjects)): ?>
                    <ul class="subject-list">
                        <?php foreach (array_slice($active_subjects, 0, 3) as $subject): ?>
                            <li class="subject-item">
                                <div class="subject-info">
                                    <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                </div>
                                <div class="subject-grade">
                                    <?php if ($subject['has_scores']): ?>
                                        <div class="grade-value 
                                            <?php 
                                            if ($subject['overall_grade'] >= 90) echo 'grade-excellent';
                                            elseif ($subject['overall_grade'] >= 80) echo 'grade-good';
                                            elseif ($subject['overall_grade'] >= 75) echo 'grade-average';
                                            else echo 'grade-poor';
                                            ?>
                                        ">
                                            <?php echo number_format($subject['overall_grade'], 1); ?>%
                                        </div>
                                        <span class="risk-badge <?php echo $subject['risk_level']; ?>">
                                            <?php echo ucfirst($subject['risk_level']); ?>
                                        </span>
                                    <?php else: ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No active subjects</p>
                        <small>Add subjects to start tracking your grades</small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Scores -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-list-check"></i>
                        Recent Scores
                    </div>
                </div>
                <?php if (!empty($recent_scores)): ?>
                    <ul class="subject-list">
                        <?php foreach ($recent_scores as $score): ?>
                            <li class="score-item">
                                <div class="score-info">
                                    <div class="score-name"><?php echo htmlspecialchars($score['score_name']); ?></div>
                                    <div class="score-subject">
                                        <?php 
                                        if (isset($score['subject_code'])) {
                                            echo htmlspecialchars($score['subject_code']);
                                        } else {
                                            echo 'Unknown Subject';
                                        }
                                        ?> • <?php echo htmlspecialchars($score['score_type']); ?>
                                    </div>
                                </div>
                                <div class="score-value">
                                    <?php echo number_format($score['score_value'], 1); ?>/<?php echo number_format($score['max_score'], 1); ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No recent scores</p>
                        <small>Add scores to your subjects to see recent activity</small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Risk Overview -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-pie"></i>
                        Risk Overview
                    </div>
                </div>
                <div style="text-align: center; padding: 1rem;">
                    <div class="bar-chart-wrapper" style="height: 200px;">
                        <canvas id="riskOverviewChart"></canvas>
                    </div>
                    <div style="display: flex; justify-content: space-around; margin-top: 1rem;">
                        <div style="text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--plp-green);">
                                <?php echo $semester_risk_data['total_high_risk'] ?? 0; ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-medium);">High Risk</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--plp-green);">
                                <?php echo $semester_risk_data['total_low_risk'] ?? 0; ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-medium);">Low Risk</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--plp-green);">
                                <?php 
                                $totalArchived = $semester_risk_data['total_archived_subjects'] ?? 0;
                                $totalWithRisk = ($semester_risk_data['total_high_risk'] ?? 0) + ($semester_risk_data['total_low_risk'] ?? 0);
                                echo $totalArchived - $totalWithRisk; 
                                ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-medium);">No Data</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize Charts
        function initializeCharts() {
            const semesterRiskData = <?php echo json_encode($semester_risk_data); ?>;
            
            console.log('Risk Data:', semesterRiskData); // Debug log
            
            // Risk Overview Chart (for the 3-column grid)
            const riskOverviewCtx = document.getElementById('riskOverviewChart')?.getContext('2d');
            if (riskOverviewCtx) {
                // Calculate data for the chart
                const highRiskCount = semesterRiskData.total_high_risk || 0;
                const lowRiskCount = semesterRiskData.total_low_risk || 0;
                const noRiskCount = semesterRiskData.total_archived_subjects - highRiskCount - lowRiskCount;
                
                // Only show chart if there's data
                if (semesterRiskData.total_archived_subjects > 0) {
                    new Chart(riskOverviewCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['High Risk', 'Low Risk', 'No Data'],
                            datasets: [{
                                data: [highRiskCount, lowRiskCount, noRiskCount],
                                backgroundColor: ['#dc3545', '#28a745', '#e2e8f0'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '70%',
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 15,
                                        usePointStyle: true,
                                        boxWidth: 12
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((value / total) * 100);
                                            return `${label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                } else {
                    // Hide chart container if no data
                    riskOverviewCtx.canvas.style.display = 'none';
                    document.querySelector('.bar-chart-wrapper').innerHTML = '<p style="color: var(--text-light); text-align: center; padding: 2rem;">No risk data available</p>';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', initializeCharts);
    </script>
</body>
</html>