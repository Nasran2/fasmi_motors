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

Allocations already completed before reopening are part of the carried account balance. Only cash received and spent since the latest opening is suggested as the next addition. Saved account closing balances and the cashier's leftover count continue to carry separately. Legacy full-balance requests remain supported.

No new migration is needed. This change was completed locally without running tests; frontend assets were rebuilt for manual testing by the user.

## Opening after separating account cash

The opening popup carries **cash left in the cashier** independently and shows the **current ledger totals for Main, Spare Parts and Bank**. For example, a saved close with cashier Rs. 1,350, Main Rs. 10,700, Spare Parts Rs. 8,500 and Bank Rs. 16,800 suggests those same four values at the next opening, adjusted for any intervening movements. Opening account fields accept full carried balances; the closing form accepts only the additions made during that opening. Every suggested amount remains editable, with a reason required for changes. Existing nonzero Washing balances are preserved under the advanced Washing field.

An opening event stores the confirmed cash/account balances and snapshots of the ledger movements already recorded. Main Account Cashier and Spare Parts Account Cashier therefore start at zero for the new opening, including a same-day reopening. Earlier receipts, payouts and allocation events remain in history; they are not counted a second time in the current cashier pools or available opening funds. Calendar-day receipt and payout totals remain cumulative and are labelled accordingly.

Cashier cash is suggested independently. Main and Spare Parts opening inputs use their full current ledger balances directly, with no deduction for cash held in the cashier and no automatic allocation adjustment on opening. Only a user-entered balance change posts an account correction, with its required reason. Closing snapshots remain available for audit history.

These changes use existing session/event snapshot fields and require no new migration. They were made locally without running tests or changing live data.

## Overdue sessions and closing history

Cashiers are closed manually. Crossing midnight does not enter a closing count, close a session, or discard its transactions. The highlighted cashier control opens the active session's closing popup, even when it belongs to an earlier date. An opening-popup request that finds an active session also shows that closing form. Close the overdue date first, then open today's cashier. Other sales and ordinary cashier-funded outgoings still require today's cashier to be open.

The overdue closing form uses that session's date and balances. A withdrawal submitted as part of this form can use its open cashier sources; this exception is scoped to the locked session passed internally by the closing service. It does not enable backdated cashier spending in ordinary payment forms. The transaction is effective on the cash date, while the closing event records when the operator actually completed it.

**Closing history** is available beside the header cashier control and from All Collections. Each history row offers **Close cashier** for an open date or **View / edit closing** for a saved close. The popup displays the counted cashier remainder, account balances, additions, withdrawals and their sources, washing settlement/assignment, related transactions with invoice/job/bike links, and original close/correction events. New closing events include a saved account summary and transaction-ID snapshot. Older events show their recorded fields without inventing unavailable snapshots.

Users with the existing Daily Closing permission can expand **Edit saved closing balances**. A reason is mandatory. For closes using daily additions, Main and Spare Parts edits are amounts added at that close, with full balances calculated from the saved account bases; the cashier remainder recalculates and remains editable. Bank and Washing balances are independent. Saving records account adjustments and an immutable correction event with previous/new values; it does not repay withdrawals or receipts. Correct payments through their source transaction.

Later recorded openings and closing counts are preserved. An account correction is reconciled at the next recorded opening checkpoint, with the reason retained. Physical count corrections change the selected closing remainder, while later saved physical openings remain as recorded. Stale closing-edit forms are rejected. Original close events remain viewable after corrections.

No new migration is required. These changes were authored locally without running tests or changing live data. Frontend assets were rebuilt for the user's manual testing.

## Editing the full Main or Spare Parts total while closing

Each Main/Spare Parts **Account balance after addition** card has an **Edit total** button for users with Daily Closing permission. Its popup shows the total currently displayed (including the entered daily addition), accepts the corrected full account total and requires a reason. Saving immediately posts only the difference as a `cashier_account_balance_correction` adjustment to that account's carried funds. Physical cashier cash, daily additions, pending withdrawals and other accounts remain unchanged.

The server recomputes the displayed total from the saved account base, entered addition and selected opening-funded withdrawal; it does not trust a submitted old total. Account locks and the closing balance fingerprint reject a stale popup. The correction event saves the old/new displayed totals, actual ledger balance before/after, difference, pending amounts, operator and reason. It appears in both daily event history and the closing audit popup.

For example, a displayed Main total of Rs. 30,598 corrected to Rs. 30,000 posts a Rs. 598 decrease. The close form updates its account base and full total without resetting unsaved additions or counted cash. Completing the closing still posts its allocations and withdrawals separately. Future ledgers and opening suggestions include the saved correction; it is not a one-time display override, receipt or expense. Existing saved opening/closing count records are not rewritten.

No new migration is required. These changes were authored locally without running tests or changing live data; frontend assets were rebuilt.

## Current total account balances in opening

Main and Spare Parts opening inputs show the current full balances calculated by the account ledger, as of the opening date. They are not populated from the previous daily addition, a last-close count, or a reconstructed cashier allocation. Physical opening cash is entered separately and is not deducted from either account total.

Both totals are editable directly in the opening popup. The current ledger amount is shown beneath each input, and the entered total previews live. Changing a total requires a reason. Confirming opening records only the difference from the current ledger as an audited account correction; that correction affects future balances and opening suggestions. Confirming unchanged totals does not post an automatic account allocation or overwrite account totals from old closing figures. Original saved opening/closing count records remain in history.

The popup and opening submission share the same current-ledger calculation and stale-balance validation. Historical opening edits continue to use their originally recorded opening totals. No migration or data repair is run. These changes were made locally without running tests or changing live data; frontend assets were rebuilt for manual checking.

## Transfers from a previous date that is still open

Account Transfers reloads funding balances and cashier availability when Transfer Date changes. A same-shop, authenticated funding-options endpoint validates the selected date and returns date-labelled choices with a specific open/closed message. A previous day's open cashier is selectable when the transfer is dated on that day; choosing another closed date keeps its cashier sources disabled. Refresh balances retries loading after a network failure or a cashier status change. Responses are not cached, older responses cannot overwrite newer dates, and the form blocks submission while date options are loading or unavailable. Split amounts are preserved across date refreshes.

Under account/session locks, posting rechecks the session for the selected transaction date and its available funds; future dates and closed-session cashier sources remain unavailable. The debit and destination credit retain the selected transfer timestamp, so a 31 October transfer belongs to the 31 October ledgers and closing. A client-side option cannot reopen a session or authorize a different date. The same selected-date rule now applies to other outgoing payments as described below. No existing financial records are recalculated by this change.

This fixes the mismatch where the header showed an older open cashier, but the transfer options were loaded once using today's status and did not respond to date edits. Source changes and frontend assets were prepared locally without application tests, browser checks or live-server changes, as requested.

## Selected-date funding throughout outgoing payments

Salary finalization/payments, staff advances, commission payouts, expenses, drawings, purchases and supplier payments now reload their funding choices for the payment/expense date, using shared controls. Purchases use Purchase Date. Forms without an editable transaction date use their supplied cash date or today; late cashier closing uses the session date. Reopening/resetting a payment popup refreshes its options too. The authenticated, shop-scoped `funding.options` endpoint is available to the roles that can use these payment controls.

Example: on 1 November, if 31 October's cashier is still open, selecting 31 October in a salary payment enables that day's Main/Spare Parts Cashier pools and shows their balances. Selecting 1 November keeps those cashier choices unavailable until that day's cashier is open. The message identifies the open date. Changing the date preserves split amounts and any still-valid source selection. Stale network responses are discarded, and submission waits for the latest date's options. Refresh balances retries errors or reloads after a session status change. The server independently rechecks the selected session and available funds under locks for every outgoing payment, rather than checking only today's session.

No existing records, opening/closing counts or live-server data are changed. No tests are run; the assets are rebuilt locally for manual checking.

## Payment-source totals excluding cashier funds

Main Account and Spare Parts Account in the shared outgoing-funding picker show each account's full current ledger balance less the positive cashier funds for the selected date/current opening cycle. They no longer calculate availability from the opening snapshot plus only opening-funded movements. Capital, transfers, reconciliations and all other posted movements are therefore reflected in the account totals. Cashier amounts continue to be offered separately and are not counted twice. A negative cashier position never increases spendable account funds, and cashier-source availability remains capped by the actual ledger balance. Bank reservations and cashier open/closed restrictions remain unchanged.

Example: Main's current total is Rs. 80,000, of which Rs. 20,000 is the current cashier pool. Main Account displays Rs. 60,000 and Main Account Cashier displays Rs. 20,000. A new Rs. 5,000 capital contribution directly to Main changes the first choice to Rs. 65,000 while its cashier choice stays Rs. 20,000. The backend uses this same calculation under locks for outgoing payments and transfers, including split funding. Source labels now say “total excluding cashier” and the shared help text explains the split. Existing saved transactions and opening/closing counts are not rewritten. No tests or live-server changes were made.

## Transfer destinations and account/cashier movements

To Account offers the same five pools as Source: Main Account, Main Account Cashier, Spare Parts Account, Spare Parts Account Cashier and Bank. Both pickers refresh balances and cashier availability for the selected Transfer Date. Cashier destinations are greyed out when that date is closed, and the server rechecks the session under account/session locks before posting. A transfer into the exact same pool is rejected, including any positive split-source row that matches the destination. Different pools in the same ledger are allowed in both directions.

For example, Spare Parts Account Rs. 10,000 and Spare Parts Cashier Rs. 3,000 have a combined ledger total of Rs. 13,000. Transferring Rs. 1,000 from Spare Parts Cashier to Spare Parts Account leaves Account Rs. 11,000 and Cashier Rs. 2,000; the combined total stays Rs. 13,000. The debit retains the selected source and the credit retains the selected destination in each ledger entry's existing funding_source field. This also supports split-funded transfers and account-to-cashier replenishment without counting the transfer as sales income. Transfer history displays the source and destination pool labels, including after cancellation; legacy entries fall back to their saved account names.

No migration, historical financial repair or live-server action is required. No application tests were run; frontend assets were rebuilt for manual checking.

## Other charges and bill discounts

Received Other charges now belong to Spare Parts, alongside parts receipts. Cash receipts therefore increase Spare Parts Cashier; non-cash receipts retain their Bank mirror. Main receives Service and Labour, Washing remains separate, and the net Spare Parts share bears the whole bill discount (percentage plus fixed). Parts amounts already include their line discounts; the bill total supplies the net residual, including tax, without deducting any discount twice. Opening-due receipts retain their existing Main allocation.

For a fully collected bill with Service/Labour Rs. 500, Other Rs. 200, parts Rs. 1,000 and discount Rs. 100, Main receives Rs. 500 and Spare Parts receives Rs. 1,100. Partial receipts still settle Service, Labour, Washing, Other, then Spare Parts. No money is posted for unpaid components. If a discount exceeds Other and parts, the excess is debited to Spare Parts at final paid settlement, preserving Service/Labour and Washing in full. For example, Service Rs. 500 less discount Rs. 100 collects Rs. 400: Main receives Rs. 500 and Spare Parts bears Rs. 100. That offset is part of the receipt allocation, not an additional cash payout; net physical cash received is still Rs. 400.

The centralized rule is shared by new payments, ordinary invoice-revision reconciliation and collection component reports. No historical repair command, financial-data update, tests or live-server actions were run for this change.

## One Washing row and adjustment origins

The cashier account table has one Washing row. Its received-this-date link opens the receipt breakdown. New receipts belong to Main and are not added again to the account total. When historical Washing ledger entries remain, the same row shows their opening, credits, debits, adjustments and remaining balance. That real remaining balance stays in the total until transferred; combining the rows never clears or rewrites it.

The adjustment popup separates ledger opening (including opening corrections), the saved opening snapshot, their reconciliation difference, and net posted adjustments across all pages. Recorded cents remain intact. In the supplied 2 November example, Main is 45,738 + 29,600 − 46,570 + 37,510.79 = 66,278.79, and Spare Parts is 79,435 + 108,130 − 14,270 + 15,409.21 = 188,704.21. The fractional balances come from the adjustment amounts shown, not from currency formatting. The original financial records must be inspected before correcting these amounts; verified totals can be changed with a reason using the existing Edit total control. No tests, financial-data changes or live-server actions were performed.
