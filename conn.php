<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli('localhost', 'root', '', 'mydb');
$conn->set_charset('utf8mb4');
$conn->query(
    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(254) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_user_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$conn->query(
    "CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        category ENUM('acoustic', 'bass', 'electric') NOT NULL,
        brand VARCHAR(100) NOT NULL DEFAULT '',
        model VARCHAR(100) NOT NULL DEFAULT '',
        image_path VARCHAR(255) NOT NULL DEFAULT '',
        price DECIMAL(10, 2) UNSIGNED NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_products_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$imageColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'image_path'");
if ($imageColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD image_path VARCHAR(255) NOT NULL DEFAULT '' AFTER model");
}
