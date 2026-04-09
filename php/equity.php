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
            // Handle stdin with -f -
            if ($this->options['f'] === '-') {
                $content = file_get_contents('php://stdin');
                if ($content === false) {
                    throw new \RuntimeException("Could not read from stdin");
                }
            } else {
                $content = $this->readLedgerFile($this->options['f']);
            }
            
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
            
            // Handle --option=value or --option
            if (strpos($arg, '--') === 0) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;
                
                if ($key === 'help') {
                    $this->showUsage();
                    exit(0);
                }
                
                $options[$key] = $value;
            }
            // Handle -f - (dash as value for stdin)
            elseif ($arg === '-f' && isset($argv[$i + 1]) && $argv[$i + 1] === '-') {
                $options['f'] = '-';
                $i++; // Skip the next argument which is '-'
            }
            // Handle -f file
            elseif ($arg === '-f' && isset($argv[$i + 1])) {
                $options['f'] = $argv[$i + 1];
                $i++;
            }
            // Handle -b date, -e date
            elseif (preg_match('/^-([be])$/', $arg, $matches) && isset($argv[$i + 1])) {
                $key = $matches[1];
                $options[$key] = $argv[$i + 1];
                $i++;
            }
            // Handle --payee, --columns
            elseif (in_array($arg, ['--payee', '--columns']) && isset($argv[$i + 1])) {
                $key = substr($arg, 2);
                $options[$key] = $argv[$i + 1];
                $i++;
            }
            // Handle flags without value: --debug, --help
            elseif (in_array($arg, ['--debug', '--help'])) {
                $key = substr($arg, 2);
                $options[$key] = true;
            }
            // Handle single letter flags: -d (debug)
            elseif ($arg === '-d') {
                $options['debug'] = true;
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
        
        // Sort transactions by date
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
        
        // Filter by date range
        $filtered = array_filter($transactions, function($transaction) use ($start, $end) {
            return $transaction->date >= $start && $transaction->date <= $end;
        });
        $filtered = array_values($filtered);
        
        if ($this->options['debug']) {
            echo "Transactions after date filtering: " . count($filtered) . "\n\n";
            
            if (count($filtered) > 0) {
                echo "First filtered transaction: " . 
                     $filtered[0]->date->format('Y/m/d') . " - " . 
                     $filtered[0]->payee . "\n";
                echo "Last filtered transaction: " . 
                     end($filtered)->date->format('Y/m/d') . " - " . 
                     end($filtered)->payee . "\n\n";
            }
        }
        
        // Apply payee filter
        if (!empty($this->options['payee'])) {
            $originalCount = count($filtered);
            $filtered = array_filter($filtered, function($transaction) {
                return stripos($transaction->payee, $this->options['payee']) !== false;
            });
            $filtered = array_values($filtered);
            
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
        
        // Calculate accumulated balances
        $balances = [];
        foreach ($filtered as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                if ($accountChange->balance === null) {
                    continue;
                }
                
                $accountName = $accountChange->name;
                
                if (!isset($balances[$accountName])) {
                    $balances[$accountName] = SimpleRational::zero();
                }
                
                $balances[$accountName] = $balances[$accountName]->plus($accountChange->balance);
                
                if ($this->options['debug']) {
                    $changeValue = $accountChange->balance->toFloat(DISPLAY_PRECISION);
                    $newBalance = $balances[$accountName]->toFloat(DISPLAY_PRECISION);
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
        
        // Remove zero balance accounts
        $nonZeroBalances = [];
        foreach ($balances as $name => $balance) {
            if (!$balance->isZero()) {
                $nonZeroBalances[$name] = $balance;
            }
        }
        
        if (empty($nonZeroBalances)) {
            echo "All balances are zero in specified period.\n";
            return;
        }
        
        // Create equity transaction
        $lastTransaction = end($filtered);
        $date = $lastTransaction->date;
        
        echo $date->format(TRANSACTION_DATE_FORMAT) . " Opening Balances\n";
        
        // Print accounts sorted
        ksort($nonZeroBalances);
        
        $availableWidth = $this->options['columns'] - 4;
        $valueWidth = 12;
        
        foreach ($nonZeroBalances as $name => $balance) {
            $balanceStr = $balance->toFloat(DISPLAY_PRECISION);
            $nameLength = strlen($name);
            
            $nameColumn = min($nameLength + 4, $availableWidth - $valueWidth);
            if ($nameColumn > 50) $nameColumn = 50;
            
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
