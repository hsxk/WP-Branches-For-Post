# WP Branches For Post 2.3.3

This patch resolves the remaining Polylang branch-creation issue found during
production verification after updating Time2Log to 2.3.2.

## Fix

A newly created WordPress draft may initially have the site's default language.
Polylang can automatically translate category or tag term assignments to that
draft's language. Before this fix the branch synchronizer sometimes applied
the original's category before assigning the original's language, leading to
`wbfp_taxonomy_sync_verification_failed` for translated (non-default-language)
posts.

Version 2.3.3 sets `language` terms first, before ordinary taxonomies. It
preserves exact taxonomy verification, the original language assignments,
categories, tags, and custom taxonomies. The internal `post_translations`
group remains excluded from copy/merge as in 2.3.2.

## Tests and compatibility

- Real WordPress integration tests now reproduce default language assigned to
  a new draft and category translation during term writes.
- Existing legacy baseline, rollback, merge, and Polylang-style isolation tests
  are retained.
- Keep the WordPress 6.6 / PHP 8.2 baseline and WordPress 7.1.3 / PHP 8.2–8.4
  matrix.
- No new UI strings, changes to approved language catalogs, or schema changes.

After upgrading a Polylang site, recreate a draft from translated Japanese
and English posts, compare their language and categories against originals,
and confirm existing live translation groups are unchanged before merging.
