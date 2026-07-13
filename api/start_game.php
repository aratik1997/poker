<?php
require_once __DIR__ . '/../includes/game_engine.php';

$pdo = get_db();
$input = read_json_body();
$host = require_player($pdo, $input['token'] ?? null);
require_host($host);
$room = get_room_by_id($pdo, $host['room_id']);

if ($room['status'] !== 'lobby') json_error('Game already in progress');

$players = get_room_players($pdo, $room['id']);
$joinable = array_filter($players, fn($p) => !in_array($p['status'], ['left', 'kicked'], true));
if (count($joinable) < MIN_PLAYERS) json_error('Need at least ' . MIN_PLAYERS . ' players to start');
if (count($joinable) > MAX_PLAYERS) json_error('Too many players');

start_hand($pdo, $room['id']);

json_out(['ok' => true]);
