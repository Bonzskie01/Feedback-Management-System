# Feedback Management System

A secure PHP and PostgreSQL application for creating feedback campaigns, building questionnaires, inviting participants, collecting one-time responses, and reviewing aggregate insights.

## What changed

- Elections are now feedback campaigns with draft, active, paused, and closed states.
- Ballot positions are now rating, single-choice, multiple-choice, or written questions.
- Voters are now invited participants with one-time campaign credentials.
- Votes and live winners are now feedback submissions and aggregate response analytics.
- Existing administrator accounts, TOTP two-factor authentication, audit logging, settings, and security controls are retained.

The previous implementation remains in the repository as migration reference, but it is no longer linked from the live feedback interface.

## Requirements

- PHP 8.0 or newer with `pgsql`, `session`, `openssl`, `mbstring`, and `fileinfo`.
- PostgreSQL 12 or newer.
- Apache, XAMPP, or another PHP-capable web server.

## Setup

1. Create an empty PostgreSQL database. The database name does not include `.sql`.
2. Apply the complete schema:

   ```text
   psql -U feedback_app -d feedback_system -f database/feedback_schema.sql
   ```

3. Copy `.env.example` to `.env`, then set the database password and a 32-byte base64 `APP_KEY`. The application loads this project-level `.env` automatically. For production, you may instead set `FMS_ENV_FILE` to a protected environment file outside the web root.
4. Generate an application key with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` and paste the result after `APP_KEY=`.
5. Create the first administrator from the project directory:

   ```text
   php database/create_admin.php
   ```

6. Serve this directory through PHP and open `index.php`.

For an in-place upgrade, the old database and user names can remain in the environment file; no rename is required. The schema uses `CREATE TABLE IF NOT EXISTS` and preserves compatible existing records.

## Main workflow

1. Sign in with an existing administrator account.
2. Create a campaign in **Campaigns**.
3. Add and order questions in **Questions**.
4. Create participant credentials in **Participants** and save the generated password when shown.
5. Set the campaign to **Active**.
6. Participants sign in at `index.php`, complete the form once, and receive a submission reference.
7. Review participation, ratings, choice distributions, and written comments in **Responses**.

## Data model

The migration creates `feedback_campaigns`, `feedback_questions`, `feedback_participants`, `feedback_submissions`, and `feedback_answers`.

Participant identity is not displayed beside response content in analytics. Database access should still be restricted because direct database access can correlate a submission with its participant record.

## Security behavior

- Prepared PostgreSQL statements are used for new write paths.
- Management and submission forms use CSRF tokens.
- Participant passwords use PHP password hashing and are displayed only when generated or reset.
- Submission uses a database transaction and row lock to enforce one response per participant.
- Campaign status and time window are checked at login and submission.
- Answers are validated against the stored question type and allowed options.
- Existing rate limiting, admin TOTP, secure sessions, LAN restriction, and audit logging are retained.
