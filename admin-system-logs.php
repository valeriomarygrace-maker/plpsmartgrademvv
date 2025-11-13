<?php
require_once 'config.php';
requireAdminRole();

// Initialize variables
$logs = [];
$stats = [];
$error = '';
$success = '';
$filter_user_type = $_GET['user_type'] ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_date = $_GET['date'] ?? '';

// Build filters
$filters = [];
if (!empty($filter_user_type)) {
    $filters['user_type'] = $filter_user_type;
}
if (!empty($filter_action)) {
    $filters['action'] = $filter_action;
}

// Get logs
try {
    $logs = getSystemLogs($filters, 50);
    $stats = getLoginStatistics(30);
} catch (Exception $e) {
    $error = 'Error loading system logs: ' . $e->getMessage();
    error_log("Error in admin-system-logs.php: " . $e->getMessage());
}

// Handle date filtering
if (!empty($filter_date) && is_array($logs)) {
    $logs = array_filter($logs, function($log) use ($filter_date) {
        return date('Y-m-d', strtotime($log['created_at'])) === $filter_date;
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - PLP SmartGrade</title>
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

        /* Filter Styles */
        .filters-container {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--plp-green);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-weight: 600;
            color: var(--plp-green);
            font-size: 0.9rem;
        }

        .filter-select, .filter-input {
            padding: 0.75rem;
            border: 1px solid rgba(0, 99, 65, 0.2);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .filter-buttons {
            display: flex;
            gap: 0.5rem;
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

        /* Table Styles */
        .logs-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table th {
            background: var(--plp-green);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .logs-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            font-size: 0.9rem;
        }

        .logs-table tr:hover {
            background: var(--plp-green-pale);
        }

        .logs-table tr:last-child td {
            border-bottom: none;
        }

        /* Badge Styles */
        .badge {
            display: inline-block;
            padding: 0.3rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-success {
            background: #c6f6d5;
            color: #2f855a;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5aa0;
        }

        .badge-warning {
            background: #fef5e7;
            color: #d69e2e;
        }

        .badge-student {
            background: #e0f2e9;
            color: var(--plp-green);
        }

        .badge-admin {
            background: #e0e7ff;
            color: #3730a3;
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
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
                <a href="admin-dashboard.php" class="nav-link">
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
                <a href="admin-messages.php" class="nav-link">
                    <i class="fas fa-comments"></i>
                    Messages
                    <?php 
                    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'admin');
                    if ($unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-settings-logs.php" class="nav-link active">
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
            <div class="welcome">System Activity Logs</div>
        </div>

        <!-- Filters -->
        <div class="filters-container">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label class="filter-label">User Type</label>
                    <select name="user_type" class="filter-select">
                        <option value="">All Users</option>
                        <option value="student" <?php echo $filter_user_type === 'student' ? 'selected' : ''; ?>>Students</option>
                        <option value="admin" <?php echo $filter_user_type === 'admin' ? 'selected' : ''; ?>>Admins</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Action</label>
                    <select name="action" class="filter-select">
                        <option value="">All Actions</option>
                        <option value="login" <?php echo $filter_action === 'login' ? 'selected' : ''; ?>>Login</option>
                        <option value="logout" <?php echo $filter_action === 'logout' ? 'selected' : ''; ?>>Logout</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Date</label>
                    <input type="date" name="date" class="filter-input" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i>
                        Apply Filters
                    </button>
                    <a href="admin-system-logs.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i>
                    Activity Logs
                </div>
                <div style="color: var(--text-medium); font-size: 0.9rem;">
                    <?php echo count($logs); ?> records found
                </div>
            </div>
            
            <div class="table-responsive">
                <?php if (!empty($logs)): ?>
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-dark);">
                                            <?php echo date('M j, Y', strtotime($log['created_at'])); ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: var(--text-light);">
                                            <?php echo date('g:i A', strtotime($log['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-dark);">
                                            <?php echo htmlspecialchars($log['user_email']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $log['user_type'] === 'student' ? 'badge-student' : 'badge-admin'; ?>">
                                            <?php echo ucfirst($log['user_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $log['action'] === 'login' ? 'badge-success' : 'badge-info'; ?>">
                                            <?php echo ucfirst($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code style="font-size: 0.8rem; color: var(--text-medium);">
                                            <?php echo htmlspecialchars($log['ip_address']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <div style="color: var(--text-dark);">
                                            <?php echo htmlspecialchars($log['description']); ?>
                                        </div>
                                        <?php if (!empty($log['user_agent'])): ?>
                                            <div style="font-size: 0.75rem; color: var(--text-light); margin-top: 0.25rem;">
                                                <?php 
                                                $user_agent = htmlspecialchars($log['user_agent']);
                                                // Truncate long user agent strings
                                                if (strlen($user_agent) > 50) {
                                                    echo substr($user_agent, 0, 50) . '...';
                                                } else {
                                                    echo $user_agent;
                                                }
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No activity logs found</p>
                        <small>Try adjusting your filters or check back later</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh the page every 30 seconds to show new logs
        setTimeout(function() {
            window.location.reload();
        }, 30000);

        // Confirm before clearing filters
        document.querySelector('.btn-secondary').addEventListener('click', function(e) {
            if (!confirm('Clear all filters?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>