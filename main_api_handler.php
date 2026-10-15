<?php
// api.php - REST API for Songs, Services, Service Items, and User Administration with RBAC enforcement
session_start();
require_once 'db.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access. Please log in.']);
    exit();
}

$currentUserId = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role']; // Admin, Tech, Band
$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';

// Helper function to check admin rights
function requireAdmin($role) {
    if ($role !== 'Admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: Administrator privileges required for this action.']);
        exit();
    }
}

try {
    switch ($resource) {
        // --- USERS MANAGEMENT (Admin only for modifications) ---
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

                if (empty($username) || empty($password)) {
                    throw new Exception('Username and password are required.');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, notes) VALUES (?, ?, ?, ?)');
                $stmt->execute([$username, $hash, $role, $notes]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            } elseif ($method === 'PUT') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $id = $data['id'] ?? 0;
                $role = $data['role'] ?? 'Band';
                $notes = trim($data['notes'] ?? '');
                
                if (!empty($data['password'])) {
                    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET role = ?, notes = ?, password_hash = ? WHERE id = ?');
                    $stmt->execute([$role, $notes, $hash, $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET role = ?, notes = ? WHERE id = ?');
                    $stmt->execute([$role, $notes, $id]);
                }
                echo json_encode(['success' => true]);
            } elseif ($method === 'DELETE') {
                requireAdmin($currentUserRole);
                $id = $_GET['id'] ?? 0;
                if ($id == $currentUserId) {
                    throw new Exception('You cannot delete your own active admin account.');
                }
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            }
            break;

        // --- SONGS & CHORDPRO LIBRARY ---
        case 'songs':
            if ($method === 'GET') {
                if (isset($_GET['id'])) {
                    $stmt = $pdo->prepare('SELECT * FROM songs WHERE id = ?');
                    $stmt->execute([$_GET['id']]);
                    echo json_encode($stmt->fetch());
                } else {
                    $stmt = $pdo->query('SELECT id, title, artist, key_sig, updated_at FROM songs ORDER BY title ASC');
                    echo json_encode($stmt->fetchAll());
                }
            } elseif ($method === 'POST') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $title = trim($data['title'] ?? '');
                $artist = trim($data['artist'] ?? '');
                $key = trim($data['key_sig'] ?? 'C');
                $content = trim($data['content'] ?? '');

                if (empty($title) || empty($content)) {
                    throw new Exception('Song title and ChordPro content are required.');
                }
                $stmt = $pdo->prepare('INSERT INTO songs (title, artist, key_sig, content, created_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$title, $artist, $key, $content, $currentUserId]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            } elseif ($method === 'PUT') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $id = $data['id'] ?? 0;
                $title = trim($data['title'] ?? '');
                $artist = trim($data['artist'] ?? '');
                $key = trim($data['key_sig'] ?? 'C');
                $content = trim($data['content'] ?? '');

                $stmt = $pdo->prepare('UPDATE songs SET title = ?, artist = ?, key_sig = ?, content = ? WHERE id = ?');
                $stmt->execute([$title, $artist, $key, $content, $id]);
                echo json_encode(['success' => true]);
            } elseif ($method === 'DELETE') {
                requireAdmin($currentUserRole);
                $id = $_GET['id'] ?? 0;
                $stmt = $pdo->prepare('DELETE FROM songs WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            }
            break;

        // --- SERVICES (ORDER OF SERVICE) ---
        case 'services':
            if ($method === 'GET') {
                if (isset($_GET['id'])) {
                    $serviceId = $_GET['id'];
                    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
                    $stmt->execute([$serviceId]);
                    $service = $stmt->fetch();
                    
                    if ($service) {
                        $itemStmt = $pdo->prepare('
                            SELECT si.*, u.username as assigned_username, s.title as song_title 
                            FROM service_items si 
                            LEFT JOIN users u ON si.assigned_user_id = u.id 
                            LEFT JOIN songs s ON si.song_id = s.id 
                            WHERE si.service_id = ? 
                            ORDER BY si.item_order ASC
                        ');
                        $itemStmt->execute([$serviceId]);
                        $service['items'] = $itemStmt->fetchAll();
                    }
                    echo json_encode($service);
                } else {
                    $stmt = $pdo->query('SELECT s.*, (SELECT SUM(duration_minutes) FROM service_items WHERE service_id = s.id) as total_duration FROM services s ORDER BY service_date DESC');
                    echo json_encode($stmt->fetchAll());
                }
            } elseif ($method === 'POST') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $date = $data['service_date'] ?? date('Y-m-d');
                $title = trim($data['title'] ?? 'Sunday Worship Service');
                $notes = trim($data['notes'] ?? '');

                $stmt = $pdo->prepare('INSERT INTO services (service_date, title, notes) VALUES (?, ?, ?)');
                $stmt->execute([$date, $title, $notes]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            } elseif ($method === 'PUT') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $id = $data['id'] ?? 0;
                $date = $data['service_date'] ?? date('Y-m-d');
                $title = trim($data['title'] ?? '');
                $notes = trim($data['notes'] ?? '');

                $stmt = $pdo->prepare('UPDATE services SET service_date = ?, title = ?, notes = ? WHERE id = ?');
                $stmt->execute([$date, $title, $notes, $id]);
                echo json_encode(['success' => true]);
            } elseif ($method === 'DELETE') {
                requireAdmin($currentUserRole);
                $id = $_GET['id'] ?? 0;
                $stmt = $pdo->prepare('DELETE FROM services WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            }
            break;

        // --- SERVICE ITEMS (PLANNER ELEMENTS) ---
        case 'service_items':
            if ($method === 'POST') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                $serviceId = $data['service_id'] ?? 0;
                $title = trim($data['title'] ?? '');
                $type = $data['type'] ?? 'Song';
                $duration = intval($data['duration_minutes'] ?? 5);
                $assignedUser = !empty($data['assigned_user_id']) ? $data['assigned_user_id'] : null;
                $songId = !empty($data['song_id']) ? $data['song_id'] : null;
                $notes = trim($data['notes'] ?? '');

                // Determine next order index
                $orderStmt = $pdo->prepare('SELECT MAX(item_order) as max_ord FROM service_items WHERE service_id = ?');
                $orderStmt->execute([$serviceId]);
                $row = $orderStmt->fetch();
                $nextOrder = ($row['max_ord'] !== null) ? $row['max_ord'] + 1 : 1;

                $stmt = $pdo->prepare('INSERT INTO service_items (service_id, item_order, title, type, duration_minutes, assigned_user_id, song_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$serviceId, $nextOrder, $title, $type, $duration, $assignedUser, $songId, $notes]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            } elseif ($method === 'PUT') {
                requireAdmin($currentUserRole);
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Check if reordering request
                if (isset($data['reorder']) && is_array($data['reorder'])) {
                    $stmt = $pdo->prepare('UPDATE service_items SET item_order = ? WHERE id = ? AND service_id = ?');
                    foreach ($data['reorder'] as $index => $itemId) {
                        $stmt->execute([$index + 1, $itemId, $data['service_id']]);
                    }
                    echo json_encode(['success' => true]);
                    break;
                }

                $id = $data['id'] ?? 0;
                $title = trim($data['title'] ?? '');
                $type = $data['type'] ?? 'Song';
                $duration = intval($data['duration_minutes'] ?? 5);
                $assignedUser = !empty($data['assigned_user_id']) ? $data['assigned_user_id'] : null;
                $songId = !empty($data['song_id']) ? $data['song_id'] : null;
                $notes = trim($data['notes'] ?? '');

                $stmt = $pdo->prepare('UPDATE service_items SET title = ?, type = ?, duration_minutes = ?, assigned_user_id = ?, song_id = ?, notes = ? WHERE id = ?');
                $stmt->execute([$title, $type, $duration, $assignedUser, $songId, $notes, $id]);
                echo json_encode(['success' => true]);
            } elseif ($method === 'DELETE') {
                requireAdmin($currentUserRole);
                $id = $_GET['id'] ?? 0;
                $stmt = $pdo->prepare('DELETE FROM service_items WHERE id = ?');
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid API resource specified.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>