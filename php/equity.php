#!/usr/bin/env php
<?php

require_once __DIR__ . '/ledger.php';

use LedgerPHP\Ledger;
use LedgerPHP\Parser;
use LedgerPHP\SimpleRational;

define('TRANSACTION_DATE_FORMAT', 'Y/m/d');
define('DISPLAY_PRECISION', 2);

class EquityCLI
{
    private $options = [
        'f' => '',           // Ledger file
        'b' => '1970/01/01', // Start date
        'e' => null,         // End date
        'payee' => '',       // Payee filter
        'columns' => 79,     // Column width
        'debug' => false,    // Debug mode
    ];

    public function run(array $argv): void
    {
        $this->parseArguments($argv);
        
        if (empty($this->options['f'])) {
            $this->showUsage();
            exit(1);
        }
        
        if ($this->options['e'] === null) {
            $this->options['e'] = date(TRANSACTION_DATE_FORMAT, strtotime('+1 day'));
        }
        
        try {
            $content = $this->options['f'] === '-' 
                ? file_get_contents('php://stdin')
                : $this->readLedgerFile($this->options['f']);
            
            if ($content === false) {
                throw new \RuntimeException("Could not read file");
            }
            
            $transactions = Parser::parseLedger($content);
            $this->generateEquityTransaction($transactions);
            
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function readLedgerFile(string $filepath): string
    {
        if (!file_exists($filepath)) {
            throw new \RuntimeException("File '$filepath' not found");
        }
        
        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new \RuntimeException("Could not read file '$filepath'");
        }
        
        // Process includes
        return $this->processIncludes($content, dirname($filepath));
    }
    
    private function processIncludes(string $content, string $baseDir): string
    {
        $lines = explode("\n", $content);
        $result = [];
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Check if it's an include line
            if (preg_match('/^(include|!include)\s+["\']?(.+?)["\']?\s*$/i', $trimmedLine, $matches)) {
                $includedFile = trim($matches[2]);
                $includedPath = $this->resolvePath($includedFile, $baseDir);
                
                // Read the included file
                if (!file_exists($includedPath)) {
                    throw new \RuntimeException("Included file '$includedFile' not found (looking in: $includedPath)");
                }
                
                $includedContent = file_get_contents($includedPath);
                if ($includedContent === false) {
                    throw new \RuntimeException("Could not read included file '$includedFile'");
                }
                
                // Recursively process any includes within the included file
                $includedProcessed = $this->processIncludes($includedContent, dirname($includedPath));
                
                // Add without extra empty line
                $includedProcessed = trim($includedProcessed, "\n");
                if (!empty($includedProcessed)) {
                    $result[] = $includedProcessed;
                }
            } else {
                $result[] = $line;
            }
        }
        
        return implode("\n", $result);
    }
    
    private function resolvePath(string $path, string $baseDir): string
    {
        // If absolute path, return as is
        if (preg_match('/^(\/|\\\\|[A-Za-z]:)/', $path)) {
            return $path;
        }
        
        // Otherwise, resolve as relative path
        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
    
    private function parseArguments(array $argv): void
    {
        $options = [];
        
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            
            if (strpos($arg, '--') === 0) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;
                
                if ($key === 'help') {
                    $this->showUsage();
                    exit(0);
                }
                
                $options[$key] = $value;
            } elseif (strpos($arg, '-') === 0) {
                $key = $arg[1];
                if (isset($argv[$i + 1]) && strpos($argv[$i + 1], '-') !== 0) {
                    $options[$key] = $argv[$i + 1];
                    $i++;
                } else {
                    $options[$key] = true;
                }
            }
        }
        
        $this->options = array_merge($this->options, $options);
    }
    
    private function generateEquityTransaction(array $transactions): void
    {
        if ($this->options['debug']) {
            echo "=== DEBUG MODE ===\n";
            echo "Total transactions loaded: " . count($transactions) . "\n";
            echo "Date range: " . $this->options['b'] . " to " . $this->options['e'] . "\n";
            echo "Payee filter: '" . $this->options['payee'] . "'\n\n";
        }
        
        // Sort transactions by date (as Go assumes)
        usort($transactions, function($a, $b) {
            return $a->date <=> $b->date;
        });
        
        // Parse dates
        $start = \DateTime::createFromFormat(TRANSACTION_DATE_FORMAT, $this->options['b']);
        $end = \DateTime::createFromFormat(TRANSACTION_DATE_FORMAT, $this->options['e']);
        
        if (!$start || !$end) {
            throw new \RuntimeException("Invalid date format. Use YYYY/MM/DD");
        }
        
        if ($this->options['debug']) {
            echo "Parsed start date: " . $start->format('Y/m/d H:i:s') . "\n";
            echo "Parsed end date: " . $end->format('Y/m/d H:i:s') . "\n\n";
        }
        
        // Implement EXACTLY the same logic as Go:
        // 1. Find first index where Date > StartDate (After())
        $startIndex = 0;
        for ($i = 0; $i < count($transactions); $i++) {
            if ($transactions[$i]->date > $start) { // After() in Go
                $startIndex = $i;
                break;
            }
        }
        
        // 2. Find last index where Date < EndDate (Before())
        $endIndex = count($transactions) - 1;
        for ($i = count($transactions) - 1; $i >= 0; $i--) {
            if ($transactions[$i]->date < $end) { // Before() in Go
                $endIndex = $i;
                break;
            }
        }
        
        if ($this->options['debug']) {
            echo "Start index (first after start date): $startIndex\n";
            echo "End index (last before end date): $endIndex\n";
        }
        
        // 3. Extract slice (as Go does with generalLedger[timeStartIndex:timeEndIndex+1])
        $filtered = [];
        if ($startIndex <= $endIndex) {
            $filtered = array_slice($transactions, $startIndex, $endIndex - $startIndex + 1);
        }
        
        if ($this->options['debug']) {
            echo "Transactions after date filtering: " . count($filtered) . "\n\n";
            
            if (count($filtered) > 0) {
                echo "First filtered transaction: " . 
                     $filtered[0]->date->format('Y/m/d') . " - " . 
                     $filtered[0]->payee . "\n";
                echo "Last filtered transaction: " . 
                     end($filtered)->date->format('Y/m/d') . " - " . 
                     end($filtered)->payee . "\n\n";
                
                // Show all filtered transactions for debug
                if (count($filtered) <= 20) {
                    echo "All filtered transactions:\n";
                    foreach ($filtered as $i => $t) {
                        echo sprintf("%3d", $i) . ": " . 
                             $t->date->format('Y/m/d') . " - " . 
                             $t->payee . "\n";
                    }
                    echo "\n";
                }
            }
        }
        
        // 4. Apply payee filter (as Go does with strings.Contains)
        if (!empty($this->options['payee'])) {
            $originalCount = count($filtered);
            $filtered = array_filter($filtered, function($transaction) {
                return stripos($transaction->payee, $this->options['payee']) !== false;
            });
            $filtered = array_values($filtered); // Reindex
            
            if ($this->options['debug']) {
                echo "Transactions after payee filter: " . count($filtered) . 
                     " (removed " . ($originalCount - count($filtered)) . ")\n\n";
            }
        }
        
        if (empty($filtered)) {
            echo "No transactions in specified period.\n";
            return;
        }
        
        if ($this->options['debug']) {
            echo "=== BALANCE CALCULATION ===\n";
        }
        
        // Calculate accumulated balances (same logic as Go)
        $balances = [];
        foreach ($filtered as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                if ($accountChange->balance === null) {
                    if ($this->options['debug']) {
                        echo "WARNING: null balance for account: " . 
                             $accountChange->name . " in transaction: " . 
                             $transaction->payee . "\n";
                    }
                    continue;
                }
                
                $accountName = $accountChange->name;
                $changeValue = $accountChange->balance->toFloat(DISPLAY_PRECISION);
                
                if (!isset($balances[$accountName])) {
                    $balances[$accountName] = SimpleRational::zero();
                }
                
                $oldBalance = $balances[$accountName]->toFloat(DISPLAY_PRECISION);
                $balances[$accountName] = $balances[$accountName]->plus($accountChange->balance);
                $newBalance = $balances[$accountName]->toFloat(DISPLAY_PRECISION);
                
                if ($this->options['debug']) {
                    echo "Transaction: " . $transaction->date->format('Y/m/d') . 
                         " | Account: " . str_pad($accountName, 30) . 
                         " | Change: " . str_pad(sprintf("%8.2f", $changeValue), 10) .
                         " | Balance: " . sprintf("%8.2f", $newBalance) . "\n";
                }
            }
        }
        
        if ($this->options['debug']) {
            echo "\n=== FINAL BALANCES ===\n";
            foreach ($balances as $name => $balance) {
                echo str_pad($name, 40) . ": " . 
                     $balance->toFloat(DISPLAY_PRECISION) . "\n";
            }
            echo "\n";
        }
        
        // Remove zero balance accounts (as Go does with ratZero.Cmp)
        $nonZeroBalances = [];
        foreach ($balances as $name => $balance) {
            if (!$balance->isZero()) {
                $nonZeroBalances[$name] = $balance;
            } elseif ($this->options['debug']) {
                echo "Removing zero balance account: $name\n";
            }
        }
        
        if (empty($nonZeroBalances)) {
            echo "All balances are zero in specified period.\n";
            return;
        }
        
        // Create equity transaction
        $transaction = new \LedgerPHP\Transaction("Opening Balances", new \DateTime());
        
        // Use date from last filtered transaction (as Go does)
        if (!empty($filtered)) {
            $lastTransaction = end($filtered);
            $transaction->date = $lastTransaction->date;
            
            if ($this->options['debug']) {
                echo "Using date from last transaction: " . 
                     $transaction->date->format('Y/m/d') . "\n";
            }
        }
        
        // Add non-zero balance accounts (sorted as Go does)
        ksort($nonZeroBalances);
        foreach ($nonZeroBalances as $name => $balance) {
            $accountChange = new \LedgerPHP\AccountChange();
            $accountChange->name = $name;
            $accountChange->balance = $balance;
            $transaction->accountChanges[] = $accountChange;
        }
        
        // Print transaction
        if ($this->options['debug']) {
            echo "\n=== FINAL TRANSACTION ===\n";
        }
        $this->printTransaction($transaction);
    }
    
    private function printTransaction($transaction): void
    {
        echo $transaction->date->format(TRANSACTION_DATE_FORMAT) . 
             " " . $transaction->payee . "\n";
        
        // Find maximum name length
        $maxNameLength = 0;
        foreach ($transaction->accountChanges as $accountChange) {
            $nameLength = strlen($accountChange->name);
            if ($nameLength > $maxNameLength) {
                $maxNameLength = $nameLength;
            }
        }
        
        // Define column for values
        $availableWidth = $this->options['columns'] - 4;
        $valueWidth = 12;
        $nameColumn = min($maxNameLength + 4, $availableWidth - $valueWidth);
        if ($nameColumn > 50) $nameColumn = 50;
        
        foreach ($transaction->accountChanges as $accountChange) {
            $balanceStr = $accountChange->balance->toFloat(DISPLAY_PRECISION);
            $name = $accountChange->name;
            $nameLength = strlen($name);
            
            if ($nameLength > $nameColumn - 4) {
                $maxDisplayLength = $nameColumn - 7;
                if ($maxDisplayLength > 10) {
                    $name = substr($name, 0, $maxDisplayLength) . '...';
                    $nameLength = strlen($name);
                }
            }
            
            $totalSpaces = $availableWidth - $nameLength - strlen($balanceStr);
            if ($totalSpaces < 2) $totalSpaces = 2;
            
            echo "    " . $name . str_repeat(' ', $totalSpaces) . $balanceStr . "\n";
        }
        
        echo "\n";
    }
    
    private function showUsage(): void
    {
        echo "Equity - Opening Balance Transaction Generator\n";
        echo "=============================================\n\n";
        echo "Usage: php equity.php [OPTIONS]\n\n";
        echo "Options:\n";
        echo "  -f FILE      Ledger file (*required) or '-' for stdin\n";
        echo "  -b DATE      Start date (default: 1970/01/01)\n";
        echo "  -e DATE      End date (default: today)\n";
        echo "  --payee=STR  Filter by payee\n";
        echo "  --columns=N  Column width (default: 79)\n";
        echo "  --debug      Enable debug output\n";
        echo "  --help       Show this help\n\n";
        echo "Description:\n";
        echo "  Generates an 'Opening Balances' transaction with accumulated balances\n";
        echo "  from all transactions in the specified period. Useful for archiving\n";
        echo "  old transactions and starting with correct balances.\n\n";
        echo "Examples:\n";
        echo "  php equity.php -f my_ledger.txt -b 2023/01/01 -e 2023/12/31\n";
        echo "  php equity.php -f my_ledger.txt --payee='Salary' -b 2023/01/01\n";
        echo "  cat my_ledger.txt | php equity.php -f -\n";
    }
}

if (PHP_SAPI === 'cli') {
    $cli = new EquityCLI();
    $cli->run($argv);
}
