# Fasmir Motors deployment

## Domain without `/public`

The preferred and most secure setup is to point the domain's document root to this project's `public` directory:

```text
/home/account/fasmir_motors/public
```

Set the production URL in `.env` without a trailing `/public` segment:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
```

If shared hosting only permits the domain to point at the project root, keep the root `.htaccess` included with this project. It internally serves requests from `public` and redirects old `/public/...` URLs to clean URLs.

After uploading, run:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
npm ci && npm run build
```

Ensure `storage` and `bootstrap/cache` are writable by the web server. Do not upload the local `.env`; create a production `.env` on the server.
