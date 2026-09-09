<?php
mysqli_report(MYSQLI_REPORT_OFF);
$host=getenv('MYSQLHOST')?:'localhost';
$port=(int)(getenv('MYSQLPORT')?:3306);
$user=getenv('MYSQLUSER')?:'root';
$pass=getenv('MYSQLPASSWORD')?:'';
$dbname=getenv('MYSQLDATABASE')?:'railway';

for($i=0;$i<30;$i++){
    $conn=@new mysqli($host,$user,$pass,$dbname,$port);
    if(!$conn->connect_errno) break;
    sleep(2);
}
if(!isset($conn)||$conn->connect_errno){fwrite(STDERR,"CarVault seed skipped: database unavailable.\n");exit(0);}
$conn->set_charset('utf8mb4');
$conn->query("CREATE TABLE IF NOT EXISTS users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,email VARCHAR(150) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("CREATE TABLE IF NOT EXISTS cars(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,car_name VARCHAR(120) NOT NULL,brand VARCHAR(100) NOT NULL,model VARCHAR(120) NOT NULL,year SMALLINT UNSIGNED NOT NULL,color VARCHAR(60) NOT NULL,fuel_type ENUM('Petrol','Diesel','Electric','Hybrid','CNG','Other') NOT NULL DEFAULT 'Petrol',price DECIMAL(14,2) NOT NULL DEFAULT 0,image_data MEDIUMTEXT NULL,image_type VARCHAR(50) NULL,image_url TEXT NULL,description TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(user_id),INDEX(brand),INDEX(year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@$conn->query("ALTER TABLE cars ADD COLUMN image_url TEXT NULL AFTER image_type");

$email='demo@carvault.com';
$password='Demo@12345';
$name='Demo User';
$hash=password_hash($password,PASSWORD_DEFAULT);
$stmt=$conn->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$stmt->bind_param('s',$email);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
if($row){$demoId=(int)$row['id'];$u=$conn->prepare('UPDATE users SET name=?,password=? WHERE id=?');$u->bind_param('ssi',$name,$hash,$demoId);$u->execute();}
else{$i=$conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)');$i->bind_param('sss',$name,$email,$hash);$i->execute();$demoId=(int)$i->insert_id;}

$cars=[
 ['Ferrari 488 GTB','Ferrari','488 GTB',2020,'Rosso Corsa','Petrol',25000000,'Italian mid-engine performance car with a twin-turbo V8 and a beautifully balanced chassis.','https://commons.wikimedia.org/wiki/Special:Redirect/file/2017_Ferrari_488_GTB_Automatic_3.9_Front.jpg?width=1600'],
 ['BMW M4 Competition','BMW','M4 Competition',2022,'Tanzanite Blue','Petrol',14500000,'High-performance coupe with everyday usability, sharp handling and a strong road presence.','https://commons.wikimedia.org/wiki/Special:Redirect/file/BMW_G82_M4_Competition_Tanzanite_Blue_Metallic_%2814%29.jpg?width=1600'],
 ['Toyota Fortuner Legender','Toyota','Fortuner Legender',2023,'Silver Metallic','Diesel',5200000,'Premium touring SUV with strong road presence, long-distance comfort and dependable capability.','https://commons.wikimedia.org/wiki/Special:Redirect/file/Toyota_Fortuner_GUN166_Legender_2.8_Q_4x2_Silver_Metallic_02.jpg?width=1600'],
 ['Tesla Model 3','Tesla','Model 3',2024,'Stealth Grey','Electric',6000000,'Modern electric sedan with instant torque, minimalist design and an effortless daily-driving experience.','https://commons.wikimedia.org/wiki/Special:Redirect/file/Tesla_model_3_grey_%281%29.jpg?width=1600']
];

foreach($cars as $c){
 [$carName,$brand,$model,$year,$color,$fuel,$price,$desc,$url]=$c;
 $q=$conn->prepare('SELECT id FROM cars WHERE user_id=? AND car_name=? LIMIT 1');$q->bind_param('is',$demoId,$carName);$q->execute();$existing=$q->get_result()->fetch_assoc();
 if($existing){$id=(int)$existing['id'];$up=$conn->prepare('UPDATE cars SET brand=?,model=?,year=?,color=?,fuel_type=?,price=?,description=?,image_url=? WHERE id=? AND user_id=?');$up->bind_param('ssissdssii',$brand,$model,$year,$color,$fuel,$price,$desc,$url,$id,$demoId);$up->execute();}
 else{$ins=$conn->prepare('INSERT INTO cars(user_id,car_name,brand,model,year,color,fuel_type,price,description,image_url) VALUES(?,?,?,?,?,?,?,?,?,?)');$ins->bind_param('isssissdss',$demoId,$carName,$brand,$model,$year,$color,$fuel,$price,$desc,$url);$ins->execute();}
}

echo "CarVault demo account and collection ready.\n";
