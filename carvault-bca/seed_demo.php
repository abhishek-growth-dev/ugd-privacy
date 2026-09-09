<?php
$host = getenv('MYSQLHOST') ?: 'localhost';
$port = (int)(getenv('MYSQLPORT') ?: 3306);
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$dbname = getenv('MYSQLDATABASE') ?: 'railway';

for ($i = 0; $i < 30; $i++) {
    $conn = @new mysqli($host, $user, $pass, $dbname, $port);
    if (!$conn->connect_errno) break;
    sleep(2);
}

if (!isset($conn) || $conn->connect_errno) {
    fwrite(STDERR, "Demo seed skipped: database unavailable.\n");
    exit(0);
}

$conn->set_charset('utf8mb4');
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS cars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    car_name VARCHAR(120) NOT NULL,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(120) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    color VARCHAR(60) NOT NULL,
    fuel_type ENUM('Petrol','Diesel','Electric','Hybrid','CNG','Other') NOT NULL DEFAULT 'Petrol',
    price DECIMAL(14,2) NOT NULL DEFAULT 0,
    image_data MEDIUMBLOB NULL,
    image_type VARCHAR(50) NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_brand (brand),
    INDEX idx_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$email = 'demo@carvault.com';
$password = 'Demo@12345';
$name = 'Demo User';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $demoUserId = (int)$row['id'];
    $update = $conn->prepare('UPDATE users SET name = ?, password = ? WHERE id = ?');
    $update->bind_param('ssi', $name, $hash, $demoUserId);
    $update->execute();
} else {
    $insert = $conn->prepare('INSERT INTO users(name, email, password) VALUES(?,?,?)');
    $insert->bind_param('sss', $name, $email, $hash);
    $insert->execute();
    $demoUserId = (int)$insert->insert_id;
}

$countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM cars WHERE user_id = ?');
$countStmt->bind_param('i', $demoUserId);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

if ($total === 0) {
    $cars = [
        ['Ferrari 488 GTB','Ferrari','488 GTB',2020,'Rosso Corsa','Petrol',25000000,'Italian mid-engine sports car with a twin-turbo V8.'],
        ['BMW M4 Competition','BMW','M4 Competition',2022,'Portimao Blue','Petrol',14500000,'High-performance coupe with everyday usability.'],
        ['Toyota Fortuner Legender','Toyota','Fortuner Legender',2023,'Pearl White','Diesel',5200000,'Premium SUV built for touring and rough roads.'],
        ['Tesla Model 3','Tesla','Model 3',2024,'Stealth Grey','Electric',6000000,'Modern electric sedan with instant torque and clean design.']
    ];
    $ins = $conn->prepare('INSERT INTO cars(user_id,car_name,brand,model,year,color,fuel_type,price,description) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach ($cars as $car) {
        [$carName,$brand,$model,$year,$color,$fuel,$price,$description] = $car;
        $ins->bind_param('isssissds',$demoUserId,$carName,$brand,$model,$year,$color,$fuel,$price,$description);
        $ins->execute();
    }
}

echo "Demo account ready: {$email}\n";
