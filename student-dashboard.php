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
$upcoming_deadlines = [];
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
        
        // Get recent scores (last 5 scores across all subjects)
        $all_scores = supabaseFetchAll('student_subject_scores');
        if ($all_scores && is_array($all_scores)) {
            // Sort by creation date descending and take first 5
            usort($all_scores, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            $recent_scores = array_slice($all_scores, 0, 5);
        }
        
        // Calculate overall performance metrics
        $performance_metrics = calculatePerformanceMetrics($student['id']);
        
        // Get upcoming deadlines (simulated - you can implement this with actual deadlines)
        $upcoming_deadlines = getUpcomingDeadlines($student['id']);
        
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in student-dashboard.php: " . $e->getMessage());
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
 * Get upcoming deadlines (simulated data - you can implement with actual deadlines)
 */
function getUpcomingDeadlines($student_id) {
    $deadlines = [];
    
    // This is simulated data - you can replace with actual deadline tracking
    $simulated_deadlines = [
        ['title' => 'Programming Project Submission', 'subject' => 'COMP 104', 'due_date' => date('Y-m-d', strtotime('+3 days')), 'type' => 'project'],
        ['title' => 'Database Design Quiz', 'subject' => 'COMP 105', 'due_date' => date('Y-m-d', strtotime('+1 day')), 'type' => 'quiz'],
        ['title' => 'Math Problem Set', 'subject' => 'IT 102', 'due_date' => date('Y-m-d', strtotime('+5 days')), 'type' => 'assignment']
    ];
    
    foreach ($simulated_deadlines as $deadline) {
        $deadlines[] = [
            'title' => $deadline['title'],
            'subject' => $deadline['subject'],
            'due_date' => $deadline['due_date'],
            'type' => $deadline['type'],
            'days_remaining' => ceil((strtotime($deadline['due_date']) - time()) / (60 * 60 * 24))
        ];
    }
    
    // Sort by days remaining
    usort($deadlines, function($a, $b) {
        return $a['days_remaining'] - $b['days_remaining'];
    });
    
    return array_slice($deadlines, 0, 5); // Return only 5 nearest deadlines
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

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .card-title {
            color: var(--plp-green);
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .metric-card {
            text-align: center;
            padding: 1rem;
            background: var(--plp-green-pale);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .metric-card:hover {
            background: var(--plp-green-lighter);
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

        .subject-list {
            list-style: none;
        }

        .subject-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .subject-item:last-child {
            border-bottom: none;
        }

        .subject-info {
            flex: 1;
        }

        .subject-code {
            font-weight: 600;
            color: var(--plp-green);
            font-size: 0.9rem;
        }

        .subject-name {
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .subject-grade {
            text-align: right;
        }

        .grade-value {
            font-weight: 700;
            font-size: 0.9rem;
        }

        .grade-excellent { color: var(--success); }
        .grade-good { color: var(--info); }
        .grade-average { color: var(--warning); }
        .grade-poor { color: var(--danger); }
        .grade-no-data { color: var(--text-light); font-style: italic; }

        .gwa-value {
            font-size: 0.8rem;
            color: var(--text-medium);
        }

        .score-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .score-item:last-child {
            border-bottom: none;
        }

        .score-info {
            flex: 1;
        }

        .score-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .score-subject {
            color: var(--text-medium);
            font-size: 0.8rem;
        }

        .score-value {
            font-weight: 700;
            color: var(--plp-green);
        }

        .deadline-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .deadline-item:last-child {
            border-bottom: none;
        }

        .deadline-info {
            flex: 1;
        }

        .deadline-title {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .deadline-subject {
            color: var(--text-medium);
            font-size: 0.8rem;
        }

        .deadline-date {
            text-align: right;
        }

        .days-remaining {
            font-weight: 700;
            font-size: 0.9rem;
        }

        .days-urgent { color: var(--danger); }
        .days-warning { color: var(--warning); }
        .days-normal { color: var(--success); }

        .deadline-type {
            font-size: 0.8rem;
            color: var(--text-medium);
            text-transform: capitalize;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-medium);
        }

        .empty-state i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--plp-green-lighter);
        }

        .empty-state p {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
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

        /* Risk badges */
        .risk-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .metrics-grid {
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
        <?php if ($error_message): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <div class="header">
            <div class="welcome">Welcome back, <?php echo htmlspecialchars(explode(' ', $student['fullname'])[0]); ?>!</div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="background: rgba(255,255,255,0.2); padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600;">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('F j, Y'); ?>
                </div>
            </div>
        </div>

        <!-- Risk Distribution -->
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-shield-alt"></i>
                        Risk Distribution
                    </div>
                </div>
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-value" style="color: var(--success);"><?php echo $performance_metrics['low_risk_count']; ?></div>
                        <div class="metric-label">Low Risk</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" style="color: var(--warning);"><?php echo $performance_metrics['medium_risk_count']; ?></div>
                        <div class="metric-label">Medium Risk</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" style="color: var(--danger);"><?php echo $performance_metrics['high_risk_count']; ?></div>
                        <div class="metric-label">High Risk</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value" style="color: var(--text-light);">
                            <?php echo $performance_metrics['total_subjects'] - $performance_metrics['subjects_with_scores']; ?>
                        </div>
                        <div class="metric-label">No Data</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="dashboard-grid">
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
                        <?php foreach (array_slice($active_subjects, 0, 5) as $subject): ?>
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
                                        <div class="grade-value grade-no-data">No Data</div>
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
                                    <div class="score-subject"><?php echo htmlspecialchars($score['score_type']); ?></div>
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
                        <small>Add scores to see recent activity</small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Deadlines -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-clock"></i>
                        Upcoming Deadlines
                    </div>
                </div>
                <?php if (!empty($upcoming_deadlines)): ?>
                    <ul class="subject-list">
                        <?php foreach ($upcoming_deadlines as $deadline): ?>
                            <li class="deadline-item">
                                <div class="deadline-info">
                                    <div class="deadline-title"><?php echo htmlspecialchars($deadline['title']); ?></div>
                                    <div class="deadline-subject">
                                        <?php echo htmlspecialchars($deadline['subject']); ?> • 
                                        <span class="deadline-type"><?php echo $deadline['type']; ?></span>
                                    </div>
                                </div>
                                <div class="deadline-date">
                                    <div class="days-remaining 
                                        <?php 
                                        if ($deadline['days_remaining'] <= 1) echo 'days-urgent';
                                        elseif ($deadline['days_remaining'] <= 3) echo 'days-warning';
                                        else echo 'days-normal';
                                        ?>
                                    ">
                                        <?php echo $deadline['days_remaining']; ?> day<?php echo $deadline['days_remaining'] != 1 ? 's' : ''; ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--text-light);">
                                        <?php echo date('M j', strtotime($deadline['due_date'])); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>No upcoming deadlines</p>
                        <small>All caught up for now!</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-error');
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

        // Add subtle animations to metric cards
        document.addEventListener('DOMContentLoaded', function() {
            const metricCards = document.querySelectorAll('.metric-card');
            metricCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'fadeInUp 0.6s ease-out';
            });
        });
    </script>
</body>
</html>