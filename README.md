# Kaura Hardware Inventory

The supplied [product brief](docs/Kaura_Hardware_Inventory_Product_Brief_v0.1.%20working%20draft.md) is preserved in the project. See the [implementation review](docs/implementation-status.md) for requirement-by-requirement status and remaining first-release work. Application workflows are implemented; real shop and target deployment acceptance remain open.

Laravel, Inertia, React and TypeScript application for one hardware shop. Account access, catalogue, customers, owner settings, opening stock/debt review, supplier deliveries, cash/manual M-Pesa checkout, discount/credit approval, deposits, oldest-first instalments, owner payment verification, physical counts with owner-approved stock adjustments, owner expenses/withdrawals, financial reports with account activity, CSV exports, collection lists, sale voids with owner goods review and printable receipts are implemented. Full void refunds and retained customer-credit balances are implemented. Reviewed partial returns, retained-credit checkout/offset, reasoned debt write-offs, linked correction/replacement records, catalogue import, cash handover/reconciliation and detailed report exports are implemented. Real shop pilot and target-environment checks remain.

## Local setup

Dependencies are declared in composer.json and package.json. Use an environment file with a generated application key, then run:

```text
composer install
npm.cmd ci
php artisan migrate
php artisan db:seed --class=DevelopmentUserSeeder
npm.cmd run build
composer run dev
```

Development logins (local/testing only):

| Role     | Email               | Password      |
| -------- | ------------------- | ------------- |
| Owner    | owner@kaura.test    | KauraDev!2026 |
| Employee | employee@kaura.test | KauraDev!2026 |

Both development accounts are active and email verified, without a forced first password change. Rerunning the seeder preserves existing passwords, roles and activation status. It seeds no products, stock, sales or debts. `DatabaseSeeder` invokes it only in local/testing; direct development seeding refuses production.

On shells without PowerShell script restrictions, `npm` works in place of `npm.cmd`. For real onboarding, use `php artisan kaura:create-owner` instead of the development seeder. The owner command asks for credentials interactively and refuses to run when an owner already exists. Passwords must never be passed as command arguments. Owner email is trusted through this administrator procedure; employees must verify their email. Configure working mail delivery before employee onboarding (the local log mailer is for development only).

An owner created by the administrator command signs in, replaces the initial password, then uses Employees to create staff accounts. Initial passwords require 12 characters, upper/lowercase letters, a number and a symbol. Share each password privately. Existing accounts retain their privileges; none are promoted automatically. To preview transactions, enter shop settings, catalogue and actual opening balances, then sign off opening at `/owner/inventory`.

## Demo shop for testing

The explicitly requested local demo dataset is loaded with:

```text
php artisan db:seed --class=DevelopmentDemoSeeder --no-interaction
```

This is separate from `DatabaseSeeder`, which still seeds development accounts only. The demo seeder refuses production and refuses an established shop with posted history or nonzero stock. An existing zero-stock catalogue can be preserved; its active listings receive explicitly simulated zero opening records so sign-off remains consistent. The local database is backed up before the first load.

The dataset contains 24 labelled demo products across five categories, six fictional customers, 15 sales and 18 payments. It includes separate selling units, fractional quantities, low/zero stock, one unknown-cost listing, opening debts with due dates, a paid supplier delivery, pending/verified/disputed M-Pesa, credit/discount requests in different states, cash and M-Pesa instalments, pending/resolved voids, refunds, retained credit, a credit payout, expenses, a withdrawal and pending/approved counts. Existing catalogue entries and login passwords are preserved. Approved unposted requests expire normally after 30 minutes; request fresh approval when testing later.

All demo transactions run through the normal posting services in one transaction. A final audit marker makes reruns a no-op, preserving subsequent user edits and balances rather than resetting the shop. Failure rolls back the dataset and marker together. The shop defaults to **Kaura Hardware — DEMO** with the non-payable placeholder **DEMO-NOT-PAYABLE** when settings were blank; customer contacts and payment codes are fictional. A persistent **DEMO DATA** banner identifies the database. Never treat these fixtures as real accounts, balances or payment instructions.

Try both development logins: the employee sees their own sales/collections and can request counts/approvals; the owner can explore financial reports, customer statements, payment verification, void stock review and retained-credit payouts. Current-day reports contain sample activity; opening balances are dated seven days before loading. No external funds are sent by the seeder.

## Access design

| Capability                              | Owner                | Employee |
| --------------------------------------- | -------------------- | -------- |
| Personal profile/security and workspace | Yes                  | Yes      |
| Create employee accounts                | Yes                  | No       |
| Activate/deactivate employees           | Yes, reason required | No       |
| Inspect access audit history            | Yes                  | No       |
| Search products, prices and stock       | Yes                  | Yes      |
| Create/edit products and categories     | Yes                  | No       |
| Read purchase costs and price history   | Yes                  | No       |
| Search/add customers                    | Yes                  | Yes      |
| Edit customer details and credit limits | Yes                  | No       |
| Public registration or account deletion | No                   | No       |

Shop settings, opening imports/sign-off, supplier deliveries, the stock movement ledger and customer debt statements are owner-only. Employees can see posted stock quantities through Products; cost and debt data remain restricted.

Both roles can post cash sales or record pending manual M-Pesa sales. Employees can list only their own sales and collections, and view/print their own receipts/payment records. Only the owner can verify/dispute M-Pesa. The owner can inspect all sales/collections and filter by employee. Cost snapshots remain owner-only and are omitted from every customer-facing receipt.

Roles, active status, password-change requirement and session version are stored on users. Public input cannot assign these fields. Owner routes require authentication, verified email and owner middleware. The global web middleware checks active status and session version and enforces the initial password change, including for passkey and two-factor sign-ins. Employee activation changes increment the version, clear remember tokens and reset links, and delete database sessions. Reactivation does not resurrect old sessions. Use a shared cache for the owner bootstrap lock when running multiple application instances.

Account creation and access changes commit with their audit events in a database transaction. Audit events record actor, subject, server time, reason and selected before/after fields, never passwords. Audit pages are read-only and owner-only; timestamps display in Africa/Nairobi. Database administrators can still modify data, so production database privileges and backups must protect audit history.

## Catalogue and customer design

| Table                 | Purpose and invariants                                                                                                                                                                                                   |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| categories            | Display name and unique normalized name; case/whitespace variants cannot create duplicates.                                                                                                                              |
| products              | Unique uppercase SKU, category, selling unit, quantity precision, nullable purchase cost, selling price, optional unique barcode, reorder threshold, active flag and edit version. Every listing starts with zero stock. |
| product_price_history | Initial prices/costs and every later price/cost change, actor, reason and server effective time; earlier rows are preserved.                                                                                             |
| customers             | Name, original contact, normalized match keys, creator, credit limit and edit version. New credit limits are zero. Contact sharing is allowed after explicit duplicate review.                                           |
| audit_events          | Also identifies catalogue/customer resources by type and ID, with before/after state.                                                                                                                                    |

Money is stored as integer KES minor units and accepts up to 9 whole digits plus 2 decimal places. Decimal text converts directly to integers without floating-point arithmetic. A missing purchase cost stays null; zero is a known zero cost. Employee product responses omit purchase costs, and employee customer responses omit credit limits. No debts or profit figures are inferred from directory data.

Stock/reorder values use decimal(12,3). Each listing fixes its quantity precision from 0 to 3 decimal places, so a whole-unit listing rejects fractional thresholds. SKU, unit label and quantity precision are immutable after creation; create a separate listing for another sellable unit. There are no unit conversions. Stock and valuation inputs are prohibited in catalogue forms; opening balances and stock receipts update stock through explicit movements.

Product/category writes require owner middleware and policies. Customer creation is available to both roles; customer edits and credit limits require the owner. Domain services recheck policies, lock edited records and compare versions; stale forms fail without changing prices or credit limits. Product changes, price history and audit entries commit together. Owner updates require a reason. Duplicate SKUs/barcodes are rejected; matching product/customer names and contacts require review before a separate record is created. Local and international forms of Kenyan mobile contacts are matched while preserving the original contact text.

Pages: `/products` supports case-insensitive name/SKU/barcode search, category/status/low-stock filters and 20-row pagination. `/products/create` and `/products/{id}/edit` are owner-only. Categories are added from the product list. `/customers` supports name/contact search and pagination; `/customers/create` is available to both roles, and `/customers/{id}/edit` provides owner details and credit-limit forms. The owner audit page shows resource identifiers and changes. Price history displays its 20 most recent entries; all earlier entries remain stored.

## Owner settings and stock posting

The owner enters the actual name/address/contact and optional manual M-Pesa receiving number at `/owner/shop-settings`. Name and receiving-number changes require a reason and optimistic version check; a replacement number requires matching confirmation. The restricted audit log retains before/after values. No real shop identity or payment number is seeded. The sidebar uses the saved shop name. Currency remains KES, the business timezone is Africa/Nairobi, and no merchant integration is activated.

At `/owner/inventory`, create an **opening stock**, **opening debt** or **stock delivery** draft. Enter rows manually or upload a UTF-8 CSV (up to 500 rows / 1 MB), review the saved snapshot and totals, then approve and post separately. Drafts do not change stock, receivables or money. Each submission key is bound to its payload; a retry returns the same draft/posted record, while a different payload requires a new key. Posting an already-posted batch returns its existing result without duplicate movements/outflows. Incorrect drafts can be cancelled with a reason; a corrected delivery can reuse the reference of a cancelled draft. Posted records cannot be cancelled or edited and need future linked correction workflows.

| Table / record            | Purpose and invariants                                                                                                                                                                                                               |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| shop_settings             | One row for shop identity, settings version, shared opening as-of date and final owner sign-off. Posting locks this row to serialize single-shop batches and sign-off.                                                               |
| inventory_batches         | Immutable reviewed metadata and draft/posted/cancelled progression, submission/payload hash, source note, dates, creator/approver and supplier/payment snapshots. Active delivery references are unique per supplier.                |
| inventory_batch_items     | Reviewed SKU/unit/customer identities and versions, exact quantity, cost/amount and debt due dates. Duplicate product/customer rows within a batch are rejected.                                                                     |
| stock_movements           | One movement per posted stock row, with quantity delta, resulting balance, value delta, effective date and actor. Unique opening-product references prevent re-importing an opening balance.                                         |
| receivables               | Opening debt origin, original/remaining amount, source, due/as-of dates and actor. One opening entry per customer; opening debt creates no sale or collection. Ordered customer/date/ID indexes prepare for oldest-first allocation. |
| suppliers / cash_outflows | Supplier identity and one full-payment stock-purchase outflow per delivery. Outgoing M-Pesa references are unique. A purchase does not become an operating expense.                                                                  |

Stock CSV headers: `sku,unit,quantity,unit_cost`. Use existing SKUs and exact unit labels; unknown opening costs may be blank. Delivery costs are required. Debt CSV headers: `customer_id,amount,due_on`; use existing directory IDs, KES amounts and YYYY-MM-DD dates. Due dates may be overdue. Customer IDs appear in the directory. Header-only templates are available from the draft screen. These imports do **not** create product/customer listings; bulk catalogue creation remains a separate future task.

All opening batches share one as-of date. Every active listing needs an explicit opening count, including zero, before final sign-off. Set the real shop name, resolve every draft, compare the debt total to the paper records, then explicitly confirm sign-off. If no customers owe paper debts, confirm a paper debt total of zero. Missing costs remain flagged and do not block owner sign-off. Deliveries are unavailable before sign-off and cannot predate the opening date. Opening imports are closed after sign-off; a newly created product starts with zero stock and can receive deliveries.

The inventory page shows posted opening counts, opening debt, known stock value and unknown-cost warnings. `/owner/inventory/ledger` lists source-linked stock movements. `/owner/customers/{id}/statement` shows opening receivables, linked M-Pesa verification balances and their confirmed allocations. Opening debts accept confirmed instalments through the same oldest-first allocation workflow. An inherited opening debt is recorded even when it exceeds the customer's new-credit limit, without increasing that limit.

### Exact costing and posting controls

Quantities are converted to integer thousandths before arithmetic. Line values use exact decimal multiplication and round to the nearest cent (half up). Inventory carries the sum of posted line values less posted cost of goods sold. Weighted-average unit cost is the carried value divided by quantity, rounded half up to cents. Reference purchase cost in the catalogue is independent and cannot revalue posted stock. Cash sales snapshot inventory costs and allocate the final carried value on depletion as described below; margin reports use these historical cost snapshots.

Unknown opening costs on positive stock leave inventory value and weighted-average cost null. A later known-cost delivery does not pretend the old cost is known. Zero counted stock has known zero inventory value even when unit cost is blank. The inventory total explicitly labels incomplete valuations. Each SKU remains separate; quantities of different units are never combined into a shop-wide quantity total.

Large intermediate products use the installed Brick Math exact-decimal implementation. Unit amounts allow 9 whole KES digits; batch/global known-stock/debt totals are capped at 9,000,000,000,000,000 minor units and stock balances at 999,999,999.999 units. Aggregate payment/reconciliation inputs allow larger totals than individual unit amounts. Currency display formats integer strings without converting financial values to floating-point numbers.

Draft review preserves product/customer versions. Posting opening balances locks and rechecks records; stale identities/counts fail without partial posting. Deliveries recheck active products and compute weighted costs from the current locked balance. Stock, valuation, receivables/outflows, batch status and audit events commit in one transaction. Unique source and payment references provide additional duplicate guards. PostgreSQL concurrency behavior still needs integration testing; SQLite tests exercise retries, stale drafts, uniqueness and atomic failure paths.

## Cash checkout and receipts

Use **Checkout** (`/sales/create`) after opening sign-off. Search by name/SKU/barcode, add distinct listings, enter their quantities and optionally identify a customer. Up to 100 distinct lines may be checked out together. The cart exists only on this screen; leaving the screen discards it and it never reserves stock. For cash, enter tendered cash, review total/change and explicitly confirm posting. For manual M-Pesa, follow the pending-payment workflow below. Current prices and available stock can be refreshed without silently changing an existing quoted cart price.

Standard checkout accepts full cash payment or a full-amount pending manual M-Pesa claim at the standard price. Discounts, credit and deposits use the approval workflow below. Short payment, over/underpayment of a manual claim, unapproved discounts/credit, merchant/STK attempts, inactive/missing products, zero or unavailable quantities and unit-precision violations are rejected. The server compares every quoted price with the current selling price; a changed price requires explicit refresh/reconfirmation. Staff attribution comes from the authenticated account and cannot be supplied by the form.

| Table / record  | Purpose and invariants                                                                                                                                                                                                                               |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| sales           | Numbered reference, actor, server time/Nairobi business date, immutable shop/customer/employee snapshots, total/cost, tender and change. Unique submission key and payload hash bind a retry to the original actor and request.                      |
| sale_items      | SKU/name/unit/precision, quantity, price, weighted unit-cost snapshot, authoritative line revenue and allocated inventory cost. Unique product/position per sale.                                                                                    |
| payments        | One server-keyed payment record per checkout, linked sale/customer and separate handling employee. Cash is net retained and verified; manual M-Pesa is pending with a unique normalized code, amount, payer reference and receiving-number snapshot. |
| stock_movements | Sales create negative source-linked quantity/value deltas. Each row has exactly one batch-item or sale-item source, enforced by foreign keys/unique indexes plus SQLite triggers or a PostgreSQL check constraint.                                   |

Checkout locks the single-shop control row, product rows in ID order and any customer row, then rechecks stock/prices. Sale/items, stock/value deductions, payment and audit commit together. PostgreSQL posting remains serialized by the shop row for this shop's small staff count. SQLite busy retries restart a fresh transaction, re-read stock/idempotency state and either return the prior result or reject unavailable stock. Other failures roll back the entire checkout. The same actor/payload/key returns the original sale even if prices/availability subsequently change; a changed payload or another employee cannot reuse a posted key.

Cash tender and change are stored separately from collections: a KES 50 sale paid with KES 100 records KES 50 collected and KES 50 change, not KES 100 revenue/collections. A fully paid cash sale creates no receivable. Unknown purchase costs do not block a sale; its cost total remains null and the owner sees incomplete cost data.

For known costs, a partial sale allocates carried inventory value in proportion to the quantity sold, rounded half up to cents. Selling the final quantity takes the entire remaining carried value, so rounding residuals cannot leave value on empty stock. The rounded unit-cost snapshot can differ slightly from the authoritative allocated line cost; use `cost_total_minor` for later margin calculations. Unknown costs remain unknown on historical sale lines. Depleting unknown-cost stock resets the empty balance to known zero, without inventing a historical cost.

`/sales` and `/payments` paginate 20 rows and filter by inclusive Nairobi business dates. Employee SQL queries are scoped to their own selling/handling ID; employee-supplied owner filters are rejected. The sale policy also protects direct detail and receipt URLs, and denied attempts are audited. Employee checkout/detail props omit purchase costs and owner financial metadata.

`/sales/{id}` shows the saved sale; `/sales/{id}/receipt` is a standalone, escaped HTML receipt with print CSS and a Print button. It retains the shop identity, employee/customer and line details from checkout even after catalogue/settings changes or staff deactivation. Cash receipts show tender/change and zero balance due. Manual M-Pesa receipts show the historical number, code, current verification status, confirmed collection and unresolved balance. Pending/disputed receipts never say paid in full. This is a simple sales receipt; no eTIMS/tax compliance claim is made. Browser/physical-printer output still needs a pilot check. Voids use the linked review workflow below; completed void refunds use a separate source-linked financial resolution. There is no edit/delete action for a posted sale.

## Manual M-Pesa recording and verification

The owner first configures the real receiving number. At checkout, staff show that number and the exact sale total, then record the customer's transaction code, amount and optional payer reference. Codes are trimmed/uppercased, must contain 6–30 letters/digits and remain unique even when disputed. Missing codes cannot post a sale; leave the cart unposted. Standard checkout requires a positive full-total claim; approved deposits use the approval workflow below.

The server rechecks the displayed number against current settings. A number changed before posting causes rejection, requiring explicit reconfirmation; the UI clears old payment details when adopting a new destination. A payment already recorded retains its original number permanently. Changing settings never rewrites old payments or configures a merchant account. There is no STK Push button, callback integration or customer PIN collection.

Posting a manual claim atomically creates the sale, stock deduction, pending payment and full unresolved receivable (`origin=payment_verification`). This is a verification exposure, not owner-approved credit. Customer identification is optional for these full-payment claims; unassigned balances stay linked to the sale and payment for owner follow-up rather than disappearing from the ledger. Named-customer balances appear on the owner's statement. Pending claims do not reduce confirmed outstanding balances or increase confirmed money received.

The owner opens **Collections → Pending verification** or `/payments?status=pending`, then reviews `/payments/{id}` against the receiving number's messages/statement. Verify/dispute requires the owner's own authenticated account, explicit confirmation and an evidence/reason note. A verify decision creates one allocation to that checkout's receivable and reduces it exactly once; dispute creates no allocation and leaves the amount due. The sale and stock movement remain unchanged in either case. This checkout allocation does not pay an older opening debt: oldest-first distribution applies to the separate instalment workflow below.

`payment_verifications` stores one terminal owner decision per payment, with actor, time and reason. `payment_allocations` stores source-linked confirmed settlement amounts. Both commit with payment status, balance and audit changes. Identical-decision retries return the existing decision; contradictory decisions are rejected without rewriting it. Any correction/reversal of a finalized decision needs a future explicit correction workflow. Cash records cannot be disputed through this manual verification endpoint. Original payment recorder/date, code and receiving number remain intact.

Collections show separate recorded, verified, pending and disputed M-Pesa totals, plus confirmed cash and combined confirmed collections. Totals are scoped to the selected entry dates/employee and reflect current verification status across all statuses, even when the list is filtered to pending/disputed. Verification time is preserved separately in its decision record; it never overwrites the original entry day. Employee totals/details and dashboard alerts are scoped to their own handling account. The owner sees all entries. Disputed/pending alerts link to the matching queue; receipts/statements use the same settlement status.

Incoming and outgoing M-Pesa codes are checked against each other under the shop posting lock; a supplier transaction cannot be reused as an incoming claim or vice versa. Per-table uniqueness adds a database guard. `PostingTransaction` shares bounded SQLite busy retries between checkout and verification; each retry starts a fresh transaction. Parallel SQLite tests force conflicting reads and verify one sale per incoming code and one allocation/decision per payment. PostgreSQL concurrency testing remains pending.

## Discount/credit approval and instalments

In Checkout, expand **Request owner discount / credit approval**. Choose the exact cart, customer and payment method, then enter discount, credit, promised date and reason. `/sale-requests` lists an employee's own requests; the owner sees all. A request does not reserve stock or move money. The owner reviews the frozen terms using their own login and approves/rejects with a reason; approvals last 1–60 minutes. Owner review includes the customer's limit and outstanding balance; employees never receive those totals.

Complete an approved sale from its request detail page. Any changed cart/customer/method, expired/consumed approval, changed customer identity, changed price or insufficient stock requires review again. Credit limits are checked at request, owner decision and final checkout. Credit needs a named customer and a promised date today or later. For a manual M-Pesa deposit, the entire net sale counts against the limit until verification. Discount cents are allocated across lines without altering inventory costs. Full cash credit uses zero tender and creates no fictitious collection. Request, decision and checkout retries are idempotent, with immutable audit history.

Use **Collect instalment** (`/payments/collect`) for opening debts and credit-sale balances. A confirmed cash payment allocates oldest-first by debt date and ID, spanning debts when needed. A pending manual M-Pesa instalment reserves collectible capacity but creates no allocations or balance reduction until owner verification. Dispute leaves debt outstanding and releases its reservation. Existing pending checkout deposits reserve their own receivable amounts; instalments skip those reserved amounts and record the exception for owner review. Other pending instalments also reserve capacity, preventing overlapping collections. Verification, allocation, debt changes and audit entries commit atomically.

Payment details and `/payments/{id}/receipt` show collection amounts and confirmed allocations. Instalment receipts preserve original shop/customer/collector identity and receiving number. Employees see their own collections and allocation references, with sale links only for their own sales; full customer statements, balances and exception notes remain owner-only. Owner customer statements list unconfirmed instalments separately. Collections include instalments without counting them as new sales revenue. No edit, reversal, refund or debt write-off workflow is included yet.

## Physical counts and stock corrections

Both roles can use **Stock counts** (`/stock-counts`) after opening sign-off. Select up to 100 distinct listings, physically count them and enter a reason; enter zero explicitly for an empty shelf. Only selected listings are counted. Pause sales/deliveries for those listings while physically counting; no stock is reserved by this workflow. A changed listing/version at submission requires refresh and recount. Submitted counts retain quantity, unit/name/SKU snapshots, system balance, discrepancy, counter and timestamp. Employees see only their own counts and no costs; the owner sees all counts and valuation details.

The owner's own authenticated decision approves or rejects the entire count with confirmation and a reason. Approval rechecks every product version, quantity and carried value; a sale, delivery, catalogue edit or another adjustment after submission makes the count stale. Reject stale counts and submit fresh physical counts rather than overwriting intervening activity. Rejected/approved records cannot be edited or decided differently. Matching retries return the existing count/decision.

Approval posts one linked stock movement per line, including explicit zero discrepancies, at the current Nairobi business date. Stock, valuations, status, approver and audit history commit atomically; no cash, sales or debts are created. Known stock retains its carried average cost, rounded half up to cents; empty balances become known zero. Found stock on an empty balance, or adjustments to unknown-cost stock, retain unknown valuation until emptied. Catalogue reference purchase cost never silently supplies a stock valuation. The owner stock ledger links each adjustment back to the count and reason. Further corrections require a fresh count.

## Expenses, withdrawals and money-out ledger

The owner uses **Money out** (`/owner/outflows`) after opening sign-off. Record operating expenses under rent, transport, electricity, wages or other; record owner withdrawals separately without an expense category. Each entry captures the positive exact KES amount, recipient, reason/supporting reference, cash or outgoing M-Pesa method, authenticated owner and current Nairobi business date. Confirm that the funds actually left the shop and were not recorded previously. Outgoing codes are normalized and checked against supplier payments, previous owner entries and incoming claims, including pending/disputed claims. This records an existing payment; it does not initiate a transfer.

Entries and their audit events commit together. The submission key binds the payload; identical retries reuse the posted result, while changed payloads require a new key. Supplier deliveries continue to create their own outflow once and appear in the same ledger through their existing link. Record supplier purchases through stock delivery, rather than as operating expenses. Expenses/withdrawals never change stock, sales, customer debt or incoming collections. Posted entries have no edit/delete action; a reversal/correction workflow remains to be implemented.

The ledger defaults to today's Nairobi date. Filter inclusive dates, type and payment method, or choose All dates. Totals match all filtered entries across pages, with separate stock-purchase, expense and owner-withdrawal amounts plus cash/M-Pesa out. Withdrawals reduce shop funds and never count as operating expenses or profit deductions. Inventory purchases remain separate from operating expenses. No available-cash balance or profit figure is inferred here; opening cash, reconciliation and reporting are separate work.

The owner can download the matching full CSV across all pages, with original business dates, references, amount, method, recipient, reason and attribution. CSV text cells that could execute spreadsheet formulas are escaped. Downloads are audited and employees cannot access the ledger, entry forms/details or export, including direct URLs. The screens use shared light-mode palette tokens.

## Owner financial report

Use **Financial report** (`/owner/reports/financial`) from the owner dashboard or sidebar. It defaults to the current Nairobi day and accepts up to 366 inclusive business days. Employees cannot access the page/export, and the report service also checks active owner access. A shared posting lock keeps sales, payments, outflows, debt and inventory readings in one consistent report snapshot. There is no schema migration or fabricated report data.

Period sales include posted sales only, using immutable gross, discount, net and cost snapshots. Opening debts and repayments never create report sales. Gross margin is net sales less saved sale costs; operating profit subtracts operating expenses only. Stock purchases are inventory payments, and owner withdrawals reduce funds without reducing profit. If any selected sale lacks complete costs, both profit estimates are unavailable rather than assuming zero cost or copying current catalogue costs. Estimates exclude tax, unrecorded costs and stock-count valuation gains/losses; partial-return reporting awaits its workflow; void refunds are reported separately.

Collections use their original payment-entry business date and current verification status. Cash means retained funds after change, including instalments. Pending/disputed M-Pesa is shown separately and excluded from confirmed money. Verifying an older payment changes its original entry-period report; the owner decision date never replaces the collection date or original collector. The CSV records generation time so these snapshots are distinguishable.

Cash movement is confirmed cash collected less all recorded cash out, including purchases, expenses and withdrawals. It is not available drawer cash or a reconciled physical handover. Account activity separately attributes sales to sellers and payments to collectors, preserves inactive accounts and reports recorded cash movement. It does not assign another employee's instalment collection to the original seller or to the owner who verified it.

Current debt/inventory balances are shown in a separately labelled section across all dates, not as historical closing balances for the selected period. Outstanding debt includes pending payment exposure; opening, credit-sale, full-payment verification and unidentified balances are distinguished. Past-due amounts include opening/approved-credit balances due before today's Nairobi date; payment-verification exposure is listed separately. Known inventory value and the number of unknown-cost listings remain distinct.

Report sums use arbitrary-precision integers and exact two-decimal strings, including negative estimates and totals exceeding native integer range. Owner CSV exports include summary/current/account sections, opening readiness, selected dates and generation timestamp. Incomplete estimates export as `INCOMPLETE`; untrusted account names are escaped against spreadsheet formulas. Exports are audited. The report is computed from existing ledgers and does not write financial transactions.

## Sale voids and owner goods review

From sale details, an active employee can void their own completed sale with a reason and explicit confirmation; the owner can void any sale. One linked void record preserves the original seller, voiding actor/time/reason, reversed sale/cost amounts, canceled remaining debt and confirmed funds. Identical retries return the existing void; the original sale, lines, payments and allocation history are never deleted or rewritten.

Voiding cancels only that sale's remaining receivable and frees that debt exposure. Prior confirmed instalments remain allocated in history. Statements distinguish canceled debt from payments. Voiding does not increase stock: the owner uses **Voided sale review** (`/owner/sale-voids`) to physically account for all original units and either restock all as resellable or record no restock with a reason. Approval restores original saved sale costs alongside current carried stock, preserving unknown costs and recording linked current-day movements. Decisions are atomic/idempotent; further stock corrections use a new physical count. Partial returns and mixed per-line goods disposition remain separate future work.

Confirmed customer funds remain held and unresolved until the owner explicitly records a refund or retained credit after goods review. Voiding/reviewing goods alone never resolves funds. Pending checkout M-Pesa can still be verified/disputed using the original code/receiving number. Verification after a void increases held funds exactly once, without allocating to canceled debt; dispute creates no confirmed liability. A pending general instalment whose collectible balance disappeared needs owner reconciliation rather than allocation to the voided sale.

Reports exclude voided sales from sales/margin estimates while retaining actual collections and showing held void funds separately. Sale details and printable receipts clearly label the void and never claim a refund or paid-in-full sale. The stock ledger links each approved restock back to its original sale. Employees cannot view other employees' sale records, owner queues or approve stock disposition. No automatic financial payout or stock return occurs when a void is requested.

## Refunds and retained customer credit

After completing physical goods review and deciding every pending checkout claim, the owner can resolve the entire positive confirmed amount once from **Voided sale review**. A completed refund records the actual recipient, reason/evidence, cash or M-Pesa method and the current Nairobi date. The owner confirms funds have actually been returned; this application records the payout and does not initiate a transfer. Outgoing codes are normalized and checked against incoming payments and every other outgoing entry. The amount is derived from confirmed funds, not supplied by the browser. Partial/split dispositions are not supported in this slice.

A refund creates one source-linked `refund` money-out entry and leaves the original collection/allocation history intact. Retained credit instead creates a separate customer liability with no cash out, income or silent debt offset. Named-sale credit must stay with that customer; for a walk-in sale, the owner explicitly selects and confirms a named customer. Customer identity/contact is snapshotted. Statements show retained credit separately from debt. Checkout credit spending, transfer and debt-offset workflows remain unavailable; the owner can instead return a retained balance through the payout action below.

One resolution per void, unique submission keys and transaction retries prevent duplicate refund/credit posting. Different terms cannot overwrite a completed disposition. Resolution, money-out/customer-credit rows, customer version and audit changes commit atomically. Resolved voids leave the open queue and remain visible in All voids, sale details and receipts. Money-out details/CSV retain the original sale link. No stock movement is created by financial resolution.

Reports preserve gross confirmed collections, show refunds separately and calculate collections less refunds; cash movement includes cash refund payouts. Refunds are not operating expenses and do not double-deduct a voided sale from profit. Unresolved void funds exclude completed resolutions, while retained credit is shown separately as a current liability. Default/production seeding never fabricates financial records; the explicitly requested local demo dataset includes labelled examples.

From an owner's customer statement, **Return retained credit** records a completed full cash/M-Pesa payout of the remaining balance. The server derives the amount, checks outgoing/incoming code uniqueness and creates one linked refund outflow and payout record while reducing that credit balance to zero. Customer version and audit entries commit atomically. The original credit-resolution and collection records remain intact; no sale, income, debt offset or stock movement is created. Retry keys and unique credit/outflow links prevent duplicate payouts. Details and the voided-sale receipt retain the later payout evidence. Partial payouts and checkout redemption are not supported yet.

## Light-mode palette

The user's revised light-mode preference replaces the initial brief palette. The recommendation is warm ivory `#F7F6F2` backgrounds, white `#FFFFFF` cards, slate `#25332F` text, forest `#183C34` navigation and deep teal `#176B5B` actions with white text. A restrained sand-gold `#E7BC78` brand mark warms the sidebar; pale neutral surfaces replace the earlier mint tint. Legacy cards and the welcome screen now use shared semantic tokens, alongside all newer screens.

Calculated contrast is 12.19:1 for body text, 6.38:1 for primary buttons, 5.26:1 for muted text, 11.21:1 for navigation, 8.56:1 for selected navigation and 3.37:1 for input borders. These selected pairs exceed the [WCAG minimum text contrast](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) and [non-text contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html) targets. Status labels remain explicit; browser/device validation is still pending. Dark-mode tokens are preserved.

## Verification

```text
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress
php artisan test
npm.cmd run check
npm.cmd run types:check
npm.cmd run build
```

Feature tests cover owner/employee boundaries, direct requests, forged roles, duplicate email, password validation, deactivation with preserved identity, repeated deactivation, session revocation, fresh login after reactivation, first-password enforcement, registration/deletion denial, and owner setup. Catalogue/customer tests also cover exact money, unknown costs, separate bag/kg listings, duplicate submissions and contacts, stock-input rejection, unit precision, price history, stale edits, cost/limit redaction, search/filter/pagination, and rollback on audit failure. Most tests use an isolated in-memory SQLite database; the checkout race test uses a guarded temporary file database. PostgreSQL integration and a browser walkthrough remain necessary before deployment. Automatic approval review blocked the earlier attempted isolated browser/server launch with “blocked by policy”; no browser validation is claimed.

Observed catalogue/customer verification: 68 backend tests pass with 475 assertions; PHPStan, TypeScript checking, frontend lint/format checking and production build pass. Migration apply/rollback/reapply is checked in a separate SQLite database before local application. No real shop catalogue, customer or debt records are fabricated.

Opening/stock tests cover inert/retried drafts, duplicate posting/imports, exact fractional costing, missing costs, weighted averages, full supplier payment, duplicate outgoing codes, CSV headers/units/customer matching, shared dates, sign-off reconciliation, stale counts, cancelled/posted guards, multi-row rollback, audit failure and employee denial. No browser walkthrough or PostgreSQL concurrency test is claimed for this slice.

Observed full-suite verification after the owner settings/opening/stock slice: **90 tests, 894 assertions**, PHPStan, TypeScript, lint/format and the production build pass. Local migration was applied after a SQLite backup; its apply/rollback/reapply cycle also passed in a separate database. The real shop settings and opening records remain for the owner to enter and approve.

Cash checkout tests cover net cash/change, price/stock rechecks, identical and changed retries, employee/privacy restrictions, short/forged payments, fractional cost allocation/final depletion, missing costs, immutable/escaped receipt snapshots, Nairobi dates, multi-line and audit rollback, pagination and migration safety. `CheckoutConcurrencyTest` starts two PHP processes with a barrier after both read the last unit in a guarded SQLite WAL test database. Exactly one sale/payment/deduction must commit and the other must reject the sold-out quantity. Temporary fixtures are isolated from the shop database and cleaned after the test. PostgreSQL locking and browser/printer validation remain pending.

Observed full-suite verification after cash checkout: **112 tests, 1,319 assertions**, PHPStan, TypeScript, lint/format and production build pass. The migration apply/rollback/reapply cycle passed in an isolated SQLite database; tests also preserve existing stock through that round-trip and verify rollback refusal after a posted sale. The local schema was applied after a SQLite backup. No operational sales/payments were seeded.

Observed verification after manual M-Pesa: **132 tests, 1,704 assertions**, PHPStan, TypeScript, lint/format and production build pass. Coverage includes old-number verification, stale checkout rejection, case-normalized duplicate codes, pending/disputed debt and totals, owner-only decisions, allocation/audit rollback, amount/confirmation checks, incoming/outgoing code reuse, customer statements and guarded schema rollback. Three process-level races cover cash last-unit checkout, duplicate incoming M-Pesa codes and duplicate verification. Migration apply/rollback/reapply passed in a separate SQLite database. Browser/printer and PostgreSQL validation remain pending.

Observed verification after approvals/instalments: **150 tests, 1,946 assertions**, PHPStan, TypeScript, lint/format and production build pass. New coverage includes development login/production guards, preserved credentials on reseeding, owner-only approval, request privacy, frozen terms, expiry/price/customer changes, limits at each posting stage, exact discount residuals, deposits/full credit, oldest-first allocation, pending reservations, verification/dispute, audit rollback and employee receipt privacy. A fourth process race verifies that simultaneous collections cannot overpay the final balance. Migration apply/rollback/reapply passes in isolated SQLite; the local migration was applied after an integrity-checked backup. Only the requested development users were seeded. Browser/printer and PostgreSQL validation remain pending.

Observed verification after physical counts: **166 tests, 2,116 assertions**, PHPStan, TypeScript, lint/format and production build pass. Coverage includes inert/private employee counts, owner-only decisions, duplicate submissions/approvals, stale counts after checkout, multi-line and audit rollback, positive/negative/zero discrepancies, unknown costs, exact fractional valuation, overflow rejection, movement-source constraints and guarded migration round-trips. A fifth process race verifies two simultaneous count approvals create one adjustment. Calculated light-mode text contrast meets 4.5:1 for the documented text pairs; input borders exceed 3:1. Browser/device and PostgreSQL validation remain pending.

Observed verification after expenses/withdrawals: **181 tests, 2,297 assertions**, PHPStan, TypeScript, lint/format and production build pass. Coverage includes owner-only entry/detail/export access, distinct expense/withdrawal totals, exact amounts and Nairobi dates, idempotent posting, incoming/outgoing code reuse, confirmation/forged-field rejection, audit rollback, inclusive filters, full CSV across pagination, spreadsheet formula escaping, aggregate limits and migration preservation/rollback guards. A sixth process race verifies simultaneous retries create one expense. The local migration was applied after an integrity-checked backup; development users remain active and no money-out entries were seeded. Browser/device and PostgreSQL validation remain pending.

Observed verification after financial reporting: **192 tests, 2,390 assertions**, PHPStan, TypeScript, lint/format and production build pass. Report coverage includes revenue versus opening-debt collections, discounted credit deposits, cost/expense/withdrawal separation, unknown historical costs, negative estimates, original-date verification status, seller/collector attribution, inactive accounts, Nairobi date boundaries, owner-only access, audited CSV/formula escaping and totals above native integer range. No migration or operational records were added. Browser/device and PostgreSQL validation remain pending.

Observed verification after sale voids: **210 tests, 2,558 assertions**, PHPStan, TypeScript, lint/format and production build pass. Coverage includes owner/own-sale authorization, immutable/idempotent voids, explicit sale/cost reversal snapshots, debt cancellation with preserved allocations, pending/disputed/verified void claims, full-credit voids without fictitious money, unknown/original costs on restock, terminal stock decisions, audit rollback, statement/receipt/report labels and protected migration round-trips. Eight process races now include duplicate voids and duplicate restock approvals. The local migration was applied after an integrity-checked backup, with development users preserved and no voids seeded. Refund/retained-credit resolution, partial returns, browser/device and PostgreSQL validation remain pending.

Observed verification after refund/credit resolution and the revised light palette: **222 tests, 2,675 assertions**, PHPStan, TypeScript, lint/format and production build pass. Coverage includes full cash/M-Pesa refunds, retained credit liabilities/customer statements, original collection preservation, owner-only decisions, pending/goods-review guards, customer identity binding, duplicate keys/codes, atomic audit rollback, source-linked outflows, report separation and protected migration round-trips. Ten process races now include duplicate refund and retained-credit requests. Selected light-mode text/control pairs pass calculated contrast targets. Credit redemption/offset, partial returns, browser/device and PostgreSQL validation remain pending.

Observed verification after retained-credit payouts and demo data: **233 tests, 2,810 assertions**, PHPStan, TypeScript, lint/format and production build pass. Coverage includes owner-only full credit payouts, original collection preservation, code/amount/confirmation guards, atomic rollback, protected migration round-trips, local-only demo seeding, established-shop refusal, rerun preservation, dataset rollback and stock-movement reconciliation. Eleven process races include duplicate retained-credit payouts. The local database was backed up, migrated and populated with the explicitly requested demo dataset; the prior catalogue entry and login accounts were preserved. Browser/device and PostgreSQL validation remain pending.

## Deployment and rollback

Back up the database before applying migrations. The access migration adds columns and an audit table without deleting accounts; existing accounts default to active employees. Deploy code and migration together during maintenance because new code requires the new columns. Keep the session and cache stores shared across application instances. Production requires HTTPS and working mail delivery.

The catalogue/customer migration creates four tables and adds resource columns to audit events. It does not seed products/customers or alter existing account privileges. Back up before migrating. Its rollback drops catalogue/customer and price-history data and removes audit resource identifiers; use a matching database backup when reversing a deployment with real data. It does not remove the access-foundation audit events.

The opening/stock migration adds the settings singleton, two product valuation columns and the stock/debt/purchase tables. Legacy nonzero quantities are marked as having unknown value. It seeds no operational shop records. Apply code and migration together after a backup. Its rollback drops posted stock/debt/purchase records and valuation columns; do not run it against real posted balances as an operational correction. Restore a matching code/database backup to reverse the deployment. Apply/rollback/reapply is exercised on a separate SQLite database before the local migration.

The cash-sale migration adds sales/items/payments, allows a nullable batch source on movements and adds the exclusive sale/batch source constraint. Existing stock movements remain linked to their batch items. Back up before deploying code and migration together. Schema rollback is allowed only when no sales exist; after any sale it deliberately refuses, because dropping sales would otherwise leave their stock deductions behind. Restore matching code/database backups for a deployment rollback with live sales. Voids use linked compensating records; refunds require their own financial workflow, never schema rollback.

The manual M-Pesa migration adds checkout method/payer reference, a sale-linked receivable and verification/allocation history. Receivable customer IDs become nullable so unidentified pending claims remain traceable. Existing cash sales default to cash; their amounts, stock and confirmations are preserved. Back up and deploy matching code/migration together. Rollback is allowed only without manual payment/settlement history; otherwise it refuses and requires a matching database backup. No real payment number, claim or balance is seeded.

The approval/instalment migration adds immutable sale requests, approved credit/due dates, line gross/discount values and payment purpose, retry hashes, allocation exceptions and receipt snapshots. Existing payments default to checkout; existing line gross values are backfilled from their totals. Apply with matching code after backup. Rollback refuses after any approval request or instalment, including pending requests; restore matching code/database backups instead of discarding financial or approval history.

The stock-count migration adds count headers/items and a unique count-item movement source. It replaces the movement source constraint with an exactly-one-of-batch/sale/count rule on SQLite and PostgreSQL. Existing stock/movements are preserved. Deploy matching code and migration after backup; rollback is allowed only with no count history. A pending or rejected count also prevents schema rollback so its review/audit evidence is not discarded. Operational corrections always use new count/adjustment records, never schema rollback.

The owner-outflow migration preserves supplier payments and allows a nullable purchase batch for explicit owner entries. It adds unique submission keys, payload hashes, category, recipient and reason, plus a source constraint on SQLite/PostgreSQL. Apply matching code/migration after backup. Rollback is allowed only without expense/withdrawal history; otherwise restore matching code/database backups. It never deletes live money-out records as an operational correction.

The sale-void migration adds linked void headers/items and a unique void-item stock movement source. It preserves sale/payment/allocation history and extends the exactly-one-source check to batch, sale, count or void. Apply matching code/migration after backup. Rollback refuses any void history; restore matching backups instead of deleting reversals or leaving restocked quantities without their source.

The void-resolution migration adds immutable resolution/customer-credit records and a unique refund source on money out. Existing purchase, expense and withdrawal records are preserved. Deploy matching code/migration after backup. Rollback refuses after any refund/credit resolution; restore matching backups instead of discarding payouts or customer liabilities.

The retained-credit payout migration adds source-linked, uniquely keyed payout records without deleting or reclassifying existing credits. Its rollback refuses paid history and otherwise preserves retained balances. Restore matching code/database backups after real payouts instead of dropping their evidence. Demo fixtures are loaded only through the separately invoked, environment-guarded seeder; schema migrations never fabricate them.

To roll back a deployment, enter maintenance mode, preserve/export audit events and restore matching code/database backups. Employee reactivation and stock corrections use their explicit application workflows instead.

## Remaining deployment acceptance

1. Actual shop accounts, payment number and opening balances, independently reconciled and signed off by the owner.
2. PostgreSQL integration, physical printer and production hosting/mail validation.

Use integer KES minor units, fixed decimal quantities, explicit stock movements and payment allocations. Pending M-Pesa must not settle confirmed debt or collections. Production PostgreSQL is proposed; the current development setup remains unchanged. Live STK Push is deferred.

## November 24 completion work

See [implementation status](docs/implementation-status.md) and [operator guidance](docs/operator-guide.md). Added partial returns with an exact server preview, damaged-goods history, debt write-offs, retained-credit offsets and checkout redemption, full payment/delivery reversals with reviewed replacements, bulk catalogue preview/posting, opening-debt name/contact matching and daily employee/owner cash reconciliation.

The homepage, authentication layout, login, team-access signup guidance and owner account-creation form were redesigned. Reports now provide daily trends and period presets, product performance and stock valuation, low-stock queues, debt aging, employee/collector activity, handovers, complete financial breakdowns, print view and filtered CSV datasets. Original entries and source attribution remain preserved.

Verification: **255 tests, 2,997 assertions** pass; PHPStan passes. PHP formatting, TypeScript, frontend lint/format and production build pass. Browser evidence is in `storage/app/qa`. New migrations were applied locally after integrity/restore-checked backups in `storage/app/backups`. Their rollback guards preserve operational history. SQLite table rebuilds explicitly restore the outflow validation triggers. No fabricated new shop transactions were posted by this work. A real shop pilot, physical printer test and PostgreSQL validation remain outstanding. Live STK Push remains deferred.


## User guide for staff and the owner

Share the [printable PDF user guide](docs/user-guide/Kaura-Hardware-User-Guide.pdf), or customize the [editable Word copy](docs/user-guide/Kaura-Hardware-User-Guide.docx). A [browser-readable copy](docs/user-guide/Kaura-Hardware-User-Guide.html) is also included. The 14-section guide covers the grouped menus, mobile cart, daily sales/payments, owner reviews, stock, returns, cash handover, reports and troubleshooting. Complete the website and help-contact fields on the first page before distribution.
