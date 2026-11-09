# Movexa

منصة بث فيديو خاصة مستضافة ذاتياً (تجربة شبيهة بـ Netflix) لمكتبة أفلام ومسلسلات تملكها، وقنوات بث مباشر من مصادر قانونية. تعمل داخل الشبكة المحلية/VPN على Ubuntu 24.04 + aaPanel، على العنوان الوحيد **`https://movexa.lan:8088`**، وتتكامل مع CloudSync وHomeCore.

> **المحتوى القانوني فقط:** لا توجد أي ميزة لجلب محتوى من مواقع قرصنة أو تورنت. المحتوى من ملفاتك أو من مصادر بث مرخّصة تشترك فيها.

## التقنيات
Laravel 13 (PHP 8.5) · MariaDB 10.11 · Inertia 2 + React 19 + TypeScript + Tailwind 4 + shadcn/ui · hls.js · ffmpeg/ffprobe (QSV/VAAPI/NVENC + تراجع للمعالج) · HLS fMP4 · Redis queues · Laravel Reverb · ClamAV · TMDB (اختياري).

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
**الخطوة اليدوية الوحيدة:** في aaPanel ← Website ← Add site ← الدومين `movexa.lan` واختر PHP 8.5. لا SSL ولا قاعدة بيانات ولا Rewrite.

ثم على السيرفر (root):
```bash
git clone https://github.com/code-create-innovate/Movexa.git /root/movexa
```
```bash
sudo bash /root/movexa/deploy/install.sh
```
السكربت يجد الموقع تلقائياً (من قاعدة مواقع aaPanel أو إعدادات nginx أو `/www/wwwroot`)، وإن وجد أكثر من موقع يسألك أيّها Movexa. ويمكن تحديده صراحة:
```bash
sudo bash /root/movexa/deploy/install.sh --site /www/wwwroot/movexa.lan --domain movexa.lan
```
يعمل على Ubuntu 24.04 و26.04. السكربت يضبط كل شيء: الحزم (ffmpeg، ClamAV، تعريفات GPU)، إعدادات PHP 8.5، مجلدات البيانات (noexec)، الـ sandbox، شهادة HTTPS من CA محلية، nginx على **8088 فقط**، قاعدة البيانات، Redis، بناء التطبيق، الخدمات الخلفية (systemd)، الـ Cron، الجدار الناري للشبكة المحلية، وحساب المسؤول. في النهاية يطبع الرابط وكلمة مرور المسؤول (ومحفوظة في `/root/movexa-install-summary.txt`). يمكن تشغيله مرة ثانية بأمان.

بعده: وجّه `movexa.lan` إلى IP السيرفر في الراوتر أو Pi-hole، وثبّت `/root/movexa-rootCA.crt` على الأجهزة. للتحديث لاحقاً: `sudo bash /www/wwwroot/movexa.lan/deploy/deploy.sh`.

الشرح اليدوي التفصيلي لكل خطوة (للمرجعية أو لو أردت الضبط يدوياً): **[docs/aapanel-setup.md](docs/aapanel-setup.md)**.

## الحسابات والصلاحيات
لا توجد شاشة "من يشاهد؟": **كل فرد يدخل بحسابه** (اسم مستخدم أو بريد + كلمة مرور، و2FA اختيارياً). المسؤول ينشئ الحسابات من **الإدارة ← المستخدمون والصلاحيات** ويحدد لكل حساب:

| الصلاحية | تتيح |
|---|---|
| البث المباشر · التنزيل · المشاهدة الجماعية | ميزات المشاهدة |
| رفع الملفات · إدارة المكتبة · الحجر والأمان · مصادر البث · الحجب المخصص · الإحصائيات · إدارة المستخدمين | أجزاء لوحة الإدارة |
| حد العمر (بدون / 0 / 7 / 12 / 13 / 16 / 18) | يرى فقط ما ضمن الحد |
| المكتبات المسموحة | يرى مكتبات محددة فقط |
| بثوث متزامنة، حصة التنزيل، إيقاف الحساب | حدود الاستخدام |

المسؤول (admin) يملك كل الصلاحيات. كل شيء مطبق في الخادم (الواجهة تخفي ما لا يُسمح به فقط للتوضيح).

## أفلام مجانية للتجربة (سكربت منفصل، اختياري)

مش جزء من التثبيت. شغّله بروحه لو تبي أفلام تجرّب بيها:

```bash
sudo bash /www/wwwroot/movexa.lan/deploy/sample-films.sh
```

ينزّل 10 أفلام قانونية (أفلام Blender المفتوحة برخصة CC BY، وكلاسيكيات في الملكية العامة) من download.blender.org و archive.org فقط،
ويحطها في مكتبة "Free films" وتمر على فحص الحماية كأي ملف. `--list` يعرض الأفلام والرخص، و`--only 1 --only 5` لأفلام محددة،
و`--no-scan` للتنزيل بس. إعادة التشغيل تكمّل التنزيل الناقص وتتخطى الأفلام الجاهزة.

## الاختبارات
```bash
php vendor/bin/phpunit                         # اختبارات الميزات والوحدات
MOVEXA_TEST_CLAMD=/run/clamav/clamd.ctl php vendor/bin/phpunit tests/Feature/Security   # مع clamd حقيقي
tests/e2e/run.sh                               # Playwright: المشغل، الحسابات والصلاحيات، QR، التنزيل دون اتصال، الإضاءة، شاشة الإيقاف…
sudo tests/nginx/run.sh                        # nginx + php-fpm + Reverb حقيقي على 8088 (حاوية تجريبية فقط)
sudo tests/install/run.sh                      # يشغّل install.sh على aaPanel محاكى (حاوية تجريبية فقط)
```

## التوثيق
- [تقرير الحالة](docs/STATUS.md): كل ميزة وحالتها، والقيود المعروفة لكل متصفح.
- [قائمة الاختبار اليدوي](docs/manual-tests.md)
- [التكامل مع CloudSync و HomeCore](docs/integration-cloudsync.md): `GET /api/health`، واجهات CloudSync و HomeCore، و SSO.
- [متطلبات العتاد](docs/hardware.md)
- النسخ الاحتياطي والاستعادة: `deploy/scripts/backup.sh` و `restore.sh` (ملفات الفيديو اختيارية: `INCLUDE_MEDIA=1`).
