=== Zorem AI Agent Connector ===
Contributors: zorem,gaurav1092,eranzorem,yashpatel1007
Tags: mcp, mcp server, ai agent, ai assistant, model context protocol
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 5.0
WC tested up to: 11.3.0
Stable tag: 1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT and other AI apps to WordPress safely, through an AI agent with its own identity and safety switches.

== Description ==

**Zorem AI Agent Connector** lets an AI app such as Claude, ChatGPT or Codex work on your WordPress site through an agent with its own identity and its own safety switches. The AI app never logs in as you: it connects to the agent's private MCP address, sees only the tools the agent is allowed, and anything else it asks for is refused.

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
* **WooCommerce tools** — With WooCommerce active, the agent can look up orders and products, read and add order notes, change an order's status, and create, update or delete products, using WooCommerce's own tools. Each is granted to nothing until you grant it and is lent only the access its own check needs, for that call only. Customer details in orders are masked when the agent does not act for the administrator.
* **Website Brain** — your store policies, chosen pages, WooCommerce settings and installed plugins, ready for the agent to answer from. See below.
* **Exact permissions per tool** — Set each section to Off, View only or Full, or open it and grant permissions one at a time, each marked View, Change or Delete. "Show only what's on" hides everything you have not granted.
* **Automatic risk ratings** — Every tool is rated Read, Write or Destructive, so you can see at a glance what each one can do.
* **Destructive switch** — Tools that move content to the trash work only after you turn on the agent's own destructive switch. It is off by default.
* **Personal-data masking** — Names, emails, phone numbers and addresses are replaced with [masked] when the agent does not act for the administrator.
* **Safe defaults** — New content is saved as a draft unless the agent asks to publish, and deleting only moves to the trash.
* **Status screen** — Versions, database tables, the MCP Adapter and anything that needs a look, with diagnostics you can copy into a support ticket.
* **Private by default** — No tracking, no telemetry and no call to a Zorem server.

= Website Brain: What Your Agent Knows =

Without your own content, an AI app guesses. The **Website Brain** (**AI Agent Connector > Website Brain**) gives your agent what your site knows, so it answers from what you wrote:

* **Your pages and posts** — only the ones you tick, never drafts or password-protected pages.
* **Store policies and settings** — with WooCommerce, the policy pages you chose and your store settings. Never prices, stock, orders or customers.
* **Your installed plugins** — what each one does, read from its files without running its code.
* **Bundled documentation** — ready-made notes for big plugins such as WooCommerce. Nothing is downloaded.

The agent reads a plugin's knowledge only while it holds one of that plugin's tools, reads nothing while revoked, and never sees keys or passwords. It reads through three read-only knowledge tools you can switch off on its Permissions tab. The **Entries** tab shows exactly what it can read.

= What It Keeps Safe =

Connecting an AI app with an administrator's password gives it everything. Zorem AI Agent Connector gives it only what it needs:

* **No shared passwords** — the AI app connects as the agent, through its own address, never as you.
* **No access to users, plugins or site code** — those capabilities are never lent to the agent, and there is no tool that changes the agent's own permissions.
* **No surprise deletions** — destructive tools stay off until you switch them on, and deleting only moves to the trash.
* **No accidental publishing** — new posts and pages are saved as drafts unless the agent asks to publish.
* **No customer data where it does not belong** — personal data is masked when the agent does not act for the administrator.
* **One click to stop it** — revoke the agent and its token and sign-ins stop working at once; its permissions are kept so you can reactivate it.

= Who Uses Zorem AI Agent Connector =

* **Bloggers and publishers** — draft a post, fix a category or tidy tags from the chat.
* **Editorial sites** — triage comments and move drafts forward from one place.
* **Agencies** — one agent per client site, each with its own tools, revoked in a click.
* **Membership and course sites** — keep lessons, categories and menus in order without sharing an administrator password.
* **Developers** — connect Cursor, VS Code or Claude Code with the agent's token.
* **Sites with plugins that register abilities** — their tools appear on the Permissions tab, granted to nothing until you grant them.

**Please note:** this plugin controls what an AI app may do on your site. It cannot control what that app does with what it reads. Grant the smallest set of tools that does the job, leave the destructive switch off unless you need it, and treat the agent's address and token like a password.

= Works With =

* **Claude** — claude.ai and Claude Desktop, by signing in
* **ChatGPT** — with developer mode turned on, by signing in
* **Grok** — grok.com, by signing in (Settings → Connect Apps)
* **Codex** — with the agent's token
* **Cursor, VS Code, Claude Code** and other MCP clients — by signing in, or with the agent's token

Signing in needs your site to be served over HTTPS. Without it, connect with the agent's token.

= Go Further with AI Agent OS PRO =

Zorem AI Agent Connector gives you one fully featured agent, free. When AI does real work on your site every day, [AI Agent OS PRO](https://www.zorem.com/product/ai-agent-os/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=go-pro) adds:

* **Unlimited agents** — give Claude, ChatGPT, Codex or each team its own agent, with its own permissions, token and MCP address. Revoke one without touching the others.
* **Ready-made profiles** — start an agent as Store Admin, Support, Fulfillment, Supplier or Shopping Agent, with the right permissions already set.
* **Activity Log** — every call your agents make and every one that was refused, what was asked and what came back, kept for up to 365 days, with a 30-day summary and CSV export.
* **Plugin, publishing and SEO tools** — install, update and switch plugins, publish and schedule posts, and set SEO titles and descriptions, by asking.
* **Site-building tools** — 15 tools for the site title and tagline, homepage, logo, site icon, header menus, templates, colours and fonts, changed by asking.
* **Shopping agents** — approve the agents AI Shopping Assistant proposes for your customers, each with its own permissions and customer data masked.
* **Priority email support.**

Upgrading needs no setup: your Admin Assistant, its token, MCP address and permissions carry over.

👉 [Get AI Agent OS PRO](https://www.zorem.com/product/ai-agent-os/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=go-pro) · 📖 [AI Agent OS PRO documentation](https://docs.zorem.com/docs/ai-agent-os/)

= Documentation & Support =

* 📖 [Full documentation and setup guides](https://docs.zorem.com/docs/zorem-ai-agent-connector/)
* 📖 [AI Agent OS PRO documentation](https://docs.zorem.com/docs/ai-agent-os/)
* 💬 [WordPress.org support forum](https://wordpress.org/support/plugin/zorem-ai-agent-connector/)

= More Plugins by Zorem =

* [Advanced Shipment Tracking for WooCommerce](https://www.zorem.com/product/woocommerce-advanced-shipment-tracking/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=add-ons) — Shipment tracking for WooCommerce orders, trusted by 70,000+ stores.
* [SMS for WooCommerce](https://www.zorem.com/product/sms-for-woocommerce/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=add-ons) — Automated SMS order and shipping notifications via Twilio, WhatsApp, and 18 other providers.
* [Customer Email Verification PRO](https://www.zorem.com/product/customer-email-verification/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=add-ons) — Block fake accounts and spam orders with OTP-based email verification.
* [Advanced Local Pickup PRO](https://www.zorem.com/product/zorem-local-pickup-pro/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=add-ons) — Advanced local pickup with multiple locations, appointment scheduling, and ready-for-pickup notifications.
* [Zorem Returns](https://www.zorem.com/product/zorem-returns/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=add-ons) — Self-service returns, exchanges, and store credit management for WooCommerce.

Explore the full catalog at [zorem.com →](https://www.zorem.com/)

== Privacy ==

This plugin sends no data anywhere by default.

**The agent you connect.** The agent reaches your site only through its MCP address, after you approve its sign-in or with the token you issued. What it receives is exactly what its permissions allow, and it goes to the app the agent runs in, such as Claude Desktop, and to that app's AI provider. Names, emails, phone numbers and addresses are masked when the agent does not act for the administrator, unless you turn masking off. Revoke the agent and nothing more is sent. The plugin keeps no log of the agent's calls.

**The Website Brain.** What the Website Brain knows is stored in its own tables in your database: copies of the pages and posts you choose, your WooCommerce settings (never prices, stock or orders), and facts read from your installed plugins' files. Secrets such as keys and passwords are redacted before an agent sees an entry. It is read only by the agent, within its permissions, and leaves your site only in the answers the agent receives. It is deleted on uninstall when "Delete all data when uninstalling" is on in Settings, unless AI Agent OS PRO or AI Shopping Assistant is still installed and using it.

== External services ==

**An MCP client's own address (only while a client is signing in).** When a client starts connecting by sign-in and identifies itself by a web address (a "client ID metadata document", as claude.ai does), the site fetches that one document, over https only, to learn the client's name and where to send the person back; nothing about your site or its users is sent, and private, local and non-standard addresses are refused. The sign-in itself only works on an https site.

That document belongs to the AI app's own publisher, not to Zorem, so the terms that apply to it are theirs.

**An AI provider you set up yourself (Website Brain AI drafts, off by default).** Only if you configure an AI provider in WordPress (for example an Anthropic or OpenAI key) and turn on AI drafts, offered alongside AI Shopping Assistant, does the Website Brain send a chosen plugin's documentation to that provider to draft a summary, which waits for your approval. That provider's terms apply, for example [Anthropic](https://www.anthropic.com/legal) or [OpenAI](https://openai.com/policies/).

No other external service is contacted. There is no tracking and no telemetry, and no call to a Zorem server.

The agent you connect is not an external service of this plugin: it calls your site, with the sign-in you approved or the token you issued, and receives only what you allowed.

== Bundled third-party code ==

This plugin bundles the [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), which serves the agent's MCP address on top of the WordPress Abilities API. It is copyright the WordPress contributors and licensed GPL-2.0-or-later, the same licence as this plugin; the full text ships with it in `vendor-bundled/mcp-adapter/LICENSE.md`. It is bundled rather than required as a separate plugin so this one works on activation.

The admin screens use Zorem's own ZUI component library (`assets/zui/`), also GPL-2.0-or-later.

== Installation ==

1. Go to **Plugins > Add New** in your WordPress admin and search for "Zorem AI Agent Connector".
2. Click **Install Now**, then **Activate**.
3. Go to **Zorem AI Agent Connector > Agent**. Your Admin Assistant is there, holding the WordPress tools this plugin adds.
4. On its **Connection** tab, copy the MCP address into your AI app (for example claude.ai > Settings > Connectors > Add custom connector) and approve the sign-in.
5. Under **Access** on the same tab, turn destructive tools or data masking on or off at any time; the change applies on the agent's next request.

Alternatively, upload the `zorem-ai-agent-connector` folder to `/wp-content/plugins/` and activate it through the Plugins menu.

== Screenshots ==

1. The Admin Assistant agent, set up the moment you activate the plugin — the WordPress tools it holds, with delete tools switched off by default.
2. The Connection tab: copy the agent's MCP address straight into your AI app, or issue a token for apps that cannot sign in.
3. The Permissions tab — set a whole section to Off, View only or Full, or grant permissions one at a time, each marked View, Change or Delete so you can see at a glance what it can do.
4. The Status screen: versions, database tables, the MCP Adapter and anything that needs a look, with diagnostics you can copy into a support ticket.

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is the open standard AI apps use to call tools on other systems. Zorem AI Agent Connector gives the agent its own MCP address on your site, served by the official WordPress MCP Adapter.

= Which AI apps can I connect? =

Claude (claude.ai and Claude Desktop), ChatGPT and Grok connect by signing in. Codex connects with a token. Cursor, VS Code, Claude Code and other MCP clients connect by signing in or with a token. Grok sign-in can be switched off under "Let Grok sign in" in Settings.

= Why does sign-in say my site is not served over HTTPS? =

AI apps only sign in to servers that use HTTPS. On a site without it, issue a token on the agent's Connection tab and connect with that instead.

= Do I need to copy a token or edit a config file? =

No, for apps that sign in: you paste the agent's address and approve one screen. A token is only needed for apps that cannot sign in, such as Codex.

= Can the agent delete my content? =

Only if you turn on its destructive switch, and even then deleting only moves posts and pages to the trash, where you can restore them.

= Will the agent publish posts without asking? =

New posts and pages are saved as drafts unless the agent asks to publish them.

= Can the agent change its own permissions? =

No. There is no tool for it, and the capability to manage Zorem AI Agent Connector is never lent to the agent.

= Can the agent change users, plugins or site code? =

No. Those capabilities are never lent to the agent, and the user list is read only.

= Can I limit which tools the agent uses? =

Yes. Open the agent's Permissions tab and set a whole section to Off, View only or Full, or grant permissions one at a time — each is marked View, Change or Delete, and "Show only what's on" hides the rest. The Admin Assistant starts with the WordPress tools this plugin adds, and delete tools stay off until you turn on its destructive switch.

= Can I connect more than one AI app? =

Yes. Every app you connect signs in as the Admin Assistant and gets exactly the tools you allowed it, so there is one place to look to see what any connected app can do, and one switch that stops all of them.

= Can I see what the agent did? =

This plugin keeps no log of the agent's calls — nothing is written to your database and nothing is sent anywhere. What the agent changed shows up where you would normally look: a post's revisions, the comment it replied to, the menu it edited.

= How do I disconnect an AI app? =

Remove the connector in the app, or revoke the agent on its Connection tab. Revoking stops its token and sign-ins at once; its permissions are kept, so you can reactivate it later.

= What is the Website Brain? =

The knowledge your agent answers from: store policies, the pages you choose, WooCommerce settings and what your installed plugins do. Open **AI Agent Connector > Website Brain** to choose what it reads. Nothing is read until you tick it, installed plugins are read from their files without running them, and nothing is sent anywhere.

= Does the Website Brain read my whole site? =

No. It reads only the pages and posts you tick, your WooCommerce policy pages and settings, and your installed plugins' files. It never reads orders, customers, prices or stock, and it never runs plugin code.

= How do I stop the agent reading something? =

Untick the page on the Website Brain Overview, or switch off a source on the Sources tab. To stop the agent using the Website Brain at all, switch off its three knowledge tools on the agent's Permissions tab.

= Do I need an AI key for the Website Brain? =

No. What it reads is stored as written, so no AI provider is needed or contacted. The app you connect (for example Claude or ChatGPT) does the answering.

= Does it work without WooCommerce? =

Yes. Zorem AI Agent Connector works on any WordPress site and needs no other plugin.

= Can the agent work with my WooCommerce store? =

Yes. When WooCommerce is active, its own AI tools appear on the agent's Permissions tab: look up orders and products, read and add order notes, change an order's status, and create, update and delete products. None is granted until you grant it. Changing an order's status to cancelled, and deleting a product, also need the agent's destructive switch. Each tool is lent exactly the WooCommerce capabilities its own permission check asks for, for that call only, and customer names, emails, phone numbers and addresses are masked when the agent does not act for the administrator.

= Does it send my data anywhere? =

No. There is no tracking, no telemetry and no call to a Zorem server. The AI app you connect receives only what the agent is allowed — see Privacy above.

= Can tools from my other plugins be used too? =

Yes. Any plugin that registers abilities with the WordPress Abilities API appears on the Permissions tab as soon as it is active, rated like the rest and granted to nothing until you grant it.

= Is there a PRO version? =

Yes. [AI Agent OS PRO](https://www.zorem.com/product/ai-agent-os/?utm_source=ai-agent-connector&utm_medium=wp-org-readme&utm_campaign=go-pro) adds unlimited agents, ready-made agent profiles, the Activity Log, plugin, publishing, SEO and site-building tools, and priority email support. Your agent, token and permissions carry over when you upgrade.

= Why does this need WordPress 6.9 or newer? =

The plugin is built on the WordPress Abilities API, which is what lets tools be registered and permissioned in a standard way. It is available from WordPress 6.9, so earlier versions cannot run the plugin.

== Upgrade Notice ==

= 1.4 =
Adds the Website Brain: your agent can now answer from your store policies, the pages you choose, your WooCommerce settings and what your installed plugins do. Your agent, token, MCP address and permissions are unchanged.

= 1.3 =
Fixes a connection problem that could stop AI apps such as ChatGPT from loading the tool list after signing in. Your agent, token, MCP address and permissions are unchanged.

= 1.2 =
Grok can now connect, approval happens in a short popup instead of a full admin screen, and permissions are simpler (Off, View only or Full per section). Your agent, token, MCP address and existing permissions are unchanged.

== Changelog ==

= 1.4 =
* New: Website Brain, now included free. Give your agent your store policies, the pages you choose, WooCommerce settings and what your installed plugins do. Find it under AI Agent Connector → Website Brain.
* New: your agent gets three read-only knowledge tools (search, topics and one article) and can read Website Brain articles as MCP resources. Existing agents get the knowledge tools once, at View; turn them off any time on the Permissions tab.
* Improvement: posts and pages the agent creates or duplicates are credited to the administrator who connected the app, not to the agent's hidden account.
* Improvement: the "See this agent's own permissions" tool also answers on another plugin's MCP connection, saying which user is signed in.
* Improvement: the Status screen shows the Website Brain's state and version.
* Fix: the approval popup no longer raises a PHP warning when headers were already sent.
* "Delete all data when uninstalling" also deletes the Website Brain's data, unless AI Agent OS PRO or AI Shopping Assistant still uses it.

= 1.3 =
* Fix: after signing in, some AI apps could not load the tool list, because the connection refused the way they number their requests. Every app's requests are now answered the way the MCP standard expects.
* Fix: deleting this plugin after upgrading to AI Agent OS PRO no longer removes the agents, permissions, app connections or settings that PRO now uses.

= 1.2 =
* New: Grok can connect. Add the agent's address in Grok (Settings → Connect Apps), approve, done. Only Grok's own addresses (grok.com, x.ai) may register, and every connection still needs your approval. Turn it off with "Let Grok sign in" in Settings.
* New: a small approval popup. When an app connects, you approve in one short card that shows the app, the agent and what it can do, instead of a full admin screen. "Don't ask again" is offered for agents that can only look.
* New: the Agent tab opens on an agents list (status, connected apps, tools, customer data); click the name to open the agent.
* New: redesigned Connect page: copy the agent's address, step-by-step guides for Claude, ChatGPT, Grok, Codex and other apps, and a list of connected apps, each with Disconnect.
* New: simpler permissions: each section is Off, View only or Full, every permission shows View, Change or Delete, and "Show only what's on" hides the rest.
* Improvement: friendly names for every permission (for example "See orders" instead of "Query orders"), and "Category or tag" instead of "term".
* Improvement: Posts and Pages are separate sections, and sections appear in a fixed, everyday order.
* Improvement: shorter, clearer Access & safety and Settings pages; "Danger zone" is now "Manage agent".
* Improvement: the Activity Log and Website Brain previews now show what AI Agent OS PRO looks like.
* Fix: ChatGPT showed "No tools available" for some sites; every tool is now sent in a form ChatGPT accepts.
* Fix: some view-only permissions showed as Off even though they were on.
* Tested with WordPress 7.1.5 and WooCommerce 11.3.0.

= 1.1 =
* New: WooCommerce tools. With WooCommerce active, the agent can look up orders and products, read and add order notes, change an order's status, and create, update or delete products, each lent only the access its own check needs. Customer details in orders are masked when the agent does not act for the administrator.
* New: tools from other plugins that need store or settings access (such as `manage_woocommerce`) now work for an agent that acts for the administrator, for the call only. Users, roles, plugins, themes, site code and personal-data erasure stay out of reach.
* New: Go PRO tab, and previews of the Activity Log and Website Brain.
* Fix: WooCommerce 11.3's "Create product" and "Update product" tools were refused and reported on the Status screen; they are now governed like every other tool.
* Fix: the agent's hidden account now uses an address that can never receive mail, so plugins that react to new users do not treat it as a sign-up.
* Fix: the status badges on the Status screen are centred.
* Improvement: security hardening of sign-in, the approval screen and MCP request handling.
* Tested with WordPress 7.1.5 and WooCommerce 11.3.0.

= 1.0 =
* Initial release