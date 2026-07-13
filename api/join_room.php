<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$name = clean_name($input['name'] ?? '');
$code = strtoupper(trim($input['room_code'] ?? ''));

if ($code === '') json_error('Enter a room code');
$room = get_room_by_code($pdo, $code);
if (!$room) json_error('Room not found', 404);

$players = get_room_players($pdo, $room['id']);
$activeCount = count(array_filter($players, fn($p) => !in_array($p['status'], ['left', 'kicked'], true)));
if ($activeCount >= MAX_PLAYERS) json_error('Room is full');

foreach ($players as $p) {
    if (strcasecmp($p['name'], $name) === 0 && !in_array($p['status'], ['left', 'kicked'], true)) {
        json_error('That name is already taken in this room');
    }
}

$status = $room['status'] === 'lobby' ? 'active' : 'spectating';
$token = random_token();
$pdo->prepare("INSERT INTO players (room_id, token, name, chips, status, is_host) VALUES (?, ?, ?, ?, ?, 0)")
    ->execute([$room['id'], $token, $name, STARTING_CHIPS, $status]);

if ($status === 'spectating') {
    add_chat($pdo, $room['id'], 'Dealer', $name . ' joined and will spectate until the next hand.', true);
} else {
    add_chat($pdo, $room['id'], 'Dealer', $name . ' joined the room.', true);
}

json_out(['token' => $token, 'room_code' => $room['code']]);
