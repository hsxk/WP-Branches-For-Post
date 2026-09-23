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
