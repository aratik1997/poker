<?php
/**
 * Computer players for Poker Night. A bot is a players row with is_bot = 1. It bets through the same
 * apply_action() a person's request uses, so the betting rules and pot accounting are identical.
 *
 * A bot judges its hand as a strength between 0 and 1: from its two hole cards before the flop, and
 * from its best five-card hand once community cards are out. Difficulty decides how it turns that
 * strength into a bet.
 *  - easy:   calls most things, folds weak hands only some of the time, rarely raises.
 *  - medium: folds weak hands, raises strong hands, bets in proportion to the pot.
 *  - hard:   plays the pot odds, value-bets strong hands, and sometimes bluffs with a weak hand.
 *
 * apply_action() calls json_error() on an illegal bet, which would end the request. So a bot only
 * ever chooses an action that the rules accept (check only when nothing is owed, a raise at or above
 * the minimum, an all-in when it can't cover the raise).
 */

require_once __DIR__ . '/game_engine.php';

const BOT_DIFFICULTIES = ['easy', 'medium', 'hard'];

/** Seconds a bot "thinks" before each action, so it reads as a person rather than instant. */
const BOT_DELAY_SECONDS = [
    'easy'   => [2, 4],
    'medium' => [2, 3],
    'hard'   => [1, 3],
];

/** Preflop strength from the two hole cards (0..1). */
function bot_preflop_strength(array $hole): float {
    $values = array_map('card_rank_value', $hole);
    $hi = max($values);
    $lo = min($values);
    if ($hi === $lo) {
        return min(1.0, 0.55 + $hi / 40); // pairs: 0.6 (twos) up to 0.9 (aces)
    }
    $s = ($hi + $lo) / 28;
    if (card_suit($hole[0]) === card_suit($hole[1])) $s += 0.05;
    if ($hi - $lo <= 2) $s += 0.04;          // connected, or one gap apart
    if ($hi >= 12) $s += 0.05;               // queen or better
    return min(1.0, $s);
}

/** Made-hand strength after the flop (0..1), from the best five cards of hole + community. */
function bot_made_strength(array $hole, array $community): float {
    $eval = evaluate_best_hand([...$hole, ...$community]);
    $kicker = $eval['tiebreak'][0] ?? 0; // 2..14
    return min(1.0, 0.1 + $eval['category'] * 0.1 + $kicker / 200);
}

/**
 * Chooses the bot's action. Returns [action, amount], where action is one of
 * fold | check | call | bet | raise | allin, and amount is the total bet level for this round.
 */
function bot_decide(array $room, array $bot, string $difficulty): array {
    $hole = json_decode($bot['hole_cards'] ?? '[]', true) ?: [];
    $community = json_decode($room['community_cards'] ?? '[]', true) ?: [];
    $strength = $community ? bot_made_strength($hole, $community) : bot_preflop_strength($hole);

    $currentBet = (int)$room['current_bet'];
    $toCall = $currentBet - (int)$bot['bet_this_round'];
    $pot = (int)$room['pot'];
    $minRaise = (int)$room['min_raise'];
    $potOdds = $toCall > 0 ? $toCall / ($pot + $toCall) : 0.0;
    $roll = mt_rand(0, 99) / 100;

    // A raise to roughly $fraction of the pot, never below the legal minimum, all-in if short.
    $raise = function (float $fraction) use ($bot, $currentBet, $minRaise, $pot): array {
        $base = max($minRaise, (int)round($pot * $fraction));
        $total = $currentBet + $base;
        if ($total >= (int)$bot['chips'] + (int)$bot['bet_this_round']) {
            return ['allin', 0];
        }
        return [$currentBet > 0 ? 'raise' : 'bet', $total];
    };

    if ($difficulty === 'easy') {
        if ($toCall === 0) {
            return ($strength > 0.85 && $roll < 0.5) ? $raise(0.5) : ['check', 0];
        }
        return ($strength < 0.25 && $roll < 0.6) ? ['fold', 0] : ['call', 0];
    }

    if ($difficulty === 'medium') {
        if ($toCall === 0) {
            return $strength > 0.6 ? $raise(0.6) : ['check', 0];
        }
        if ($strength < 0.3) return ['fold', 0];
        return $strength > 0.75 ? $raise(0.8) : ['call', 0];
    }

    // hard
    if ($toCall === 0) {
        if ($strength > 0.55) return $raise(0.7);
        if ($strength < 0.35 && $roll < 0.12) return $raise(0.6); // a bluff, now and then
        return ['check', 0];
    }
    if ($strength < max(0.2, $potOdds)) return ['fold', 0];
    return $strength > 0.7 ? $raise(1.0) : ['call', 0];
}

/** True once the bot's thinking time for the current turn has passed. Schedules it the first time. */
function bot_due(PDO $pdo, array $room, string $difficulty): bool {
    if ($room['bot_next_at'] === null) {
        $delay = mt_rand(BOT_DELAY_SECONDS[$difficulty][0], BOT_DELAY_SECONDS[$difficulty][1]);
        $pdo->prepare('UPDATE rooms SET bot_next_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() + $delay), $room['id']]);
        return false;
    }
    return strtotime($room['bot_next_at']) <= time();
}

/**
 * Lets the bot whose turn it is act, if its thinking time has passed. Called from get_state.php.
 * Holds the room row locked for the whole action, so two overlapping polls can't both act.
 */
function advance_bots(PDO $pdo, int $roomId): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? FOR UPDATE');
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        $bettingPhases = ['preflop', 'flop', 'turn', 'river'];
        $bot = null;
        if ($room && $room['status'] === 'playing' && in_array($room['phase'], $bettingPhases, true)
            && $room['current_turn_seat'] !== null) {
            foreach (get_room_players($pdo, $roomId) as $p) {
                if ((int)$p['seat'] === (int)$room['current_turn_seat'] && $p['status'] === 'active') {
                    $bot = $p;
                    break;
                }
            }
        }

        if (!$bot || !(int)$bot['is_bot']) {
            if ($room && $room['bot_next_at'] !== null) {
                $pdo->prepare('UPDATE rooms SET bot_next_at = NULL WHERE id = ?')->execute([$roomId]);
            }
            $pdo->commit();
            return;
        }

        $difficulty = in_array($bot['bot_difficulty'], BOT_DIFFICULTIES, true) ? $bot['bot_difficulty'] : 'medium';
        if (!bot_due($pdo, $room, $difficulty)) {
            $pdo->commit();
            return;
        }

        [$action, $amount] = bot_decide($room, $bot, $difficulty);
        apply_action($pdo, $room, $bot, $action, $amount);

        $pdo->prepare('UPDATE rooms SET bot_next_at = NULL WHERE id = ?')->execute([$roomId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
