<?php
require_once 'config.php';
requireAdminRole();

// Handle filters
$filters = [];
$limit = 50;
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['user_type']) && $_POST['user_type'] !== '') {
        $filters['user_type'] = sanitizeInput($_POST['user_type']);
    }
    if (isset($_POST['action']) && $_POST['action'] !== '') {
        $filters['action'] = sanitizeInput($_POST['action']);
    }
    if (isset($_POST['date_from']) && !empty($_POST['date_from'])) {
        $filters['date_from'] = sanitizeInput($_POST['date_from']) . ' 00:00:00';
    }
    if (isset($_POST['date_to']) && !empty($_POST['date_to'])) {
        $filters['date_to'] = sanitizeInput($_POST['date_to']) . ' 23:59:59';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['clear_filters'])) {
    // Clear filters
    $filters = [];
    $offset = 0;
}

// Get system logs
$system_logs = getSystemLogs($filters, $limit, $offset);

// Get total count for pagination
$total_logs = countSystemLogs($filters);
$total_pages = ceil($total_logs / $limit);
$current_page = floor($offset / $limit) + 1;
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
        /* Add your existing admin styles here */
        :root {
            --plp-green: #006341;
            --plp-green-light: #008856;
            --plp-green-lighter: #e0f2e9;
            --plp-green-pale: #f5fbf8;
            --plp-green-gradient: linear-gradient(135deg, #006341 0%, #008856 100%);
            --text-dark: #2d3748;
            --text-medium: #4a5568;
            --text-light: #718096;
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --box-shadow: 0 4px 12px rgba(0, 99, 65, 0.1);
            --box-shadow-lg: 0 8px 24px rgba(0, 99, 65, 0.15);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--plp-green-pale);
            border-radius: var(--border-radius);
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: var(--plp-green);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-group select,
        .form-group input {
            padding: 0.75rem;
            border: 1px solid rgba(0, 99, 65, 0.2);
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
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
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.2);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-medium);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .logs-table th,
        .logs-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .logs-table th {
            background: var(--plp-green-pale);
            color: var(--plp-green);
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .logs-table tr:hover {
            background: var(--plp-green-pale);
        }

        .user-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .user-type-admin {
            background: #e0f2fe;
            color: #0369a1;
        }

        .user-type-student {
            background: #f0fdf4;
            color: #166534;
        }

        .action-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .action-login {
            background: #dcfce7;
            color: #166534;
        }

        .action-logout {
            background: #fef3c7;
            color: #92400e;
        }

        .action-other {
            background: #e0e7ff;
            color: #3730a3;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .pagination-info {
            color: var(--text-medium);
            font-size: 0.9rem;
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
    </style>
</head>
<body>
    <!-- Include your admin sidebar and header -->

    <div class="main-content">
        <div class="header">
            <h1>System Logs</h1>
            <p>Monitor user activities and system events</p>
        </div>

        <div class="container">
            <!-- Filters -->
            <form method="POST" class="filters-form">
                <div class="form-group">
                    <label for="user_type">User Type</label>
                    <select id="user_type" name="user_type">
                        <option value="">All Types</option>
                        <option value="admin" <?php echo isset($filters['user_type']) && $filters['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="student" <?php echo isset($filters['user_type']) && $filters['user_type'] === 'student' ? 'selected' : ''; ?>>Student</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="action">Action</label>
                    <select id="action" name="action">
                        <option value="">All Actions</option>
                        <option value="login" <?php echo isset($filters['action']) && $filters['action'] === 'login' ? 'selected' : ''; ?>>Login</option>
                        <option value="logout" <?php echo isset($filters['action']) && $filters['action'] === 'logout' ? 'selected' : ''; ?>>Logout</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date_from">Date From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo isset($_POST['date_from']) ? htmlspecialchars($_POST['date_from']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="date_to">Date To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo isset($_POST['date_to']) ? htmlspecialchars($_POST['date_to']) : ''; ?>">
                </div>

                <div class="form-group" style="grid-column: 1 / -1; display: flex; gap: 1rem; align-items: end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="?clear_filters=1" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                </div>
            </form>

            <!-- Logs Table -->
            <?php if (!empty($system_logs)): ?>
                <div style="overflow-x: auto;">
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User Email</th>
                                <th>User Type</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($system_logs as $log): ?>
                                <tr>
                                    <td><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['user_email']); ?></td>
                                    <td>
                                        <span class="user-type-badge user-type-<?php echo $log['user_type']; ?>">
                                            <?php echo ucfirst($log['user_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="action-badge action-<?php echo $log['action']; ?>">
                                            <?php echo ucfirst($log['action']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($current_page > 1): ?>
                            <a href="?offset=<?php echo max(0, $offset - $limit); ?>" class="btn btn-secondary">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                            (Total: <?php echo $total_logs; ?> logs)
                        </span>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="?offset=<?php echo $offset + $limit; ?>" class="btn btn-secondary">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No logs found</h3>
                    <p>There are no system logs matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>