<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Initialize variables
$messages = [];
$unread_count = 0;
$error = '';

// Get messages for student
try {
    $messages = getMessagesForUser($_SESSION['user_id'], 'student');
    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'student');
} catch (Exception $e) {
    $error = 'Error loading messages: ' . $e->getMessage();
    error_log("Error in student-messages.php: " . $e->getMessage());
}

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $message_id = $_GET['mark_read'];
    markMessageAsRead($message_id);
    
    // Refresh messages
    header('Location: student-messages.php');
    exit;
}

// Handle delete message
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $message_id = $_GET['delete'];
    deleteMessage($message_id);
    
    // Refresh messages
    header('Location: student-messages.php');
    exit;
}

// Get student info
$student = null;
try {
    $student = getStudentByEmail($_SESSION['user_email']);
} catch (Exception $e) {
    $error = 'Error loading student info: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* COPY ALL THE CSS FROM YOUR student-dashboard.php FILE */
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

        .messages-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .message-item {
            padding: 1.25rem;
            border: 1px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            transition: var(--transition);
            position: relative;
        }

        .message-item.unread {
            background: var(--plp-green-pale);
            border-left: 4px solid var(--plp-green);
        }

        .message-item:hover {
            background: var(--plp-green-pale);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .message-from {
            font-weight: 600;
            color: var(--plp-green);
            font-size: 1rem;
        }

        .message-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--text-medium);
        }

        .message-subject {
            font-weight: 700;
            color: var(--text-dark);
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .message-content {
            color: var(--text-dark);
            line-height: 1.6;
            margin-bottom: 1rem;
            white-space: pre-wrap;
        }

        .message-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
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

        .btn-danger {
            background: #fed7d7;
            color: #c53030;
        }

        .btn-danger:hover {
            background: #feb2b2;
            transform: translateY(-2px);
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

        .badge-unread {
            background: var(--danger);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
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

        .unread-indicator {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 12px;
            height: 12px;
            background: var(--plp-green);
            border-radius: 50%;
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
            max-width: 500px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-btn {
            font-size: 0.9rem;
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
            background: var(--danger);
            color: white;
        }

        .modal-btn-confirm:hover {
            background: #c53030;
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
            
            .message-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .message-actions {
                justify-content: flex-start;
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
            <div class="student-email"><?php echo htmlspecialchars($_SESSION['user_email']); ?></div>
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
                <a href="student-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-messages.php" class="nav-link active">
                    <i class="fas fa-envelope"></i>
                    Messages
                    <?php if ($unread_count > 0): ?>
                        <span class="badge badge-unread"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
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
        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="header">
            <div class="welcome">My Messages</div>
            <?php if ($unread_count > 0): ?>
                <div style="background: white; color: var(--plp-green); padding: 0.5rem 1rem; border-radius: 50px; font-weight: 600;">
                    <i class="fas fa-bell"></i>
                    <?php echo $unread_count; ?> unread message<?php echo $unread_count > 1 ? 's' : ''; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-inbox"></i>
                    Inbox
                </div>
                <?php if (!empty($messages)): ?>
                    <div style="color: var(--text-medium); font-size: 0.9rem;">
                        <?php echo count($messages); ?> message<?php echo count($messages) > 1 ? 's' : ''; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="messages-list">
                <?php if (!empty($messages)): ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="message-item <?php echo !$message['is_read'] ? 'unread' : ''; ?>">
                            <?php if (!$message['is_read']): ?>
                                <div class="unread-indicator"></div>
                            <?php endif; ?>
                            
                            <div class="message-header">
                                <div class="message-from">
                                    From: Administrator
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
                            
                            <div class="message-content">
                                <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                            </div>
                            
                            <div class="message-actions">
                                <?php if (!$message['is_read']): ?>
                                    <a href="?mark_read=<?php echo $message['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-check"></i>
                                        Mark as Read
                                    </a>
                                <?php endif; ?>
                                <button class="btn btn-danger delete-btn" data-message-id="<?php echo $message['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                    Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-envelope-open"></i>
                        <p>No messages yet</p>
                        <small>You'll see messages from administrators here</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <h3 style="color: var(--danger); font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem;">
                Delete Message
            </h3>
            <div style="color: var(--text-medium); margin-bottom: 2rem; line-height: 1.6;">
                Are you sure you want to delete this message?<br>
                This action cannot be undone.
            </div>
            <div style="display: flex; justify-content: center; gap: 1rem;">
                <button class="modal-btn modal-btn-cancel" id="cancelDelete">
                    Cancel
                </button>
                <a href="#" class="modal-btn modal-btn-confirm" id="confirmDelete">
                    <i class="fas fa-trash"></i>
                    Delete Message
                </a>
            </div>
        </div>
    </div>

    <script>
        // Delete confirmation modal
        const deleteModal = document.getElementById('deleteModal');
        const cancelDelete = document.getElementById('cancelDelete');
        const confirmDelete = document.getElementById('confirmDelete');
        let currentMessageId = null;

        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                currentMessageId = this.getAttribute('data-message-id');
                deleteModal.classList.add('show');
            });
        });

        cancelDelete.addEventListener('click', () => {
            deleteModal.classList.remove('show');
        });

        confirmDelete.addEventListener('click', function(e) {
            e.preventDefault();
            if (currentMessageId) {
                window.location.href = '?delete=' + currentMessageId;
            }
        });

        // Close modal when clicking outside
        deleteModal.addEventListener('click', (e) => {
            if (e.target === deleteModal) {
                deleteModal.classList.remove('show');
            }
        });

        // Auto-mark as read when message is viewed for more than 3 seconds
        document.querySelectorAll('.message-item.unread').forEach(item => {
            const timer = setTimeout(() => {
                const markReadBtn = item.querySelector('.btn-primary');
                if (markReadBtn) {
                    // Simulate click on mark as read button
                    markReadBtn.click();
                }
            }, 3000);

            // Clear timer if user moves away from message
            item.addEventListener('mouseleave', () => {
                clearTimeout(timer);
            });
        });
    </script>
</body>
</html>