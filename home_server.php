<?php
// home_server.php

// Security: Enable strict typing
declare(strict_types=1);

// Configuration
$configFile = 'services.conf';
$pidFile = '/tmp/home_server_pids';
$localIP = trim(shell_exec("ip address | grep 'inet ' | grep 'wlo1' | awk '{print $2}' | awk -F'/' '{print $1}'"));

// Simple authentication - in a real scenario, use a secure auth system
$validUsername = 'admin';
$validPasswordHash = password_hash("change_this_password", PASSWORD_DEFAULT); // Change this to a secure password

// Allowed commands whitelist - add your safe commands here
$allowedCommands = [
    'php -S 0.0.0.0:8000', // Example: PHP built-in server
    'python3 -m http.server 8000', // Example: Python simple server
    // Add other safe commands that you want to allow
];

// Start session for authentication
session_start();

// Check if user is authenticated
function isAuthenticated(): bool {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// Handle login
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    header('Content-Type: application/json');
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === $validUsername && password_verify($password, $validPasswordHash)) {
        $_SESSION['authenticated'] = true;
        echo json_encode(['success' => true, 'message' => 'Login successful']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    }
    exit;
}

// Handle logout
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Logged out']);
    exit;
}

// Require authentication for all other actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isAuthenticated() && $_POST['action'] !== 'login') {
    header('Content-Type: application/json', true, 401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Function to get all services
function getServices(): array {
    global $configFile;
    $services = [];
    
    if (file_exists($configFile)) {
        $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) !== 3) continue;
            
            list($name, $command, $port) = $parts;
            $services[] = [
                'name' => $name,
                'command' => $command,
                'port' => $port,
                'status' => isPortInUse((int)$port) ? 'UP' : 'DOWN'
            ];
        }
    }
    
    return $services;
}

// Function to check if port is in use
function isPortInUse(int $port): bool {
    exec("ss -tuln | grep :" . escapeshellarg((string)$port), $output);
    return !empty($output);
}

// Function to validate command against whitelist
function isAllowedCommand(string $command): bool {
    global $allowedCommands;
    return in_array($command, $allowedCommands, true);
}

// Function to add a service
function addService(string $name, string $command, string $port): array {
    global $configFile;
    
    if (!ctype_digit($port)) {
        return ['success' => false, 'message' => 'Invalid port number'];
    }
    
    if (!isAllowedCommand($command)) {
        return ['success' => false, 'message' => 'Command is not allowed'];
    }
    
    $line = implode('|', [escapePipe($name), escapePipe($command), $port]) . "\n";
    file_put_contents($configFile, $line, FILE_APPEND);
    
    logAction("Service '$name' added");
    return ['success' => true, 'message' => "Service '$name' added successfully"];
}

// Function to update a service
function updateService(int $index, string $name, string $command, string $port): array {
    global $configFile;
    
    if (!ctype_digit($port)) {
        return ['success' => false, 'message' => 'Invalid port number'];
    }
    
    if (!isAllowedCommand($command)) {
        return ['success' => false, 'message' => 'Command is not allowed'];
    }
    
    $services = getServices();
    if (!isset($services[$index])) {
        return ['success' => false, 'message' => 'Service not found'];
    }
    
    $services[$index] = [
        'name' => $name,
        'command' => $command,
        'port' => $port,
        'status' => $services[$index]['status']
    ];
    
    saveServices($services);
    logAction("Service '$name' updated");
    return ['success' => true, 'message' => "Service '$name' updated successfully"];
}

// Function to delete a service
function deleteService(int $index): array {
    $services = getServices();
    if (!isset($services[$index])) {
        return ['success' => false, 'message' => 'Service not found'];
    }
    
    $name = $services[$index]['name'];
    array_splice($services, $index, 1);
    saveServices($services);
    
    logAction("Service '$name' deleted");
    return ['success' => true, 'message' => "Service '$name' deleted successfully"];
}

// Function to save services to config file
function saveServices(array $services): void {
    global $configFile;
    
    $content = '';
    foreach ($services as $service) {
        $content .= implode('|', [escapePipe($service['name']), escapePipe($service['command']), $service['port']]) . "\n";
    }
    
    file_put_contents($configFile, $content);
}

// Function to escape pipe characters in names and commands
function escapePipe(string $str): string {
    return str_replace('|', '\\|', $str);
}

// Function to start a service
function startService(int $index): array {
    $services = getServices();
    if (!isset($services[$index])) {
        return ['success' => false, 'message' => 'Service not found'];
    }
    
    $service = $services[$index];
    
    if ($service['status'] === 'UP') {
        return ['success' => false, 'message' => "Service '{$service['name']}' is already running"];
    }
    
    global $pidFile;
    
    // Special handling for SSH - but we shouldn't allow this in a web interface
    if ($service['name'] === 'SSH') {
        return ['success' => false, 'message' => 'SSH management is disabled for security reasons'];
    }
    
    // Validate command before executing
    if (!isAllowedCommand($service['command'])) {
        return ['success' => false, 'message' => 'Command is not allowed'];
    }
    
    // Start the service
    exec(escapeshellcmd($service['command']) . " > /dev/null 2>&1 & echo $!", $output);
    $pid = $output[0] ?? '';
    
    // Save PID
    if ($pid) {
        file_put_contents($pidFile, "{$service['name']}:$pid\n", FILE_APPEND);
    }
    
    logAction("Service '{$service['name']}' started");
    return ['success' => true, 'message' => "Service '{$service['name']}' started"];
}

// Function to stop a service
function stopService(int $index): array {
    $services = getServices();
    if (!isset($services[$index])) {
        return ['success' => false, 'message' => 'Service not found'];
    }
    
    $service = $services[$index];
    
    if ($service['status'] === 'DOWN') {
        return ['success' => false, 'message' => "Service '{$service['name']}' is not running"];
    }
    
    global $pidFile;
    
    // Special handling for SSH - disabled for security
    if ($service['name'] === 'SSH') {
        return ['success' => false, 'message' => 'SSH management is disabled for security reasons'];
    }
    
    // Find PID from file
    $pid = null;
    if (file_exists($pidFile)) {
        $lines = file($pidFile, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            if (strpos($line, "{$service['name']}:") === 0) {
                list(, $pid) = explode(':', $line);
                unset($lines[$i]);
                file_put_contents($pidFile, implode("\n", $lines) . "\n");
                break;
            }
        }
    }
    
    // Kill process by PID
    if ($pid) {
        exec("kill -15 " . escapeshellarg($pid) . " 2>/dev/null || kill -9 " . escapeshellarg($pid) . " 2>/dev/null");
    }
    
    // Also try to kill by port
    exec("lsof -i:" . escapeshellarg($service['port']) . " -t 2>/dev/null", $portPids);
    if (!empty($portPids)) {
        foreach ($portPids as $portPid) {
            exec("kill -15 " . escapeshellarg($portPid) . " 2>/dev/null || kill -9 " . escapeshellarg($portPid) . " 2>/dev/null");
        }
    }
    
    logAction("Service '{$service['name']}' stopped");
    return ['success' => true, 'message' => "Service '{$service['name']}' stopped"];
}

// Function to log actions
function logAction(string $message): void {
    $logFile = 'server_actions.log';
    $timestamp = date('Y-m-d H:i:s');
    $user = $_SESSION['username'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $logMessage = "[$timestamp] User: $user IP: $ip Action: $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'login' && $_POST['action'] !== 'logout') {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    switch ($action) {
        case 'add':
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $command = filter_input(INPUT_POST, 'command', FILTER_SANITIZE_STRING);
            $port = filter_input(INPUT_POST, 'port', FILTER_SANITIZE_STRING);
            $result = addService($name, $command, $port);
            echo json_encode($result);
            break;
            
        case 'update':
            $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $command = filter_input(INPUT_POST, 'command', FILTER_SANITIZE_STRING);
            $port = filter_input(INPUT_POST, 'port', FILTER_SANITIZE_STRING);
            $result = updateService($index, $name, $command, $port);
            echo json_encode($result);
            break;
            
        case 'delete':
            $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
            $result = deleteService($index);
            echo json_encode($result);
            break;
            
        case 'start':
            $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
            $result = startService($index);
            echo json_encode($result);
            break;
            
        case 'stop':
            $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
            $result = stopService($index);
            echo json_encode($result);
            break;
        
        case 'getStats':
            $serviceName = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $stats = getServiceStats($serviceName);
            echo json_encode($stats);
            exit;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
    exit;
}

function getServiceStats(string $serviceName): array {
    $statusFile = ".server_data/{$serviceName}_status.log";
    $usageFile = ".server_data/{$serviceName}_usage.log";
    
    $stats = [
        'hasData' => false,
        'uptime' => 0,
        'totalRecords' => 0,
        'uptimeHistory' => [],
        'cpuHistory' => [],
        'memHistory' => [],
        'timestamps' => []
    ];
    
    if (!file_exists($statusFile) || !file_exists($usageFile)) {
        return $stats;
    }
    
    $stats['hasData'] = true;
    
    // Read status file for uptime history
    $statusLines = file($statusFile, FILE_IGNORE_NEW_LINES);
    $stats['totalRecords'] = count($statusLines);
    
    $upCount = 0;
    foreach ($statusLines as $line) {
        $parts = explode(',', $line);
        if (count($parts) >= 2) {
            $timestamp = $parts[0];
            $status = $parts[1];
            $stats['timestamps'][] = $timestamp;
            $stats['uptimeHistory'][] = ($status === 'UP') ? 1 : 0;
            if ($status === 'UP') {
                $upCount++;
            }
        }
    }
    
    // Calculate uptime percentage
    if ($stats['totalRecords'] > 0) {
        $stats['uptime'] = round(($upCount / $stats['totalRecords']) * 100, 2);
    }
    
    // Read usage file for CPU and memory history
    $usageLines = file($usageFile, FILE_IGNORE_NEW_LINES);
    foreach ($usageLines as $line) {
        $parts = explode(',', $line);
        if (count($parts) >= 4) {
            $stats['cpuHistory'][] = floatval($parts[2]);
            $stats['memHistory'][] = floatval($parts[3]);
        }
    }
    
    return $stats;
}

// Add security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Get all services for display if authenticated
$services = isAuthenticated() ? getServices() : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Server Manager</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #1e1e1e;
            color: #f0f0f0;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            color: #58a6ff;
            text-align: center;
        }
        .info {
            background-color: #2d333b;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .services {
            background-color: #2d333b;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #444;
        }
        th {
            background-color: #3a3f4b;
        }
        .status-up {
            color: #7ce38b;
            font-weight: bold;
        }
        .status-down {
            color: #f85149;
            font-weight: bold;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 5px;
        }
        .btn-start {
            background-color: #238636;
            color: white;
        }
        .btn-stop {
            background-color: #da3633;
            color: white;
        }
        .btn-edit {
            background-color: #3fb950;
            color: white;
        }
        .btn-delete {
            background-color: #f85149;
            color: white;
        }
        .btn-add {
            background-color: #58a6ff;
            color: white;
            margin-top: 10px;
        }
        .btn-logout {
            background-color: #6e7681;
            color: white;
            float: right;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
        }
        .modal-content {
            background-color: #2d333b;
            margin: 10% auto;
            padding: 20px;
            border-radius: 5px;
            width: 50%;
            max-width: 500px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
        }
        input[type="text"], input[type="number"], input[type="password"] {
            width: 100%;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #444;
            background-color: #1e1e1e;
            color: #f0f0f0;
        }
        .modal-buttons {
            text-align: right;
            margin-top: 15px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            display: none;
        }
        .message-success {
            background-color: #238636;
            color: white;
        }
        .message-error {
            background-color: #da3633;
            color: white;
        }
        .auth-container {
            background-color: #2d333b;
            padding: 20px;
            border-radius: 5px;
            max-width: 400px;
            margin: 0 auto;
        }
        
          .stats-container {
            padding: 15px;
            background-color: #2d333b;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .stats-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .stats-title {
            font-size: 18px;
            font-weight: bold;
            color: #58a6ff;
        }
        .stats-summary {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .stats-box {
            background-color: #3a3f4b;
            padding: 10px;
            border-radius: 5px;
            width: 30%;
            text-align: center;
        }
        .stats-value {
            font-size: 24px;
            font-weight: bold;
            margin: 5px 0;
        }
        .stats-label {
            font-size: 14px;
            color: #8b949e;
        }
        .stats-chart {
            width: 100%;
            height: 250px;
            margin-bottom: 20px;
            position: relative;
            left: 50%;
            transform: translateX(-50%);
            
        }
        .btn-stats {
            background-color: #58a6ff;
            color: white;
        }

        #statsModal{
            overflow-y: auto;
        }
    </style>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <h1>Home Server Manager</h1>
        
        <div id="message" class="message"></div>
        
        <?php if (!isAuthenticated()): ?>
        <div class="auth-container">
            <h2>Login Required</h2>
            <form id="loginForm">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-add" onclick="login()">Login</button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <button class="btn btn-logout" onclick="logout()">Logout</button>
        
        <div class="info">
            <p>Your local IP: <strong><?php echo htmlspecialchars($localIP); ?></strong></p>
        </div>
        
        <div class="services">
            <h2>Services</h2>
             <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Port</th>
                <th>Status</th>
                <th>Actions</th>
                <th>Access</th>
                <th>Stats</th>
            </tr>
        </thead>
        <tbody id="services-list">
            <?php foreach ($services as $index => $service): ?>
            <tr>
                <td><?php echo htmlspecialchars($service['name']); ?></td>
                <td><?php echo htmlspecialchars($service['port']); ?></td>
                <td class="status-<?php echo strtolower($service['status']); ?>"><?php echo $service['status']; ?></td>
                <td>
                    <?php if ($service['status'] === 'DOWN'): ?>
                    <button class="btn btn-start" onclick="startService(<?php echo $index; ?>)">Start</button>
                    <?php else: ?>
                    <button class="btn btn-stop" onclick="stopService(<?php echo $index; ?>)">Stop</button>
                    <?php endif; ?>
                    <button class="btn btn-edit" onclick="showEditModal(<?php echo $index; ?>, '<?php echo addslashes($service['name']); ?>', '<?php echo addslashes($service['command']); ?>', <?php echo $service['port']; ?>)">Edit</button>
                    <button class="btn btn-delete" onclick="deleteService(<?php echo $index; ?>)">Delete</button>
                </td>
                <td>
                <?php if ($service['status'] === 'UP'): ?>
                    <a href="http://<?php echo htmlspecialchars($localIP); ?>:<?php echo $service['port']; ?>" target="_blank">
                    <button class="btn btn-access">Access</button>
                    </a>
                <?php else: ?>
                    <button class="btn btn-access" disabled>Access</button>
                <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-stats" onclick="showStatsModal('<?php echo addslashes($service['name']); ?>')">Stats</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
            
            <button class="btn btn-add" onclick="showAddModal()">Add New Service</button>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Add Service Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addModal')">&times;</span>
            <h2>Add New Service</h2>
            <form id="addForm">
                <div class="form-group">
                    <label for="name">Service Name:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="command">Command:</label>
                    <select id="command" name="command" required>
                        <?php foreach ($allowedCommands as $cmd): ?>
                        <option value="<?php echo htmlspecialchars($cmd); ?>"><?php echo htmlspecialchars($cmd); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="port">Port:</label>
                    <input type="number" id="port" name="port" min="1" max="65535" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                    <button type="button" class="btn btn-add" onclick="addService()">Add Service</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Service Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editModal')">&times;</span>
            <h2>Edit Service</h2>
            <form id="editForm">
                <input type="hidden" id="editIndex" name="index">
                <div class="form-group">
                    <label for="editName">Service Name:</label>
                    <input type="text" id="editName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="editCommand">Command:</label>
                    <select id="editCommand" name="command" required>
                        <?php foreach ($allowedCommands as $cmd): ?>
                        <option value="<?php echo htmlspecialchars($cmd); ?>"><?php echo htmlspecialchars($cmd); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editPort">Port:</label>
                    <input type="number" id="editPort" name="port" min="1" max="65535" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                    <button type="button" class="btn btn-edit" onclick="updateService()">Update Service</button>
                </div>
            </form>
        </div>
    </div>
    
        <!-- Add Statistics Modal -->
     <div id="statsModal" class="modal">
        <div class="modal-content" style="width: 80%; max-width: 800px;">
            <span class="close" onclick="closeModal('statsModal')">&times;</span>
            <h2 id="statsTitle">Service Statistics</h2>
            
            <div id="noStatsData" style="display: none;">
                <p>No monitoring data available for this service.</p>
            </div>
            
            <div id="statsData">
                <div class="stats-summary">
                    <div class="stats-box">
                        <div class="stats-value" id="uptimeValue">0%</div>
                        <div class="stats-label">Uptime</div>
                    </div>
                    <div class="stats-box">
                        <div class="stats-value" id="avgCpuValue">0%</div>
                        <div class="stats-label">Avg CPU</div>
                    </div>
                    <div class="stats-box">
                        <div class="stats-value" id="avgMemValue">0%</div>
                        <div class="stats-label">Avg Memory</div>
                    </div>
                </div>
                
                <div class="stats-container">
                    <div class="stats-header">
                        <div class="stats-title">Uptime History</div>
                    </div>
                    <canvas id="uptimeChart" class="stats-chart"></canvas>
                </div>
                
                <div class="stats-container">
                    <div class="stats-header">
                        <div class="stats-title">CPU Usage</div>
                    </div>
                    <canvas id="cpuChart" class="stats-chart"></canvas>
                </div>
                
                <div class="stats-container">
                    <div class="stats-header">
                        <div class="stats-title">Memory Usage</div>
                    </div>
                    <canvas id="memChart" class="stats-chart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Show message function
        function showMessage(message, isSuccess) {
            const messageEl = document.getElementById('message');
            messageEl.textContent = message;
            messageEl.className = isSuccess ? 'message message-success' : 'message message-error';
            messageEl.style.display = 'block';
            
            setTimeout(() => {
                messageEl.style.display = 'none';
            }, 3000);
        }
        
        // Refresh services list
        function refreshServicesList() {
            location.reload();
        }
        
        // Login function
        function login() {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            const formData = new FormData();
            formData.append('action', 'login');
            formData.append('username', username);
            formData.append('password', password);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        // Logout function
        function logout() {
            const formData = new FormData();
            formData.append('action', 'logout');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        // Show add modal
        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
        }
        
        // Show edit modal
        function showEditModal(index, name, command, port) {
            document.getElementById('editIndex').value = index;
            document.getElementById('editName').value = name;
            document.getElementById('editCommand').value = command;
            document.getElementById('editPort').value = port;
            document.getElementById('editModal').style.display = 'block';
        }
        
        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Add service
        function addService() {
            const name = document.getElementById('name').value;
            const command = document.getElementById('command').value;
            const port = document.getElementById('port').value;
            
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('name', name);
            formData.append('command', command);
            formData.append('port', port);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeModal('addModal');
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        // Update service
        function updateService() {
            const index = document.getElementById('editIndex').value;
            const name = document.getElementById('editName').value;
            const command = document.getElementById('editCommand').value;
            const port = document.getElementById('editPort').value;
            
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('index', index);
            formData.append('name', name);
            formData.append('command', command);
            formData.append('port', port);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeModal('editModal');
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        // Delete service
        function deleteService(index) {
            if (!confirm('Are you sure you want to delete this service?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('index', index);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        // Start service
        function startService(index) {
            const formData = new FormData();
            formData.append('action', 'start');
            formData.append('index', index);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        // Stop service
        function stopService(index) {
            const formData = new FormData();
            formData.append('action', 'stop');
            formData.append('index', index);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showMessage(data.message, data.success);
                if (data.success) {
                    setTimeout(refreshServicesList, 1000);
                }
            });
        }
        
        
        // Charts for statistics
        let uptimeChart = null;
        let cpuChart = null;
        let memChart = null;
        
        // Function to show statistics modal
        function showStatsModal(serviceName) {
            document.getElementById('statsTitle').textContent = `Statistics for ${serviceName}`;
            document.getElementById('statsModal').style.display = 'block';
            
            // Reset charts if they exist
            if (uptimeChart) uptimeChart.destroy();
            if (cpuChart) cpuChart.destroy();
            if (memChart) memChart.destroy();
            
            // Fetch statistics data
            const formData = new FormData();
            formData.append('action', 'getStats');
            formData.append('name', serviceName);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.hasData) {
                    document.getElementById('noStatsData').style.display = 'block';
                    document.getElementById('statsData').style.display = 'none';
                    return;
                }
                
                document.getElementById('noStatsData').style.display = 'none';
                document.getElementById('statsData').style.display = 'block';
                
                // Update summary values
                document.getElementById('uptimeValue').textContent = `${data.uptime}%`;
                
                // Calculate average CPU and memory
                const avgCpu = data.cpuHistory.length > 0 
                    ? data.cpuHistory.reduce((a, b) => a + b, 0) / data.cpuHistory.length 
                    : 0;
                const avgMem = data.memHistory.length > 0 
                    ? data.memHistory.reduce((a, b) => a + b, 0) / data.memHistory.length 
                    : 0;
                
                document.getElementById('avgCpuValue').textContent = `${avgCpu.toFixed(1)}%`;
                document.getElementById('avgMemValue').textContent = `${avgMem.toFixed(1)}%`;
                
                // Create charts
                createUptimeChart(data.timestamps, data.uptimeHistory);
                createResourceChart('cpuChart', 'CPU Usage (%)', data.timestamps, data.cpuHistory, 'rgba(88, 166, 255, 0.5)');
                createResourceChart('memChart', 'Memory Usage (%)', data.timestamps, data.memHistory, 'rgba(246, 185, 59, 0.5)');
            });
        }
        
        // Function to create uptime chart
        function createUptimeChart(timestamps, uptimeData) {
            const ctx = document.getElementById('uptimeChart').getContext('2d');
            const canvas = document.getElementById('uptimeChart');
            canvas.width = 700;
            canvas.height = 500;
            // Convert binary uptime data to "UP" or "DOWN" for tooltip
            const tooltipLabels = uptimeData.map(value => value === 1 ? 'UP' : 'DOWN');
            const x_labels = Array.from({ length: uptimeData.length }, (_, i) => i + 1);

            uptimeChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: x_labels,
                    datasets: [{
                        label: 'Uptime',
                        data: uptimeData,
                        backgroundColor: 'rgba(63, 185, 80, 0.2)',
                        borderColor: 'rgba(63, 185, 80, 1)',
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHitRadius: 10,
                        pointHoverBackgroundColor: 'rgba(63, 185, 80, 1)',
                        steppedLine: true,
                        fill: true
                    }]
                },
                options: {
                    responsive: false,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            ticks: {
                                maxTicksLimit: 10,
                                color: '#8b949e'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        y: {
                            min: 0,
                            max: 1,
                            ticks: {
                                stepSize: 1,
                                callback: function(value) {
                                    return value === 0 ? 'DOWN' : 'UP';
                                },
                                color: '#8b949e'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const index = context.dataIndex;
                                    return tooltipLabels[index];
                                }
                            }
                        },
                        legend: {
                            labels: {
                                color: '#f0f0f0'
                            }
                        }
                    }
                }
            });
        }
        
        // Function to create resource usage charts (CPU and Memory)
        function createResourceChart(canvasId, label, timestamps, data, color) {
            const ctx = document.getElementById(canvasId).getContext('2d');
            const canvas = document.getElementById(canvasId)
            canvas.width = 700;
            canvas.height = 500;
            if (data.length === 0) {
                document.getElementById(canvasId).style.display = 'none';
                return;
            } else {
                document.getElementById(canvasId).style.display = 'block';
            }

            const x_labels = Array.from({ length: data.length }, (_, i) => i + 1);
            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: x_labels,
                    datasets: [{
                        label: label,
                        data: data,
                        backgroundColor: color,
                        borderColor: color.replace('0.5', '1'),
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHitRadius: 10,
                        pointHoverBackgroundColor: color.replace('0.5', '1'),
                        fill: true
                    }]
                },
                options: {
                    responsive: false,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            ticks: {
                                maxTicksLimit: 10,
                                color: '#8b949e'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        },
                        y: {
                            min: 0,
                            suggestedMax: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                },
                                color: '#8b949e'
                            },
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: {
                                color: '#f0f0f0'
                            }
                        }
                    }
                }
            });
            
            if (canvasId === 'cpuChart') {
                cpuChart = chart;
            } else if (canvasId === 'memChart') {
                memChart = chart;
            }
        }
    </script>
</body>
</html>
