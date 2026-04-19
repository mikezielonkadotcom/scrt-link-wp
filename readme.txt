=== scrt.link for WordPress ===
Contributors:      mikezielonka
Tags:              block, encryption, privacy, secrets, scrt.link
Requires at least: 6.6
Tested up to:      6.9
Requires PHP:      7.4
Stable tag:        0.1.3
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Drop a block onto any page to let visitors send you end-to-end encrypted, self-destructing secrets via scrt.link.

== Description ==

`scrt.link for WordPress` ships a single Gutenberg block — **Send me a secret** — that turns any WordPress page into a personal, end-to-end encrypted drop-box powered by [scrt.link](https://scrt.link).

* **End-to-end encrypted.** Encryption happens in the visitor's browser using scrt.link's client crypto module. Your server and WordPress never see plaintext.
* **One-time.** Each submission becomes a self-destructing scrt.link URL delivered to your notification email.
* **White-label friendly.** Point the plugin at your own scrt.link deployment or stick with `https://scrt.link`.
* **Modern stack.** `apiVersion: 3`, Interactivity API (`viewScriptModule`), dynamic server-rendered block — ready to migrate to `@wordpress/build` when it stabilizes for single-block plugins.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/scrt-link-wp/` or install via the Plugins screen.
2. Activate the plugin.
3. Go to **Settings → scrt.link** and paste your scrt.link API key. Optionally configure a white-label base URL and notification email.
4. Add the **Send me a secret** block to any page.

== Frequently Asked Questions ==

= Does WordPress see the secret contents? =

No. The visitor's browser encrypts the payload before it's sent to WordPress. WordPress forwards the ciphertext to scrt.link, authenticated with your API key, and emails the resulting self-destructing URL to your notification address.

= Where is my API key stored? =

In the `wp_options` table (site-level option), only readable by users with `manage_options`. It is never emitted in block markup or sent to the browser.

= Can I use a self-hosted scrt.link instance? =

Yes. Set the base URL in **Settings → scrt.link** to your deployment.

== Changelog ==

= 0.1.3 =
* Verify self-update path via Git Updater (no code changes).

= 0.1.2 =
* Rename GitHub repo scrt.link-wp → scrt-link-wp (no dot) so the release folder name, main plugin file, and repo slug all match. Fixes Git Updater auto-update path.

= 0.1.1 =
* Tighten release zip — drop `/src` directory entirely (render.php and block.json already live in `/build`).

= 0.1.0 =
* Initial release.
