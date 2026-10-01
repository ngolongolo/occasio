# Okesheni

A Laravel 12 invitation application with organiser accounts, event creation, XLSX/CSV guest imports, private RSVP links, queue-based email/SMS/WhatsApp submissions and response exports.

## Install

Requires PHP 8.2+ with mbstring, XML, DOM, GD, ZIP, SQLite or MySQL, and Composer 2. No Node build required.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

In a second terminal:

```bash
php artisan queue:work --tries=1 --timeout=60
```

Visit http://localhost:8000 and register. For an optional demonstration event, set DEMO_EMAIL and DEMO_PASSWORD in .env then run `php artisan db:seed` outside production. No default passwords are supplied.

## Guest import

Download the XLSX template from the dashboard or use samples/guest-import.xlsx. Required headers: name, email, phone, max_guests. Supply email or phone, or both. Format phone cells as text and include +countrycode. Maximum 10 seats per invitation, 5,000 rows and 5 MB. Row errors are reported; other valid rows import. Duplicate email or phone in the event is skipped, preserving original RSVP links.

## Sending

DELIVERY_MODE=preview is the default: jobs become previewed and no external message is sent. Open the guest RSVP link to inspect invitations. Set APP_URL to the real public HTTPS address before sending.

For email: set DELIVERY_MODE=live, MAIL_MAILER=smtp and SMTP credentials, port, sender and MAIL_SCHEME as required by the provider.

SMS and WhatsApp use Twilio. Set account SID/token and authorised senders in .env. WhatsApp additionally requires an approved ContentSid template whose variables are 1 guest name, 2 event title, 3 event date/time, 4 full RSVP URL. Configure the template body/URL button to match those variables. The application sends via the Message resource. Twilio charges apply. Confirm recipients have opted in and sender registration is supported in your destination countries.

Statuses: queued → processing → submitted, previewed or failed. Submitted records provider acceptance only. Delivery receipts/webhooks are not implemented; use the provider console for actual delivery outcomes. Existing submitted records are not resent; failed and previewed rows can be queued again. There are no automatic retries to avoid duplicate paid messages. A worker crash can leave processing status; investigate provider logs before manually resetting it. Queue worker timeout is shorter than retry_after.

## RSVP

Random 64-character bearer links; no invitee account needed. Responses can change until the end of the RSVP deadline in Africa/Dar_es_Salaam time. Seat limits enforced server-side. Declines reset attending count to zero. Organisers can access only their own events. Registration/login and RSVP endpoints are rate limited; forms use CSRF protection and Blade escaping.

## Production

Use HTTPS, APP_ENV=production, APP_DEBUG=false and SESSION_SECURE_COOKIE=true. Configure a real database, SMTP and messaging credentials. Point web root at public/, never the project root. Keep queue:work under Supervisor/systemd. Run `php artisan config:cache` and `php artisan view:cache`. Back up the database and protect guest data. Secrets belong only in .env. Disable public account registration at route level if this is an invite-only service.

## Design assets

The Okesheni logo variants and favicon are stored in `public/assets/logo`. Public landing assets are product marketing materials; client-specific source files in branding are not presented as public endorsements.

## Scope

Simple MVP: no billing, event editing/deletion, password reset, email verification, check-in scanning, scheduled reminders, custom invitation editor, inbound messaging, or provider receipt webhooks. These are extension points. The app is designed for deployment by your developer; delivery credentials are not included.

## Verification in this workspace

Source and print card reviewed. Automated PHP tests included but not executed: PHP/Composer could not be installed in this restricted environment. Browser rendering also could not run because Chromium download was blocked. Run `composer install`, `php artisan migrate` and `vendor/bin/phpunit` before deployment, then verify registration, XLSX import, RSVP and each configured delivery channel in staging. No live messages were sent.
