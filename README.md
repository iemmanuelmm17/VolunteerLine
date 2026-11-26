# VolunteerLine — NetSapiens Web Responder (PHP 8.5.12)

A pure-PHP NetSapiens Web Responder v2 application for **Call & Win** and **Refer & Win**, designed to run on **Windows + XAMPP (PHP 8.5.12)** with no external Composer dependencies.

## Features

1. **Call & Win** (`/call-and-win.php`)
   - Collects **Team Number** (up to 10 digits, 3s digit-end timeout) with digit-by-digit TTS readback (`1` to confirm, `2` to re-enter).
   - Collects **Number of Calls** (configurable max digits, 3s digit-end timeout) with digit-by-digit TTS readback (`1` to confirm, `2` to re-enter).
   - Sends an SMTP (STARTTLS) email containing Team Number, Number of Calls, 10-digit Caller ID, Eastern Time date/time, and Call ID.

2. **Refer & Win** (`/refer-and-win.php`)
   - Collects **Team Number** (up to 10 digits, 3s timeout) with digit-by-digit confirmation.
   - Collects **Friend's Phone Number** (10-digit US/CA) with digit-by-digit confirmation.
   - Records a **voice message** (friend's name & address) over NetSapiens `<Start><Stream url="wss://...">` (press `#` when finished, configurable max duration, default `60` seconds).
   - Optionally plays back the `.wav` recording (`<Play>`) and asks `1` to confirm or `2` to re-record (`refer_confirm_before_send` in config).
   - Sends an SMTP (STARTTLS) email with the `.wav` voice message attached, Team Number, Friend's Phone Number, 10-digit Caller ID, and Eastern Time date/time.

3. **Configurable & Logged**
   - `config/config.php` (copied from `config/config.example.php`) holds all variables: TTS voice/language (`en-US`, `male`), timeouts, retry limits (`3`), max recording seconds (`60`), `refer_confirm_before_send`, `discard_on_early_hangup`, `keep_recordings`, separate recipient emails, and SMTP STARTTLS settings.
   - Daily logs written to `logs/volunteerline-YYYY-MM-DD.log`.

---

## Project Structure

```text
VolunteerLine/
├── bin/
│   ├── ws-server.php          # PHP CLI WebSocket server for NetSapiens <Stream>
│   └── start-ws.bat           # Windows launcher for ws-server.php
├── config/
│   └── config.example.php     # Copy to config/config.php (gitignored)
├── public/
│   ├── .htaccess              # Optional clean URL rewrites
│   ├── call-and-win.php       # Call & Win Web Responder URL
│   ├── refer-and-win.php      # Refer & Win Web Responder URL
│   └── recording.php          # Signed .wav playback endpoint for <Play>
├── src/                       # Core PHP classes (XML, WAV, SMTP, WebSocket, Handlers)
├── storage/
│   ├── recordings/            # .wav recordings (gitignored)
│   └── sessions/              # Per-call state JSON files (gitignored)
├── logs/                      # Daily log files (gitignored)
└── tests/
    └── run.php                # Self-contained PHP CLI test suite
```

---

## Windows + XAMPP Setup (`d:/www/volunteerline`)

### 1. Clone from Git to `d:/www/volunteerline`

Open **Command Prompt** or **PowerShell** on Windows and run:

```bat
mkdir D:\www
cd /d D:\www
git clone <YOUR_GIT_REPOSITORY_URL> volunteerline
cd /d D:\www\volunteerline
```

*(If your branch is not the default branch, check out the active branch after cloning, e.g. `git checkout <branch-name>`.)*

### 2. Create Local Configuration

Copy the sample config file to `config\config.php` (this file is ignored by Git):

```bat
copy config\config.example.php config\config.php
```

Edit `D:\www\volunteerline\config\config.php` and configure:
- `app_base_url`: Public HTTPS URL pointing to `public/` (e.g., `https://voice.yourdomain.org`)
- `ws_public_url`: Public `wss://` URL proxied to the PHP WebSocket server (e.g., `wss://voice.yourdomain.org/ws`)
- `call_and_win_email_to` and `refer_and_win_email_to`
- `smtp_host`, `smtp_port` (`587`), `smtp_encryption` (`'starttls'`), `smtp_username`, `smtp_password`, `smtp_from_email`
- `recording_access_secret`: Replace with a long random string
- Behavior flags as needed: `call_count_max_digits`, `max_recording_seconds`, `refer_confirm_before_send`, `discard_on_early_hangup`, `keep_recordings`, `tts_voice`, `tts_language`

### 3. Verify XAMPP PHP Extensions

In XAMPP (`D:\xampp\php\php.ini`), ensure these extensions are uncommented:

```ini
extension=openssl
extension=mbstring
```

Run the built-in PHP test suite to verify everything works on your PHP 8.5.12 installation:

```bat
D:\xampp\php\php.exe D:\www\volunteerline\tests\run.php
```

### 4. Configure XAMPP Apache (HTTPS + WebSocket Proxy)

NetSapiens `<Stream>` requires **`wss://`** (TLS WebSocket) and `<Play>` works best over **`https://`**.

1. In `D:\xampp\apache\conf\httpd.conf`, make sure these modules are enabled (uncommented):
   ```apache
   LoadModule ssl_module modules/mod_ssl.so
   LoadModule rewrite_module modules/mod_rewrite.so
   LoadModule proxy_module modules/mod_proxy.so
   LoadModule proxy_http_module modules/mod_proxy_http.so
   LoadModule proxy_wstunnel_module modules/mod_proxy_wstunnel.so
   ```

2. In your SSL VirtualHost (e.g. `D:\xampp\apache\conf\extra\httpd-ssl.conf` or `httpd-vhosts.conf`), point `DocumentRoot` to `D:/www/volunteerline/public` and proxy `/ws` to the PHP WebSocket server (`127.0.0.1:8090`):

   ```apache
   <VirtualHost *:443>
       ServerName voice.yourdomain.org
       DocumentRoot "D:/www/volunteerline/public"

       SSLEngine on
       SSLCertificateFile "conf/ssl.crt/server.crt"
       SSLCertificateKeyFile "conf/ssl.key/server.key"

       <Directory "D:/www/volunteerline/public">
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>

       # Proxy wss://voice.yourdomain.org/ws -> ws://127.0.0.1:8090
       ProxyPreserveHost On
       ProxyPass "/ws" "ws://127.0.0.1:8090"
       ProxyPassReverse "/ws" "ws://127.0.0.1:8090"
   </VirtualHost>
   ```

   > **Note:** If you host VolunteerLine in a subfolder (for example `https://yourdomain.org/volunteerline/public`), adjust `app_base_url` accordingly and match the `ProxyPass` path to your `ws_public_url` path (e.g. `ProxyPass "/volunteerline/ws" "ws://127.0.0.1:8090"`).

   > **Important:** Ensure `D:/www/volunteerline/config`, `src`, `storage`, and `logs` are **above** `DocumentRoot` (`public/`), as shown above, so they can never be browsed over HTTP.

3. Restart Apache in the XAMPP Control Panel.

### 5. Start the PHP WebSocket Server

Start the WebSocket audio recorder in a terminal (for testing):

```bat
D:\www\volunteerline\bin\start-ws.bat
```

For production on Windows, run `D:\xampp\php\php.exe D:\www\volunteerline\bin\ws-server.php` as a background service using **NSSM** (Non-Sucking Service Manager) or **Windows Task Scheduler** (triggered *At startup*, run whether user is logged on or not).

---

## NetSapiens Configuration

In your NetSapiens Manager / Admin UI:

1. Ensure **SNAPsolution v44.1+** (v44.4.6 verified) and **Text-to-Speech (Voice Services)** are enabled for the system user / domain handling the IVR.
2. Create **two Dial Translations** using the **`To-Web`** application:
   - **Call & Win**
     - URL: `https://voice.yourdomain.org/call-and-win.php` *(or `/call-and-win`)*
     - Application Parameter / Token: `<HttpMethod=POST>`
   - **Refer & Win**
     - URL: `https://voice.yourdomain.org/refer-and-win.php` *(or `/refer-and-win`)*
     - Application Parameter / Token: `<HttpMethod=POST>`
3. Link your IVR menu options to those two Dial Translation destinations (routing through a system user as recommended by NetSapiens).
