<br>
<p align="center">
  <a href="https://paymenter.org">
    <picture>
      <source media="(max-width: 768px)" srcset="https://paymenter.org/iso.svg" width="65px">
      <source media="(prefers-color-scheme: dark)" srcset="https://paymenter.org/iso.svg" width="80px">
      <source media="(prefers-color-scheme: light)" srcset="https://paymenter.org/iso.svg" width="80px">
      <img alt="Paymenter Isotype" src="https://paymenter.org/iso.svg">
    </picture>
  </a>
</p>
<h1 align="center">
  Paymenter (Shared Hosting Fork)
</h1>

<div align="center">
  <h3>Open-Source Billing, Built for Hosting</h3>
  <p>A fork of <a href="https://github.com/paymenter/paymenter">paymenter/paymenter</a> optimized for shared hosting environments — no SSH, no Composer, just FTP.</p>
</div>

<h4 align="center">
  <a href="https://paymenter.org">Upstream Website</a> ·
  <a href="https://paymenter.org/docs/installation/install">Upstream Documentation</a> ·
  <a href="https://github.com/hldh214/Paymenter">This Fork</a>
</h4>

 <div align="center">
   
  [![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://github.com/hldh214/Paymenter/blob/master/LICENSE)
  [![GitHub release (latest by date)](https://img.shields.io/github/v/release/hldh214/Paymenter)](https://github.com/hldh214/Paymenter/releases)

</div>

## ⚠️ Important: Shared Hosting Notice

This fork is specifically designed for **shared hosting** environments where:

- **No SSH access** is available
- **No Composer** can be run on the server
- **Only FTP** is available for file management

All dependencies (`vendor/` directory) are included in the release archive so you can simply upload files via FTP and get started.

## Getting Started

### Requirements

- PHP 8.3 or higher (provided by your shared hosting)
- MySQL / MariaDB database (provided by your shared hosting)
- FTP access to your hosting account

### Installation (Shared Hosting via FTP)

1. Download the latest release archive from the [Releases](https://github.com/hldh214/Paymenter/releases) page.
2. Extract the archive on your local machine.
3. Copy `.env.example` to `.env` and edit it with your database credentials and other settings.
4. Upload all files to your web hosting via FTP (set the document root to the `public/` directory).
5. Visit your site in a browser to complete the setup.
6. Set up a cron job (see [Cron Job Setup](#cron-job-setup) below).

### Cron Job Setup

Paymenter requires a scheduled task to run periodic jobs such as sending invoices, suspending expired services, and importing ticket emails.

A `cron.sh` script is included in the project root. In your hosting control panel (e.g. cPanel → "Cron Jobs"), add the following cron entry to run **every minute**:

```
* * * * * /path/to/your/paymenter/cron.sh >> /dev/null 2>&1
```

> **Note:** Replace `/path/to/your/paymenter` with the actual path to your Paymenter installation directory. Make sure the script is executable (`chmod +x cron.sh`). If your hosting provider doesn't support running shell scripts, you can use the PHP command directly instead:
>
> ```
> * * * * * php /path/to/your/paymenter/artisan schedule:run >> /dev/null 2>&1
> ```
>
> You may need to use the full path to PHP (e.g. `/usr/bin/php` or `/usr/local/bin/php`), which varies by hosting provider.

If your hosting panel only allows a minimum interval of 5 minutes, use:

```
*/5 * * * * /path/to/your/paymenter/cron.sh >> /dev/null 2>&1
```

This is sufficient for most use cases — only the heartbeat check (every minute) will run less frequently.

### Updating

1. Download the latest release archive.
2. Extract and upload the new files via FTP, overwriting existing files.
3. Visit `/admin` to check if any database migrations need to be run, or access the migration URL if provided.

## What is Paymenter?

Paymenter is an open-source billing platform tailored for hosting companies. It simplifies the management of hosting services, providing a seamless experience for both providers and customers.

### Key Features:
- User-Friendly Interface: Paymenter is designed with simplicity in mind, ensuring an intuitive experience for users of all technical levels.
- Open Source and Extensible: As an open-source platform, Paymenter encourages community contributions and customization.
- Efficient Management: Streamline your operations with Paymenter's powerful admin panel.
- Secure and Reliable: Built with security as a priority, Paymenter ensures the protection of your data and transactions.

### Changes in This Fork

- **No Redis dependency** — uses database for cache and sessions, sync queue processing (no background worker needed).
- **No Docker** — Docker files and related CI workflows have been removed.
- **No SSH required** — removed `update.sh` and other SSH-dependent tooling.
- **Shared hosting friendly** — designed to work with FTP-only deployment and cPanel cron jobs.
- **Bug fix: extension settings save** — `CreateServer` and `CreateGateway` now correctly persist `type`, `encrypted`, and JSON-encoded values for array/tags/multi-select config fields (upstream bug).

### Custom Extensions

This fork ships with the following custom extensions:

#### DueDate (`extensions/Others/DueDate`)

A "quarterly-to-monthly alignment" extension for pro-rata billing. When a user purchases a quarterly plan for a product whose category name contains a configurable keyword (default: "Pro-rata"), this extension:

1. Hides monthly plans from the storefront (they are for internal renewal only).
2. Adjusts checkout pricing to a pro-rata amount so the first period ends at the end of the month.
3. On service creation, aligns `expires_at` to end-of-month and switches the service to a monthly plan for subsequent renewals.
4. Persists the aligned expiration date and restores it after payment processing to prevent the renewal system from overwriting it.

**Config:** `dd_category_keyword` — the keyword to match in the product category name.

#### ManualFulfillment (`extensions/Servers/ManualFulfillment`)

A server extension for manual provisioning. Designed for scenarios where the admin manually purchases a resource after user payment, then fills in credential fields (e.g. IP, Username, Password) via the admin panel.

1. Admin defines arbitrary credential field names in the extension config using a tag input.
2. On service creation (after payment), empty property placeholders are created for each field.
3. Admin fills in the actual values via Admin > Services > [service] > Properties tab.
4. The user sees all filled-in credentials on their service detail page.

**Config:** `mf_credential_fields` — tag input defining the field names; `mf_auto_activate` — whether to auto-activate on creation.

## License

Licensed under the [MIT License](https://github.com/hldh214/Paymenter/blob/master/LICENSE).
