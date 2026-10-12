# WP Branches For Post 2.3.2

This maintenance release fixes taxonomy verification errors when creating or
merging WordPress post branches on websites using Polylang.

## Bug fix

- Do not copy or synchronize Polylang's internal `post_translations` taxonomy,
  which models language-to-language post relationships rather than editable
  post classifications. This avoids `wbfp_taxonomy_sync_verification_failed`
  during branch creation.
- Preserve the source post's language, categories, tags, and ordinary custom
  taxonomy assignments. The published original retains its existing translation
  group.
- Read legacy branch baseline snapshots compatibly if those snapshots contain
  the old internal translation-group taxonomy, without rewriting saved history.
- Add real WordPress regression coverage for translated-post branch creation,
  preserved language, isolated draft translation relationships, legacy baseline
  review and merge safety.

## Compatibility and quality

- Supports the existing WordPress 6.6 baseline and the WordPress 7.1.3 test
  matrix on PHP 8.2, 8.3 and 8.4.
- No new strings, translations, UI features or database migrations.
- All approved existing translation catalogs remain unchanged.

## Production rollout

After WordPress.org deploys this version, update WP Branches For Post in the
WordPress admin and confirm that translated original posts still belong to
their existing Polylang translation group. Create a fresh draft branch from an
affected translated post and verify the draft is editable without changing
the original's public language links.

This patch builds on the previously released 2.3.1 localization update.
