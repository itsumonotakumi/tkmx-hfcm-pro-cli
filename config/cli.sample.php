<?php

/**
 * TKMX HFCM Pro CLI - Local configuration sample.
 *
 * Copy this file to config/cli.local.php and set permissions:
 *   cp config/cli.sample.php config/cli.local.php
 *   chmod 0600 config/cli.local.php
 *
 * Bootstrap validates: file owned by process user AND mode exactly 0600.
 * Any mismatch causes exit 4 (FORBIDDEN).
 */

// ---------------------------------------------------------------------------
// Required: default WordPress user login for CLI execution.
// The user must have the manage_options capability.
// Can also be set via environment: HFCM_CLI_DEFAULT_USER=admin
// ---------------------------------------------------------------------------
define('HFCM_CLI_DEFAULT_USER', 'admin');

// ---------------------------------------------------------------------------
// Optional: allow --as=<user_login> impersonation.
// Set to 1 to enable. Can also be set via environment: HFCM_CLI_ALLOW_AS=1
// When enabled, every --as invocation is recorded in the audit log.
// ---------------------------------------------------------------------------
// define('HFCM_CLI_ALLOW_AS', '1');

// ---------------------------------------------------------------------------
// Optional: list of user logins permitted to be impersonated via --as.
// Leave empty or unset to allow any user (subject to their own WP capabilities).
// ---------------------------------------------------------------------------
// define('HFCM_CLI_ALLOWED_AS_USERS', ['admin', 'editor']);
