<?php
// Reservations are additive: private garage records are never listed by default.
function rq($db, $sql, $types = '', ...$values) {
    $s = $db->prepare($sql);
    if (!$s) throw new RuntimeException('Database operation failed.');
    if ($types !== '') $s->bind_param($types, ...$values);
    if (!$s->execute()) throw new RuntimeException('Database operation failed.');
    return $s;
}
function reservationSchema($db) {
    foreach ([
        "CREATE TABLE IF NOT EXISTS car_listings (
            car_id INT UNSIGNED PRIMARY KEY,
            is_listed TINYINT NOT NULL DEFAULT 0,
            is_demo TINYINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_listing_car FOREIGN KEY(car_id) REFERENCES cars(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS reservations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference VARCHAR(20) NOT NULL UNIQUE,
            car_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            car_name VARCHAR(120) NOT NULL,
            listed_value DECIMAL(14,2) NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 0,
            status ENUM('pending','confirmed','declined','cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(user_id,created_at), INDEX(owner_id,status), INDEX(car_id,status),
            CONSTRAINT fk_reservation_car FOREIGN KEY(car_id) REFERENCES cars(id) ON DELETE SET NULL,
            CONSTRAINT fk_reservation_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_reservation_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ] as $sql) {
        if (!$db->query($sql)) throw new RuntimeException('Reservation setup failed.');
    }
}
function listing($db, $id) {
    return rq($db, "SELECT c.id,c.user_id,c.car_name,c.brand,c.model,c.year,c.color,c.fuel_type,c.price,c.image_data,c.image_type,c.image_url,l.is_demo,
        EXISTS(SELECT 1 FROM reservations r WHERE r.car_id=c.id AND r.status IN ('pending','confirmed')) AS reserved
        FROM cars c JOIN car_listings l ON l.car_id=c.id AND l.is_listed=1 WHERE c.id=?", 'i', $id)->get_result()->fetch_assoc();
}
function reservationAction($db, $action) {
    if (!in_array($action, ['set_listing','reserve_car','cancel_reservation','review_reservation'], true)) return;
    guard();
    if (!csrf()) { flash('error','Your session expired. Please try again.'); go('?p=user_panel'); }
    $uid = (int)$_SESSION['user_id'];
    $id = (int)($_POST['id'] ?? 0);
    $destination = '?p=user_panel';
    try {
        if (!$db->begin_transaction()) throw new RuntimeException('Please try again.');
        if ($action === 'set_listing' || $action === 'reserve_car') {
            // All inventory mutations use the same car-row lock, including deletion.
            $car = rq($db, 'SELECT * FROM cars WHERE id=? FOR UPDATE', 'i', $id)->get_result()->fetch_assoc();
            if (!$car) throw new DomainException('This vehicle is no longer available.');
            if ($action === 'set_listing') {
                if ((int)$car['user_id'] !== $uid) throw new DomainException('You can only list your own vehicles.');
                $listed = ($_POST['listed'] ?? '') === '1' ? 1 : 0;
                rq($db, 'INSERT INTO car_listings(car_id,is_listed) VALUES(?,?) ON DUPLICATE KEY UPDATE is_listed=VALUES(is_listed)', 'ii', $id, $listed);
                $message = $listed ? 'Vehicle listed for reservations. Your private notes remain private.' : 'Vehicle removed from the public listings.';
                $destination = '?p=view&id='.$id;
            } else {
                $offer = rq($db, 'SELECT * FROM car_listings WHERE car_id=? AND is_listed=1', 'i', $id)->get_result()->fetch_assoc();
                if (!$offer) throw new DomainException('This vehicle is not listed for reservations.');
                if ((int)$car['user_id'] === $uid) throw new DomainException('You cannot reserve your own vehicle.');
                $demo = (int)$offer['is_demo'];
                $active = rq($db, "SELECT id FROM reservations WHERE car_id=? AND status IN ('pending','confirmed') AND (?=0 OR user_id=?) LIMIT 1 FOR UPDATE", 'iii', $id, $demo, $uid)->get_result()->fetch_assoc();
                if ($active) throw new DomainException($demo ? 'You already have an active demo reservation for this car.' : 'This car already has an active reservation. Please choose another vehicle.');
                $reference = 'CV-'.strtoupper(bin2hex(random_bytes(6)));
                $status = $demo ? 'confirmed' : 'pending';
                rq($db, 'INSERT INTO reservations(reference,car_id,user_id,owner_id,car_name,listed_value,is_demo,status) VALUES(?,?,?,?,?,?,?,?)', 'siiisdis', $reference, $id, $uid, (int)$car['user_id'], $car['car_name'], (float)$car['price'], $demo, $status);
                $message = $demo ? 'Demo reservation saved. No real vehicle or payment is involved.' : 'Reservation request submitted. The owner will review it; track the status below.';
            }
        } else {
            // Read only the lock key first; authorization and status are rechecked under lock.
            $key = rq($db, 'SELECT car_id FROM reservations WHERE id=?', 'i', $id)->get_result()->fetch_assoc();
            if (!$key) throw new DomainException('Reservation not found.');
            if ($key['car_id']) rq($db, 'SELECT id FROM cars WHERE id=? FOR UPDATE', 'i', (int)$key['car_id']);
            $r = rq($db, 'SELECT * FROM reservations WHERE id=? FOR UPDATE', 'i', $id)->get_result()->fetch_assoc();
            if ($action === 'cancel_reservation') {
                if ((int)$r['user_id'] !== $uid) throw new DomainException('Reservation not found.');
                if (!in_array($r['status'], ['pending','confirmed'], true)) throw new DomainException('This reservation is already closed.');
                $status = 'cancelled';
            } else {
                if ((int)$r['owner_id'] !== $uid || (int)$r['is_demo']) throw new DomainException('You cannot review this reservation.');
                $status = $_POST['status'] ?? '';
                $allowed = $r['status'] === 'pending' ? ['confirmed','declined'] : ($r['status'] === 'confirmed' ? ['cancelled'] : []);
                if (!in_array($status, $allowed, true)) throw new DomainException('This status change is no longer available.');
                $destination = '?p=user_panel&tab=requests';
            }
            rq($db, 'UPDATE reservations SET status=? WHERE id=?', 'si', $status, $id);
            $message = 'Reservation '. $status .'.';
        }
        if (!$db->commit()) throw new RuntimeException('Please try again.');
        flash('success', $message);
    } catch (DomainException $ex) {
        $db->rollback(); flash('error', $ex->getMessage());
    } catch (Throwable $ex) {
        $db->rollback(); error_log('CarVault reservation operation failed.');
        flash('error', 'Could not save this change. Please refresh and try again.');
    }
    go($destination);
}
