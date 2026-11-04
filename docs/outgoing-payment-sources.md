# Outgoing payment sources

Outgoing forms offer these sources:

| Source | Available funds |
| --- | --- |
| Main Account | Opening Main balance, less payments from that opening pool (never deducted from cashier drawer cash) |
| Spare Parts Account | Opening Spare Parts balance, less payments from that opening pool (never deducted from cashier drawer cash) |
| Spare Parts Account Cashier | Cash movements received today into Spare Parts, less spending from today's cashier pool (reduces physical drawer cash) |
| Bank Account | Bank ledger funds, less reserved issued supplier cheques |

Expenses and cashouts paid from Main Account always come from the opening balance outside the cashier, never from today's cashier drawer. For Spare Parts Account, users can select whether the payment comes from the opening balance or today's cashier cash.

Opening corrections apply to the opening pool. Same-day bank collections never increase a cash cashier pool. Cashier choices are disabled and grey when today's cashier is closed. The server rechecks the date and cashier state while holding the shared account/session locks, and rejects a cashier source for another date or insufficient source funds. No source can spend beyond the underlying account's current ledger balance.

These are funding pools within the existing Main/Spare Parts/Bank accounts, not additional ledgers. Each outgoing ledger entry stores `funding_source`. Opening-fund spending (`main`, `spare_parts`) is excluded from physical drawer cash; only cashier spending (`spare_parts_cashier`) reduces physical drawer cash and appears under "Cash paid out today" in the cashier drawer breakdown. Source labels are included in ledger notes and expense/drawing detail breakdowns. Reversals copy both the funding source and category so the original source is restored.

Expense and drawing forms, account-page expenses/drawings, purchases and supplier payments, salary payouts, staff advances, commission payouts, outer-product expenses, account transfers, and the Close Cashier owner-withdrawal field use the shared source rules. The close form deducts the withdrawal from the selected logical accounts; only a cashier-source withdrawal reduces physical drawer cash. Legacy submissions without a source plan retain their old withdrawal behavior. Existing accounting corrections remain audit adjustments.

New outgoing payments default to **Main Account — opening balance**, including expenses, drawings, salaries, advances, commissions, transfers, outer-product expenses, and the cashier closing withdrawal. Non-cash methods use Bank Account. For spare parts purchases and expenses, users can choose between Spare Parts Account (opening balance) and Spare Parts Account Cashier (today's cash).

Use **Split across accounts** to enter source amounts adding up to the payment. For purchases, use the existing **Add Payment** rows: Spare Parts Account defaults on each cash row. A purchase of Rs. 1,000 can be paid with Rs. 600 from Spare Parts Account and Rs. 400 from Spare Parts Account Cashier. Each portion posts once to Spare Parts, and only the cashier portion affects today's drawer. Selecting Bank with a cash purchase row changes that row to Bank Transfer. Issued supplier cheques use Bank; customer cheques forwarded to suppliers retain their existing held-cheque lifecycle.

Staff advance and outer-product source allocations are saved for later edits; correcting a payment reverses the old ledger entries before applying the new allocation. Salary and commission payments keep their existing separate settlement rules. Source allocation amounts use integer cents for total comparison.

Migration: `2026_10_31_000011_add_outgoing_funding_sources.php` adds nullable funding metadata to ledger entries, supplier payments, expenses, and staff advances. Existing historical entries are not rewritten. Historical cash outgoings without source metadata continue to count as cash movements; new selections are explicitly classified going forward.

Implementation was completed locally without running tests, per the user's request. The schema migration was guarded to run only with `APP_ENV=local` and a localhost database host. Frontend assets were rebuilt. No live/demo data was changed.
