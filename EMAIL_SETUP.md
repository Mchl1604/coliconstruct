# Email Setup Guide

Everything in the system that sends email is already built and tested. Nothing
is sent yet because no mail server has been configured — until one is, messages
are written to `storage/logs/laravel.log` instead of being delivered, and the
interface honestly says so rather than promising an email nobody will receive.

This guide is the whole of what is left to do.

---

## 1. What you need before you start

Pick one email service and collect five things from it:

| You need | Goes in | Example |
| --- | --- | --- |
| SMTP host | `MAIL_HOST` | `smtp.gmail.com` |
| SMTP port | `MAIL_PORT` | `587` |
| Username | `MAIL_USERNAME` | `noreply@yourcompany.com` |
| Password / app password / API key | `MAIL_PASSWORD` | *(see below)* |
| Encryption | `MAIL_SCHEME` | leave `null` for port 587 |

Plus two you choose yourself:

| You choose | Goes in | Example |
| --- | --- | --- |
| The address emails appear to come from | `MAIL_FROM_ADDRESS` | `noreply@yourcompany.com` |
| The name beside it in the inbox | `MAIL_FROM_NAME` | `ColiConstruct` |

> **On `MAIL_SCHEME`.** This Laravel version expresses encryption as a scheme
> rather than an `MAIL_ENCRYPTION` value. Leave it `null` for port **587**
> (STARTTLS — the usual choice), and set it to `smtps` for port **465**
> (implicit TLS). There is no setting for "no encryption"; do not use one.

---

## 2. Choose a provider

### Gmail — quickest for a small deployment or a demo

Gmail **will not accept your ordinary account password**. You must create an
App Password, and that requires 2-Step Verification to be switched on first.

1. Go to <https://myaccount.google.com/security> and turn on **2-Step Verification**.
2. Go to <https://myaccount.google.com/apppasswords>.
3. Create an app password (name it "ColiConstruct"). Google shows a 16-character
   value once — copy it now.
4. Use that value as `MAIL_PASSWORD`, with the spaces removed.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=youraddress@gmail.com
MAIL_PASSWORD=abcdefghijklmnop
MAIL_SCHEME=null
MAIL_FROM_ADDRESS="youraddress@gmail.com"
MAIL_FROM_NAME="ColiConstruct"
```

Gmail caps sending at roughly 500 messages a day and rewrites the From address
to your own account. Fine for a demo; not a production mail service.

### Mailtrap — best while testing

Mailtrap catches every message in a web inbox instead of delivering it, so you
can exercise registration, resets and invitations without emailing real people.
Sign up at <https://mailtrap.io>, open your inbox, and copy the SMTP credentials
it shows.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_SCHEME=null
MAIL_FROM_ADDRESS="noreply@coliconstruct.test"
MAIL_FROM_NAME="ColiConstruct"
```

### Outlook / Microsoft 365

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=youraddress@yourcompany.com
MAIL_PASSWORD=your_password_or_app_password
MAIL_SCHEME=null
```

Microsoft 365 tenants increasingly require modern authentication; if SMTP AUTH
is disabled for your tenant an administrator must enable it for the mailbox.

### SendGrid, Brevo, Mailgun, Postmark — the right choice for production

These are built for transactional mail: proper deliverability, bounce handling
and a sending reputation that is not your office Gmail account. SendGrid as an
example — create an **API key** with Mail Send permission, then:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=SG.your_actual_api_key_here
MAIL_SCHEME=null
MAIL_FROM_ADDRESS="noreply@yourcompany.com"
MAIL_FROM_NAME="ColiConstruct"
```

The username is the literal word `apikey`. For any of these providers you must
also **verify your sending domain** (SPF and DKIM DNS records) or your mail will
land in spam.

---

## 3. Set the company branding

Every email carries the same header, logo and footer. These come from `.env`
too, so branding never requires a code change:

```dotenv
COMPANY_NAME="ColiConstruct"
COMPANY_TAGLINE="Project Management System"
COMPANY_LOGO="img/coliconstructlogor.png"
COMPANY_ADDRESS="2F Coli Building, 123 Sample Street, Quezon City"
COMPANY_PHONE="(02) 8123 4567"
COMPANY_EMAIL="support@coliconstruct.com"
COMPANY_WEBSITE="${APP_URL}"
COMPANY_COLOR_PRIMARY="#0d6efd"
COMPANY_COLOR_HEADER="#0b2545"
```

Two things to get right:

- **`APP_URL` must be correct and publicly reachable.** Every link and the logo
  in every email is built from it. If it still says `http://localhost`, links in
  emails will point at the recipient's own machine.
- **`COMPANY_LOGO`** is resolved against `APP_URL` unless you give a full
  `https://…` URL. It must be fetchable without signing in — inboxes cannot
  authenticate.

Anything left empty is simply omitted from the footer rather than printed blank.

---

## 4. Apply and test

```bash
php artisan config:clear
```

Send yourself a real message through the real templates:

```bash
php artisan mail:test you@example.com
```

The command prints the settings it is using, then either confirms the message
was accepted or shows the exact error. Check the inbox: you should get a
verification-code email with your logo, your company name and your contact
details in the footer.

---

## 5. Run the queue worker

Emails are queued so no user action ever waits on a mail server. `QUEUE_CONNECTION`
is already `database`, which means **a worker must be running or nothing is
delivered**.

In development, `composer run dev` starts one alongside the server. In
production, run it under a process supervisor so it restarts on failure:

```bash
php artisan queue:work --tries=3
```

Also keep the scheduler running — it drives the daily task reminders and sweeps
away lapsed verification codes:

```bash
php artisan schedule:work
```

> If you would rather not run a worker at all, set `QUEUE_CONNECTION=sync`.
> Emails then send during the request instead. It works, but every action that
> sends one gets slower by the round trip to the mail server.

---

## 6. Verify it end to end

Once mail is configured, these are the flows to try:

1. **Register** a new client account → a 6-digit code arrives → entering it
   activates the account and signs you in.
2. **Forgot password** → a code arrives → entering it opens the reset page.
3. **Configuration → User Management → create an employee** → they receive a
   welcome email with their role and temporary password.
4. **Reset a user's password** from the same page → they receive the new one.
5. **Deactivate then reactivate** an account → they are told each time.
6. **Profile → change your email address** → a code goes to the *new* address,
   and the old one is warned once the change completes.
7. **Create a project** with a client email → that client receives an invitation
   explaining they should register with that same address.

---

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| "Email is not configured on this system" on the Forgot Password page | `MAIL_MAILER` is still `log` or `array`. Set it to `smtp`. |
| `mail:test` succeeds but nothing else arrives | The queue worker is not running. Start `php artisan queue:work`. |
| `Failed to authenticate on SMTP server` | Wrong username/password, or an account password used where an **app password** is required (Gmail, Outlook). |
| `Connection could not be established` | Wrong host or port, or a firewall blocking outbound SMTP. Try port 587. |
| Emails arrive with a broken logo | `APP_URL` is wrong, or `COMPANY_LOGO` points somewhere the recipient cannot reach. |
| Links in emails point at `localhost` | `APP_URL` is wrong. |
| Everything lands in spam | Your sending domain has no SPF/DKIM records. Set them up with your provider. |
| Codes are rejected as expired straight away | The server clock is wrong. Codes live 10 minutes from `now()`. |

---

## What is configured where

| Setting | File | Notes |
| --- | --- | --- |
| SMTP credentials | `.env` | Never commit these. `.env` is git-ignored; `.env.example` documents them. |
| Mailer definitions | `config/mail.php` | Untouched Laravel defaults; no need to edit. |
| Company branding | `.env` → `config/company.php` | Name, logo, colours, footer contact details. |
| Code lifetime, attempts, cooldown | `app/Services/OtpService.php` | Constants at the top: 6 digits, 10 minutes, 5 attempts, 60-second resend. |
