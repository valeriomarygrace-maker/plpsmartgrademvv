<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Attendance - PLP SmartGrade</title>
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--plp-light-green);
            display: flex;
            min-height: 100vh;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .sidebar {
            width: 340px;
            background: white;
            box-shadow: var(--box-shadow);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            border-right: 1px solid rgba(0, 99, 65, 0.1);
        }

        .sidebar-header {
            text-align: center;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(226, 240, 228, 0.8);
            margin-bottom: 1rem;
        }

        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .logo {
            width: 100px;
            height: 100px;
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
            margin-bottom: 0.3rem;
            letter-spacing: 0.5px;
        }

        .professor-email {
            color: var(--text-medium);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            word-break: break-all;
            background: var(--plp-green-pale);
            padding: 0.5rem;
            border-radius: 6px;
            font-weight: 500;
        }

        .nav-menu {
            list-style: none;
            flex-grow: 1;
            margin-top: 1rem;
        }

        .nav-item {
            margin-bottom: 0.5rem;
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            color: var(--text-medium);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
            background: transparent;
        }

        .nav-link:hover {
            background: var(--plp-green);
            color: white;
            transform: translateX(5px);
        }

        .nav-link:hover svg {
            fill: white;
        }

        .nav-link.active {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: var(--box-shadow);
        }

        .nav-link.active svg {
            fill: white;
        }

        .nav-link.active:hover {
            background: var(--plp-green-gradient);
            color: white;
            transform: none;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid rgba(0, 99, 65, 0.1);
        }

        .logout-btn {
            background: transparent;
            color: var(--text-medium);
            padding: 0.85rem 1rem;
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
            padding: 1.5rem 2.5rem;
            background: var(--plp-green-pale);
            max-width: 100%;
            margin: 0 auto;
            width: 100%;
        }

        .header {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            background: var(--plp-green-gradient);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .attendance-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .attendance-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--plp-green-lighter);
        }
        
        .attendance-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--plp-green);
        }
        
        .date-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .attendance-table th {
            background: var(--plp-green);
            color: white;
            padding: 1rem;
            text-align: left;
        }
        
        .attendance-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .attendance-table tr:nth-child(even) {
            background-color: var(--plp-green-pale);
        }
        
        .attendance-options {
            display: flex;
            gap: 1rem;
        }
        
        .attendance-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
    
        
        .setup-panel {
            background: var(--plp-green-pale);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .setup-btn {
            background: var(--plp-green);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .setup-btn:hover {
            background: var(--plp-dark-green);
            transform: translateY(-2px);
        }
        
        .notification-badge {
            background: #e53e3e;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            margin-left: 0.5rem;
            font-weight: 600;
        }
        
        .alert-container {
            margin-bottom: 1.5rem;
        }
        
        .alert {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            box-shadow: var(--box-shadow);
        }
        
        .alert-error {
            background: #fed7d7;
            border-left: 4px solid #e53e3e;
            color: #742a2a;
        }
        
        .alert-success {
            background: #c6f6d5;
            border-left: 4px solid #38a169;
            color: #22543d;
        }
        
        .alert-icon {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .alert-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }
        
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .weight-info {
            background: var(--plp-green-pale);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        
        .weight-info p {
            margin: 0;
            font-size: 0.9rem;
        }
        
        .form-actions {
            margin-top: 1.5rem;
            text-align: center;
        }
        
        .save-btn {
            background: var(--plp-green);
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .save-btn:hover {
            background: var(--plp-dark-green);
            transform: translateY(-2px);
        }
        
        .date-input {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-family: 'Poppins', sans-serif;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo">
                    <img src="logo.png" alt="PLP Logo">
                </div>
            </div>
            <div class="portal-title">Professor Portal</div>
            <div class="professor-email">professor@plp.edu</div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="professor-page.php" class="nav-link">
                    <i class="fas fa-house"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="professor-profile.php" class="nav-link">
                    <i class="fas fa-circle-user"></i>
                    Profile
                </a>
            </li>
            <li class="nav-item">
                <a href="professor-section.php" class="nav-link active">
                    <i class="fas fa-layer-group"></i>
                    Classes
                </a>
            </li>
            <li class="nav-item">
                <a href="professor-messages.php" class="nav-link">
                    <i class="fas fa-comments"></i>
                    Message
                </a>
            </li>
            <li class="nav-item">
                <a href="professor-report.php" class="nav-link">
                    <i class="fas fa-folder-open"></i>
                    Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="professor-notification.php" class="nav-link">
                    <i class="fas fa-bell"></i>
                    Notifications
                    <span class="notification-badge">3</span>
                </a>
            </li>
        </ul>
        <div class="sidebar-footer">
            <button class="logout-btn" id="logoutBtn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </button>
        </div>
    </div>
    
    <div class="main-content">
        <div class="header">
            <div class="class-title">
                Computer Science <strong>></strong>
                BSIT 3A <strong>></strong>
                Attendance
            </div>
            <div class="header-actions">
                <a href="class-management.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Alert Container -->
        <div class="alert-container">
            <!-- Success Alert Example -->
            <div class="alert alert-success">
                <i class="fas fa-check-circle alert-icon"></i>
                <div class="alert-content">
                    <div class="alert-title">Success</div>
                    <div class="alert-message">Attendance saved successfully!</div>
                </div>
                <button class="alert-close">&times;</button>
            </div>
        </div>

        <!-- Setup Panel (shown when attendance is not set up) -->
        <!--
        <div class="setup-panel">
            <h3>Attendance Not Set Up</h3>
            <p>You need to add attendance as a class standing before you can track attendance.</p>
            <button type="button" class="setup-btn">
                <i class="fas fa-plus"></i> Add Attendance Standing
            </button>
            <p style="margin-top: 1rem; font-size: 0.9rem; color: var(--text-medium);">
                Current total: 40% of 60% allocated
            </p>
        </div>
        -->

        <!-- Attendance Container (shown when attendance is set up) -->
        <div class="attendance-container">
            <div class="attendance-header">
                <h2 class="attendance-title">Student Attendance</h2>
                <div class="date-selector">
                    <label for="attendance-date">Date:</label>
                    <input type="date" id="attendance-date" name="attendance_date" 
                           value="2023-11-15" class="date-input">
                </div>
            </div>

            <form method="POST" action="" id="attendanceForm">
                <input type="hidden" name="attendance_date" value="2023-11-15">
                
                <div class="score-table-container">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Student Number</th>
                                <th>Student Name</th>
                                <th>Attendance Status</th>
                                <th>Current Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>2023-001</td>
                                <td>John Smith</td>
                                <td>
                                    <div class="attendance-options">
                                        <div class="attendance-option">
                                            <input type="radio" id="present-1" name="attendance[1]" value="present" checked>
                                            <label for="present-1">Present</label>
                                        </div>
                                        <div class="attendance-option">
                                            <input type="radio" id="absent-1" name="attendance[1]" value="absent">
                                            <label for="absent-1">Absent</label>
                                        </div>
                                    </div>
                                </td>
                                <td>85%</td>
                            </tr>
                            <tr>
                                <td>2023-002</td>
                                <td>Maria Garcia</td>
                                <td>
                                    <div class="attendance-options">
                                        <div class="attendance-option">
                                            <input type="radio" id="present-2" name="attendance[2]" value="present" checked>
                                            <label for="present-2">Present</label>
                                        </div>
                                        <div class="attendance-option">
                                            <input type="radio" id="absent-2" name="attendance[2]" value="absent">
                                            <label for="absent-2">Absent</label>
                                        </div>
                                    </div>
                                </td>
                                <td>92%</td>
                            </tr>
                            <tr>
                                <td>2023-003</td>
                                <td>David Johnson</td>
                                <td>
                                    <div class="attendance-options">
                                        <div class="attendance-option">
                                            <input type="radio" id="present-3" name="attendance[3]" value="present">
                                            <label for="present-3">Present</label>
                                        </div>
                                        <div class="attendance-option">
                                            <input type="radio" id="absent-3" name="attendance[3]" value="absent" checked>
                                            <label for="absent-3">Absent</label>
                                        </div>
                                    </div>
                                </td>
                                <td>78%</td>
                            </tr>
                            <tr>
                                <td>2023-004</td>
                                <td>Sarah Williams</td>
                                <td>
                                    <div class="attendance-options">
                                        <div class="attendance-option">
                                            <input type="radio" id="present-4" name="attendance[4]" value="present" checked>
                                            <label for="present-4">Present</label>
                                        </div>
                                        <div class="attendance-option">
                                            <input type="radio" id="absent-4" name="attendance[4]" value="absent">
                                            <label for="absent-4">Absent</label>
                                        </div>
                                    </div>
                                </td>
                                <td>88%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="save_attendance" class="save-btn">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Date change functionality
        document.getElementById('attendance-date').addEventListener('change', function() {
            const date = this.value;
            console.log('Date changed to:', date);
        });

        // Alert close functionality
        document.querySelectorAll('.alert-close').forEach(button => {
            button.addEventListener('click', function() {
                this.closest('.alert').style.display = 'none';
            });
        });

        // Auto-save functionality (optional)
        let autoSaveTimeout;
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(() => {
                    console.log('Auto-saving attendance changes...');
                }, 3000);
            });
        });

        document.getElementById('logoutBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to logout?')) {
                console.log('Logging out...');
            }
        });
    </script>
</body>
</html>