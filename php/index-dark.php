<?php
// Configuration
$ledgerCliPath = __DIR__ . '/ledger-cli.php';
$defaultFile = 'Journal.txt';

// DEFINIR FUSO HORÁRIO CORRETO - Adicione isto no início
date_default_timezone_set('America/Sao_Paulo'); // Ajuste para seu fuso horário

// Function to detect mobile device
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // List of mobile device patterns
    $mobilePatterns = [
        '/android/i',
        '/webos/i',
        '/iphone/i',
        '/ipod/i',
        '/ipad/i',
        '/blackberry/i',
        '/windows phone/i',
        '/opera mini/i',
        '/iemobile/i',
        '/mobile/i',
        '/kindle/i',
        '/silk/i',
        '/tablet/i'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    return false;
}

// Check if it's an AJAX request
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    // Process AJAX commands
    $currentFile = $_POST['ledger_file'] ?? $defaultFile;
    
    if (isset($_POST['ajax_command'])) {
        $command = trim($_POST['ajax_command']);
        $lastCommand = $command;
        
        if (!empty($command)) {
            $commandOutput = executeLedgerCommand($command, $currentFile, $ledgerCliPath);
            
            echo json_encode([
                'success' => true,
                'command' => $command,
                'output' => $commandOutput,
                'mobile' => isMobileDevice(),
                'file' => $currentFile
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Empty command']);
        }
        exit;
    }
    
    if (isset($_POST['ajax_change_file'])) {
        $newFile = $_POST['ajax_file'] ?? $defaultFile;
        $currentFile = $newFile;
        
        echo json_encode([
            'success' => true,
            'file' => $currentFile,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    if (isset($_POST['ajax_add_transaction'])) {
        $currentFile = $_POST['ajax_file'] ?? $defaultFile;
        $transactionResult = addTransaction($currentFile);
        
        echo json_encode([
            'success' => strpos($transactionResult, 'Error') === false,
            'message' => $transactionResult,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    if (isset($_POST['ajax_delete_last'])) {
        $currentFile = $_POST['ajax_file'] ?? $defaultFile;
        $deleteResult = deleteLastTransaction($currentFile);
        
        echo json_encode([
            'success' => strpos($deleteResult, 'Error') === false,
            'message' => $deleteResult,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    exit;
}

// Process file change (non-AJAX)
$currentFile = $defaultFile;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_file'])) {
    $currentFile = $_POST['ledger_file'] ?? $defaultFile;
}

// Process command (non-AJAX)
$commandOutput = '';
$lastCommand = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['command'])) {
    $command = trim($_POST['command']);
    $lastCommand = $command;
    
    if (!empty($command)) {
        // Execute command
        $commandOutput = executeLedgerCommand($command, $currentFile, $ledgerCliPath);
    }
}

// Process new transaction (non-AJAX)
$transactionResult = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_transaction'])) {
    $transactionResult = addTransaction($currentFile);
}

// Process last transaction deletion (non-AJAX)
$deleteResult = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_last'])) {
    $deleteResult = deleteLastTransaction($currentFile);
}

// List available files
$availableFiles = [];
$txtFiles = glob("*.txt");
$ledgerFiles = glob("*.ledger");
$availableFiles = array_merge($txtFiles, $ledgerFiles);

// Load existing accounts
$existingAccounts = getExistingAccounts($currentFile);

function executeLedgerCommand($command, $ledgerFile, $ledgerCliPath) {
    if (!file_exists($ledgerFile)) {
        return "Error: File '$ledgerFile' not found on server.";
    }
    
    if (!file_exists($ledgerCliPath)) {
        return "Error: ledger-cli.php not found at: $ledgerCliPath";
    }
    
    // Check if it's a mobile device
    $isMobile = isMobileDevice();
    
    // Prepare base command
    $fullCommand = "php " . escapeshellarg($ledgerCliPath) . 
                  " -f " . escapeshellarg($ledgerFile);
    
    // CORRECTION HERE: Add --columns=58 for mobile if --columns is not in command
    if ($isMobile && strpos($command, '--columns') === false) {
        $fullCommand .= " --columns=58";
    }
    
    // Add user command
    $fullCommand .= " " . $command . " 2>&1";
    
    // Execute command
    $output = [];
    $returnCode = 0;
    exec($fullCommand, $output, $returnCode);
    
    return implode("\n", $output);
}

function getExistingAccounts($ledgerFile) {
    if (!file_exists($ledgerFile)) {
        return [];
    }
    
    $content = file_get_contents($ledgerFile);
    if ($content === false) {
        return [];
    }
    
    $accounts = [];
    
    // Extract accounts from ledger
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        // Look for account lines (start with 4 spaces or tab)
        if (preg_match('/^\s{4,}|\t/', $line)) {
            // Remove leading spaces and comments
            $line = trim($line);
            $line = preg_replace('/\s+;.*$/', '', $line); // Remove comments
            
            // Split by 2 or more spaces to get account
            $parts = preg_split('/\s{2,}/', $line);
            if (!empty($parts[0])) {
                $account = trim($parts[0]);
                if (!empty($account) && !in_array($account, $accounts)) {
                    $accounts[] = $account;
                }
            }
        }
    }
    
    // Sort and remove duplicates
    sort($accounts);
    return $accounts;
}

function addTransaction($ledgerFile) {
    if (!file_exists($ledgerFile)) {
        return "Error: File '$ledgerFile' not found.";
    }
    
    // Get date from POST or use current date
    if (isset($_POST['transaction_date']) && !empty($_POST['transaction_date'])) {
        $dateInput = $_POST['transaction_date'];
        $date = str_replace('-', '/', $dateInput); // Convert YYYY-MM-DD to YYYY/MM/DD
    } else {
        $date = date('Y/m/d');
    }
    
    $payee = trim($_POST['transaction_payee'] ?? '');
    $comment = trim($_POST['transaction_comment'] ?? '');
    $entries = $_POST['account_entries'] ?? [];
    
    if (empty($payee)) {
        return "Error: Payee is required.";
    }
    
    if (count($entries) < 2) {
        return "Error: At least 2 entries (accounts) are required.";
    }
    
    // Check if transaction is balanced
    $total = 0;
    foreach ($entries as $entry) {
        $amount = floatval($entry['amount'] ?? 0);
        $total += $amount;
    }
    
    if (abs($total) > 0.01) {
        return "Error: Transaction not balanced. Difference: " . number_format($total, 2);
    }
    
    // Build transaction with proper formatting
    $transaction = "";
    
    // Add comment if exists
    if (!empty($comment)) {
        $transaction .= "; " . $comment . "\n";
    }
    
    // Add date and payee
    $transaction .= $date . " " . $payee . "\n";
    
    // SIMPLE FIX: Just use 79 columns and align values to the right
    foreach ($entries as $entry) {
        $account = trim($entry['account'] ?? '');
        $amount = $entry['amount'] ?? '';
        
        if (!empty($account) && !empty($amount)) {
            $value = floatval($amount);
            $amountFormatted = number_format(abs($value), 2);
            $sign = ($value >= 0) ? ' ' : '-';
            $valueStr = $sign . $amountFormatted;
            
            // Start with 4 spaces
            $line = "    " . $account;
            
            // Pad to 79 columns
            $currentLength = strlen($line);
            $valueLength = strlen($valueStr);
            $spacesNeeded = 79 - $currentLength - $valueLength;
            
            // Add spaces and value
            if ($spacesNeeded > 0) {
                $line .= str_repeat(' ', $spacesNeeded) . $valueStr;
            } else {
                // Account name too long, put value on next line
                $line = "    " . $account . "\n        " . $valueStr;
            }
            
            $transaction .= $line . "\n";
        }
    }
    
    // Add TWO blank lines after transaction
    $transaction .= "\n\n";
    
    // Read existing content
    $content = file_get_contents($ledgerFile);
    
    // Ensure content ends with at least ONE newline
    $content = rtrim($content);
    
    // If content is not empty, ensure it ends with TWO newlines
    if (!empty($content)) {
        // Check how many newlines are at the end
        $newlineCount = 0;
        $i = strlen($content) - 1;
        while ($i >= 0 && $content[$i] === "\n") {
            $newlineCount++;
            $i--;
        }
        
        // We need at least 2 newlines at the end
        if ($newlineCount === 0) {
            $content .= "\n\n";
        } elseif ($newlineCount === 1) {
            $content .= "\n";
        }
    } else {
        $content = "";
    }
    
    // Append new transaction to the content
    $content .= $transaction;
    
    // Save the complete content
    file_put_contents($ledgerFile, $content, LOCK_EX);
    
    return "Transaction added successfully!";
}

function deleteLastTransaction($ledgerFile) {
    if (!file_exists($ledgerFile)) {
        return "Error: File '$ledgerFile' not found.";
    }
    
    $content = file_get_contents($ledgerFile);
    if (empty($content)) {
        return "File is empty.";
    }
    
    // Remove trailing whitespace
    $content = rtrim($content);
    
    // Split content by lines
    $lines = explode("\n", $content);
    $totalLines = count($lines);
    
    // Find start of last transaction
    $lastTransactionStart = -1;
    
    // Search from bottom to top
    for ($i = $totalLines - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        
        // Check if it's a date line (YYYY/MM/DD at beginning)
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}/', $line)) {
            $lastTransactionStart = $i;
            
            // Check for comments before
            for ($j = $i - 1; $j >= 0; $j--) {
                $prevLine = trim($lines[$j]);
                if (strpos($prevLine, ';') === 0) {
                    // It's a comment
                    $lastTransactionStart = $j;
                } else if ($prevLine === '') {
                    // Empty line, stop
                    break;
                } else {
                    // Other content, stop
                    break;
                }
            }
            break;
        }
    }
    
    if ($lastTransactionStart === -1) {
        return "No transaction found.";
    }
    
    // Find end of last transaction
    $lastTransactionEnd = $totalLines;
    
    // Search from transaction start
    for ($i = $lastTransactionStart; $i < $totalLines; $i++) {
        $line = trim($lines[$i]);
        
        // If found empty line
        if ($line === '') {
            // Check if next lines are empty or if next non-empty is a date
            $foundNextTransaction = false;
            
            // Check next lines
            for ($j = $i + 1; $j < $totalLines; $j++) {
                $nextLine = trim($lines[$j]);
                if ($nextLine !== '') {
                    if (preg_match('/^\d{4}\/\d{2}\/\d{2}/', $nextLine)) {
                        // Found next transaction
                        $foundNextTransaction = true;
                        $lastTransactionEnd = $i + 1; // Include empty line
                    }
                    break;
                }
            }
            
            if ($foundNextTransaction) {
                break;
            }
        }
    }
    
    // If didn't find next transaction, go to end
    if ($lastTransactionEnd === $totalLines) {
        // Check for trailing empty lines to include
        for ($i = $lastTransactionStart; $i < $totalLines; $i++) {
            if (trim($lines[$i]) === '' && $i > $lastTransactionStart) {
                // Found empty line after transaction start
                $lastTransactionEnd = $i + 1;
                break;
            }
        }
    }
    
    // Remove transaction
    $newLines = [];
    for ($i = 0; $i < $totalLines; $i++) {
        if ($i < $lastTransactionStart || $i >= $lastTransactionEnd) {
            $newLines[] = $lines[$i];
        }
    }
    
    // Rebuild content
    $newContent = implode("\n", $newLines);
    
    // Remove excessive trailing empty lines
    $newContent = rtrim($newContent);
    if (!empty($newContent)) {
        $newContent .= "\n";
    }
    
    // Save back
    if (file_put_contents($ledgerFile, $newContent, LOCK_EX) !== false) {
        return "Last transaction removed successfully!";
    } else {
        return "Error saving file.";
    }
}

// Function to format output preserving spaces
function formatOutput($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Function to display text
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ledger CLI Terminal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #000;
            font-family: 'Courier New', monospace;
            height: 100vh;
            overflow: hidden;
        }
        
        .container {
            display: flex;
            height: 100vh;
            gap: 2px;
            background: #333;
        }
        
        /* Terminal (60%) */
        .terminal-section {
            flex: 0 0 60%;
            background: #000;
            display: flex;
            flex-direction: column;
        }
        
        /* Transaction editor (40%) */
        .transaction-section {
            flex: 0 0 40%;
            background: #111;
            display: flex;
            flex-direction: column;
            color: #ccc;
        }
        
        /* Terminal styles */
        .terminal {
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .terminal-header {
            background: #1a1a1a;
            color: #ccc;
            padding: 10px 15px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .file-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        select {
            background: #333;
            color: #0f0;
            border: 1px solid #555;
            padding: 5px 10px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            min-width: 150px;
        }
        
        .btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .current-file {
            color: #0f0;
            font-size: 12px;
        }
        
        .terminal-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            color: #0f0;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .terminal-input {
            background: #111;
            padding: 10px 15px;
            border-top: 1px solid #333;
            display: flex;
            align-items: center;
        }
        
        .prompt {
            color: #0f0;
            margin-right: 10px;
            font-weight: bold;
        }
        
        input[type="text"] {
            background: transparent;
            border: none;
            color: #0f0;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            outline: none;
            padding: 5px;
        }
        
        .command-output {
            margin-bottom: 15px;
        }
        
        .command-line {
            color: #0f0;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        /* IMPORTANTE: ESTILO PARA SAÍDA DO TERMINAL COM TAMANHO FIXO E QUEBRA DE LINHA */
        .command-result {
            color: #0f0;
            white-space: pre-wrap; /* Quebra de linha automática */
            word-wrap: break-word; /* Quebra palavras longas */
            word-break: break-all; /* Quebra tudo se necessário */
            overflow-wrap: break-word; /* Suporte moderno para quebra */
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.4;
            background: rgba(0, 20, 0, 0.1);
            padding: 5px;
            border-radius: 3px;
            border: 1px solid rgba(0, 80, 0, 0.3);
            max-width: 100%;
            display: block;
        }
        
        .error {
            color: #ff5555;
        }
        
        .success {
            color: #55ff55;
        }
        
        /* Transaction section styles */
        .transaction-header {
            background: #222;
            color: #ccc;
            padding: 10px;
            border-bottom: 1px solid #333;
        }
        
        .transaction-header h3 {
            color: #0f0;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .transaction-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
        }
        
        .transaction-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .form-group label {
            color: #0f0;
            font-size: 12px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group textarea {
            background: #222;
            border: 1px solid #444;
            color: #ccc;
            padding: 8px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
        }
        
        .form-group input[type="text"]:focus,
        .form-group input[type="date"]:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0f0;
        }
        
        .entries-container {
            background: #222;
            border: 1px solid #444;
            border-radius: 3px;
            padding: 10px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .entry-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        
        .entry-row input[type="text"] {
            flex: 1;
            background: #333;
            border: 1px solid #555;
            color: #ccc;
            padding: 5px;
        }
        
        .entry-row input[type="number"] {
            width: 100px;
            background: #333;
            border: 1px solid #555;
            color: #ccc;
            padding: 5px;
        }
        
        .remove-entry {
            background: #dc3545;
            color: white;
            border: none;
            width: 25px;
            height: 25px;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .add-entry {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            align-self: flex-start;
        }
        
        .transaction-footer {
            background: #222;
            padding: 15px;
            border-top: 1px solid #333;
            display: flex;
            gap: 10px;
        }
        
        .transaction-footer .btn {
            flex: 1;
        }
        
        .autocomplete-container {
            position: relative;
        }
        
        .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #222;
            border: 1px solid #444;
            border-top: none;
            max-height: 150px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .autocomplete-item {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid #333;
        }
        
        .autocomplete-item:hover {
            background: #333;
            color: #0f0;
        }
        
        /* Scrollbars */
        .terminal-body::-webkit-scrollbar,
        .transaction-body::-webkit-scrollbar,
        .entries-container::-webkit-scrollbar,
        .autocomplete-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .terminal-body::-webkit-scrollbar-track,
        .transaction-body::-webkit-scrollbar-track,
        .entries-container::-webkit-scrollbar-track,
        .autocomplete-list::-webkit-scrollbar-track {
            background: #1a1a1a;
        }
        
        .terminal-body::-webkit-scrollbar-thumb,
        .transaction-body::-webkit-scrollbar-thumb,
        .entries-container::-webkit-scrollbar-thumb,
        .autocomplete-list::-webkit-scrollbar-thumb {
            background: #444;
            border-radius: 4px;
        }
        
        .terminal-body::-webkit-scrollbar-thumb:hover,
        .transaction-body::-webkit-scrollbar-thumb:hover,
        .entries-container::-webkit-scrollbar-thumb:hover,
        .autocomplete-list::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .result-message {
            padding: 10px;
            border-radius: 3px;
            margin-top: 10px;
            font-size: 12px;
        }
        
        .result-success {
            background: rgba(40, 167, 69, 0.2);
            color: #55ff55;
            border: 1px solid #28a745;
        }
        
        .result-error {
            background: rgba(220, 53, 69, 0.2);
            color: #ff5555;
            border: 1px solid #dc3545;
        }
        
        /* Responsiveness */
        @media (max-width: 1200px) {
            .container {
                flex-direction: column;
            }
            
            .terminal-section,
            .transaction-section {
                flex: 1;
                min-height: 50vh;
            }
        }
        
        .welcome-message {
            color: #888;
            margin-bottom: 15px;
            font-style: italic;
        }
        
        /* Mobile indicator (optional, for debug) */
        .mobile-indicator {
            position: fixed;
            top: 5px;
            right: 5px;
            background: rgba(0, 100, 0, 0.7);
            color: #0f0;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 10px;
            z-index: 9999;
            display: none; /* Keep as 'none' for production */
        }

        @media (max-width: 600px) {
            .terminal-body {
                font-size: 9px;
            }
            
            .terminal-input input[type="text"] {
                font-size: 11px;
            }
            
            .command-line, .command-result {
                font-size: 9px;
            }
            
            .prompt {
                font-size: 9px;
            }
            
            .autocomplete-list {
                width: 95vw !important;
                left: 2.5vw !important;
                right: 2.5vw !important;
                max-height: 300px !important;
            }
            
            .autocomplete-item {
                padding: 15px !important;
                font-size: 16px !important;
            }
        }
        
        /* Styles specific for mobile devices */
        @media (max-width: 768px) {
            .terminal-body {
                font-size: 9px;
            }
            
            .terminal-input input[type="text"] {
                font-size: 11px;
            }
            
            .command-line, .command-result {
                font-size: 9px;
            }
            
            .prompt {
                font-size: 9px;
            }
        }
        
        /* Permitir rolagem em dispositivos móveis */
        @media (max-width: 768px) {
            body {
                overflow: auto !important;
                height: auto !important;
                min-height: 100vh !important;
            }
            
            .container {
                height: auto !important;
                min-height: 100vh !important;
                overflow: visible !important;
            }
            
            .terminal-section,
            .transaction-section {
                height: auto !important;
                min-height: 50vh !important;
                overflow: visible !important;
            }
            
            .terminal {
                height: auto !important;
                min-height: 50vh !important;
            }
        }
        
        /* Container com largura fixa para o terminal */
        .terminal-content {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        /* Para linhas muito longas com caracteres contínuos */
        .break-long-lines {
            overflow-wrap: anywhere !important;
            word-break: break-all !important;
        }
    </style>
</head>
<body>
    <!-- Mobile indicator (optional, for debug) -->
    <div class="mobile-indicator" id="mobileIndicator">
        <?php echo isMobileDevice() ? 'MOBILE' : 'DESKTOP'; ?>
    </div>
    
    <div class="container">
        <!-- Terminal Section (60%) -->
        <div class="terminal-section">
            <div class="terminal">
                <div class="terminal-header">
                    <div class="file-selector">
                        <select name="ledger_file" id="fileSelector">
                            <option value="">-- Select file --</option>
                            <?php foreach ($availableFiles as $file): ?>
                                <option value="<?php echo e($file); ?>" <?php echo ($currentFile === $file) ? 'selected' : ''; ?>>
                                    <?php echo e($file); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="current-file" id="currentFileDisplay"><?php echo e($currentFile); ?></span>
                    </div>
                </div>
                
                <div class="terminal-body" id="terminalOutput">
                    <?php if (!empty($commandOutput) && !empty($lastCommand)): ?>
                        <div class="command-output">
                            <div class="command-line">$ ledger-cli -f <?php echo e($currentFile); ?> 
                                <?php if (isMobileDevice() && strpos($lastCommand, '--columns') === false): ?>
                                    --columns=58 
                                <?php endif; ?>
                                <?php echo e($lastCommand); ?>
                            </div>
                            <div class="command-result"><?php echo formatOutput($commandOutput); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (empty($commandOutput)): ?>
                        <div class="welcome-message">
                            Ledger CLI Terminal<br>
                            Current file: <?php echo e($currentFile); ?><br>
                            <?php if (isMobileDevice()): ?>
                                <span style="color: #0a0;">Mobile mode active (--columns=58, smaller font)</span><br>
                            <?php endif; ?>
                            Type '--help' to see available commands
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="terminal-input">
                    <div style="display: flex; width: 100%; align-items: center;">
                        <span class="prompt">$</span>
                        <input type="text" id="commandInput" 
                               placeholder="Type a command (ex: bal, print, stats, accounts...)" 
                               autocomplete="off" 
                               autofocus
                               value="<?php echo isset($_POST['command']) ? e($_POST['command']) : ''; ?>">
                        <button type="button" id="executeButton" class="btn" style="margin-left: 10px;">Execute</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Transactions Section (40%) -->
        <div class="transaction-section">
            <div class="transaction-header">
                <h3>Add New Transaction</h3>
            </div>
            
            <div class="transaction-body">
                <form class="transaction-form" id="transactionForm">
                    <div class="form-group">
                        <label for="transaction_date">Date:</label>
                        <input type="date" id="transaction_date" 
                               value="<?php echo date('Y-m-d'); ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="transaction_payee">Payee/Description:</label>
                        <input type="text" id="transaction_payee" 
                               placeholder="Who/where was the transaction" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="transaction_comment">Comment (optional):</label>
                        <textarea id="transaction_comment" 
                                  rows="2" 
                                  placeholder="Transaction comment"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Entries (Accounts and Values):</label>
                        <div class="entries-container" id="entriesContainer">
                            <?php for ($i = 0; $i < 3; $i++): ?>
                                <div class="entry-row">
                                    <div class="autocomplete-container" style="flex: 1;">
                                        <input type="text" 
                                               class="account-input" 
                                               placeholder="Account name"
                                               data-index="<?php echo $i; ?>"
                                               autocomplete="off">
                                        <div class="autocomplete-list" id="autocomplete-<?php echo $i; ?>"></div>
                                    </div>
                                    <input type="number" 
                                           class="amount-input"
                                           step="0.01" 
                                           placeholder="Value">
                                    <?php if ($i >= 2): ?>
                                        <button type="button" class="remove-entry" onclick="removeEntry(this)">&times;</button>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <button type="button" class="add-entry" onclick="addEntry()">+ Add Entry</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Total: <span id="totalAmount">0.00</span></label>
                        <div id="balanceStatus" style="font-size: 11px; margin-top: 5px;"></div>
                    </div>
                </form>
            </div>
            
            <div class="transaction-footer">
                <button type="button" id="addTransactionButton" class="btn">Add Transaction</button>
                <button type="button" id="deleteLastButton" class="btn btn-danger">
                    Delete Last Transaction
                </button>
            </div>
        </div>
    </div>

    <script>
        // Existing accounts for autocomplete
        let existingAccounts = <?php echo json_encode($existingAccounts); ?>;
        let currentFile = "<?php echo e($currentFile); ?>";
        let isMobile = <?php echo isMobileDevice() ? 'true' : 'false'; ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus on command field
            const commandInput = document.getElementById('commandInput');
            if (commandInput) {
                commandInput.focus();
                commandInput.setSelectionRange(commandInput.value.length, commandInput.value.length);
            }
            
            // Automatic terminal scrolling
            const terminalOutput = document.getElementById('terminalOutput');
            if (terminalOutput) {
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            }
            
            // Set up autocomplete for account fields
            setupAutocomplete();
            
            // Calculate initial total
            calculateTotal();
            
            // Set up events
            document.getElementById('executeButton').addEventListener('click', executeCommand);
            document.getElementById('commandInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') executeCommand();
            });
            
            document.getElementById('fileSelector').addEventListener('change', changeFile);
            document.getElementById('addTransactionButton').addEventListener('click', addTransaction);
            document.getElementById('deleteLastButton').addEventListener('click', deleteLastTransaction);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+L to clear terminal (visually only)
                if (e.ctrlKey && e.key === 'l') {
                    e.preventDefault();
                    if (terminalOutput) {
                        terminalOutput.innerHTML = '<div class="welcome-message">Terminal cleared</div>';
                        terminalOutput.scrollTop = 0;
                    }
                }
            });
            
            // Show mobile indicator for 3 seconds (for debug)
            setTimeout(() => {
                document.getElementById('mobileIndicator').style.opacity = '0';
                setTimeout(() => {
                    document.getElementById('mobileIndicator').style.display = 'none';
                }, 1000);
            }, 3000);
        });
        
        function executeCommand() {
            const commandInput = document.getElementById('commandInput');
            const command = commandInput.value.trim();
            
            if (!command) return;
            
            // Show loading
            const terminalOutput = document.getElementById('terminalOutput');
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'command-output';
            
            // Build command line for display
            let displayCommand = '$ ledger-cli -f ' + currentFile;
            // Add --columns=58 in display if mobile and command doesn't have --columns
            if (isMobile && command.indexOf('--columns') === -1) {
                displayCommand += ' --columns=58';
            }
            displayCommand += ' ' + command;
            
            loadingDiv.innerHTML = '<div class="command-line">' + displayCommand + '</div><div class="command-result">Executing...</div>';
            terminalOutput.appendChild(loadingDiv);
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
            
            // Send command via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                // Remove loading
                loadingDiv.remove();
                
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            // Add result to terminal
                            const outputDiv = document.createElement('div');
                            outputDiv.className = 'command-output';
                            
                            const commandLine = document.createElement('div');
                            commandLine.className = 'command-line';
                            // Build command line for display
                            let displayCommandLine = '$ ledger-cli -f ' + response.file;
                            if (response.mobile && response.command.indexOf('--columns') === -1) {
                                displayCommandLine += ' --columns=58';
                            }
                            displayCommandLine += ' ' + response.command;
                            commandLine.textContent = displayCommandLine;
                            
                            const resultDiv = document.createElement('div');
                            resultDiv.className = 'command-result';
                            resultDiv.textContent = response.output;
                            
                            // Add class for breaking long lines
                            resultDiv.classList.add('break-long-lines');
                            
                            outputDiv.appendChild(commandLine);
                            outputDiv.appendChild(resultDiv);
                            
                            terminalOutput.appendChild(outputDiv);
                            
                            // Clear input field
                            commandInput.value = '';
                        } else {
                            showError(response.error);
                        }
                    } catch (e) {
                        showError('Error processing response');
                    }
                } else {
                    showError('Error communicating with server');
                }
                
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            };
            
            xhr.onerror = function() {
                loadingDiv.remove();
                showError('Connection error');
            };
            
            const params = new URLSearchParams();
            params.append('ajax_command', command);
            params.append('ledger_file', currentFile);
            
            xhr.send(params.toString());
        }
        
        function changeFile() {
            const fileSelector = document.getElementById('fileSelector');
            const newFile = fileSelector.value;
            
            if (!newFile) return;
            
            // Send via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            // Update current file
                            currentFile = response.file;
                            document.getElementById('currentFileDisplay').textContent = currentFile;
                            
                            // Update accounts for autocomplete
                            existingAccounts = response.accounts;
                            setupAutocomplete();
                            
                            // Update welcome message
                            const terminalOutput = document.getElementById('terminalOutput');
                            terminalOutput.innerHTML = '<div class="welcome-message">' +
                                'Ledger CLI Terminal<br>' +
                                'Current file: ' + currentFile + '<br>' +
                                'File changed successfully<br>' +
                                'Type \'--help\' to see available commands</div>';
                        } else {
                            showError('Error changing file');
                            fileSelector.value = currentFile;
                        }
                    } catch (e) {
                        showError('Error processing response');
                    }
                }
            };
            
            const params = new URLSearchParams();
            params.append('ajax_change_file', '1');
            params.append('ajax_file', newFile);
            
            xhr.send(params.toString());
        }
        
        function addTransaction() {
            // Collect form data
            const dateInput = document.getElementById('transaction_date').value;
            const payee = document.getElementById('transaction_payee').value.trim();
            const comment = document.getElementById('transaction_comment').value.trim();
            
            // Collect entries
            const entries = [];
            const accountInputs = document.querySelectorAll('.account-input');
            const amountInputs = document.querySelectorAll('.amount-input');
            
            for (let i = 0; i < accountInputs.length; i++) {
                const account = accountInputs[i].value.trim();
                const amount = amountInputs[i].value;
                
                if (account && amount) {
                    entries.push({
                        account: account,
                        amount: amount
                    });
                }
            }
            
            // Validations
            if (!payee) {
                showTransactionMessage('Payee is required.', 'error');
                return;
            }
            
            if (entries.length < 2) {
                showTransactionMessage('At least 2 entries are required.', 'error');
                return;
            }
            
            // Calculate total
            let total = 0;
            entries.forEach(entry => {
                total += parseFloat(entry.amount) || 0;
            });
            
            if (Math.abs(total) > 0.01) {
                if (!confirm('Transaction is not balanced (total: ' + total.toFixed(2) + '). Continue anyway?')) {
                    return;
                }
            }
            
            // CORREÇÃO: Enviar data no formato correto (YYYY-MM-DD)
            // O PHP vai converter para YYYY/MM/DD
            const formattedDate = dateInput; // Manter formato YYYY-MM-DD
            
            // Send via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        // Show message in terminal
                        showTransactionMessageInTerminal(response.message, response.success ? 'success' : 'error');
                        
                        if (response.success) {
                            // Clear form
                            document.getElementById('transaction_payee').value = '';
                            document.getElementById('transaction_comment').value = '';
                            
                            const accountInputs = document.querySelectorAll('.account-input');
                            const amountInputs = document.querySelectorAll('.amount-input');
                            
                            accountInputs.forEach(input => input.value = '');
                            amountInputs.forEach(input => input.value = '');
                            
                            // Update accounts for autocomplete
                            existingAccounts = response.accounts;
                            setupAutocomplete();
                            
                            calculateTotal();
                        }
                    } catch (e) {
                        showTransactionMessageInTerminal('Error processing response', 'error');
                    }
                } else {
                    showTransactionMessageInTerminal('Error communicating with server', 'error');
                }
            };
            
            const formData = new FormData();
            formData.append('ajax_add_transaction', '1');
            formData.append('ajax_file', currentFile);
            formData.append('transaction_date', formattedDate);
            formData.append('transaction_payee', payee);
            formData.append('transaction_comment', comment);
            
            entries.forEach((entry, index) => {
                formData.append('account_entries[' + index + '][account]', entry.account);
                formData.append('account_entries[' + index + '][amount]', entry.amount);
            });
            
            // Convert FormData to URLSearchParams
            const params = new URLSearchParams();
            for (const [key, value] of formData) {
                params.append(key, value);
            }
            
            xhr.send(params.toString());
        }
        
        function deleteLastTransaction() {
            if (!confirm('Are you sure you want to delete the last transaction?')) {
                return;
            }
            
            // Send via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        // Show message in terminal
                        showTransactionMessageInTerminal(response.message, response.success ? 'success' : 'error');
                        
                        if (response.success) {
                            // Update accounts for autocomplete
                            existingAccounts = response.accounts;
                            setupAutocomplete();
                        }
                    } catch (e) {
                        showTransactionMessageInTerminal('Error processing response', 'error');
                    }
                } else {
                    showTransactionMessageInTerminal('Error communicating with server', 'error');
                }
            };
            
            const params = new URLSearchParams();
            params.append('ajax_delete_last', '1');
            params.append('ajax_file', currentFile);
            
            xhr.send(params.toString());
        }
        
        function showTransactionMessageInTerminal(message, type) {
            const terminalOutput = document.getElementById('terminalOutput');
            const messageDiv = document.createElement('div');
            messageDiv.className = 'command-output ' + (type === 'success' ? 'success' : 'error');
            
            const resultDiv = document.createElement('div');
            resultDiv.className = 'command-result';
            resultDiv.textContent = message;
            
            messageDiv.appendChild(resultDiv);
            terminalOutput.appendChild(messageDiv);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.parentNode.removeChild(messageDiv);
                }
            }, 5000);
            
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        }
        
        function showError(message) {
            const terminalOutput = document.getElementById('terminalOutput');
            const errorDiv = document.createElement('div');
            errorDiv.className = 'command-output error';
            errorDiv.textContent = 'Error: ' + message;
            terminalOutput.appendChild(errorDiv);
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        }
        
        function setupAutocomplete() {
            const accountInputs = document.querySelectorAll('.account-input');
            
            accountInputs.forEach(input => {
                input.addEventListener('input', function() {
                    const value = this.value.toLowerCase();
                    const autocompleteList = document.getElementById('autocomplete-' + this.dataset.index);
                    
                    if (value.length < 2) {
                        autocompleteList.style.display = 'none';
                        return;
                    }
                    
                    // Filter accounts that match input
                    const matches = existingAccounts.filter(account => 
                        account.toLowerCase().includes(value)
                    );
                    
                    // Display results
                    if (matches.length > 0) {
                        autocompleteList.innerHTML = matches.slice(0, 10).map(account => 
                            '<div class="autocomplete-item" onclick="selectAccount(this, \'' + account.replace(/'/g, "\\'") + '\')">' + account + '</div>'
                        ).join('');
                        autocompleteList.style.display = 'block';
                    } else {
                        autocompleteList.style.display = 'none';
                    }
                });
                
                // Hide autocomplete when clicking outside
                document.addEventListener('click', function(e) {
                    if (!e.target.classList.contains('account-input')) {
                        document.querySelectorAll('.autocomplete-list').forEach(list => {
                            list.style.display = 'none';
                        });
                    }
                });
                
                // Allow keyboard navigation
                input.addEventListener('keydown', function(e) {
                    const autocompleteList = document.getElementById('autocomplete-' + this.dataset.index);
                    const items = autocompleteList.querySelectorAll('.autocomplete-item');
                    
                    if (items.length === 0) return;
                    
                    let activeIndex = Array.from(items).findIndex(item => 
                        item.classList.contains('active')
                    );
                    
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (activeIndex < items.length - 1) {
                            if (activeIndex >= 0) {
                                items[activeIndex].classList.remove('active');
                            }
                            items[activeIndex + 1].classList.add('active');
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (activeIndex > 0) {
                            if (activeIndex >= 0) {
                                items[activeIndex].classList.remove('active');
                            }
                            items[activeIndex - 1].classList.add('active');
                        }
                    } else if (e.key === 'Enter' && activeIndex >= 0) {
                        e.preventDefault();
                        selectAccount(items[activeIndex], items[activeIndex].textContent);
                    }
                });
            });
        }
        
        function selectAccount(element, account) {
            const input = element.closest('.autocomplete-container').querySelector('.account-input');
            input.value = account;
            element.closest('.autocomplete-list').style.display = 'none';
            input.focus();
            
            // Move to next field (value)
            const amountInput = input.closest('.entry-row').querySelector('.amount-input');
            if (amountInput) {
                amountInput.focus();
            }
        }
        
        function addEntry() {
            const container = document.getElementById('entriesContainer');
            const index = container.children.length;
            
            const entryRow = document.createElement('div');
            entryRow.className = 'entry-row';
            entryRow.innerHTML = '<div class="autocomplete-container" style="flex: 1;">' +
                               '<input type="text" class="account-input" placeholder="Account name" data-index="' + index + '" autocomplete="off">' +
                               '<div class="autocomplete-list" id="autocomplete-' + index + '"></div>' +
                               '</div>' +
                               '<input type="number" class="amount-input" step="0.01" placeholder="Value" oninput="calculateTotal()">' +
                               '<button type="button" class="remove-entry" onclick="removeEntry(this)">&times;</button>';
            
            container.appendChild(entryRow);
            setupAutocomplete();
            
            // Add event for new value field
            entryRow.querySelector('.amount-input').addEventListener('input', calculateTotal);
        }
        
        function removeEntry(button) {
            if (document.querySelectorAll('.entry-row').length > 2) {
                button.closest('.entry-row').remove();
                calculateTotal();
            }
        }
        
        function calculateTotal() {
            const amountInputs = document.querySelectorAll('.amount-input');
            let total = 0;
            
            amountInputs.forEach(input => {
                const value = parseFloat(input.value) || 0;
                total += value;
            });
            
            const totalElement = document.getElementById('totalAmount');
            const balanceStatus = document.getElementById('balanceStatus');
            
            totalElement.textContent = total.toFixed(2);
            
            // Check if balanced
            if (Math.abs(total) < 0.01) {
                totalElement.style.color = '#55ff55';
                balanceStatus.textContent = 'Transaction balanced';
                balanceStatus.style.color = '#55ff55';
            } else {
                totalElement.style.color = '#ff5555';
                balanceStatus.textContent = 'Unbalanced: ' + total.toFixed(2);
                balanceStatus.style.color = '#ff5555';
            }
        }
        
        // Add events for value fields
        document.querySelectorAll('.amount-input').forEach(input => {
            input.addEventListener('input', calculateTotal);
        });
    </script>
</body>
</html>
