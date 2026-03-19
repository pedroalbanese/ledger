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
    const PERIOD_WEEK = 'Weekly';
    const PERIOD_2WEEK = 'BiWeekly';
    const PERIOD_MONTH = 'Monthly';
    const PERIOD_2MONTH = 'BiMonthly';
    const PERIOD_QUARTER = 'Quarterly';
    const PERIOD_SEMIYEAR = 'SemiYearly';
    const PERIOD_YEAR = 'Yearly';
    
    const RANGE_PARTITION = 'Partition';
    const RANGE_SNAPSHOT = 'Snapshot';
    
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
    
    /**
     * Find the start of a period based on a date (matching original ledger behavior)
     */
    private static function getPeriodStart(\DateTime $date, string $period, ?\DateTime $firstTransactionDate = null): \DateTime
    {
        $start = clone $date;
        $start->setTime(0, 0, 0);
        
        switch ($period) {
            case self::PERIOD_WEEK:
                // Find the most recent Sunday (including today if it's Sunday)
                $dayOfWeek = (int)$start->format('w'); // 0 = Sunday, 6 = Saturday
                if ($dayOfWeek > 0) {
                    $start->modify("last sunday");
                }
                break;
                
            case self::PERIOD_2WEEK:
                // CRITICAL FIX: For BiWeekly, start from the FIRST transaction date
                // and find the Sunday before it (or on it)
                if ($firstTransactionDate !== null) {
                    $start = clone $firstTransactionDate;
                    $start->setTime(0, 0, 0);
                }
                
                // Find the most recent Sunday
                $dayOfWeek = (int)$start->format('w');
                if ($dayOfWeek > 0) {
                    $start->modify("last sunday");
                }
                break;
                
            case self::PERIOD_MONTH:
                $start->setDate((int)$start->format('Y'), (int)$start->format('m'), 1);
                break;
                
            case self::PERIOD_2MONTH:
                $month = (int)$start->format('m');
                $year = (int)$start->format('Y');
                
                // Start of bi-month period (Jan-Feb, Mar-Apr, etc.)
                if ($month % 2 == 1) {
                    $startMonth = $month;
                } else {
                    $startMonth = $month - 1;
                }
                $start->setDate($year, $startMonth, 1);
                break;
                
            case self::PERIOD_QUARTER:
                $month = (int)$start->format('m');
                $year = (int)$start->format('Y');
                
                if ($month <= 3) {
                    $start->setDate($year, 1, 1);
                } elseif ($month <= 6) {
                    $start->setDate($year, 4, 1);
                } elseif ($month <= 9) {
                    $start->setDate($year, 7, 1);
                } else {
                    $start->setDate($year, 10, 1);
                }
                break;
                
            case self::PERIOD_SEMIYEAR:
                $month = (int)$start->format('m');
                $year = (int)$start->format('Y');
                
                if ($month <= 6) {
                    $start->setDate($year, 1, 1);
                } else {
                    $start->setDate($year, 7, 1);
                }
                break;
                
            case self::PERIOD_YEAR:
                $start->setDate((int)$start->format('Y'), 1, 1);
                break;
        }
        
        return $start;
    }
    
    /**
     * Calculate the end date of a period based on its start
     */
    private static function getPeriodEnd(\DateTime $start, string $period): \DateTime
    {
        $end = clone $start;
        
        switch ($period) {
            case self::PERIOD_WEEK:
                $end->modify('+7 days');
                break;
            case self::PERIOD_2WEEK:
                $end->modify('+14 days');
                break;
            case self::PERIOD_MONTH:
                $end->modify('first day of next month');
                break;
            case self::PERIOD_2MONTH:
                $end->modify('+2 months');
                $end->setDate((int)$end->format('Y'), (int)$end->format('m'), 1);
                break;
            case self::PERIOD_QUARTER:
                $end->modify('+3 months');
                $end->setDate((int)$end->format('Y'), (int)$end->format('m'), 1);
                break;
            case self::PERIOD_SEMIYEAR:
                $end->modify('+6 months');
                $end->setDate((int)$end->format('Y'), (int)$end->format('m'), 1);
                break;
            case self::PERIOD_YEAR:
                $end->modify('first day of next year');
                break;
        }
        
        return $end;
    }
    
    /**
     * Get the earliest and latest transaction dates
     */
    private static function getDateRange(array $transactions): array
    {
        if (empty($transactions)) {
            return ['start' => null, 'end' => null];
        }
        
        $start = $transactions[0]->date;
        $end = $transactions[0]->date;
        
        foreach ($transactions as $transaction) {
            if ($transaction->date < $start) {
                $start = $transaction->date;
            }
            if ($transaction->date > $end) {
                $end = $transaction->date;
            }
        }
        
        return ['start' => $start, 'end' => $end];
    }
    
    /**
     * Filter transactions within a date range (matching original ledger behavior)
     */
    private static function transactionsInDateRange(array $transactions, \DateTime $start, \DateTime $end): array
    {
        $result = [];
        
        // Original: start.Add(-1 * time.Second) - includes transactions on the start day
        $startInclusive = clone $start;
        $startInclusive->modify('-1 second');
        
        // Original: tran.Date.After(start) && tran.Date.Before(end)
        // End is exclusive - transactions on the end day are NOT included
        foreach ($transactions as $transaction) {
            if ($transaction->date > $startInclusive && $transaction->date < $end) {
                $result[] = $transaction;
            }
        }
        
        return $result;
    }
    
    /**
     * Generate all period boundaries between two dates
     */
    private static function generatePeriods(\DateTime $startDate, \DateTime $endDate, string $period, ?\DateTime $firstTransactionDate = null): array
    {
        $periods = [];
        
        // Start from the beginning of the period containing the first transaction
        $currentStart = self::getPeriodStart($startDate, $period, $firstTransactionDate);
        
        // Generate periods until we pass the end date
        while ($currentStart <= $endDate) {
            $currentEnd = self::getPeriodEnd($currentStart, $period);
            
            $periodObj = new \stdClass();
            $periodObj->start = clone $currentStart;
            $periodObj->end = clone $currentEnd;
            
            $periods[] = $periodObj;
            
            // Move to next period
            $currentStart = clone $currentEnd;
        }
        
        return $periods;
    }
    
    /**
     * Format a period key for display
     */
    private static function formatPeriodKey(\DateTime $start, string $period): string
    {
        switch ($period) {
            case self::PERIOD_WEEK:
            case self::PERIOD_2WEEK:
                return $start->format('Y/m/d');
            case self::PERIOD_MONTH:
                return $start->format('Y/m');
            case self::PERIOD_2MONTH:
                $month = (int)$start->format('m');
                $biMonth = ceil($month / 2);
                return $start->format('Y') . '-BM' . $biMonth;
            case self::PERIOD_QUARTER:
                $quarter = ceil((int)$start->format('m') / 3);
                return $start->format('Y') . '-Q' . $quarter;
            case self::PERIOD_SEMIYEAR:
                $semester = ((int)$start->format('m') <= 6) ? 1 : 2;
                return $start->format('Y') . '-H' . $semester;
            case self::PERIOD_YEAR:
                return $start->format('Y');
            default:
                return $start->format('Y/m/d');
        }
    }
    
    // ADDED: transactionsByPeriod() method - Fixed version matching original
    public static function transactionsByPeriod(array $transactions, string $period): array
    {
        if (empty($transactions)) {
            return [];
        }
        
        // Get overall date range
        $dateRange = self::getDateRange($transactions);
        
        // Get first transaction date for BiWeekly special case
        $firstTransactionDate = null;
        if ($period === self::PERIOD_2WEEK && !empty($transactions)) {
            $firstTransactionDate = $transactions[0]->date;
        }
        
        // Generate all periods from before first transaction to after last
        $periods = self::generatePeriods($dateRange['start'], $dateRange['end'], $period, $firstTransactionDate);
        
        $results = [];
        foreach ($periods as $periodObj) {
            $periodTransactions = self::transactionsInDateRange(
                $transactions, 
                $periodObj->start, 
                $periodObj->end
            );
            
            // Always include the period, even if empty (like original)
            $range = new \stdClass();
            $range->start = clone $periodObj->start;
            
            // End date should be the last day (inclusive, so subtract 1 day)
            $range->end = clone $periodObj->end;
            $range->end->modify('-1 day');
            
            $range->transactions = $periodTransactions;
            $range->key = self::formatPeriodKey($periodObj->start, $period);
            
            $results[] = $range;
        }
        
        return $results;
    }
    
    // ADDED: balancesByPeriod() method - Fixed version matching original
    public static function balancesByPeriod(array $transactions, string $period, string $rangeType): array
    {
        if (empty($transactions)) {
            return [];
        }
        
        // Get overall date range
        $dateRange = self::getDateRange($transactions);
        
        // Get first transaction date for BiWeekly special case
        $firstTransactionDate = null;
        if ($period === self::PERIOD_2WEEK && !empty($transactions)) {
            $firstTransactionDate = $transactions[0]->date;
        }
        
        // Generate all periods
        $periods = self::generatePeriods($dateRange['start'], $dateRange['end'], $period, $firstTransactionDate);
        
        $results = [];
        $runningBalances = [];
        
        foreach ($periods as $periodObj) {
            $periodTransactions = self::transactionsInDateRange(
                $transactions, 
                $periodObj->start, 
                $periodObj->end
            );
            
            // Always include the period, even if empty (like original)
            $range = new \stdClass();
            $range->start = clone $periodObj->start;
            
            // End date should be the last day (inclusive, so subtract 1 day)
            $range->end = clone $periodObj->end;
            $range->end->modify('-1 day');
            
            if ($rangeType === self::RANGE_SNAPSHOT) {
                // Snapshot: running total including all previous periods
                foreach ($periodTransactions as $transaction) {
                    foreach ($transaction->accountChanges as $change) {
                        if ($change->balance === null) continue;
                        
                        $accountName = $change->name;
                        if (!isset($runningBalances[$accountName])) {
                            $runningBalances[$accountName] = SimpleRational::zero();
                        }
                        $runningBalances[$accountName] = $runningBalances[$accountName]->plus($change->balance);
                    }
                }
                
                // Convert to Account objects
                $balances = [];
                foreach ($runningBalances as $name => $balance) {
                    if (!$balance->isZero()) {
                        $balances[] = new Account($name, $balance);
                    }
                }
                usort($balances, function($a, $b) {
                    return strcmp($a->name, $b->name);
                });
                
                $range->balances = $balances;
            } else {
                // Partition: only transactions in this period
                $range->balances = self::getBalances($periodTransactions);
            }
            
            $results[] = $range;
        }
        
        return $results;
    }
}
