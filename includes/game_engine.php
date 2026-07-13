<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/deck.php';
require_once __DIR__ . '/hand_eval.php';

// Statuses that are still contesting the pot in the current hand.
function is_in_hand(string $status): bool {
    return in_array($status, ['active', 'allin'], true);
}

function find_next_seat_to_act(array $players, int $fromSeat): ?array {
    // Build a map seat => player among all players still in hand
    $bySeat = [];
    $maxSeat = -1;
    foreach ($players as $p) {
        if ($p['seat'] !== null) {
            $bySeat[$p['seat']] = $p;
            $maxSeat = max($maxSeat, $p['seat']);
        }
    }
    if ($maxSeat < 0) return null;
    for ($i = 1; $i <= $maxSeat + 1; $i++) {
        $seat = ($fromSeat + $i) % ($maxSeat + 1);
        if (isset($bySeat[$seat]) && $bySeat[$seat]['status'] === 'active' && $bySeat[$seat]['chips'] > 0) {
            return $bySeat[$seat];
        }
    }
    return null;
}

// Starts a brand new hand: purges leavers, promotes spectators, reshuffles seating order, posts blinds, deals hole cards.
function start_hand(PDO $pdo, int $roomId): void {
    // Remove anyone who asked to leave or was kicked - they no longer occupy a seat.
    $pdo->prepare("DELETE FROM players WHERE room_id = ? AND status IN ('left','kicked')")->execute([$roomId]);

    $players = get_room_players($pdo, $roomId);
    $eligible = array_values(array_filter($players, fn($p) => $p['chips'] > 0));
    // Anyone with 0 chips is eliminated - keep them (for the scoreboard) but they never get a seat again.
    foreach ($players as $p) {
        if ($p['chips'] <= 0 && $p['status'] !== 'eliminated') {
            $pdo->prepare("UPDATE players SET status = 'eliminated', seat = NULL WHERE id = ?")->execute([$p['id']]);
        }
    }

    if (count($eligible) < MIN_PLAYERS) {
        // Not enough players to deal - go back to lobby and wait.
        $pdo->prepare("UPDATE rooms SET status='lobby', phase='waiting', current_turn_seat=NULL, pot=0 WHERE id = ?")->execute([$roomId]);
        return;
    }

    // Reshuffle seating/turn order every hand.
    shuffle($eligible);
    $seatUpdate = $pdo->prepare("UPDATE players SET seat=?, status='active', hole_cards=NULL, reveal_cards=0, bet_this_round=0, contributed_total=0, has_acted=0, last_action=NULL WHERE id=?");
    foreach ($eligible as $i => $p) {
        $seatUpdate->execute([$i, $p['id']]);
    }
    $n = count($eligible);

    $deck = new_shuffled_deck();
    $hands = [];
    foreach ($eligible as $p) $hands[$p['id']] = [];
    // Deal two cards to each player, one at a time around the table.
    for ($round = 0; $round < 2; $round++) {
        foreach ($eligible as $p) {
            $hands[$p['id']][] = array_pop($deck);
        }
    }
    $holeStmt = $pdo->prepare("UPDATE players SET hole_cards=? WHERE id=?");
    foreach ($hands as $pid => $cards) {
        $holeStmt->execute([json_encode($cards), $pid]);
    }

    $dealerSeat = 0;
    $sbSeat = $n === 2 ? 0 : 1;
    $bbSeat = $n === 2 ? 1 : 2;
    $firstToActSeat = $n === 2 ? 0 : (3 % $n);

    $bySeat = [];
    foreach ($eligible as $i => $p) $bySeat[$i] = $p;

    $sbAmount = min(SMALL_BLIND, $bySeat[$sbSeat]['chips']);
    $bbAmount = min(BIG_BLIND, $bySeat[$bbSeat]['chips']);

    $blindStmt = $pdo->prepare("UPDATE players SET chips = chips - ?, bet_this_round = ?, contributed_total = ?, status = ? WHERE id = ?");
    $blindStmt->execute([$sbAmount, $sbAmount, $sbAmount, $sbAmount >= $bySeat[$sbSeat]['chips'] + $sbAmount ? 'allin' : 'active', $bySeat[$sbSeat]['id']]);
    $blindStmt->execute([$bbAmount, $bbAmount, $bbAmount, $bbAmount >= $bySeat[$bbSeat]['chips'] + $bbAmount ? 'allin' : 'active', $bySeat[$bbSeat]['id']]);

    $pot = $sbAmount + $bbAmount;

    $pdo->prepare("
        UPDATE rooms SET status='playing', phase='preflop', dealer_seat=?, round_number = round_number + 1,
            community_cards='[]', deck=?, pot=?, side_pots=NULL, current_bet=?, min_raise=?,
            current_turn_seat=?, last_aggressor_seat=?, last_result=NULL, showdown_at=NULL
        WHERE id = ?
    ")->execute([$dealerSeat, json_encode($deck), $pot, $bbAmount, BIG_BLIND, $firstToActSeat, $bbSeat, $roomId]);

    add_chat($pdo, $roomId, 'Dealer', 'New hand started. Good luck!', true);
    bump_event($pdo, $roomId, 'deal_hole');
}

function betting_round_settled(array $players): bool {
    $inHand = array_filter($players, fn($p) => is_in_hand($p['status']));
    $canAct = array_filter($inHand, fn($p) => $p['status'] === 'active' && $p['chips'] > 0);
    if (count($canAct) <= 1 && count($inHand) > 1) {
        // At most one player left who can still act (others all-in) - round is settled once that player has acted.
        foreach ($canAct as $p) {
            if (!$p['has_acted']) return false;
        }
        return true;
    }
    foreach ($inHand as $p) {
        if ($p['status'] !== 'active') continue;
        if (!$p['has_acted']) return false;
    }
    $bets = array_map(fn($p) => $p['bet_this_round'], array_filter($inHand, fn($p) => $p['status'] === 'active'));
    if (count(array_unique($bets)) > 1) return false;
    return true;
}

function reset_betting_round(PDO $pdo, array $players): void {
    $stmt = $pdo->prepare("UPDATE players SET bet_this_round=0, has_acted=0 WHERE id=?");
    foreach ($players as $p) {
        if (is_in_hand($p['status'])) $stmt->execute([$p['id']]);
    }
}

function advance_phase(PDO $pdo, array $room): void {
    $roomId = $room['id'];
    $players = get_room_players($pdo, $roomId);
    $inHand = array_filter($players, fn($p) => is_in_hand($p['status']));

    if (count($inHand) <= 1) {
        award_uncontested_pot($pdo, $room, $players);
        return;
    }

    reset_betting_round($pdo, $players);
    $deck = json_decode($room['deck'], true) ?: [];
    $community = json_decode($room['community_cards'], true) ?: [];

    $phase = $room['phase'];
    if ($phase === 'preflop') {
        array_pop($deck); // burn
        $community[] = array_pop($deck);
        $community[] = array_pop($deck);
        $community[] = array_pop($deck);
        $newPhase = 'flop';
        $eventType = 'flop';
    } elseif ($phase === 'flop') {
        array_pop($deck);
        $community[] = array_pop($deck);
        $newPhase = 'turn';
        $eventType = 'turn';
    } elseif ($phase === 'turn') {
        array_pop($deck);
        $community[] = array_pop($deck);
        $newPhase = 'river';
        $eventType = 'river';
    } else {
        do_showdown($pdo, $room, $players);
        return;
    }

    $canAct = array_filter($inHand, fn($p) => $p['status'] === 'active' && $p['chips'] > 0);
    $firstSeat = $room['dealer_seat'];
    $next = count($canAct) > 1 ? find_next_seat_to_act($players, $firstSeat) : null;

    $pdo->prepare("UPDATE rooms SET phase=?, community_cards=?, deck=?, current_bet=0, min_raise=?, current_turn_seat=? WHERE id=?")
        ->execute([$newPhase, json_encode($community), json_encode($deck), BIG_BLIND, $next ? $next['seat'] : null, $roomId]);

    bump_event($pdo, $roomId, $eventType);

    if (!$next) {
        // Everyone left is all-in - keep dealing straight through to showdown.
        $room = get_room_by_id($pdo, $roomId);
        advance_phase($pdo, $room);
    }
}

function award_uncontested_pot(PDO $pdo, array $room, array $players): void {
    $winner = null;
    foreach ($players as $p) {
        if (is_in_hand($p['status'])) { $winner = $p; break; }
    }
    $roomId = $room['id'];
    if ($winner) {
        $pdo->prepare("UPDATE players SET chips = chips + ? WHERE id = ?")->execute([$room['pot'], $winner['id']]);
        add_chat($pdo, $roomId, 'Dealer', $winner['name'] . ' wins the pot of ' . $room['pot'] . ' chips (everyone else folded).', true);
        $pdo->prepare("UPDATE rooms SET last_result=? WHERE id=?")->execute([json_encode(['type' => 'fold_win', 'winner' => $winner['name'], 'amount' => $room['pot']]), $roomId]);
    }
    $pdo->prepare("UPDATE rooms SET phase='showdown', current_turn_seat=NULL, showdown_at=NOW() WHERE id=?")->execute([$roomId]);
    bump_event($pdo, $roomId, 'showdown');
}

function do_showdown(PDO $pdo, array $room, array $players): void {
    $roomId = $room['id'];
    $community = json_decode($room['community_cards'], true) ?: [];
    $contenders = array_values(array_filter($players, fn($p) => is_in_hand($p['status'])));

    // Build side pots from each contributor's total contribution this hand.
    $contribs = [];
    foreach ($players as $p) {
        if ($p['contributed_total'] > 0) {
            $contribs[] = ['id' => $p['id'], 'name' => $p['name'], 'amount' => $p['contributed_total'], 'folded' => !is_in_hand($p['status'])];
        }
    }
    $pots = [];
    while (count($contribs) > 0) {
        $min = min(array_column($contribs, 'amount'));
        $potAmount = $min * count($contribs);
        $eligible = array_values(array_filter($contribs, fn($c) => !$c['folded']));
        $pots[] = ['amount' => $potAmount, 'eligible' => array_column($eligible, 'id')];
        foreach ($contribs as &$c) $c['amount'] -= $min;
        unset($c);
        $contribs = array_values(array_filter($contribs, fn($c) => $c['amount'] > 0));
    }

    $evalById = [];
    foreach ($contenders as $p) {
        $hole = json_decode($p['hole_cards'], true) ?: [];
        $evalById[$p['id']] = evaluate_best_hand(array_merge($hole, $community));
    }

    $chipStmt = $pdo->prepare("UPDATE players SET chips = chips + ? WHERE id = ?");
    $summaries = [];
    foreach ($pots as $pot) {
        $eligibleIds = array_filter($pot['eligible'], fn($id) => isset($evalById[$id]));
        if (empty($eligibleIds)) continue;
        // Find the winner(s) for this pot (may split on a tie).
        $winners = [];
        $bestEval = null;
        foreach ($eligibleIds as $id) {
            $e = $evalById[$id];
            if ($bestEval === null || compare_evaluations($e, $bestEval) > 0) {
                $bestEval = $e;
                $winners = [$id];
            } elseif (compare_evaluations($e, $bestEval) === 0) {
                $winners[] = $id;
            }
        }
        $share = intdiv($pot['amount'], count($winners));
        $remainder = $pot['amount'] % count($winners);
        foreach ($winners as $i => $id) {
            $amount = $share + ($i === 0 ? $remainder : 0);
            $chipStmt->execute([$amount, $id]);
            $name = current(array_filter($players, fn($p) => $p['id'] == $id))['name'];
            $summaries[] = $name . ' wins ' . $amount . ' with ' . $bestEval['name'];
        }
    }

    foreach ($contenders as $p) {
        $pdo->prepare("UPDATE players SET reveal_cards=1 WHERE id=?")->execute([$p['id']]);
    }

    $resultText = implode('; ', array_unique($summaries));
    add_chat($pdo, $roomId, 'Dealer', 'Showdown: ' . $resultText, true);
    $pdo->prepare("UPDATE rooms SET phase='showdown', current_turn_seat=NULL, last_result=?, showdown_at=NOW() WHERE id=?")
        ->execute([json_encode(['type' => 'showdown', 'text' => $resultText]), $roomId]);
    bump_event($pdo, $roomId, 'showdown');
}

function apply_action(PDO $pdo, array $room, array $player, string $action, int $amount): void {
    $roomId = $room['id'];
    $toCall = $room['current_bet'] - $player['bet_this_round'];

    if ($action === 'fold') {
        $pdo->prepare("UPDATE players SET status='folded', has_acted=1, last_action='Fold' WHERE id=?")->execute([$player['id']]);
    } elseif ($action === 'check') {
        if ($toCall > 0) json_error('Cannot check, must call or fold');
        $pdo->prepare("UPDATE players SET has_acted=1, last_action='Check' WHERE id=?")->execute([$player['id']]);
    } elseif ($action === 'call') {
        $callAmt = min($toCall, $player['chips']);
        $newStatus = $callAmt >= $player['chips'] ? 'allin' : 'active';
        $pdo->prepare("UPDATE players SET chips=chips-?, bet_this_round=bet_this_round+?, contributed_total=contributed_total+?, has_acted=1, status=?, last_action=? WHERE id=?")
            ->execute([$callAmt, $callAmt, $callAmt, $newStatus, $newStatus === 'allin' ? 'All-in' : 'Call', $player['id']]);
        $pdo->prepare("UPDATE rooms SET pot=pot+? WHERE id=?")->execute([$callAmt, $roomId]);
    } elseif ($action === 'bet' || $action === 'raise' || $action === 'allin') {
        $wantTotal = $action === 'allin' ? $player['chips'] + $player['bet_this_round'] : $amount;
        $wantTotal = min($wantTotal, $player['chips'] + $player['bet_this_round']);
        $addAmt = $wantTotal - $player['bet_this_round'];
        if ($addAmt <= 0 || $addAmt > $player['chips']) json_error('Invalid bet amount');
        $newBetTotal = $player['bet_this_round'] + $addAmt;
        $isAllin = $addAmt === $player['chips'];
        $minAllowed = $room['current_bet'] > 0 ? $room['current_bet'] + $room['min_raise'] : $room['min_raise'];
        if (!$isAllin && $newBetTotal < $minAllowed) {
            json_error('Raise too small');
        }
        $newStatus = $isAllin ? 'allin' : 'active';
        $pdo->prepare("UPDATE players SET chips=chips-?, bet_this_round=?, contributed_total=contributed_total+?, has_acted=1, status=?, last_action=? WHERE id=?")
            ->execute([$addAmt, $newBetTotal, $addAmt, $newStatus, $isAllin ? 'All-in' : ($room['current_bet'] > 0 ? 'Raise to ' . $newBetTotal : 'Bet ' . $newBetTotal), $player['id']]);
        $pdo->prepare("UPDATE rooms SET pot=pot+? WHERE id=?")->execute([$addAmt, $roomId]);

        $raiseSize = max($newBetTotal - $room['current_bet'], $room['min_raise']);
        if ($newBetTotal > $room['current_bet']) {
            // Reset has_acted for everyone else since there's a new bet to respond to.
            $others = get_room_players($pdo, $roomId);
            $resetStmt = $pdo->prepare("UPDATE players SET has_acted=0 WHERE id=?");
            foreach ($others as $p) {
                if ($p['id'] != $player['id'] && is_in_hand($p['status']) && $p['status'] === 'active') $resetStmt->execute([$p['id']]);
            }
            $pdo->prepare("UPDATE rooms SET current_bet=?, min_raise=?, last_aggressor_seat=? WHERE id=?")
                ->execute([$newBetTotal, $raiseSize, $player['seat'], $roomId]);
        }
    } else {
        json_error('Unknown action');
    }

    bump_event($pdo, $roomId, 'action');
    progress_game($pdo, $roomId, $player['seat']);
}

// Shared post-action bookkeeping: checks for a lone remaining player, a settled betting
// round, or hands the turn to the next active seat. Used after actions, folds-on-leave, and kicks.
function progress_game(PDO $pdo, int $roomId, ?int $actingSeat): void {
    $room = get_room_by_id($pdo, $roomId);
    if ($room['status'] !== 'playing' || !in_array($room['phase'], ['preflop', 'flop', 'turn', 'river'], true)) return;

    $players = get_room_players($pdo, $roomId);
    $inHand = array_filter($players, fn($p) => is_in_hand($p['status']));

    if (count($inHand) <= 1) {
        award_uncontested_pot($pdo, $room, $players);
        return;
    }

    if (betting_round_settled($players)) {
        advance_phase($pdo, $room);
        return;
    }

    $next = find_next_seat_to_act($players, $actingSeat ?? $room['dealer_seat']);
    $pdo->prepare("UPDATE rooms SET current_turn_seat=? WHERE id=?")->execute([$next ? $next['seat'] : null, $roomId]);
    if (!$next) {
        $room = get_room_by_id($pdo, $roomId);
        advance_phase($pdo, $room);
    }
}

// Removes a player from an in-progress hand (they left or were kicked). If it was their
// turn, or their departure ends the betting round, the game keeps moving without them.
function remove_player_from_hand(PDO $pdo, array $room, array $player, string $newStatus): void {
    $wasInHand = is_in_hand($player['status']);
    $pdo->prepare("UPDATE players SET status=?, has_acted=1 WHERE id=?")->execute([$newStatus, $player['id']]);
    if ($room['status'] === 'playing' && $wasInHand) {
        progress_game($pdo, $room['id'], $player['seat']);
    }
}

// Host cancels the in-progress hand: refund every contribution and immediately deal a fresh hand.
function cancel_and_restart(PDO $pdo, int $roomId): void {
    $players = get_room_players($pdo, $roomId);
    $refundStmt = $pdo->prepare("UPDATE players SET chips = chips + contributed_total, bet_this_round=0, contributed_total=0, status='active' WHERE id=?");
    foreach ($players as $p) {
        if (is_in_hand($p['status'])) $refundStmt->execute([$p['id']]);
    }
    add_chat($pdo, $roomId, 'Dealer', 'Host cancelled the round. Dealing a new hand.', true);
    start_hand($pdo, $roomId);
}

// If we're sitting in the showdown phase past the display delay, deal the next hand.
// Elapsed time is computed inside MySQL (TIMESTAMPDIFF) so this can't be thrown off by
// the PHP process and the DB server having different configured timezones.
// The `advancing` flag is a cheap mutex so only one concurrent poll deals the next hand.
function maybe_advance_from_showdown(PDO $pdo, array $room): void {
    if ($room['phase'] !== 'showdown' || !$room['showdown_at']) return;
    $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, showdown_at, NOW()) FROM rooms WHERE id = ?");
    $stmt->execute([$room['id']]);
    $elapsed = (int)$stmt->fetchColumn();
    if ($elapsed < SHOWDOWN_DELAY_SECONDS) return;
    $stmt = $pdo->prepare("UPDATE rooms SET advancing = 1 WHERE id = ? AND phase = 'showdown' AND advancing = 0");
    $stmt->execute([$room['id']]);
    if ($stmt->rowCount() === 1) {
        start_hand($pdo, $room['id']);
        $pdo->prepare("UPDATE rooms SET advancing = 0 WHERE id = ?")->execute([$room['id']]);
    }
}
