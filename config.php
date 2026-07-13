<?php
// If a config.local.php file exists (gitignored - see .gitignore), it defines the four
// DB_* constants below with real credentials that never end up committed to source control.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    // Database connection settings for XAMPP's default MySQL install.
    // Edit these if your local MySQL uses different credentials.
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'poker_game');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

// Game settings
define('STARTING_CHIPS', 1000);
define('SMALL_BLIND', 10);
define('BIG_BLIND', 20);
define('MIN_PLAYERS', 2);
define('MAX_PLAYERS', 8);
define('SHOWDOWN_DELAY_SECONDS', 8); // how long the table shows the showdown before auto-dealing next hand
