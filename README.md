# ♠ Poker Night — Multiplayer Texas Hold'em

A full multiplayer Texas Hold'em poker game that runs entirely on a classic PHP + MySQL stack (built for XAMPP), with a vanilla JS/HTML/CSS frontend — no build step, no Node, no frameworks. Create a room, share the 5-letter code, and play with 2–8 friends right in the browser.

## Features

- **2–8 player rooms** — anyone can create a room (becoming host) and share a room code for others to join.
- **Full Texas Hold'em engine** — blinds, hole cards, flop/turn/river, side pots for all-ins, and a standard 5-card hand evaluator (high card through straight flush).
- **Host controls** — the host can kick any player from the lobby *or* mid-game. The kicked player immediately sees "*{Host}* kicked you"; everyone else sees a system chat message: "*{Host}* kicked *{Player}*".
- **New Round button** — the host can cancel the hand in progress and instantly deal a fresh one; all chips wagered that hand are refunded first.
- **Leave anytime** — a Leave button is available in both the lobby and at the table. Leaving mid-hand auto-folds you and you're skipped from the next deal onward.
- **Mid-game join = spectate** — joining while a hand is in progress puts you in spectator mode until the current hand finishes; spectators are excluded from the scoreboard.
- **Reshuffled seating** — turn order is randomly reshuffled before every new hand.
- **Live table chat** — chat with the table from the lobby or the game screen.
- **Scoreboard** — players ranked by chip count, highest (winner) on top.
- **Sound effects** — synthesized (no external audio files) sounds for dealing/drawing cards, playing community cards, and revealing hands at showdown.
- **In-app Game Rules** — a "Game Rules" panel explains hand rankings and how a hand plays out, based on standard published Texas Hold'em rules.
- **Custom SVG logo** — used both as the in-app branding and the browser favicon.

## Tech stack

- **Backend:** PHP (PDO/MySQL), no frameworks.
- **Realtime sync:** short-interval AJAX polling (~1.2s) against a JSON API — chosen so the whole app runs on a stock XAMPP install with nothing extra to install or run.
- **Frontend:** vanilla HTML/CSS/JS, Web Audio API for sound.
- **Database:** MySQL/MariaDB, auto-created and auto-migrated on first request (see below) — a `schema.sql` is also included for manual/reference use.

## Getting started (XAMPP)

1. Drop this folder into `htdocs` (e.g. `C:\xampp\htdocs\poker`) — if you're reading this from there, you're already set.
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Open `http://localhost/poker/` in your browser.

That's it — `includes/db.php` automatically creates the `poker_game` database and its tables the first time the app runs. If your MySQL uses non-default credentials, edit `config.php` first.

To play with others on your network, share `http://<your-lan-ip>/poker/` instead of `localhost`.

## How to play

1. One player clicks **Create Room** and shares the room code shown in the lobby.
2. Everyone else clicks **Join Room** with that code.
3. The host clicks **Start Game** once 2–8 players are in.
4. Play through blinds → hole cards → flop → turn → river → showdown, same as standard No-Limit Hold'em. Click **Game Rules** in-app any time for a refresher on hand rankings and betting actions.

## Project structure

```
poker/
├─ index.html              # single-page app shell (home / lobby / table screens)
├─ config.php               # DB connection + game settings (blinds, starting chips, etc.)
├─ schema.sql                # reference schema (auto-applied by includes/db.php)
├─ includes/
│  ├─ db.php                 # PDO connection + automatic schema creation
│  ├─ helpers.php            # request/response helpers, auth-by-token
│  ├─ deck.php                # card/deck primitives
│  ├─ hand_eval.php           # 5-card & best-of-7 hand evaluator
│  └─ game_engine.php          # the Hold'em state machine (dealing, betting, showdown, side pots)
├─ api/                     # JSON endpoints called by the frontend
│  ├─ create_room.php / join_room.php / leave_room.php / kick_player.php
│  ├─ start_game.php / player_action.php / new_round.php
│  ├─ get_state.php           # polling endpoint (game state + chat + scoreboard)
│  └─ send_chat.php
└─ assets/
   ├─ css/style.css
   ├─ js/app.js               # UI rendering + polling loop
   ├─ js/sound.js              # synthesized sound effects (Web Audio API)
   └─ img/logo.svg             # logo, reused as the favicon
```

## Known limitations

- Real-time updates are via polling (~1.2s), not WebSockets — there's a small delay rather than instant push. This keeps the whole stack to plain PHP/MySQL so it runs on stock XAMPP.
- No reconnect-after-host-disconnect timeout: if a host closes their tab without clicking Leave, host duties only transfer when they explicitly leave.
- No persistent accounts — a player's session is a random token stored in the browser's `localStorage`, tied to one room at a time.

## License

No license file is included yet — add one (MIT, etc.) before treating this as open source for others to reuse.
