CREATE DATABASE IF NOT EXISTS pos_system;
USE pos_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    role ENUM('admin', 'manager', 'cashier') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    barcode VARCHAR(50),
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category_id INT,
    price DECIMAL(10, 2) NOT NULL,
    cost DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    low_stock_threshold INT DEFAULT 10,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('purchase', 'sale', 'adjustment', 'return') NOT NULL,
    quantity INT NOT NULL,
    reference_id INT,
    notes TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(20) NOT NULL UNIQUE,
    customer_id INT,
    user_id INT NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    discount_amount DECIMAL(10, 2) DEFAULT 0,
    tax_amount DECIMAL(10, 2) DEFAULT 0,
    grand_total DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('cash', 'card', 'bank_transfer', 'other') NOT NULL,
    payment_status ENUM('paid', 'partial', 'pending') DEFAULT 'paid',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    discount DECIMAL(10, 2) DEFAULT 0,
    total DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(20) NOT NULL UNIQUE,
    supplier_id INT NOT NULL,
    user_id INT NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('received', 'pending', 'ordered') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(10, 2) NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS backup_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Insert admin user with password 'admin123'
INSERT INTO users (username, password, full_name, email, role)
VALUES ('admin', '$2y$10$8zUlMG4AyOiHQbJpUJHznuVwjBEqQnHhE9HHvgKqk8vGdHJCIjlAa', 'Administrator', 'admin@example.com', 'admin');

-- Insert categories
INSERT INTO categories (name, description) VALUES
('Beverages', 'Drinks, coffee, tea, and other beverages'),
('Snacks', 'Chips, candies, and other snack items'),
('Fresh Produce', 'Fruits, vegetables, and other fresh items'),
('Bakery', 'Bread, cakes, and other baked goods'),
('Dairy', 'Milk, cheese, and other dairy products');

-- Insert products
INSERT INTO products (sku, barcode, name, description, category_id, price, cost, quantity, low_stock_threshold) VALUES
('BEV001', '8851959132012', 'Mineral Water 500ml', 'Bottled drinking water', 1, 10.00, 5.00, 100, 20),
('BEV002', '8851959133521', 'Cola Soda 330ml', 'Carbonated cola drink', 1, 15.00, 8.00, 80, 15),
('BEV003', '8851959134382', 'Orange Juice 1L', 'Fresh orange juice', 1, 45.00, 25.00, 30, 10),
('SNK001', '8851959135392', 'Potato Chips 50g', 'Salted potato chips', 2, 20.00, 12.00, 75, 15),
('SNK002', '8851959136122', 'Chocolate Bar 100g', 'Milk chocolate bar', 2, 35.00, 20.00, 50, 10),
('FP001', '8851959137521', 'Banana 1kg', 'Fresh bananas', 3, 30.00, 15.00, 20, 5),
('FP002', '8851959138392', 'Apple 1kg', 'Fresh apples', 3, 60.00, 35.00, 25, 5),
('BAK001', '8851959139122', 'White Bread', 'Sliced white bread', 4, 25.00, 12.00, 15, 3),
('BAK002', '8851959140382', 'Croissant', 'Butter croissant', 4, 18.00, 8.00, 20, 5),
('DRY001', '8851959141521', 'Milk 1L', 'Full cream milk', 5, 40.00, 25.00, 40, 10);

-- Insert customers
INSERT INTO customers (name, email, phone, address) VALUES
('Walk-in Customer', NULL, NULL, NULL),
('John Doe', 'john@example.com', '0891234567', '123 Main St, Bangkok'),
('Jane Smith', 'jane@example.com', '0812345678', '456 Park Ave, Bangkok');

-- Insert suppliers
INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES
('ABC Distributors', 'Bob Johnson', 'bob@abcdist.com', '0234567890', '789 Supply Road, Bangkok'),
('XYZ Goods', 'Mary Williams', 'mary@xyzgoods.com', '0345678901', '101 Vendor Lane, Chiang Mai');

-- Insert settings
INSERT INTO settings (setting_key, setting_value) VALUES
('store_name', 'My POS Store'),
('store_address', '123 Shop Street, Bangkok 10300'),
('store_phone', '0299887766'),
('store_email', 'contact@myposstore.com'),
('tax_percentage', '7'),
('receipt_footer', 'Thank you for shopping with us!'),
('currency_symbol', '฿'),
('currency_code', 'THB');