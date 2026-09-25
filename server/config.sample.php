<?php
/*
 * Pashabakess — server configuration.
 *
 * Copy this file to config.php (same folder) and fill it in.
 * config.php is private: it is never committed to git and never web-accessible.
 */
return [
    // Full public address of the website, no trailing slash.
    // Used for links in emails (e.g. the "Open in admin" button).
    'site_url' => 'https://pashabakess.alwaysdata.net',

    // Public website folder. Leave empty: it is found automatically
    // (dist/ next to server/ on alwaysdata, public_html/ next to server/ on Hostinger).
    'public_dir' => '',

    // One-time key needed to create the admin login at /admin/setup.php.
    // Change it to any long random text before going live.
    'setup_key' => 'change-this-to-a-long-random-phrase',

    // Database.
    //  - 'sqlite' needs no setup (one file in server/data/). Fine for a small bakery.
    //  - 'mysql' uses an alwaysdata MariaDB database (recommended for production).
    'db' => [
        'driver'   => 'sqlite',
        'sqlite'   => __DIR__ . '/data/pashabakess.sqlite',
        'host'     => 'mysql-ACCOUNT.alwaysdata.net',
        'name'     => 'ACCOUNT_pashabakess',
        'user'     => 'ACCOUNT',
        'password' => '',
    ],

    // Outgoing email.
    //  - 'smtp' : recommended. Use an alwaysdata mailbox (or Gmail with an app password).
    //  - 'mail' : PHP's built-in mail() — no password, but more likely to land in spam.
    //  - 'log'  : development only — emails are saved as files in server/data/mail/.
    'mail' => [
        'transport'   => 'smtp',
        'from_email'  => 'orders@pashabakess.alwaysdata.net',
        'from_name'   => 'Pashabakess',
        'reply_to'    => 'pashabakess@gmail.com',
        'smtp_host'   => 'smtp-ACCOUNT.alwaysdata.net',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',   // 'tls' for port 587, 'ssl' for port 465
        'smtp_user'   => 'orders@pashabakess.alwaysdata.net',
        'smtp_pass'   => '',

        // Pasha's Gmail app password (16 letters). Emails are sent through her Gmail when this
        // (or the one saved in Admin → Settings) is set. Keeping it here — or in the
        // PB_GMAIL_APP_PASSWORD environment variable — keeps it out of database copies and backups.
        'gmail_app_password' => '',
    ],
];
