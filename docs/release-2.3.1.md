# WP Branches For Post 2.3.1

This maintenance release completes the newly introduced **2.3.0 editorial
workflow interface** in Japanese, Simplified Chinese and Traditional Chinese.

- Added **54 previously untranslated gettext messages per locale**, including
  independent approval, review status and queue, scheduled merges, review
  errors and per-field conflict decisions.
- Generated and included the corresponding PHP `.mo` files and Gutenberg
  editor script-translation JSON. **All 120 pre-existing translated entries
  in each catalog retain their exact approved text.**
- Added real **WordPress 7.1.3 + Chromium** user-interface tests in all three
  locales: visible editor controls, localized PHP Tools screen, 390px mobile
  width and keyboard expansion.
- Preserved all 2.3.0 merge, approval and cron safety behavior; no storage
  migration is required.
- WordPress 7.1.3 is tested on PHP 8.2, 8.3 and 8.4, with the WordPress 6.6
  compatibility baseline retained.

The WordPress.org Tested up to value is `7.1`, as required by Plugin Check;
patch version 7.1.3 is covered by the actual tested CI matrix.

For the complete feature list, see the
[2.3.0 release](https://github.com/hsxk/WP-Branches-For-Post/releases/tag/v2.3.0).
