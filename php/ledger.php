<?php

namespace LedgerPHP;

// ==============================
// Main Classes
// ==============================

class SimpleRational
{
    private float $value;
    
    public function __construct($value)
    {
        if (is_string($value)) {
            // Normalize
            $value = trim($value);
            // Replace comma with dot
            $value = str_replace(',', '.', $value);
            // If in parentheses, it's negative
            if (preg_match('/^\(.+\)$/', $value)) {
                $value = preg_replace('/[\(\)]/', '', $value);
                $value = '-' . $value;
            }
            // Remove currency characters
            $value = preg_replace('/[\$\€\£\¥]/', '', $value);
        }
        $this->value = (float) $value;
    }
    
    public static function of($value): self
    {
        return new self($value);
    }
    
    public static function zero(): self
    {
        return new self(0);
    }
    
    public function plus(self $other): self
    {
        return new self($this->value + $other->value);
    }
    
    public function negated(): self
    {
        return new self(-$this->value);
    }
    
    public function isZero(): bool
    {
        return abs($this->value) < 0.000001;
    }
    
    public function toFloat(int $precision = 2): string
    {
        return number_format($this->value, $precision, '.', '');
    }
    
    public function getValue(): float
    {
        return $this->value;
    }
    
    public function __toString(): string
    {
        return $this->toFloat();
    }
    
    public function sign(): int
    {
        if ($this->value > 0.000001) return 1;
        if ($this->value < -0.000001) return -1;
        return 0;
    }
    
    // ADICIONADO: Método equals() necessário para o handleBalance()
    public function equals(SimpleRational $other): bool
    {
        return abs($this->value - $other->value) < 0.000001;
    }
}

class Account
{
    public string $name;
    public SimpleRational $balance;

    public function __construct(string $name, SimpleRational $balance)
    {
        $this->name = $name;
        $this->balance = $balance;
    }
}

class AccountChange
{
    public string $name;
    public ?SimpleRational $balance = null;

    public function __toString(): string
    {
        if ($this->balance === null) {
            return $this->name;
        }
        return $this->name . "    " . $this->balance->toFloat();
    }
}

class Transaction
{
    public string $payee;
    public \DateTime $date;
    public array $accountChanges = [];
    public array $comments = [];

    public function __construct(string $payee, \DateTime $date)
    {
        $this->payee = $payee;
        $this->date = $date;
    }

    public function __toString(): string
    {
        $output = $this->date->format('Y/m/d') . " " . $this->payee . "\n";
        foreach ($this->accountChanges as $change) {
            $output .= "    " . $change . "\n";
        }
        return $output;
    }
}

// ==============================
// Parser - Simplified and More Robust Version
// ==============================

class Parser
{
    public static function parseLedger(string $content): array
    {
        $transactions = [];
        $lines = explode("\n", $content);
        
        $currentTransaction = null;
        $comments = [];
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            
            // Skip empty lines
            if (trim($line) === '') {
                if ($currentTransaction !== null) {
                    // Finalize current transaction
                    self::finalizeTransaction($currentTransaction);
                    $currentTransaction->comments = $comments;
                    $transactions[] = $currentTransaction;
                    $currentTransaction = null;
                    $comments = [];
                }
                continue;
            }
            
            // Comments
            if (strpos(trim($line), ';') === 0) {
                $comments[] = $line;
                continue;
            }
            
            // Check if it's transaction start (date at beginning)
            if (preg_match('/^(\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2})\s+(.+)$/', $line, $matches)) {
                if ($currentTransaction !== null) {
                    // Finalize previous transaction
                    self::finalizeTransaction($currentTransaction);
                    $currentTransaction->comments = $comments;
                    $transactions[] = $currentTransaction;
                    $comments = [];
                }
                
                $dateString = $matches[1];
                $payee = $matches[2];
                
                try {
                    $date = self::parseDate($dateString);
                    $currentTransaction = new Transaction($payee, $date);
                } catch (\Exception $e) {
                    // Ignore invalid line
                    continue;
                }
            } 
            // Account line (indented)
            elseif ($currentTransaction !== null && (strpos($line, '    ') === 0 || strpos($line, "\t") === 0)) {
                $accountChange = self::parseAccountLine(trim($line));
                if ($accountChange !== null) {
                    $currentTransaction->accountChanges[] = $accountChange;
                }
            }
        }
        
        // Last transaction
        if ($currentTransaction !== null) {
            self::finalizeTransaction($currentTransaction);
            $currentTransaction->comments = $comments;
            $transactions[] = $currentTransaction;
        }
        
        // Sort by date
        usort($transactions, function($a, $b) {
            return $a->date <=> $b->date;
        });
        
        return $transactions;
    }
    
    private static function parseDate(string $dateString): \DateTime
    {
        // Normalize separators
        $dateString = str_replace(['.', '-'], '/', $dateString);
        
        // Try Y/m/d format
        $date = \DateTime::createFromFormat('Y/m/d', $dateString);
        if ($date !== false) {
            return $date;
        }
        
        throw new \RuntimeException("Invalid date: {$dateString}");
    }
    
    private static function parseAccountLine(string $line): ?AccountChange
    {
        $line = trim($line);
        
        // Pattern: "Account Name    123.45" or "Account Name"
        // Look for 2 or more spaces before value
        if (preg_match('/^(.*?)\s{2,}(.+)$/', $line, $matches)) {
            $accountName = trim($matches[1]);
            $valueStr = trim($matches[2]);
            
            $balance = self::parseBalance($valueStr);
            if ($balance !== null) {
                $accountChange = new AccountChange();
                $accountChange->name = $accountName;
                $accountChange->balance = $balance;
                return $accountChange;
            }
        }
        
        // Try to find value at end of line
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 2) {
            $lastPart = end($parts);
            $balance = self::parseBalance($lastPart);
            if ($balance !== null) {
                $accountName = implode(' ', array_slice($parts, 0, -1));
                $accountChange = new AccountChange();
                $accountChange->name = $accountName;
                $accountChange->balance = $balance;
                return $accountChange;
            }
        }
        
        // Line without value
        $accountChange = new AccountChange();
        $accountChange->name = $line;
        return $accountChange;
    }
    
    private static function parseBalance(string $valueStr): ?SimpleRational
    {
        $valueStr = trim($valueStr);
        
        // Empty?
        if ($valueStr === '') {
            return null;
        }
        
        // Is it a number?
        $testStr = $valueStr;
        
        // Remove parentheses
        $isNegative = false;
        if (preg_match('/^\((.+)\)$/', $testStr, $matches)) {
            $testStr = $matches[1];
            $isNegative = true;
        }
        
        // Remove currency characters
        $testStr = preg_replace('/[\$\€\£\¥\s,]/', '', $testStr);
        
        // Replace comma with dot
        $testStr = str_replace(',', '.', $testStr);
        
        // Check if it's numeric
        if (is_numeric($testStr)) {
            $value = (float)$testStr;
            if ($isNegative) {
                $value = -$value;
            }
            return SimpleRational::of($value);
        }
        
        return null;
    }
    
    private static function finalizeTransaction(Transaction $transaction): void
    {
        // Calculate total
        $total = SimpleRational::zero();
        $emptyIndex = -1;
        
        foreach ($transaction->accountChanges as $index => $change) {
            if ($change->balance === null) {
                if ($emptyIndex !== -1) {
                    throw new \RuntimeException("Multiple empty accounts in transaction");
                }
                $emptyIndex = $index;
            } else {
                $total = $total->plus($change->balance);
            }
        }
        
        // Fill empty account if exists
        if ($emptyIndex !== -1) {
            $transaction->accountChanges[$emptyIndex]->balance = $total->negated();
        }
        
        // Check if balanced
        $checkTotal = SimpleRational::zero();
        foreach ($transaction->accountChanges as $change) {
            if ($change->balance !== null) {
                $checkTotal = $checkTotal->plus($change->balance);
            }
        }
        
        if (!$checkTotal->isZero()) {
            // Round to avoid precision issues
            if (abs($checkTotal->getValue()) > 0.01) {
                throw new \RuntimeException(
                    "Transaction not balanced: {$transaction->payee} (diff: {$checkTotal})"
                );
            }
        }
    }
}

// ==============================
// Ledger - Simplified Main Logic
// ==============================

class Ledger
{
    const PERIOD_MONTH = 'Monthly';
    const PERIOD_QUARTER = 'Quarterly';
    const PERIOD_SEMIYEAR = 'SemiYearly';
    const PERIOD_YEAR = 'Yearly';
    
    const RANGE_PARTITION = 'Partition';
    
    public static function getBalances(array $transactions, array $filters = []): array
    {
        $balances = [];
        
        foreach ($transactions as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                if ($accountChange->balance === null) {
                    continue;
                }
                
                $include = empty($filters);
                if (!$include) {
                    foreach ($filters as $filter) {
                        if (strpos($accountChange->name, $filter) !== false) {
                            $include = true;
                            break;
                        }
                    }
                }
                
                if ($include) {
                    $accountName = $accountChange->name;
                    if (!isset($balances[$accountName])) {
                        $balances[$accountName] = SimpleRational::zero();
                    }
                    $balances[$accountName] = $balances[$accountName]->plus($accountChange->balance);
                }
            }
        }
        
        // Convert to Account objects
        $result = [];
        foreach ($balances as $name => $balance) {
            $result[] = new Account($name, $balance);
        }
        
        // Sort by name
        usort($result, function($a, $b) {
            return strcmp($a->name, $b->name);
        });
        
        return $result;
    }
    
    // ADICIONADO: Método transactionsByPeriod() necessário para o handleRegister()
    public static function transactionsByPeriod(array $transactions, string $period): array
    {
        $grouped = [];
        
        foreach ($transactions as $transaction) {
            $date = $transaction->date;
            
            // Determine period key based on the period type
            switch ($period) {
                case self::PERIOD_MONTH:
                    $key = $date->format('Y-m');
                    break;
                case self::PERIOD_QUARTER:
                    $quarter = ceil($date->format('m') / 3);
                    $key = $date->format('Y') . '-Q' . $quarter;
                    break;
                case self::PERIOD_SEMIYEAR:
                    $semester = ($date->format('m') <= 6) ? 1 : 2;
                    $key = $date->format('Y') . '-H' . $semester;
                    break;
                case self::PERIOD_YEAR:
                    $key = $date->format('Y');
                    break;
                default:
                    // Default to monthly
                    $key = $date->format('Y-m');
            }
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            
            $grouped[$key][] = $transaction;
        }
        
        // Convert to range objects
        $ranges = [];
        foreach ($grouped as $key => $periodTransactions) {
            if (empty($periodTransactions)) {
                continue;
            }
            
            // Sort transactions within period
            usort($periodTransactions, function($a, $b) {
                return $a->date <=> $b->date;
            });
            
            $range = new \stdClass();
            $range->start = clone $periodTransactions[0]->date;
            $range->end = clone end($periodTransactions)->date;
            $range->transactions = $periodTransactions;
            $range->key = $key;
            
            $ranges[] = $range;
        }
        
        // Sort ranges chronologically
        usort($ranges, function($a, $b) {
            return $a->start <=> $b->start;
        });
        
        return $ranges;
    }
    
    // ADICIONADO: Método balancesByPeriod() necessário para o handleBalance()
    public static function balancesByPeriod(array $transactions, string $period, string $rangeType): array
    {
        $periods = self::transactionsByPeriod($transactions, $period);
        $results = [];
        
        foreach ($periods as $period) {
            $range = new \stdClass();
            $range->start = $period->start;
            $range->end = $period->end;
            $range->balances = self::getBalances($period->transactions);
            $results[] = $range;
        }
        
        return $results;
    }
}
