<?php
require_once 'config.php';
requireAdminRole();

// Initialize variables
$error = '';
$success = '';
$students = [];
$sent_messages = [];

// Get all students
try {
    $students = supabaseFetchAll('students', 'fullname.asc');
} catch (Exception $e) {
    $error = 'Error loading students: ' . $e->getMessage();
}

// Get sent messages
try {
    $sent_messages = getSentMessages($_SESSION['user_id'], 'admin', 20);
} catch (Exception $e) {
    error_log("Error loading sent messages: " . $e->getMessage());
}

// Handle send message form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $to_student_id = sanitizeInput($_POST['to_student_id']);
    $subject = sanitizeInput($_POST['subject']);
    $message = sanitizeInput($_POST['message']);
    $priority = sanitizeInput($_POST['priority']);
    
    // Validation
    if (empty($to_student_id) || empty($subject) || empty($message)) {
        $error = 'All fields are required.';
    } else {
        // Get student info
        $student = getStudentById($to_student_id);
        if (!$student) {
            $error = 'Selected student not found.';
        } else {
            // Send message
            $result = sendMessage(
                $_SESSION['user_id'],
                'admin',
                $student['id'],
                'student',
                $subject,
                $message,
                $priority
            );
            
            if ($result !== false) {
                $success = 'Message sent successfully to ' . htmlspecialchars($student['fullname']) . '!';
                
                // Log the action
                logUserAction(
                    $_SESSION['user_email'],
                    'admin',
                    'send_message',
                    'Sent message to student: ' . $student['email']
                );
                
                // Clear form
                $_POST = [];
                
                // Refresh sent messages
                $sent_messages = getSentMessages($_SESSION['user_id'], 'admin', 20);
            } else {
                $error = 'Failed to send message. Please try again.';
            }
        }
    }
}

// Handle bulk message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_bulk_message'])) {
    $bulk_subject = sanitizeInput($_POST['bulk_subject']);
    $bulk_message = sanitizeInput($_POST['bulk_message']);
    $bulk_priority = sanitizeInput($_POST['bulk_priority']);
    $selected_students = $_POST['selected_students'] ?? [];
    
    if (empty($bulk_subject) || empty($bulk_message) || empty($selected_students)) {
        $error = 'Please select at least one student and fill all fields.';
    } else {
        $success_count = 0;
        $error_count = 0;
        
        foreach ($selected_students as $student_id) {
            $student = getStudentById($student_id);
            if ($student) {
                $result = sendMessage(
                    $_SESSION['user_id'],
                    'admin',
                    $student['id'],
                    'student',
                    $bulk_subject,
                    $bulk_message,
                    $bulk_priority
                );
                
                if ($result !== false) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
        }
        
        if ($success_count > 0) {
            $success = "Successfully sent message to $success_count student(s).";
            if ($error_count > 0) {
                $success .= " Failed to send to $error_count student(s).";
            }
            
            // Log the action
            logUserAction(
                $_SESSION['user_email'],
                'admin',
                'send_bulk_message',
                "Sent bulk message to $success_count students"
            );
            
            // Refresh sent messages
            $sent_messages = getSentMessages($_SESSION['user_id'], 'admin', 20);
        } else {
            $error = 'Failed to send messages to any students.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Messages - PLP SmartGrade</title>
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

        .card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            transition: var(--transition);
            margin-bottom: 1.5rem;
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

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--plp-green);
        }

        .form-select, .form-input, .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(0, 99, 65, 0.2);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--plp-green-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-medium);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
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

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #38a169;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .tabs {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--plp-green-lighter);
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-medium);
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }

        .tab.active {
            color: var(--plp-green);
            border-bottom-color: var(--plp-green);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            max-height: 300px;
            overflow-y: auto;
            padding: 1rem;
            border: 1px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
        }

        .student-checkbox {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border: 1px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            transition: var(--transition);
        }

        .student-checkbox:hover {
            background: var(--plp-green-pale);
        }

        .student-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .student-details {
            font-size: 0.8rem;
            color: var(--text-medium);
        }

        .messages-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .message-item {
            padding: 1rem;
            border: 1px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            margin-bottom: 0.75rem;
            transition: var(--transition);
        }

        .message-item:hover {
            background: var(--plp-green-pale);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .message-to {
            font-weight: 600;
            color: var(--plp-green);
        }

        .message-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-medium);
        }

        .message-subject {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .message-preview {
            color: var(--text-medium);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-high {
            background: #fed7d7;
            color: #c53030;
        }

        .badge-normal {
            background: #e0f2e9;
            color: var(--plp-green);
        }

        .badge-low {
            background: #fef5e7;
            color: #d69e2e;
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
            font-size: 1rem;
            margin-bottom: 0.5rem;
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
            
            .students-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                text-align: left;
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
                <a href="student-messages.php" class="nav-link active">
                    <i class="fas fa-envelope"></i>
                    Messages
                    <?php if (getUnreadMessageCount($_SESSION['user_id'], 'student') > 0): ?>
                        <span class="badge badge-unread"><?php echo getUnreadMessageCount($_SESSION['user_id'], 'student'); ?></span>
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
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <div class="header">
            <div class="welcome">Send Messages to Students</div>
        </div>

        <div class="tabs">
            <button class="tab active" data-tab="single">Single Message</button>
            <button class="tab" data-tab="bulk">Bulk Message</button>
            <button class="tab" data-tab="sent">Sent Messages</button>
        </div>

        <!-- Single Message Tab -->
        <div class="tab-content active" id="single-tab">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-envelope"></i>
                        Send Message to Student
                    </div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="send_message" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Select Student</label>
                        <select name="to_student_id" class="form-select" required>
                            <option value="">Choose a student...</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>" <?php echo isset($_POST['to_student_id']) && $_POST['to_student_id'] == $student['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['fullname']); ?> - <?php echo htmlspecialchars($student['email']); ?> (<?php echo htmlspecialchars($student['section']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select" required>
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-input" placeholder="Enter message subject" required value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-textarea" placeholder="Type your message here..." required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        Send Message
                    </button>
                </form>
            </div>
        </div>

        <!-- Bulk Message Tab -->
        <div class="tab-content" id="bulk-tab">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-users"></i>
                        Send Bulk Message
                    </div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="send_bulk_message" value="1">
                    
                    <div class="form-group">
                        <label class="form-label">Select Students</label>
                        <div class="students-grid">
                            <?php if (!empty($students)): ?>
                                <?php foreach ($students as $student): ?>
                                    <label class="student-checkbox">
                                        <input type="checkbox" name="selected_students[]" value="<?php echo $student['id']; ?>">
                                        <div class="student-info">
                                            <div class="student-name"><?php echo htmlspecialchars($student['fullname']); ?></div>
                                            <div class="student-details">
                                                <?php echo htmlspecialchars($student['email']); ?> | 
                                                Section: <?php echo htmlspecialchars($student['section']); ?> | 
                                                Semester: <?php echo htmlspecialchars($student['semester']); ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No students found</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="bulk_priority" class="form-select" required>
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="bulk_subject" class="form-input" placeholder="Enter message subject" required value="<?php echo isset($_POST['bulk_subject']) ? htmlspecialchars($_POST['bulk_subject']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="bulk_message" class="form-textarea" placeholder="Type your message here..." required><?php echo isset($_POST['bulk_message']) ? htmlspecialchars($_POST['bulk_message']) : ''; ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                        Send to Selected Students
                    </button>
                </form>
            </div>
        </div>

        <!-- Sent Messages Tab -->
        <div class="tab-content" id="sent-tab">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-paper-plane"></i>
                        Sent Messages
                    </div>
                </div>
                
                <div class="messages-list">
                    <?php if (!empty($sent_messages)): ?>
                        <?php foreach ($sent_messages as $message): ?>
                            <div class="message-item">
                                <div class="message-header">
                                    <div class="message-to">
                                        To: 
                                        <?php 
                                        $student = getStudentById($message['to_user_id']);
                                        echo $student ? htmlspecialchars($student['fullname']) : 'Unknown Student';
                                        ?>
                                    </div>
                                    <div class="message-meta">
                                        <span class="badge badge-<?php echo $message['priority']; ?>">
                                            <?php echo ucfirst($message['priority']); ?>
                                        </span>
                                        <span><?php echo formatDate($message['created_at'], 'M j, Y g:i A'); ?></span>
                                    </div>
                                </div>
                                <div class="message-subject">
                                    <?php echo htmlspecialchars($message['subject']); ?>
                                </div>
                                <div class="message-preview">
                                    <?php 
                                    $preview = strip_tags($message['message']);
                                    echo strlen($preview) > 150 ? substr($preview, 0, 150) . '...' : $preview;
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-envelope"></i>
                            <p>No sent messages yet</p>
                            <small>Send your first message to get started</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                
                // Show corresponding content
                const tabId = this.getAttribute('data-tab');
                document.getElementById(tabId + '-tab').classList.add('active');
            });
        });

        // Auto-expand textarea
        document.querySelectorAll('.form-textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });

        // Select all students for bulk message
        document.querySelector('#bulk-tab').addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_students[]"]');
            const selectAllBtn = document.createElement('button');
            selectAllBtn.type = 'button';
            selectAllBtn.className = 'btn btn-secondary';
            selectAllBtn.innerHTML = '<i class="fas fa-check-double"></i> Select All';
            selectAllBtn.style.marginBottom = '1rem';
            
            selectAllBtn.addEventListener('click', function() {
                checkboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
            });
            
            const existingBtn = document.querySelector('#bulk-tab .btn-secondary');
            if (!existingBtn) {
                document.querySelector('#bulk-tab .form-group:first-child').prepend(selectAllBtn);
            }
        });
    </script>
</body>
</html>