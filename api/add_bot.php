<?php
require_once __DIR__ . '/../includes/game_engine.php';
require_once __DIR__ . '/../includes/bots.php';

$pdo = get_db();
$input = read_json_body();
$player = require_player($pdo, $input['token'] ?? null);
require_host($player);

$difficulty = strtolower(trim((string)($input['difficulty'] ?? 'medium')));
if (!in_array($difficulty, BOT_DIFFICULTIES, true)) json_error('Bot difficulty must be easy, medium or hard');

$room = get_room_by_id($pdo, (int)$player['room_id']);
if (!$room) json_error('Room not found', 404);

$players = get_room_players($pdo, $room['id']);
$present = array_values(array_filter($players, fn($p) => !in_array($p['status'], ['left', 'kicked'], true)));
if (count($present) >= MAX_PLAYERS) json_error('Room is full');

// The first "Bot N" name nobody in the room is using.
$taken = array_map(fn($p) => mb_strtolower($p['name']), $present);
$number = 1;
while (in_array(mb_strtolower("Bot {$number}"), $taken, true)) {
    $number++;
}
$name = "Bot {$number}";

// Same rule as a person joining: seated in the lobby, or spectating until the next hand.
$status = $room['status'] === 'lobby' ? 'active' : 'spectating';
$pdo->prepare("INSERT INTO players (room_id, token, name, chips, status, is_host, is_bot, bot_difficulty) VALUES (?, ?, ?, ?, ?, 0, 1, ?)")
    ->execute([$room['id'], random_token(), $name, STARTING_CHIPS, $status, $difficulty]);

add_chat($pdo, $room['id'], 'Dealer', $name . ' joined the table.', true);

json_out(['ok' => true, 'name' => $name]);
