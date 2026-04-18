=== HP Source Finder ===
Contributors: holyprof1
Tags: admin, search, code search, developer tools, debugging
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only finder for WordPress code, settings, menu pages, and labels.

== Description ==

HP Source Finder helps site administrators safely find code, settings, menu pages, and registered labels from the WordPress admin area.

Homepage: https://tobi.holyprofweb.com/hp-source-finder
Documentation: https://tobi.holyprofweb.com/hp-source-finder
Support: https://tobi.holyprofweb.com/hp-source-finder
Source code repository: https://github.com/holyprof1/hp-source-finder

Features include:

* Read-only search across the HP Source Finder plugin, active theme, parent theme, and active plugins
* Read-only lookup for admin menu pages, submenu pages, registered settings, settings sections, and field labels
* File-type filters for PHP, CSS, JS, and template files
* Grouped results with file paths, line numbers, and highlighted keyword matches
* AJAX-powered search to keep the admin page responsive
* Safety limits for large files, skipped dependency folders, unreadable files, and oversized result sets

HP Source Finder does not edit files. It is designed as an admin-only inspection tool.

== Installation ==

1. Upload the `hp-source-finder` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `HP Source Finder` from the WordPress admin menu.

== Frequently Asked Questions ==

= Does this plugin edit any files? =

No. HP Source Finder is read-only.

= Who can use the search page? =

Only users with the `manage_options` capability can access and run searches.

= What files are searched? =

The plugin searches supported text-based files in:

* the HP Source Finder plugin directory
* the active theme
* the parent theme when a child theme is active
* active plugins

= Can it find settings pages and labels too? =

Yes. HP Source Finder can also surface matching admin menu pages, submenu pages, registered settings, settings sections, and field labels where WordPress exposes them.

== Screenshots ==

1. The HP Source Finder admin search screen.
2. Grouped search results with file paths, match counts, and highlighted snippets.

== Changelog ==

= 1.0.0 =

* Initial release
* Admin-only read-only finder for code, settings, menu pages, and labels
* AJAX results loading
* Search safety guards for large files and noisy folders
