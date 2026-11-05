=== Zorem AI Agent Connector ===
Contributors: zorem
Tags: ai, agents, mcp, claude, chatgpt
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT and other AI apps to WordPress safely, through one agent with its own identity and safety switches.

== Description ==

**Zorem AI Agent Connector** lets an AI app such as Claude, ChatGPT or Codex work on your WordPress site through an agent with its own identity and its own safety switches. The AI app never logs in as you: it connects to the agent's own MCP address, sees only the tools the agent is allowed, and anything else it asks for is refused.

The plugin sets up an **Admin Assistant** agent the moment you activate it. You decide which of its tools it may use, issue or replace its credentials, and switch it off whenever you want. Paste its address into your AI app, approve one sign-in screen, and you are connected — no token to copy, no file to edit. It is built on the WordPress Abilities API and the official MCP Adapter.

**Built on the WordPress Abilities API** · **Works without WooCommerce** · **Free & Open Source**

= How It Works =

1. Activate the plugin. Your Admin Assistant agent is set up, holding the WordPress tools this plugin adds, with delete tools switched off.
2. Copy the agent's MCP address from its Connection tab and add it to your AI app as a custom connector.
3. Approve the sign-in on your site as an administrator. The agent's tools appear in your chats, and you ask in plain words: "Draft a post about our summer sale", "Reply to the newest comment", "Add the About page to the main menu".

Every request goes through the agent. It runs as its own hidden WordPress account, is lent only the capabilities a tool needs for that one call, and never the ones that change users, plugins or site code.

= What's Included =

* **Ready on activation** — Your Admin Assistant agent is set up for you. There is nothing to configure before you connect.
* **Connect by signing in** — Add the agent's address to claude.ai, Claude Desktop, ChatGPT or any client with an "add custom connector" box, then approve one screen. No token is copied by hand.
* **Token for apps that cannot sign in** — Codex and similar apps connect with a token you issue on the agent's Connection tab. It is shown once and stored only as a hash.
* **43 WordPress tools** — Posts, pages, categories and tags; comments; the media library; the user list (read only); navigation menus; custom post types and their terms; and site tools such as statistics, post meta, active plugins, search and replace, and clearing the cache.
* **Exact permissions per tool** — Choose None, Read or Write for every tool, with Grant all, All read and Revoke for a whole section at once.
* **Automatic risk ratings** — Every tool is rated Read, Write or Destructive, so you can see at a glance what each one can do.
* **Destructive switch** — Tools that move content to the trash work only after you turn on the agent's own destructive switch. It is off by default.
* **Personal-data masking** — Names, emails, phone numbers and addresses are replaced with [masked] for an agent that does not act for the administrator.
* **Safe defaults** — New content is saved as a draft unless the agent asks to publish, and deleting only moves to the trash.
* **Status screen** — Versions, database tables, the MCP Adapter and anything that needs a look, with diagnostics you can copy into a support ticket.
* **Private by default** — No tracking, no telemetry and no call to a Zorem server.

= What It Keeps Safe =

Connecting an AI app with an administrator's password gives it everything. Zorem AI Agent Connector gives it only what it needs:

* **No shared passwords** — the AI app connects as the agent, through its own address, never as you.
* **No access to users, plugins or site code** — those capabilities are never lent to an agent, and there is no tool that changes the agent's own permissions.
* **No surprise deletions** — destructive tools stay off until you switch them on, and deleting only moves to the trash.
* **No accidental publishing** — new posts and pages are saved as drafts unless the agent asks to publish.
* **No customer data where it does not belong** — personal data is masked for an agent that does not act for the administrator.
* **One click to stop it** — revoke the agent and its token and sign-ins stop working at once; its permissions are kept so you can reactivate it.

= Works With =

* **Claude** — claude.ai and Claude Desktop, by signing in
* **ChatGPT** — with developer mode turned on, by signing in
* **Codex** — with the agent's token
* **Cursor, VS Code, Claude Code** and other MCP clients — by signing in, or with the agent's token

Signing in needs your site to be served over HTTPS. Without it, connect with the agent's token.

= Documentation & Support =

* 💬 [WordPress.org support forum](https://wordpress.org/support/plugin/zorem-ai-agent-connector/)

== Bundled third-party code ==

This plugin bundles the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), which serves the agent's MCP address on top of the WordPress Abilities API. It is copyright the WordPress contributors and licensed GPL-2.0-or-later, the same licence as this plugin; the full text ships with it in `vendor-bundled/mcp-adapter/LICENSE.md`. It is bundled rather than required as a separate plugin so this one works on activation.

The admin screens use Zorem's own ZUI component library (`assets/zui/`), also GPL-2.0-or-later.

== Privacy ==

This plugin sends no data anywhere by default.

**The agent you connect.** An agent reaches your site only through its own MCP address, after you approve its sign-in or with the token you issued. What it receives is exactly what its permissions allow, and it goes to the app the agent runs in, such as Claude Desktop, and to that app's AI provider. Names, emails, phone numbers and addresses are masked for an agent that does not act for the administrator, unless you turn masking off for it. Revoke the agent and nothing more is sent. The plugin keeps no log of the agent's calls.

== External services ==

**An MCP client's own address (only while a client is signing in).** When a client starts connecting by sign-in and identifies itself by a web address (a "client ID metadata document", as claude.ai does), the site fetches that one document, over https only, to learn the client's name and where to send the person back; nothing about your site or its users is sent, and private, local and non-standard addresses are refused. The sign-in itself only works on an https site.

That document belongs to the AI app's own publisher, not to Zorem, so the terms that apply to it are theirs.

No other external service is contacted. There is no tracking and no telemetry, and no call to a Zorem server. Zorem's own [Terms of Service](https://www.zorem.com/terms-of-service/) and [Privacy Policy](https://www.zorem.com/privacy-policy/) cover the zorem.com website and are listed here for completeness; this plugin does not contact it.

The agent you connect is not an external service of this plugin: it calls your site, with the sign-in you approved or the token you issued, and receives only what you allowed.

== Installation ==

1. Go to **Plugins > Add New** in your WordPress admin and search for "Zorem AI Agent Connector".
2. Click **Install Now**, then **Activate**.
3. Go to **Zorem AI Agent Connector > Agent**. Your Admin Assistant is there, holding the WordPress tools this plugin adds.
4. On its **Connection** tab, copy the MCP address into your AI app (for example claude.ai > Settings > Connectors > Add custom connector) and approve the sign-in.
5. Under **Access** on the same tab, turn destructive tools or data masking on or off at any time; the change applies on the agent's next request.

Alternatively, upload the `zorem-ai-agent-connector` folder to `/wp-content/plugins/` and activate it through the Plugins menu.

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is the open standard AI apps use to call tools on other systems. Zorem AI Agent Connector gives the agent its own MCP address on your site, served by the official WordPress MCP Adapter.

= Which AI apps can I connect? =

Claude (claude.ai and Claude Desktop) and ChatGPT connect by signing in. Codex connects with a token. Cursor, VS Code, Claude Code and other MCP clients connect by signing in or with a token.

= Why does sign-in say my site is not served over HTTPS? =

AI apps only sign in to servers that use HTTPS. On a site without it, issue a token on the agent's Connection tab and connect with that instead.

= Do I need to copy a token or edit a config file? =

No, for apps that sign in: you paste the agent's address and approve one screen. A token is only needed for apps that cannot sign in, such as Codex.

= Can the agent delete my content? =

Only if you turn on its destructive switch, and even then deleting only moves posts and pages to the trash, where you can restore them.

= Will the agent publish posts without asking? =

New posts and pages are saved as drafts unless the agent asks to publish them.

= Can the agent change its own permissions? =

No. There is no tool for it, and the capability to manage Zorem AI Agent Connector is never lent to an agent.

= Can the agent change users, plugins or site code? =

No. Those capabilities are never lent to an agent, and the user list is read only.

= Can I limit which tools the agent uses? =

Yes. Open the agent’s Permissions tab and set None, Read or Write for every tool, one at a time or a whole section at once. The Admin Assistant starts with the WordPress tools this plugin adds, and delete tools stay off until you turn on its destructive switch.

= Can I connect more than one AI app? =

Yes. Every app you connect signs in as the Admin Assistant and gets exactly the tools you allowed it, so there is one place to look to see what any connected app can do, and one switch that stops all of them.

= Can I see what the agent did? =

This plugin keeps no log of the agent's calls — nothing is written to your database and nothing is sent anywhere. What the agent changed shows up where you would normally look: a post's revisions, the comment it replied to, the menu it edited.

= How do I disconnect an AI app? =

Remove the connector in the app, or revoke the agent on its Connection tab. Revoking stops its token and sign-ins at once; its permissions are kept, so you can reactivate it later.

= Does it work without WooCommerce? =

Yes. Zorem AI Agent Connector works on any WordPress site and needs no other plugin.

= Does it send my data anywhere? =

No. There is no tracking, no telemetry and no call to a Zorem server. The AI app you connect receives only what the agent is allowed — see Privacy above.

= Can tools from my other plugins be used too? =

Yes. Any plugin that registers abilities with the WordPress Abilities API appears on the Permissions tab as soon as it is active, rated like the rest and granted to nothing until you grant it.

== Changelog ==

= 1.0.1 =
* Change - The agent factory itself refuses to make a second agent, so one agent is a property of the plugin rather than of the one caller that asks for it.
* Fix - The admin stylesheet asked the browser for nine component files that are not shipped, so every admin page load produced nine 404s.
* Change - The admin menu item is now "AI Agent Connector", which fits the menu column without wrapping onto three lines.
* Change - The Status tab leads with four tiles that each say something — the plugin version, the agent, the MCP endpoint and health — instead of leaving the row half empty.
* Fix - Posting a setting this plugin does not define wrote it into the options row anyway.
* Fix - Grant all, All read and Revoke on the Permissions tab did nothing when clicked.
* Change - The sign-in approval screen states which agent a client connects as instead of offering a choice that does not exist.
* Change - Wording in the admin screens and the readme describes this plugin and its agent, and nothing else.
* Change - The shared agent-management capability is kept on uninstall through the `ai_agent_os_keep_manage_capability` filter instead of looking for another plugin on disk.
* Remove - Admin stylesheet rules and a script for screens this plugin does not ship.
* Change - Every tool now checks the specific post, page, comment or term it was given, instead of only the caller's role, and custom post types and taxonomies are checked against their own capabilities rather than the built-in ones.
* Change - Tools registered by your other plugins appear on the Permissions tab as soon as the plugin is active, granted to nothing until you grant them.
* Change - Issuing a credential for the agent, giving it a new MCP address, and changing its destructive, masking or acts-for settings now need more than the agent-management capability alone.
* Change - Every parameter of a sign-in request is sanitised and validated as it arrives, and the unused `scope` parameter is no longer accepted or carried.
* Fix - A sign-in `state` containing a reserved character was handed back to the app double-encoded.
* Fix - The agent's hidden WordPress account was listed on the Users screen again if anything changed its role. It is now identified by the marker this plugin writes, so a role change no longer reveals it, the "All" count stays right, and uninstall still recognises the account and removes it.
* Remove - The activity and usage tables, which were created but never written to, and the unused CSV helper that went with them.

= 1.0.0 - 2026-10-27 =
* Add - First release on WordPress.org: an Admin Assistant agent, created on activation, with its own hidden WordPress account and its own MCP address.
* Add - 43 WordPress tools: posts, pages, categories and tags; comments; the media library; the user list (read only); navigation menus; custom post types and their terms; site statistics, post meta, active plugins, search and replace, and clearing the cache.
* Add - Connect Claude and ChatGPT by signing in, with no token to copy; Codex and other MCP apps with a token that is shown once and stored only as a hash.
* Add - Automatic Read, Write and Destructive risk rating for every tool.
* Add - Destructive switch, off by default; deleting only moves to the trash.
* Add - Personal-data masking for an agent that does not act for the administrator.
* Add - Agent expiry, revoke and reactivate.
* Add - Settings to close the MCP endpoint and to choose what happens on uninstall.
* Add - Status tab with versions, health checks and diagnostics to copy into a support ticket.
* Add - None, Read or Write permissions for every tool, per section or all at once.
