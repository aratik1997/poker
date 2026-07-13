<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$host = require_player($pdo, $input['token'] ?? null);
require_host($host);
$room = get_room_by_id($pdo, $host['room_id']);

if ($room['status'] !== 'playing') json_error('No hand is in progress');

cancel_and_restart($pdo, $room['id']);

json_out(['ok' => true]);
