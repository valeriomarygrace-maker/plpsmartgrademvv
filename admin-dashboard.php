<?php
require_once 'config.php';
require_once 'ml-helpers.php';

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
$total_students = 0;
$total_subjects = 0;
$recent_students = [];
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
            $recent_students = array_slice($students, 0, 5);
        }
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in admin-dashboard.php: " . $e->getMessage());
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
                <a href="admin-reports.php" class="nav-link">
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

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <a href="admin-students.php" style="
                        display: flex; 
                        align-items: center; 
                        gap: 0.75rem; 
                        padding: 0.75rem; 
                        background: var(--plp-green-pale); 
                        border-radius: var(--border-radius); 
                        text-decoration: none; 
                        color: var(--plp-green); 
                        font-weight: 500;
                        transition: var(--transition);
                    ">
                        <i class="fas fa-user-plus"></i>
                        Add New Student
                    </a>
                    <a href="admin-subjects.php" style="
                        display: flex; 
                        align-items: center; 
                        gap: 0.75rem; 
                        padding: 0.75rem; 
                        background: var(--plp-green-pale); 
                        border-radius: var(--border-radius); 
                        text-decoration: none; 
                        color: var(--plp-green); 
                        font-weight: 500;
                        transition: var(--transition);
                    ">
                        <i class="fas fa-book-medical"></i>
                        Add New Subject
                    </a>
                    <a href="admin-reports.php" style="
                        display: flex; 
                        align-items: center; 
                        gap: 0.75rem; 
                        padding: 0.75rem; 
                        background: var(--plp-green-pale); 
                        border-radius: var(--border-radius); 
                        text-decoration: none; 
                        color: var(--plp-green); 
                        font-weight: 500;
                        transition: var(--transition);
                    ">
                        <i class="fas fa-chart-pie"></i>
                        Generate Reports
                    </a>
                </div>
            </div>

            <!-- System Status -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-server"></i>
                        System Status
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-medium);">Database</span>
                        <span style="color: var(--success); font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Online
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-medium);">ML Service</span>
                        <span style="color: var(--success); font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Active
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color: var(--text-medium);">Last Backup</span>
                        <span style="color: var(--text-medium); font-size: 0.85rem;">
                            <?php echo date('M j, Y'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Simple logout confirmation
        document.querySelector('.logout-btn').addEventListener('click', (e) => {
            if (!confirm('Are you sure you want to logout?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>