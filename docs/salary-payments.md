# Salary details, overtime and adjustments

## Recalculate saved paysheets and record dated partial payments

Salary Payments now offers **Recalculate** on Draft, Finalized, Partially Paid and Paid rows. The reason popup applies current attendance, staff salary, OT and EPF/ETF rules to the saved salary period. Drafts remain drafts. Finalized sheets retain their applied advances, repair deductions and old-balance recoveries; recalculation does not recover them again or select new debts. Manual monetary overrides and described adjustments remain. Recorded salary payments and their dates are preserved, and the outstanding balance/status update. A previously paid sheet with a larger net amount becomes Partially Paid so its extra balance can be paid separately.

The recalculation reason and earlier totals are saved in the snapshot and audit log. Overpayments use the existing carried-balance recovery mechanism, without issuing a refund. Recalculation rolls back if applied deductions exceed the new earnings, if the salary period changes or overlaps another finalized sheet, if already-paid EPF/ETF figures would change, or if an already-recovered overpayment would be rewritten. Ordinary attendance editing and bulk recalculation still respect finalized-period locks; the explicit paysheet action recalculates its own attendance under the staff lock.

**Finalize** offers full payment, partial payment or finalize only without payment. Unpaid finalized and partially paid sheets show **Pay / Partial Pay**. Select the amount, payment date and funding source; balances before and after payment are displayed. Sources refresh for the selected payment date. Finalizing without payment does not require a funding source. No tests or browser/runtime checks were run for this change, and live data was not changed.

Overtime eligibility includes a check-in exactly at workshop opening time. With opening at 08:00, 07:59 and 08:00 qualify; 08:01 does not. Eligibility still requires an actual checkout beyond the configured closing grace period. Missing/automatic checkout does not earn automatic OT. Existing manual amount overrides remain available.

In Salary Payments, save a draft and choose **View** to see the complete salary period with daily check-in/out times, status, worked hours, overtime and attendance deductions. New calculations capture attendance times with the salary snapshot. Legacy sheets without this snapshot show current attendance records with an explanatory note; their saved salary amounts are unchanged.

Use **Add an earning or deduction** in View to enter multiple items, one at a time. A description is required; an additional note is optional. Descriptions and notes appear on the payslip. The generation form also accepts an initial described earning and named deductions with notes. Adjustments are available for drafts, finalized unpaid sheets and partial payments. Each is audited. They update salary totals without creating cash payments, changing paid amounts or reapplying advances, repair deductions or commissions. Deductions cannot exceed gross earnings or reduce net payable below salary already paid. Fully paid sheets do not accept these new adjustments.

**View / Print Payslip** is available before payment and after partial/full payment. It shows status, paid amount and balance. Drafts are explicitly marked as drafts, and an unpaid/partial slip does not imply full payment.

For earlier attendance saved under the previous strict-before-opening OT rule, choose **Refresh Attendance & OT** on a draft and provide a reason. It recalculates attendance and draft salary together, retains manual amount overrides and adjustment descriptions, refreshes the attendance snapshot and contribution totals, and never creates a payment. Finalized attendance remains locked; an unpaid finalized sheet can first be reopened through the existing action. Refreshing cannot change a salary period or update dates overlapping another finalized sheet. Finalization rejects attendance-time changes after a draft snapshot.

Verification was local using the isolated testing database: 167 payroll/attendance/repair/advance regression cases passed (2,893 assertions), plus three salary-preview JavaScript cases. No live server or live financial data was changed.

## Recalculate attendance in bulk

Staff Attendance now has **Recalculate All Attendance** below the filters. Choose the applied period, or **All recorded dates** to process historical records too. Both scopes respect the applied staff and status filters; choose All technicians and All statuses to recalculate all recorded attendance. A reason is required and saved with each updated record. Processing uses ID-based chunks, so it includes all table pages even when derived statuses change.

Finalized, partially paid and paid salary periods are skipped under the same staff lock used by payroll. Manual OT/deduction amount overrides are retained while other metrics refresh. Invalid punch times are skipped and reported. The result reports recalculated records, locked skips, preserved manual amounts and invalid rows. It does not create attendance, record payments or overwrite stored salary drafts; use the existing draft salary refresh action before finalization.

Bulk recalculation verification: 55 selected attendance/payroll cases passed (383 assertions), including 205 filtered records across pagination/chunk boundaries, staff/date/status filtering, all locked salary states, manual overrides, invalid times, reason validation and permissions. The production build and compiled attendance template passed locally.

## Nearest half-hour overtime

Eligible OT is now rounded to the nearest 30 minutes, with half-way ties rounding up. For a 17:00 closing and Rs. 150 hourly OT rate, 17:16 and 17:44 each pay 30 minutes (Rs. 75); 17:45 pays 60 minutes (Rs. 150). The eligibility check uses the actual check-in/out and configured closing grace period before rounding. A check-in after opening and an automatically filled checkout remain ineligible for automatic OT.

Actual checkout times and working minutes are unchanged. Migration `2026_10_29_000007_add_actual_ot_minutes_to_attendance` adds nullable `actual_ot_minutes`. The existing `ot_minutes` / `ot_hours` hold payable duration after recalculation. Hourly regular earnings subtract actual OT duration; rounded overtime therefore does not take away regular earnings or pay the same actual overtime again as regular time. Older rows retain their existing basis until recalculated, and manual amount overrides remain intact.

Deploy the migration before using the updated calculator. Use **Recalculate All** for existing unlocked attendance, then **Refresh Attendance & OT** for existing salary drafts. Finalized and paid salary records remain locked. Fallback slab amount rounding remains a separate setting from the new nearest-half-hour duration rule. The migration was applied only to the guarded local development database; live data was untouched.

Nearest-half-hour verification: 192 selected payroll/attendance/repair regression cases passed (3,059 assertions), including 17:16 / 17:44 / 17:45 boundaries, later-hour ties, actual punch preservation, hourly regular-pay protection, grace/late/auto-checkout exclusions and legacy bulk/draft refresh. Production build, compiled modified templates, PHP formatting and whitespace checks passed locally.

## Commission payments

New/refreshed salary calculations exclude commission. Commission is managed separately under Staff → Commission, including described deductions, partial/full payments and daily/monthly slips; see `docs/commission-payments.md`. Historical finalized totals remain as recorded, and an old draft containing commission must be refreshed before finalization.

## Separate EPF / ETF salary basic

Add Staff and Edit Staff include an editable **EPF / ETF salary basic · LKR** field. Migration `2026_10_29_000009_add_epf_etf_basic_salary_to_technicians` supplies Rs. 30,000 for existing and new profiles. The field accepts non-negative amounts with up to two decimal places; an omitted field on an older profile update preserves the saved value.

Employee EPF, company EPF and company ETF use this amount and the staff member's configured percentages and eligibility flags. Main basic pay, attendance earnings, OT, allowances, commissions and deductions do not form the contribution base. Changing the field does not change those earnings or their rates. The contribution amount is not prorated by salary type, attendance days or the salary period.

For a Rs. 75,000 main salary and Rs. 30,000 contribution basic, rates of 8%, 12% and 3% produce Rs. 2,400 employee EPF, Rs. 3,600 company EPF and Rs. 900 ETF. Only employee EPF reduces net salary, as before.

New paysheets snapshot the contribution basic and percentages. Contribution records, reports, salary details and payslips show the saved basis. Updating a profile does not rewrite existing sheets or contribution records. Use **Refresh Attendance & OT** to explicitly recalculate an unpaid draft from the new profile settings. Old drafts with contribution eligibility must be refreshed before finalization; finalized and paid sheets remain preserved.

Deploy the migration before using the field. It was applied only to the guarded local development database. Local verification: 168 selected staff, payroll, commission and print cases passed (2,716 assertions); production build and Blade compilation passed. No live server changes or tests were performed.
# Old balances and repair recovery

**Old balance owed to shop** accepts an opening debt, description, note and the amount to recover this month. Enter existing debt once; do not enter advances or repair bills again. Available carried balances appear on later monthly previews with a separate recovery input; zero skips recovery for that month.

Ordinary deductions are applied first. Recovery then uses available salary for old balances followed by repairs. Requests exceeding that capacity produce a highlighted negative requested balance, while actual salary payable remains non-negative. Unrecovered old debt stays on its original balance, and unrecovered repair debt stays on its original repair plan. Only applied repair recovery settles an invoice; no extra cash receipt is created.

Previewing, saving/replacing and refreshing drafts never create debt or recoveries. Finalization records the opening debt and its actual recovery once, with staff-level locking and stale-balance checks. Reopening an unpaid sheet restores recovery. An originating sheet cannot be reopened while a later finalized sheet uses its debt. Saved debt snapshots remain historical; profile edits do not recalculate prior paysheets.

Migration `2026_10_29_000010_add_salary_balance_recoveries` adds recovered amounts and per-paysheet recovery records. Run pending migrations when deploying. Existing salary-overpayment adjustments use the same optional partial-recovery flow, preserving earlier deducted amounts.

Payslips show old balances, repair references/bikes, recovery and the combined remaining balance prominently. Month-boundary counters and the salary-cycle conditions panel are removed from the slip; actual salary period dates remain. Print spacing was compacted and a sample with old debt and a repair was visually verified as one A4 page. Long item lists may continue onto additional pages rather than clip.

Latest local monetary regression run: 102 cases passed (2,552 assertions), plus five salary-preview JavaScript checks. A broader run also exposed an unrelated existing attendance expectation: an Absent row with punches is now normalized to Present in the current attendance code. This salary work does not alter that behavior. Live data was not changed.

## Full basic salary and separate unpaid leave deductions

New/refreshed Daily and Monthly paysheets show the saved staff-profile **Basic Salary** in earnings. Daily salary no longer multiplies attended days to reduce the earnings line. **Unpaid leave / absence** is a separate deduction: explicitly recorded Absent / No Pay Leave days on working dates × the staff daily salary rate. Paid/approved leave and weekly/special shop-closed dates do not produce automatic no-pay deductions. Missing attendance rows are not silently counted as absences.

Half-day deductions use 50% of the staff daily salary once, now that the Daily basic is no longer reduced by half-day attendance. Manual attendance amount overrides remain explicit adjustments in deductions, including waived amounts; they are not also added to Daily base earnings. Late/early leave, advances and other deductions retain their own lines. Hourly earnings continue to use actual regular hours, and Weekly earnings keep their prorated period basis. EPF/ETF still use their separate saved contribution basic.

For example, Basic Salary Rs. 65,000, one unpaid day at Rs. 2,600 and no other amounts gives earnings Rs. 65,000, unpaid leave / absence deduction Rs. 2,600 and net Rs. 62,400. Six shop-closed days do not add another deduction. The actual result also includes the paysheet's OT, other earnings and deductions.

Payslips and salary details label the saved full amount **Basic salary** and show the unpaid day count/rate. New snapshots record the salary basis and no-pay day/rate used. Existing saved calculations and paid/finalized sheets are not rewritten. An older Daily draft shows a notice linking to **View → Refresh draft salary calculation / Refresh Attendance & OT**, with a required reason; it must be refreshed before finalization under this rule. Draft regeneration also applies the new calculation.

No migration or live data change is performed. No tests were run, as requested; frontend assets were rebuilt for the user's manual checking.

## Compact A4 salary slip

The salary slip uses a compact layout on screen and for printing, with A4 portrait paper and 12 mm margins on all sides. Attendance stays in six columns, earnings/deductions and payment references stay in two columns, and the contribution/payment cards remain side by side in print regardless of screen breakpoints. Section padding, heading spacing, the net-pay banner and signature area are reduced. Long adjustment/debt/reference content can continue to another page rather than being clipped; all financial details remain available. The EPF/ETF salary-basic explanatory line is omitted from this slip; contribution amounts and deductions remain visible.

This presentation change was made locally without tests or PDF/browser verification, as requested. It does not alter payroll amounts or live data.

## Balanced A4 spacing and footer signatures

The A4 slip now uses a more readable 12 px print body with 1.4 line spacing, larger attendance cells, clearer section gaps and a restored navy net-pay banner. The 12 mm paper margins remain. The main details and signatures are separate flex-column sections; a 272 mm minimum printable height and automatic space above the footer keep signatures at the bottom on a normal A4 slip. The signature area reserves 12 mm of signing space and stays in document flow, so additional details can continue without a fixed footer overlapping them. The 80 mm receipt layout does not use the A4 minimum height. No payroll values or EPF/ETF explanatory text were changed or restored. This update was authored locally without tests or PDF/browser verification.


## Full-day absence and half-day deductions

Absence / No Pay Leave now deducts the staff daily salary; Half Day, Morning Half Day and Evening Half Day deduct exactly 50% of that daily salary. The saved Daily Salary takes precedence, with the existing salary-type fallback when it is zero. A separate legacy No Pay / Day amount or fixed half-day setting cannot override this rule. Staff forms explain the rule instead of offering a conflicting absence rate, and salary settings retain automatic half-day classification cutoffs but show the fixed 50% deduction rule.

An explicit half-day status applies the deduction even when a fingerprint is missing. Automatic classification still uses the enabled half-day rules and the staff-adjusted cutoff times; Early Leave alone is not silently reclassified as a half day. Half-day deductions replace late/early deductions for that day. Paid leave and shop-closed dates remain exempt; hourly staff use actual earnings without a second absence/half-day deduction. Explicit manual monetary overrides remain supported.

Salary generation rebuilds half-day deductions from the recorded statuses and current daily rate, rather than blindly summing older zero amounts. Existing attendance and saved paysheets are not rewritten by this code change. After deployment, use Recalculate All Attendance for unlocked rows, then View → Refresh Attendance & OT (with a reason) on the draft paysheet to synchronize its attendance details and totals. Finalized/paid periods remain locked. For a daily salary of Rs. 1,200, absence deducts Rs. 1,200 and a half day deducts Rs. 600. No tests, browser checks or live-server changes were performed.
