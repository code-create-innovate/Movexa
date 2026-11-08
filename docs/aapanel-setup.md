# دليل إعداد Movexa على Ubuntu 24.04 + aaPanel

> هذا الدليل يتقدم على أي تعليمات عامة. لا تعدّل إعدادات aaPanel الرئيسية يدوياً ولا تثبّت Nginx/PHP/MariaDB خارج الـ Panel.

## 1. الحزم خارج الـ Panel (apt + systemd)
```bash
sudo apt update && sudo apt install -y ffmpeg clamav clamav-daemon clamav-freshclam inotify-tools bubblewrap
```
تعريفات التسريع العتادي (اختر حسب العتاد):
```bash
sudo apt install -y intel-media-va-driver-non-free vainfo   # Intel Quick Sync / VAAPI (Gen 8+)
sudo apt install -y mesa-va-drivers vainfo                  # AMD VAAPI
sudo ubuntu-drivers install                                 # NVIDIA (NVENC) ثم أعد التشغيل
sudo usermod -aG render,video www                           # وصول www إلى /dev/dri
```
```bash
sudo systemctl enable --now clamav-daemon clamav-freshclam   # تحديث التواقيع تلقائياً
```

## 2. مستخدم الـ sandbox ومجلدات البيانات
```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin -G www movexa-sandbox
sudo mkdir -p /www/data/movexa/{inbox/drop/movies,inbox/drop/shows,library,quarantine,cache,images,uploads}
sudo chown -R www:www /www/data/movexa && sudo chmod -R 2775 /www/data/movexa && sudo chmod 2770 /www/data/movexa/quarantine
sudo install -m 0755 -o root -g root /www/wwwroot/movexa.lan/deploy/scripts/movexa-sandbox /usr/local/sbin/movexa-sandbox
echo 'www ALL=(root) NOPASSWD: /usr/local/sbin/movexa-sandbox' | sudo tee /etc/sudoers.d/movexa-sandbox && sudo chmod 440 /etc/sudoers.d/movexa-sandbox
```
**قرص منفصل مُركّب بـ noexec** (`/etc/fstab`):
```
UUID=<uuid>  /www/data/movexa  ext4  defaults,noexec,nosuid,nodev  0 2
```

## 3. الموقع وقاعدة البيانات
- Website → Add site: `movexa.lan`، المجلد `/www/wwwroot/movexa.lan`، PHP 8.3، **Running directory = `/public`**.
- Databases → Add: قاعدة `movexa` ومستخدم مستقل.

## 4. PHP 8.3
App Store → PHP 8.3 → Install extensions: `redis`, `fileinfo`, `intl`, `imagick`, `exif`, `opcache`, (`pcntl` لـ CLI).

Disabled functions — أزل من القائمة **فقط**:
| الدالة | السبب |
|---|---|
| `proc_open`, `proc_get_status` | تشغيل ffmpeg/ffprobe عبر Symfony Process، و Composer |
| `putenv` | Laravel/Composer |
| `pcntl_signal`, `pcntl_alarm` | مهلة وإيقاف عمال الطوابير (CLI) |
| `shell_exec` | بدء عملية ffmpeg للبث المباشر بشكل منفصل (setsid) |
| `posix_kill` (إن وُجدت) | إيقاف عمليات البث المباشر |

Configuration: `upload_max_filesize = 16M` و `post_max_size = 16M` (حجم الجزء 8MB، لا حجم الفيلم)، `max_execution_time = 120`.

**open_basedir**: Website → movexa.lan → Site directory → عدّل `.user.ini` (أو "Anti-XSS" off فقط لهذا الموقع) إلى:
```
open_basedir=/www/wwwroot/movexa.lan/:/www/data/movexa/:/www/data/cloudsync/:/tmp/:/usr/bin/ffmpeg:/usr/bin/ffprobe:/var/run/clamav/
```

## 5. Nginx — المنفذ 8088 (إلزامي)
العنوان الوحيد: **`https://movexa.lan:8088`** — HTTPS مباشرة على 8088، ولا يخدم Movexa على 80 أو 443.

1. **قبل التثبيت** تأكد أن المنفذ غير مستخدم: `ss -ltnp | grep -E ':(8088|8080) '` (يجب ألا يظهر شيء).
2. Website → movexa.lan → **Domain manager**: أضف `movexa.lan:8088` واحذف `movexa.lan` (المنفذ 80).
3. **SSL → Other certificate**: الصق الشهادة والمفتاح (انظر القسم 6). **لا تفعّل Force HTTPS** (يفترض 443).
4. **Config file**: استبدل كتلة `server` كاملة بالملف الجاهز [`deploy/nginx/movexa.lan.conf`](../deploy/nginx/movexa.lan.conf)، وأهم ما فيه:
```nginx
listen 8088 ssl http2;               # nginx >= 1.25.1:  listen 8088 ssl;  +  http2 on;
error_page 497 https://$host:$server_port$request_uri;   # http://…:8088 → https://…:8088
location /_protected/ { internal; alias /www/data/movexa/; … }      # X-Accel-Redirect فقط
location ~ ^/(app|apps)/ { proxy_set_header Host $http_host; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_pass http://127.0.0.1:8080; }
```
5. **URL rewrite**: اتركه فارغاً (القاعدة `try_files` موجودة داخل الملف).
6. ⚠️ **إذا غيّرت الشهادة أو المنفذ من الـ Panel، قد يعيد كتابة الملف**: افتح Config file وتأكد من أسطر `[8088]`: `listen 8088 ssl…` و`error_page 497…`، وأنه لا يوجد `listen 80` أو `listen 443`.
7. التحقق:
```bash
curl --cacert rootCA.pem https://movexa.lan:8088/api/health        # {"status":"ok",...}
curl -I http://movexa.lan:8088/                                     # 302 → https://movexa.lan:8088/
```
جُرِّب هذا الملف آلياً على nginx 1.24 حقيقي عبر `tests/nginx/run.sh` (انظر تقرير الحالة).

### Reverb عبر 8088
- المتصفح يتصل بـ `wss://movexa.lan:8088/app/...`، وNginx يمرر إلى Reverb على `127.0.0.1:8080` (لا يُفتح 8080 أبداً).
- PHP يرسل الأحداث أيضاً إلى `https://movexa.lan:8088` (قيم `REVERB_HOST/PORT/SCHEME`)، لذا يجب أن **يثق PHP في شهادة CA المحلية**:
```bash
sudo cp rootCA.pem /usr/local/share/ca-certificates/movexa-local-ca.crt && sudo update-ca-certificates
```
  أو ضع المسار في `REVERB_CA_BUNDLE=/path/rootCA.pem`. ويجب أن يُحل `movexa.lan` على السيرفر نفسه (DNS المحلي، أو سطر في `/etc/hosts`: `127.0.0.1 movexa.lan`).
- CSP يسمح فقط بـ `connect-src 'self' wss://movexa.lan:8088`.

### كل ما يجب أن يتضمن المنفذ
`APP_URL=https://movexa.lan:8088` (روابط QR، الروابط المطلقة)، Redirect URI في HomeCore: `https://movexa.lan:8088/auth/homecore/callback`، و`MOVEXA_URL` في CloudSync، و`start_url` للـ PWA نسبي فيعمل على نفس المنفذ. الروابط الموقعة `/m/...` نسبية فتأخذ المنفذ تلقائياً.

## 6. SSL والدومين الداخلي
```bash
mkcert -install && mkcert movexa.lan     # على جهاز الإدارة؛ ثبّت rootCA.pem على كل جهاز عائلي
```
Site → SSL → Other certificate: الصق `movexa.lan.pem` و`movexa.lan-key.pem` (أو `tailscale cert`). ثبّت `rootCA.pem` على كل جهاز (Android: الإعدادات ← الأمان ← تثبيت شهادة CA؛ iPhone: ملف تعريف ثم الإعدادات ← عام ← حول ← إعدادات الثقة بالشهادات)، وعلى السيرفر نفسه (لـ PHP→Reverb). Service Worker والتنزيلات تحتاج شهادة موثوقة على الجهاز.
DNS: في Pi-hole/AdGuard Home → Local DNS: `movexa.lan → <IP السيرفر>` (أو DHCP/DNS في الراوتر).

## 7. Supervisor Manager (App Store)
| الاسم | الأمر | المستخدم | العدد |
|---|---|---|---|
| movexa-queue | `/www/server/php/83/bin/php /www/wwwroot/movexa.lan/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600` | www | 1 |
| movexa-scan | `/www/server/php/83/bin/php /www/wwwroot/movexa.lan/artisan queue:work redis --queue=scan --timeout=21600 --tries=1` | www | 1 |
| movexa-transcode | `/www/server/php/83/bin/php /www/wwwroot/movexa.lan/artisan queue:work redis --queue=transcode --timeout=21600 --tries=1` | www | = `MOVEXA_MAX_TRANSCODES` |
| movexa-reverb | `/www/server/php/83/bin/php /www/wwwroot/movexa.lan/artisan reverb:start --host=127.0.0.1 --port=8080` | www | 1 |
| movexa-watch | `/www/server/php/83/bin/php /www/wwwroot/movexa.lan/artisan movexa:watch` | www | 1 |
| movexa-live | `/www/server/php/83/bin/php /www/wwwroot/movexa.lan/artisan movexa:live-reaper --loop` | www | 1 |

## 8. Cron (قسم Cron)
- كل دقيقة (Shell): `cd /www/wwwroot/movexa.lan && /www/server/php/83/bin/php artisan schedule:run >> /dev/null 2>&1`
- يومياً 03:20: `/www/wwwroot/movexa.lan/deploy/scripts/backup.sh` (نسخ aaPanel الافتراضي لا يشمل `/www/data/movexa`).

## 9. الجدار الناري (Security)
- افتح **8088 فقط** (TCP) **مقيّداً بالمصدر**: `192.168.0.0/16` و`100.64.0.0/10` (Tailscale). لا تفتح 80/443 لـ Movexa، ولا 8080 (Reverb خلف Nginx فقط)، ولا 3306/6379.
- **منفذ لوحة aaPanel**: قيّده بالشبكة المحلية/VPN، فعّل Security entrance والمصادقة الثنائية للوحة.
- `.env` خارج `public` دائماً.

## 10. التثبيت
```bash
cd /www/wwwroot/movexa.lan && git clone https://github.com/code-create-innovate/Movexa.git . 
cp .env.example .env && /www/server/php/83/bin/php artisan key:generate && /www/server/php/83/bin/php artisan reverb:install
deploy/scripts/deploy.sh
/www/server/php/83/bin/php artisan movexa:create-admin admin@movexa.lan --emergency
/www/server/php/83/bin/php artisan movexa:token homecore homecore:read      # ضعه في HomeCore
/www/server/php/83/bin/php artisan movexa:token cloudsync cloudsync:ingest  # ضعه في CloudSync
```
