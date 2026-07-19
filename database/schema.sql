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
-- UPDATE: The login.php script uses password_verify(), so we MUST insert hashed passwords.
-- The following hashes are for the password 'password123'.
INSERT INTO users (full_name, email, password_hash, role) VALUES 
('Head of Department', 'hod@example.com', '$2y$10$nys6AerBbkB55pLN/w/6TOvntua69gptIIqrhnVVO0m.UrHxafoh.', 'admin'),
('Lab Incharge Mam', 'incharge@example.com', '$2y$10$nys6AerBbkB55pLN/w/6TOvntua69gptIIqrhnVVO0m.UrHxafoh.', 'staff');

-- 3. ASSETS TABLE (For the Add Item & Dashboard pages)
CREATE TABLE assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_name VARCHAR(255) NOT NULL,
    category_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    item_no VARCHAR(255) NOT NULL UNIQUE,
    cost DECIMAL(10, 2) NOT NULL,
    location VARCHAR(100),
    date_of_issue DATE NOT NULL,
    assigned_to VARCHAR(100),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Note: The category_id corresponds to the hardcoded array in the PHP files (1: Expandable, 2: Consumables, etc.)