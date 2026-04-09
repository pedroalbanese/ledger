#!/usr/bin/env php
<?php

require_once __DIR__ . '/ledger.php';

use LedgerPHP\Ledger;
use LedgerPHP\Parser;
use LedgerPHP\SimpleRational;

define('TRANSACTION_DATE_FORMAT', 'Y/m/d');
define('DISPLAY_PRECISION', 2);

class LedgerCLI
{
    private $options = [
        'f' => '',
        'b' => '1970/01/01',
        'e' => null,
        'period' => '',
        'payee' => '',
        'empty' => false,
        'depth' => -1,
        'columns' => 79,
        'wide' => false,
    ];

    private $command;
    private $filters = [];

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
            $transactions = $this->filterByDate($transactions);
            $transactions = $this->filterByPayee($transactions);

            $this->executeCommand($transactions);

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

        // Get real path to normalize and detect circular includes
        $realPath = realpath($filepath);
        if ($realPath === false) {
            $realPath = $filepath;
        }

        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new \RuntimeException("Could not read file '$filepath'");
        }

        // Process includes with circular reference detection
        $processedFiles = [$realPath];
        return $this->processIncludes($content, dirname($filepath), $processedFiles);
    }

    private function processIncludes(string $content, string $baseDir, array &$processedFiles = []): string
    {
        $lines = explode("\n", $content);
        $result = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Check if it's an include line
            if (preg_match('/^(include|!include)\s+["\']?(.+?)["\']?\s*$/i', $trimmedLine, $matches)) {
                $includedFile = trim($matches[2]);
                $includedPath = $this->resolvePath($includedFile, $baseDir);
                
                // Normalize path to detect duplicates
                $normalizedPath = realpath($includedPath);
                if ($normalizedPath === false) {
                    $normalizedPath = $includedPath;
                }
                
                // Check for circular includes to prevent infinite recursion and duplication
                if (in_array($normalizedPath, $processedFiles)) {
                    continue; // Skip already processed file
                }
                
                // Check if file exists
                if (!file_exists($includedPath)) {
                    throw new \RuntimeException("Included file '$includedFile' not found (looking in: $includedPath)");
                }
                
                $includedContent = file_get_contents($includedPath);
                if ($includedContent === false) {
                    throw new \RuntimeException("Could not read included file '$includedFile'");
                }
                
                // Mark this file as processed
                $processedFiles[] = $normalizedPath;
                
                // Recursively process includes within the included file
                $includedProcessed = $this->processIncludes($includedContent, dirname($includedPath), $processedFiles);
                
                // Add the processed content (without the include line)
                $includedProcessed = trim($includedProcessed, "\n");
                if (!empty($includedProcessed)) {
                    $result[] = $includedProcessed;
                }
                // IMPORTANT: Do NOT add the original include line
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
        $args = [];

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
            // Handle -b date, -e date, --period period, --payee payee, --depth N, --columns N
            elseif (preg_match('/^-([bep])$/', $arg, $matches) && isset($argv[$i + 1])) {
                $key = $matches[1];
                $options[$key] = $argv[$i + 1];
                $i++;
            }
            // Handle --period, --payee, --depth, --columns with value
            elseif (in_array($arg, ['--period', '--payee', '--depth', '--columns']) && isset($argv[$i + 1])) {
                $key = substr($arg, 2);
                $options[$key] = $argv[$i + 1];
                $i++;
            }
            // Handle flags without value: --empty, --wide, --help
            elseif (in_array($arg, ['--empty', '--wide', '--help'])) {
                $key = substr($arg, 2);
                $options[$key] = true;
            }
            // Handle single letter flags: -e (end date with default), --help already handled
            elseif (preg_match('/^-([ew])$/', $arg, $matches)) {
                $key = $matches[1];
                $options[$key] = true;
            }
            else {
                $args[] = $arg;
            }
        }

        $this->options = array_merge($this->options, $options);

        if ($this->options['wide']) {
            $this->options['columns'] = 132;
        }

        if (count($args) > 0) {
            $this->command = strtolower($args[0]);
            $this->filters = array_slice($args, 1);
        }
    }

    private function filterByDate(array $transactions): array
    {
        if (empty($transactions)) {
            return [];
        }

        $start = \DateTime::createFromFormat(TRANSACTION_DATE_FORMAT, $this->options['b']);
        $end = \DateTime::createFromFormat(TRANSACTION_DATE_FORMAT, $this->options['e']);

        if (!$start || !$end) {
            throw new \RuntimeException("Invalid date format. Use YYYY/MM/DD");
        }

        $filtered = [];
        foreach ($transactions as $transaction) {
            if ($transaction->date >= $start && $transaction->date <= $end) {
                $filtered[] = $transaction;
            }
        }

        return $filtered;
    }

    private function filterByPayee(array $transactions): array
    {
        if (empty($this->options['payee'])) {
            return $transactions;
        }

        $filtered = [];
        foreach ($transactions as $transaction) {
            if (stripos($transaction->payee, $this->options['payee']) !== false) {
                $filtered[] = $transaction;
            }
        }

        return $filtered;
    }

    private function executeCommand(array $transactions): void
    {
        if (empty($this->command)) {
            $this->showUsage();
            exit(1);
        }

        switch ($this->command) {
            case 'balance':
            case 'bal':
                $this->handleBalance($transactions);
                break;
            case 'print':
                $this->handlePrint($transactions);
                break;
            case 'register':
            case 'reg':
                $this->handleRegister($transactions);
                break;
            case 'stats':
                $this->handleStats($transactions);
                break;
            case 'accounts':
                $this->handleAccounts($transactions);
                break;
            default:
                echo "Command '{$this->command}' not implemented.\n";
                $this->showUsage();
                exit(1);
        }
    }

    private function handleBalance(array $transactions): void
    {
        if (empty($transactions)) {
            echo "No transactions found.\n";
            return;
        }

        if (empty($this->options['period'])) {
            $balances = Ledger::getBalances($transactions, $this->filters);
            $this->printBalances($balances);
        } else {
            $ranges = Ledger::balancesByPeriod(
                $transactions,
                $this->options['period'],
                Ledger::RANGE_PARTITION
            );

            foreach ($ranges as $i => $range) {
                if ($i > 0) {
                    echo "\n" . str_repeat('=', $this->options['columns']) . "\n";
                }
                echo $range->start->format(TRANSACTION_DATE_FORMAT)
                     . " - "
                     . $range->end->format(TRANSACTION_DATE_FORMAT) . "\n";
                echo str_repeat('=', $this->options['columns']) . "\n";
                $this->printBalances($range->balances);
            }
        }
    }

    /**
     * CORRECTED: Simple accumulation by building parent sums from leaf accounts
     */
    private function printBalances(array $balances): void
    {
        $maxDepth = $this->options['depth'] >= 0 ? $this->options['depth'] : PHP_INT_MAX;
        $showEmpty = $this->options['empty'];
        
        // First, build a map of all account balances
        $accountBalances = [];
        foreach ($balances as $account) {
            $accountBalances[$account->name] = $account->balance;
        }
        
        // Build a set of all account names for quick lookup
        $allAccountNames = array_keys($accountBalances);
        
        // Identify leaf accounts (accounts that have NO children in the account list)
        $leafAccounts = [];
        foreach ($accountBalances as $name => $balance) {
            $hasChild = false;
            foreach ($allAccountNames as $otherName) {
                if ($otherName !== $name && strpos($otherName, $name . ':') === 0) {
                    $hasChild = true;
                    break;
                }
            }
            // Only include as leaf if it has no children
            if (!$hasChild) {
                $leafAccounts[$name] = $balance;
            }
        }
        
        // Also include accounts that have direct balances but are not leaves
        // (accounts that have both direct postings AND children)
        $directOnlyAccounts = [];
        foreach ($accountBalances as $name => $balance) {
            $hasChild = false;
            foreach ($allAccountNames as $otherName) {
                if ($otherName !== $name && strpos($otherName, $name . ':') === 0) {
                    $hasChild = true;
                    break;
                }
            }
            if ($hasChild && $balance->sign() != 0) {
                $directOnlyAccounts[$name] = $balance;
            }
        }
        
        // Calculate parent balances by summing leaf descendants ONLY
        $hierarchyBalances = [];
        
        // First, add all leaf balances
        foreach ($leafAccounts as $leafName => $leafBalance) {
            $parts = explode(':', $leafName);
            
            // Add to all ancestors including itself
            for ($i = 1; $i <= count($parts); $i++) {
                $ancestorName = implode(':', array_slice($parts, 0, $i));
                if (!isset($hierarchyBalances[$ancestorName])) {
                    $hierarchyBalances[$ancestorName] = SimpleRational::zero();
                }
                $hierarchyBalances[$ancestorName] = $hierarchyBalances[$ancestorName]->plus($leafBalance);
            }
        }
        
        // Then add direct balances for accounts that have both direct and children
        foreach ($directOnlyAccounts as $name => $balance) {
            if (!isset($hierarchyBalances[$name])) {
                $hierarchyBalances[$name] = SimpleRational::zero();
            }
            $hierarchyBalances[$name] = $hierarchyBalances[$name]->plus($balance);
        }
        
        // Also include any accounts that have direct balances but no children and weren't in leaf
        foreach ($accountBalances as $name => $balance) {
            if (!isset($hierarchyBalances[$name])) {
                $hierarchyBalances[$name] = $balance;
            }
        }
        
        // Apply depth filter
        $displayBalances = [];
        foreach ($hierarchyBalances as $name => $balance) {
            $depth = substr_count($name, ':') + 1;
            if ($depth <= $maxDepth) {
                if ($showEmpty || $balance->sign() != 0) {
                    $displayBalances[$name] = $balance;
                }
            }
        }
        
        // Sort by root account first, then by name
        $sortedNames = array_keys($displayBalances);
        usort($sortedNames, function($a, $b) {
            $partsA = explode(':', $a);
            $partsB = explode(':', $b);
            // First compare by root account
            $rootCmp = strcmp($partsA[0], $partsB[0]);
            if ($rootCmp !== 0) {
                return $rootCmp;
            }
            // Then compare full names
            return strcmp($a, $b);
        });
        
        // Calculate overall total
        $overallBalance = SimpleRational::zero();
        foreach ($balances as $account) {
            $overallBalance = $overallBalance->plus($account->balance);
        }
        
        // Print balances
        foreach ($sortedNames as $name) {
            $balance = $displayBalances[$name];
            $balanceStr = $balance->toFloat(DISPLAY_PRECISION);
            $spaces = $this->options['columns'] - strlen($name) - strlen($balanceStr);
            if ($spaces < 0) $spaces = 0;
            echo $name . str_repeat(' ', $spaces) . $balanceStr . "\n";
        }
        
        if (!empty($sortedNames)) {
            echo str_repeat('-', $this->options['columns']) . "\n";
            $balanceStr = $overallBalance->toFloat(DISPLAY_PRECISION);
            $spaces = $this->options['columns'] - strlen($balanceStr);
            echo str_repeat(' ', $spaces) . $balanceStr . "\n";
        }
    }

    private function handlePrint(array $transactions): void
    {
        foreach ($transactions as $transaction) {
            $inFilter = empty($this->filters);

            if (!$inFilter) {
                foreach ($transaction->accountChanges as $accountChange) {
                    foreach ($this->filters as $filter) {
                        if (strpos($accountChange->name, $filter) !== false) {
                            $inFilter = true;
                            break 2;
                        }
                    }
                }
            }

            if ($inFilter) {
                $this->printTransaction($transaction);
            }
        }
    }

    private function printTransaction($transaction): void
    {
        foreach ($transaction->comments as $comment) {
            echo $comment . "\n";
        }

        echo $transaction->date->format(TRANSACTION_DATE_FORMAT)
             . " " . $transaction->payee . "\n";

        $maxNameLength = 0;
        foreach ($transaction->accountChanges as $accountChange) {
            $nameLength = strlen($accountChange->name);
            if ($nameLength > $maxNameLength) {
                $maxNameLength = $nameLength;
            }
        }

        $availableWidth = $this->options['columns'] - 4;
        $valueWidth = 12;
        $nameColumn = min($maxNameLength + 4, $availableWidth - $valueWidth);

        if ($nameColumn > 50) {
            $nameColumn = 50;
        }

        foreach ($transaction->accountChanges as $accountChange) {
            $balanceStr = $accountChange->balance !== null
                ? $accountChange->balance->toFloat(DISPLAY_PRECISION)
                : '';

            if ($balanceStr !== '') {
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
                if ($totalSpaces < 2) {
                    $totalSpaces = 2;
                }

                echo "    " . $name . str_repeat(' ', $totalSpaces) . $balanceStr . "\n";
            } else {
                echo "    " . $accountChange->name . "\n";
            }
        }

        echo "\n";
    }

    private function handleRegister(array $transactions): void
    {
        if (empty($this->options['period'])) {
            $this->printRegister($transactions);
        } else {
            $ranges = Ledger::transactionsByPeriod($transactions, $this->options['period']);

            foreach ($ranges as $i => $range) {
                if ($i > 0) {
                    echo str_repeat('=', $this->options['columns']) . "\n";
                }
                echo $range->start->format(TRANSACTION_DATE_FORMAT)
                     . " - "
                     . $range->end->format(TRANSACTION_DATE_FORMAT) . "\n";
                echo str_repeat('=', $this->options['columns']) . "\n";
                $this->printRegister($range->transactions);
            }
        }
    }

    private function printRegister(array $transactions): void
    {
        if (empty($transactions)) {
            echo "No transactions in the period.\n";
            return;
        }

        $remainingWidth = $this->options['columns'] - (10 * 3) - 4;
        $col1width = floor($remainingWidth / 3);
        $col2width = $remainingWidth - $col1width;

        $format = sprintf(
            "%%-10.10s %%-%d.%ds %%-%d.%ds %%10.10s %%10.10s\n",
            $col1width,
            $col1width,
            $col2width,
            $col2width
        );

        $runningBalance = SimpleRational::zero();

        foreach ($transactions as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                if ($accountChange->balance === null) {
                    continue;
                }

                $inFilter = empty($this->filters);

                if (!$inFilter) {
                    foreach ($this->filters as $filter) {
                        if (strpos($accountChange->name, $filter) !== false) {
                            $inFilter = true;
                            break;
                        }
                    }
                }

                if ($inFilter) {
                    $runningBalance = $runningBalance->plus($accountChange->balance);
                    $balanceStr = $accountChange->balance->toFloat(DISPLAY_PRECISION);
                    $runningStr = $runningBalance->toFloat(DISPLAY_PRECISION);

                    printf(
                        $format,
                        $transaction->date->format(TRANSACTION_DATE_FORMAT),
                        substr($transaction->payee, 0, $col1width),
                        substr($accountChange->name, 0, $col2width),
                        $balanceStr,
                        $runningStr
                    );
                }
            }
        }
    }

    private function formatDuration(int $days): string
    {
        if ($days === 0) {
            return '0 days';
        }
        
        $years = (int)($days / 365);
        $remainingAfterYears = $days % 365;
        
        $weeks = (int)($remainingAfterYears / 7);
        $remainingDays = $remainingAfterYears % 7;
        
        $parts = [];
        
        if ($years > 0) {
            $parts[] = $years . ' year' . ($years > 1 ? 's' : '');
        }
        
        if ($weeks > 0) {
            $parts[] = $weeks . ' week' . ($weeks > 1 ? 's' : '');
        }
        
        if ($remainingDays > 0) {
            $parts[] = $remainingDays . ' day' . ($remainingDays > 1 ? 's' : '');
        }
        
        return implode(' ', $parts);
    }

    private function handleStats(array $transactions): void
    {
        if (empty($transactions)) {
            echo "Empty ledger.\n";
            return;
        }

        $startDate = $transactions[0]->date;
        $endDate = end($transactions)->date;
        
        $interval = $endDate->diff($startDate);
        $days = $interval->days;
        
        $periodString = $this->formatDuration($days);
        $transPerDay = $days > 0 ? count($transactions) / $days : count($transactions);
        
        $payees = [];
        $accounts = [];
        $postings = 0;
        $lastDate = null;
        
        foreach ($transactions as $transaction) {
            $payees[$transaction->payee] = true;
            foreach ($transaction->accountChanges as $accountChange) {
                $accounts[$accountChange->name] = true;
                $postings++;
            }
            $lastDate = $transaction->date;
        }
        
        $now = new DateTime('now', new DateTimeZone('UTC'));
        
        $lastMidnight = clone $lastDate;
        $lastMidnight->setTime(0, 0, 0);
        $lastMidnight->setTimezone(new DateTimeZone('UTC'));
        
        $intervalSinceLast = $now->diff($lastMidnight);
        $hoursSinceLast = $intervalSinceLast->days * 24 + $intervalSinceLast->h;
        
        if ($intervalSinceLast->i > 0 || $intervalSinceLast->s > 0) {
            $hoursSinceLast += 1;
        }
        
        $daysSinceLast = (int)($hoursSinceLast / 24);
        $timeSinceLastPost = $this->formatDuration($daysSinceLast);
        
        if ($hoursSinceLast === 0) {
            $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
            $currentHourUTC = (int)$nowUTC->format('H');
            
            if ($lastDate->format('Y') > date('Y')) {
                $daysSinceLast = (int)($currentHourUTC / 24);
                $timeSinceLastPost = $this->formatDuration($daysSinceLast);
            } else if ($currentHourUTC >= 16) {
                $daysSinceLast = (int)(16 / 24);
                $timeSinceLastPost = $this->formatDuration($daysSinceLast);
            } else {
                $daysSinceLast = (int)($currentHourUTC / 24);
                $timeSinceLastPost = $this->formatDuration($daysSinceLast);
            }
        }
        
        $postingsPerDay = $days > 0 ? $postings / $days : $postings;
        
        echo "Time period               : " . $startDate->format('Y-m-d') . " to " . $endDate->format('Y-m-d') . " ($periodString)\n";
        echo "Unique payees             : " . count($payees) . "\n";
        echo "Unique accounts           : " . count($accounts) . "\n";
        echo "Number of transactions    : " . count($transactions) . " (" . number_format($transPerDay, 1) . " per day)\n";
        echo "Number of postings        : " . $postings . " (" . number_format($postingsPerDay, 1) . " per day)\n";
        echo "Time since last post      : " . $timeSinceLastPost . "\n";
    }

    private function handleAccounts(array $transactions): void
    {
        $allAccounts = [];

        foreach ($transactions as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                $allAccounts[$accountChange->name] = true;
            }
        }

        ksort($allAccounts);

        echo "Accounts in ledger:\n";
        echo str_repeat('-', $this->options['columns']) . "\n";

        foreach (array_keys($allAccounts) as $account) {
            echo $account . "\n";
        }

        echo str_repeat('-', $this->options['columns']) . "\n";
        printf("Total: %d accounts\n", count($allAccounts));
    }

    private function showUsage(): void
    {
        echo "Ledger CLI in PHP\n";
        echo "=================\n\n";
        echo "Usage: php ledger-cli.php [OPTIONS] COMMAND [FILTERS]\n\n";
        echo "Commands:\n";
        echo "  bal, balance    Account balance summary\n";
        echo "  print           Print formatted ledger\n";
        echo "  reg, register   Filtered register\n";
        echo "  stats           Ledger statistics\n";
        echo "  accounts        List all accounts\n\n";
        echo "Options:\n";
        echo "  -f FILE         Ledger file (*required) or '-' for stdin\n";
        echo "  -b DATE         Start date (default: 1970/01/01)\n";
        echo "  -e DATE         End date (default: today)\n";
        echo "  --period=PERIOD Period (Daily, Weekly, BiWeekly, Monthly, BiMonthly, Quarterly, SemiYearly, Yearly)\n";
        echo "  --payee=STR     Filter by payee\n";
        echo "  --empty         Show zero balance accounts\n";
        echo "  --depth=N       Transaction depth\n";
        echo "  --columns=N     Column width (default: 79)\n";
        echo "  --wide          Wide mode (132 columns)\n";
        echo "  --help          Show this help\n\n";
        echo "Examples:\n";
        echo "  php ledger-cli.php -f Journal.txt bal\n";
        echo "  php ledger-cli.php -f Journal.txt bal Assets\n";
        echo "  php ledger-cli.php -f Journal.txt reg Expenses\n";
        echo "  php ledger-cli.php -f Journal.txt --period=Monthly reg\n";
        echo "  php ledger-cli.php -f Journal.txt stats\n";
        echo "  cat Journal.txt | php ledger-cli.php -f - bal\n";
    }
}

if (PHP_SAPI === 'cli') {
    $cli = new LedgerCLI();
    $cli->run($argv);
}
