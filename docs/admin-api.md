# Madok Admin API

Base URL: `https://madok.devnotation.com/api/admin`

All endpoints return JSON with one response shape:

- Success: `{ "ok": true, "data": {} }`
- Error: `{ "ok": false, "message": "...", "errors": {} }`

PHP session cookies provide authentication. Browser requests must include credentials. `GET /auth/csrf.php` initializes the session and returns `data.csrf_token`; every authenticated `POST`, `PUT`, `PATCH`, and `DELETE` requires that value in `X-CSRF-Token`. A stale CSRF token returns HTTP 419. Unauthenticated requests return 401; insufficient permissions return 403.

## Authentication

- `GET /auth/csrf.php`: initialize session and obtain CSRF token.
- `POST /auth/login.php`: `{ "email": "...", "password": "..." }`; returns user, dynamic permissions, and a rotated CSRF token.
- `GET /auth/me.php`: current active user, permissions, and CSRF token.
- `POST /auth/logout.php`: audit and destroy the session.
- `GET /profile.php`: account details.
- `POST /profile.php`: `{ "action": "change_password", "current_password": "...", "new_password": "...", "confirm_password": "..." }`.

## Admin data

- `GET /dashboard.php`: database-derived summary and recent audit activity; requires `dashboard.view`.
- `GET /statistics.php`: report counts by type/day/division/district/upazila and locations by type; requires `statistics.view`.
- `GET /reports.php`: paginated report list. Query fields include `page`, `per_page` (maximum 100), `search`, `type`, `status=active|deleted|all`, `location_id`, `from`, `to`, `sort`, and `direction`.
- `GET /reports.php?id={id}`: report detail; access is permission-gated and audited.
- `PUT /reports.php?id={id}`: editable whitelist: `title`, `description`, `type`.
- `DELETE /reports.php?id={id}`: soft-delete; requires `reports.delete` and `reports.allow_delete=true`.
- `PATCH /reports.php?id={id}` with `{ "action": "restore" }`: restore an individually deleted report after its location is active; requires `reports.restore`.
- `PATCH /reports.php?id={id}` with `{ "action": "permanent_delete", "confirm": "DELETE" }`: irreversibly remove report and votes; requires `reports.permanent_delete` and `reports.allow_delete=true`.
- `GET /locations.php`: paginated location list with search, type, active/deleted state, district, upazila, police station, sorting, and page-size filters.
- `GET /locations.php?id={id}`: location detail; requires `locations.view` and is audited.
- `PUT /locations.php?id={id}`: editable whitelist: `title`, coordinates, `upazila_id`, `police_station_id`.
- `DELETE /locations.php?id={id}`: soft-delete; requires `locations.delete` and `locations.allow_delete=true`.
- `PATCH /locations.php?id={id}` with `{ "action": "restore" }`: restore a location and its still-active reports; requires `locations.restore`.
- `PATCH /locations.php?id={id}` with `{ "action": "permanent_delete", "confirm": "DELETE" }`: permanently delete only if no reports reference the location; requires `locations.permanent_delete` and the delete setting.
- `GET /users.php`: paginated users with `search`, `status`, and `role_id` filters; requires `users.view`.
- `GET /users.php?id={id}`: administrator detail.
- `GET /users.php?resource=roles`: only roles safe for the current actor to assign.
- `POST /users.php`: create `{ "name", "email", "password", "role_id" }`; requires `users.create`.
- `PUT /users.php?id={id}`: update `{ "name", "email", "role_id" }`; requires `users.edit`.
- `PATCH /users.php?id={id}` with `{ "action": "enable|disable" }`: toggle an account; requires `users.edit`, and disabling also requires `users.delete`.
- `PATCH /users.php?id={id}` with `{ "action": "change_password", "new_password": "..." }`: authorized password reset.
- `DELETE /users.php?id={id}`: permanently remove an already-disabled non-self account; requires `users.delete`, and audit entries remain with the actor reference.
- `GET /roles.php`: roles and grouped permissions; requires `roles.manage`.
- `POST /roles.php`: create role and requested permission assignment.
- `PUT /roles.php?id={id}`: update name, description, and permission assignment.
- `DELETE /roles.php?id={id}`: delete only non-system roles with no assigned administrators.
- `GET /settings.php`: database settings; requires `settings.view`.
- `PUT /settings.php`: `{ "key", "value", "type", "description" }`; requires `settings.manage` and validates string/boolean/integer/JSON types.
- `GET /audit.php`: paginated immutable audit stream with `search`, `action`, `entity`, `admin_user_id`, `from`, and `to` filters; requires `audit.view`.

## Permissions and defaults

Permissions are stored in `admin_permissions` and linked through `admin_role_permissions`; backend checks use permission keys, never role-name shortcuts. The schema seeds SUPER ADMIN with all permissions, MANAGER with operational review/delete/restore plus read-only settings and audit, and MODERATOR with view/edit only. Deletion is independently controlled by `reports.allow_delete` and `locations.allow_delete`.

## Delete lifecycle

Report and location soft deletion writes `deleted_at`, `deleted_by`, `delete_reason`, counters/audit in a transaction. Public APIs exclude records whose own row or parent location is deleted. Restore is audited and restores public visibility. Permanent report deletion cascades votes and removes only an image file with a generated safe filename whose resolved path remains inside `uploads/reports`. Permanent location deletion is blocked while any report references it.

## CORS and sessions

Production CORS allows only `https://adminmadok.devnotation.com`; set `ADMIN_CORS_ORIGINS` explicitly when configuring deployment. Development origins are enabled only when `APP_ENV` is not `production` and are restricted to `localhost`/`127.0.0.1` on the explicit Vite fallback ports 5173-5175 and 8888-8890. Credentialed requests use `Access-Control-Allow-Credentials: true`, an explicit origin, and `Vary: Origin`. Session cookies are HttpOnly, SameSite=Lax, and Secure in production. Never configure wildcard CORS with credentials.
