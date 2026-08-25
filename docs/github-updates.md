# GitHub updates for Commerce Documents

The plugin can receive checksum-verified updates from the private GitHub
repository xmods97/commerce-documents-kit. The updater uses the latest GitHub
Release and requires these two release assets:

- commerce-documents-woocommerce.zip
- commerce-documents-woocommerce.zip.sha256

The repository's release workflow publishes the ZIP and checksum automatically
when a semantic version tag is pushed, for example `v0.3.1`. The tag must match
both the plugin header version and `Plugin::VERSION`.

## Server configuration

Put the read-only GitHub token only in wp-config.php on the target WordPress
installation, before the line that says "That's all, stop editing":

    define('COMMERCE_DOCUMENTS_GITHUB_TOKEN', 'github_pat_...');

The token must be able to read repository contents and releases only. Never put
it in Git, a plugin file, a WordPress option, or the database.

If the constant is absent, the plugin remains fully functional but does not
contact GitHub and no update is offered.

## Release flow

1. Update the plugin header version and Plugin::VERSION.
2. Run the local tests and build as a preflight.
3. Push a matching tag, for example `git push origin v0.3.1`.
4. GitHub Actions runs the tests, builds the ZIP/checksum, verifies the package,
   and publishes the GitHub Release with both assets.
5. WordPress polls the latest Release and offers the update on the normal
   Plugins or Updates page. The active plugin opts into WordPress automatic
   updates once a verified release response is available.

The branch itself is not an update channel. A release tag and its two assets are
required before WordPress can offer an update.

Before installation, the updater verifies the ZIP SHA-256 against the
authenticated GitHub checksum asset. A missing token, wrong asset name, invalid
