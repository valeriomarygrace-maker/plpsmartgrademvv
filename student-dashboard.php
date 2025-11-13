<?php
require_once 'config.php';

requireStudentRole();

// Get student info
$student = getStudentByEmail($_SESSION['user_email']);

if (!$student) {
    session_destroy();
    header('Location: login.php');
    exit;
}
// Initialize variables
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
                        'gwa' => $performance['gwa'] ?? 0,
                        'risk_level' => $performance['risk_level'] ?? 'no-data',
                        'has_scores' => ($performance && $performance['overall_grade'] > 0)
                    ]);
                }
            }
        }
        
        // Get recent scores (last 5 scores for current student)
        $recent_scores = getRecentScoresForStudent($student['id'], 3);
        
        // Calculate overall performance metrics
        $performance_metrics = calculatePerformanceMetrics($student['id']);
        
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
 * Calculate overall performance metrics
 */
function calculatePerformanceMetrics($student_id) {
    $metrics = [
        'total_subjects' => 0,
        'subjects_with_scores' => 0,
        'average_grade' => 0,
        'average_gwa' => 0,
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
            $total_gwa = 0;
            $subjects_with_data = 0;
            
            foreach ($student_subjects as $subject_record) {
                $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record['id']]);
                
                if ($performance_data && count($performance_data) > 0) {
                    $performance = $performance_data[0];
                    if ($performance['overall_grade'] > 0) {
                        $metrics['subjects_with_scores']++;
                        $total_grade += $performance['overall_grade'];
                        $total_gwa += $performance['gwa'];
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
                $metrics['average_gwa'] = round($total_gwa / $subjects_with_data, 2);
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
 * Calculate GWA from grade (Philippine system)
 */
function calculateGWA($grade) {
    if ($grade >= 90) return 1.00;
    elseif ($grade >= 85) return 1.25;
    elseif ($grade >= 80) return 1.50;
    elseif ($grade >= 75) return 1.75;
    elseif ($grade >= 70) return 2.00;
    elseif ($grade >= 65) return 2.25;
    elseif ($grade >= 60) return 2.50;
    elseif ($grade >= 55) return 2.75;
    elseif ($grade >= 50) return 3.00;
    else return 5.00;
}

/**
 * Get semester risk data for bar chart - IMPROVED VERSION
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
        'total_low_risk' => 0,
        'total_active_subjects' => 0,
        'active_high_risk' => 0,
        'active_low_risk' => 0,
        'debug_info' => [] // Add debug info
    ];
    
    try {
        // Get ALL subjects (both active and archived)
        $all_subjects = supabaseFetch('student_subjects', [
            'student_id' => $student_id, 
            'deleted_at' => null
        ]);
        
        $data['debug_info']['total_subjects_found'] = $all_subjects ? count($all_subjects) : 0;
        
        if ($all_subjects && is_array($all_subjects)) {
            foreach ($all_subjects as $subject_record) {
                // Get subject info
                $subject_data = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
                
                if ($subject_data && count($subject_data) > 0) {
                    $subject_info = $subject_data[0];
                    $is_archived = $subject_record['archived'] === true || $subject_record['archived'] === 'true';
                    
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
                    
                    // Add to counts based on archived status
                    if ($is_archived) {
                        $data['total_archived_subjects']++;
                        if ($is_high_risk) {
                            $data['total_high_risk']++;
                        }
                        if ($is_low_risk) {
                            $data['total_low_risk']++;
                        }
                    } else {
                        $data['total_active_subjects']++;
                        if ($is_high_risk) {
                            $data['active_high_risk']++;
                        }
                        if ($is_low_risk) {
                            $data['active_low_risk']++;
                        }
                    }
                    
                    // Categorize by semester for archived subjects only
                    if ($is_archived) {
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
                    }
                    
                    // Add debug info for this subject
                    $data['debug_info']['subjects'][] = [
                        'subject_code' => $subject_info['subject_code'],
                        'archived' => $is_archived,
                        'grade' => $final_grade,
                        'risk_level' => $risk_level,
                        'has_performance_data' => !empty($performance_data)
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
 * Calculate subject grade from scores if performance data is missing
 */
function calculateSubjectGradeFromScores($student_subject_id) {
    try {
        $allScores = supabaseFetch('student_subject_scores', ['student_subject_id' => $student_subject_id]);
        if (!$allScores || empty($allScores)) {
            return 0;
        }
        
        $midtermGrade = 0;
        $finalGrade = 0;
        
        // Get midterm categories
        $midtermCategories = supabaseFetch('student_class_standing_categories', [
            'student_subject_id' => $student_subject_id,
            'term_type' => 'midterm'
        ]);
        
        // Get final categories
        $finalCategories = supabaseFetch('student_class_standing_categories', [
            'student_subject_id' => $student_subject_id,
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
            $subjectGrade = array_sum($grades) / count($grades);
            return min($subjectGrade, 100);
        }
        
        return 0;
        
    } catch (Exception $e) {
        error_log("Error calculating grade from scores: " . $e->getMessage());
        return 0;
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
                <a href="student-messages.php" class="nav-link">
                    <i class="fas fa-comments"></i>
                    Messages
                    <?php 
                    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'student');
                    if ($unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
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
                                        <div class="gwa-value">
                                            GWA: <?php echo number_format($subject['gwa'], 2); ?>
                                            <span class="risk-badge <?php echo $subject['risk_level']; ?>">
                                                <?php echo ucfirst($subject['risk_level']); ?>
                                            </span>
                                        </div>
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
                            <div style="font-size: 1.5rem; font-weight: 700; color: #dc3545;">
                                <?php 
                                $totalHighRisk = ($semester_risk_data['total_high_risk'] ?? 0) + ($semester_risk_data['active_high_risk'] ?? 0);
                                echo $totalHighRisk; 
                                ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-medium);">High Risk</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: #28a745;">
                                <?php 
                                $totalLowRisk = ($semester_risk_data['total_low_risk'] ?? 0) + ($semester_risk_data['active_low_risk'] ?? 0);
                                echo $totalLowRisk; 
                                ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-medium);">Low Risk</div>
                        </div>
                        <div style="text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: #e2e8f0;">
                                <?php 
                                $totalSubjects = ($semester_risk_data['total_archived_subjects'] ?? 0) + ($semester_risk_data['total_active_subjects'] ?? 0);
                                $totalWithRisk = $totalHighRisk + $totalLowRisk;
                                echo max(0, $totalSubjects - $totalWithRisk); 
                                ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-medium);">No Data</div>
                        </div>
                    </div>
                    
                    <!-- Show helpful message based on what we found -->
                    <?php 
                    $totalArchived = $semester_risk_data['total_archived_subjects'] ?? 0;
                    $totalActive = $semester_risk_data['total_active_subjects'] ?? 0;
                    ?>
                    
                    <?php if ($totalArchived === 0 && $totalActive === 0): ?>
                        <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-light);">
                            <i class="fas fa-info-circle"></i>
                            No subjects found
                        </div>
                    <?php elseif ($totalArchived === 0): ?>
                        <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-light);">
                            <i class="fas fa-info-circle"></i>
                            Showing active subjects (no archived subjects yet)
                        </div>
                    <?php else: ?>
                    <?php endif; ?>
                </div>
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
    // Initialize Charts
    function initializeCharts() {
        const semesterRiskData = <?php echo json_encode($semester_risk_data); ?>;
        
        console.log('Risk Data:', semesterRiskData); // Debug log
        
        // Risk Overview Chart (for the 3-column grid)
        const riskOverviewCtx = document.getElementById('riskOverviewChart')?.getContext('2d');
        if (riskOverviewCtx) {
            // Calculate data for the chart - include both active and archived
            const highRiskCount = (semesterRiskData.total_high_risk || 0) + (semesterRiskData.active_high_risk || 0);
            const lowRiskCount = (semesterRiskData.total_low_risk || 0) + (semesterRiskData.active_low_risk || 0);
            const totalArchived = semesterRiskData.total_archived_subjects || 0;
            const totalActive = semesterRiskData.total_active_subjects || 0;
            const totalSubjects = totalArchived + totalActive;
            const noDataCount = Math.max(0, totalSubjects - highRiskCount - lowRiskCount);
            
            console.log('Chart Data:', {
                highRisk: highRiskCount,
                lowRisk: lowRiskCount,
                noData: noDataCount,
                total: totalSubjects,
                archived: totalArchived,
                active: totalActive
            });
            
            // Only show chart if there's data
            if (totalSubjects > 0) {
                new Chart(riskOverviewCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['High Risk', 'Low Risk', 'No Data'],
                        datasets: [{
                            data: [highRiskCount, lowRiskCount, noDataCount],
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
                                    boxWidth: 12,
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                // Show message if no data
                riskOverviewCtx.canvas.style.display = 'none';
                const wrapper = document.querySelector('.bar-chart-wrapper');
                wrapper.innerHTML = '<p style="color: var(--text-light); text-align: center; padding: 2rem;">No subjects with risk data available</p>';
            }
        } else {
            console.error('Risk overview chart canvas not found');
        }
    }

    // Initialize charts when DOM is fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeCharts();
    });

    // Also initialize when window loads (as backup)
    window.addEventListener('load', function() {
        setTimeout(initializeCharts, 100);
    });

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
    logoutModal.addEventListener('click', (e) => {
        if (e.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });
</script>
</body>
</html>