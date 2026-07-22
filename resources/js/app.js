window.spreadsheetSafeText = (value) => {
    const text = String(value ?? '');

    return /^\s*[=+\-@]/u.test(text) ? `'${text}` : text;
};

window.csvCell = (value) => `"${window.spreadsheetSafeText(value).replace(/"/g, '""')}"`;
