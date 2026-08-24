# GitHub updates for Commerce Documents

The plugin can receive checksum-verified updates from the private GitHub
repository xmods97/commerce-documents-kit. The updater uses the latest GitHub
Release and requires these two release assets:

- commerce-documents-woocommerce.zip
- commerce-documents-woocommerce.zip.sha256

The ZIP and checksum are produced by tools/build-woocommerce.ps1. The ZIP
must be attached to a versioned GitHub Release whose tag is a semantic version,
for example v0.3.1.

## Server configuration

Put the read-only GitHub token only in wp-config.php on the target WordPress
installation, before the line that says "That's all, stop editing":

    define('COMMERCE_DOCUMENTS_GITHUB_TOKEN', 'github_pat_...');

The token must be able to read repository contents and releases only. Never put
it in Git, a plugin file, a WordPress option, or the database.

If the constant is absent, the plugin remains fully functional but does not
contact GitHub and no update is offered.

## Release checklist

1. Update the plugin header version and Plugin::VERSION.
2. Run tools/build-woocommerce.ps1.
3. Create a GitHub Release with a matching semantic tag.
4. Attach both generated files from dist/ without renaming them.
5. In WordPress, open the normal Plugins or Updates page and install the offered
   Commerce Documents update.

Before installation, the updater verifies the ZIP SHA-256 against the
authenticated GitHub checksum asset. A missing token, wrong asset name, invalid
