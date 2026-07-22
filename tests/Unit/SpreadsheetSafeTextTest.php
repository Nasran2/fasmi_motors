<?php

use App\Support\SpreadsheetSafeText;

test('spreadsheet exports neutralize formula prefixes while preserving typed numbers', function () {
    expect(SpreadsheetSafeText::escape('=HYPERLINK("https://example.test")'))->toStartWith("'=")
        ->and(SpreadsheetSafeText::escape(' +SUM(1,2)'))->toStartWith("' +")
        ->and(SpreadsheetSafeText::escape('-10+20'))->toBe("'-10+20")
        ->and(SpreadsheetSafeText::escape('@payload'))->toBe("'@payload")
        ->and(SpreadsheetSafeText::escape(125.50))->toBe(125.50)
        ->and(SpreadsheetSafeText::escape('Normal text'))->toBe('Normal text');
});
