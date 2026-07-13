<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$player = require_player($pdo, $input['token'] ?? null);

$message = trim((string)($input['message'] ?? ''));
if ($message === '') json_error('Empty message');
$message = mb_substr($message, 0, 300);

add_chat($pdo, $player['room_id'], $player['name'], $message, false);

json_out(['ok' => true]);
