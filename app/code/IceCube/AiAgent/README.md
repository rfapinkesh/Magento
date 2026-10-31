# IceCube_AiAgent

Read-only AI chat assistant for the Magento 2 admin panel. Admins ask questions in plain language
("What's the status of order 000000123?", "Which products are low on stock?") and the agent looks up
live store data through tool calling on the AI provider you pick: OpenAI (Responses API),
Claude (Anthropic Messages API, official PHP SDK) or Groq (OpenAI-compatible Chat Completions).

The agent can never save, edit or delete anything: every tool only reads through Magento collections
and repositories.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.7, 2.4.8 or 2.4.9
- PHP 8.2 to 8.5
- An API key for the chosen provider (OpenAI, Claude or Groq)
- For Claude: the `anthropic-ai/sdk` composer package (`composer require anthropic-ai/sdk`)
- Outbound HTTPS to `api.openai.com`, `api.anthropic.com` or `api.groq.com`

## Install

1. Copy the module to `app/code/IceCube/AiAgent`.
2. Run:

   ```bash
   bin/magento module:enable IceCube_AiAgent
   bin/magento setup:upgrade
   bin/magento setup:di:compile            # production mode only
   bin/magento setup:static-content:deploy # production mode only
   bin/magento cache:flush
   ```

3. Optional, to allow clean schema rollbacks later:

   ```bash
   bin/magento setup:db-declaration:generate-whitelist --module-name=IceCube_AiAgent
   ```

## Configure

Go to **Stores > Configuration > IceCube > AI Agent**:

| Setting | Default | Notes |
| --- | --- | --- |
| Enable AI Assistant | No | Turn on once the API key is saved |
| AI Provider | OpenAI | OpenAI, Claude or Groq; only that provider's fields are shown |
| OpenAI: API Key / Model / Reasoning Effort / Base URL | empty / gpt-5.6-sol / Low / https://api.openai.com/v1 | Responses API model with function calling |
| Claude: API Key / Model / Effort / Base URL | empty / claude-opus-5-5 / Low / empty (api.anthropic.com) | Effort: low, medium, high, xhigh, max |
| Groq: API Key / Model / Base URL | empty / llama-3.3-70b-versatile / https://api.groq.com/openai/v1 | Model must support tool use |
| Request Timeout | 60 s | Per API call, all providers |
| Max Tool Calls per Question | 8 | After this the agent must answer with what it has |
| Max Rows per Lookup | 20 | Hard cap 100 |
| Previous Messages Sent as Context | 12 | Memory within one chat |
| Extra Instructions | empty | Store-specific notes added to the system prompt |
| Enabled Tools | all six | Multiselect |
| Mask Customer Personal Data | Yes | Emails, phones, street, postcode masked before sending |
| Questions per Admin per Hour | 60 | 0 = unlimited |
| Keep Chat History | 90 days | Nightly cron cleanup, 0 = forever |

## Permissions (System > Permissions > User Roles)

- **IceCube AI Agent > Use AI Assistant**: needed to open the chat.
- **Stores > Settings > Configuration > IceCube AI Agent Settings**: needed to change settings.
- Each tool also checks the role's existing Magento permission:

| Tool | Requires |
| --- | --- |
| search_orders, get_order_details | Sales > Operations > Orders |
| search_products, get_product_details, get_low_stock | Catalog > Inventory > Products |
| get_customer | Customers > All Customers |
| search_sales_documents | Sales > Orders, plus Invoices / Shipments / Credit Memos for each type |
| get_sales_report | Reports > Sales > Orders |
| get_best_sellers | Reports > Products > Bestsellers |
| get_abandoned_carts | Reports > Marketing > Abandoned Carts |

A tool the role cannot use is never offered to the model.

## Use

Open **IceCube > AI Agent > AI Assistant** in the admin menu. Each admin sees only their own chats.
Answers include "open in admin" links to orders, products and customers.

## Usage dashboard, audit log and exports

- **IceCube > AI Agent > Usage Dashboard** (permission *View Usage Dashboard*): questions, active admins,
  lookups, tokens and estimated cost for the last 7/30/90/365 days, per day, per admin, per provider/model
  and per tool. Cost uses the optional token prices in each provider's settings (Claude defaults to
  $4 / $20 per million tokens, the Claude Opus 5.5 price); answers from before provider tracking show as unknown.
- **IceCube > AI Agent > Audit Log** (permission *View Audit Log*): every tool call with time, admin, the
  question that triggered it, tool, arguments and masked result; filter by admin, tool, dates and text,
  and export the filtered list as CSV.
- **Download CSV** above every table in a chat answer saves that table as a CSV file.

## Tools

| Tool | What it answers |
| --- | --- |
| search_orders | Orders by number, customer email/name, status, date range, store view, total |
| get_order_details | Items, totals, addresses, payment/shipping, invoices, shipments with tracking, credit memos, comments |
| search_products | Products by SKU/name text, category, status, type, price range, stock status |
| get_product_details | Prices, special price dates, stock, categories, websites, SEO meta, configurable children |
| get_low_stock | Products at or below a qty threshold, or out of stock |
| get_customer | Customer accounts by email or name, with order count, lifetime sales and last order |
| search_sales_documents | Invoices, shipments (with tracking) and credit memos across orders, with count and total |
| get_sales_report | Orders, revenue, AOV, items, discounts, shipping, tax, refunds; by day/week/month/store/payment/status; vs previous period |
| get_best_sellers | Top products by units or revenue, or enabled products with no sales (slow movers) |
| get_abandoned_carts | Active carts with items and no order: count, total value, customers and top items |

### Adding a provider

Implement `IceCube\AiAgent\Model\Provider\ProviderInterface`, register it in `etc/di.xml` under
`IceCube\AiAgent\Model\ProviderPool` > `providers`, and add it to `Config::PROVIDERS`,
`Model/Config/Source/Provider.php` and the AI Provider group in `etc/adminhtml/system.xml`.

### Adding a tool

1. Create a class implementing `IceCube\AiAgent\Model\Tool\ToolInterface` (read-only).
2. Register it in `etc/di.xml` under `IceCube\AiAgent\Model\ToolRegistry` > `tools`.
3. Add its name to `Enabled Tools` in config (the default list in `etc/config.xml` covers the built-in six).

## Privacy and data flow

- The admin's question and the tool results are sent to the selected provider to produce the answer.
- OpenAI requests are sent with `store: false`, so OpenAI does not keep the conversation for later retrieval.
  Claude and Groq keep API data under their own retention policies.
- With masking on, customer emails (`j***@example.com`), phone numbers (`***10`), street lines
  and postcodes are masked inside tool results before they leave the server. Text the admin types
  is sent as typed.
- Chat history and an audit log of every tool call (name, arguments, masked output, token counts)
  are stored in `icecube_aiagent_conversation` and `icecube_aiagent_message`.

## Known limits

- Stock figures come from the default stock (`cataloginventory_stock_item`). Stores with several
  inventory sources should confirm salable quantity on the product page.
- Answers are generated by a language model from live data and can still contain mistakes.
- Long multi-lookup answers can take 10 to 40 seconds. The chat request allows up to 300 seconds;
  raise PHP-FPM / proxy timeouts if your stack cuts requests earlier.

## Database tables

- `icecube_aiagent_conversation`: one row per chat, linked to `admin_user` (deleted with the admin user).
- `icecube_aiagent_message`: user, assistant and tool rows (deleted with the chat).

## Uninstall

```bash
bin/magento module:disable IceCube_AiAgent
bin/magento setup:upgrade
```

Then remove `app/code/IceCube/AiAgent` and drop the two tables if no longer needed.
