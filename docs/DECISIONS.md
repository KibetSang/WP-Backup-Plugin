# Decisions

1. **A backup key instead of a passphrase** (changes PROMPT §3 and §4.3). As in the Biosafety conference system, the
   site keeps only the public half of an X25519 key; the secret half is shown once as `BBK-` and 14 groups of 4
   (13 for the key, 1 check group that catches typing mistakes). A scheduled backup needs no secret on the server, and a
   break-in cannot read the backups. The passphrase format (Argon2id) stays in the core for tools and tests.
2. **Destinations in this version: Google Drive, OneDrive and a file server (SFTP/FTPS)**, as asked by the owner.
   S3-compatible storage and Dropbox from PROMPT §2 are left for a later version; the `Destination` interface is ready
   for them.
3. **SFTP and FTPS through PHP's curl**, which most hosts have, rather than ext-ssh2 (rare) or ext-ftp. Plain FTP is not
   offered. SFTP requires the host key fingerprint; without it the destination is "not set up".
4. **The OAuth redirect is a REST route**, and it checks the WordPress login cookie itself (REST requests without a nonce
   have no current user), so the browser that returns must be the administrator who pressed Connect.
5. **Busy or lost answers from a cloud during an upload resume next step**, after asking the provider how much it has;
   ten in a row and the destination is given up for that backup (the others carry on).
6. **Backups are listed and pruned by the time in their names**, not the upload time, so retention keeps the newest
   backups even when an older one was uploaded later.
7. **The installer keeps the backup key and database password in the browser** and sends them with each step; only the
   archive reader's position is stored on the new server, and it is deleted at the end.
8. **The dashboard never overwrites `wp-config.php`**, this plugin or its storage. The installer does restore
   `wp-config.php` and patches the database login, prefix and any `WP_HOME`/`WP_SITEURL`; if the backup had no prefix
   line, one is added.
9. **Admin UI:** the Biosafety Backups page's panels and wording (backup key, make a backup, the list with "check with the
   key", restoring steps) built from WordPress admin parts (tabs, form tables, `widefat` lists, notices, buttons), in plain
   JavaScript and CSS with no build step.
10. **Tests without WordPress:** the plugin layer is tested against a small stand-in for WordPress whose options live in a
    real `wp_options` table on MariaDB, so restores that swap tables are tested as they behave in WordPress. A run on a
    real WordPress install (and Plugin Check, PHPCS) is still to do before release; WordPress could not be downloaded in
    the build environment.
