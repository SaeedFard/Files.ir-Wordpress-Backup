=== Files.ir Wordpress Backup ===
Contributors: saeedfard
Tags: backup, database, files, cron, api, uploader
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

بکاپ دیتابیس و فایل‌های مهم وردپرس را طبق زمان‌بندی گرفته و به Files.ir از طریق API آپلود می‌کند.

== Description ==

- **DB + Files**: خروجی دیتابیس + آرشیو فایل‌ها (uploads/themes/plugins).
- **زمان‌بندی روزانه/هفتگی** با ساعت/دقیقه قابل تنظیم.
- **Worker URL** برای اجرای مستقیم بدون وابستگی به WP‑Cron (مناسب سرور کران).
- **سازگاری با API**: هدر Authorization (Bearer)، فیلدهای اضافه، `parentId` و `relativePath`.
- **حالت سازگاری** (Compact) برای سرورهایی که با multipart استاندارد مشکل دارند.
- **مدیریت لاگ**: نمایش، پاک‌سازی و حذف فایل لاگ.
- **نگه‌داری محلی** با سیاست تعدادی (Retention).

**Author:** Saeed Fard — https://github.com/SaeedFard

== Upgrade Notice ==
از نسخهٔ قبلی (Files DB Uploader) به این نسخه مهاجرت کردیم. نام پوشهٔ افزونه تغییر کرده است اما:
- کلید تنظیمات (**fdu_settings**) همان قبلی است ⇒ تنظیمات شما حفظ می‌شود.
- نام هوک‌های کران همان قبلی است ⇒ زمان‌بندی قبلی بدون مشکل ادامه می‌یابد.
- مسیر ذخیرهٔ فایل‌های محلی به‌صورت هوشمند، اگر پوشهٔ جدید وجود نداشته باشد، از مسیر قدیمی استفاده می‌کند.

== Installation ==
1. ZIP را نصب و فعال کنید.
2. تنظیمات قبلی شما به‌طور خودکار اعمال می‌شود.

== Changelog ==

= 1.0.2 =
* افزودن لینک «تنظیمات» در لیست افزونه‌ها.

= 1.0.1 =
* رفع ریزایراد نوشتاری در رشته‌های لاگ (`spawn_cron`/`doing_wp_cron`).

= 1.0.0 =
* تغییر اسلاگ افزونه به `files-ir-wordpress-backup` با حفظ کامل تنظیمات و کران قبلی.
* فایل‌های محلی: اگر پوشهٔ جدید موجود نباشد، از مسیر قدیمی استفاده می‌شود (بدون شکستن لاگ/بکاپ‌های قبلی).
