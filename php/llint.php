#!/usr/bin/env php
<?php

require_once __DIR__ . '/ledger.php';

use LedgerPHP\Parser;

function usage($name) {
    echo "Usage: $name <ledger-file>\n";
    exit(1);
}

function main($argv) {
    if (count($argv) != 2) {
        usage($argv[0]);
    }
    
    $ledgerFileName = $argv[1];
    
    try {
        if (!file_exists($ledgerFileName)) {
            throw new \RuntimeException("File '$ledgerFileName' not found");
        }
        
        $content = file_get_contents($ledgerFileName);
        if ($content === false) {
            throw new \RuntimeException("Could not read file '$ledgerFileName'");
        }
        
        $transactions = Parser::parseLedger($content);
        $errorCount = 0;
        
        foreach ($transactions as $transaction) {
            // Check if transaction is balanced
            $total = \LedgerPHP\SimpleRational::zero();
            foreach ($transaction->accountChanges as $change) {
                if ($change->balance !== null) {
                    $total = $total->plus($change->balance);
                }
            }
            
            if (!$total->isZero()) {
                echo "Ledger: Transaction not balanced: {$transaction->payee} (diff: {$total->toFloat()})\n";
                $errorCount++;
            }
        }
        
        if ($errorCount > 0) {
            echo "Found $errorCount error(s) in ledger file.\n";
            exit($errorCount);
        } else {
            echo "Ledger file is valid.\n";
            exit(0);
        }
        
    } catch (\Exception $e) {
        echo "Ledger: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (PHP_SAPI === 'cli') {
    main($argv);
}
