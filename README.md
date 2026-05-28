# Debi Payment for WooCommerce

Official Debi payment gateway integration for WooCommerce.

This document is the **contributor / developer** README. End-user installation
and support documentation lives in [`README.txt`](README.txt), which is the
canonical file consumed by the [WordPress.org plugin
directory](https://wordpress.org/plugins/).

> The current plugin source (`debi-payment-for-woocommerce.php`, `debi.php`,
> `class-wc-debi.php`, `uninstall.php`) is being rewritten on top of the
> `debi-php` and `debi-js` SDKs. The infrastructure in this branch
> (`refactor`) is the foundation for that rewrite. The scaffolding is
> intentionally lightweight and is not yet wired to publish anything.

---

## Requirements

- [Docker](https://www.docker.com/) (Desktop on macOS/Windows, Engine on Linux)
- [Node.js](https://nodejs.org/) 20+
- [Composer](https://getcomposer.org/) 2+
- PHP 7.4+ on the host (only for running PHPCS / PHPUnit locally; the
  workbench itself ships its own PHP via Docker)

## Quick start (single-site, the daily driver)

```bash
git clone https://github.com/tucuota/debi-payment-for-woocommerce.git
cd debi-payment-for-woocommerce
git checkout refactor

# One-time install
composer install
npm install

# Boot the local WordPress + WooCommerce + Debi workbench
npm start
```

When `npm start` finishes:

- WordPress runs at **http://localhost:8888**
- wp-admin at **http://localhost:8888/wp-admin** (login: `admin` / `password`)
- The seeder has created 1 customer + 3 plain WC products and enabled the
  Debi gateway in sandbox mode.

Useful commands:

| Command | What it does |
|---|---|
| `npm start` | Start the single-site workbench |
| `npm stop` | Stop containers (data persists) |
| `npm run reset` | Destroy volumes and re-start from scratch |
| `npm run seed` | Re-run the seeder against the running env |
| `npm run shell` | Drop into a bash shell inside the WP container |
| `npm run lint` | Run PHPCS against `bin/` and `tests/` |
| `npm run lint:fix` | Auto-fix what PHPCBF can |
| `npm test` | Run the PHPUnit smoke suite |

## Multisite profile

Your first real customer runs WordPress multisite, so we keep a first-class
multisite profile available behind opt-in commands:

```bash
npm run start:multisite
```

When this finishes:

- The multisite network runs at **http://localhost:8888**
- Network admin at **http://localhost:8888/wp-admin/network/**
- WooCommerce and Debi are **network-activated**
- Two subsites exist: `/shop-one/` and `/shop-two/`
- Each site (root + 2 subsites) has the same baseline seed data

Stop/reset commands mirror the single-site ones: `npm run stop:multisite`,
`npm run reset:multisite`.

### How profile switching works under the hood

`wp-env` does not accept a `--config` flag — it always reads `.wp-env.json`
from the current directory and, if present, merges `.wp-env.override.json`
on top of it.

We exploit that override hook:

- `.wp-env.json` is the committed single-site baseline.
- `.wp-env.multisite.json` is the committed multisite profile.
- `.wp-env.override.json` is **transient** (git-ignored).
- `npm start` removes any leftover override, then starts.
- `npm run start:multisite` copies the multisite profile into
  `.wp-env.override.json`, then starts.
- `npm stop` / `npm run stop:multisite` both remove the override on the
  way out, so the next start defaults to single-site.

Because both profiles use the same Docker project, **only one profile
runs at a time** (port 8888 in either case). To switch, stop the running
profile first; the cached containers/images are reused, so the swap is
fast.

## Repository layout

```text
debi-payment-for-woocommerce/
├── debi-payment-for-woocommerce.php   # plugin entry (placeholder — being rewritten)
├── debi.php                           # placeholder
├── class-wc-debi.php                  # placeholder
├── uninstall.php                      # placeholder
├── languages/                         # translations
├── README.txt                         # WordPress.org-facing readme
├── README.md                          # this file
│
├── .wp-env.json                       # default workbench (single-site)
├── .wp-env.multisite.json             # opt-in multisite profile
├── package.json                       # npm: wp-env + wp-scripts (dev only)
├── composer.json                      # composer: dev tools + future debi-php
├── phpcs.xml.dist                     # WordPress + PHPCompatibility ruleset
├── phpunit.xml.dist                   # smoke test config
├── .distignore                        # what NOT to ship to WordPress.org
│
├── bin/                               # dev-only — excluded from release ZIP
│   ├── seed.php                       # idempotent seeder (auto-detects multisite)
│   └── fixtures.json                  # declarative seed data
│
├── tests/                             # dev-only — excluded from release ZIP
│   ├── bootstrap.php
│   └── unit/SmokeTest.php
│
└── .github/workflows/
    ├── ci.yml                         # PHPCS + PHPUnit + Plugin Check
    └── release.yml                    # manual: WP.org SVN + GitHub Releases
```

## Release pipeline

`.github/workflows/release.yml` is deliberately **inert** during this
refactor phase (it only runs on `workflow_dispatch`). It already implements
the full build:

1. `composer install --no-dev --optimize-autoloader` (bundles `debi-php` once
   it is added as a Composer dependency)
2. `npm ci && npm run build` (bundles `debi-js` once it has a `src/` entry
   point)
3. `10up/action-wordpress-plugin-deploy` to push the filtered tree (per
   `.distignore`) to WordPress.org SVN
4. `softprops/action-gh-release` to attach the same ZIP to a GitHub Release

When the plugin rewrite is done and ready for a release:

1. Add the WordPress.org SVN credentials as `WP_ORG_SVN_USERNAME` /
   `WP_ORG_SVN_PASSWORD` secrets in the repo settings.
2. Flip the workflow trigger to `push: tags: ["v*"]`.
3. Tag and push: `git tag v1.2.0 && git push --tags`.

## Contributing

1. Branch off `refactor` (or `main` once the rewrite has landed).
2. `npm run lint && npm test` should be green before opening a PR.
3. CI runs PHPCS, PHPUnit (PHP 7.4 + 8.1, smoke suite only for now), and the
   official [Plugin Check](https://wordpress.org/plugins/plugin-check/)
   action. A full WP-version + single/multisite matrix lands together with
   real integration tests in the rewrite.

## License

GPL-2.0-or-later — see [`README.txt`](README.txt) for full terms.
