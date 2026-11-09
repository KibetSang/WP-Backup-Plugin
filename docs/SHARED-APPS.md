# The shared "Connect" apps (once, by the publisher)

Every site connects a cloud by pressing **Connect** and signing in. For that, the plugin carries one registered app
per provider. Register them once (about 15 minutes each), put their IDs in `includes/Destinations/Apps.php`, and
publish a new zip. Site owners never create an app of their own.

| Provider | How Connect works | What to put in `Apps.php` |
|---|---|---|
| OneDrive | Sign in at Microsoft, allow, come straight back | `ONEDRIVE_CLIENT_ID` |
| Dropbox | Sign in at Dropbox, allow, come straight back | `DROPBOX_APP_KEY` |
| Google Drive | Google's sign-in opens in a new tab; paste the code (already copied) and allow | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |

OneDrive and Dropbox come back through one static page in this repository, `docs/connect/index.html`, served by GitHub
Pages at **https://kibetsang.github.io/WP-Backup-Plugin/connect/** (turn Pages on once: repository Settings → Pages →
Deploy from a branch → `main`, folder `/docs` → Save). Every site has its own address, and a provider only sends people
back to addresses registered in advance, so all sites share this one; it only forwards the browser to the site that
started the sign-in. It sees no tokens: the one-time code it passes on is useless without the random secret (PKCE) the
site kept. Google does not allow a secret-free web app, so Google uses its code sign-in instead, which needs no return
address at all.

## OneDrive

1. [Microsoft Entra admin centre](https://entra.microsoft.com/) → App registrations → **New registration**: name
   "Bastion Backup", supported accounts **Accounts in any organizational directory and personal Microsoft accounts**.
2. **Authentication** → Add a platform → **Mobile and desktop applications** → custom redirect URI
   `https://kibetsang.github.io/WP-Backup-Plugin/connect/` → Configure. Then Advanced settings → **Allow public client
   flows: Yes** → Save. (Mobile and desktop makes it a public client: no secret, PKCE instead.)
3. **API permissions** → Add a permission → Microsoft Graph → Delegated: `Files.ReadWrite.AppFolder`, `offline_access`,
   `User.Read`.
4. Copy the **Application (client) ID** into `ONEDRIVE_CLIENT_ID`. No secret is created or used.
5. Optional: publisher verification (Branding & properties), so work accounts do not see "unverified".

## Dropbox

1. [Dropbox App Console](https://www.dropbox.com/developers/apps/create) → **Scoped access** → **App folder** → name
   "Bastion Backup".
2. **Permissions**: tick `account_info.read`, `files.metadata.read`, `files.content.read`, `files.content.write` → Submit.
3. **Settings** → OAuth 2 → Redirect URIs: add `https://kibetsang.github.io/WP-Backup-Plugin/connect/`. Allow public
   clients (Implicit grant / PKCE): **Allow**.
4. Copy the **App key** into `DROPBOX_APP_KEY`. The App secret is not used.
5. While the app is in "Development" it works for up to 500 accounts; press **Apply for production** when you publish.

## Google Drive

1. [Google Cloud Console](https://console.cloud.google.com/) → New project, e.g. "Bastion Backup".
2. APIs & Services → Library → **Google Drive API** → Enable.
3. Google Auth Platform → **Branding**: app name "Bastion Backup", support email, home page
   `https://github.com/KibetSang/WP-Backup-Plugin`, privacy policy (a page saying the plugin stores only the backups it
   makes, in the user's own Drive, and sends nothing to the publisher).
4. **Audience**: External → **Publish app**, or test users lose access after 7 days.
5. **Data access** → Add scope `https://www.googleapis.com/auth/drive.file` only (a non-sensitive scope: no security
   assessment).
6. **Clients** → Create client → Application type **TVs and Limited Input devices**. Copy the client ID and secret into
   `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`. For this type Google does not treat the secret as confidential.

## Then

Run `bin/build.sh` and publish the new zip. A site can still use its own apps (Destinations → "Advanced: use your
own app instead"), or other shared apps through `BASTION_BACKUP_GOOGLE_CLIENT_ID` / `_SECRET`,
`BASTION_BACKUP_ONEDRIVE_CLIENT_ID`, `BASTION_BACKUP_DROPBOX_APP_KEY` and `BASTION_BACKUP_CONNECT_RELAY` in wp-config.php.
