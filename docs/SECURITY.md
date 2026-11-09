# Security

## What is protected

The site's files and database (in backups), the backup key, the destination credentials, the site itself during a
restore, and the new server during an installer run.

## Threats and how they are handled

| Threat | Mitigation | Where |
|---|---|---|
| Someone takes over the site and reads the backups | Backups are sealed to the backup key; the site holds only its public half. Making backups never needs the secret half. | `Keys`, `Crypto::newArchiveKeysForPublicKey` |
| Backups downloaded from the web | Storage in `wp-content/bastion-backups-<16 hex>/` with `.htaccess` (2.2 and 2.4), `web.config`, `index.php`; `BASTION_BACKUP_STORAGE` to move it outside the web root; Site Health fetches a canary file to prove it is blocked; downloads only through admin-post with capability + nonce | `Storage`, `SiteHealth::storage`, `Ajax::download` |
| A tampered, swapped or cut-off backup restored | Every frame authenticated (XChaCha20-Poly1305), header bound as associated data, final tag required, nothing after the end marker; the whole archive is verified before anything changes | `ArchiveReader`, `RestoreJob::verify` |
| A crafted archive exhausting the server | Header ≤ 64 KiB, frames ≤ 8 MiB, record meta ≤ 1 MiB, decompression capped at 8 MiB, KDF limits | `ArchiveReader`, `Crypto` |
| Path traversal or symlink escape on extraction | `PathGuard`: no NUL, absolute paths, drive letters or `..`; every folder `realpath`-checked inside the root; symlinks never followed when backing up; setuid bits dropped | `PathGuard`, `Restorer` |
| SQL injection from an archive | Only `CREATE TABLE`/`INSERT INTO` the placeholder table are accepted, the name substituted at that fixed position; `mysqli::query`, never `multi_query`; identifiers backtick-quoted; values escaped or hex | `DbImporter`, `DbExporter` |
| PHP object injection during search/replace | `unserialize(…, ['allowed_classes' => ['stdClass']])`; values holding other objects are left untouched (a test proves `__wakeup`/`__destruct` never run) | `SearchReplace` |
| A half-restored site | Database into temporary tables, one atomic `RENAME TABLE`; files to `*.bbk-part` then renamed; a failure before the swap drops the temporary tables and leaves the site as it was | `DbImporter::swap`, `Restorer` |
| An unauthorised restore, key change, deletion or installer | `manage_options` (`manage_network_options` on multisite) and a nonce on every request; the WordPress password asked again, 5 wrong tries lock for 15 minutes; restore also needs "RESTORE" typed | `Ajax` |
| A forged OAuth return | `state` is 32 random bytes, single use, 10 minutes, bound to the administrator; the returning browser must be signed in as that same administrator (login cookie) who still has the capability; PKCE S256 | `OAuthDestination`, `OAuth::callback` |
| Destination credentials read from a database copy | Client secrets, tokens, passwords and SSH keys in one secretbox blob, key from `BASTION_BACKUP_SECRET`, else the salts, else a key file; never echoed back into forms | `Vault`, `Admin` |
| A server pretending to be your file server | SFTP requires the pinned host key fingerprint (SHA-256 or MD5); FTPS verifies the certificate and host name and encrypts login and data; plain FTP is not offered | `FileServer` |
| Leaking tokens or signed URLs | No token, key or upload/download URL is logged; logs and emails carry no secrets | `Http`, `Logger`, `Notifier` |
| Retention deleting someone else's files | Only names matching `bastion-<slug>-<date>-<time>-<6 hex>.bbk` are listed or deleted | `Registry::isOurs` |
| The installer left behind | One-time access key (only its hash is in the file), 10 tries then locked, CSRF token, strict session cookie, security headers, warns without HTTPS, deletes itself, its working folder and (if chosen) the backup | `installer-template.php` |
| Two jobs at once | One job at a time, `flock` around each step | `Jobs` |
| Who did what | Audit log of backups, checks, restores, key changes, connections, deletions, downloads and failed password checks, with user, time and address | `Audit` |

## Remaining risks

- Whoever holds the **backup key** can open every backup made with it. It is shown once; keep it offline, and replace it
  (Backups → Replace the key) if it may have been seen.
- On a host that ignores `.htaccess` (Nginx), the storage folder inside the website relies on its unguessable name
  until `BASTION_BACKUP_STORAGE` is set; Site Health reports this.
- A request killed by the server mid-step (a hard kill, not the step deadline) during a restore can repeat the INSERT
  statements of that step for tables without a primary key. Steps stop at 60 % of PHP's limit to make this unlikely;
  WP-CLI avoids it altogether.
- The file server destination has been tested for its settings and checks, but not yet against a live SFTP/FTPS server
  (none was available while building it). Test it with your server (Destinations → Test) before relying on it.
