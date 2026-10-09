<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
admin_require_method('GET');
$user = admin_current_user(false);
if (!$user) {
    admin_error('Authentication required.', 401);
}
admin_success([
    'user' => admin_public_user($user),
    'permissions' => $user['permissions'],
    'csrf_token' => admin_csrf_token(),
]);
