# Salary details, overtime and adjustments

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
