=== Lifeboat Backup ===
Tags: backup, google drive, onedrive, dropbox, encryption
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatic WordPress backups to Google Drive, OneDrive, Dropbox, Box and MEGA, with schedules, backups after updates, password encryption, and one-click restore.

== Description ==

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

== Installation ==

1. Upload the `lifeboat-backup` folder to `/wp-content/plugins/` and activate it.
2. Weekly full backups and daily database backups are scheduled right away, stored on this server.
3. Go to **Lifeboat → Cloud storage** and connect at least one cloud.

= How do Google Drive, OneDrive, Dropbox and Box connect? =
Click **Connect** and sign in with your account. The sign-in goes through the Lifeboat sign-in relay, which hands the access token back to your site; your backup files go straight from your server to your cloud and never pass through the relay. If you prefer, you can use your own app keys instead (Advanced, on the Cloud storage screen). MEGA needs no app.

== Frequently Asked Questions ==

= My host blocks loopback requests. Will backups still run? =
Yes. WP-Cron resumes the job every minute, and an open Lifeboat page also drives it. For very large sites on low-traffic hosts, add a real cron job that calls wp-cron.php every minute.

= How do I restore if WordPress itself is broken? =
Download the archives from your cloud and extract them with any unzip tool. Import `database.sql.gz` with phpMyAdmin or `mysql` (every statement is on its own line).

Encrypted backups end in `.enc`. Decrypt each one first with the tool included in the plugin: `php lifeboat-decrypt.php uploads.tar.gz.enc`. It asks for the encryption password and writes the normal `.tar.gz` file.

= I lost my encryption password. Can you recover my backups? =
No. Nobody can, and that is the point of encryption. As long as the site that made the backups still works, it can restore them without the password, and you can set a new password for future backups. Keep the password in a password manager.

= Does Easy mode make backups slower? =
Yes, on purpose. It trades speed for keeping your site responsive. Use Rush for manual backups when you want them done quickly.

== Credits ==

* Interface fonts: Inter (The Inter Project Authors) and Noto Sans Khmer (Google), both under the SIL Open Font License 1.1. They are bundled in `assets/fonts`, so the admin screens never load fonts from another server.

== Changelog ==

= 1.0.6 =
* New: the Prasat Digital overview is now a full dashboard: website health with a severity breakdown, "What to fix first", live tiles for every plugin, recent activity, 7-day charts and the Smart AI Optimize (Premium) card.
* New: Prasat temple loading animations for checks, scans, backups and every progress bar.
* New: last backup, storage and running backups appear on the Prasat Digital dashboard.

= 1.0.5 =
* New: updates now come from the plugin's GitHub releases (github.com/thhyarith-prog), with the normal one-click "Update now" and automatic updates.

= 1.0.4 =
* Fix: declares "Update URI", so WordPress never offers an unrelated WordPress.org plugin with the same folder name as an update for this one.

= 1.0.3 =
* New: reports to the one-click "Check my website" on the Prasat Digital page.

= 1.0.2 =
* New: a new app icon for Lifeboat Backup and for the Prasat Digital menu, plus a short motto and credit (Made by Prasat Digital, founded by Thyyarith) at the bottom of every Prasat Digital screen.

= 1.0.1 =
* Fix: when a firewall or bot check (such as Cloudflare's) blocks the site's requests to itself, the background schedule now works for most of each run instead of one 4-second slice, so large backups finish in hours instead of a day.
* New: the Backups screen explains when these self-requests are blocked.

= 1.0.0 =
* First release.
