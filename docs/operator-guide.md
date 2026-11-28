# Kaura Hardware operator guide

## Access and setup

Open the homepage and choose Sign in. Each person uses their own account. The Join the team page explains owner-managed signup; it does not create public accounts. The owner creates employees from Employees, shares a temporary password privately, and asks each employee to replace it and verify their email. Deactivation preserves attribution and revokes access.

For a new real shop, an administrator uses `php artisan kaura:create-owner` with interactive credentials. Configure actual shop identity and manual M-Pesa number in Shop settings. Tax fields are saved for a future review only; they do not change receipts or imply compliance.

## Opening records

1. Prepare the actual catalogue. Use Import catalogue for up to 500 UTF-8 CSV rows. The screen provides exact headers. Review separate selling units, similar names, prices, costs and barcodes, then post the preview. Listings start at zero stock.
2. Create and review customer names/contacts. Shared contacts are allowed, so ambiguous paper accounts need explicit customer IDs.
3. Enter physically counted opening stock in Stock and opening. Use the selected as-of date and a paper-source note. Unknown costs stay blank and will flag valuation/profit as incomplete.
4. Import opening debt using `customer_id,amount,due_on` or `customer_name,contact,amount,due_on`. Names/contacts must identify exactly one existing customer; the review screen shows the resolved identity before posting.
5. Reconcile the paper totals and complete owner opening sign-off. Opening debts are not new sales. New app sales begin after sign-off.

## Counter day

Build the cart at current prices. Cash checkout records money retained after change. Manual M-Pesa records a claim against the displayed receiving number; the owner must later verify or dispute it. A pending claim is never confirmed collections. Discount/credit requests bind the precise draft and must be approved from the owner's own session. A credit sale captures the customer's promised repayment date and respects their limit.

Cash instalments settle the oldest outstanding opening/sale debt first. M-Pesa instalments remain pending until verified. Print the payment receipt and inspect allocations when needed. Never enter the same payment again to resolve a disputed claim.

## Returns and customer credit

Open the original sale and choose Return items / review refund. Select an original item, enter the returned quantity, and explicitly choose received/resellable, received/damaged, or not received. Request the financial preview before paying anything. It shows the original discounted return value, debt reduction and refund due. If balances change, refresh the preview; posting rejects outdated amounts.

The owner confirms any actual refund, method and new M-Pesa code. Debt is reduced before paid funds are refunded. Return quantity cannot exceed what remains unreturned. Resellable goods add one stock movement; damaged/unreceived goods do not become available stock. Further items can be returned in additional linked operations. A sale with prior returns or debt adjustments uses remaining-item returns instead of a later whole-sale void.

Full voids preserve their original sale, stock review and owner payment-resolution workflow. Confirm goods before restocking. Retained credit can be paid out through the statement, applied to the oldest debt through customer debt review, or redeemed by the owner at cash checkout. Select the matching customer and credit, enter the amount used, and collect only the remaining cash. Enter zero cash tendered for a fully credit-funded sale. This does not create a cash collection.

Owner debt write-offs require a reason and preserve the original receivable plus a linked adjustment. Resolve pending customer M-Pesa claims before changing debt.

## Deliveries and corrections

A supplier delivery posts stock, weighted-average valuation and one payment together. Opening entries and staff counts are separate workflows. Staff counts do not change stock before owner approval; intervening stock activity requires fresh review.

For an incorrect expense or withdrawal, open the payment and record a linked reversal after confirming the financial facts. Create a replacement with the original reversed payment ID in the optional replacement field. Original and corrected entries stay visible.

A full delivery reversal is available only while none of its products has subsequent stock movement. Confirm the goods returned and supplier funds received. A replacement delivery uses the original batch ID in its replacement field. If later sales or movements exist, the reversal is refused; use a new owner-reviewed physical count and resolve the financial difference explicitly. Do not delete or edit the original receipt.

The Money out screen retains original payment values. Returns & corrections shows linked partial-return refunds and signed corrections. Reports combine both ledgers. Original M-Pesa codes stay reserved; replacement entries must not reuse a previously recorded code.

## My day and cash handover

Select the Nairobi business day in My day & cash. Each employee sees their own cash and split M-Pesa totals and submits the actual cash handed over with a note. The owner sees all accounts, records cash actually received, and explains differences. Handovers do not create sales or collections.

Late postings flag changed expected cash. The owner can perform a supplementary review; previous figures and reasons remain in audit history. Negative net movement means the shop supplied funds overall. Explain opening floats, external funding and other physical cash differences in the reconciliation notes; net movement alone is not the available drawer balance.

## Reports and exports

Reports & insights offers a date range and one/seven/thirty/ninety-day shortcuts. Net sales and collections are different: old-debt instalments are collections, while stock purchases and withdrawals are not operating expenses. Pending/disputed M-Pesa is separated from confirmed funds. Return refunds and corrections are included. Incomplete historical costs prevent a definitive margin/profit estimate.

Use Products & stock for sales quantity, margin, current balances, low stock and damaged-goods history. Customer debt includes aging buckets and links to statements. Team & cash distinguishes the seller from the collector and compares physical handovers. Financial detail exposes the underlying metric groups and links to operation/variance exports. Search, low-stock, overdue and account table filters are carried into their relevant CSV exports; the full financial CSV covers the complete selected period.

Debt, stock and attention queues are current snapshots, clearly labelled; they are not historical closing balances. CSV fields ending `_minor` are integer KES cents. Print view prints the selected report tab. Browser-generated A4 PDF is checked; test the physical counter printer before launch.

## Backup and recovery

Run `php artisan kaura:backup-check` for file-based SQLite. It makes a consistent snapshot, copies it into an isolated restore file, checks SQLite integrity and foreign keys, compares every table's row count, and saves a SHA-256 verification receipt beside the backup. Backups are in `storage/app/backups`; restrict access and keep an additional protected off-machine copy. Preserve the matching application version, environment configuration and application key.

Before a real restore, stop writes/put the application into maintenance mode, take a fresh safety copy, and have the administrator restore the selected database with its matching code and configuration. Rehearse using a separate database first; confirm logins, statements, totals, receipts and stock movement reconciliation before accepting new shop activity. Do not run destructive schema rollback to correct live transactions: migrations refuse when linked operational history exists.

For PostgreSQL deployment, use `pg_dump` and `pg_restore` into an isolated database and verify the same workflows. PostgreSQL integration has not been exercised in this workstation session. The SQLite backup command deliberately refuses PostgreSQL rather than claiming to verify it.

## Pilot still required

The owner must provide the actual receiving number, accounts, catalogue, counted stock, debts, source dates and paper totals. Run an independently reconciled pilot with cash/M-Pesa sales, an approved credit sale, instalment, stock count, return/refund and end-of-day handover. Check actual phone devices, printer, delivery mail, HTTPS, backup retention and restore access on the target deployment. Live STK Push remains a later phase requiring merchant credentials and a separately tested integration.
