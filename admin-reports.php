<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Initialize variables
$admin = null;
$report_data = [];
$error_message = '';
$report_type = $_GET['report_type'] ?? 'overview';
$time_period = $_GET['time_period'] ?? 'current_semester';

try {
    // Get admin info
    $admin = getAdminByEmail($_SESSION['user_email']);
    
    if (!$admin) {
        $error_message = 'Admin record not found.';
    } else {
        // Get report data based on type
        $report_data = generateReportData($report_type, $time_period);
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in admin-reports.php: " . $e->getMessage());
}

/**
 * Generate report data based on type and time period
 */
function generateReportData($report_type, $time_period) {
    $data = [];
    
    switch($report_type) {
        case 'student_performance':
            $data = getStudentPerformanceReport($time_period);
            break;
        case 'subject_analysis':
            $data = getSubjectAnalysisReport($time_period);
            break;
        case 'risk_assessment':
            $data = getRiskAssessmentReport($time_period);
            break;
        case 'overview':
        default:
            $data = getOverviewReport($time_period);
            break;
    }
    
    return $data;
}

/**
 * Get overview report data
 */
function getOverviewReport($time_period) {
    $data = [];
    
    // Total students
    $students = supabaseFetchAll('students');
    $data['total_students'] = $students ? count($students) : 0;
    
    // Total subjects
    $subjects = supabaseFetchAll('subjects');
    $data['total_subjects'] = $subjects ? count($subjects) : 0;
    
    // Active students (with subjects)
    $active_students = 0;
    if ($students) {
        foreach ($students as $student) {
            $student_subjects = supabaseFetch('student_subjects', ['student_id' => $student['id'], 'deleted_at' => null]);
            if ($student_subjects && count($student_subjects) > 0) {
                $active_students++;
            }
        }
    }
    $data['active_students'] = $active_students;
    
    // Risk distribution
    $risk_data = getRiskDistribution();
    $data['risk_distribution'] = $risk_data;
    
    // Recent activity
    $data['recent_activity'] = getRecentActivity();
    
    return $data;
}

/**
 * Get student performance report
 */
function getStudentPerformanceReport($time_period) {
    $data = [];
    
    // Get all students with their performance data
    $students = supabaseFetchAll('students');
    $performance_data = [];
    
    if ($students) {
        foreach ($students as $student) {
            $student_performance = calculateStudentPerformance($student['id']);
            if ($student_performance) {
                $performance_data[] = [
                    'student' => $student,
                    'performance' => $student_performance
                ];
            }
        }
    }
    
    $data['performance_data'] = $performance_data;
    $data['summary'] = calculatePerformanceSummary($performance_data);
    
    return $data;
}

/**
 * Get subject analysis report
 */
function getSubjectAnalysisReport($time_period) {
    $data = [];
    
    $subjects = supabaseFetchAll('subjects');
    $subject_analysis = [];
    
    if ($subjects) {
        foreach ($subjects as $subject) {
            $analysis = analyzeSubjectPerformance($subject['id']);
            if ($analysis) {
                $subject_analysis[] = $analysis;
            }
        }
    }
    
    $data['subject_analysis'] = $subject_analysis;
    
    return $data;
}

/**
 * Get risk assessment report
 */
function getRiskAssessmentReport($time_period) {
    $data = [];
    
    $students = supabaseFetchAll('students');
    $risk_assessments = [];
    
    if ($students) {
        foreach ($students as $student) {
            $risk_data = assessStudentRisk($student['id']);
            if ($risk_data) {
                $risk_assessments[] = [
                    'student' => $student,
                    'risk_data' => $risk_data
                ];
            }
        }
    }
    
    $data['risk_assessments'] = $risk_assessments;
    $data['risk_summary'] = calculateRiskSummary($risk_assessments);
    
    return $data;
}

/**
 * Helper functions for report generation
 */
function getRiskDistribution() {
    $distribution = [
        'high_risk' => 0,
        'medium_risk' => 0,
        'low_risk' => 0,
        'no_data' => 0
    ];
    
    $students = supabaseFetchAll('students');
    if (!$students) return $distribution;
    
    foreach ($students as $student) {
        $student_subjects = supabaseFetch('student_subjects', ['student_id' => $student['id'], 'deleted_at' => null]);
        $has_risk_data = false;
        
        if ($student_subjects) {
            foreach ($student_subjects as $subject) {
                $performance = supabaseFetch('subject_performance', ['student_subject_id' => $subject['id']]);
                if ($performance && count($performance) > 0) {
                    $has_risk_data = true;
                    $risk_level = $performance[0]['risk_level'] ?? 'no-data';
                    if ($risk_level !== 'no-data') {
                        $distribution[$risk_level . '_risk']++;
                    }
                }
            }
        }
        
        if (!$has_risk_data) {
            $distribution['no_data']++;
        }
    }
    
    return $distribution;
}

function getRecentActivity() {
    $activity = [];
    
    // Get recent student registrations (last 7 days)
    $students = supabaseFetchAll('students');
    if ($students) {
        $recent_students = array_filter($students, function($student) {
            $created = strtotime($student['created_at']);
            return $created >= (time() - 7 * 24 * 60 * 60);
        });
        $activity['new_registrations'] = count($recent_students);
    }
    
    return $activity;
}

function calculateStudentPerformance($student_id) {
    $performance = [];
    
    $student_subjects = supabaseFetch('student_subjects', ['student_id' => $student_id, 'deleted_at' => null]);
    if (!$student_subjects) return null;
    
    $total_grade = 0;
    $subject_count = 0;
    $risk_subjects = 0;
    
    foreach ($student_subjects as $subject) {
        $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject['id']]);
        if ($performance_data && count($performance_data) > 0) {
            $perf = $performance_data[0];
            if ($perf['overall_grade'] > 0) {
                $total_grade += $perf['overall_grade'];
                $subject_count++;
                
                if ($perf['risk_level'] === 'high') {
                    $risk_subjects++;
                }
            }
        }
    }
    
    if ($subject_count > 0) {
        $performance['average_grade'] = round($total_grade / $subject_count, 2);
        $performance['subject_count'] = $subject_count;
        $performance['risk_subjects'] = $risk_subjects;
        $performance['performance_level'] = getPerformanceLevel($performance['average_grade']);
    }
    
    return $performance;
}

function getPerformanceLevel($grade) {
    if ($grade >= 90) return 'Excellent';
    if ($grade >= 80) return 'Good';
    if ($grade >= 75) return 'Average';
    return 'Needs Improvement';
}

function analyzeSubjectPerformance($subject_id) {
    $analysis = [];
    
    $subject = getSubjectById($subject_id);
    if (!$subject) return null;
    
    $student_subjects = supabaseFetch('student_subjects', ['subject_id' => $subject_id, 'deleted_at' => null]);
    if (!$student_subjects) return null;
    
    $analysis['subject'] = $subject;
    $analysis['total_students'] = count($student_subjects);
    
    $total_grade = 0;
    $students_with_grades = 0;
    $high_risk_count = 0;
    
    foreach ($student_subjects as $student_subject) {
        $performance = supabaseFetch('subject_performance', ['student_subject_id' => $student_subject['id']]);
        if ($performance && count($performance) > 0 && $performance[0]['overall_grade'] > 0) {
            $total_grade += $performance[0]['overall_grade'];
            $students_with_grades++;
            
            if ($performance[0]['risk_level'] === 'high') {
                $high_risk_count++;
            }
        }
    }
    
    if ($students_with_grades > 0) {
        $analysis['average_grade'] = round($total_grade / $students_with_grades, 2);
        $analysis['students_with_grades'] = $students_with_grades;
        $analysis['high_risk_percentage'] = round(($high_risk_count / $students_with_grades) * 100, 2);
    }
    
    return $analysis;
}

function assessStudentRisk($student_id) {
    $risk_data = [];
    
    $performance = calculateStudentPerformance($student_id);
    if (!$performance) return null;
    
    $risk_data['performance'] = $performance;
    
    // Calculate overall risk level
    $risk_score = 0;
    if ($performance['average_grade'] < 75) $risk_score += 3;
    elseif ($performance['average_grade'] < 80) $risk_score += 2;
    elseif ($performance['average_grade'] < 85) $risk_score += 1;
    
    $risk_score += $performance['risk_subjects'];
    
    if ($risk_score >= 4) $risk_data['overall_risk'] = 'high';
    elseif ($risk_score >= 2) $risk_data['overall_risk'] = 'medium';
    else $risk_data['overall_risk'] = 'low';
    
    return $risk_data;
}

function calculatePerformanceSummary($performance_data) {
    $summary = [
        'excellent' => 0,
        'good' => 0,
        'average' => 0,
        'needs_improvement' => 0,
        'total_students' => count($performance_data)
    ];
    
    foreach ($performance_data as $data) {
        $level = $data['performance']['performance_level'] ?? 'Unknown';
        switch($level) {
            case 'Excellent': $summary['excellent']++; break;
            case 'Good': $summary['good']++; break;
            case 'Average': $summary['average']++; break;
            case 'Needs Improvement': $summary['needs_improvement']++; break;
        }
    }
    
    return $summary;
}

function calculateRiskSummary($risk_assessments) {
    $summary = [
        'high_risk' => 0,
        'medium_risk' => 0,
        'low_risk' => 0,
        'total_assessed' => count($risk_assessments)
    ];
    
    foreach ($risk_assessments as $assessment) {
        $risk_level = $assessment['risk_data']['overall_risk'] ?? 'low';
        $summary[$risk_level . '_risk']++;
    }
    
    return $summary;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - PLP SmartGrade</title>
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

        .admin-email {
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

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        /* Report Controls */
        .report-controls {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .control-group {
            flex: 1;
            min-width: 200px;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .generate-btn {
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .generate-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        /* Report Content */
        .report-content {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .report-header {
            background: var(--plp-green-pale);
            padding: 1.5rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .report-title {
            color: var(--plp-green);
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .report-body {
            padding: 1.5rem;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-medium);
            font-weight: 500;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .chart-title {
            color: var(--plp-green);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            text-align: center;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .data-table th {
            background: var(--plp-green);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: var(--plp-green-pale);
        }

        .risk-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .risk-high {
            background: #fed7d7;
            color: #c53030;
        }

        .risk-medium {
            background: #fef5e7;
            color: #d69e2e;
        }

        .risk-low {
            background: #c6f6d5;
            color: #2f855a;
        }

        /* Empty State */
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
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        /* Alerts */
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

        /* Responsive Design */
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
            
            .report-controls {
                flex-direction: column;
            }
            
            .control-group {
                min-width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
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

        .modal-title {
            color: var(--plp-green);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .modal-body {
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-col {
            flex: 1;
        }

        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .modal-btn {
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
            min-width: 120px;
            justify-content: center;
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
            <div class="admin-email"><?php echo htmlspecialchars($admin['email']); ?></div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="admin-dashboard.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-students.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Manage Students
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Manage Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-reports.php" class="nav-link active">
                    <i class="fas fa-chart-bar"></i>
                    Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-settings.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    System Settings
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
            <div class="welcome">Analytics & Reports</div>
            <div class="header-actions">
                <a href="admin-dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
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
    // Logout modal functionality
        const logoutBtn = document.querySelector('.logout-btn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogout = document.getElementById('confirmLogout');

        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutModal.classList.add('show');
        });

        cancelLogout.addEventListener('click', () => {
            logoutModal.classList.remove('show');
        });

        confirmLogout.addEventListener('click', () => {
            window.location.href = 'logout.php';
        });

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target === document.getElementById('updateStudentModal')) {
                closeUpdateModal();
            }
            if (e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });
    </script>
</body>
</html>