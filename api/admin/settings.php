<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
admin_require_method('GET', 'PUT', 'PATCH');
$pdo = db();

if ($method === 'GET') {
    admin_require_permission('settings.view');
    $items = $pdo->query('SELECT setting_key, setting_value, value_type, description, updated_at FROM admin_settings ORDER BY setting_key')->fetchAll();
    $items = array_map(static fn(array $row): array => [
        'key' => (string) $row['setting_key'],
        'value' => $row['setting_value'],
        'type' => (string) $row['value_type'],
        'description' => $row['description'],
        'updated_at' => (string) $row['updated_at'],
    ], $items);
    admin_success(['items' => $items]);
}

if ($method !== 'PUT' && $method !== 'PATCH') admin_error('Method not allowed.', 405);
$actor = admin_require_permission('settings.manage');
admin_require_csrf();
$body = admin_body();
$key = admin_text($body, 'key', 120, true);
$value = $body['value'] ?? null;
$type = (string) ($body['type'] ?? 'string');
if (!in_array($type, ['string', 'boolean', 'integer', 'json'], true)) admin_error('Validation failed.', 422, ['type' => 'Unsupported setting type.']);
if ($type === 'boolean') {
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) admin_error('Validation failed.', 422, ['value' => 'Enter a valid boolean value.']);
    $value = $parsed ? 'true' : 'false';
} elseif ($type === 'integer') {
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false) admin_error('Validation failed.', 422, ['value' => 'Enter a valid integer.']);
    $value = (string) $parsed;
} elseif ($type === 'json') {
    if (is_string($value)) {
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) admin_error('Validation failed.', 422, ['value' => 'Enter valid JSON.']);
    } else {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
} else {
    if (!is_scalar($value) && $value !== null) admin_error('Validation failed.', 422, ['value' => 'Enter a string value.']);
    $value = (string) ($value ?? '');
}
if ($key === 'deletion.soft_delete' && filter_var($value, FILTER_VALIDATE_BOOL) !== true) {
    admin_error('Soft deletion is required for report and location safety.', 409, ['value' => 'This setting must remain enabled.']);
}
$description = admin_text($body, 'description', 255);
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO admin_settings (setting_key, setting_value, value_type, description, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type),
                                 description = VALUES(description), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$key, $value, $type, $description !== '' ? $description : null, (int) $actor['id']]);
    admin_audit((int) $actor['id'], 'settings.update', 'setting', null, 'Updated setting ' . $key . '.');
    $pdo->commit();
    admin_success(['key' => $key, 'value' => $value, 'type' => $type]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Madok admin settings update failed: ' . $error->getMessage());
    admin_error('Unable to update setting.', 500);
}
