# Account report source links

Main, Spare Parts, Bank and Washing account movement tables link both the transaction reference and description to the source in a new tab.

- Receipts open their invoice. Job entries and job advances open their job card. Readers with job permission but without internal invoice permission can open the related job instead.
- Expenses and drawings open a read-only detail page, including notes, cancellation status and archived records.
- Purchase entries, supplier payments and purchase returns open the related purchase when one is available. Sales returns open the invoice.
- Salary entries open the saved paysheet. Commission payments open the person's commission detail and payment history. Cashier adjustments open the relevant cashier date.
- Reversals retain the original source reference. Missing, manual and unsupported references open a read-only transaction detail page.

The source endpoint requires report permission and validates the transaction's account against the active shop. Each destination retains its own permission checks. If the reader cannot open the source, the endpoint displays the existing report transaction details instead.

This change does not post transactions, alter balances or change exported report values. No database migration is required.

Local validation: `AccountReportSourceTest`, `ReportCalculationTest`, `PrintLayoutTest` and `ExpenseReminderModuleTest`; production asset build and Blade compilation.

## Cashier account movement popups

In the cashier's separate account balances table, each Credits and Debits amount opens a read-only popup for the selected account and cash day. The popup lists the entries contributing to that amount, their descriptions, methods, source references, invoice numbers, job numbers and bike registration numbers where available. Invoice and job links open in a new tab and retain document permissions.

Totals include posted originals and compensating reversal entries, matching the cashier summary. Opening corrections (`cashier_opening` and `cashier_account_opening`) belong to Opening Balance and are excluded. Each popup shows 25 entries per page while retaining the full total. Zero amounts open an empty-state message. Unknown references open transaction details.

Popup and source access require the same permissions as the cashier page and remain limited to the active shop. The physical drawer, receipts, balances and original records are not changed by viewing a popup. No migration is required. Regression coverage: `CashierMovementPopupTest`, `AccountReportSourceTest`, `SeparateCashierDrawerTest`, `CashierSessionTest` and the cashier JavaScript checks.
