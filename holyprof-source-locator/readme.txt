=== Holyprof Source Locator ===
Contributors: holyprof1
Tags: admin, search, code search, developer tools, debugging
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find where code, hooks, templates, and text are defined in your themes and plugins.

== Description ==

Holyprof Source Locator helps you find where features, hooks, templates, and text are defined in your active themes and plugins. Search across theme files, functions.php, and plugin code to quickly locate the source of functionality or visible output. This tool is designed for developers and site builders who need to trace how something is implemented inside WordPress.

Owned and maintained by Tobi Holyprof.

Source code repository: https://github.com/holyprof1/holyprof-source-locator

Features include:

* Read-only search across the plugin itself, the active theme, the parent theme when a child theme is active, and active plugins
* Focus on PHP source, `functions.php`, templates, hooks, CSS, and JS
* Grouped results with source names, file paths, line numbers, and highlighted snippets
* AJAX-powered search to keep the admin page responsive
* Limited secondary references for related admin pages, menus, and settings when WordPress exposes them
* Safety limits for large files, skipped dependency folders, unreadable files, and oversized result sets

Holyprof Source Locator does not edit files or settings. It is designed as an admin-only inspection tool.

== Limitations ==

Holyprof Source Locator is intentionally limited in v1.

* It is focused on source-code locations in active themes and plugins.
* It does not search posts or pages.
* It does not guarantee every visible text string will be found.
* It does not guarantee coverage of every JavaScript-rendered admin screen.
* It does not search database content outside what WordPress exposes through registered admin menus and settings metadata.
* Admin-page, menu, and settings references are secondary clues and depend on what WordPress or plugins expose to PHP at search time.

== Installation ==

1. Upload the `holyprof-source-locator` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Open `Holyprof Source Locator` from the WordPress admin menu.

== Frequently Asked Questions ==

= Does this plugin edit any files? =

No. Holyprof Source Locator is read-only.

= Who can use the search page? =

Only users with the `manage_options` capability can access and run searches.

= What files are searched? =

The plugin searches supported text-based files in:

* the Holyprof Source Locator plugin directory
* the active theme
* the parent theme when a child theme is active
* active plugins

= Does it search posts or pages? =

No. Holyprof Source Locator is focused on source files in active themes and plugins.

= Can it still show related settings pages or labels? =

Yes. Holyprof Source Locator can also surface related admin menu pages, submenu pages, registered settings, settings sections, and field labels where WordPress exposes them, but that is secondary to the source-code search.

= Can it find every visible text string on admin pages? =

No. The plugin can often help with plugin admin-page references, but it does not guarantee every rendered label or every JavaScript-driven screen can be traced in v1.

== Screenshots ==

1. The Holyprof Source Locator admin search screen.
2. Grouped search results with file paths, match counts, and highlighted snippets.

== Changelog ==

= 1.0.0 =

* Initial release
* Admin-only read-only finder for source code locations in active themes and plugins
* AJAX results loading
* Search safety guards for large files and noisy folders
