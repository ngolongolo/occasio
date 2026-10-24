# Okesheni

A Laravel 12 invitation application with organiser accounts, event creation, XLSX/CSV guest imports, private RSVP links, queue-based email/SMS/WhatsApp submissions and response exports.

Each event can accept participation responses, pledges, or both. Pledge-enabled events show administrator-configured payment instructions on each guest's private response page. Guest-reported payments remain unverified until an administrator books received funds.

After an event starts, organisers can record actual attendance separately from RSVP, individually or in bulk. Pledge payments use an audited installment ledger: an organiser records the amount, date, optional method/reference and note; the system calculates paid and outstanding totals and marks the pledge partially paid, paid or overpaid. Duplicate event references and accidental overpayments are guarded, while corrections are voided with a reason instead of deleted. Each installment can generate its own configured email/SMS receipt, and fully paid pledges can trigger a separate thank-you. Notification delivery history prevents accidental duplicates. Fully paid pledges are locked against guest edits. This is a manual payment-verification workflow; direct payment-gateway checkout/webhooks require a provider-specific integration.

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

SMS uses Sprint SMS Service. Set `SPRINT_SMS_API_ID`, `SPRINT_SMS_API_PASSWORD`, and the approved `SPRINT_SMS_SENDER_ID` in `.env`. WhatsApp uses Twilio and requires an account SID/token, authorised sender, and approved ContentSid template whose variables are 1 guest name, 2 event title, 3 event date/time, 4 full RSVP URL. Configure the template body/URL button to match those variables. Provider charges apply. Confirm recipients have opted in and sender registration is supported in your destination countries.

Statuses: queued → processing → submitted, previewed or failed. Submitted records provider acceptance only. Delivery receipts/webhooks are not implemented; use the provider console for actual delivery outcomes. Existing submitted records are not resent; failed and previewed rows can be queued again. There are no automatic retries to avoid duplicate paid messages. A worker crash can leave processing status; investigate provider logs before manually resetting it. Queue worker timeout is shorter than retry_after.

## RSVP

Random 64-character bearer links; no invitee account needed. The RSVP date is a requested response date; organisers may continue sending invitations and guests may respond until the event starts in Africa/Dar_es_Salaam time. Seat limits are enforced server-side. Declines reset attending count to zero. Organisers can access only their own events. Registration/login and RSVP endpoints are rate limited; forms use CSRF protection and Blade escaping.

## Venue map search

Venue text remains the event's required location; attaching coordinates is optional. Organisers can run an explicit map search or place a pin, choose a suggested result, or keep their typed venue when no result matches. The app does not run keystroke autocomplete.

Search uses the configurable Nominatim endpoint in `NOMINATIM_SEARCH_URL`, sends an identifying `NOMINATIM_USER_AGENT` and optional `NOMINATIM_CONTACT_EMAIL`, caches normalised searches, and enforces both per-user and app-wide rate limits. `NOMINATIM_COUNTRY_CODES=tz` favours Tanzania; set it to an empty value for worldwide search. For production volume, configure a managed or self-hosted compatible endpoint and follow that provider's terms. `MAP_TILE_URL` makes the Leaflet tile source switchable; the default OpenStreetMap services require visible attribution and compliance with their usage policies.

## Snippe payments

Set `SNIPPE_KEY` and `SNIPPE_WEBHOOK_SECRET` in `.env`. Configure `SNIPPE_WEBHOOK_BASE_URL` as either the public HTTPS origin or the complete `https://your-domain.example/api/snippe/webhook` URL. Payment and payout requests use `POST /api/snippe/webhook` for Snippe API version `2026-01-25`; the endpoint verifies the raw-body HMAC signature, five-minute timestamp window, event header, references, currency and amount before changing local records. Local HTTP development omits the webhook URL and uses payment-status polling instead.

## Production

Use HTTPS, APP_ENV=production, APP_DEBUG=false and SESSION_SECURE_COOKIE=true. Configure a real database, SMTP and messaging credentials. Point web root at public/, never the project root. Keep queue:work under Supervisor/systemd. Run `php artisan config:cache` and `php artisan view:cache`. Back up the database and protect guest data. Secrets belong only in .env. Disable public account registration at route level if this is an invite-only service.

## Design assets

The Okesheni logo variants and favicon are stored in `public/assets/logo`. Public landing assets are product marketing materials; client-specific source files in branding are not presented as public endorsements.

## Scope

Simple MVP: no billing, event editing/deletion, password reset, email verification, check-in scanning, scheduled reminders, custom invitation editor, inbound messaging, or provider receipt webhooks. These are extension points. The app is designed for deployment by your developer; delivery credentials are not included.

## Verification in this workspace

Run `php artisan migrate` and `vendor/bin/phpunit` before deployment, then verify registration, XLSX import, RSVP and each configured delivery channel in staging. Automated tests use preview delivery mode, so no live messages are sent.
