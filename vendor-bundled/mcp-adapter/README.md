# Bundled copy — do not edit

[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), bundled here so this plugin
works without asking anyone to install a second plugin. It bridges the WordPress Abilities API to
the Model Context Protocol and serves each agent's MCP address.

Copyright the WordPress contributors. Licensed **GPL-2.0-or-later**, the same licence as this
plugin; the full text is in `LICENSE.md`.

Changed from upstream: the text domain of its translatable strings is rewritten to this plugin's
(`bin/sync-libs.mjs`), and `?:` short ternaries and doc-comment spacing are corrected to pass the
WordPress Coding Standards. Edit the library upstream and bundle it again; changes made here are
overwritten.
