# Shop Email Campaigns for WooCommerce

A self-hosted WordPress plugin for designing and sending branded marketing emails to your WooCommerce customers. No third-party email marketing service, no per-contact fees: emails go out through WordPress's own `wp_mail`, so they use whatever mail setup the site already has (an SMTP plugin such as SMTP2GO, WP Mail SMTP, Postmark, etc. is strongly recommended for deliverability).

## Features

- **Email designer** with a live desktop/mobile preview. Blocks: heading, rich text, button, image (media library), product card, discount code, divider, spacer and sign-off.
- **Personalisation** with merge tags: `{first_name}`, `{shop_url}`, `{site_url}`, `{account_url}`, `{product_url:123}`.
- **Customer segments** built from WooCommerce order history, with filters you can combine with AND / OR:
  - purchased in the last N days/weeks/months
  - has bought before but not in the last N months (lapsed)
  - first order in the last N months (new customers)
  - has ever purchased (all-time customers) / never purchased
  - number of orders at least / at most N (e.g. repeat buyers)
  - total spent at least X
  - bought (or not bought) specific products, or from a category
  - has / hasn't an active subscription (WooCommerce Subscriptions)
  - joined the list recently, or consent status
- **Templates**: save any design as a template and start new campaigns from it. Five starter templates are included.
- **Branding** set once in Settings: logo, colours, font, button style, sender, footer address.
- **Batched sending** through Action Scheduler (bundled with WooCommerce), with adjustable batch size and interval to stay inside your mail provider's limits. Pause, resume, cancel and schedule for later.
- **Test emails** to up to five addresses.
- **Consent and compliance** (UK GDPR / PECR, and similar rules elsewhere):
  - every contact has a consent status (subscribed, customer, unsubscribed, bounced) with the wording, time, source and IP of their opt-in;
  - optional unticked opt-in box at checkout (block and classic checkout) and in My Account;
  - "soft opt-in" for existing customers is off by default and must be switched on deliberately;
  - every email has an unsubscribe link plus `List-Unsubscribe` and one-click `List-Unsubscribe-Post` headers (Gmail/Yahoo bulk sender requirements);
  - unsubscribe page asks for confirmation, so link scanners can't unsubscribe people by accident;
  - people who unsubscribed are never re-added by imports or order syncs;
  - hooks into WordPress's personal data export and erasure tools.
- **Live sending master switch**: campaigns can't go to customers until you switch it on in Settings. Test emails always work.
- **Campaign reports** (Email Marketing → Reports), with no open tracking:
  - sent, delivered, bounced (hard and soft), spam complaints and unsubscribes for each campaign;
  - **click tracking**: which links each person clicked, and how many people clicked;
  - **orders and revenue** from people who clicked, within 7 days of their first click;
  - **Google Analytics tags** (`utm_source=newsletter`, `utm_medium=email`, `utm_campaign=<campaign name>`) added to links to your own site;
  - **bounces from SMTP2GO** via a webhook: hard bounces mark the contact as Bounced, three soft bounces in a row do the same, and spam complaints unsubscribe the contact. Copy the webhook address from Settings → Reporting into SMTP2GO → Settings → Webhooks (events: Delivered, Bounce, Spam, Reject, Unsubscribe). Only events for mail sent from the From email's domain are used, so one SMTP2GO account can serve several sites (add more domains with the `whm_webhook_sender_domains` filter).
- **Contacts**: synced automatically from paid WooCommerce orders, CSV import, manual add, search and filters.
- **Bring your history with you**: import a WooCommerce Customers export from your old shop and its order counts, spend and last-active dates are kept as purchase history, so segments work from day one. Live orders on the new site are added on top.
- Works with both WooCommerce order storage modes (HPOS and legacy posts).

## Requirements

- WordPress 6.4+
- WooCommerce 8.9+ (for the block checkout opt-in field; the rest works on older versions)
- PHP 8.0+

## Installation

1. Download this repository as a ZIP, or clone it into `wp-content/plugins/shop-email-campaigns`.
2. Activate **Shop Email Campaigns** in Plugins.
3. Go to **Email Marketing → Settings** and set your logo, colours, sender address and footer address.
4. Review the consent options, then switch on **Live sending** when you're ready.

Existing customers are pulled in from past orders in the background on activation. You can re-run this from **Contacts → Sync customers now**.

## How it sends

When you press send, the plugin freezes the audience into a queue and sends `batch size` emails every `batch interval` seconds using Action Scheduler (which runs on WP-Cron). Each recipient is re-checked just before their email goes out, so anyone who unsubscribes mid-send is skipped. Progress shows on the Campaigns page.

For reliable timing on low-traffic sites, trigger WP-Cron from a real cron job.

## Why no open tracking?

Open tracking relies on a hidden image. Apple Mail loads every image automatically and many apps block them, so open rates are unreliable, and the UK ICO treats tracking pixels as needing consent under PECR. Clicks, orders and bounces are measured instead.

## Customising

- `whm_default_settings` filter: change the default settings (handy for a brand preset).
- `whm_starter_templates` filter: add or replace the starter templates created on activation.
- `whm_paid_order_statuses` filter: which order statuses count as purchases (defaults to WooCommerce's paid statuses).

## Not legal advice

The consent tools help you follow the rules, but you're responsible for how you collect consent and who you email.

## Licence

GPL-2.0-or-later.
