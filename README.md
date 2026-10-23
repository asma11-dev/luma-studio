# Luma Studio booking system

A mobile-first salon website and PHP/MySQL booking application. The code uses PHP 8.1+, PDO MySQL, the `mbstring` extension, MySQL 8/MariaDB, server-rendered pages and vanilla JavaScript. It needs no Node runtime or per-stylist subscription.

Open `preview.html` for a static visual preview with an interactive sample booking panel. It includes salon photography, a light/dark switch and motion effects. The preview uses example availability; the deployed PHP app uses live database availability. Replace the sample photos with Luma Studio’s own team and salon images before launch. Photo credits and source pages are listed on the gallery page (Unsplash and Pexels).

## Install

1. Create a MySQL database and import `database/schema.sql`.
2. Copy `config.example.php` to `config.php` and enter the database credentials, site URL, timezone and mail sender. Keep `config.php` outside public access (the supplied `.htaccess` denies it).
3. Point the hosting document root to `public/`. Enable HTTPS and PHP sessions.
4. Run `php scripts/create_owner.php` from the project directory to create Sarah's owner login. The command prompts for the password and stores only its password hash.
5. Sign in at `/?page=login`. In Admin, connect each stylist record to that stylist's login, assign services, and set working hours and exceptions.
6. Add this cron job to send reminders every 15 minutes: `php /path/to/luma-studio/scripts/send_reminders.php`.

The public site can be browsed without a database connection; booking, accounts and admin features require setup. Set a real `APP_URL` and `APP_TIMEZONE` before launch. `UTC` is the default until the salon's location is configured. The database stores appointment times in the configured salon-local timezone, so PHP's configured timezone must match.

## What is included

- Public home, services, stylist profiles, gallery and contact pages.
- Email/password registration, login, logout and expiring password reset links.
- Service → stylist or any available → date → live slot lookup → confirmation flow.
- Customer appointment list, rescheduling and cancellation.
- Owner and stylist roles; owner gets all appointments and management pages, stylist sees only their linked schedule.
- Day/week schedule, service and stylist editing, working hours, time off, manual blockouts, customer history and notes, and basic weekly/revenue/service reports.
- Email confirmations and a cron-driven 24-hour reminder sender.
- CSRF tokens, PDO prepared statements, escaped output, password hashing, reset-token hashing, a honeypot contact field and basic per-IP form throttling.

## Concurrent bookings

Booking writes run in a database transaction and acquire a MySQL advisory lock scoped to stylist and service date (`GET_LOCK`). The server then checks the schedule and overlapping confirmed appointments again before inserting. That serializes competing requests for the same stylist/day, including the empty-slot case, so two requests cannot both pass an overlap check and claim the same time. The client-side availability endpoint is only a convenience; the server always rechecks.

For a high-traffic deployment, keep MySQL and PHP on reliable hosting, use HTTPS, back up the database, and configure SPF/DKIM for the email sender. Payments, SMS, discount codes and translations can be added through the booking and notification services without changing the appointment model.

## Database outline

`users` stores customer and staff identities/roles; `stylists` maps a stylist profile to an optional staff login; `services` and `stylist_services` describe bookable work; `working_hours`, `time_off` and `blocked_times` define availability; `appointments` stores the customer, service, stylist, time and state; `customer_notes` stores staff-only notes; `password_resets` holds hashed, expiring tokens. See `database/schema.sql` for keys and indexes.

## Scope note

This is a usable first version, not a hosted service. Mail delivery through the host's PHP `mail()` function, database details, real salon copy/photos, the timezone, service prices and the four stylists' names and hours must be supplied during deployment. It can be iterated after launch; the booking and notification boundaries are kept small so Stripe deposits, SMS, loyalty and English/French content can be added later.
