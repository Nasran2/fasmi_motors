# Salary details, overtime and adjustments

Overtime eligibility includes a check-in exactly at workshop opening time. With opening at 08:00, 07:59 and 08:00 qualify; 08:01 does not. Eligibility still requires an actual checkout beyond the configured closing grace period. Missing/automatic checkout does not earn automatic OT. Existing manual amount overrides remain available.

In Salary Payments, save a draft and choose **View** to see the complete salary period with daily check-in/out times, status, worked hours, overtime and attendance deductions. New calculations capture attendance times with the salary snapshot. Legacy sheets without this snapshot show current attendance records with an explanatory note; their saved salary amounts are unchanged.

Use **Add an earning or deduction** in View to enter multiple items, one at a time. A description is required; an additional note is optional. Descriptions and notes appear on the payslip. The generation form also accepts an initial described earning and named deductions with notes. Adjustments are available for drafts, finalized unpaid sheets and partial payments. Each is audited. They update salary totals without creating cash payments, changing paid amounts or reapplying advances, repair deductions or commissions. Deductions cannot exceed gross earnings or reduce net payable below salary already paid. Fully paid sheets do not accept these new adjustments.

**View / Print Payslip** is available before payment and after partial/full payment. It shows status, paid amount and balance. Drafts are explicitly marked as drafts, and an unpaid/partial slip does not imply full payment.

For earlier attendance saved under the previous strict-before-opening OT rule, choose **Refresh Attendance & OT** on a draft and provide a reason. It recalculates attendance and draft salary together, retains manual amount overrides and adjustment descriptions, refreshes the attendance snapshot and contribution totals, and never creates a payment. Finalized attendance remains locked; an unpaid finalized sheet can first be reopened through the existing action. Refreshing cannot change a salary period or update dates overlapping another finalized sheet. Finalization rejects attendance-time changes after a draft snapshot.

Verification was local using the isolated testing database: 167 payroll/attendance/repair/advance regression cases passed (2,893 assertions), plus three salary-preview JavaScript cases. No live server or live financial data was changed.
