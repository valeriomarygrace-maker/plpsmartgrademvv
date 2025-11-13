<?php
require_once 'config.php';
requireStudentRole();

$student_id = $_SESSION['user_id'];
$student = getStudentById($student_id);

// Get conversation partners (admins who have messaged with this student)
$partners = getConversationPartners($student_id, 'student');
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

        .messages-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: calc(100vh - 120px);
        }

        .admins-list {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .list-header {
            background: var(--plp-green-gradient);
            color: white;
            padding: 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .admins-container {
            flex: 1;
            overflow-y: auto;
        }

        .admin-item {
            padding: 1.5rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .admin-item:hover, .admin-item.active {
            background: var(--plp-green-pale);
            border-left: 4px solid var(--plp-green);
        }

        .admin-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--plp-green-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .admin-info {
            flex: 1;
        }

        .admin-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .admin-details {
            font-size: 0.85rem;
            color: var(--text-medium);
        }

        .chat-container {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            padding: 1.5rem 2rem;
            background: var(--plp-green-gradient);
            color: white;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chat-header-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .chat-header-info {
            flex: 1;
        }

        .chat-header-name {
            font-weight: 600;
            font-size: 1.2rem;
        }

        .chat-header-status {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .messages-area {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
            background: var(--plp-green-pale);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message {
            max-width: 70%;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            position: relative;
            animation: messageSlide 0.3s ease-out;
        }

        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.sent {
            background: var(--plp-green-gradient);
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 5px;
        }

        .message.received {
            background: white;
            color: var(--text-dark);
            border: 1px solid var(--plp-green-lighter);
            border-bottom-left-radius: 5px;
            box-shadow: var(--box-shadow);
        }

        .message-content {
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            text-align: right;
        }

        .message.received .message-time {
            text-align: left;
        }

        .message-input-container {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--plp-green-lighter);
            background: white;
        }

        .message-input-wrapper {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
        }

        .message-input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: 50px;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            resize: none;
            height: 60px;
            transition: var(--transition);
        }

        .message-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .send-btn {
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .send-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .no-chat-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-medium);
            text-align: center;
            padding: 2rem;
        }

        .no-chat-selected i {
            font-size: 4rem;
            color: var(--plp-green-lighter);
            margin-bottom: 1rem;
        }

        .no-chat-selected h3 {
            color: var(--plp-green);
            margin-bottom: 0.5rem;
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

        .empty-state {
            padding: 2rem;
            text-align: center;
            color: var(--text-medium);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--plp-green-lighter);
            margin-bottom: 1rem;
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
            
            .messages-container {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .admins-list {
                height: 300px;
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
                <a href="student-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-messages.php" class="nav-link active">
                    <i class="fas fa-comments"></i>
                    Messages
                    <?php 
                    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'student');
                    if ($unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $unread_count; ?></span>
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
        <div class="header">
            <div class="welcome">Messages</div>
        </div>

        <div class="messages-container">
            <div class="admins-list">
                <div class="list-header">
                    <i class="fas fa-user-shield"></i>
                    Administrators
                </div>
                <div class="admins-container">
                    <?php if (!empty($partners)): ?>
                        <?php foreach ($partners as $partner): ?>
                            <div class="admin-item" data-admin-id="<?= $partner['id'] ?>" data-admin-name="<?= htmlspecialchars($partner['name']) ?>">
                                <div class="admin-avatar">
                                    <?= strtoupper(substr($partner['name'], 0, 1)) ?>
                                </div>
                                <div class="admin-info">
                                    <div class="admin-name">
                                        <?= htmlspecialchars($partner['name']) ?>
                                        <?php if ($partner['unread_count'] > 0): ?>
                                            <span class="unread-badge"><?= $partner['unread_count'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="admin-details">
                                        Administrator
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-tie"></i>
                            <p>No administrators available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="chat-container">
                <div class="chat-header" id="chat-header" style="display: none;">
                    <div class="chat-header-avatar" id="header-avatar">A</div>
                    <div class="chat-header-info">
                        <div class="chat-header-name" id="header-name">Admin Name</div>
                        <div class="chat-header-status">Online</div>
                    </div>
                </div>
                
                <div class="messages-area" id="messages-area">
                    <div class="no-chat-selected">
                        <i class="fas fa-comment-dots"></i>
                        <h3>Select an Administrator</h3>
                        <p>Choose an administrator from the list to start messaging</p>
                    </div>
                </div>
                
                <div class="message-input-container" id="message-input" style="display: none;">
                    <div class="message-input-wrapper">
                        <textarea 
                            class="message-input" 
                            id="message-text" 
                            placeholder="Type your message here..."
                            rows="1"
                        ></textarea>
                        <button class="send-btn" onclick="sendMessage()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentAdminId = null;
        let currentAdminName = null;
        let refreshInterval = null;

        // Admin selection
        document.querySelectorAll('.admin-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.admin-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                
                currentAdminId = this.dataset.adminId;
                currentAdminName = this.dataset.adminName;
                
                // Update chat header
                document.getElementById('chat-header').style.display = 'flex';
                document.getElementById('header-name').textContent = currentAdminName;
                document.getElementById('header-avatar').textContent = currentAdminName.charAt(0).toUpperCase();
                document.getElementById('message-input').style.display = 'block';
                
                loadMessages();
                startAutoRefresh();
            });
        });

        // Load messages for selected admin
        function loadMessages() {
            if (!currentAdminId) return;
            
            fetch('get_messages.php?partner_id=' + currentAdminId + '&user_type=student')
                .then(response => response.json())
                .then(messages => {
                    const messagesArea = document.getElementById('messages-area');
                    messagesArea.innerHTML = '';
                    
                    if (messages.length === 0) {
                        messagesArea.innerHTML = `
                            <div class="no-chat-selected">
                                <i class="fas fa-comment-slash"></i>
                                <h3>No Messages Yet</h3>
                                <p>Start the conversation by sending a message</p>
                            </div>
                        `;
                        return;
                    }
                    
                    messages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        const isSent = msg.sender_type === 'student';
                        messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                        
                        const time = new Date(msg.created_at).toLocaleTimeString([], { 
                            hour: '2-digit', minute: '2-digit' 
                        });
                        
                        messageDiv.innerHTML = `
                            <div class="message-content">${msg.message}</div>
                            <div class="message-time">${time}</div>
                        `;
                        
                        messagesArea.appendChild(messageDiv);
                    });
                    
                    messagesArea.scrollTop = messagesArea.scrollHeight;
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                });
        }

        // Send message
        function sendMessage() {
            const messageText = document.getElementById('message-text').value.trim();
            if (!messageText || !currentAdminId) return;
            
            const formData = new FormData();
            formData.append('receiver_id', currentAdminId);
            formData.append('message', messageText);
            
            fetch('send_message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    document.getElementById('message-text').value = '';
                    loadMessages();
                } else {
                    alert('Failed to send message. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Error sending message. Please try again.');
            });
        }

        // Auto-refresh messages
        function startAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
            refreshInterval = setInterval(loadMessages, 3000);
        }

        // Enter key to send message
        document.getElementById('message-text')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea
        document.getElementById('message-text')?.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Stop auto-refresh when leaving page
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });

        // Auto-refresh page every 60 seconds to update message count
        setTimeout(() => {
            location.reload();
        }, 60000);
    </script>
</body>
</html>