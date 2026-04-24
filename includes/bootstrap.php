<?php
/**
 * Gemeenschappelijke bootstrap voor alle pagina's, AJAX- en exportscripts.
 * Laadt config, DB, helpers en start de sessie.
 */

define('DKG_BOOT', true);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/authz.php';

session_boot();
