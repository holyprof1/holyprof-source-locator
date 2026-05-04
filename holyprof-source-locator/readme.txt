=== Holyprof Source Locator ===
Contributors: holyprof1
Tags: admin, search, code search, developer tools, debugging
Requires at least: 6.0
Requires PHP: 7.4
Tested up to: 6.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Holyprof Source Locator helps WordPress admins find where features, hooks, templates, text, CSS, JS, and plugin/theme settings pages are located.

== Description ==

Holyprof Source Locator helps WordPress admins find both where something is defined in code and where that same feature is configured inside WordPress admin, plugin settings, or theme settings.

Search across theme files, functions.php, plugin code, admin menu pages, submenu pages, registered settings sections, registered setting fields, and safe setting names to quickly locate either the source code or the likely settings page behind a feature.

Admin/settings detection is based on safe registered WordPress admin data such as menu titles, page titles, slugs, section labels, field labels, and registered setting names. It does not edit settings or expose stored option values.

Examples:

* Search `sitemap` to find sitemap-related source files and likely settings pages.
* Search `breadcrumb` to find breadcrumb settings/admin pages and source files.
* Search `add_action` to find hook usage in plugin/theme PHP files.
* Search `functions.php` to locate active theme functions.php files first.

Owned and maintained by Tobi Holyprof.

Source code repository: https://github.com/holyprof1/holyprof-source-locator

Features include:

* Read-only search across the plugin itself, the active theme, the parent theme when a child theme is active, and active plugins
* Focus on PHP source, `functions.php`, templates, hooks, CSS, JS, and likely plugin/theme settings pages
* Best Matches that can point to likely admin/settings pages for features like sitemap, breadcrumbs, SMTP, cache, analytics, SEO, checkout, and more
* Grouped results with source names, file paths, line numbers, highlighted snippets, and copy actions
* Open settings page actions when a safe admin URL can be constructed
* AJAX-powered search to keep the admin page responsive
* Safety limits for large files, skipped dependency folders, unreadable files, and oversized result sets

Holyprof Source Locator is admin-only and read-only. It does not edit files or settings.

== Limitations ==

Holyprof Source Locator is intentionally limited in v1.

* It is focused on source-code locations in active themes and plugins.
* It does not search posts or pages.
* Exact settings page detection depends on how plugins register admin pages, menu pages, settings sections, setting fields, and option names.
* Some JavaScript-rendered admin screens may not expose searchable labels or setting text.
* Frontend pages, posts, and crawled frontend URLs are not searched.
* It does not inspect private option values or expose secrets such as API keys, tokens, passwords, or SMTP credentials.
* It does not search database content outside what WordPress exposes through registered admin/menu/settings metadata.

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

= Can it help me find plugin settings pages for features like sitemap or breadcrumb? =

Yes. Holyprof Source Locator can surface likely plugin/theme settings pages, admin menu pages, registered settings sections, field labels, and admin page matches for feature searches such as `sitemap`, `breadcrumb`, `smtp`, `cache`, `analytics`, `seo`, `checkout`, and related terms.

= Does it search posts or pages? =

No. Holyprof Source Locator is focused on source files in active themes and plugins.

= Can it still show related settings pages or labels? =

Yes. Holyprof Source Locator can also surface related admin menu pages, submenu pages, registered settings, settings sections, field labels, setting names, and likely feature settings locations where WordPress or active plugins expose them.

= Can it find every visible text string on admin pages? =

No. Holyprof Source Locator relies on registered admin/menu/settings data such as menu titles, page titles, slugs, section labels, field labels, and registered setting names. It does not guarantee that every rendered label or every JavaScript-driven screen can be traced.

== Screenshots ==

1. The Holyprof Source Locator admin search screen.
2. Best Matches shown for a feature search such as sitemap or breadcrumb.
3. Grouped file matches with file paths, line numbers, hook details, copy actions, and highlighted snippets.

== Changelog ==

= 1.0.0 =

* Initial release
* Admin-only read-only finder for source code locations in active themes and plugins
* AJAX results loading
* Search safety guards for large files and noisy folders
