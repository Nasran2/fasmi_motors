# Cashier cash and account balances

**Opening cash in cashier** is the physical money in the drawer. It is editable and independent of Main Account, Spare Parts Account, Washing Account and Bank balances. For example, opening cashier cash can be Rs. 5,000 while Main Account is Rs. 74,838 and Spare Parts is Rs. 12,000. These amounts do not need to add up or match.

The opening popup suggests the most recent recorded cashier cash, including subsequent cash movements. When there is no recorded cash count, it starts at zero for the operator to enter the real amount. Main, Spare Parts and Bank opening fields suggest their separate ledger balances and remain editable. Bank is excluded from physical cashier cash. Changing drawer cash does not post an adjustment to Main. Changing Main, Spare Parts or Bank posts an audited account adjustment, excluded from physical cashier cash. A negative ledger balance does not prevent opening a cashier with positive physical cash.

The highlighted header control opens the popup when closed and the review/closing page when open. Each shop has its own session. A previous open day must be closed before starting today. Cashiers may close and reopen repeatedly on the same date. Opening/reopening events record physical cash, separate account figures, washing assignment, actor and reason. Washing commission defaults to a fixed Rs. 60 per job containing washing, with editable per-opening/per-job amounts and an optional saved default.

## Received money and the physical drawer

Expected cashier cash is physical opening cash + actual cash receipts + other physical cash in − cash paid out + recorded cash count adjustments. Bank receipts, unpaid/held invoices, account balance adjustments and internal washing-to-Main transfers are excluded from the drawer total. Cash count adjustments are shown separately from collections. Partial receipts settle Service → Labour → Washing → Other → Spare Parts. Bank and cash payments both consume their appropriate allocation share, but only actual cash receipts enter the drawer.

Cash collection rows contain received money only. All Collections opens separately and offers cash, bank-transfer and all-payment filters. Bank is a physical payment channel, while Main/Washing/Spare Parts identify the income allocation; the receipt total is counted once.

## Closing

Enter the actual physical cash left in the cashier, independently of the Main, Spare Parts and Bank closing balances. Account balances are suggested automatically and remain editable. Account amounts are not required to add up to the drawer count. A difference in either a count or an account balance requires a reason.

Owner cash withdrawal reduces the physical drawer and Spare Parts Account once, and is limited by both available drawer cash and Spare Parts balance. Collected washing cash is settled into Main at closing; this internal transfer does not change total physical cash or spend non-cash washing money. A cashier cash shortage/surplus adjusts the recorded drawer count without changing Main. Account balance corrections change the relevant ledger without changing cashier cash. PDF and Excel closing reports show physical cashier cash in its own row, followed by the four account balances. Earlier count adjustments are identified in the closing note and included in the cash reconciliation math.

The verified physical count carries into later openings, including cash movements on intervening days without a session. Reopening the same day preserves the original daily opening, uses the entered current cash count, and keeps receipts and withdrawals cumulative. It does not repeat transactions or washing transfers.

## Editing saved openings

Select the cash day and choose **Edit Opening Balances**, or **Edit opening** beside a history row. Past, closed and reopened sessions are editable with a required reason. A past date without a saved opening offers **Record Opening Balances**, which records the starting cash without opening a till or inventing a closing count. Unknown counts display as Not recorded.

For today's open cashier, **Recalculate today from these opening balances** applies the corrected physical opening to current expected cash, then actual cash movements. Earlier actual counts and original closing events remain in history. Unchecking it preserves recorded count checkpoints and records reconciliation differences. Historical edits preserve subsequent recorded counts. Original values, new drawer cash, separate account balances, reason and actor are retained in correction history. Stale forms are rejected, including a physical-only change that leaves all account ledgers unchanged.

Opening means cash held before that day's receipts. Entering the current drawer amount as the original opening would count receipts twice. No financial amount is guessed, and a system start date does not automatically delete or exclude earlier transactions.

Bank opening balances are editable in the same popup for new, reopened and historical sessions. Bank corrections require a reason, update only the Bank ledger and appear in opening history. They do not count as collections or change physical cashier cash. An account-only correction preserves an earlier drawer count difference even when today's recalculation option is checked. Existing three-account snapshots remain supported; adding Bank uses the existing snapshot fields and requires no additional migration.

## Compatibility and deployment

Migration `2026_10_29_000006_separate_cashier_drawer_from_accounts` adds nullable physical opening/closing fields, drawer count adjustments and a separate account-opening snapshot. It does not rewrite existing financial records. Existing sessions keep their historical calculation until opened/edited through the new popup. Older API payloads retain their previous validation; all new popup submissions explicitly use separate drawer/account balances. A converted session stays separate on subsequent requests. Apply the migration before deploying the new application code.

Earlier migrations retain cashier gating, ordered collections, washing assignments and fixed washing commission behavior. Paid/payroll-linked washing commissions remain protected. While closed, job creation, quick jobs, billing and parts sales remain blocked; intake and viewing jobs remain available. The legacy reopen endpoint still requires the existing reopen permission.

All work and verification for this change used the local development and isolated testing databases. No live server was accessed or changed.

Verification: 132 selected backend tests passed (1,209 assertions), alongside nine JavaScript tests. Coverage includes independent opening/closing amounts, actual cash/bank receipt allocation, ledger-only corrections, cash-only edits with no ledger postings, owner withdrawal, washing sweeps, repeated opens/closes, historical edits, missing historical records, negative Main ledgers, cash count differences, stale forms, gaps between sessions, later legacy checkpoints and separate PDF/Excel rows. Production assets build, compiled cashier templates, PHP formatting and whitespace checks pass. Migration 000006 was applied only to the guarded local development database; tests used the isolated local testing database.

Bank opening verification: 144 selected backend cases passed (1,314 assertions); after adding protection for an existing drawer count difference, all 30 Bank and separate-drawer cases passed (257 assertions). Nine cashier JavaScript tests, the production build, compiled cashier templates and formatting checks passed. All verification remained local.
