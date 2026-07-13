<?php
require_once __DIR__ . '/../config.php';

function get_db() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    try {
        // Most hosts (shared hosting included) pre-create the database for you and the
        // DB user has no CREATE DATABASE privilege - so try connecting straight to it first.
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown database') === false) throw $e;
        // Local dev (e.g. XAMPP root) - the database doesn't exist yet, so create it.
        $root = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $root->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    init_schema($pdo);

    return $pdo;
}

function init_schema(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(6) NOT NULL UNIQUE,
            status ENUM('lobby','playing') NOT NULL DEFAULT 'lobby',
            phase ENUM('waiting','preflop','flop','turn','river','showdown') NOT NULL DEFAULT 'waiting',
            dealer_seat INT NOT NULL DEFAULT 0,
            round_number INT NOT NULL DEFAULT 0,
            community_cards TEXT NULL,
            deck TEXT NULL,
            pot INT NOT NULL DEFAULT 0,
            side_pots TEXT NULL,
            current_bet INT NOT NULL DEFAULT 0,
            min_raise INT NOT NULL DEFAULT 0,
            current_turn_seat INT NULL,
            last_aggressor_seat INT NULL,
            last_result TEXT NULL,
            event_seq INT NOT NULL DEFAULT 0,
            event_type VARCHAR(20) NULL,
            showdown_at DATETIME NULL,
            advancing TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(30) NOT NULL,
            chips INT NOT NULL DEFAULT 0,
            seat INT NULL,
            status ENUM('active','folded','allin','left','spectating','kicked','eliminated') NOT NULL DEFAULT 'spectating',
            is_host TINYINT(1) NOT NULL DEFAULT 0,
            hole_cards TEXT NULL,
            reveal_cards TINYINT(1) NOT NULL DEFAULT 0,
            bet_this_round INT NOT NULL DEFAULT 0,
            contributed_total INT NOT NULL DEFAULT 0,
            has_acted TINYINT(1) NOT NULL DEFAULT 0,
            last_action VARCHAR(30) NULL,
            pending_leave TINYINT(1) NOT NULL DEFAULT 0,
            kick_message VARCHAR(255) NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            sender_name VARCHAR(30) NOT NULL,
            message VARCHAR(500) NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
