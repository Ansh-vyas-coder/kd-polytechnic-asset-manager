-- 1. USERS TABLE (The foundation for the Login Page)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. INSERT THE TWO SPECIFIC USERS
-- Note: We are inserting plain text for the password right now just to test the connection.
-- When we write the PHP API, we will upgrade these to securely encrypted hashes.
INSERT INTO users (full_name, email, password_hash, role) VALUES 
('Head of Department', 'hod@example.com', '$2y$10$nys6AerBbkB55pLN/w/6TOvntua69gptIIqrhnVVO0m.UrHxafoh.', 'admin'),
('Lab Incharge Mam', 'incharge@example.com', '$2y$10$nys6AerBbkB55pLN/w/6TOvntua69gptIIqrhnVVO0m.UrHxafoh.', 'staff');--you have to enter"password123" on web for both users to login. The password is hashed using PHP's password_hash function.