<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$token = $_GET['token'] ?? '';
$sinceChat = (int)($_GET['since_chat'] ?? 0);

if (!$token) json_error('Missing token', 401);
$player = get_player_by_token($pdo, $token);
if (!$player) json_error('Not found in any room', 404);

if ($player['status'] === 'kicked') {
    json_out(['kicked' => true, 'message' => $player['kick_message'] ?: 'You were kicked by the host']);
}

$pdo->prepare("UPDATE players SET last_seen = NOW() WHERE id = ?")->execute([$player['id']]);

$room = get_room_by_id($pdo, $player['room_id']);
if (!$room) json_error('Room not found', 404);

maybe_advance_from_showdown($pdo, $room);
$room = get_room_by_id($pdo, $room['id']); // reload in case a hand was just dealt
$player = get_player_by_token($pdo, $token); // reload player too

$allPlayers = get_room_players($pdo, $room['id']);
$visiblePlayers = array_values(array_filter($allPlayers, fn($p) => !in_array($p['status'], ['left', 'kicked'], true)));

$publicPlayers = array_map(function ($p) use ($player) {
    $isMe = $p['id'] === $player['id'];
    $cardCount = $p['hole_cards'] ? count(json_decode($p['hole_cards'], true)) : 0;
    return [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'chips' => (int)$p['chips'],
        'seat' => $p['seat'] === null ? null : (int)$p['seat'],
        'status' => $p['status'],
        'is_host' => (bool)$p['is_host'],
        'is_me' => $isMe,
        'bet_this_round' => (int)$p['bet_this_round'],
        'last_action' => $p['last_action'],
        'card_count' => $cardCount,
        'hole_cards' => ($isMe || $p['reveal_cards']) && $p['hole_cards'] ? json_decode($p['hole_cards'], true) : null,
    ];
}, $visiblePlayers);

$scoreboard = array_values(array_filter($visiblePlayers, fn($p) => $p['status'] !== 'spectating'));
usort($scoreboard, fn($a, $b) => $b['chips'] <=> $a['chips']);
$scoreboard = array_map(fn($p) => ['name' => $p['name'], 'chips' => (int)$p['chips'], 'status' => $p['status']], $scoreboard);

$chatStmt = $pdo->prepare("SELECT id, sender_name, message, is_system, created_at FROM chat_messages WHERE room_id = ? AND id > ? ORDER BY id ASC LIMIT 100");
$chatStmt->execute([$room['id'], $sinceChat]);
$chat = $chatStmt->fetchAll();

$toCall = max(0, $room['current_bet'] - $player['bet_this_round']);
$isYourTurn = $room['status'] === 'playing' && $room['current_turn_seat'] !== null
    && $player['seat'] !== null && (int)$room['current_turn_seat'] === (int)$player['seat']
    && $player['status'] === 'active';

json_out([
    'room' => [
        'code' => $room['code'],
        'status' => $room['status'],
        'phase' => $room['phase'],
        'community_cards' => json_decode($room['community_cards'] ?: '[]', true),
        'pot' => (int)$room['pot'],
        'current_bet' => (int)$room['current_bet'],
        'min_raise' => (int)$room['min_raise'],
        'dealer_seat' => (int)$room['dealer_seat'],
        'current_turn_seat' => $room['current_turn_seat'] === null ? null : (int)$room['current_turn_seat'],
        'round_number' => (int)$room['round_number'],
        'last_result' => $room['last_result'] ? json_decode($room['last_result'], true) : null,
        'event_seq' => (int)$room['event_seq'],
        'event_type' => $room['event_type'],
    ],
    'players' => $publicPlayers,
    'scoreboard' => $scoreboard,
    'chat' => $chat,
    'you' => [
        'id' => (int)$player['id'],
        'name' => $player['name'],
        'chips' => (int)$player['chips'],
        'seat' => $player['seat'] === null ? null : (int)$player['seat'],
        'status' => $player['status'],
        'is_host' => (bool)$player['is_host'],
        'hole_cards' => $player['hole_cards'] ? json_decode($player['hole_cards'], true) : null,
        'bet_this_round' => (int)$player['bet_this_round'],
        'to_call' => $toCall,
        'is_your_turn' => $isYourTurn,
        'min_raise_total' => (int)$room['current_bet'] + (int)$room['min_raise'],
    ],
]);
