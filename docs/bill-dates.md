# Bill and payment dates

Run `php artisan migrate --force` when deploying. The additive migration creates nullable indexed `invoices.bill_date` and nullable `job_cards.bill_date`. Existing invoices use their creation timestamp as their bill date until explicitly corrected. Creation timestamps remain audit metadata.

Job pages and invoice detail pages provide **Edit bill date**, protected by `job_card.edit`. Corrections require a reason and preserve the assigned bike and all payment dates. Standard 30-day due dates follow the bill date; explicitly customized due dates are preserved. Pending commissions follow corrected bill dates; paid salary commissions require the salary sheet to be reopened first.

The job overview's **Edit bike number** corrects the existing bike's complete registration in place, using the same permission. It preserves the bike ID and customer/vehicle identity, so all linked intakes, normal and quick jobs, bills, reminders, repair deductions and reports show the new registration. Quick-job fallback snapshots are updated without changing completion timestamps. Active and archived registrations cannot be reused from another bike. The correction requires a reason, records before/after values on the bike audit log, and appears in the source job's change history. This is a plain text editor, separate from choosing a different bike.

Job billing, the separate Job Card Billing screen, and POS checkout accept independent bill and payment dates. Supplied dates must be valid calendar dates from 1900 through today. Older integrations may omit these fields and retain their existing default dates. An explicitly empty supplied date is rejected.

Date bases:

- Sales, spare-parts sales, profit and loss revenue, invoice lists, bill-date due filters, dashboard sales and daily-closing bill lists use the effective bill date.
- Payment Collections and payment lists use `payments.paid_at`. They do not filter by invoice date or receipt creation time. Sales reports label lifetime balances and link to Payment Collections for receipts within a date range.
- Account ledgers, cash book and daily-closing cash movements use `account_transactions.transaction_date`. Cash book shows this as its primary date and keeps recording time as secondary audit information. A backdated receipt enters that day and carries forward into later opening balances.
- Payment corrections reverse old ledger amounts on their original effective date and post replacement receipts on the selected replacement date. Invoice allocation corrections retain the original posting date, including cheque clearance dates. Actual cancellations/refunds retain their own event dates.
- Purchases, expenses, drawings, attendance, salary periods, contributions, job activity and reminders keep their existing applicable business dates. Each report identifies its date basis. Outstanding balance reports remain current balances filtered by bill date, rather than reconstructed historical debt snapshots.

Browser print, PDF bills and CSV reports use the same dates as the displayed records. Invoice identifiers are immutable references and retain their originally generated numbers after a date correction.

Regression coverage: `BillDateReportTest` exercises separate bill/payment/audit dates, report and export boundaries, brought-forward cash, receipt corrections, permissions, date validation, POS checkout, legacy dates and commissioned salary protection. The existing reporting, payment, printing, invoice, account and dashboard suites cover compatibility.

Validation: 212 related regression tests passed (1,815 assertions), followed by all 12 date-specific tests passing after the final due-date regression was added. The registration correction workflow is covered separately by `BikeRegistrationCorrectionTest`, including shared references, duplicate protection, permissions and preservation of bill and payment dates. Core date/account/report PHPStan checks passed, Pint passed and the production frontend build succeeded. The full application run retained seven previously identified unrelated failures; a temporary missing-font error caused by building assets during that run passed when rerun after the build.

Registration correction validation: 64 related registration, date, payment, quick-job and invoice tests passed; all 11 registration cases passed after adding the final number-validation case. The top-panel editor was visually verified, and the registration/date services passed PHPStan and Pint.
