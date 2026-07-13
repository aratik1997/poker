<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$player = require_player($pdo, $input['token'] ?? null);
$room = get_room_by_id($pdo, $player['room_id']);

add_chat($pdo, $room['id'], 'Dealer', $player['name'] . ' left the room.', true);

if ($room['status'] === 'lobby') {
    $pdo->prepare("DELETE FROM players WHERE id = ?")->execute([$player['id']]);
} else {
    remove_player_from_hand($pdo, $room, $player, 'left');
}

if ($player['is_host']) {
    // Hand the host role to the longest-seated remaining player.
    $remaining = get_room_players($pdo, $room['id']);
    $remaining = array_values(array_filter($remaining, fn($p) => !in_array($p['status'], ['left', 'kicked'], true)));
    if (count($remaining) > 0) {
        usort($remaining, fn($a, $b) => strtotime($a['joined_at']) <=> strtotime($b['joined_at']));
        $pdo->prepare("UPDATE players SET is_host = 1 WHERE id = ?")->execute([$remaining[0]['id']]);
        add_chat($pdo, $room['id'], 'Dealer', $remaining[0]['name'] . ' is now the host.', true);
    }
}

json_out(['ok' => true]);
