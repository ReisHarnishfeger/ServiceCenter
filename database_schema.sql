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