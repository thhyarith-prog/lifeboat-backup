<p align="center"><img src="assets/img/icon-lifeboat.png" width="96" height="96" alt="Lifeboat Backup icon"></p>

<h1 align="center">Lifeboat Backup</h1>

<p align="center">Automatic WordPress backups to Google Drive, OneDrive, Dropbox, Box and MEGA, with schedules, backups after updates, password encryption, and one-click restore.</p>

<p align="center">Version 1.0.5 · WordPress 6.0+ · PHP 7.4+ · GPL-2.0-or-later · by <a href="https://www.prasatdigital.com">Prasat Digital</a></p>

---

**Where backups go**
* Google Drive, OneDrive (personal and business), Dropbox, Box and MEGA, plus this server. Send each backup to several places at once.
* Uploads go in resumable chunks. A 5 GB backup survives timeouts and network hiccups, with automatic retries and back-off.
* MEGA backups are encrypted on your server (AES-128-CTR with MEGA's integrity MAC) before upload, exactly like MEGA's own apps. Lifeboat signs in once, keeps only an encrypted session, and never stores your MEGA password. MEGA two-factor codes work.
* Cloud tokens and secrets are encrypted at rest with AES-256-GCM.

**Encryption**
* Turn on encryption with a password, and every backup is encrypted with AES-256-GCM on your server before it is uploaded. Your cloud provider, or anyone who gets into your cloud account, only ever sees encrypted files.
* The password is never stored. Lifeboat keeps only a key derived from it (PBKDF2-SHA256, 600,000 rounds), itself encrypted with your site's security keys, so scheduled backups run unattended.
* Every 1 MB block is authenticated, so a damaged, altered or cut-short file is detected before anything is restored.
* Restoring on the same site needs no password. Restoring on a new site, or a backup made with an older password, asks for it.
* `tools/lifeboat-decrypt.php` decrypts a backup file on any computer with PHP, even without WordPress.

**When backups run**
* Full backups daily, weekly or monthly at the hour you choose, in your site's timezone.
* Optional extra database-only backups, twice a day, daily or weekly.
* **Automatic backup after updates.** It starts five minutes after core, plugin or theme updates finish, whether manual or automatic, so a bulk update of ten plugins makes one backup, not ten.
* Retention: keep N backups on the server and N in each cloud. Full and database-only backups are counted separately, so frequent database backups never push out your full ones.

**Smart background processing**
* Backups never run inside a visitor's page load. Work happens in short background slices chained by loopback requests, with WP-Cron as a safety net. If a server restarts mid-backup, Lifeboat continues where it stopped.
* **Easy mode**: 4-second work bursts, light compression, a disk and upload speed cap, and an automatic pause while the server is busy. Visitors don't notice. This is the default for scheduled backups.
* **Rush mode**: long bursts at full speed, several times faster. Best right before you make big changes.
* Database dumps page by primary key, so huge tables are as fast as small ones.
* Archives are standard `.tar.gz` files that open with any tool (7-Zip, macOS Archive Utility, `tar`), written append-only so a resumed backup never rewrites what is already done.

**Update snapshots and one-click rollback**
* Just before WordPress updates a plugin or theme (manually, in bulk or automatically), Lifeboat saves a copy of the installed version.
* If the update breaks something, click "Roll back to 1.2" on the Plugins screen or in Lifeboat. The version being replaced is saved first, so a rollback can be undone.
* The last 3 versions of each plugin and theme are kept. Only that plugin's or theme's files change; the database is not touched.

**Restore**
* Restore any combination of database, plugins, themes, uploads, other content and core, from this server or straight from any cloud copy, even if the server copy is gone.
* Downloads are checked against SHA-1 checksums before anything is touched.
* The database is rebuilt in temporary tables and swapped in at the end, so the live site keeps working until the restore succeeds. The swap is verified table by table.
* wp-config.php and Lifeboat itself are never overwritten. Your cloud connections and (optionally) the current site address are kept, so you can restore a backup from another domain.

## Installation

1. Download the latest release ZIP (or this repository as a ZIP and rename the folder to `lifeboat-backup`).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the ZIP and click **Install Now**.
3. Activate it. It appears under the **Prasat Digital** menu, together with the other Prasat Digital plugins you use.

## The Prasat Digital plugin family

| Plugin | What it does |
|---|---|
| [Bastion Security](https://github.com/thhyarith-prog/bastion-security) | Firewall, login security, malware scan, comment spam shield, visitor stats |
| [Lifeboat Backup](https://github.com/thhyarith-prog/lifeboat-backup) | Automatic cloud backups and one-click restore |
| [Slipstream](https://github.com/thhyarith-prog/slipstream) | Page cache and speed optimizations |
| [Compass](https://github.com/thhyarith-prog/compass) | SEO: titles, descriptions, schema, sitemap, redirects |
| [Featherweight](https://github.com/thhyarith-prog/featherweight) | WebP images and WebM videos |

All five share one **Prasat Digital** menu with a one-click **Check my website** score.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

<p align="center"><em>“Every website deserves to stand strong, load fast and stay safe.”</em><br>Made by <a href="https://www.prasatdigital.com">Prasat Digital</a> · Founded by Thyyarith</p>
