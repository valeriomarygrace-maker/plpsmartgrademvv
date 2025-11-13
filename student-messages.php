<?php
require_once 'config.php';

// Debug session
error_log("Session status: " . session_status());
error_log("Logged in: " . (isLoggedIn() ? 'Yes' : 'No'));
error_log("User type: " . ($_SESSION['user_type'] ?? 'Not set'));

requireStudentRole();

$student_id = $_SESSION['user_id'];
$student = getStudentById($student_id);
$admins = supabaseFetchAll('admins', 'fullname.asc');

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $receiver_id = sanitizeInput($_POST['receiver_id']);
    $message = sanitizeInput($_POST['message']);
    
    if (!empty($message) && !empty($receiver_id)) {
        $message_data = [
            'sender_id' => $student_id,
            'sender_type' => 'student',
            'receiver_id' => $receiver_id,
            'receiver_type' => 'admin',
            'message' => $message,
            'is_read' => false
        ];
        
        if (supabaseInsert('messages', $message_data)) {
            echo json_encode(['success' => true]);
            exit;
        }
    }
    echo json_encode(['success' => false]);
    exit;
}

// Get messages for student
function getStudentMessages($student_id) {
    global $supabase_url, $supabase_key;
    
    // Get all messages where student is involved with any admin
    $url = $supabase_url . "/rest/v1/messages?select=*&or=(and(sender_id.eq.{$student_id},sender_type.eq.student),and(receiver_id.eq.{$student_id},receiver_type.eq.student))&order=created_at.asc";
    
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
    
    error_log("Failed to fetch messages. HTTP Code: $httpCode");
    return [];
}

$messages = getStudentMessages($student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Student Performance Tracking System</title>
    <style>
        .messages-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        .messages-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .messages-content {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            height: 70vh;
        }
        .admins-list {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-y: auto;
        }
        .admin-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.3s;
        }
        .admin-item:hover, .admin-item.active {
            background: #e3f2fd;
        }
        .admin-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .admin-role {
            font-size: 12px;
            color: #666;
        }
        .chat-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }
        .chat-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            background: #4caf50;
            color: white;
            border-radius: 10px 10px 0 0;
        }
        .messages-area {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            max-height: 400px;
        }
        .message {
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 15px;
            max-width: 70%;
            word-wrap: break-word;
        }
        .message.sent {
            background: #4caf50;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 5px;
        }
        .message.received {
            background: #e9ecef;
            color: #333;
            border-bottom-left-radius: 5px;
        }
        .message-time {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }
        .message-input {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
        }
        .message-input textarea {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 20px;
            resize: none;
            height: 40px;
        }
        .send-btn {
            background: #4caf50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
        }
        .send-btn:hover {
            background: #45a049;
        }
        .no-chat-selected {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #666;
        }
        .unread-badge {
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="messages-container">
        <div class="messages-header">
            <h1>Messages</h1>
            <p>Communicate with administrators</p>
        </div>
        
        <div class="messages-content">
            <div class="admins-list">
                <?php if (!empty($admins)): ?>
                    <?php foreach ($admins as $admin): ?>
                        <?php 
                        $unread_count = 0;
                        foreach ($messages as $msg) {
                            if ($msg['sender_id'] == $admin['id'] && $msg['sender_type'] == 'admin' && !$msg['is_read']) {
                                $unread_count++;
                            }
                        }
                        ?>
                        <div class="admin-item" data-admin-id="<?= $admin['id'] ?>" data-admin-name="<?= htmlspecialchars($admin['fullname']) ?>">
                            <div class="admin-name">
                                <?= htmlspecialchars($admin['fullname']) ?>
                                <?php if ($unread_count > 0): ?>
                                    <span class="unread-badge"><?= $unread_count ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-role">
                                Administrator
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="admin-item">
                        <div class="admin-name">No administrators found</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="chat-container">
                <div class="chat-header">
                    <span id="current-admin-name">Select an administrator to start chatting</span>
                </div>
                <div class="messages-area" id="messages-area">
                    <div class="no-chat-selected">
                        Please select an administrator to view messages
                    </div>
                </div>
                <div class="message-input" style="display: none;" id="message-input">
                    <textarea placeholder="Type your message..." id="message-text"></textarea>
                    <button class="send-btn" onclick="sendMessage()">Send</button>
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
                
                document.getElementById('current-admin-name').textContent = `Chat with ${currentAdminName}`;
                document.getElementById('message-input').style.display = 'flex';
                
                loadMessages();
                startAutoRefresh();
            });
        });

        // Load messages for selected admin
        function loadMessages() {
            if (!currentAdminId) return;
            
            fetch('get_messages.php?admin_id=' + currentAdminId + '&user_type=student')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(messages => {
                    const messagesArea = document.getElementById('messages-area');
                    messagesArea.innerHTML = '';
                    
                    if (messages.length === 0) {
                        messagesArea.innerHTML = '<div class="no-chat-selected">No messages yet. Start the conversation!</div>';
                        return;
                    }
                    
                    messages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message ${msg.sender_type === 'student' ? 'sent' : 'received'}`;
                        
                        const time = new Date(msg.created_at).toLocaleTimeString([], { 
                            hour: '2-digit', minute: '2-digit' 
                        });
                        
                        messageDiv.innerHTML = `
                            <div>${msg.message}</div>
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
            if (!messageText || !currentAdminId) return;
            
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('receiver_id', currentAdminId);
            formData.append('message', messageText);
            
            fetch('student_messages.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    document.getElementById('message-text').value = '';
                    loadMessages();
                } else {
                    alert('Failed to send message');
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Error sending message');
            });
        }

        // Mark messages as read
        function markMessagesAsRead() {
            if (!currentAdminId) return;
            
            fetch('mark_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    admin_id: currentAdminId,
                    user_type: 'student'
                })
            }).catch(error => console.error('Error marking as read:', error));
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

        // Stop auto-refresh when leaving page
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>