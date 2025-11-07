<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Get subject ID from URL
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if (!$subject_id) {
    header('Location: student-subjects.php');
    exit;
}

// Get student and subject information
$student = null;
$subject = null;

try {
    $student = getStudentByEmail($_SESSION['user_email']);
    
    // Verify the subject belongs to the student
    $subject_record = supabaseFetch('student_subjects', [
        'id' => $subject_id, 
        'student_id' => $student['id']
    ]);
    
    if (!$subject_record || count($subject_record) === 0) {
        header('Location: student-subjects.php');
        exit;
    }
    
    $subject_record = $subject_record[0];
    $subject_info = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
    
    if (!$subject_info || count($subject_info) === 0) {
        header('Location: student-subjects.php');
        exit;
    }
    
    $subject = array_merge($subject_record, $subject_info[0]);
    
} catch (Exception $e) {
    header('Location: student-subjects.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Term Evaluation - PLP SmartGrade</title>
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
        }

        .header {
            background: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
            background: var(--plp-green-gradient);
            color: white;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            transition: var(--transition);
            white-space: nowrap;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-3px);
        }

        .subject-name {
            font-size: 1.5rem;
            font-weight: 700;
            flex: 1;
            text-align: center;
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

        .terms-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 1.5rem;
        }

        .term-card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: 2px solid var(--plp-green-lighter);
            transition: var(--transition);
            cursor: pointer;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .term-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
            border-color: var(--plp-green);
        }

        .term-card.midterm {
            border-top: 4px solid #3b82f6;
        }

        .term-card.final {
            border-top: 4px solid #ef4444;
        }

        .term-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--plp-green);
        }

        .term-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        .term-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-item {
            text-align: center;
            padding: 0.75rem;
            background: var(--plp-green-pale);
            border-radius: var(--border-radius);
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--plp-green);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-medium);
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
                max-width: 100%;
                padding: 1.5rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .terms-container {
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
                <a href="student-subjects.php" class="nav-link active">
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
            <div class="header-content">
                <a href="student-subjects.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Subjects
                </a>
                <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                <div style="width: 120px;"></div> <!-- Spacer for balance -->
            </div>
        </div>

        <div class="card">
            <div class="terms-container">
                <!-- Midterm Card -->
                <div class="term-card midterm" onclick="window.location.href='subject-management.php?subject_id=<?php echo $subject_id; ?>&term=midterm'">
                    <div class="term-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                    <div class="term-title">MIDTERM</div>
                    <div class="term-stats">
                        <div class="stat-item">
                            <div class="stat-value">60%</div>
                            <div class="stat-label">Class Standing</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">40%</div>
                            <div class="stat-label">Midterm Exam</div>
                        </div>
                    </div>
                </div>

                <!-- Final Card -->
                <div class="term-card final" onclick="window.location.href='subject-management.php?subject_id=<?php echo $subject_id; ?>&term=final'">
                    <div class="term-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="term-title">FINAL</div>   
                    <div class="term-stats">
                        <div class="stat-item">
                            <div class="stat-value">60%</div>
                            <div class="stat-label">Class Standing</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">40%</div>
                            <div class="stat-label">Final Exam</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>