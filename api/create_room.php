<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$name = clean_name($input['name'] ?? '');

do {
    $code = random_room_code();
    $exists = get_room_by_code($pdo, $code);
} while ($exists);

$pdo->prepare("INSERT INTO rooms (code) VALUES (?)")->execute([$code]);
$roomId = (int)$pdo->lastInsertId();

$token = random_token();
$pdo->prepare("INSERT INTO players (room_id, token, name, chips, status, is_host) VALUES (?, ?, ?, ?, 'active', 1)")
    ->execute([$roomId, $token, $name, STARTING_CHIPS]);

add_chat($pdo, $roomId, 'Dealer', $name . ' created the room.', true);

json_out(['token' => $token, 'room_code' => $code]);
