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
# Editable physical cashier counts

The opening popup highlights **Opening cash in cashier**, prefilled from the carried physical count and editable independently of the accounts.

The close form calculates **Closing balance in cashier** as the remainder after separating Main and Spare Parts balances. Its starting total is the automatic Main balance (including washing) plus the automatic Spare Parts balance after owner withdrawal. Subtract the Main and Spare Parts closing amounts entered by the user; Bank is excluded. Keeping both suggested account amounts leaves zero in the cashier. Changing either account recalculates the remainder, while the cashier count also remains directly editable. Explicit counts, including zero, are retained on validation errors and stale-version reloads until an account changes.

Example: automatic Main 6,700 + Spare Parts 6,750 = 13,450. Enter Main 6,000 to leave 700; enter Spare Parts 6,000 to leave 1,450. Saving records Main 6,000 and Spare Parts 6,000 separately, with cashier closing cash 1,450 carried to the next opening.

The remainder calculation is separate from expected physical cash (opening count plus cash receipts and cash movements). Changes from recorded cash or account balances still require a reason. The current form can close a legacy session with an independent drawer count without forcing account balances to add up to the drawer count. The owner withdrawal can select or split the same outgoing funding sources; only cashier-funded portions reduce physical drawer cash.

Earlier count-entry verification: 70 cashier regression cases (648 assertions), 11 cashier JavaScript checks, production build and Blade compilation passed before the remainder calculation change. No additional schema migration is needed. The remainder calculation change was made without running tests, as requested. Frontend assets were rebuilt for manual checking locally. Live data was not changed.

## Current cash in hand

The cashier page highlights current cash in hand as **cashier opening cash + today's net Main Account Cashier cash + today's net Spare Parts Account Cashier cash**. The opening amount comes from the saved physical cashier opening; it is never calculated by adding the Main/Spare Parts opening account balances. Today's cashier amounts use the same cash-source classification as outgoing payments, before operational-ledger spending caps. Only actual cash receipts, cash-source outgoings and their reversals affect these two amounts. Bank/non-cash receipts and opening-account expenditure are excluded.

The main cash-in-hand card and the highlighted breakdown use this formula. Unsaved owner withdrawals from either cashier source reduce that source and the highlighted total while editing. Withdrawals from opening funds or Bank do not reduce the displayed cashier total. The separate editable closing remainder and saved reconciliation counts retain their existing workflow.

This display change was made locally without running tests, per the user's request; frontend assets were rebuilt.

## Daily account additions in the close form

The current close form accepts **Today's amount added to Main Account** and **Today's amount added to Spare Parts Account**, instead of complete closing account totals. Each field shows the saved account opening balance, any other account movements/expenses, the amount entered today, and the resulting account balance live. Bank continues to accept its complete balance independently.

For example, Main opening Rs. 10,000 plus today's addition Rs. 1,700 gives a closing Main balance of Rs. 11,700 when there are no other movements. If opening funds paid an expense, that expense is shown separately in the breakdown and reduces the account base. Today's net cashier collections prefill the addition fields; both are editable, including zero. Pending cashier-funded owner withdrawals reduce the appropriate suggested daily addition, while opening-funded withdrawals reduce the account base.

Cashier cash remaining is the highlighted cashier opening plus today's Main/Spare Parts cashier amounts, less only the daily additions. Account opening balances are not subtracted again. Over-allocation is blocked on the server; a changed physical count or Bank balance still requires a reason. Ordinary daily allocation choices are recorded as `cashier_daily_allocation` ledger adjustments with the entered amount and account base, rather than missing-cash discrepancies. The closing snapshot and history event keep the separate daily additions and final account balances.

Earlier allocations on the same date are excluded from the account base and included when suggesting the next cumulative daily additions. Reopening and saving unchanged amounts does not apply the day's allocation twice. Saved account closing balances and the cashier's leftover count continue to carry separately. Legacy full-balance requests remain supported.

No new migration is needed. This change was completed locally without running tests; frontend assets were rebuilt for manual testing by the user.
