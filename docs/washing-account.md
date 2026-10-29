# Washing account and job commission

Accounts → Washing Account Ledger shows opening, receipts, expenses, transfers and closing balance, plus washing charges billed and their unpaid/held share. Reports → Washing Account Report lists the invoices containing washing charges, including unpaid bills, and separately shows posted account movements. Charge CSV/PDF exports use the invoice table; ledger PDF/Excel exports contain posted transactions. Opening balances and transfers support Washing Account. Existing Main Account viewing permissions also permit the Washing ledger.

Washing on a job is displayed separately from service/labor and other charges. Billed charges are not money received: an unpaid Rs. 200 washing charge appears in the report with Rs. 200 unpaid while the received balance remains zero. Settled payments collect Service, then Labor, then Washing, Other and Spare Parts. Washing remains unpaid until earlier charges are collected; held cheques settle on clearance. Cashier closing includes only washing cash in hand. The separate All Collections window includes both cash and bank washing receipts.

Regular job commission = (service + labor) × technician percentage. Washing, other charges, parts and tax are excluded. Parts commission keeps its separate configured percentage/profit basis. Legacy Total bill and Labor job modes now use this service + labor rule. Manual commission remains available as an explicit mode. The linked job form fills and protects the correct service/labor base.

Migration 2026_10_29_000002 corrects existing pending automatic job commissions with no finalized salary association, keeping previous values in the audit log. Paid commissions/finalized payroll and manual/parts commission entries are preserved. Subsequent job edits refresh pending service/labor commission using the percentage originally recorded. Salary drafts whose commission changed must be regenerated before finalization.

Validation on 29 October 2026: the full suite passes 768/772 tests (8,665 assertions), including all washing, cashier and salary-preview checks. The four previously identified stock/display failures remain. Build, PHP formatting, template syntax and browser checks pass. See `docs/cashier.md` for the popup and repeated-opening validation.

Washing-person commission is a fixed amount per job containing washing, initially Rs. 60. Cashier opening and individual jobs can override it, including zero to disable it. The checkbox at opening saves a new default. Billing creates one pending fixed Washing commission; changing the washing charge does not multiply or scale it. Paid/payroll-linked commissions remain protected and retain historical earnings.

Cashier closing asks for the physical closing balance and auto-fills editable Main and Spare Parts allocations; their sum must match the cash count. It automatically transfers washing cash, after expenses, into Main Account. Non-cash washing allocations and bank balances remain unchanged. The paired ledger transfer changes account allocations without creating another receipt or expense.

Latest validation: full suite 776/779 passed; the three existing inventory failures remain. All washing commission, migration, cashier and salary-preview tests pass.
