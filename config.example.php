<?php
// FrogFind settings. Copy this file to config.php and change the secret.
// config.php is in .gitignore, so the secret never ends up on GitHub.

// A long random string used to sign FrogFind's links. Make one with:
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Changing it expires every link handed out so far (people just see the
// address box and can press Go! again).
define('FF_SECRET', 'change-me-to-a-long-random-string');

// Where cached pages and lock files go. Best outside public_html, for example
// a folder next to it. The web server needs to be able to write here.
define('FF_CACHE_DIR', sys_get_temp_dir() . '/frogfind-cache');

// Optional: anything else in section 1 of lib/frogfind.php can be changed here
// too, for example how many outside fetches may run at once:
// define('FF_SLOTS', 8);
