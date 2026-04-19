# scrt.link for WordPress

A WordPress plugin that adds a single Gutenberg block — **Send me a secret** — so visitors can send you end-to-end encrypted, self-destructing messages via [scrt.link](https://scrt.link).

Encryption happens in the visitor's browser. WordPress forwards only ciphertext to scrt.link (authenticated with your API key), and the resulting one-time URL is emailed to your notification address. WordPress never sees plaintext.

## How it works

```
[visitor]  → browser encrypts →  [WP REST proxy]  → adds Bearer token →  [scrt.link]
                                        ↓
                                   wp_mail() the self-destructing link
                                        ↓
                                     [you]
```

1. Visitor fills the form. `view.js` (Interactivity API view module) loads scrt.link's client crypto module from your configured base URL and encrypts locally.
2. Ciphertext is POSTed to `/wp-json/scrt-link/v1/submit` with a WordPress REST nonce.
3. PHP verifies nonce, enforces a per-IP rate limit, adds the site's scrt.link Bearer token, and forwards to `POST {baseUrl}/api/v1/secrets`.
4. scrt.link returns `{ secretLink, receiptId, expiresAt }`. PHP emails the link to the site's notification address. Visitor sees a success message.

## Architecture

```
scrt-link-wp/
├── scrt-link-wp.php          # Plugin bootstrap (header + requires)
├── uninstall.php             # delete_option on uninstall
├── includes/
│   ├── class-plugin.php      # Singleton bootstrap, option helpers
│   ├── class-settings.php    # Settings API page (options-general.php?page=scrt-link-wp)
│   └── class-rest.php        # REST proxy with nonce check + rate limiting
├── src/
│   └── scrt-request/
│       ├── block.json        # apiVersion: 3, viewScriptModule, dynamic render
│       ├── index.js          # registerBlockType (save: null — dynamic)
│       ├── edit.js           # Editor UI with InspectorControls
│       ├── view.js           # Interactivity API store + actions (compiled as ES module)
│       ├── render.php        # Dynamic PHP render with data-wp-* directives
│       ├── style.scss        # Frontend + editor shared styles
│       └── editor.scss       # Editor-only styles
├── build/                    # Generated — do not commit
└── package.json              # wpPlugin key aligned with @wordpress/build schema
```

## Modern stack alignment

Per the April 2026 [next-generation WordPress plugin build tooling article](https://developer.wordpress.org/news/2026/04/wordpress-build-the-next-generation-of-wordpress-plugin-build-tooling/), `@wordpress/build` is the future but is **not yet recommended for single-block plugins**. We therefore:

- Use `@wordpress/scripts` today with the standard `src/` source layout (zero-config, production-tested).
- Build with `--experimental-modules` so `viewScriptModule` entries are compiled as ES modules in the same pass.
- Declare a `wpPlugin` key in `package.json` aligned with the future `@wordpress/build` schema (name, scriptGlobal, packageNamespace, handlePrefix).
- Use `apiVersion: 3` in `block.json` (required for WordPress 7.0's iframed post editor).
- Use `viewScriptModule` (ES module) rather than classic `viewScript`.
- Use the Interactivity API (`@wordpress/interactivity`) for frontend state instead of hand-rolled React hydration.

When `@wordpress/build` stabilizes for single-block plugins, migration is a rename from `src/` → `blocks/` and swapping `wp-scripts build --experimental-modules` for `wp-build`. The block.json already matches the future conventions.

### Why we haven't migrated yet (April 2026)

Probed `@wordpress/build@0.12.0` directly. It's built for **multi-package plugins like Gutenberg** (`packages/<name>/` monorepo layout, custom globals via `window.myPlugin.foo`, admin `pages`, `routes/`). For a single-block plugin it's a net loss today:

- **Blocks aren't auto-discovered yet** — the article references Gutenberg [issue #74542](https://github.com/WordPress/gutenberg/issues/74542), which is still open.
- The tool auto-generates `build/build.php` for script/module registration, but **does not handle `register_block_type`**. We'd have to hand-wire block registration and use `wpCopyFiles` to stage `block.json` / `render.php`.
- The view module compiles to `build-module/<pkg>/view.mjs` while `block.json` lives next to the editor script — a relative-path mismatch the tool doesn't bridge.

Tracking: [issue #1](https://github.com/mikezielonkadotcom/scrt-link-wp/issues/1). Revisit when `@wordpress/build` ≥ v1.0 or when block auto-discovery lands (whichever comes first).

## Security model

- **API key**: site option, `manage_options` capability-gated, never serialized into block markup or emitted to the browser. The settings field is a password input with a "leave blank to keep current" affordance.
- **REST proxy**: `POST /wp-json/scrt-link/v1/submit` requires a valid `X-WP-Nonce` (`wp_rest` action). Anonymous visitors receive this nonce via the block's `data-wp-context`, which expires with the page.
- **Rate limiting**: per-IP transient, configurable (default 5 submissions/hour). HTTP 429 on overflow.
- **Input sanitization**: `sanitize_text_field`, `sanitize_email`, `esc_url_raw`, `absint` on all settings. Block attributes validated by block.json schema.
- **Output escaping**: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` on user-editable text. `get_block_wrapper_attributes()` for the dynamic wrapper.
- **Uninstall**: `uninstall.php` removes the single `scrt_link_wp_options` option.

## Development

```bash
npm install
npm run start   # watch mode
npm run build   # production build → build/
npm run lint:js
npm run format
npm run plugin-zip   # release artifact
```

## Releases

Tag-triggered via [mikezielonkadotcom/wp-github-plugin-actions](https://github.com/mikezielonkadotcom/wp-github-plugin-actions). Bumping + tagging is all that's needed:

```bash
# 1. Bump Version: in scrt-link-wp.php and SCRT_LINK_WP_VERSION constant
# 2. Bump "Stable tag" in readme.txt
# 3. Commit
git commit -am "chore: bump to 0.2.0"
# 4. Tag and push
git tag -a v0.2.0 -m "Release 0.2.0"
git push && git push origin v0.2.0
```

The [release workflow](.github/workflows/release.yml) runs `npm ci && npm run build`, then zips everything not listed in [`.distignore`](.distignore), then creates a GitHub Release with the zip attached.

Zip invariants (per the wp-github-plugin-actions release standards):
- **Includes:** `build/`, `includes/*.php`, `src/**/*.php`, `scrt-link-wp.php`, `uninstall.php`, `readme.txt`, `README.md`, `LICENSE`
- **Excludes:** `node_modules/`, `src/**/*.{js,jsx,ts,tsx,scss,css}`, `dev/`, `.wp-env.json`, `composer.json`, `.git*`, `.github/`

## WP-CLI

Three commands for scriptable configuration and smoke-testing (requires the plugin to be active):

```bash
wp scrt-link status                                    # show current config (key presence, base URL, expiry, rate limit)
wp scrt-link set-key ak_live_abc…                      # store the API key (scriptable deploys, CI)
wp scrt-link test                                      # full encrypt → POST → print secret link → email owner
wp scrt-link test --message="hi" --expires-in=3600000  # customize the test message + TTL
wp scrt-link test --skip-mail --dump-payload           # offline-ish: dump the JSON before POSTing
```

`wp scrt-link test` re-implements the view.js crypto in PHP (OpenSSL AES-GCM + PBKDF2 + EC P-384), so the command validates the full pipeline — API key, base URL, upstream auth, email delivery — without a browser.

## Extensibility

Filters (all in `includes/class-rest.php` and `includes/class-plugin.php`):

| Hook | Signature | Use case |
|---|---|---|
| `scrt_link_wp_request_args` | `( array $args, string $payload_json, string $endpoint )` | Customize the `wp_remote_post` args (timeouts, proxies, extra headers) |
| `scrt_link_wp_rate_limit_skip` | `( bool $skip, string $ip )` | Allowlist IPs (office, load testing) |
| `scrt_link_wp_email_to` | `( string $to, string $link, string $note, string $expiry )` | Route notifications (e.g. by block instance, by time of day) |
| `scrt_link_wp_email_subject` | `( string $subject, string $link, string $note )` | Customize subject |
| `scrt_link_wp_email_body` | `( string $body, string $link, string $note, string $expiry )` | Switch to HTML, template with visitor info, inject branding |

Actions:

| Hook | Signature | Use case |
|---|---|---|
| `scrt_link_wp_secret_created` | `( string $secret_link, string $public_note, string $expires_at, array $upstream )` | Slack/Discord delivery, CRM logging, custom CPTs, webhooks |
| `scrt_link_wp_upstream_failed` | `( WP_Error\|null $error, int $http_status, mixed $body )` | Alerting, Sentry, ops notifications |

Example — ship a Slack notification instead of email:

```php
add_action( 'scrt_link_wp_secret_created', function ( $secret_link, $note, $expires_at ) {
    wp_remote_post( SLACK_WEBHOOK, [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( [
            'text' => sprintf( "New secret from %s — <%s|open one-time link> (expires %s)",
                $note ?: 'anonymous', $secret_link, $expires_at ),
        ] ),
    ] );
}, 10, 3 );
```

## Configuration

After activation, visit **Settings → scrt.link**:

| Field | Notes |
| --- | --- |
| API key | From your scrt.link account. Stored in `wp_options`; password field obscures existing value. |
| Base URL | Defaults to `https://scrt.link`. Point at your self-hosted deployment for white-label. |
| Notification email | Where self-destructing links are delivered. Defaults to the site admin email. |
| Default expiration | Milliseconds. Blocks may override per instance. |
| Rate limit | Max submissions per client IP per hour. |

## License

GPL-2.0-or-later.
