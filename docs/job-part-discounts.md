# Per-product job part discounts

Click a product name in the job card's Spare Parts table to open Edit Part Details. **Discount · LKR for this line** loads the saved discount and remains editable for stock and outer products. The total updates while typing. The amount is deducted once from quantity × unit price, rather than once per unit. Enter 0 to remove the discount.

The saved discount appears in the parts table and beneath the product on customer invoices, internal invoice details and 80 mm receipts. It updates the line total, profit, job parts total and linked unpaid invoice. Overall job discounts remain separate and apply after per-line discounts. Earlier API clients omitting discount retain the existing value.

The server validates non-negative two-decimal amounts and rejects discounts exceeding the line amount. Job locking, permissions and protection against reducing an invoice below settled receipts remain in place. Discount-only edits do not issue/return stock or change/repost an outer product's purchase expense. The existing discount column is reused; no additional migration is needed for this feature.

Local verification: 97 selected job part/discount/invoice/parts-sale/financial regression cases passed (1,130 assertions), plus three JavaScript checks for modal prefill, per-product reset and live totals/validation. No live server was accessed or changed.
