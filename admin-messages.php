<?php
require_once 'config.php';
requireAdminRole();

$admin_id = $_SESSION['user_id'];
$students = supabaseFetchAll('students');

// Get conversation partners (students who have messaged with this admin)
$partners = getConversationPartners($admin_id, 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Messages - PLP SmartGrade</title>
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
        .students-list {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-y: auto;
        }
        .student-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.3s;
        }
        .student-item:hover, .student-item.active {
            background: #e3f2fd;
        }
        .student-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .student-info {
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
            <p>Communicate with students</p>
        </div>
        
        <div class="messages-content">
            <div class="students-list">
                <?php if (!empty($partners)): ?>
                    <?php foreach ($partners as $partner): ?>
                        <div class="student-item" data-student-id="<?= $partner['id'] ?>">
                            <div class="student-name">
                                <?= htmlspecialchars($partner['name']) ?>
                                <?php if ($partner['unread_count'] > 0): ?>
                                    <span class="unread-badge"><?= $partner['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="student-info">
                                Student
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="student-item">
                        <div class="student-name">No conversations yet</div>
                        <div class="student-info">Start a conversation with a student</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="chat-container">
                <div class="chat-header">
                    <span id="current-student-name">Select a student to start chatting</span>
                </div>
                <div class="messages-area" id="messages-area">
                    <div class="no-chat-selected">
                        Please select a student to view messages
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
        let currentStudentId = null;
        let currentStudentName = null;
        let refreshInterval = null;

        // Student selection
        document.querySelectorAll('.student-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.student-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                
                currentStudentId = this.dataset.studentId;
                currentStudentName = this.querySelector('.student-name').textContent.split(' ')[0];
                
                document.getElementById('current-student-name').textContent = `Chat with ${currentStudentName}`;
                document.getElementById('message-input').style.display = 'flex';
                
                loadMessages();
                startAutoRefresh();
            });
        });

        // Load messages for selected student
        function loadMessages() {
            if (!currentStudentId) return;
            
            fetch('get_messages.php?partner_id=' + currentStudentId + '&user_type=admin')
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
                        messageDiv.className = `message ${msg.sender_type === 'admin' ? 'sent' : 'received'}`;
                        
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
            if (!messageText || !currentStudentId) return;
            
            const formData = new FormData();
            formData.append('receiver_id', currentStudentId);
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