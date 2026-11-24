# Kaura Hardware Inventory — Development Specification

**Version:** 1.1 · **Date:** 22 November 2026 · **Status:** Ready for phased development. Business decisions below are supplied by the owner's son. Implementation defaults are labelled as such.

## 1. Purpose and scope

Build a Laravel + Inertia.js + React + TypeScript system for one Kenyan hardware shop with approximately 100–500 product listings and 3–5 employees besides the owner. The shop currently uses paper records. The system must track stock, sales, money collected, customer debts, expenses, estimated profit, and each employee's activity. Currency: KES; confirm the shop's legal name and actual catalogue during setup.

**First release:** owner and employee accounts; catalogue and stock; owner-entered stock receipts; sales and printable receipts; cash and manually recorded M-Pesa to the owner's configurable number; owner-approved discounts and customer credit; instalments and debt tracking; voids, stock counts and adjustments; expenses and owner withdrawals; daily financial and employee reports; opening data import. **Later release:** Paybill/Till and live STK Push. There is currently no Till or Paybill. Do not activate or advertise a working STK Push payment button without eligible merchant credentials and verified integration.

## 2. Users and permissions

| Action                                                                        | Owner                              | Employee                               |
| ----------------------------------------------------------------------------- | ---------------------------------- | -------------------------------------- |
| View all sales, payments, customer debts, financial reports and audit history | Yes                                | No; own sales and payment records only |
| View products and stock quantities                                            | Yes                                | Yes                                    |
| Add customers; create sales; record cash or pending M-Pesa payments           | Yes                                | Yes, attributed to their own account   |
| Enter stock receipts or opening stock; change selling prices                  | Yes                                | No                                     |
| Approve discounts and credit; set each customer's credit limit                | Yes, through own signed-in account | Request only                           |
| Create physical stock counts and report discrepancies                         | Yes                                | Yes                                    |
| Approve stock adjustments or refunds                                          | Yes                                | No                                     |
| Void a completed sale with a mandatory reason                                 | Yes                                | Yes, subject to rules in §5            |
| Change manual M-Pesa receiving number; verify incoming M-Pesa payments        | Yes                                | No                                     |
| Create expenses and owner withdrawals                                         | Yes                                | No by default                          |
| Manage accounts and permissions                                               | Yes                                | No                                     |

Use server-side authorization for all actions and record ownership. Deactivating an employee must preserve their historical attribution. Every sensitive event records actor, time, reason, and before/after state. Owner approval uses the owner's own authenticated session; never allow an employee to approve on the owner's behalf by entering a shared PIN.

## 3. Catalogue and inventory

Each sellable unit is a **separate product listing**: for example, a bag and a kilogram have distinct SKUs, prices, and stock balances. Do not assume automatic conversion or shared stock between them. Where the shop needs to break one bag into kilograms, the owner explicitly records a conversion transaction that deducts the source SKU and adds the destination SKU using a product-specific quantity and cost rule configured for that conversion. Until configured, conversion is unavailable. No stock may be created implicitly.

Product fields: unique SKU, name, category, unit label, purchase cost, selling price, reorder threshold, optional barcode, active flag. Provide fast search, filters, pagination, and low stock alerts. Keep price/cost history and snapshot the relevant values onto sale lines. Owner enters supplier and stock delivery details, quantity and cost. Posting a receipt increases stock once only. Physical counts entered by staff do not alter stock until owner approval; preserve counted quantity, system quantity at count time, discrepancy, reason and approver. Corrections use explicit movements, never silent quantity edits. Prevent sale of unavailable stock and double selling during concurrent checkout.

## 4. Sales and payment workflow

An employee builds a cart at current prices, identifies a customer for credit sales, and chooses cash, manual M-Pesa, or an approved credit balance; allow a deposit and a remaining credit amount. Completed checkout atomically records sale, line items, stock deductions and payment or debt entries. Draft carts do not affect stock. Issue a numbered **viewable and printable simple receipt** with shop identity, date, sale number, employee, items, units, quantities, prices, totals, payments, and any balance due. No WhatsApp receipt or eTIMS invoice is required in the first release; the shop currently does not issue eTIMS invoices. Keep tax fields configurable for a later compliance review, without claiming tax compliance.

The owner approves a discount for the precise draft sale/line, amount, and expiry through their own account. Without that approval, use the standard price. Record the employee who sold and the employee who handled each payment, including later instalments. Each employee sees only their own sales and payment records; owner sees all.

### Manual M-Pesa

Initially, customers pay the owner's personal number. The owner may change the displayed receiving number in settings; changes affect new payments only. Snapshot the receiving number on each payment. Employee records amount, M-Pesa transaction code, payer reference if known and their account. Enforce unique codes. The payment is **pending verification** until the owner checks the receiving number's messages or statement later and marks it verified or disputed. Keep recorded, verified and disputed M-Pesa totals distinct. A disputed payment must not count as confirmed money received; preserve the sale and show the unresolved balance. Store old and new numbers in the restricted audit log when the setting changes.

## 5. Voids, returns and refunds

Any employee may void a completed sale, but must supply a reason. The original sale stays visible, with the voiding employee and time. Voiding uses compensating records for stock and the sale total; it must not silently delete payments or automatically return cash/M-Pesa. If a payment exists, flag it for the owner to resolve by confirming a refund or retaining a customer credit. A completed sale may be voided once; repeat requests are idempotent. Goods must be physically accounted for before any reversal increases available stock. Later customer returns link to the original sale and cannot exceed quantities sold less earlier returns. Only the owner authorizes refunds, chooses refund method, and decides whether returned goods are resellable before restocking. Reconcile credit balances when a sale or item is reversed; preserve a full audit trail.

## 6. Credit and instalments

Owner must approve each credit sale from their account. Each customer has an owner-set maximum outstanding credit balance. Credit requests capture customer identity/contact, proposed sale, any deposit, balance, and the **repayment date the customer promises**; reject a credit sale that exceeds the available limit unless the owner explicitly changes that customer's limit first. Permit multiple instalments via cash or manual M-Pesa. Apply every **verified** instalment automatically to that customer's oldest outstanding sale first, then the next oldest, recording allocation rows and any exceptions. Pending M-Pesa instalments are shown separately and do not permanently reduce confirmed debt until verified. Prevent duplicate payment posting or overpayment. Show customer statements, opening debt, sale balances, due dates, overdue balances and full collection history. A payment against an imported opening debt follows the same oldest-first rule. Owner-only write-offs are explicit, reasoned events.

## 7. Money management and reporting

Record all money leaving the shop, with a separate type for stock purchases, operating expenses (rent, transport, electricity, wages and other categories), and **owner withdrawals**. Supplier purchases are paid when goods are delivered; record both stock receipt and cash outflow, once each. Owner withdrawals reduce available cash but are never operating expenses or profit deductions. Provide day and date-range views and owner-only CSV exports.

| Metric                     | Rule                                                                                                                                                 |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Gross sales                | Total posted sales before discounts and returns, excluding voided sales                                                                              |
| Net sales                  | Gross sales less approved discounts and returns                                                                                                      |
| Confirmed collections      | Cash collected plus owner-verified M-Pesa, including repayments of old debt; show unverified M-Pesa separately                                       |
| Customer debt              | Opening unpaid debts plus posted credit sales less verified instalments, eligible returns and explicit write-offs; show pending payments separately  |
| Gross margin estimate      | Net sales less cost of goods sold, using a declared costing method and recorded product cost snapshots                                               |
| Estimated operating profit | Gross margin less recorded operating expenses; stock purchases are inventory cost, not an immediate second expense; owner withdrawals are excluded   |
| Cash reconciliation        | Cash received less cash expenses, cash stock purchases and owner withdrawals, compared with actual cash handed over; show discrepancies per employee |

For incomplete purchase costs or opening stock values, flag margin/profit as incomplete rather than inventing figures. Show sales and collections by employee, payment verification queues, stock valuation, low stock, overdue debt, expenses, withdrawals, and void/refund audit events. Define the inventory cost method (implementation default: weighted average) consistently before calculating margin.

## 8. Launch data migration

Import **product listings, physically counted opening stock, and customers' outstanding debts** from paper records. Do not import old sales as new transactions. Opening stock and debt entries carry an as-of date, source note and owner approval; opening debt is not current-period sales. Review duplicates, missing cost values and customer matches before posting. Keep a signed-off import summary and allow reconciliation to the paper totals. Start new sales in the app only after the opening balances have been approved.

## 9. Design and technical architecture

Palette: `#143109` deep green, `#FFFBFC` off-white, `#0B132B` navy, `#0E9594` teal, `#127475` dark teal. Use navy/deep green for text and major surfaces, off-white for light backgrounds, teal for primary actions and dark teal for accents. Check contrast; pair status colours with labels/icons. Provide responsive desktop and phone views suited to the counter.

Laravel backend with Inertia.js, React and TypeScript frontend; PostgreSQL is the proposed database unless deployment constraints dictate otherwise. Use Laravel sessions, CSRF protection, policies, request validation and database transactions. Maintain immutable transaction/stock history and explicit reversal entries. Use integer minor units or fixed decimals for KES, fixed decimal stock quantities, indexed SKUs and references, unique M-Pesa codes, and idempotency on posting actions. Keep business logic in domain services and typed page props. Use HTTPS, protected secrets, backups with a restore check, and server-side audit logging. Store timestamps in UTC; display shop days in Africa/Nairobi.

Suggested entities: users/roles/permissions, customers/credit limits, products/categories, suppliers, stock receipts/items/movements/counts, sales/items/voids/returns, payments/allocations/verifications, expenses, owner withdrawals, opening balances and audit events. The implementer should normalize this into migrations and document all invariants before coding.

## 10. Delivery plan and acceptance criteria

1. **Foundation:** accounts, server permissions, catalogue, customers, owner settings and audit history. Staff cannot access other employees' sales/payments or owner financial views.
2. **Opening balances and stock:** import review, opening stock/debt, owner-posted deliveries, count and adjustment approvals. Stock movement totals reconcile to current quantities; unit listings stay distinct.
3. **Sales and credit:** atomic checkout, printable receipt, owner discount/credit approval, per-customer limit, promised date, instalments allocated oldest-first, staff void with reason, owner refunds. Concurrent checkout cannot oversell; voided paid sales surface owner resolution.
4. **Payments and controls:** cash handling, manually recorded M-Pesa with later owner verification, editable receiving number with historical snapshot, expense/withdrawal ledger, debt and financial dashboards, employee reconciliation and exports. Pending M-Pesa never inflates confirmed receipts.
5. **Pilot:** verify imported balances, test real daily sales with owner and 3–5 staff, reconcile cash, M-Pesa and physical stock, train staff, then go live.
6. **Later Paybill/STK Push:** only after the owner obtains an eligible business Till/Paybill or approved provider and production credentials. Initiate prompt to customer's phone, never collect PIN, verify authenticated callback/status, prevent duplicate charges, and reconcile. Changing the manual receiving number alone never changes the merchant destination. This is explicitly outside first-release activation.

For each phase, provide migrations, policies, typed Inertia props, usable UI, meaningful posting/authorization tests, and deployment and rollback notes. Never populate production with fabricated sales or debts.

## 11. Implementation brief for the coding AI

Use this document as the approved product scope. Before writing code, inspect the existing repository, propose the concrete schema, permission matrix, page map, report formulas and phased task list. Implement one phase at a time and show tests and observed results. For minor unspecified details, state a reasonable default and proceed; do not change the confirmed rules above. Reserve integrations and eTIMS for their stated later scope. Confirm operational facts only where real data or credentials are required (shop name, opening inventory/debt, actual M-Pesa number, hosting, merchant credentials). No STK Push activation without a real merchant setup.

## 12. Detailed task workflows for the first release

The workflow owner is the role that may complete the task. A request or draft is not a posted transaction. Every posted event has a unique reference, server timestamp, actor and audit trail. Interfaces should show the saved state immediately and make retry behaviour clear.

### 12.1 Sign in, access and staff management

1. Owner creates an employee account with name and login identifier, selects the Employee role, and provides a secure initial sign-in method. The system records who created it and requires a password change on first use where applicable.
2. Employee signs in; server loads their permissions and exposes the employee workspace (own sales/payments, products and stock, customers, counts). Owner signs in to the owner workspace (all records, approvals, settings, reports).
3. Each page and action checks authorization again on the server and scopes employee queries to their own sales and collections. Direct URL or API requests cannot read another employee's records.
4. Owner deactivates an account when needed. Existing sessions are revoked, new sign-ins fail, and all historical sale/payment attributions remain intact. Owner can review login and denied-action history.

### 12.2 Add or edit product

1. Owner chooses **Products → Add product**; enters SKU, name, category, exact selling unit (piece, bag, kg, etc.), selling price, cost and low-stock threshold. An individual piece and a bag are entered as different products with different SKUs.
2. System validates required fields, unique SKU, nonnegative monetary values and sensible decimal precision for the chosen unit. It offers matching names to help prevent accidental duplicate listings.
3. Owner saves; the listing starts with zero stock until an opening balance or stock receipt is posted. Staff can search it but cannot edit price or cost.
4. Later price changes require owner login, new price and effective time; record old/new price. Existing sale line snapshots never change. Deactivation hides a product from new sales but retains history.
5. If the owner chooses to convert stock between separate unit listings, require an explicitly configured conversion ratio, input/output SKUs, quantities and cost allocation; post both movements atomically and refuse conversion when source stock is insufficient.

### 12.3 Enter opening products and stock

1. Owner prepares the 100–500 product listings and physically counts stock by SKU and unit on a selected as-of date. Staff may assist with counts; only the owner posts the import.
2. System previews import/entry rows, duplicate SKUs, missing costs, negative quantities and mismatched unit labels; owner corrects errors before confirmation.
3. Owner signs off the opening batch. System posts one opening stock movement per SKU, stores source note and as-of date, and prevents posting the same batch twice.
4. Owner compares the summary against paper counts. Any later correction goes through an approved stock adjustment, leaving the original opening batch visible. Missing costs cause inventory value and profit estimates to carry an incomplete-data warning.

### 12.4 Receive and pay for new stock

1. Owner selects supplier (or adds one), enters delivery date, reference, SKU, quantity and unit cost for each item, and the amount paid when delivered.
2. System checks active SKUs, positive quantities, nonnegative costs, duplicate delivery references and the available payment method. Draft receipt does not change stock or cash.
3. Owner reviews and posts. In one database transaction, system saves receipt/items, increases stock, updates weighted-average cost if applicable, and records the supplier payment as a stock-purchase cash outflow. The same purchase must not also become an operating expense.
4. System shows receipt reference and updated balances. Duplicate submit returns the existing result; a later correction is a dated reversal or new adjustment requiring the owner's reason.

### 12.5 Create customer and opening debt

1. Owner or employee adds a customer with name and contact. System suggests possible matches to avoid duplicate accounts; staff may add customers but cannot set credit limits.
2. Owner sets that customer's maximum outstanding credit and may change it later with an audit record.
3. For old paper debts, owner enters customer, original debt amount, paper source and due/as-of date. A preview shows totals across customers before posting.
4. Owner approves the opening batch. System creates opening receivables, not new sales, and includes them in the customer's oldest-first payment allocation queue. Duplicate batches are rejected.

### 12.6 Cash sale

1. Employee searches products, adds SKU and quantity, and sees price, stock and total. System rechecks stock and current prices at final checkout.
2. Customer pays cash; employee enters cash received, system calculates change and rejects an amount below total unless an owner-approved credit portion exists.
3. On confirmation, system atomically posts sale, item cost snapshots, stock deductions and cash payment with employee attribution; it issues a unique sale number and printable receipt.
4. Employee hands over change and goods, then optionally prints the receipt. Owner sees the sale immediately; employee sees it under their own sales.
5. If stock or price changed during checkout, system blocks posting and asks employee to review the updated cart. Repeated submission must not create two sales.

### 12.7 Manual M-Pesa sale

1. Employee shows the currently configured receiving number and exact amount. Customer pays from their phone; employee enters transaction code, amount and optional payer reference.
2. System checks code uniqueness and saves the sale/payment with a **pending verification** label. It stores the receiving-number snapshot and employee identity. Receipt displays M-Pesa as pending, never as owner-confirmed.
3. Later, owner opens **Payments → Pending verification**, compares code and amount with their M-Pesa message or statement and selects **Verify** or **Dispute**, with reason for dispute.
4. Verification moves the amount into confirmed collections and settles the applicable sale/debt balance. Dispute leaves a visible unresolved amount and alerts the employee/owner for follow-up; neither deleting the sale nor crediting confirmed collections is allowed.
5. If the customer cannot provide a code, save a draft or a clearly flagged unconfirmed claim according to the chosen checkout UI; never silently mark the payment successful.

### 12.8 Change the M-Pesa receiving number

1. Owner opens **Settings → Manual M-Pesa number**, enters and confirms the replacement number in their authenticated account.
2. System validates Kenyan phone format and warns that the change affects future payments only. Owner confirms; system records old/new number and actor in a restricted audit entry.
3. New checkout screens use the new number. Previously recorded payments keep the old number and must be reconciled against that number. Staff may see the current checkout number but cannot edit it.
4. This setting does not configure any future STK Push merchant account.

### 12.9 Request and approve a discount

1. Employee builds a draft sale, enters the requested discount amount and reason, and sends an approval request; the original price remains unchanged.
2. Owner signs into their own account, reviews items, base price, discount and resulting total, then approves or rejects. System binds approval to that draft's exact items/quantities/amount and an expiry.
3. Employee refreshes checkout. Approved discount applies once; rejected or expired approval means normal price or a new request. If cart content changes, approval expires.
4. Posted sale and receipt store original price, discount, approver and final price. Audit logs both request and decision.

### 12.10 Request and approve a credit sale

1. Employee chooses an existing customer, records the customer's promised repayment date, proposed credit amount and any deposit; system shows confirmed outstanding balance, pending M-Pesa amounts and customer limit.
2. System blocks a request that would exceed the limit. Owner may separately raise that customer's limit in their account, with a reason, then review the new request.
3. Owner approves or rejects the exact customer, sale, deposit, balance and due date from their own account. Approval expires if the cart or amounts change.
4. Employee completes checkout. System posts sale/stock, deposit payment (cash immediately; M-Pesa pending verification), credit balance and due date atomically. It produces a receipt with total, deposit and amount owed.
5. If an M-Pesa deposit later fails verification, the unpaid balance is flagged for owner resolution; do not silently change the agreed credit limit or falsify confirmed collections.

### 12.11 Receive a credit instalment and allocate oldest first

1. Employee selects a customer and sees oldest outstanding debts and proposed repayment amount. Enter cash payment or manual M-Pesa code; employee identity and date are captured.
2. System rejects duplicate code and payments above the customer's confirmed balance (subject to separately visible pending payments). A cash payment is posted immediately; a manual M-Pesa instalment stays pending.
3. On cash posting or owner M-Pesa verification, system allocates payment first to the oldest dated opening debt or sale, then in order to the next oldest, storing each allocation. Tie-break by immutable record ID.
4. Customer statement and remaining balances update; the original sale total is unchanged. Generate a printable payment receipt showing paid amount, allocations and remaining debt.
5. If a pending M-Pesa payment is disputed, its provisional allocation is removed or never posted as confirmed, and corresponding debt remains due. Reversals preserve payment and allocation history.

### 12.12 Physical stock count and adjustment

1. Employee or owner starts a count, selects SKUs and records observed quantities. System captures the expected quantities and count time, then shows variance. Counting does not alter available stock.
2. Employee submits discrepancy and explanation. Owner reviews physical evidence, concurrent sales/receipts since the count, and any changes to expected quantity.
3. Owner approves a recalculated adjustment or rejects the count. On approval, system posts only the necessary stock movement against the current stock ledger and logs count, variance, reason and approver. If stock changed since count, system requires a fresh review so an old count cannot overwrite recent transactions.
4. Rejected counts remain visible but cause no stock movement. Owner can export variance history.

### 12.13 Void a sale, return items and resolve payments

1. Employee finds an eligible completed sale (subject to access to their own sales) and selects **Void**, giving a mandatory reason. Owner can void any sale. System checks it has not already been voided and locks it against duplicate requests.
2. System reverses the sale with linked compensating entries and preserves the original. Stock returns to sellable inventory only after the goods are confirmed present and resellable; otherwise keep it in a pending/damaged state. It does **not** auto-refund cash or M-Pesa. If paid or partly paid, create an owner resolution item showing amount received and amount to refund or retain as customer credit.
3. Only owner confirms a refund, payment reversal or customer credit with reason and method. If goods have left the shop, owner verifies their condition before stock becomes sellable; damaged goods go to a separate damaged movement/status instead of available stock.
4. For partial returns, owner links items to original sale, limits quantities to the unreturned portion, approves refund and resellable status, and posts the corresponding stock, sale, debt and payment adjustments. Never duplicate a refund.

### 12.14 Record operating expense, stock payment or owner withdrawal

1. Owner selects the correct transaction type, amount, date, payment source, category/reference and note. Operating expense, supplier stock payment and owner withdrawal are distinct types.
2. System validates amount, requires a unique reference where appropriate and displays the effect on cash and reporting before confirmation.
3. On posting, ledger and cash totals update; operating expense reduces estimated operating profit, supplier payment reduces cash and settles a purchase already entered, and owner withdrawal reduces cash only.
4. Correction requires a reasoned reversal and replacement. A stock receipt's supplier payment cannot be entered twice as a separate expense.

### 12.15 End-of-day reconciliation and reports

1. Each employee opens **My day**, sees own cash sales, cash debt repayments, cash refunds if authorized, and M-Pesa entries split into pending/verified/disputed. Employee records cash handed over; owner can see all employees.
2. Owner compares physical cash and the M-Pesa statement(s) with recorded entries, verifies or disputes pending payments, reviews discrepancies, and records reconciliation status and notes. The app does not pretend manual M-Pesa entries are automatically confirmed.
3. Owner dashboard recalculates sales, verified collections, outstanding debt, expenses, owner withdrawals, margin and profit estimate for the selected Nairobi business day. Pending payments and missing costs remain separately flagged.
4. Owner can filter/export product, employee, customer debt, stock variance and financial reports. Employee reports expose only their own activity. Report exports retain the same permission rules.

### 12.16 Later STK Push workflow (not active in first release)

1. Owner obtains an eligible business Till/Paybill or approved provider and provides production credentials and merchant destination through a secure owner-only configuration process; a test verifies callback and settlement mapping.
2. Employee enters the customer's phone number and amount, then requests a prompt. System creates a unique attempt and sends the request. Customer approves on their own phone using their M-Pesa PIN; app never asks for it.
3. Provider callback/status is authenticated and reconciled to the attempt and sale/instalment. Only a confirmed successful result posts a verified payment. Cancellation, timeout or failure remains unpaid and retryable under an idempotent retry policy.
4. Duplicate callback, retry and network failure cannot create duplicate collections. Existing manual M-Pesa remains usable as a separate workflow. Changing the manual payment number does not change the merchant destination.

## 13. Cross-workflow state and integrity rules

| Record                | Valid progression                                                                | Key guard                                                                |
| --------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| Sale                  | Draft → awaiting owner approval (if needed) → posted → voided or partly returned | No stock or revenue impact in draft; void/return uses linked reversals   |
| Credit request        | Draft → pending → approved/rejected/expired → consumed                           | Exact cart/customer/amount/due date bound to owner approval              |
| Manual M-Pesa         | Recorded pending → verified or disputed                                          | Pending never increases confirmed collections or permanently clears debt |
| Stock count           | Draft → submitted → approved/rejected                                            | Only owner approval posts an adjustment                                  |
| Stock receipt         | Draft → posted → reversed if corrected                                           | Owner-only, one stock and one payment posting per receipt                |
| Expense or withdrawal | Draft → posted → reversed if corrected                                           | Classification controls profit calculation                               |

**Acceptance scenarios to automate:** two employees attempt to buy the last item; employee requests another employee's sale URL; discount approval is reused after a cart edit; credit request exceeds customer's limit; two instalments race to pay the same debt; M-Pesa transaction code is reused; owner changes receiving number while an old payment awaits verification; pending M-Pesa is disputed after goods leave; staff voids a paid sale; stock changes between a count and its approval; opening debt is paid without creating current sales; supplier stock payment is mistaken for an operating expense. Confirm that each case leaves stock, cash, debt and audit history consistent.

## 14. AI agent execution and completion checklist

Use this section as the delivery contract. Checkboxes are **not** claims that work is already done. The coding agent must mark each item only after observing the implementation and evidence in the actual repository. Record a short completion note with files changed, command/test results, UI evidence and any remaining limitation for each task. If a step depends on real shop data or an external credential, finish all independent code and mark that one step **Blocked: owner input/credential**, with the exact input needed; do not simulate live readiness.

### 14.1 Repeat this procedure for every task below

- [ ] Read the applicable product rule (§§1–11), workflow (§12) and state guard (§13); inspect repository conventions, schema, authentication and existing tests. Identify prerequisites and affected stock, cash, debt and permissions.
- [ ] Write a small task plan with the user-visible result, database changes, endpoints/policies, React/Inertia screens, failure cases and acceptance tests. Note any assumption before implementing it.
- [ ] Implement migrations with keys, constraints, indexes and a safe rollback; implement domain service and validation; use transactions, idempotency and audit events for posting operations.
- [ ] Implement authenticated routes/policies, typed page props, forms, loading/error/empty states and a usable responsive screen. Verify both owner and employee views, including direct-route denial.
- [ ] Add meaningful feature tests for success, rejection, authorization and at least one state or duplicate/concurrency risk. Verify calculations from stored transactions rather than repeating formulas in test fixtures.
- [ ] Run formatting, static analysis/type checking, relevant backend tests and frontend build using commands appropriate to the repository. Fix failures caused by the task; record unresolved environmental failures accurately.
- [ ] Walk through the workflow with seeded **test-only** data, including a realistic failure path; inspect resulting database records, UI totals and audit events. Never seed fabricated production activity.
- [ ] Update documentation and task status. Only check the task complete when the acceptance evidence matches the stated rule. State deployment or migration risks and how to roll back before moving to the next dependent task.

### 14.2 Foundation and access — workflow 12.1

- [ ] Implement owner and employee accounts, sign-in/sign-out, secure password handling, employee activation/deactivation and session revocation.
- [ ] Enforce owner-only settings/approvals/reports and employee ownership of sales/payments in policies and database queries; test direct URL/API attempts and export access.
- [ ] Preserve employee attribution after account deactivation; verify audit history records account changes and denied actions.
- [ ] **Done when:** an employee cannot see another employee's sale or owner dashboard through either UI or a crafted request; a deactivated employee loses access without erasing history.

### 14.3 Catalogue and units — workflow 12.2

- [ ] Implement categories, 100–500 searchable listings, unique SKU, unit label, cost, price, reorder threshold, active flag and optional barcode.
- [ ] Treat bag, piece and kilogram listings as independent inventory; add no automatic unit conversion. If conversion is implemented, require explicit owner-defined ratio/cost and atomic paired movements.
- [ ] Audit price changes and retain price/cost snapshots on old sales; test duplicate SKU, invalid quantity/price, inactive product and insufficient conversion stock.
- [ ] **Done when:** creating or editing a bag product cannot change the stock balance of a kilogram listing, and existing receipts/sales retain their original prices.

### 14.4 Opening stock and debt — workflows 12.3 and 12.5

- [ ] Build owner review/preview of imported product rows, physical opening counts and customer opening debts, with duplicates, missing costs and totals flagged before posting.
- [ ] Require owner sign-off, as-of date and source note; post opening stock and debt once using idempotent batch identifiers. Opening debts must not be current sales.
- [ ] Show a reconciliation summary against paper totals and warnings on incomplete costs; test duplicate imports, invalid rows and owner-only final posting.
- [ ] **Done when:** opening balances appear in stock/customer statements, can receive instalments, and do not inflate current-period sales or profit.

### 14.5 Receiving and paying for stock — workflow 12.4

- [ ] Build owner-only supplier/delivery entry and draft preview; validate line quantities, costs and duplicate references.
- [ ] Post stock movements, weighted-average costing update and one stock-purchase payment atomically; prevent double posting and expense duplication.
- [ ] Test interrupted transaction, duplicate submit, invalid SKU, cost calculation and owner-only access.
- [ ] **Done when:** one approved receipt increases stock exactly once, decreases cash by the paid amount once, and does not reduce profit as an operating expense.

### 14.6 Cash checkout and printable receipt — workflow 12.6

- [ ] Implement fast product search, cart, stock/price recheck, cash tender/change, atomic sale posting and numbered viewable/printable receipt.
- [ ] Capture SKU, quantity, unit, price, cost and selling employee on each line; reject overselling and below-total cash without approved credit.
- [ ] Test two concurrent buyers for the last unit, double-click checkout, price changes during a draft, receipt totals and employee scoping.
- [ ] **Done when:** one checkout yields one sale, one set of stock deductions, one cash collection and a matching receipt.

### 14.7 Manual M-Pesa checkout, settings and later verification — workflows 12.7–12.8

- [ ] Build owner-only receiving-number setting, history and historical per-payment number snapshot; display active number at checkout.
- [ ] Record unique transaction code, amount, recording employee and pending status; expose owner verification/dispute queue with reason and audit event.
- [ ] Separate pending, verified and disputed totals on receipts, statements, reconciliation and dashboards. Disputed entries do not become confirmed collections or clear debt.
- [ ] Test code reuse, number change with pending old payment, verification twice, dispute after goods leave and employee attempts to verify/edit the number.
- [ ] **Done when:** owner can reconcile a payment later against the correct number, and changing that number never rewrites previous payments.

### 14.8 Discount approval — workflow 12.9

- [ ] Implement employee request with reason and exact draft sale; owner-only approval/rejection with expiry and binding to customer, lines, quantities and discount.
- [ ] Apply approved discount at most once during checkout; invalidate it if the draft changes or expires. Audit both actors and old/final prices.
- [ ] Test forged owner approval, reused approval, edited cart and checkout without approval.
- [ ] **Done when:** an employee cannot discount a sale on their own and the receipt shows the correct approved price.

### 14.9 Credit approval and limits — workflow 12.10

- [ ] Add owner-controlled credit limit per customer, promised date, deposit, request and exact-sale approval. Recheck limit and customer balance atomically at posting.
- [ ] Show pending M-Pesa deposit separately; record sale, stock, cash/verified payment and receivable with no double counting.
- [ ] Test limit exceedance, simultaneous credit checkouts, approval reuse, changed cart, due date and failed deposit verification.
- [ ] **Done when:** only owner-approved credit posts and the customer statement and available credit agree with posted confirmed transactions.

### 14.10 Instalments and oldest-first allocation — workflow 12.11

- [ ] Post cash instalments immediately; keep manual M-Pesa instalments pending until owner verification.
- [ ] Allocate each confirmed payment to oldest outstanding opening debt/sale first, with deterministic tie-breaking and recorded allocation rows; never exceed remaining balance.
- [ ] Print a payment receipt and update statement/due balances; reverse disputed payments without deleting history.
- [ ] Test multiple debts, partial payment crossing two debts, duplicate code, concurrent repayments, overpayment and disputed M-Pesa.
- [ ] **Done when:** allocations sum exactly to the confirmed payment and debt equals opening plus posted credit less confirmed allocations/adjustments.

### 14.11 Counts and stock adjustment approval — workflow 12.12

- [ ] Implement staff/owner physical counts with expected quantity, observed quantity, count time and explanation; drafts and submissions do not move stock.
- [ ] Require owner decision, recalculate or block approval when intervening stock movements occur, and post one reasoned adjustment on approval.
- [ ] Test approval twice, stale count, rejection and staff attempt to approve or directly edit stock.
- [ ] **Done when:** a reported difference leaves stock unchanged until owner approval and the stock ledger reconciles after posting.

### 14.12 Employee void, return and owner refund — workflow 12.13

- [ ] Allow an employee to void only their accessible sale with reason; owner may void any sale. Preserve original and create one linked reversal.
- [ ] Prevent automatic cash/M-Pesa refund. Create owner payment-resolution queue for paid voids; only restock when goods are confirmed present and resellable.
- [ ] Implement owner-approved partial returns, return-quantity ceiling, refund method, damaged-stock handling and debt adjustment.
- [ ] Test duplicate void, paid void, goods not returned, over-return, refund twice and staff attempt to refund.
- [ ] **Done when:** original sale and reason remain inspectable, stock is not falsely restored, and no payment disappears or refunds itself.

### 14.13 Expenses, purchases and withdrawals — workflow 12.14

- [ ] Implement owner-only cash-out ledger with separate operating expense, supplier stock payment and owner withdrawal types and categories.
- [ ] Bind supplier payment to one stock receipt; implement reasoned correction/reversal and prohibit treating a purchase twice as expense.
- [ ] Test type-specific impact on cash, inventory cost and profit, duplicate reference, negative amount and staff denial.
- [ ] **Done when:** an owner withdrawal reduces cash but not profit and a stock purchase affects inventory/cash without becoming a second operating expense.

### 14.14 Reconciliation, dashboards and exports — workflow 12.15

- [ ] Implement employee own-day cash handover and owner per-employee reconciliation, verified/pending/disputed M-Pesa queues and discrepancy notes.
- [ ] Implement date-filtered owner views for gross/net sales, confirmed collections, customer debt, low stock, stock value, margin, estimated operating profit, expenses and withdrawals; label incomplete costs.
- [ ] Protect CSV exports with identical server-side filters/permissions; use Africa/Nairobi business-day boundaries.
- [ ] Test opening debt excluded from sales, prior-period debt repayment included in current collections, pending M-Pesa excluded from confirmed receipts, and owner withdrawal excluded from profit.
- [ ] **Done when:** dashboard figures reconcile to transaction lists for a selected day and employee, with unresolved differences visibly flagged.

### 14.15 Pilot and go-live

- [ ] Complete all first-release checklist items and run the entire journey from opening import through receipt, stock delivery, cash and M-Pesa sale, credit approval, instalment, count, void, refund and end-of-day report.
- [ ] Reconcile test-day quantities, cash, verified M-Pesa and receivables against independently prepared examples with the owner; correct defects and rerun affected checks.
- [ ] Configure real owner and staff accounts, actual payment number and opening records; back up the database, test restore, prepare operator guidance and rollback plan.
- [ ] **Done when:** owner signs off opening figures and a pilot day; critical posting and permission tests pass, backups restore, and no live STK Push is shown without merchant setup.

### 14.16 Later merchant/STK Push — workflow 12.16 (separate future phase)

- [ ] Obtain eligible Till/Paybill or approved provider, settlement arrangement and production credentials from owner; document provider-specific setup and fees before live activation.
- [ ] Implement secure merchant settings, prompt requests, unique payment attempts, authenticated callbacks/status checks, idempotent retries and reconciliation.
- [ ] Test success, decline, cancellation, timeout, duplicate callback, delayed callback and wrong amount in sandbox and approved live pilot.
- [ ] **Done when:** a provider-confirmed payment creates exactly one verified collection and failed/pending prompts never settle the sale. Until credentials exist, keep this phase marked **Deferred**, not complete.
