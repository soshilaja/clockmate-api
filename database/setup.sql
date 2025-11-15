-- ClockMate Database Setup
-- Run this in phpMyAdmin or MySQL Workbench

-- Create database
CREATE DATABASE IF NOT EXISTS clockmate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clockmate;

-- Drop tables if they exist (for clean setup)
DROP TABLE IF EXISTS clock_events;
DROP TABLE IF EXISTS pins;
DROP TABLE IF EXISTS employees;

-- Create employees table
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    role ENUM('employee', 'admin') DEFAULT 'employee',
    is_approved TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_is_approved (is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create pins table (stores PIN hashes)
CREATE TABLE pins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create clock_events table (stores clock in/out records)
CREATE TABLE clock_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    event_type ENUM('in', 'out') NOT NULL,
    timestamp DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_employee_id (employee_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (PIN: 12345678)
-- Password hash for '12345678' using PASSWORD_DEFAULT
INSERT INTO employees (name, email, role, is_approved) 
VALUES ('Admin User', 'admin@clockmate.com', 'admin', 1);

-- Get the last inserted ID and use it for the PIN
SET @admin_id = LAST_INSERT_ID();

-- Insert admin PIN (hash for '12345678')
-- Note: You should change this PIN immediately after first login!
INSERT INTO pins (employee_id, pin_hash) 
VALUES (@admin_id, '$2y$10$6pMnU7Xl2MxfeniimmiPSuVTu96XO8Mj9lLH9jirf7.QZjVNaDbRy');

-- Insert some sample employees for testing (optional)
-- All have PIN: 111111
INSERT INTO employees (name, email, role, is_approved) VALUES
('John Doe', 'john@example.com', 'employee', 1),
('Jane Smith', 'jane@example.com', 'employee', 1),
('Bob Johnson', 'bob@example.com', 'employee', 0);

-- Insert PINs for sample employees (PIN: 111111)
INSERT INTO pins (employee_id, pin_hash) VALUES
((SELECT id FROM employees WHERE email = 'john@example.com'), '$2y$10$6mYAUDVkPhSZeRuvsBq5sONMMnBbEQvVTlRD1bCpHnujA9Q2pU2AC'),
((SELECT id FROM employees WHERE email = 'jane@example.com'), '$2y$10$6mYAUDVkPhSZeRuvsBq5sONMMnBbEQvVTlRD1bCpHnujA9Q2pU2AC'),
((SELECT id FROM employees WHERE email = 'bob@example.com'), '$2y$10$6mYAUDVkPhSZeRuvsBq5sONMMnBbEQvVTlRD1bCpHnujA9Q2pU2AC');

-- Insert some sample clock events for testing
INSERT INTO clock_events (employee_id, event_type, timestamp) VALUES
((SELECT id FROM employees WHERE email = 'john@example.com'), 'in', '2024-01-15 09:00:00'),
((SELECT id FROM employees WHERE email = 'john@example.com'), 'out', '2024-01-15 17:30:00'),
((SELECT id FROM employees WHERE email = 'jane@example.com'), 'in', '2024-01-15 08:45:00'),
((SELECT id FROM employees WHERE email = 'jane@example.com'), 'out', '2024-01-15 17:15:00');

-- Create a view for easy employee log access
CREATE OR REPLACE VIEW employee_logs AS
SELECT 
    e.id as employee_id,
    e.name as employee_name,
    e.email as employee_email,
    ce.event_type,
    ce.timestamp,
    DATE(ce.timestamp) as event_date
FROM employees e
LEFT JOIN clock_events ce ON e.id = ce.employee_id
WHERE e.role = 'employee'
ORDER BY ce.timestamp DESC;

-- Show all tables
SHOW TABLES;

-- Display sample data
SELECT 'Employees:' as Info;
SELECT id, name, email, role, is_approved FROM employees;

SELECT 'Clock Events:' as Info;
SELECT e.name, ce.event_type, ce.timestamp 
FROM clock_events ce 
JOIN employees e ON ce.employee_id = e.id 
ORDER BY ce.timestamp DESC 
LIMIT 10;

SELECT 'Setup Complete!' as Status;