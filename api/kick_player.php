<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$host = require_player($pdo, $input['token'] ?? null);
require_host($host);
$room = get_room_by_id($pdo, $host['room_id']);

$targetId = (int)($input['player_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM players WHERE id = ? AND room_id = ?");
$stmt->execute([$targetId, $room['id']]);
$target = $stmt->fetch();
if (!$target) json_error('Player not found', 404);
if ($target['is_host']) json_error('Host cannot kick themselves');

$pdo->prepare("UPDATE players SET kick_message = ? WHERE id = ?")
    ->execute([$host['name'] . ' kicked you', $target['id']]);

if ($room['status'] === 'lobby') {
    $pdo->prepare("UPDATE players SET status='kicked' WHERE id = ?")->execute([$target['id']]);
} else {
    remove_player_from_hand($pdo, $room, $target, 'kicked');
}

add_chat($pdo, $room['id'], 'Dealer', $host['name'] . ' kicked ' . $target['name'] . '.', true);

json_out(['ok' => true]);
