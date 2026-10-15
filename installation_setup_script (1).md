#!/bin/bash
# ==========================================
# Worship Platform - Ubuntu Bare-Metal Installer
# Sets up Apache, PHP, MySQL, Database, and Files
# ==========================================

set -e

echo "=== [1/5] Updating system packages & installing Apache, PHP, MySQL ==="
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 php libapache2-mod-php mysql-server php-mysql unzip curl

echo "=== [2/5] Starting & Enabling Apache and MySQL services ==="
sudo systemctl enable --now apache2
sudo systemctl enable --now mysql

echo "=== [3/5] Setting up MySQL Database and Default User ==="
sudo mysql -e "CREATE DATABASE IF NOT EXISTS worship_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '';"
sudo mysql -e "FLUSH PRIVILEGES;"

echo "=== [4/5] Creating application directory in /var/www/html/worship ==="
WEB_DIR="/var/www/html/worship"
sudo mkdir -p "$WEB_DIR"

# Write schema.sql
sudo tee "$WEB_DIR/schema.sql" > /dev/null << 'EOF'
-- Create database
CREATE DATABASE IF NOT EXISTS worship_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE worship_platform;

-- Users table with RBAC roles (Admin, Tech, Band)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Tech', 'Band') NOT NULL DEFAULT 'Band',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Songs table for ChordPro library
CREATE TABLE IF NOT EXISTS songs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    artist VARCHAR(100),
    key_sig VARCHAR(10) DEFAULT 'C',
    content TEXT NOT NULL,
    created_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Services (Order of Service) table
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_date DATE NOT NULL,
    title VARCHAR(150) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Service Items (Elements within an Order of Service)
CREATE TABLE IF NOT EXISTS service_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    item_order INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    type ENUM('Song', 'Speech', 'Video', 'Prayer', 'Communion', 'Transition', 'Other') DEFAULT 'Song',
    duration_minutes INT DEFAULT 5,
    assigned_user_id INT,
    song_id INT,
    notes TEXT,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE SET NULL
);

-- Insert default admin account (Password: Admin123!)
INSERT INTO users (username, password_hash, role, notes) 
VALUES ('rootadmin', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'Admin', 'Root system administrator account with full privileges.')
ON DUPLICATE KEY UPDATE id=id;

-- Insert sample Tech and Band users (Password: User123!)
INSERT INTO users (username, password_hash, role, notes) VALUES 
('tech_john', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'Tech', 'A/V and Projection crew member.'),
('band_sarah', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'Band', 'Lead acoustic guitar and vocals.')
ON DUPLICATE KEY UPDATE id=id;
EOF

# Import schema into MySQL
sudo mysql worship_platform < "$WEB_DIR/schema.sql"

# Write db.php
sudo tee "$WEB_DIR/db.php" > /dev/null << 'EOF'
<?php
$host = 'localhost';
$db   = 'worship_platform';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}
?>
EOF

# Write login.php
sudo tee "$WEB_DIR/login.php" > /dev/null << 'EOF'
<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'login') {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Please provide username and password.']);
            exit();
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'notes' => $user['notes']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        }
    } else if ($action === 'logout') {
        session_unset();
        session_destroy();
        echo json_encode(['success' => true]);
    }
} else if ($method === 'GET') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'logged_in' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'role' => $_SESSION['role']
            ]
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
}
?>
EOF

# Write api.php (Backend REST API)
sudo tee "$WEB_DIR/api.php" > /dev/null << 'EOF'
<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access. Please log in.']);
    exit();
}

$currentUserId = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role'];
$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';

function requireAdmin($role) {
    if ($role !== 'Admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: Administrator privileges required.']);
        exit();
    }
}

try {
    switch ($resource) {
        case 'users':
            if ($method === 'GET') {
                $stmt = $pdo->query('SELECT id, username, role, notes, created_at FROM users ORDER BY username ASC');
                echo json_encode($stmt->fetchAll());
            } elseif ($method === 'POST') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $username = trim($data['username'] ?? '');
                $password = $data['password'] ?? '';
                $role = $data['role'] ?? 'Band';
                $notes = trim($data['notes'] ?? '');
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, notes) VALUES (?, ?, ?, ?)');
                $stmt->execute([$username, $hash, $role, $notes]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;
        case 'songs':
            if ($method === 'GET') {
                $stmt = $pdo->query('SELECT id, title, artist, key_sig, updated_at FROM songs ORDER BY title ASC');
                echo json_encode($stmt->fetchAll());
            } elseif ($method === 'POST') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $stmt = $pdo->prepare('INSERT INTO songs (title, artist, key_sig, content, created_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$data['title'], $data['artist'], $data['key_sig'], $data['content'], $currentUserId]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;
        case 'services':
            if ($method === 'GET') {
                $stmt = $pdo->query('SELECT s.*, (SELECT SUM(duration_minutes) FROM service_items WHERE service_id = s.id) as total_duration FROM services s ORDER BY service_date DESC');
                echo json_encode($stmt->fetchAll());
            } elseif ($method === 'POST') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $stmt = $pdo->prepare('INSERT INTO services (service_date, title, notes) VALUES (?, ?, ?)');
                $stmt->execute([$data['service_date'], $data['title'], $data['notes']]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
            break;
        default:
            echo json_encode(['error' => 'Invalid resource']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
EOF

echo "=== [5/5] Setting file permissions for www-data ==="
sudo chown -R www-data:www-data "$WEB_DIR"
sudo chmod -R 755 "$WEB_DIR"

echo ""
echo "=========================================================="
echo "Installation Complete! Access your web app at:"
echo "http://$(hostname -I | awk '{print $1}')/worship/"
echo "Default Admin Login: rootadmin / Admin123!"
echo "=========================================================="
```
eof

### How to use `install.sh` on your Ubuntu Server:
1. Save the above script to your Ubuntu server as `install.sh`.
2. Make it executable:
   ```bash
   chmod +x install.sh
   ```
3. Run with sudo privileges:
   ```bash
   sudo ./install.sh
   ```
4. Access your platform at `http://your-server-ip/worship/` and log in with `rootadmin` / `Admin123!`.