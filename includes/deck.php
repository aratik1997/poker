<?php
// Cards are 2-char strings: rank + suit, e.g. "AS" (Ace of Spades), "TD" (Ten of Diamonds).
const RANKS = ['2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K', 'A'];
const SUITS = ['S', 'H', 'D', 'C'];

function new_shuffled_deck(): array {
    $deck = [];
    foreach (SUITS as $s) {
        foreach (RANKS as $r) {
            $deck[] = $r . $s;
        }
    }
    for ($i = count($deck) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
    }
    return $deck;
}

function card_rank_value(string $card): int {
    $r = $card[0];
    return array_search($r, RANKS) + 2;
}

function card_suit(string $card): string {
    return $card[1];
}
