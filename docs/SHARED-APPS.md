# The shared "Connect" apps (once, by the publisher)

With these two apps registered and their IDs in `includes/Destinations/Apps.php`, every site connects Google Drive or
OneDrive by pressing **Connect**, signing in at google.com/device or microsoft.com/devicelogin and entering the code the
site shows. Site owners never create an app of their own. Tokens go straight from Google or Microsoft to each site:
there is no server of ours in between, and the apps only ever reach the files the plugin itself makes.

## Google Drive

1. [Google Cloud Console](https://console.cloud.google.com/) → New project, e.g. "Bastion Backup".
2. APIs & Services → Library → **Google Drive API** → Enable.
3. Google Auth Platform → **Branding**: app name "Bastion Backup", support email, home page
   `https://github.com/KibetSang/WP-Backup-Plugin`, privacy policy (a page saying the plugin stores only the backups it
   makes, in the user's own Drive, and sends nothing to the publisher).
4. **Audience**: External. **Publish app** (to Production), or test users lose access after 7 days.
5. **Data access** → Add scope `https://www.googleapis.com/auth/drive.file` only. It is a non-sensitive scope, so no
   security assessment is needed.
6. **Clients** → Create client → Application type **TVs and Limited Input devices** → Create. Copy the client ID and
   client secret. (For this client type Google does not treat the secret as confidential: it ships inside every copy of
   an app.)
7. Put them in `includes/Destinations/Apps.php` as `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`.

## OneDrive

1. [Microsoft Entra admin centre](https://entra.microsoft.com/) → App registrations → **New registration**: name
   "Bastion Backup", supported accounts **Accounts in any organizational directory and personal Microsoft accounts**.
   No redirect URI.
2. **Authentication** → Advanced settings → **Allow public client flows: Yes** → Save.
3. **API permissions** → Add a permission → Microsoft Graph → Delegated: `Files.ReadWrite.AppFolder`, `offline_access`,
   `User.Read`. (Some organisations require their administrator to approve new apps; personal accounts do not.)
4. Copy the **Application (client) ID** into `includes/Destinations/Apps.php` as `ONEDRIVE_CLIENT_ID`. No secret is used.
5. Optional: publisher verification (Branding & properties), so work accounts do not see "unverified".

## Then

Run `bin/build.sh` and publish the new zip. A site can still use its own apps instead (Destinations → "Advanced: use
your own app instead"), or other shared apps through `BASTION_BACKUP_GOOGLE_CLIENT_ID` / `_SECRET` and
`BASTION_BACKUP_ONEDRIVE_CLIENT_ID` in wp-config.php.
