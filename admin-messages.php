<?php
// Prevent caching issues
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'config.php';
requireAdminRole();

$admin_id = $_SESSION['user_id'];
$admin = getAdminById($admin_id);
$students = supabaseFetchAll('students', 'fullname.asc');

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    header('Content-Type: application/json');
    
    $receiver_id = sanitizeInput($_POST['receiver_id'] ?? '');
    $message = sanitizeInput($_POST['message'] ?? '');
    
    if (empty($message) || empty($receiver_id)) {
        echo json_encode(['success' => false, 'error' => 'Message and receiver are required']);
        exit;
    }
    
    try {
        $message_data = [
            'sender_id' => $admin_id,
            'sender_type' => 'admin',
            'receiver_id' => $receiver_id,
            'receiver_type' => 'student',
            'message' => $message,
            'is_read' => false,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $result = supabaseInsert('messages', $message_data);
        
        if ($result) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Database insertion failed']);
            exit;
        }
    } catch (Exception $e) {
        error_log("Message sending error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
        exit;
    }
}

// Get unread count for badge
$unread_count = getUnreadMessageCount($admin_id, 'admin');

// Get messages for admin
function getAdminMessages($admin_id) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/messages?select=*&or=(and(sender_id.eq.{$admin_id},sender_type.eq.admin),and(receiver_id.eq.{$admin_id},receiver_type.eq.admin))&order=created_at.asc";
    
    $ch = curl_init();
    $headers = [
        'apikey: ' . $supabase_key,
        'Authorization: Bearer ' . $supabase_key,
        'Content-Type: application/json'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true) ?: [];
    }
    
    return [];
}

$messages = getAdminMessages($admin_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Messages - PLP SmartGrade</title>
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
            flex-grow: 1;
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
            padding: 0.75rem 1rem;
            color: var(--text-medium);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .nav-link:hover:not(.active) {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: var(--box-shadow);
        }

        .sidebar-footer {
            border-top: 3px solid rgba(0, 99, 65, 0.1);
            padding-top: 1rem;
        }

        .logout-btn {
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
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem; 
            background: var(--plp-green-gradient);
            color: white;
        }

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Messages Specific Styles */
        .messages-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            height: calc(100vh - 200px);
        }

        .students-list {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .students-header {
            padding: 1.5rem;
            background: var(--plp-green-gradient);
            color: white;
        }

        .students-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .student-items {
            flex: 1;
            overflow-y: auto;
        }

        .student-item {
            padding: 1.25rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-item:hover, .student-item.active {
            background: var(--plp-green-pale);
        }

        .student-item.active {
            border-left: 4px solid var(--plp-green);
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--plp-green-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .student-details {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .unread-badge {
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
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
            padding: 1.5rem;
            background: var(--plp-green-pale);
            border-bottom: 1px solid var(--plp-green-lighter);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .current-student-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--plp-green-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .current-student-info h3 {
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .current-student-info p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .messages-area {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-height: 400px;
        }

        .no-chat-selected {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-light);
            text-align: center;
        }

        .no-chat-selected i {
            font-size: 3rem;
            color: var(--plp-green-lighter);
            margin-bottom: 1rem;
        }

        .message {
            max-width: 70%;
            padding: 1rem 1.25rem;
            border-radius: var(--border-radius);
            position: relative;
            animation: messageSlide 0.3s ease;
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
            background: var(--plp-green-pale);
            color: var(--text-dark);
            border-bottom-left-radius: 5px;
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

        .message-input-container {
            padding: 1.5rem;
            border-top: 1px solid var(--plp-green-lighter);
            background: var(--plp-green-pale);
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
            padding: 1rem 1.5rem;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 120px;
            justify-content: center;
        }

        .send-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.3);
        }

        .send-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .badge {
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .messages-container {
                grid-template-columns: 1fr;
            }
            
            .students-list {
                display: none;
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
            <div class="admin-email"><?php echo htmlspecialchars($_SESSION['user_email']); ?></div>
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
                    Students
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-messages.php" class="nav-link active">
                    <i class="fas fa-envelope"></i>
                    Messages
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-system-logs.php" class="nav-link">
                    <i class="fas fa-history"></i>
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
        <div class="header">
            <div class="welcome">Messages</div>
        </div>

        <div class="messages-container">
            <div class="students-list">
                <div class="students-header">
                    <div class="students-title">
                        <i class="fas fa-users"></i>
                        Students
                    </div>
                </div>
                <div class="student-items">
                    <?php if (!empty($students)): ?>
                        <?php foreach ($students as $student): ?>
                            <?php 
                            $unread_count = 0;
                            foreach ($messages as $msg) {
                                if ($msg['sender_id'] == $student['id'] && $msg['sender_type'] == 'student' && !$msg['is_read']) {
                                    $unread_count++;
                                }
                            }
                            ?>
                            <div class="student-item" data-student-id="<?= $student['id'] ?>" data-student-name="<?= htmlspecialchars($student['fullname']) ?>" data-student-course="<?= htmlspecialchars($student['course']) ?>" data-student-section="<?= htmlspecialchars($student['section']) ?>">
                                <div class="student-avatar">
                                    <?= strtoupper(substr($student['fullname'], 0, 1)) ?>
                                </div>
                                <div class="student-info">
                                    <div class="student-name"><?= htmlspecialchars($student['fullname']) ?></div>
                                    <div class="student-details"><?= htmlspecialchars($student['course']) ?> - <?= htmlspecialchars($student['section']) ?></div>
                                </div>
                                <?php if ($unread_count > 0): ?>
                                    <span class="unread-badge"><?= $unread_count ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="student-item">
                            <div class="student-info">
                                <div class="student-name">No students found</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="chat-container">
                <div class="chat-header" id="chat-header" style="display: none;">
                    <div class="current-student-avatar" id="current-student-avatar"></div>
                    <div class="current-student-info">
                        <h3 id="current-student-name">Student</h3>
                        <p id="current-student-details">Course - Section</p>
                    </div>
                </div>
                
                <div class="messages-area" id="messages-area">
                    <div class="no-chat-selected" id="no-chat-selected">
                        <div>
                            <i class="fas fa-comments"></i>
                            <h3>Select a student to start chatting</h3>
                            <p>Choose from the list to view your conversation</p>
                        </div>
                    </div>
                </div>
                
                <div class="message-input-container" id="message-input-container" style="display: none;">
                    <div class="message-input-wrapper">
                        <textarea 
                            class="message-input" 
                            id="message-text" 
                            placeholder="Type your message..." 
                            rows="1"
                        ></textarea>
                        <button class="send-btn" id="send-btn" onclick="sendMessage()">
                            <i class="fas fa-paper-plane"></i>
                            Send
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentStudentId = null;
        let currentStudentName = null;
        let refreshInterval = null;

        // Student selection
        document.querySelectorAll('.student-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.student-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                
                currentStudentId = this.dataset.studentId;
                currentStudentName = this.dataset.studentName;
                const studentCourse = this.dataset.studentCourse;
                const studentSection = this.dataset.studentSection;
                
                // Update chat header
                document.getElementById('chat-header').style.display = 'flex';
                document.getElementById('current-student-name').textContent = currentStudentName;
                document.getElementById('current-student-avatar').textContent = currentStudentName.charAt(0).toUpperCase();
                document.getElementById('current-student-details').textContent = studentCourse + ' - ' + studentSection;
                document.getElementById('no-chat-selected').style.display = 'none';
                document.getElementById('message-input-container').style.display = 'block';
                
                loadMessages();
                startAutoRefresh();
            });
        });

        // Load messages for selected student
        function loadMessages() {
            if (!currentStudentId) return;
            
            fetch('get_messages.php?student_id=' + currentStudentId + '&user_type=admin')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(result => {
                    const messagesArea = document.getElementById('messages-area');
                    messagesArea.innerHTML = '';
                    
                    if (!result.success || !result.messages || result.messages.length === 0) {
                        const noMessages = document.createElement('div');
                        noMessages.className = 'no-chat-selected';
                        noMessages.innerHTML = `
                            <div>
                                <i class="fas fa-comment-slash"></i>
                                <h3>No messages yet</h3>
                                <p>Start the conversation by sending a message</p>
                            </div>
                        `;
                        messagesArea.appendChild(noMessages);
                        return;
                    }
                    
                    result.messages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message ${msg.sender_type === 'admin' ? 'sent' : 'received'}`;
                        
                        const time = new Date(msg.created_at).toLocaleTimeString([], { 
                            hour: '2-digit', minute: '2-digit' 
                        });
                        
                        messageDiv.innerHTML = `
                            <div class="message-content">${escapeHtml(msg.message)}</div>
                            <div class="message-time">${time}</div>
                        `;
                        
                        messagesArea.appendChild(messageDiv);
                    });
                    
                    messagesArea.scrollTop = messagesArea.scrollHeight;
                    
                    // Mark messages as read
                    markMessagesAsRead();
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                });
        }

        // Send message
        function sendMessage() {
            const messageText = document.getElementById('message-text').value.trim();
            if (!messageText || !currentStudentId) {
                alert('Please select a recipient and enter a message');
                return;
            }
            
            const sendBtn = document.getElementById('send-btn');
            const originalText = sendBtn.innerHTML;
            
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('receiver_id', currentStudentId);
            formData.append('message', messageText);
            
            fetch('admin-messages.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(result => {
                if (result.success) {
                    document.getElementById('message-text').value = '';
                    document.getElementById('message-text').style.height = 'auto';
                    loadMessages();
                } else {
                    alert('Failed to send message: ' + (result.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Error sending message. Please try again.');
            })
            .finally(() => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = originalText;
            });
        }

        // Mark messages as read
        function markMessagesAsRead() {
            if (!currentStudentId) return;
            
            fetch('mark_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    student_id: currentStudentId,
                    user_type: 'admin'
                })
            }).catch(error => console.error('Error marking as read:', error));
        }

        // Auto-refresh messages
        function startAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
            refreshInterval = setInterval(loadMessages, 2000); // Refresh every 2 seconds
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
            this.style.height = (this.scrollHeight) + 'px';
        });

        // Stop auto-refresh when leaving page
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });

        // Utility function to escape HTML
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>