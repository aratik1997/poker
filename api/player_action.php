<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$player = require_player($pdo, $input['token'] ?? null);
$room = get_room_by_id($pdo, $player['room_id']);

if ($room['status'] !== 'playing' || !in_array($room['phase'], ['preflop', 'flop', 'turn', 'river'], true)) {
    json_error('No betting is happening right now');
}
if ($player['status'] !== 'active') json_error('You are not in this hand');
if ($room['current_turn_seat'] === null || (int)$room['current_turn_seat'] !== (int)$player['seat']) {
    json_error('It is not your turn');
}

$action = $input['action'] ?? '';
$amount = (int)($input['amount'] ?? 0);

apply_action($pdo, $room, $player, $action, $amount);

json_out(['ok' => true]);
