=== Bastion Backup ===
Contributors: kibetsang
Tags: backup, restore, migration, google drive, dropbox
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encrypted backups of the whole site and database, to 10 GB and more, sealed to a key this site cannot open. Copies to Google Drive, OneDrive or your own SFTP/FTPS server; restores here or on a new server.

== Description ==

Bastion Backup makes a complete backup of your WordPress site: WordPress itself, themes, plugins, uploads and the whole database, in one encrypted .bbk file.

**Encrypted to a backup key the site does not have.** When you make the backup key, its secret half is shown to you once (BBK-…) and the site keeps only the public half. The site can make backups but can never open them, so someone who breaks into the site, the database or the cloud account cannot read your backups either. Keep the key in your password manager: without it no one can restore a backup, including us.

**Any size, any host.** Backups and restores run in short steps (20 seconds by default) that save their place, with memory near 32 MB whatever the size of the site. Tested at 10 GB with memory_limit=128M. They carry on in the background when you close the page, and WP-CLI runs them without a browser.

**Copies elsewhere.** Press Connect and sign in: after each backup a copy goes to Google Drive (access only to files this plugin makes), OneDrive or Dropbox (the plugin's own app folder only) or your own server over SFTP or FTPS. Uploads resume where they stopped. The newest N are kept in each place; only backups this plugin made are ever deleted.

**Safe restores.** A restore first reads and checks every byte of the backup and changes nothing unless all of it is intact. You confirm with your WordPress password and the word RESTORE. The database is loaded into temporary tables and swapped in at once at the end; files are written beside the old ones and renamed into place. wp-config.php, this plugin and its backups are never overwritten from the dashboard.

**Moving or rebuilding a site.** The installer (installer.php) restores a backup on any server with PHP 8.1 and MySQL or MariaDB, like Duplicator's installer: new address, new database and new table prefix included. It is locked with an access key and deletes itself when it has finished.

== Installation ==

1. Upload the plugin and activate it.
2. Open Bastion Backup → Backups and press **Make the backup key**. Store the key that is shown, as it is shown only once.
3. Press **Back up now**, or set a schedule under Schedule & retention.
4. Optional: set up Google Drive, OneDrive or a file server under Destinations and choose it under Schedule & retention.

= Stronger setup (wp-config.php) =

* `define( 'BASTION_BACKUP_STORAGE', '/home/account/bastion-backups' );` keeps backups and working files outside the website.
* `define( 'BASTION_BACKUP_SECRET', '…64 or more random characters…' );` encrypts the saved Google, Microsoft and file server credentials with this value instead of the WordPress salts.

= Google Drive, OneDrive and Dropbox =

1. Bastion Backup → Destinations → **Connect OneDrive** or **Connect Dropbox**: sign in, allow access, and you come straight back, connected.
2. **Connect Google Drive**: Google's sign-in opens in a new tab with a code already copied for you; paste it, allow access, and the page notices by itself.
3. Press Test, then choose where copies go under Schedule & retention.

Each service gives the plugin access only to its own folder (Google Drive: the files it makes; OneDrive and Dropbox: Apps → Bastion Backup). Tokens go straight from the service to your site.

Prefer your own app? Open "Advanced: use your own app instead" on the Destinations tab and follow its steps.

= File server (SFTP or FTPS) =

1. Make a login on the server used only for backups, allowed only into the backup folder.
2. For SFTP, get the server's host key fingerprint on a computer you trust: `ssh-keyscan -t ed25519 your.server | ssh-keygen -lf -` and paste the `SHA256:…` value. A server that shows another key is refused.
3. Enter the server, folder, login and password (or an SSH private key), Save, Test. Plain FTP is not offered because it sends the password unencrypted.

== Frequently Asked Questions ==

= I lost the backup key. =

Then the backups made with it cannot be opened by anyone. Make a new key straight away (Backups → Replace the key) and a new backup. Keep two copies of the key in safe places.

= Why can the site not open its own backups? =

So that a break-in does not expose them. The site keeps only the public half of the key, which can lock but not unlock.

= Does it work on Nginx? =

Yes. Nginx ignores .htaccess, so Tools → Site Health checks whether the backup folder can be fetched from the web. If it can, set BASTION_BACKUP_STORAGE to a folder outside the website (recommended anyway).

= WP-CLI =

`wp bastion backup [--db-only] [--files-only] [--no-upload] [--to=google,onedrive,fileserver]`
`wp bastion verify <backup>` and `wp bastion restore <backup> [--db-only] [--files-only] [--yes]` (the key from BASTION_BACKUP_KEY, --key or typed when asked)
`wp bastion list [--destination=google]`, `wp bastion status`, `wp bastion cancel`

= Disaster recovery in 5 steps =

1. On the new server, make an empty database and user, and point the domain at an empty folder with HTTPS.
2. Upload installer.php (Bastion Backup → Installer) and the .bbk file into that folder.
3. Open https://your-site/installer.php and enter the installer's access key.
4. Choose the backup, paste the backup key and the database details, press Test connection, Check the backup, then Restore.
5. Sign in. The installer deletes itself; if it reports it could not, delete installer.php by hand at once.

== Changelog ==

= 1.0.3 =
* Fixed: backups stopped at once with "Undefined constant SODIUM_CRYPTO_SECRETSTREAM_…" on servers using WordPress's built-in sodium.
* Settings → Where backups are kept: choose the folder on your server (e.g. beside public_html, outside the website); backups already made are moved.
* Dropbox, with one-click Connect; OneDrive also connects in one click (sign in and come straight back). Google Drive's code is copied for you.
* The progress box shows the steps (database, files, each copy, finish), the size done and the time left.

= 1.0.2 =
* Settings → Server check shows what the PHP running the site really has: version, build (cPanel EasyApache, CloudLinux, Plesk), php.ini, sodium.
* The advice for turning sodium on now matches the server: on cPanel's EasyApache PHP it is the ea-phpXX-php-sodium package, which "Select PHP Version" does not change.

= 1.0.1 =
* Works where PHP's sodium extension is off: it uses WordPress's built-in copy (slower) and says so, instead of refusing to start.
* The Bastion Backup menu is always in the sidebar; when the server needs a change, its page says exactly what (PHP version, sodium, php.ini).
* Connect Google Drive and OneDrive by signing in with a code, once the shared apps are set (docs/SHARED-APPS.md).
* Official Google Drive and OneDrive logos.
* Fixed: the "replace the key" form showed before it was asked for.

= 1.0.0 =
* First release: encrypted backups sealed to a backup key, resumable to 10 GB and more, Google Drive, OneDrive and SFTP/FTPS destinations, dashboard restore with an atomic database swap, standalone installer, WP-CLI, Site Health checks.
