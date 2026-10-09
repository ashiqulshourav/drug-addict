<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
admin_require_method('GET');
start_secure_session();
admin_success(['csrf_token' => admin_csrf_token()]);
