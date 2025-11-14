<?php
require_once 'config.php';
requireAdminRole();

$admin_id = $_SESSION['user_id'];
$admin = getAdminByEmail($_SESSION['user_email']);

// Initialize variables
$admin = null;
$total_students = 0;
$total_subjects = 0;
$recent_students = [];
$semester_risk_data = [];
$error_message = '';

try {
    // Get admin info
    $admin = getAdminByEmail($_SESSION['user_email']);
    
    if (!$admin) {
        $error_message = 'Admin record not found.';
    } else {
        // Get total students count
        $students = supabaseFetchAll('students');
        $total_students = $students ? count($students) : 0;
        
        // Get total subjects count
        $subjects = supabaseFetchAll('subjects');
        $total_subjects = $subjects ? count($subjects) : 0;
        
        // Get recent students (last 5)
        if ($students) {
            usort($students, function($a, $b) {
                $dateA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
                $dateB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
                return $dateB - $dateA;
            });
            $recent_students = array_slice($students, 0, 3);
        }

        // Get semester risk analysis data
        $semester_risk_data = getSemesterRiskAnalysis();
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in admin-dashboard.php: " . $e->getMessage());
}

/**
 * Get semester risk analysis data
 */
function getSemesterRiskAnalysis() {
    $data = [
        'first_semester' => [
            'total_students' => 0,
            'high_risk' => 0,
            'low_risk' => 0,
            'moderate_risk' => 0,
            'no_data' => 0
        ],
        'second_semester' => [
            'total_students' => 0,
            'high_risk' => 0,
            'low_risk' => 0,
            'moderate_risk' => 0,
            'no_data' => 0
        ],
        'overall_totals' => [
            'total_students' => 0,
            'high_risk' => 0,
            'low_risk' => 0,
            'moderate_risk' => 0,
            'no_data' => 0
        ]
    ];

    try {
        // Get all students
        $all_students = supabaseFetchAll('students');
        
        if ($all_students && is_array($all_students)) {
            foreach ($all_students as $student) {
                $student_id = $student['id'];
                $student_semester = strtolower($student['semester'] ?? '');
                
                // Initialize risk counts for this student
                $student_high_risk = 0;
                $student_low_risk = 0;
                $student_moderate_risk = 0;
                $student_has_data = false;

                // Get all subjects for this student
                $student_subjects = supabaseFetch('student_subjects', [
                    'student_id' => $student_id,
                    'deleted_at' => null
                ]);

                if ($student_subjects && is_array($student_subjects)) {
                    foreach ($student_subjects as $subject_record) {
                        // Get performance data
                        $performance_data = supabaseFetch('subject_performance', [
                            'student_subject_id' => $subject_record['id']
                        ]);

                        if ($performance_data && count($performance_data) > 0) {
                            $performance = $performance_data[0];
                            $risk_level = $performance['risk_level'] ?? 'no-data';
                            $student_has_data = true;

                            switch ($risk_level) {
                                case 'high_risk':
                                    $student_high_risk++;
                                    break;
                                case 'low_risk':
                                    $student_low_risk++;
                                    break;
                                case 'moderate_risk':
                                    $student_moderate_risk++;
                                    break;
                            }
                        }
                    }
                }

                // Determine overall risk for student (based on highest risk subject)
                $student_overall_risk = 'no_data';
                if ($student_has_data) {
                    if ($student_high_risk > 0) {
                        $student_overall_risk = 'high_risk';
                    } elseif ($student_moderate_risk > 0) {
                        $student_overall_risk = 'moderate_risk';
                    } elseif ($student_low_risk > 0) {
                        $student_overall_risk = 'low_risk';
                    }
                }

                // Categorize by semester
                if (strpos($student_semester, 'first') !== false || strpos($student_semester, '1') !== false) {
                    $data['first_semester']['total_students']++;
                    $data['first_semester'][$student_overall_risk]++;
                } elseif (strpos($student_semester, 'second') !== false || strpos($student_semester, '2') !== false) {
                    $data['second_semester']['total_students']++;
                    $data['second_semester'][$student_overall_risk]++;
                }

                // Add to overall totals
                $data['overall_totals']['total_students']++;
                $data['overall_totals'][$student_overall_risk]++;
            }
        }

    } catch (Exception $e) {
        error_log("Error getting semester risk analysis: " . $e->getMessage());
    }

    return $data;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PLP SmartGrade</title>
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

        .student-list {
            list-style: none;
        }

        .student-item {
            padding: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--plp-green);
            font-size: 0.9rem;
        }

        .student-details {
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        .student-email {
            color: var(--text-medium);
            font-size: 0.8rem;
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

        .three-column-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .three-column-grid .card {
            margin-bottom: 0;
        }

        .risk-stats {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .risk-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            background: var(--plp-green-pale);
        }

        .risk-stat-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .risk-stat-value {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .risk-high { color: var(--danger); }
        .risk-moderate { color: var(--warning); }
        .risk-low { color: var(--success); }
        .risk-no-data { color: var(--text-light); }

        .semester-risk-chart {
            height: 200px;
            margin-top: 1rem;
        }

        @media (max-width: 1200px) {
            .three-column-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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
            
            .three-column-grid {
                grid-template-columns: 1fr;
            }
        }
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
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-title {
            color: var(--plp-green);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .modal-body {
            margin-bottom: 2rem;
            color: var(--text-medium);
        }

        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .modal-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
        }

        .modal-btn-cancel {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }

        .modal-btn-cancel:hover {
            background: var(--plp-green-light);
            color: white;
            transform: translateY(-2px);
        }

        .modal-btn-confirm {
            background: var(--plp-green-gradient);
            color: white;
        }

        .modal-btn-confirm:hover {
            background: linear-gradient(135deg, var(--plp-green-light), var(--plp-green));
            transform: translateY(-2px);
        }
        .unread-badge {
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
        }

        .sidebar-badge {
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
            font-weight: 600;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
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
                <a href="admin-dashboard.php" class="nav-link active">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-students.php" class="nav-link ">
                    <i class="fas fa-users"></i>
                    Manage Students
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-messages.php" class="nav-link">
                    <i class="fas fa-comments"></i>
                    Messages
                    <?php 
                    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'admin');
                    if ($unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-system-logs.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    System Logs
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
            <div class="welcome">Welcome, <?php echo htmlspecialchars(explode(' ', $admin['fullname'])[0]); ?>!</div>
        </div>

        <!-- Admin Statistics -->
        <div class="dashboard-grid">
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-value"><?php echo $total_students; ?></div>
                    <div class="metric-label">Total Students</div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?php echo $total_subjects; ?></div>
                    <div class="metric-label">Total Subjects</div>
                </div>
            </div>
        </div>

        <div class="three-column-grid">
            <!-- Recent Students -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-user-graduate"></i>
                        Recent Students
                    </div>
                    <a href="admin-students.php" style="color: var(--plp-green); text-decoration: none; font-size: 0.9rem;">
                        View All
                    </a>
                </div>
                <?php if (!empty($recent_students)): ?>
                    <ul class="student-list">
                        <?php foreach ($recent_students as $student): ?>
                            <li class="student-item">
                                <div class="student-info">
                                    <div class="student-name"><?php echo htmlspecialchars($student['fullname']); ?></div>
                                    <div class="student-details">
                                        <?php echo htmlspecialchars($student['student_number']); ?> • 
                                        <?php echo htmlspecialchars($student['section']); ?>
                                    </div>
                                    <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <p>No students found</p>
                        <small>Students will appear here once they register</small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Semester Risk Analysis -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        Semester Risk Analysis
                    </div>
                </div>
                <div class="risk-stats">
                    <!-- First Semester -->
                    <div style="margin-bottom: 1.5rem;">
                        <h4 style="color: var(--plp-green); margin-bottom: 0.75rem; font-size: 0.9rem;">
                            <i class="fas fa-calendar-alt"></i> First Semester
                        </h4>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>High Risk</span>
                            </div>
                            <div class="risk-stat-value risk-high">
                                <?php echo $semester_risk_data['first_semester']['high_risk']; ?>
                            </div>
                        </div>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-minus-circle"></i>
                                <span>Moderate Risk</span>
                            </div>
                            <div class="risk-stat-value risk-moderate">
                                <?php echo $semester_risk_data['first_semester']['moderate_risk']; ?>
                            </div>
                        </div>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-check-circle"></i>
                                <span>Low Risk</span>
                            </div>
                            <div class="risk-stat-value risk-low">
                                <?php echo $semester_risk_data['first_semester']['low_risk']; ?>
                            </div>
                        </div>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-question-circle"></i>
                                <span>No Data</span>
                            </div>
                            <div class="risk-stat-value risk-no-data">
                                <?php echo $semester_risk_data['first_semester']['no_data']; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Second Semester -->
                    <div>
                        <h4 style="color: var(--plp-green); margin-bottom: 0.75rem; font-size: 0.9rem;">
                            <i class="fas fa-calendar-alt"></i> Second Semester
                        </h4>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>High Risk</span>
                            </div>
                            <div class="risk-stat-value risk-high">
                                <?php echo $semester_risk_data['second_semester']['high_risk']; ?>
                            </div>
                        </div>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-minus-circle"></i>
                                <span>Moderate Risk</span>
                            </div>
                            <div class="risk-stat-value risk-moderate">
                                <?php echo $semester_risk_data['second_semester']['moderate_risk']; ?>
                            </div>
                        </div>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-check-circle"></i>
                                <span>Low Risk</span>
                            </div>
                            <div class="risk-stat-value risk-low">
                                <?php echo $semester_risk_data['second_semester']['low_risk']; ?>
                            </div>
                        </div>
                        <div class="risk-stat-item">
                            <div class="risk-stat-label">
                                <i class="fas fa-question-circle"></i>
                                <span>No Data</span>
                            </div>
                            <div class="risk-stat-value risk-no-data">
                                <?php echo $semester_risk_data['second_semester']['no_data']; ?>
                            </div>
                        </div>
                    </div>
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