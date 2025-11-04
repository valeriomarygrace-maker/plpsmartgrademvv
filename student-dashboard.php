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
 * Get semester risk data for pie charts - UPDATED FOR SINGLE PIE CHART
 */
function getSemesterRiskData($student_id) {
    $data = [
        'high_risk_count' => 0,
        'other_subjects_count' => 0,
        'total_archived_subjects' => 0
    ];
    
    try {
        // Get all archived subjects for this student
        $archived_subjects = supabaseFetch('archived_subjects', ['student_id' => $student_id]);
        
        if ($archived_subjects && is_array($archived_subjects)) {
            $data['total_archived_subjects'] = count($archived_subjects);
            
            foreach ($archived_subjects as $archived_subject) {
                // Get performance data for archived subject
                $performance_data = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject['id']]);
                
                if ($performance_data && count($performance_data) > 0) {
                    $performance = $performance_data[0];
                    if ($performance['risk_level'] === 'high') {
                        $data['high_risk_count']++;
                    }
                }
            }
            
            // Calculate other subjects count (non-high risk)
            $data['other_subjects_count'] = $data['total_archived_subjects'] - $data['high_risk_count'];
        }
        
    } catch (Exception $e) {
        error_log("Error getting semester risk data: " . $e->getMessage());
    }
    
    return $data;
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
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            background: white;
            text-align: center;
            padding: 1rem;
            border-radius: var(--border-radius);
            transition: var(--transition);
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

        /* Graph Styles */
        .graph-container {
            height: 250px;
            position: relative;
            margin-top: 1rem;
        }

        .semester-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .stat-card {
            background: var(--plp-green-pale);
            padding: 0.75rem;
            border-radius: var(--border-radius);
            text-align: center;
            border-left: 3px solid var(--plp-green);
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-medium);
            font-weight: 500;
        }

        /* Single Pie Chart Styles */
        .pie-chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            margin-top: 1rem;
        }

        .pie-chart-title {
            color: var(--plp-green);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .pie-chart-wrapper {
            position: relative;
            height: 250px;
            margin: 0 auto;
        }

        .pie-chart-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .pie-stat-card {
            background: var(--plp-green-pale);
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            border-left: 3px solid var(--plp-green);
        }

        .pie-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.25rem;
        }

        .pie-stat-label {
            font-size: 0.8rem;
            color: var(--text-medium);
            font-weight: 500;
        }

        /* Modal Styles */
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
            
            .semester-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .pie-chart-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .pie-chart-stats {
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
        </div>

        <!-- Academic Statistics -->
        <div class="dashboard-grid">
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-value"><?php echo $performance_metrics['total_subjects']; ?></div>
                    <div class="metric-label">Total Subjects</div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?php echo count(getUniqueProfessors($student['id'])); ?></div>
                    <div class="metric-label">Professors</div>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="dashboard-grid">
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
                                        <div class="grade-value grade-no-data">No Data</div>
                                        <div class="gwa-value">
                                            <span class="risk-badge no-data">No Data</span>
                                        </div>
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
        </div>

        <!-- High Risk Subjects Analysis -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-chart-pie"></i>
                    High Risk Subjects Analysis
                </div>
            </div>
            
            <?php if ($semester_risk_data['total_archived_subjects'] > 0): ?>
                <div class="pie-chart-container">
                    <div class="pie-chart-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        High Risk Subjects in Archived Records
                    </div>
                    <div class="pie-chart-wrapper">
                        <canvas id="highRiskPieChart"></canvas>
                    </div>
                    
                    <div class="pie-chart-stats">
                        <div class="pie-stat-card">
                            <div class="pie-stat-value"><?php echo $semester_risk_data['high_risk_count']; ?></div>
                            <div class="pie-stat-label">High Risk Subjects</div>
                        </div>
                        <div class="pie-stat-card">
                            <div class="pie-stat-value"><?php echo $semester_risk_data['total_archived_subjects']; ?></div>
                            <div class="pie-stat-label">Total Archived Subjects</div>
                        </div>
                    </div>
                    
                    <?php if ($semester_risk_data['high_risk_count'] > 0): ?>
                        <div style="margin-top: 1rem; padding: 1rem; background: #fef5e7; border-radius: var(--border-radius); border-left: 4px solid var(--warning);">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i>
                                <strong style="color: var(--text-dark);">Attention Needed</strong>
                            </div>
                            <p style="color: var(--text-medium); font-size: 0.9rem; margin: 0;">
                                You have <?php echo $semester_risk_data['high_risk_count']; ?> high-risk subject(s) in your academic history. 
                                Consider reviewing these subjects and seeking academic support if needed.
                            </p>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 1rem; padding: 1rem; background: #f0f9ff; border-radius: var(--border-radius); border-left: 4px solid var(--info);">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-check-circle" style="color: var(--info);"></i>
                                <strong style="color: var(--text-dark);">Good Performance</strong>
                            </div>
                            <p style="color: var(--text-medium); font-size: 0.9rem; margin: 0.5rem 0 0 0;">
                                No high-risk subjects found in your archived records. Keep up the good work!
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-archive" style="font-size: 2.5rem; color: var(--text-light); margin-bottom: 1rem;"></i>
                    <p>No Archived Subjects Found</p>
                    <small>Subjects will appear here once you archive them from your active subjects</small>
                    <br>
                    <a href="student-archived-subject.php" class="btn-primary" style="margin-top: 1rem; border-radius: 10px; text-decoration: none; display: inline-block; padding: 0.75rem 1.5rem;">
                        <i class="fas fa-archive"></i> View Archived Subjects
                    </a>
                </div>
            <?php endif; ?>
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

        // Initialize Single Pie Chart for High Risk Subjects
        function initializeHighRiskPieChart() {
            // Get semester risk data from PHP
            const semesterRiskData = <?php echo json_encode($semester_risk_data); ?>;
            
            // Only initialize if there are archived subjects
            if (semesterRiskData.total_archived_subjects > 0) {
                const highRiskCtx = document.getElementById('highRiskPieChart').getContext('2d');
                const highRiskData = {
                    labels: ['High Risk Subjects', 'Other Subjects'],
                    datasets: [{
                        data: [
                            semesterRiskData.high_risk_count || 0,
                            semesterRiskData.other_subjects_count || 0
                        ],
                        backgroundColor: [
                            '#dc3545', // High Risk - Red
                            '#e0f2e9'  // Other Subjects - Light Green
                        ],
                        borderColor: [
                            '#c53030',
                            '#006341'
                        ],
                        borderWidth: 2
                    }]
                };
                
                new Chart(highRiskCtx, {
                    type: 'pie',
                    data: highRiskData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    usePointStyle: true,
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
                        },
                        animation: {
                            animateScale: true,
                            animateRotate: true
                        }
                    }
                });
            }
        }

        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeHighRiskPieChart();
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