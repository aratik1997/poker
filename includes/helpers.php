<?php
require_once __DIR__ . '/db.php';

function json_out($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400) {
    json_out(['error' => $message], $status);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

function random_token(int $bytes = 24): string {
    return bin2hex(random_bytes($bytes));
}

function random_room_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no confusing chars
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function clean_name(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $name = substr($name, 0, 20);
    return $name === '' ? 'Player' : $name;
}

function get_room_by_code(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE code = ?");
    $stmt->execute([strtoupper($code)]);
    $room = $stmt->fetch();
    return $room ?: null;
}

function get_room_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $room = $stmt->fetch();
    return $room ?: null;
}

function get_player_by_token(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE token = ?");
    $stmt->execute([$token]);
    $player = $stmt->fetch();
    return $player ?: null;
}

function get_room_players(PDO $pdo, int $roomId): array {
    $stmt = $pdo->prepare("SELECT * FROM players WHERE room_id = ? ORDER BY seat IS NULL, seat ASC, joined_at ASC");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

function require_player(PDO $pdo, ?string $token): array {
    if (!$token) json_error('Missing token', 401);
    $player = get_player_by_token($pdo, $token);
    if (!$player) json_error('Not found in any room', 404);
    if ($player['status'] === 'kicked') json_error('You have been kicked', 403);
    $stmt = $pdo->prepare("UPDATE players SET last_seen = NOW() WHERE id = ?");
    $stmt->execute([$player['id']]);
    return $player;
}

function require_host(array $player) {
    if (!$player['is_host']) json_error('Only the host can do that', 403);
}

function add_chat(PDO $pdo, int $roomId, string $sender, string $message, bool $system = false) {
    $stmt = $pdo->prepare("INSERT INTO chat_messages (room_id, sender_name, message, is_system) VALUES (?, ?, ?, ?)");
    $stmt->execute([$roomId, $sender, $message, $system ? 1 : 0]);
}

function bump_event(PDO $pdo, int $roomId, string $type) {
    $stmt = $pdo->prepare("UPDATE rooms SET event_seq = event_seq + 1, event_type = ? WHERE id = ?");
    $stmt->execute([$type, $roomId]);
}
