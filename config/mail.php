<?php
/**
 * Mailconfiguratie — alle waarden uit .env met veilige defaults.
 *
 * Driver 'log' = mails naar logs/mail.log (dev).
 * Driver 'smtp' = echte verzending via PHPMailer.
 *
 * In productie worden deze straks via de Settings-UI overschreven (fase 2);
 * dit bestand levert de bootstrap-defaults voor vóór-install situaties.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

define('MAIL_DRIVER',    env('MAIL_DRIVER',    'log'));           // 'log' | 'smtp'
define('MAIL_FROM',      env('MAIL_FROM',      'noreply@localhost'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', APP_NAME));

define('SMTP_HOST',   env('SMTP_HOST',   ''));
define('SMTP_PORT',   (int)env('SMTP_PORT', '587'));
define('SMTP_USER',   env('SMTP_USER',   ''));
define('SMTP_PASS',   env('SMTP_PASS',   ''));
define('SMTP_SECURE', env('SMTP_SECURE', 'tls'));                 // 'tls' | 'ssl' | ''
