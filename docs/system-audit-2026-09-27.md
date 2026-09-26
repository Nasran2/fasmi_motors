# Fasmir Motors Audit and Repair Report

Date: 27 September 2026. Existing Laravel/MySQL/Blade application.

## Status and Evidence

Substantial repairs are implemented and regression-tested. This is not an unconditional certification that every CRUD action, integration, permission combination, and viewport is defect-free. The explicit outstanding verification gaps are listed in R.

- Final Laravel suite: **112 tests passed, 1,015 assertions**.
- Production frontend build passed; Blade compilation passed; PHP lint passed across 208 files; 313 routes were listed successfully.
- Scoped Laravel Pint check passed.
- Read-only browser navigation covered 140 observed local links. Populated-page failures were corrected and rechecked.
- Desktop dashboard, mobile dashboard/product form/confirmation, and tablet payroll were visually inspected. Requested mobile/tablet overrides were reset afterward.
- The mobile confirmation was canceled; no live template was deleted.
- Production financial transactions were not created for testing. Financial test fixtures ran in `fasmir_motors_testing`.
- Evidence files are private: `storage/app/private/audit-tests-2026-09-27.json`, `audit-phpstan-2026-09-27.json`, `audit-php-lint-2026-09-27.txt`, and `audit-routes-2026-09-27.json`.

## A. Modules Audited

Source inspection, route inspection, existing tests, new regressions, and browser navigation covered dashboard, customers/bikes/ownership, intake/jobs, products/stores/stock, purchases/suppliers/returns, invoices/payments/dues, operational accounts/bank/cash views/transfers/closing, expenses/drawings, staff/attendance/payroll/advances/commissions/bike deductions, reminders/SMS, reports, settings/backups, authentication, and roles/permissions.

Evidence is not identical for every module: accounting and payroll received transactional scenario tests; all observed navigation pages received read-only browser checks; external messaging and destructive recovery were not executed against live data.

### Existing Relationship Map

| Workflow | Existing records and relationship |
|---|---|
| Repair | Customer -> Bike -> BikeIntake -> JobCard -> Invoice -> Payment |
| Repair parts | JobCard -> JobCardPart -> Product / Store / StockBatch |
| Direct sale | Invoice -> InvoiceItem -> Product / Store / StockBatch |
| Inventory | StoreStock aggregates quantities; StockBatch tracks purchase lots; stock_movements retains movements |
| Procurement | Supplier -> Purchase -> PurchaseItem; SupplierPayment and PurchaseReturn retain payment/return sources |
| Accounting | Account -> AccountTransaction; polymorphic reference identifies Payment, Purchase, Expense, Drawing, salary sheet, or transfer |
| Payroll | Technician -> attendance, advances, commissions, deductions, salary sheets and contributions |
| Staff repair | TechnicianBikeDeduction links technician/bike/job/invoice; salary snapshot links each monthly recovery |
| Reminders | ServiceReminder -> Bike -> Customer, plus originating JobCard |

No parallel financial system or duplicate operational account tables were introduced.

## B. Problems Discovered

1. Repair payments used inconsistent service-first versus proportional allocations across payment entry points.
2. Historical parts receipts were understated in two invoices.
3. Active-only ledger sums incorrectly dropped reversed originals while retaining their compensating entries.
4. Purchase payments, operating expenses, drawings, advances and salary payments could follow inappropriate account selections.
5. Legacy transfers posted both legs to Main Account.
6. Invoice revisions could leave previously received money allocated to stale components.
7. Generic supplier payments lacked purchase-level allocation for later cancellation.
8. Cash Book omitted operational cash transactions; closing/report summaries summed physical mirrors with logical accounts.
9. Payroll recovery could consume advances/commissions or repair installments repeatedly; finalize-and-pay was not one atomic operation.
10. Draft salary replacement deleted the old draft outside the replacement transaction.
11. Opening-balance replacement lacked an atomic lock and safely optional edit reason.
12. Bike list subqueries omitted `bikes.*`; customer dues assumed every invoice had a repair job.
13. Report searches used nonexistent job/bike/product/drawing fields or ungrouped OR conditions.
14. Reminder report eager-loaded an undefined direct customer relationship.
15. P&L classified whole repairs as service revenue, omitted repair parts costs, and deducted owner drawings from profit.
16. Hidden/screen-reader file input styling caused desktop product-form overflow.
17. Native browser confirmation/notification calls remained across views.
18. POS retry protection did not cover successful JSON submissions.
19. Backup records and create/restore/delete successes were placeholders.
20. Customer TV board consumed an incompatible JSON shape and interpolated unescaped record text into HTML.

## C. Problems Fixed

The issues above were repaired in the existing services/controllers/views. Payment allocation and outgoing postings are centralized, source cancellations retain compensating history, related writes use transactions and fresh row locks, and selected installment amounts are retained in salary snapshots.

Read-only browser checks confirmed the previously failing bike, due, product-create and reminder-report pages. The TV board now renders the endpoint's grouped records. Closed or invoiced jobs cannot be reset through the live-board status endpoint.

## D. Database/Migration Changes

Applied only `2026_09_27_000001_link_commissions_to_salary_sheets.php`:

- Nullable `technician_commissions.salary_sheet_id` foreign key connects commission consumption to its salary sheet.
- Index on ledger reference type/reference ID/status supports source reconciliation and reversals.

A full SQL snapshot was taken before production migration/reconciliation: `storage/app/private/audit-before-2026-09-27.sql` (874,964 bytes, mode 0600).

A subsequent managed SQL backup was successfully generated under `storage/app/private/database-backups/` by `database:backup`.

No production `migrate:fresh`, `db:wipe`, table truncation or database drop was run. Existing user changes and existing records were preserved. Tests use their separate database and RefreshDatabase infrastructure.

## E. CRUD Fixes

- Populated bike and opening/direct invoice lists render without missing route parameters or null repair relationships.
- Posted financial records retain cancel/reversal semantics instead of hard deletion.
- Source-linked ledger entries cannot be independently reversed from the general ledger screen; cancellation must occur through the source workflow.
- Purchase payment validates the selected purchase belongs to the supplier and remains unpaid/active.
- Opening replacement requires an edit reason and executes under an account lock.
- Cheque creation/clearing are transactional, reload the cheque under lock, and reject reverting a cleared cheque to pending/deposited without a reversal.
- Draft salary replacement executes within the create transaction.
- Backup listing/create/delete use real private files; invalid file identifiers/path traversal are rejected.

## F. Accounting Fixes and Live Reconciliation

Reversed originals remain posted history and are offset by explicit reversing entries. Logical Main/Spare accounts represent business ownership; Bank is a physical non-cash mirror and must not be added to their combined balance. Cash Book derives cash movements from existing postings without writing a third copy.

Repair receipts are allocated proportionally to invoice components, including partial and subsequent payments. Cumulative cent rounding makes repeated receipts reconcile to the component total. Parts-only sales route all receipts to Spare Account, including later dues. Salary deductions and manual clearance are not cash receipts.

| Historical invoice | Received | Parts previously posted | Parts expected | Parts correction |
|---|---:|---:|---:|---:|
| INV-260801-GLBJ6UK3AG | 6,000 | 200 | 1,360 | +1,160 |
| INV-260926-X5SWDWASIA | 3,000 | 0 | 1,000 | +1,000 |

Appended equal and opposite Main/Spare allocation corrections. No invoice/payment was deleted or duplicated, and the combined operational balance did not change.

| Account | Before | Corrected |
|---|---:|---:|
| MAIN | 8,800 | 6,640 |
| SPARE | 900 | 3,060 |
| BANK | 0 | 0 |
| Combined Main + Spare | 9,700 | 9,700 |

Subsequent workshop and helmet receipt reconciliation dry runs reported zero component-allocation discrepancies. This command checks receipt allocation, not every possible historical stock, cash count, supplier opening, or payroll discrepancy.

## G. Main Account Rules Verified

Service/labor/nonparts receipt shares increase Main. Operating expenses, advances and actual new salary payments reduce Main. Non-cash payments also update the physical Bank mirror. Advance recovery and staff-repair offsets do not reduce Main a second time.

Regression scenario: opening 100,000 + repair nonparts 10,000 - expense 3,000 - actual salary cash 4,000 = **103,000**.

## H. Spare Parts/Owner Rules Verified

Parts receipts increase Spare; purchase payments and owner drawings reduce Spare. Cash supplier refunds increase Spare and reduce paid purchase value while preserving unpaid dues. Supplier credits can retain a negative supplier balance rather than disappearing at zero due.

Regression scenario: opening 100,000 + parts 20,000 - purchase 5,000 - drawing 2,000 = **113,000**. Cash movement across both operational accounts is 16,000, without including their assumed logical opening funds in the physical cash count.

## I. Attendance

Existing attendance/payroll tests were executed, including attendance rules, absent days and payroll connections. Attendance was not rebuilt and no unrelated attendance formula changes were introduced. Finalized payroll protections and deduction snapshot validation remain in the existing payroll service.

## J. Salary/Payroll Fixes

Finalize and optional payment execute atomically. Advances/manual deductions are checked for prior consumption. Commission recovery is attached to a salary sheet and restored when an eligible sheet reopens. Draft replacement is atomic. Actual salary disbursement, not gross salary or recovered advances, is posted to Main. Existing intentional partial-payment/overpayment support is preserved.

P&L includes finalized gross salary plus employer contributions, excludes drawings from profit, and includes repair parts cost. It is an accrual-oriented operational report, not statutory financial statements or tax advice.

## K. Staff Bike Repair Deductions

Reused TechnicianBikeDeduction and existing invoice/payment structures. Each salary preview exposes a numeric installment per outstanding plan. The selected amount is validated against remaining debt and salary capacity, stored in the snapshot, and consumed on finalization.

Recovery creates a clearly identified `Salary Deduction` settlement Payment, reduces invoice due, and records no additional cash deposit or duplicate sales entry. Reopening restores the receivable, cancels that offset, and restores the original next deduction date. Invoice/payment cancellation cannot independently dismantle a finalized salary offset.

Verified 24,000 recovery: 4,000 -> 20,000 remaining; 6,000 -> 14,000; 5,000 -> 9,000; 9,000 -> 0/Completed. Four recovery records and zero cash ledger entries.

## L. UI/UX Fixes

- Corrected invisible-input overflow without redesigning the product form.
- Added week filters and visible Spare/Owner balance to the dashboard.
- Updated chart positioning/date labels for variable-length periods.
- Added submitting states and preserved clicked submit-button values.
- POS clears the completed cart and reports JSON validation messages.
- Ledger source-reversal controls now match backend restrictions.
- Customer TV board uses the real grouped endpoint and escapes record text.

## M. Browser Popup Replacements

Replaced native `alert()`, `confirm()` and `prompt()` usage in application resources with styled in-system notification/confirmation dialogs. Source scans returned no remaining application-resource calls. Confirmation opens with Cancel focused, supports Escape/backdrop cancellation and removes its dialog/backdrop on close. Mobile opening/cancel was exercised against a live template without deleting it.

## N. Security/Permissions

Existing server-side route-permission middleware remains authoritative. Added missing data-management mapping and explicit customer/product import/export permissions. Exports require both module visibility and spreadsheet-export permission. Submission tokens are UUID-validated; successful redirect and JSON retries return the original result under a cache lock. POS includes its token and rejects simultaneous clicks.

SQL backup credentials are supplied through process environment, not shell interpolation. Backup names are restricted to managed identifiers. Restore validates the current password and explicitly refuses unsupported online restoration. Ledger export text uses spreadsheet formula escaping. No security setting, live user privilege or password was weakened to test the system.

## O. Tests Created/Updated

`tests/Feature/FinancialAuditRegressionTest.php` adds 18 regression cases (including dataset cases): mixed/parts-only/service-only cash and card receipts; due settlement/retry; reversal/backdating; invoice revision allocation; stock rollback; variable monthly repair offsets; finalize rollback; complete Main/Spare scenario; populated bikes/opening dues; report searches; opening replacement; backup file/password gates; cent rounding/customer dues; POS JSON retry; supplier cash-refund/cancellation.

Updated purchase posting expectations to Spare and explicit dashboard week-filter selection. Added customer-board response-shape coverage to LiveBoardTest. Existing auth, permission, inventory, workshop, attendance, salary, reports and CRUD suites were all run.

## P. Tests Passed

Final full suite: **112 passed, 1,015 assertions**. Vite production build passed. Scoped Pint passed. Route listing and scheduler listing succeeded; final PHP lint and Blade compilation results are recorded in their command evidence.

## Q. Failed Checks

No remaining Laravel test failures in the final suite. PHPStan is **not clean**: the latest recorded scan reports 173 diagnostics, including model relationship/type/static-analysis issues. Earlier test failures were corrected and rerun; they are not hidden or baselined away. The initial whole-dirty-tree formatting check also included unrelated preexisting helper/import changes; formatting was then scoped to task-owned PHP changes. Whole-tree `git diff --check` still reports whitespace in mixed preexisting/touched views and job-card changes; no semantic user work was discarded to make that check appear green.

## R. Outstanding Issues and Verification Limits

1. **Static analysis:** diagnostics remain across the existing application. Many involve insufficient Eloquent relationship typing, dynamic model properties or collection generics. A green runtime suite does not prove all are benign. They remain explicitly un-certified rather than suppressed; full type cleanup needs its own comprehensive behavior review.
2. **Online database restore:** deliberately unavailable. Safely restoring requires a verified backup, maintenance isolation and an offline recovery rehearsal. Executing a live overwrite would violate the requested production-data preservation. The former fake success was removed. SQL backups are real; automatic execution also requires the host to run Laravel's scheduler.
3. **Cloud backups:** Google Drive/S3 targets were removed from selectable storage because this repository has no verified provider integration. No credentials were transmitted or infrastructure invented. Managed backups are local/private SQL only, not a full upload-file/archive backup.
4. **External SMS/bank/cheque settlement:** live provider delivery, actual bank settlement and cheque clearing were not executed. These require real external events/credentials and can incur costs or financial consequences; page checks and mocked/local tests cannot certify provider behavior.
5. **Full acceptance coverage:** 140 navigation checks are not a test of every button, CRUD variant, role matrix or every page at four viewport sizes. Existing/new tests cover substantial workflows, but exhaustive manual certification remains unclaimed. A production-safe staging acceptance run is required before declaring the user's complete A-Z criteria satisfied.
6. **Physical opening cash:** logical account opening funds do not identify cash-versus-bank ownership. Cash Book starts from recorded cash postings; historical physical opening cash requires an explicitly reconciled cash opening record, not guessed allocation from Main/Spare funds.
7. **Performance/concurrency:** row/cache locks and token retries were tested functionally, not through a parallel stress/load test. Running balances are recalculated chronologically on ledger changes; large-history write performance still needs profiling.
8. **Deployment/debug policy:** local development displays Laravel debug details. Production must set `APP_DEBUG=false` and use appropriate HTTPS/session/security configuration; local credentials/settings were not silently changed.

## S. Files Changed

Task changes are concentrated in:

- Services: AccountService, WorkshopService, SalaryCalculationService; new DatabaseBackupService.
- Controllers: SalesBilling, Purchase, ExpenseDrawing, Finance, Technician, CustomerBike, Dashboard, Report, Setting, LiveBoard, and existing Accounts controllers.
- Models: Account, AccountTransaction, JobCardPart, Supplier, FinancialCategory; ShopScoped trait.
- New PreventDuplicateSubmission middleware; bootstrap registration; route-permission configuration; backup schedule in routes/console.php.
- New commands: ReconcileRepairReceipts and CreateDatabaseBackup; one safe migration described in D.
- Shared resources/js/app.js, resources/css/app.css and new submission-token Blade component; existing forms updated for tokens/styled confirmation; payroll/POS/customer dues/dashboard/ledger/backup/customer-board views updated.
- Financial regression tests, DashboardTest, PurchaseModuleTest, LiveBoardTest; rebuilt public/build assets/manifest; this report.

The working tree already contained extensive user changes, untracked imports/exports, controllers, helper scripts and generated assets. The complete git diff includes that work and must not be represented as exclusively authored by this audit. No commit or rollback of unrelated changes was performed.

## T. Commands Executed

Key verification and maintenance commands (run from the project root):

```sh
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php vendor/bin/pest --compact
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php vendor/bin/pest tests/Feature/FinancialAuditRegressionTest.php --compact
npm run build
php vendor/bin/pint <task-changed PHP paths>
php vendor/bin/pint --test <task-changed PHP paths>
php vendor/bin/phpstan analyse --memory-limit=512M --error-format=json
php artisan route:list --json
php artisan schedule:list
php artisan view:cache
find app bootstrap config database/migrations routes tests -name '*.php' -print0 | xargs -0 -n1 php -l
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php artisan migrate --path=database/migrations/2026_09_27_000001_link_commissions_to_salary_sheets.php --force
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php artisan accounts:reconcile-receipts
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php artisan accounts:reconcile-receipts --apply
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php artisan accounts:reconcile-receipts --shop=helmet
DB_SOCKET=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock php artisan database:backup
```

Used read-only `rg`, `sed`, schema/source inspection and git diff/status throughout. Pre-change mysqldump ran through Symfony Process with credentials in environment. Browser work used the authenticated local session and a temporary audit tab. The existing development application remains available at `http://127.0.0.1:8001`.
