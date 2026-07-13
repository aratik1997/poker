<?php
require_once __DIR__ . '/deck.php';

const HAND_NAMES = [
    8 => 'Straight Flush',
    7 => 'Four of a Kind',
    6 => 'Full House',
    5 => 'Flush',
    4 => 'Straight',
    3 => 'Three of a Kind',
    2 => 'Two Pair',
    1 => 'One Pair',
    0 => 'High Card',
];

function k_combinations(array $items, int $k): array {
    $results = [];
    $n = count($items);
    if ($k > $n) return $results;
    $indices = range(0, $k - 1);
    while (true) {
        $results[] = array_map(fn($i) => $items[$i], $indices);
        $i = $k - 1;
        while ($i >= 0 && $indices[$i] === $i + $n - $k) $i--;
        if ($i < 0) break;
        $indices[$i]++;
        for ($j = $i + 1; $j < $k; $j++) $indices[$j] = $indices[$j - 1] + 1;
    }
    return $results;
}

// Evaluates exactly 5 cards. Returns ['category'=>int 0-8, 'tiebreak'=>int[]]
function evaluate5(array $cards): array {
    $ranks = array_map('card_rank_value', $cards);
    $suits = array_map('card_suit', $cards);
    rsort($ranks);

    $counts = [];
    foreach ($ranks as $r) $counts[$r] = ($counts[$r] ?? 0) + 1;
    // groups sorted by [count desc, rank desc]
    $groups = [];
    foreach ($counts as $rank => $count) $groups[] = [$count, $rank];
    usort($groups, fn($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);

    $isFlush = count(array_unique($suits)) === 1;

    $distinct = array_values(array_unique($ranks));
    rsort($distinct);
    $isStraight = false;
    $straightHigh = null;
    if (count($distinct) === 5) {
        if ($distinct[0] - $distinct[4] === 4) {
            $isStraight = true;
            $straightHigh = $distinct[0];
        } elseif ($distinct === [14, 5, 4, 3, 2]) { // wheel: A-2-3-4-5
            $isStraight = true;
            $straightHigh = 5;
        }
    }

    if ($isStraight && $isFlush) {
        return ['category' => 8, 'tiebreak' => [$straightHigh]];
    }
    if ($groups[0][0] === 4) {
        $kicker = $groups[1][1];
        return ['category' => 7, 'tiebreak' => [$groups[0][1], $kicker]];
    }
    if ($groups[0][0] === 3 && $groups[1][0] >= 2) {
        return ['category' => 6, 'tiebreak' => [$groups[0][1], $groups[1][1]]];
    }
    if ($isFlush) {
        return ['category' => 5, 'tiebreak' => $ranks];
    }
    if ($isStraight) {
        return ['category' => 4, 'tiebreak' => [$straightHigh]];
    }
    if ($groups[0][0] === 3) {
        $kickers = array_slice(array_column($groups, 1), 1, 2);
        return ['category' => 3, 'tiebreak' => array_merge([$groups[0][1]], $kickers)];
    }
    if ($groups[0][0] === 2 && $groups[1][0] === 2) {
        $pairHigh = max($groups[0][1], $groups[1][1]);
        $pairLow = min($groups[0][1], $groups[1][1]);
        $kicker = $groups[2][1];
        return ['category' => 2, 'tiebreak' => [$pairHigh, $pairLow, $kicker]];
    }
    if ($groups[0][0] === 2) {
        $kickers = array_slice(array_column($groups, 1), 1);
        return ['category' => 1, 'tiebreak' => array_merge([$groups[0][1]], $kickers)];
    }
    return ['category' => 0, 'tiebreak' => $ranks];
}

function compare_evaluations(array $a, array $b): int {
    if ($a['category'] !== $b['category']) return $a['category'] <=> $b['category'];
    $len = max(count($a['tiebreak']), count($b['tiebreak']));
    for ($i = 0; $i < $len; $i++) {
        $av = $a['tiebreak'][$i] ?? 0;
        $bv = $b['tiebreak'][$i] ?? 0;
        if ($av !== $bv) return $av <=> $bv;
    }
    return 0;
}

// Evaluates the best 5-card hand out of 5-7 cards.
function evaluate_best_hand(array $cards): array {
    $best = null;
    $bestCombo = null;
    foreach (k_combinations($cards, 5) as $combo) {
        $eval = evaluate5($combo);
        if ($best === null || compare_evaluations($eval, $best) > 0) {
            $best = $eval;
            $bestCombo = $combo;
        }
    }
    $best['name'] = HAND_NAMES[$best['category']];
    $best['best5'] = $bestCombo;
    return $best;
}
