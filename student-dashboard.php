<?php
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Get student data from Supabase
$student = getStudentById($userId);
if (!$student) {
    $_SESSION['error_message'] = "Student account not found";
    header('Location: login.php');
    exit;
}

// Get student subjects from Supabase
$subjects = supabaseFetch('student_subjects', ['student_id' => $userId]);
if (!$subjects) {
    $subjects = [];
}

// Get subject details for each student subject
$subjectDetails = [];
foreach ($subjects as $subject) {
    $subjectInfo = supabaseFetch('subjects', ['id' => $subject['subject_id']]);
    if ($subjectInfo && count($subjectInfo) > 0) {
        $subjectDetails[] = array_merge($subject, $subjectInfo[0]);
    }
}

$subjects = $subjectDetails;

$total_subjects = count($subjects);
$subjects_with_data = 0;
$total_gpa = 0;
$risk_distribution = ['low' => 0, 'medium' => 0, 'high' => 0, 'no-data' => 0];

$subject_performance = [];
$high_risk_subjects = [];

foreach ($subjects as $subject) {
    $performance = calculateSubjectPerformance($subject['id']);
    $subject_performance[$subject['id']] = $performance;
    
    if ($performance['has_scores']) {
        $subjects_with_data++;
        $total_gpa += $performance['gpa'];
        $risk_distribution[$performance['risk_level']]++;
        
        if ($performance['risk_level'] === 'high') {
            $high_risk_subjects[] = $subject;
        }
    } else {
        $risk_distribution['no-data']++;
    }
}

$average_gpa = $subjects_with_data > 0 ? $total_gpa / $subjects_with_data : 0;

// Get recent activities
$recent_activities = getRecentActivities($userId);

function calculateSubjectPerformance($student_subject_id) {
    try {
        // Get categories for this subject
        $categories = supabaseFetch('student_class_standing_categories', ['student_subject_id' => $student_subject_id]);
        if (!$categories) {
            $categories = [];
        }
        
        // Get scores for this subject
        $allScores = supabaseFetch('student_subject_scores', ['student_subject_id' => $student_subject_id]);
        if (!$allScores) {
            $allScores = [];
        }
        
        $classStandings = array_filter($allScores, function($score) {
            return $score['score_type'] === 'class_standing';
        });
        
        $midtermExam = array_filter($allScores, function($score) {
            return $score['score_type'] === 'midterm_exam';
        });
        
        $finalExam = array_filter($allScores, function($score) {
            return $score['score_type'] === 'final_exam';
        });
        
        $hasScores = !empty($classStandings) || !empty($midtermExam) || !empty($finalExam);
        
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
        
        // Calculate performance
        $totalClassStanding = 0;
        $categoryTotals = [];
        
        foreach ($categories as $category) {
            $categoryTotals[$category['id']] = [
                'percentage' => $category['category_percentage'],
                'total_score' => 0,
                'max_possible' => 0
            ];
        }
        
        foreach ($classStandings as $standing) {
            if ($standing['category_id'] && isset($categoryTotals[$standing['category_id']])) {
                $categoryId = $standing['category_id'];
                $categoryName = '';
                foreach ($categories as $cat) {
                    if ($cat['id'] == $categoryId) {
                        $categoryName = $cat['category_name'];
                        break;
                    }
                }
                
                if (strtolower($categoryName) === 'attendance') {
                    $scoreValue = ($standing['score_name'] === 'Present') ? 1 : 0;
                    $categoryTotals[$categoryId]['total_score'] += $scoreValue;
                    $categoryTotals[$categoryId]['max_possible'] += 1;
                } else {
                    $categoryTotals[$categoryId]['total_score'] += $standing['score_value'];
                    $categoryTotals[$categoryId]['max_possible'] += $standing['max_score'];
                }
            }
        }
        
        foreach ($categoryTotals as $categoryId => $category) {
            if ($category['max_possible'] > 0) {
                $percentageScore = ($category['total_score'] / $category['max_possible']) * 100;
                $weightedScore = ($percentageScore * $category['percentage']) / 100;
                $totalClassStanding += $weightedScore;
            }
        }
        
        if ($totalClassStanding > 60) $totalClassStanding = 60;
        
        $midtermScore = 0;
        $finalScore = 0;
        
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
        
        $overallGrade = min(100, $totalClassStanding + $midtermScore + $finalScore);
        
        // Calculate GPA and risk level
        if ($overallGrade >= 89) {
            $gpa = 1.00;
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
        } elseif ($overallGrade >= 82) {
            $gpa = 2.00;
            $riskLevel = 'medium';
            $riskDescription = 'Medium Risk';
        } elseif ($overallGrade >= 79) {
            $gpa = 2.75;
            $riskLevel = 'medium';
            $riskDescription = 'Medium Risk';
        } else {
            $gpa = 3.00;
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
        return [
            'overall_grade' => 0,
            'gpa' => 0,
            'class_standing' => 0,
            'exams_score' => 0,
            'risk_level' => 'no-data',
            'risk_description' => 'Error calculating',
            'has_scores' => false
        ];
    }
}

function getRecentActivities($student_id) {
    try {
        $activities = [];
        
        // Get recent score updates
        $studentSubjects = supabaseFetch('student_subjects', ['student_id' => $student_id]);
        if (!$studentSubjects) {
            return $activities;
        }
        
        $studentSubjectIds = array_column($studentSubjects, 'id');
        
        // This is a simplified version - you might need to adjust based on your Supabase setup
        $recentScores = [];
        foreach ($studentSubjectIds as $subjectId) {
            $scores = supabaseFetch('student_subject_scores', ['student_subject_id' => $subjectId]);
            if ($scores) {
                $recentScores = array_merge($recentScores, array_slice($scores, 0, 2));
            }
        }
        
        foreach ($recentScores as $score) {
            $subject = supabaseFetch('student_subjects', ['id' => $score['student_subject_id']]);
            $subjectInfo = $subject && count($subject) > 0 ? supabaseFetch('subjects', ['id' => $subject[0]['subject_id']]) : null;
            
            $subjectCode = $subjectInfo && count($subjectInfo) > 0 ? $subjectInfo[0]['subject_code'] : 'Unknown';
            
            $activities[] = [
                'type' => 'score_update',
                'message' => "Updated {$score['score_name']} in {$subjectCode}: {$score['score_value']}/{$score['max_score']}",
                'date' => $score['score_date'],
                'icon' => 'fas fa-chart-line'
            ];
        }
        
        // Get subject additions
        foreach (array_slice($studentSubjects, 0, 2) as $subject) {
            $subjectInfo = supabaseFetch('subjects', ['id' => $subject['subject_id']]);
            if ($subjectInfo && count($subjectInfo) > 0) {
                $activities[] = [
                    'type' => 'subject_added',
                    'message' => "Added new subject: {$subjectInfo[0]['subject_code']} - {$subjectInfo[0]['subject_name']}",
                    'date' => $subject['created_at'],
                    'icon' => 'fas fa-book'
                ];
            }
        }
        
        // Sort by date and return top 5
        usort($activities, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return array_slice($activities, 0, 5);
        
    } catch (Exception $e) {
        return [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --border-radius: 8px;
            --border-radius-lg: 12px;
            --box-shadow: 0 2px 8px rgba(0, 99, 65, 0.1);
            --box-shadow-lg: 0 4px 12px rgba(0, 99, 65, 0.15);
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
            color: var(--text-dark);
            line-height: 1.6;
        }

        .sidebar {
            width: 280px;
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
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }

        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.5rem;
        }

        .logo {
            width: 100px;
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
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
            letter-spacing: 0.5px;
        }

        .student-email {
            color: var(--text-medium);
            font-size: 0.8rem;
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
            border-top: 1px solid rgba(0, 99, 65, 0.1);
            padding-top: 1rem;
        }

        .logout-btn {
            background: transparent;
            color: var(--text-medium);
            padding: 0.75rem;
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
            padding: 1rem 1.5rem;
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
            font-size: 1.3rem;
            font-weight: 700;
        }

        /* Dashboard Grid Layout */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .dashboard-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            border-top: 4px solid var(--plp-green);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--plp-green);
        }

        .card-icon {
            width: 40px;
            height: 40px;
            background: var(--plp-green-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--plp-green);
        }

        /* Performance Metrics */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .metric-card {
            background: white;
            padding: 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            border-left: 4px solid var(--plp-green);
        }

        .metric-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.25rem;
        }

        .metric-label {
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

        /* Charts */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 1rem;
        }

        /* Subject List */
        .subject-list {
            list-style: none;
        }

        .subject-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            transition: var(--transition);
            cursor: pointer;
        }

        .subject-item:hover {
            background: var(--plp-green-pale);
        }

        .subject-info {
            flex: 1;
        }

        .subject-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .subject-code {
            font-size: 0.85rem;
            color: var(--text-medium);
        }

        .subject-grade {
            text-align: right;
        }

        .grade-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--plp-green);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            background: var(--plp-green-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--plp-green);
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-message {
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .activity-date {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        /* High Risk Subjects - Simple List */
        .high-risk-list {
            list-style: none;
        }

        .high-risk-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            transition: var(--transition);
            cursor: pointer;
        }

        .high-risk-item:hover {
            background: #fef5f5;
        }

        .high-risk-icon {
            width: 32px;
            height: 32px;
            background: #fed7d7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #c53030;
            flex-shrink: 0;
        }

        .high-risk-content {
            flex: 1;
        }

        .high-risk-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .high-risk-code {
            font-size: 0.85rem;
            color: var(--text-medium);
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-medium);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--plp-green-lighter);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .dashboard-container {
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
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
            <div class="header">
                <div class="welcome">Welcome, <?php echo htmlspecialchars($student['fullname']); ?>!</div>
                <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.9rem;">
                    <span><?php echo htmlspecialchars($student['semester']); ?> Semester</span>
                </div>
            </div>

            <!-- Performance Overview -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-value"><?php echo $total_subjects; ?></div>
                    <div class="metric-label">Total Subjects</div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?php echo $subjects_with_data; ?></div>
                    <div class="metric-label">With Scores</div>
                </div>  
                <div class="metric-card">
                    <div class="metric-value"><?php echo $risk_distribution['high']; ?></div>
                    <div class="metric-label">High Risk</div>
                </div>
            </div>

            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Risk Distribution Chart -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="card-title">Risk Distribution</div>
                    </div>
                    <div class="chart-container">
                        <canvas id="riskChart"></canvas>
                    </div>
                </div>

                <!-- High Risk Subjects -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="card-title">High Risk Subjects</div>
                    </div>
                    <?php if (!empty($high_risk_subjects)): ?>
                        <ul class="high-risk-list">
                            <?php foreach ($high_risk_subjects as $subject): ?>
                                <?php $performance = $subject_performance[$subject['id']]; ?>
                                <li class="high-risk-item" onclick="window.location.href='subject-management.php?subject_id=<?php echo $subject['id']; ?>'">
                                    <div class="high-risk-content">
                                        <div class="high-risk-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                        <div class="high-risk-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                                    </div>
                                    <div class="subject-grade">
                                        <div class="grade-value"><?php echo number_format($performance['overall_grade'], 1); ?>%</div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                            <p>No High Risk Subjects</p>
                            <small>Great job! All your subjects are on track.</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activities -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="card-title">Recent Activities</div>
                    </div>
                    <?php if (!empty($recent_activities)): ?>
                        <ul class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                                <li class="activity-item">
                                    <div class="activity-icon">
                                        <i class="<?php echo $activity['icon']; ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-message"><?php echo htmlspecialchars($activity['message']); ?></div>
                                        <div class="activity-date"><?php echo date('M j, Y g:i A', strtotime($activity['date'])); ?></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No recent activities</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
       // Risk Distribution Chart
        const riskCtx = document.getElementById('riskChart');
        if (riskCtx) {
            const riskChart = new Chart(riskCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Low Risk', 'Medium Risk', 'High Risk', 'No Data'],
                    datasets: [{
                        data: [
                            <?php echo $risk_distribution['low']; ?>,
                            <?php echo $risk_distribution['medium']; ?>,
                            <?php echo $risk_distribution['high']; ?>,
                            <?php echo $risk_distribution['no-data']; ?>
                        ],
                        backgroundColor: [
                            '#c6f6d5',
                            '#fef5e7',
                            '#fed7d7',
                            '#e2e8f0'
                        ],
                        borderColor: [
                            '#2f855a',
                            '#d69e2e',
                            '#c53030',
                            '#718096'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>