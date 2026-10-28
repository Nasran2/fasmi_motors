# Cashier opening and closing

When closed, the highlighted header control opens the opening popup on desktop, mobile and POS. When open, it opens `/cashier` to review and close. Accounts → Daily Closing opens the same screen. Each shop has its own daily session; a previous open day must be closed before starting today. Cashiers can open and close repeatedly on the same day through the opening popup, without a cycle limit or a separate reopening permission. Each opening/reopening and closing records its counted cash, washing assignment/rate, actor and withdrawal details in Opening & Closing History. Day totals remain cumulative, so earlier receipts and withdrawals are not repeated. The legacy reason-based reopen endpoint still requires `account.reopen_closing`.

While closed, the server blocks job card creation, quick jobs, job billing and parts sales, including direct POST/JSON requests. Bike intake and viewing existing jobs remain available. Existing receipt collection workflows remain available.

Opening defaults to the total physical cash carried forward in Main, Washing and Spare Parts. Bank funds and non-cash receipts are excluded. Changing the counted total requires a reason and posts one Main cash opening correction. Cash account opening balances are frozen for the session, and verified closing cash carries into the following day.

Settled payments collect Service → Labor → Washing → Other → Spare Parts, consuming each charge before the next. Main receives Service, Labor and Other. The existing full-invoice discount/tax account budgets are preserved. Unpaid bills, held cheques and salary deductions do not count as received money. A cheque takes its priority share when it clears. Split invoice allocations sharing one receipt are counted once. Parts-only sales, including tax, belong to Spare Parts. The migration corrects receipts in the currently open period (or today); it leaves earlier closed periods unchanged.

The cashier screen and closing form show physical cash only: opening, actual cash collections, other cash movements, expenses and cash in hand. Main, Washing and Spare Parts have separate counted cash inputs. Bank balances are never edited during closing. Owner withdrawal is a cash drawing from available Spare Parts cash and updates that balance and the total live. Previously saved withdrawals are never repeated on reopening. Count differences require a reason and create cash reconciliation entries.

The highlighted All Collections header button opens a separate window. It shows settled cash and bank receipts once, with date, text and All / Cash only / Bank / Bank transfers filters and a Service, Labor, Washing, Other and Spare Parts breakdown. Account transfers and unpaid bills are not customer collections.

Opening, closing and protected sales serialize through the system account row locks. Closing checks a version of the displayed balances and ledger entries; a stale form refreshes the account inputs instead of silently overwriting newer movements. Closed sessions retain their verified balances and summaries. New daily closing records and PDF/Excel exports show the three cash wallets and cash total, separately for each shop. Older closing records retain their original account snapshots.

Deploy with the normal application migration and asset build steps: `php artisan migrate --force`, `npm ci`, `npm run build`. The schema migration adds cashier sessions, separates daily closing dates by shop and adds Washing fields without deleting existing financial records. Rollback to the old globally unique closing date requires resolving any dates that have closings in both shops first.

Tests: `php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/CashierSessionTest.php`; `node --test tests/Unit/cashier.test.mjs`.

## Validation

Regression coverage includes ordered partial receipts, bank exclusion, held cheques, split advances, legacy allocation corrections, cash reconciliation, withdrawal limits, carry-forward, permissions and new-window collection filters. Run `tests/Feature/OrderedCashCollectionTest.php` alongside the cashier and washing tests.

Validation on 29 October 2026: all 59 cashier, ordered collection, washing assignment/commission and quick-job scenarios pass in the full run, alongside both JavaScript balance tests. The full suite passed 768/772 tests (8,665 assertions). The four existing stock/display failures remain: completed-job stock fixture, inventory adjustment payload, product-selector text and live-board ordering. Asset build, Blade compilation and compiled job-template syntax, PHP formatting and whitespace checks pass. Browser verification confirms the opening popup carries current physical cash, starts at 10%, enforces change reasons and rate limits, and the job page has editable washing assignments. Popup POST returns JSON validation errors without losing entered values.

Regression tests include `tests/Feature/CashierWashingAssignmentTest.php`, which exercises five same-day opening/closing cycles without repeating money or withdrawals, saved-default isolation, inheritance and overrides, billing and salary commission, paid commission protection and permissions.

## Washing assignment at opening

The popup suggests the carried cash on first opening, and current cash in hand on a same-day reopening. Any difference needs a reason and posts only that difference. It also selects an active staff member as today’s washing person and a percentage initially defaulting to 10%. The checkbox saves a new percentage default separately for Workshop and Helmet shop. A rate entered without the checkbox applies to that opening only. Assign later keeps washing unassigned.

Normal jobs and quick jobs inherit the active cashier’s person and rate, with editable overrides. Assignments are stored on each job; future openings and default changes do not rewrite older jobs. The job’s Washing Assignment panel allows later changes, including on billed jobs whose washing commission is still pending. Washing commission is earned at billing from washing charge × percentage, appears separately in staff commissions and salary previews, and never changes Service + Labor commission. Payroll-linked or paid washing commissions must be resolved before their assignment, rate or charge is changed. Existing jobs are not assigned retroactively.

Migration `2026_10_29_000004_add_washing_assignments_and_cashier_history` adds washing assignments and the append-only opening/closing history. It does not create commissions for old jobs.
