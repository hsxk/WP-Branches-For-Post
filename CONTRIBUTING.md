# Contributing

Thanks for helping improve WP Branches For Post.

## Before opening a pull request

- Keep changes focused and compatible with the WordPress coding standards used
  by this repository.
- Run the PHP syntax checks, PHPCS, JavaScript build, and relevant plugin tests.
- Do not commit `node_modules/` or `vendor/`.
- When a change affects the Block Editor, Classic Editor, merge/rebase behavior,
  permissions, metadata, taxonomies, or post identity, include a regression
  test or a clear reproduction case.

## Translation change policy

Translation approval is intentionally handled separately from normal development.

- **Bug fixes:** do not change existing translations, catalogs, message IDs,
  or POT/PO/MO/JSON language artifacts. Reuse existing strings and correct
  behavior without triggering translation churn.
- **New features:** reuse existing user-facing strings when they accurately
  describe the action. Introduce only the minimum genuinely necessary new
  translatable strings. Do not rename existing messages for style or wording.
- Do not regenerate, normalize, reorder, reformat or commit existing translation
  catalogs incidentally while fixing code, running builds, or refreshing assets.
- Any required translation additions must be narrowly scoped, explicitly
  identified in the PR, and handled in a separate reviewed translation change
  when approval is needed. Never silently rewrite already approved entries.
- Translation files should remain unchanged when no translation change is
  actually required; release/CI checks must not be treated as permission to
  auto-commit catalog updates.

## Security-sensitive changes

Branch creation, merge, rebase/update, discard, REST routes, capability checks,
nonces, and stale-write/conflict handling are security-sensitive. Read
[SECURITY.md](./SECURITY.md) before changing those paths.

A force merge may bypass conflict blocking only; it must never bypass
authorization or branch/original relationship validation.

## Releases

WordPress.org deployment is release automation, not a development shortcut.
Do not publish or retag a release until its validation workflows have passed.
Keep user-facing changelog/readme text in sync with the shipped plugin version.
