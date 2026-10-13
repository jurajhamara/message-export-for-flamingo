# Message Export for Flamingo

A WordPress plugin that exports Contact Form 7 messages saved by Flamingo to
an Excel file (.xlsx). Choose the forms and the period, get one readable row
for each message.

The plugin is being submitted to the WordPress.org plugin directory. The full
description for users is in [readme.txt](readme.txt).

## How it works

The plugin adds an Export page to the Flamingo menu, for users who can see
Flamingo's Inbound Messages.

- Filters: forms (one, several or all), period, sender e-mail, text, and
  optionally spam.
- The download button shows how many messages the file will have.
- Column names come from the field labels in the Contact Form 7 forms. Fields
  with the same label in different forms share one column.
- The first column is the date of sending as a real date, in the site's time
  zone and date format. Every other value is written as text, so nothing runs
  as a formula.

The plugin only reads Flamingo data. It does not change or delete messages,
has no settings and saves nothing in the database.

## Requirements

- WordPress 6.7 or later, PHP 7.4 or later (the same as Flamingo)
- Flamingo
- Contact Form 7 is optional. Without it, columns are named by the field
  names and follow the order in the messages.
- The PHP zip extension. The .xlsx file is written by the plugin itself with
  `ZipArchive`, there is no bundled library.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the
   Plugins screen in your WordPress admin once it is on WordPress.org.
2. Activate it through the Plugins screen.
3. Open Flamingo > Export.

When zipping for a manual install, the plugin must sit inside a folder named
`message-export-for-flamingo`, the WordPress.org slug.

## Repository layout

The plugin files live at the repository root so the deploy workflow can push
them straight to the WordPress.org SVN trunk.

- `message-export-for-flamingo.php` - main file: dependency check, filters,
  Export page, download
- `includes/export.php` - reading messages, field labels, columns
- `includes/class-meff-xlsx-writer.php` - the .xlsx writer, knows nothing
  about Flamingo
- `assets/` - CSS and JavaScript of the Export page
- `languages/` - the `.pot` template plus a `.po` and compiled `.mo` per locale
- `readme.txt` - the file WordPress.org reads
- `README.md` - this file (GitHub only, excluded from the WordPress.org deploy)
- `dev/` - scripts for a local test site with made-up messages (excluded
  from the WordPress.org deploy)
- `.github/workflows/deploy.yml` - deploy to WordPress.org on tag push
- `.distignore` - files kept out of the WordPress.org deploy

## Releasing

1. Bump the version in three places, all must match: the `Version:` header in
   `message-export-for-flamingo.php`, `Stable tag:` in `readme.txt`, and the
   git tag (for example `1.0.0`, with no `v` prefix).
2. Update `Tested up to:` in both files once verified against the latest
   WordPress release.
3. Commit, then create and push the tag. The GitHub Action deploys to the
   WordPress.org SVN automatically. Do not commit to SVN by hand.

The workflow needs two repository secrets: `SVN_USERNAME` and `SVN_PASSWORD`
(the WordPress.org account credentials).

## License

GPLv2 or later. See [LICENSE](LICENSE).
