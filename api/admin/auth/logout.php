<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
admin_require_method('POST');
$user = admin_current_user();
admin_require_csrf();
admin_audit((int) $user['id'], 'auth.logout', 'admin_user', (int) $user['id'], 'Administrator signed out.');
admin_clear_session();
admin_success(['logged_out' => true]);
