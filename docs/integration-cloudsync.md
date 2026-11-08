# التكامل مع CloudSync و HomeCore

## العنوان
كل الاستدعاءات من CloudSync وHomeCore إلى **`https://movexa.lan:8088`** (`MOVEXA_URL` في إعداداتهما). يجب أن يثق كلاهما بشهادة CA المحلية.

## الصحة
`GET /api/health` (بدون مصادقة) → `{"status":"ok|degraded|down","service":"movexa","checks":{"database":"ok","redis":"ok","storage":"ok","ffmpeg":"ok"}}` — حالة فقط، دون إصدارات أو مسارات أو أعداد. 503 عند تعطل قاعدة البيانات/Redis.

## CloudSync → Movexa (توكن بصلاحية `cloudsync:ingest`)
| الطلب | الوظيفة |
|---|---|
| `POST /api/cloudsync/link` `{user, file_id, library: movies\|shows}` | زر "أضف إلى Movexa": **ربط بلا نسخ**. فحص النوع + ffprobe + ClamAV في مكانه؛ إن احتوى الملف مرفقات/بيانات مشبوهة تُبنى نسخة نظيفة (CDR) في المكتبة. |
| `POST /api/cloudsync/send` | "أرسل إلى المكتبة" من الجهاز: نسخ إلى inbox ثم المسار الكامل (CDR). |
| `GET /api/cloudsync/ingest/{id}` | حالة المعالجة. |

`user` = الـ `sub` من HomeCore OIDC. **Movexa لا يثق بالمسار المرسَل**: يستدعي CloudSync للتحقق من الصلاحية، ثم يتحقق أن `realpath` داخل `CLOUDSYNC_ROOT`.

## Movexa → CloudSync (يجب أن يوفرها CloudSync، توكن `CLOUDSYNC_TOKEN`)
```
GET /api/integrations/movexa/files/{id}?user=<sub>   → {id, name, path, size, mime, can_read: bool}
GET /api/integrations/movexa/folders?user=<sub>&parent=/ → {folders: [{id, name, path}]}
GET /api/health                                         → {status: "ok"}
```
اختيار مجلد CloudSync كمكتبة: لوحة المسؤول ← مكتبة جديدة ← المصدر `cloudsync` ← مسار داخل `CLOUDSYNC_ROOT` (يُضاف إلى `open_basedir`).

## HomeCore (توكن `homecore:read`)
`/api/homecore/continue-watching?user=<sub>` (آخر 3 مع البوستر عبر `/api/homecore/poster/{id}?user=<sub>`)، `/now-streaming`، `/transcodes`، `/search?user=<sub>&q=` (لاختصار `mx`). كل النتائج تمر بسياسة الوصول لأول ملف بالغ في حساب المستخدم (حد العمر + الحجب)؛ بدون `user` لا تُعاد نتائج.

## SSO
OIDC Authorization Code + PKCE (S256) ضد HomeCore (Passport): تحقق RS256 عبر JWKS، و`iss/aud/exp/nonce/state`. Redirect URI: `https://movexa.lan:8088/auth/homecore/callback`. إذا توقف HomeCore: الجلسات الحالية مستمرة (جلسات Movexa محلية)، وحساب الطوارئ المحلي (`movexa:create-admin --emergency`) يدخل بكلمة المرور.
