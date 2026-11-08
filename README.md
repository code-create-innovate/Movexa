# Movexa

منصة بث فيديو خاصة مستضافة ذاتياً (تجربة شبيهة بـ Netflix) لمكتبة أفلام ومسلسلات تملكها، وقنوات بث مباشر من مصادر قانونية. تعمل داخل الشبكة المحلية/VPN على Ubuntu 24.04 + aaPanel، على العنوان الوحيد **`https://movexa.lan:8088`**، وتتكامل مع CloudSync وHomeCore.

> **المحتوى القانوني فقط:** لا توجد أي ميزة لجلب محتوى من مواقع قرصنة أو تورنت. المحتوى من ملفاتك أو من مصادر بث مرخّصة تشترك فيها.

## التقنيات
Laravel 12 (PHP 8.3) · MariaDB 10.11 · Inertia 2 + React 19 + TypeScript + Tailwind 4 + shadcn/ui · hls.js · ffmpeg/ffprobe (QSV/VAAPI/NVENC + تراجع للمعالج) · HLS fMP4 · Redis queues · Laravel Reverb · ClamAV · TMDB (اختياري).

## الهيكل
```
app/Modules/
  Core/         المستخدمون، الملفات الشخصية، 2FA، التوكنات، بوابة الوسائط الموقّعة، سياسة الوصول الموحدة (حد العمر + الحجب المخصص)، لوحة الإدارة، الأوامر
  Library/      المكتبات، الفحص، التعرف على الأسماء، TMDB، الصور المصغرة، خط الأمان (Inbox → CDR → Quarantine)
  Media/        ffprobe، الـ sandbox، اختيار Direct/Remux/Transcode، HLS، المرمّزات العتادية
  Playback/     التقدم، السجل، الجلسات، المشاهدة الجماعية
  Devices/      الدخول برمز QR، أجهزتي، الريموت، التنزيلات دون اتصال
  LiveTV/       M3U/HDHomeRun، XMLTV، عملية ffmpeg مشتركة لكل قناة، DVR
  Integrations/ CloudSync و HomeCore
resources/js/   الواجهة (pages/, components/movexa, i18n/ar|en)
deploy/nginx/   movexa.lan.conf — كتلة server كاملة جاهزة للصق في aaPanel (المنفذ 8088)
deploy/scripts/ deploy.sh, backup.sh, restore.sh, movexa-sandbox
public/offline.html, offline.js, sw.js — مشغل التنزيلات دون اتصال وService Worker
docs/           دليل aaPanel، التكامل، العتاد، تقرير الحالة، الاختبار اليدوي
```

## التثبيت (Ubuntu Server 24.04 + aaPanel)
الدليل الكامل خطوة بخطوة، مع مقاطع Nginx الجاهزة للصق، وإعدادات PHP، وSupervisor، والـ Cron، والمنافذ: **[docs/aapanel-setup.md](docs/aapanel-setup.md)**.

باختصار:
1. `apt install ffmpeg clamav clamav-daemon inotify-tools bubblewrap` + تعريفات التسريع العتادي.
2. إنشاء الموقع `movexa.lan` (PHP 8.3، Running directory `/public`) وقاعدة البيانات من الـ Panel.
3. مجلدات البيانات في `/www/data/movexa` (قرص منفصل `noexec`)، ومستخدم `movexa-sandbox`، وملف `/usr/local/sbin/movexa-sandbox`.
4. الصق [`deploy/nginx/movexa.lan.conf`](deploy/nginx/movexa.lan.conf) في Config file (المنفذ 8088، بدون 80/443).
5. `cp .env.example .env` ← `php artisan key:generate` ← `php artisan reverb:install` ← `deploy/scripts/deploy.sh`.
6. `php artisan movexa:create-admin you@example.lan --emergency` (أول حساب يمكن إنشاؤه أيضاً من صفحة التسجيل، ثم يُغلق التسجيل).
7. عمال Supervisor، و Cron لـ `schedule:run` و `backup.sh`.

الواجهة تُبنى بـ `npm ci && npm run build`؛ إن لم يتوفر Node على السيرفر، ابنِها على جهازك وارفع `public/build`.

## الاختبارات
```bash
php vendor/bin/phpunit                         # اختبارات الميزات والوحدات
MOVEXA_TEST_CLAMD=/run/clamav/clamd.ctl php vendor/bin/phpunit tests/Feature/Security   # مع clamd حقيقي
tests/e2e/run.sh                               # Playwright: المشغل، QR، التنزيل دون اتصال، الإضاءة، شاشة الإيقاف، المقدمة، الإيماءات
sudo tests/nginx/run.sh                        # nginx + php-fpm + Reverb حقيقي على 8088 (حاوية تجريبية فقط: يعدّل /etc/hosts)
```

## التوثيق
- [تقرير الحالة](docs/STATUS.md): كل ميزة وحالتها، والقيود المعروفة لكل متصفح.
- [قائمة الاختبار اليدوي](docs/manual-tests.md)
- [التكامل مع CloudSync و HomeCore](docs/integration-cloudsync.md): `GET /api/health`، واجهات CloudSync و HomeCore، و SSO.
- [متطلبات العتاد](docs/hardware.md)
- النسخ الاحتياطي والاستعادة: `deploy/scripts/backup.sh` و `restore.sh` (ملفات الفيديو اختيارية: `INCLUDE_MEDIA=1`).
