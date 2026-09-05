# Release Procedure — Beplus Performance Booster

GitHub `master` is the single source of truth (note: this repo uses
`master`, not `main` — differs from the sibling SEO plugin repo). WordPress.org
SVN is always regenerated FROM `master` — never hand-edited.

WordPress.org slug: `beplus-performance-optimizer`.
Internal plugin/class prefix: `beplus-performance-booster` / `BEPLUSPB`
(historical naming — do not "fix" without checking SVN slug impact first).

## 1. Land the fix/feature

- Branch off `master`: `fix/<short-name>` or `security-fix/<short-name>` for
  anything touching `class-bepluspb-object-cache.php`, `lib/object-cache.php`,
  or `class-bepluspb-htaccess.php`.
- Open a PR. CI must pass: PHP syntax (7.4–8.3), WPCS, version-consistency
  check, AND `security-regression-guard` (checks the two v1.0.5 security
  fixes are still in place — see `CLAUDE.md`).
- Merge to `master` with a merge commit (not squash) so the fix/feature
  reasoning stays visible in history.

## 2. Bump version

Both of these MUST match, and must match `Stable tag` in step 4:

- `beplus-performance-booster.php` → `Version:` header AND `BEPLUSPB_VERSION` constant
- `readme.txt` → `Stable tag:`

Add `= X.Y.Z =` entries to `readme.txt` (`== Changelog ==` and
`== Upgrade Notice ==` — user-facing, WordPress.org renders these) and to
`CHANGELOG.md` at repo root (dev-facing, includes internal/security detail
readme.txt wouldn't).

## 3. Sync to WordPress.org SVN

```bash
git clone https://github.com/minhpham2015/beplus-performance-optimizer.git /tmp/release-src

svn co --depth immediates https://plugins.svn.wordpress.org/beplus-performance-optimizer/ /tmp/release-svn
cd /tmp/release-svn && svn up --set-depth infinity trunk

# Mirror git -> svn trunk (this deletes anything in trunk not in the git repo).
# Dev-only files (CI config, AI/contributor docs, linter config) must NEVER
# ship in the WordPress.org package — exclude them explicitly.
rsync -av \
  --exclude='.git' --exclude='.svn' \
  --exclude='.github' \
  --exclude='CLAUDE.md' \
  --exclude='CHANGELOG.md' \
  --exclude='docs' \
  --exclude='composer.json' --exclude='composer.lock' \
  --exclude='phpcs.xml.dist' \
  --exclude='.gitignore' \
  /tmp/release-src/ trunk/ --delete-excluded
svn status
svn add <new files>
svn rm --keep-local <removed files>
```

## 4. Verify before committing — extra checks for THIS plugin

Beyond the standard version/syntax checks (see the SEO plugin's
`docs/RELEASE.md` for those, identical here), this plugin needs:

- **If Object Cache code was touched:** spin up the Docker test site, save
  Object Cache settings with a test password, then
  `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/wp-content/.bepluspb_oc.json`
  — MUST return `403`. If it returns `200`, STOP, do not release.
- **If `.htaccess` code was touched:** confirm `insert_with_markers()` still
  produces a clean single block (no duplicate markers from repeated saves).
- **If minify/CDN code was touched:** load a real post/page front-end,
  confirm no Fatal error and assets actually load (not just "page returns
  200" — check the browser console / actual asset URLs).

```bash
docker run --rm -v /tmp/release-svn/trunk:/code php:8.1-cli \
  sh -c "for f in \$(find /code -name '*.php'); do php -l \$f; done" \
  | grep -v "No syntax errors"
# Empty output = all files clean.
```

## 5. Commit trunk, then tag

```bash
svn commit trunk -m "Release X.Y.Z: <one-line summary>" --username minhphamit
svn up trunk
svn copy trunk tags/X.Y.Z
svn commit tags/X.Y.Z -m "Tag X.Y.Z release" --username minhphamit
```

## 6. Clean up

- Delete `/tmp/release-src` and `/tmp/release-svn`.
- Delete the merged branch on GitHub if not auto-deleted.
- Confirm on https://wordpress.org/plugins/beplus-performance-optimizer/
  that "Version" matches (allow a few minutes for cache).
- **If this was a security release:** consider whether existing users
  should be notified beyond the passive "Update available" dashboard
  notice — a security fix protecting credentials is higher-urgency than a
  routine feature release.

## Credentials

Same policy as the SEO plugin: SVN password provided per-session, never
stored on disk long-term, rotate after any session it was typed into a
non-personal shell/chat.
