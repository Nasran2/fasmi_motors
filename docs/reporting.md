# Report calculations and filters

The Reports menu contains 24 reports. Every table, summary and breakdown uses its report's selected filters. Print and CSV include every matching row, regardless of the current pagination page. Export and print permissions are checked on the server.

## Dates and balances

- Sales and jobs use invoice creation and job creation dates respectively.
- Purchases use purchase dates; expenses and drawings use their recorded transaction dates.
- Customer and invoice due reports default to all time. Their date range restricts invoice issue dates; balances are **current outstanding amounts**, not reconstructed historical balances at the end of the range.
- Supplier Due Bills works the same way for purchase dates. Supplier opening balances and advances remain in the supplier account; this report is explicitly a list of unpaid purchase bills.
- Payment Collections uses receipt dates, so payments against older invoices appear when received. Completed receipts are shown net of refunds to date. Held cheques and non-cash salary/manual offsets are separate. This is a receipt report, not a historical cash-flow statement.
- Sales Returns uses return dates, showing credits separately from refunds. Returns remain visible when an invoice is subsequently cancelled.
- Stock is explicitly a current snapshot, with store, category, rack, stock status and product filters. Values use current product cost and selling prices, rather than historical/FIFO valuation. Date controls are not offered for this snapshot.
- Salary reports include whole sheets whose periods overlap the selected range; they do not prorate sheets. Drafts require an explicit status selection. Contribution reports use salary months and finalized sheets.
- Account opening and closing balances cover the selected period and include posted/reversed transaction history. Search/type/method filters affect the displayed movements and filtered totals; they do not redefine the account's opening or closing balance.
- Daily closing defaults to closed days. Selecting reopened days displays their saved snapshots.

## Sales and profit

- Cancelled invoices, quotations and both opening balance invoice types are excluded from sales revenue.
- Sales shows invoice totals after returns, including sales tax, with paid, held and due amounts separately.
- Customer labels prefer the invoice customer. Legacy invoices can resolve through intake or quick jobs, and archived customers remain available for historical bills.
- Parts revenue respects line discounts, returned quantities and invoice-wide discounts. Invoice revenue is allocated between parts and additional charges in proportion to their pre-discount values. Sales tax is excluded from profit calculations. Cumulative rounding keeps each line nonnegative and makes line totals reconcile exactly.
- Profit and loss recognizes invoice revenue excluding tax, less stock parts cost and operating expenses. It excludes opening balances and quotations. External job parts are already expensed when purchased and are not counted a second time as stock cost.
- Finalized salary gross pay and employer EPF/ETF contributions are recognized at salary period end. Owner drawings are shown separately and do not reduce profit.
- Returns restate stored invoice totals. Consequently an earlier invoice-period sales report reflects subsequent returns; the separate Returns report identifies when those returns occurred.
- Daily closing totals sum main and spare-parts logical ledgers. Bank and cash values represent overlapping physical movements and are not added again.

## Available filters

| Reports | Filters in addition to dates |
| --- | --- |
| Sales | Searchable bike and customer, invoice type, payment status, payment method, invoice/customer/bike search |
| Job cards | Searchable bike, customer and technician, actual workflow statuses, job/customer/bike search |
| Parts sales | Searchable bike and customer, store, payment method, product name/code search |
| Profit and loss | Date range for the complete business result |
| Stock | Store, category, rack hierarchy, stock status, product name/code, sort |
| Purchases / supplier due bills | Supplier, store, payment status, bill/supplier search; purchases also has payment method |
| Expenses | Category, account, payment method, vendor/description/reference/note search |
| Drawings | Drawing type, account, payment method, person/reference/note search |
| Due bills | Searchable bike/customer, invoice type, payment status, invoice/customer/phone search |
| Customer due | Searchable bike/customer, invoice/customer/phone search |
| Technician performance | Technician, active/inactive, name search |
| Attendance | Technician, attendance status, staff name search |
| Salary | Technician, sheet status, staff name search |
| EPF / ETF | Technician, staff name search |
| Account reports | Transaction type, payment method, reference/description search |
| Daily closing | Closed/reopened, closing date search |
| Service reminders | Bike, customer, reminder status, SMS delivery status, bike/customer/phone search |
| SMS | Delivery status, module, recipient/phone search |
| Payment collections | Bike, customer, receipt status, method, invoice/receipt/customer search |
| Sales returns | Bike, customer, invoice/customer search |

Customer suggestions include phone numbers to distinguish namesakes. Date shortcuts clear stale custom dates while retaining the other selected filters. Counts are displayed as counts rather than rupee amounts.

## Verification

`tests/Feature/ReportCalculationTest.php` exercises every report and CSV endpoint, filtered summaries, legacy and quick jobs, outstanding balances, returns, discounts, tax, penny allocation, stock filtering, purchase returns, cancelled transactions, account reversals, closing totals, SMS delivery, receipt dates, archived customers, salary recognition, permissions, date boundaries, and complete print/CSV output. Existing report, payroll and billing regression suites provide additional coverage.

The final report-focused run passed 98 tests (727 assertions). Browser checks confirmed bike-number and customer-phone suggestions; the production frontend build and targeted PHPStan/Pint checks passed. The full application run found seven failures in existing permission, inventory/POS and staff workflows; the same seven failures were reproduced with the original source. Project-wide Pint and PHPStan also identify issues in unchanged files.
