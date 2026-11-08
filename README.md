# Movexa

منصة بث فيديو خاصة مستضافة ذاتياً (تجربة شبيهة بـ Netflix) لمكتبة أفلام ومسلسلات تملكها، وقنوات بث مباشر من مصادر قانونية. تعمل داخل الشبكة المحلية/VPN على Ubuntu 24.04 + aaPanel، وتتكامل مع CloudSync وHomeCore.

> **المحتوى القانوني فقط:** لا توجد أي ميزة لجلب محتوى من مواقع قرصنة أو تورنت. المحتوى من ملفاتك أو من مصادر بث مرخّصة تشترك فيها.

## التقنيات
Laravel 12 (PHP 8.3) · MariaDB 10.11 · Inertia 2 + React 19 + TypeScript + Tailwind 4 + shadcn/ui · hls.js · ffmpeg/ffprobe (QSV/VAAPI/NVENC + تراجع للمعالج) · HLS fMP4 · Redis queues · Laravel Reverb · ClamAV · TMDB (اختياري).

## الهيكل
```
app/Modules/
  Core/         المستخدمون، الملفات الشخصية، 2FA، التوكنات، بوابة الوسائط الموقّعة، لوحة الإدارة، الأوامر
  Library/      المكتبات، الفحص، التعرف على الأسماء، TMDB، الصور المصغرة، خط الأمان (Inbox → CDR → Quarantine)
  Media/        ffprobe، الـ sandbox، اختيار Direct/Remux/Transcode، HLS، المرمّزات العتادية
  Playback/     التقدم، السجل، الجلسات، المشاهدة الجماعية
  LiveTV/       M3U/HDHomeRun، XMLTV، عملية ffmpeg مشتركة لكل قناة، DVR
  Integrations/ CloudSync و HomeCore
resources/js/   الواجهة (pages/, components/movexa, i18n/ar|en)
deploy/scripts/ deploy.sh, backup.sh, restore.sh, movexa-sandbox
docs/           دليل aaPanel، التكامل، العتاد، تقرير الحالة، الاختبار اليدوي
```

## التثبيت (Ubuntu Server 24.04 + aaPanel)
الدليل الكامل خطوة بخطوة، مع مقاطع Nginx الجاهزة للصق، وإعدادات PHP، وSupervisor، والـ Cron، والمنافذ: **[docs/aapanel-setup.md](docs/aapanel-setup.md)**.

باختصار:
1. `apt install ffmpeg clamav clamav-daemon inotify-tools bubblewrap` + تعريفات التسريع العتادي.
2. إنشاء الموقع `movexa.lan` (PHP 8.3، Running directory `/public`) وقاعدة البيانات من الـ Panel.
3. مجلدات البيانات في `/www/data/movexa` (قرص منفصل `noexec`)، ومستخدم `movexa-sandbox`، وملف `/usr/local/sbin/movexa-sandbox`.
4. `cp .env.example .env` ← `php artisan key:generate` ← `php artisan reverb:install` ← `deploy/scripts/deploy.sh`.
5. `php artisan movexa:create-admin you@example.lan --emergency` (أول حساب يمكن إنشاؤه أيضاً من صفحة التسجيل، ثم يُغلق التسجيل).
6. عمال Supervisor، و Cron لـ `schedule:run` و `backup.sh`.

الواجهة تُبنى بـ `npm ci && npm run build`؛ إن لم يتوفر Node على السيرفر، ابنِها على جهازك وارفع `public/build`.

## الاختبارات
```bash
php vendor/bin/phpunit                         # 110 اختبار (ميزات + وحدات)
MOVEXA_TEST_CLAMD=/run/clamav/clamd.ctl php vendor/bin/phpunit tests/Feature/Security   # مع clamd حقيقي
tests/e2e/run.sh                               # Playwright: دخول → تشغيل → تقديم → جودة → ترجمة
```

## التوثيق
- [تقرير الحالة](docs/STATUS.md): كل ميزة وحالتها، والقيود المعروفة لكل متصفح.
- [قائمة الاختبار اليدوي](docs/manual-tests.md)
- [التكامل مع CloudSync و HomeCore](docs/integration-cloudsync.md): `GET /api/health`، واجهات CloudSync و HomeCore، و SSO.
- [متطلبات العتاد](docs/hardware.md)
- النسخ الاحتياطي والاستعادة: `deploy/scripts/backup.sh` و `restore.sh` (ملفات الفيديو اختيارية: `INCLUDE_MEDIA=1`).
