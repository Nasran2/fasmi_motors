<?php
$dir = __DIR__ . '/resources/views';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        
        // Find <select name="account_id" ...> loops and inject @selected($account->code === 'MAIN') or similar
        // Since the variable could be $account or $a, we use regex.
        $newContent = preg_replace_callback(
            '/<option\s+value="\{\{\s*\$([a-zA-Z0-9_]+)->id\s*\}\}"(?:\s*@selected\([^)]+\))?>\s*\{\{\s*\$\1->name\s*(?:—\s*Rs\.\s*\{\{\s*number_format\(\$\1->balance,\s*2\)\s*\}\})?\s*\}\}\s*<\/option>/s',
            function($matches) {
                $var = $matches[1];
                // Check if this option originally had a balance or not
                $hasBalance = strpos($matches[0], 'number_format') !== false;
                $balanceStr = $hasBalance ? " — Rs. {{ number_format(\$$var->balance, 2) }}" : "";
                
                return '<option value="{{ $' . $var . '->id }}" @selected($' . $var . '->code === \'MAIN\' || $' . $var . '->type === \'main\')>{{ $' . $var . '->name }}' . $balanceStr . '</option>';
            },
            $content
        );
        
        // Let's also restore the 'SPARE' one if we accidentally overwrote it, but wait, the prompt says "Alway smake the main account as defult ine" 
        // So making everything MAIN is correct.
        
        if ($content !== $newContent) {
            file_put_contents($file->getPathname(), $newContent);
            echo "Updated: " . $file->getPathname() . "\n";
        }
    }
}
