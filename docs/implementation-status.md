# Product brief implementation review

Reviewed 24 November 2026 against the supplied development specification, whose internal header says version 1.1 dated 22 November 2026. Its original filename says v0.1; the copied source is preserved byte for byte.

**Overall: the first release is not complete.** Existing automated checks pass, but several required workflows and launch acceptance steps remain. The brief's agent instructions are source material for this comparison, not an instruction to execute all remaining development in this review.

## Checklist comparison

“Implemented” below means code and automated feature coverage exist for the main workflow. It does not mean browser, printer, production database or owner acceptance has passed. Original source checkboxes are preserved; this review records status separately.

| Brief section | Status | Evidence and remaining work |
| --- | --- | --- |
| 14.1 Delivery procedure | Partial | Domain services, typed pages, migrations and tests exist. Per-task browser walkthroughs and full launch acceptance evidence are incomplete. |
| 14.2 Foundation/access | Implemented | `AccessFoundationTest`, authentication tests, account middleware, policies, login/denial audit events and employee administration cover permissions, revocation and historical attribution. |
| 14.3 Catalogue/units | Implemented with limits | `CatalogueService`, `ProductController`, `CatalogueTest` cover separate listings, search, prices, stock restrictions and history. Explicit conversions are unavailable; no implicit conversion occurs, satisfying the conditional checklist rule. If the shop needs conversions, configured ratios, paired movements and cost transfer still need implementation. Price changes take effect at server posting time; there is no owner-selected future effective time. |
| 14.4 Opening stock/debt | Partial | `InventoryService`, `InventoryController`, `InventoryTest` implement preview/posting, CSV opening stock/debt for existing records, dates, source notes, reconciliation and sign-off. Bulk creation/import of product listings is missing; opening debt import requires existing customer IDs. |
| 14.5 Stock deliveries | Implemented with correction gap | Owner delivery drafts, supplier details, duplicate checks, weighted average and one linked payment are covered by `InventoryTest`. Posted receipt reversal/correction required by section 13 is missing. Supplier details are captured on batches rather than a supplier directory. |
| 14.6 Cash checkout/receipts | Implemented | `CheckoutService`, sales pages, `CashCheckoutTest` and process-level concurrency tests cover atomic posting, overselling, retries, snapshots and access. Physical printer/browser validation remains. |
| 14.7 Manual M-Pesa | Implemented | `PaymentVerificationService`, shop settings, payment pages and `ManualMpesaTest` cover number snapshots, unique codes, pending/verified/disputed states and owner decisions. Physical cash reconciliation is separate and missing. |
| 14.8 Discounts | Implemented | `SaleApprovalService`, approval pages and `ApprovalsAndInstalmentsTest` cover exact cart binding, expiry, owner decisions and consumption. |
| 14.9 Credit approvals | Implemented; additional race evidence needed | Same approval service plus checkout and receivable services cover limits, deposits, dates and approved posting. The explicit simultaneous credit-limit checkout acceptance scenario needs a dedicated concurrency test; existing races cover other posting risks. |
| 14.10 Instalments | Implemented | `InstalmentService`, `ReceivableAllocator`, payment receipts and approval/instalment tests cover oldest-first allocation, opening debt, pending claims, overpayment and concurrent final-balance collections. Owner debt write-offs required by section 6 are missing. |
| 14.11 Counts/adjustments | Implemented with export gap | `StockCountService`, count pages and `StockCountTest` cover submission, owner decisions, stale counts and duplicate approval. Owner variance-history CSV export required by workflow 12.12 is missing. |
| 14.12 Voids/returns/refunds | Partial | `SaleVoidService`, `VoidResolutionService`, `CustomerCreditPayoutService`, void pages and associated tests cover full voids, owner stock review, full refunds, retained credit and payouts. Partial returns, quantity ceilings across returns, proportional debt/refund adjustments and a separate damaged-stock ledger are missing. Non-restocking decisions exist, but are not a damaged-stock inventory workflow. |
| 14.13 Money out | Partial | `OwnerOutflowService`, outflow pages and `OwnerOutflowTest` cover expenses, purchases, withdrawals, categorization, access and reporting. Reasoned reversal/replacement of posted expenses, withdrawals and supplier payments is missing. Type separation exists, but cannot establish that a manually entered expense is not a disguised duplicate supplier payment. |
| 14.14 Reports/reconciliation | Partial | `FinancialReportService`, financial/outflow CSVs and `FinancialReportTest` cover date ranges, employee activity, collections, debt, valuation and incomplete costs. Employee My day handover, actual cash received, owner reconciliation status, discrepancy notes and per-employee physical cash comparisons are missing. Product, customer debt and variance CSVs are missing. Employee figures are exported within the financial report. |
| 14.15 Pilot/go-live | Incomplete | Local demo data and automated tests exist. Full browser/device/printer journey, PostgreSQL integration, independent pilot reconciliation, backup restore evidence, operator guidance and owner sign-off remain. Real owner/staff details, receiving number, catalogue, opening stock/debts and signed paper totals must come from the shop. |
| 14.16 Live merchant/STK Push | Deferred as specified | No merchant credentials or verified live integration are available. This is a later release and must not be counted as completed or advertised as live. |

## Other specification gaps and limits

- Section 4 requests tax fields configurable for later compliance review. Current shop settings expose shop identity and manual payment number; configurable tax settings are missing. Existing receipts do not claim tax compliance.
- Retained credit can be paid out, but cannot be redeemed at checkout or offset against another debt. The credit-balance checkout language in section 4 needs this capability or explicit clarification that it means approved new customer debt only.
- The report's selected date range applies to activity; outstanding debt and inventory valuation are explicitly current snapshots, not reconstructed historical closing balances. Historical as-of reports would require additional work if expected.
- Responsive styles and contrast calculations exist, but do not establish tested phone usability or printer output. HTTPS, protected deployment secrets and production backup/restore are launch configuration and verification tasks.
- A local SQLite setup exists. PostgreSQL is proposed in the brief; its locking, migration and constraint behavior still needs integration verification before deployment there.

## Verification evidence

The existing implementation was checked in this conversation immediately before this document review: PHPUnit passed **233 tests / 2,810 assertions**; PHPStan, Pint, frontend lint/format, TypeScript and production build passed. No new feature code was changed during this documentation review, so those results describe the existing implementation, not the missing workflows. No browser, physical printer, PostgreSQL or live shop pilot result is claimed.

The copied brief's SHA-256 matches the Desktop source: `3A04B57D2E0F0349CB7E88B255D052B52E072861EED98558F33A9CB529E55153`.

## Suggested remaining delivery order

1. Bulk catalogue import and any customer matching needed for opening records.
2. Partial returns, damaged-goods tracking, debt write-offs and retained-credit redemption/offset.
3. Linked correction/reversal workflows for deliveries and money out.
4. Employee cash handover and owner reconciliation, remaining CSV reports and future tax configuration.
5. Add missing concurrency acceptance evidence, complete browser/printer/PostgreSQL validation, restore a backup, prepare operator guidance and run the owner pilot.

These are remaining work items, not completion claims or an estimate of effort. Conversions should be added if required by the shop; live STK Push remains a separate future phase.
