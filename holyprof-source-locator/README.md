# Holyprof Source Locator

Find where code, hooks, templates, and text are defined in WordPress themes and plugins.

## Description

Holyprof Source Locator helps you find where features, hooks, templates, and text are defined in active WordPress themes and plugins. It is an admin-only, read-only tool for tracing how functionality or visible output is implemented.

## Features

- Search across active theme files and active plugin files
- Find matches in PHP, templates, CSS, JS, and text-based files
- Show grouped results with file paths, line numbers, and snippets
- Surface related admin page and settings references when available
- Stay read-only and limited to the WordPress admin area

## How it works

The plugin scans supported files in the active theme, parent theme when applicable, and active plugins. It matches the search term against file contents and returns grouped results with the file path, line number, and a snippet for each match. For some searches, it can also show related admin menu pages and registered settings metadata exposed by WordPress.

## Installation

1. Download or clone this repository.
2. Place the `holyprof-source-locator` folder in `wp-content/plugins/`.
3. Activate the plugin from the WordPress admin.
4. Open `Holyprof Source Locator` from the admin menu.

## Screenshots

Screenshot placeholders:

- Admin search screen
- Grouped file results
- Related settings and admin page references

## Author

Tobi Holyprof  
https://tobi.holyprofweb.com

## License

GPLv2 or later
