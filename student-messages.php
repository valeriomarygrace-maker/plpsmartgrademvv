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
            background: var(--plp-green);
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
            background: var(--plp-green);
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
            background: var(--plp-green);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
        }
        .send-btn:hover {
            background: var(--plp-dark-green);
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
                <?php if (!empty($partners)): ?>
                    <?php foreach ($partners as $partner): ?>
                        <div class="admin-item" data-admin-id="<?= $partner['id'] ?>">
                            <div class="admin-name">
                                <?= htmlspecialchars($partner['name']) ?>
                                <?php if ($partner['unread_count'] > 0): ?>
                                    <span class="unread-badge"><?= $partner['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-role">
                                Administrator
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="admin-item">
                        <div class="admin-name">No conversations yet</div>
                        <div class="admin-role">Start a conversation with an administrator</div>
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
                currentAdminName = this.querySelector('.admin-name').textContent.split(' ')[0];
                
                document.getElementById('current-admin-name').textContent = `Chat with ${currentAdminName}`;
                document.getElementById('message-input').style.display = 'flex';
                
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
                }
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

        // Stop auto-refresh when leaving page
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>