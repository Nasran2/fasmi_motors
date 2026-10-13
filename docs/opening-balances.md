# Opening balances

In this system, a customer-entered "previous balance", "old balance", or "old due" means the customer's opening balance. It is an unpaid amount brought forward without a known parts/service breakdown.

- Record it as a separate `Opening Due` invoice linked to the customer and, when available, their bike. Legacy `Opening Balance` invoices follow the same rules.
- Customer creation, bike intake, and the job's Add opening balance action must not recreate balances already recorded.
- Recording the balance does not record cash received. Collections belong entirely to the Main Account with category `opening_due`; do not split them into parts or service income.
- Non-cash collections also retain the existing bank tracking. Cheques remain on hold until clearing and restore the due on return or cancellation.
- Earlier unpaid job bills remain their original invoices and retain their parts/service allocation. A total containing both opening balances and earlier unpaid bills is called "Earlier unpaid balances", not "Opening balance".
- Customer totals and payment actions include opening balances even when the customer has no bike or job. Show Pay only when a positive collectible due exists and the user has collection permission.
