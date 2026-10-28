# Print documents

Printing uses a separate document layout rather than the application screen. Tables, financial values and filters are retained; navigation, forms, editing controls, pagination and action columns are removed.

## Reports

All 25 report routes accept `print=1` and produce a standalone A4 document. `preview=1` suppresses automatic opening of the browser print dialog for preview and quality checks. The ordinary report page remains unchanged.

Print requests use all matching report records, preserving the existing date/filter rules and print permission checks. CSV remains independent of the print layout. Documents include the configured business name, shop, address/contact details, report title, period, operator and generation time, with compact summaries and applicable filters.

Columns determine portrait or landscape orientation. Headers repeat when tables cross pages. Rows stay together, and the original table footer is converted to a final table body so closing balances and totals occur only once at the end. Print margins and page numbers are explicit. The document footer is omitted from printed flow to prevent an otherwise blank final page.

## Other modules

`resources/js/printing.js` handles application print buttons and the browser's native print event. It creates a document using the same print stylesheet. List printing is explicitly labelled as the displayed page when pagination exists; use the module's complete export or Reports for all filtered records.

Row-level Receipt, Report and Statement actions print the selected record as a field/value document. Staff advance receipts therefore do not print unrelated employees or the list's editing form. Salary row print actions open the actual salary slip, and paysheet modal printing includes its staff, period and reference.

Purchase metadata and account/staff summaries use `data-print-summary`. Other static content can opt in with `data-print-content`. These annotations carry data into the document without copying application styling. Action columns are identified by their headers and removed.

Native account-ledger, daily-closing, finance and cheque documents use the shared document layout. PDF account/finance exports use `PrintDocument::pdf()` for consistent paper setup and page numbering. The product catalog's bounded canvas renderer retains its existing pagination and adds the configured business identity. A4/A5 invoices and 80 mm receipts retain their purpose-built document formats; salary slips retain their configured A4/80 mm size.

## Implementation

- `app/Support/PrintDocument.php`: document identity, safe table extraction, summaries and PDF rendering.
- `resources/views/printing/`: standalone document templates.
- `resources/css/print-document.css`: paper layout shared by browser and server documents.
- `resources/js/printing.js`: application-wide document composer, row-level documents and native browser printing.

Print content is reconstructed with an allowlist of text-formatting elements. Scripts, forms, input fields, event handlers, image URLs and action buttons do not enter generated table documents.

## Verification

`tests/Feature/PrintLayoutTest.php` verifies standalone output for every report, table sanitization, applied filters and summaries, all 75 entries in a long cash book, single final closing totals, and actual PDF paper margins. Existing report, cash-book, account, invoice-format, cheque and product-PDF tests cover their original behavior.

Visual QA renders a long cash book to PDF and inspects every page, checks row continuity and page numbers, and confirms that every entry appears once and the closing label occurs once. Browser QA exercises a selected advance receipt with two source records, confirming that only the chosen record and its values appear in the print document.

Final validation: 118 print/report/account/invoice/cheque/product-PDF tests passed (1,008 assertions); targeted Pint/PHPStan and the production frontend build passed. The full application run recorded 661 passing tests with the same seven previously verified failures in unrelated workflows. The final long-document review confirmed 75 unique cash entries, one closing label, no missing glyphs, intact rows and printable margins. A landscape sales fixture verified long customer names, references and large monetary amounts.
