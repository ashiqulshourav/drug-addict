# Madok Admin Development

## Local setup

The frontend requires Node.js/npm and the sibling PHP/MySQL backend repository. Install frontend dependencies with `npm install`, set `VITE_API_BASE_URL` in `.env.dev` to the local backend `/api/admin` directory, then run `npm run dev`. The application uses hash-based Vue Router navigation.

The frontend contains no database credentials and does not connect to MySQL. Database access is exclusively through the PHP API.

## Backend setup

1. Configure the backend's private `config/database.php` and environment file outside public version control.
2. Apply `database.admin.sql` after the existing public `database.example.sql` schema has been installed.
3. Apply `database.admin.migration.sql` to add nullable soft-delete metadata/indexes to existing reports and locations. The migration preserves public records and is safe to rerun.
4. Configure `APP_ENV`, `APP_KEY`, and `ADMIN_CORS_ORIGINS=https://adminmadok.devnotation.com` in the backend environment. Use a random private application key.
5. Create the first SUPER ADMIN using `php tools/create_admin.php admin@example.com "Administrator name"`; enter the password only at the CLI prompt. The script writes a private one-time lock in `storage/`. Remove the setup script from the deployed server after setup.
6. Serve the backend over HTTPS. Ensure the session cookie is Secure, HttpOnly, and SameSite=Lax, and keep `config`, `storage`, database files, and setup tooling out of the public document root.

## Validation

From this frontend directory run `npm install`, `npm run typecheck`, and `npm run build`. From the backend directory run `php -l` on changed PHP files. Database integration tests require a non-production MySQL database configured with the real public schema; never run destructive checks against production data.

## Security

The backend owns authentication, CSRF enforcement, CORS, rate limits, permission checks, input validation, transactions, audit records, and report/location delete policy. UI permission checks only control visibility. Never put database passwords, admin passwords, or backend secrets in Vite environment variables.
