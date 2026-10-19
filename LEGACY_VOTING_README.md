# Legacy Voting Management System documentation

A web-based voting management system for school elections. It supports voter account generation, secure one-time voting, candidate and position management, live election results, custom credential templates, and admin security controls.

Built with PHP, PostgreSQL, JavaScript, and Node.js for DOCX generation.

## Main Features

### Voter Portal

- Unified login through `index.php` using either admin username or voter ID.
- Secure voter authentication with hashed passwords.
- Ongoing-election check before a voter can access the ballot.
- Ballot page with single-winner and multi-winner position support.
- Per-position abstain option.
- Election status polling while voting.
- Automatic lockout after vote submission.
- Thank-you page using the current background and favicon configured in Settings.

### Admin Portal

- Dashboard with election, voter, turnout, candidate, and activity summaries.
- Election management with draft, published, ongoing, paused, and closed states.
- Position management with display order and max-winner settings.
- Candidate management with photo upload, party list, approval, and disqualification support.
- Voter management with single/bulk generation, reset password, delete, and bulk delete.
- Voter list export to CSV and DOCX.
- Custom voter credential DOCX templates.
- Live results with All Results and Winners views.
- Focus View for live results in a new tab without sidebar or menu.
- Abstain counts shown in live results.
- Theme-aware admin sidebar and light/dark mode.
- Settings page for site name, school name, school address, logo, background image, and favicon.
- Admin TOTP two-factor authentication with backup codes.

## Requirements

- PHP 8.0 or newer recommended.
- PostgreSQL.
- Apache through XAMPP or another PHP-capable web server.
- PHP extensions:
  - `pgsql`
  - `fileinfo`
  - `zip` / `ZipArchive` for DOCX template editing
  - `session`
- Node.js and npm for DOCX generation fallback/export.
- Modern browser with JavaScript enabled.

## Installation

1. Place the project in your web root.

   Example:

   ```text
   C:\xampp\htdocs\Voting System FINAL
   ```

2. Create/import the PostgreSQL database.

   The database dump is located at:

   ```text
   database/db.sql
   ```

   Example command:

   ```bash
   psql -U postgres -d voting_system -f "database/db.sql"
   ```

3. Configure the database connection in:

   ```text
   config/database.php
   ```

   Example:

   ```php
   $host = 'localhost';
   $port = '5432';
   $dbname = 'voting_system';
   $user = 'postgres';
   $password = 'your_password';
   ```

4. Install Node.js dependencies from the project root:

   ```bash
   npm install
   ```

5. Make sure these folders are writable by the web server:

   ```text
   assets/images/
   assets/photos/
   templates/
   ```

6. Open the system in your browser:

   ```text
   http://localhost/Voting%20System%20FINAL/index.php
   ```

   If your folder name is different, adjust the URL.

## Login Flow

The system uses one login page:

```text
index.php
```

- Admins log in with their admin username and password.
- Voters log in with voter ID and password.
- If an admin has 2FA enabled, the system redirects to:

```text
verify_2fa.php
```

## Voter Credential Templates

Custom credential templates are stored at:

```text
templates/credentials_template.docx
```

Admins can upload a template from:

```text
admin/voters/upload_template.php
```

Supported placeholders:

```text
{{SCHOOL_NAME}}
{{ELECTION_TITLE}}
{{GENERATED_DATE}}
{{VOTER_ID}}
{{PASSWORD}}
{{FULL_NAME}}
{{GRADE_SECTION}}
```

Bulk placeholders:

```text
{{VOTER_LIST}}
{{TOTAL_VOTERS}}
```

If a template only contains header placeholders, the system automatically appends a credential table so voter data is still included in the downloaded DOCX.

Template diagnostics:

```text
admin/voters/test_template.php
```

## Live Results

Live results are available at:

```text
admin/results/live.php
```

Views:

- `All Results` is the default view.
- `Winners` shows the leading/winning candidates.

Focus View:

```text
admin/results/live.php?election_id=ID&view=all&focus=1
```

Focus View opens the live results without the admin sidebar, topbar, or filter toolbar. This is useful for presenting election results on a projector or separate screen.

## Abstain Tracking

The ballot allows voters to abstain from each position.

New abstain records are stored in:

```text
vote_abstentions
```

The table is created automatically if it does not exist. Abstain records do not store voter IDs, preserving ballot secrecy.

Live results show:

- Abstain count per position.
- Abstain percentage based on voters who submitted ballots.
- Total abstain marks across the selected election.

Note: old ballots submitted before abstain tracking was added can only be partially inferred. Single-winner positions can be estimated, but multi-winner positions cannot be reconstructed perfectly.

## Settings-Driven Assets

The Settings page controls:

- Site name
- School name
- School address
- Logo
- Background image
- Favicon/tab icon

These values are stored in the `settings` table and retrieved by:

```php
site_name()
school_name()
site_logo()
site_background()
site_favicon()
```

Pages such as login, ballot, and thank-you use the configured background and favicon from Settings.

## Project Structure

```text
Voting System FINAL/
|-- admin/
|   |-- candidates/
|   |-- elections/
|   |-- party_lists/
|   |-- positions/
|   |-- results/
|   |-- voters/
|   |-- dashboard.php
|   |-- profile.php
|   `-- settings.php
|-- api/
|-- assets/
|   |-- css/
|   |-- images/
|   `-- photos/
|-- config/
|   `-- database.php
|-- database/
|   `-- db.sql
|-- includes/
|   |-- functions.php
|   |-- session.php
|   `-- totp_helper.php
|-- templates/
|   `-- credentials_template.docx
|-- voter/
|   |-- ballot.php
|   |-- check_election_status.php
|   |-- submit_vote.php
|   `-- thank_you.php
|-- gen_docx.js
|-- index.php
|-- verify_2fa.php
|-- package.json
`-- README.md
```

## Security Notes

- Admin and voter passwords are stored using PHP password hashing.
- Database queries use PostgreSQL prepared statements in critical flows.
- Voters cannot vote again after submission.
- Ballot votes do not store voter IDs.
- Abstain records also do not store voter IDs.
- Admin 2FA uses TOTP authenticator codes and hashed backup codes.
- Deleting voters who have already voted requires admin password confirmation.

## Maintenance Notes

- If uploaded background or favicon changes do not appear immediately, clear browser cache or hard refresh.
- If DOCX template replacement fails, make sure PHP ZipArchive is enabled.
- If DOCX fallback export fails, run `npm install` and confirm Node.js is available from PHP.
- If election title is blank in credential templates, ensure the election has `status = 'ongoing'` and a non-empty `title`.
