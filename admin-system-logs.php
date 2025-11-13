<?php
require_once 'config.php';
requireAdminRole();

// Get admin info
$admin = getAdminByEmail($_SESSION['user_email']);

if (!$admin) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Initialize variables
$error_message = '';
$system_logs = [];
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_student = $_GET['student'] ?? '';

// Get system logs with student information
try {
    // Build base query
    $query_filters = [];
    
    // Filter by date if provided
    if (!empty($filter_date)) {
        $query_filters[] = "date(created_at)=eq.'$filter_date'";
    }
    
    // Filter by student if provided
    if (!empty($filter_student)) {
        $query_filters[] = "user_email=ilike.*$filter_student*";
    }
    
    // Only get student login/logout logs
    $query_filters[] = "user_type=eq.student";
    $query_filters[] = "or=(action=eq.login,action=eq.logout)";
    
    $filter_string = implode('&', $query_filters);
    $system_logs = supabaseFetch('system_logs', [], 'GET', null, $filter_string);
    
    if (!$system_logs) {
        $system_logs = [];
    }
    
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Process logs to group by student and session
function processStudentSessions($logs) {
    $sessions = [];
    
    foreach ($logs as $log) {
        $email = $log['user_email'];
        $action = $log['action'];
        $timestamp = strtotime($log['created_at']);
        
        if (!isset($sessions[$email])) {
            $sessions[$email] = [
                'email' => $email,
                'logins' => [],
                'logouts' => []
            ];
        }
        
        if ($action === 'login') {
            $sessions[$email]['logins'][] = $timestamp;
        } elseif ($action === 'logout') {
            $sessions[$email]['logouts'][] = $timestamp;
        }
    }
    
    // Sort timestamps and create session pairs
    $student_sessions = [];
    
    foreach ($sessions as $email => $data) {
        // Sort timestamps
        sort($data['logins']);
        sort($data['logouts']);
        
        $login_count = count($data['logins']);
        $logout_count = count($data['logouts']);
        
        // Create session pairs
        for ($i = 0; $i < max($login_count, $logout_count); $i++) {
            $login_time = isset($data['logins'][$i]) ? $data['logins'][$i] : null;
            $logout_time = isset($data['logouts'][$i]) ? $data['logouts'][$i] : null;
            
            // Only add if we have at least a login time
            if ($login_time) {
                $student_sessions[] = [
                    'email' => $email,
                    'login_time' => $login_time,
                    'logout_time' => $logout_time,
                    'session_duration' => $logout_time ? $logout_time - $login_time : null
                ];
            }
        }
    }
    
    // Sort by login time descending
    usort($student_sessions, function($a, $b) {
        return $b['login_time'] - $a['login_time'];
    });
    
    return $student_sessions;
}

$student_sessions = processStudentSessions($system_logs);

// Get all students for filter dropdown
$all_students = supabaseFetch('students', []);
$student_options = [];
if ($all_students) {
    foreach ($all_students as $student) {
        $student_options[$student['email']] = $student['student_number'] . ' - ' . $student['fullname'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - PLP SmartGrade</title>
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
            position: relative;
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

        .card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            border-top: 4px solid var(--plp-green);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--plp-green-gradient);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .card-title {
            color: var(--plp-green);
            font-size: 1.4rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
            padding: 0;
            border: none;
        }

        .card-title i {
            font-size: 1.2rem;
            width: 32px;
            height: 32px;
            background: var(--plp-green-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Filters Section */
        .filters-container {
            background: var(--plp-green-pale);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border: 1px solid var(--plp-green-lighter);
        }

        .filters-title {
            color: var(--plp-green);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .filter-input, .filter-select {
            padding: 0.75rem 1rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            transition: var(--transition);
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .filter-actions {
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
            border: 2px solid var(--plp-green);
        }

        .btn-secondary:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-2px);
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 0.9rem;
        }

        .logs-table th {
            background: var(--plp-green);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .logs-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            vertical-align: top;
        }

        .logs-table tr:hover {
            background: var(--plp-green-pale);
        }

        .logs-table tr:last-child td {
            border-bottom: none;
        }

        .student-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .student-number {
            font-weight: 600;
            color: var(--plp-green);
            font-size: 0.95rem;
        }

        .student-email {
            color: var(--text-medium);
            font-size: 0.85rem;
        }

        .time-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        .duration-cell {
            text-align: center;
        }

        .duration-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .duration-active {
            background: #c6f6d5;
            color: #2f855a;
        }

        .duration-completed {
            background: #e2e8f0;
            color: #4a5568;
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #c6f6d5;
            color: #2f855a;
        }

        .status-completed {
            background: #e2e8f0;
            color: #4a5568;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            border-left: 4px solid var(--plp-green);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--plp-green);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-medium);
            font-weight: 500;
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
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
            }
        }

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
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .logs-table {
                font-size: 0.8rem;
            }
            
            .logs-table th,
            .logs-table td {
                padding: 0.75rem 0.5rem;
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
                <a href="admin-system-logs.php" class="nav-link active">
                    <i class="fas fa-clipboard-list"></i>
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
            <div class="welcome">System Logs</div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($student_sessions); ?></div>
                <div class="stat-label">Total Sessions</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                    $active_sessions = array_filter($student_sessions, function($session) {
                        return $session['logout_time'] === null;
                    });
                    echo count($active_sessions);
                    ?>
                </div>
                <div class="stat-label">Active Sessions</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                    $unique_students = array_unique(array_column($student_sessions, 'email'));
                    echo count($unique_students);
                    ?>
                </div>
                <div class="stat-label">Unique Students</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-clipboard-list"></i>
                    Student Login Sessions
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-container">
                <div class="filters-title">
                    <i class="fas fa-filter"></i>
                    Filter Logs
                </div>
                
                <form method="GET" action="admin-system-logs.php">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Date</label>
                            <input type="date" name="date" class="filter-input" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Student (Email)</label>
                            <input type="text" name="student" class="filter-input" placeholder="Search by email..." value="<?php echo htmlspecialchars($filter_student); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                            Apply Filters
                        </button>
                        <a href="admin-system-logs.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i>
                            Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Logs Table -->
            <div class="table-container">
                <?php if (!empty($student_sessions)): ?>
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>Student Information</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_sessions as $session): 
                                // Get student details
                                $student_details = null;
                                foreach ($all_students as $student) {
                                    if ($student['email'] === $session['email']) {
                                        $student_details = $student;
                                        break;
                                    }
                                }
                                
                                $login_time = date('M j, Y g:i A', $session['login_time']);
                                $logout_time = $session['logout_time'] ? date('M j, Y g:i A', $session['logout_time']) : 'Active';
                                $is_active = $session['logout_time'] === null;
                                $duration = $session['session_duration'];
                                
                                if ($is_active) {
                                    $duration_text = 'Active';
                                    $duration_class = 'duration-active';
                                    $status_text = 'ACTIVE';
                                    $status_class = 'status-active';
                                } else {
                                    $hours = floor($duration / 3600);
                                    $minutes = floor(($duration % 3600) / 60);
                                    $duration_text = sprintf('%02d:%02d', $hours, $minutes);
                                    $duration_class = 'duration-completed';
                                    $status_text = 'COMPLETED';
                                    $status_class = 'status-completed';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-number">
                                                <?php echo $student_details ? htmlspecialchars($student_details['student_number']) : 'N/A'; ?>
                                            </div>
                                            <div class="student-email">
                                                <?php echo htmlspecialchars($session['email']); ?>
                                            </div>
                                            <?php if ($student_details): ?>
                                                <div style="font-size: 0.8rem; color: var(--text-light);">
                                                    <?php echo htmlspecialchars($student_details['fullname']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="time-cell"><?php echo $login_time; ?></td>
                                    <td class="time-cell"><?php echo $logout_time; ?></td>
                                    <td class="duration-cell">
                                        <span class="duration-badge <?php echo $duration_class; ?>">
                                            <?php echo $duration_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No login sessions found</p>
                        <small>Try adjusting your filters or check back later</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
        // Auto-refresh page every 60 seconds to update logs
        setTimeout(() => {
            location.reload();
        }, 60000);

        // Add some interactivity to the table
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.logs-table tbody tr');
            
            tableRows.forEach(row => {
                row.addEventListener('click', function() {
                    this.classList.toggle('active');
                });
            });
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
        const modals = [addSubjectModal, editSubjectModal, archiveSubjectModal, logoutModal];
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>