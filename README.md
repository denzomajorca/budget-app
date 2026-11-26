# Budget

A personal finance web app (accounts, transactions, budgets, reports, CSV and Excel import) for one user, built to run on KnownHost shared cPanel hosting at budget.dcadizstudio.com.

Stack: Laravel 12 (PHP 8.2+), MySQL, Inertia + Vue 3 + TypeScript, Tailwind.

## Run it locally

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build        # or `npm run dev` while working on the frontend
php artisan serve
```

Create your local account by setting `SETUP_TOKEN=anything` in `.env` and opening `http://localhost:8000/setup?token=anything`.

Tests: `php artisan test` (needs `npm run build` first).

## Deploy to KnownHost (shared cPanel, no SSH needed)

The server never needs Composer or Node. GitHub builds a ready-to-upload zip.

### First time

1. **Build the zip.** On GitHub, push a tag such as `v0.1.0` (or open Actions > release > Run workflow). Download `budget-app-v0.1.0.zip` from the Releases page (or from the workflow run's artifacts).
2. **PHP version.** cPanel > MultiPHP Manager: set budget.dcadizstudio.com to PHP 8.2 or newer.
3. **Database.** cPanel > MySQL Databases: create a database (e.g. `budget`), a user with a long random password, and add the user to the database with ALL PRIVILEGES. Note the full prefixed names, like `myuser_budget`.
4. **Upload.** cPanel > File Manager: create the folder `budget-app` in your home directory (next to `public_html`, not inside it). Upload the zip there and Extract it.
5. **Point the subdomain at the app.** cPanel > Domains (or Subdomains): create `budget.dcadizstudio.com` with document root `budget-app/public`. Only the `public` folder is reachable from the web; your `.env` and code are not.
6. **Configure.** In `budget-app`, copy `deploy/env.knownhost` to `.env` and fill in the database name, user and password, the mail settings, and a long random `SETUP_TOKEN`. For `APP_KEY`, paste `base64:` followed by 32 random bytes in base64 (e.g. the output of `openssl rand -base64 32`), or any password-manager-generated 44-character base64 string.
7. **HTTPS.** cPanel > SSL/TLS Status: run AutoSSL for the subdomain if it isn't already covered.
8. **Finish in the browser.** Open `https://budget.dcadizstudio.com/setup?token=YOUR_SETUP_TOKEN`, click **Update database**, then create your account. Registration is otherwise disabled; `/setup` refuses to make a second account.

### Updates

1. Build a new zip (new tag, e.g. `v0.2.0`).
2. Upload and extract it over `budget-app`. Your `.env` and `storage/` stay as they are, because the zip contains neither a `.env` nor your uploaded files.
3. If the release changes the database, open `/setup?token=...` and click **Update database**.

If SSH turns out to be available on the plan, `php artisan migrate --force` does the same as step 3.

### Backups

cPanel > Cron Jobs, daily:

```
mysqldump --defaults-extra-file=$HOME/.budget-db.cnf myuser_budget | gzip > $HOME/backups/budget-$(date +\%F).sql.gz
```

with `~/.budget-db.cnf` holding `[client]`, `user=` and `password=` lines (permissions 600). Download a copy off the server now and then.
