#!/usr/bin/env php
<?php

require_once __DIR__ . '/ledger.php';

use LedgerPHP\Ledger;
use LedgerPHP\Parser;
use LedgerPHP\SimpleRational;

define('TRANSACTION_DATE_FORMAT', 'Y/m/d');
define('DISPLAY_PRECISION', 2);

class LimportCLI
{
    private $options = [
        'f' => '',
        'neg' => false,
        'allow-matching' => false,
        'scale' => 1.0,
        'set-search' => 'Expenses',
        'date-format' => 'm/d/Y',
        'delimiter' => ',',
        'columns' => 79,
        'wide' => false,
    ];
    
    private $account;
    private $csvFileName;

    public function run(array $argv): void
    {
        $this->parseArguments($argv);
        
        if (empty($this->options['f']) || empty($this->account) || empty($this->csvFileName)) {
            $this->showUsage();
            exit(1);
        }
        
        if ($this->options['wide']) {
            $this->options['columns'] = 132;
        }
        
        try {
            // Load ledger
            $ledgerContent = file_get_contents($this->options['f']);
            if ($ledgerContent === false) {
                throw new \RuntimeException("Could not read ledger file");
            }
            
            $generalLedger = Parser::parseLedger($ledgerContent);
            
            // FIND DESTINATION ACCOUNT
            $this->account = $this->findDestinationAccount($generalLedger, $this->account);
            if ($this->account === null) {
                throw new \RuntimeException("Unable to find matching account.");
            }
            
            // BUILD BAYESIAN CLASSIFIER (same as Go)
            $classifier = $this->buildBayesianClassifier($generalLedger);
            
            // Process CSV
            $this->processCSV($generalLedger, $classifier);
            
        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    private function findDestinationAccount(array $transactions, string $accountSubstring): ?string
    {
        $allAccounts = [];
        foreach ($transactions as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                $allAccounts[$accountChange->name] = true;
            }
        }
        
        $matchingAccounts = [];
        foreach (array_keys($allAccounts) as $accountName) {
            if (stripos($accountName, $accountSubstring) !== false) {
                $matchingAccounts[] = $accountName;
            }
        }
        
        if (empty($matchingAccounts)) {
            return null;
        }
        
        return $matchingAccounts[count($matchingAccounts) - 1];
    }
    
    private function buildBayesianClassifier(array $transactions): array
    {
        // First, collect all accounts that contain the search string
        $classes = [];
        
        foreach ($transactions as $transaction) {
            foreach ($transaction->accountChanges as $accountChange) {
                if (stripos($accountChange->name, $this->options['set-search']) !== false) {
                    $classes[$accountChange->name] = true;
                }
            }
        }
        
        $classes = array_keys($classes);
        
        // Initialize classifier
        $classifier = [
            'classes' => $classes,
            'datas' => [],
            'learned' => 0
        ];
        
        foreach ($classes as $class) {
            $classifier['datas'][$class] = [
                'freqs' => [],
                'total' => 0
            ];
        }
        
        // Train the classifier - same as Go
        foreach ($transactions as $transaction) {
            $payeeWords = $this->extractWords($transaction->payee);
            
            foreach ($transaction->accountChanges as $accountChange) {
                $accountName = $accountChange->name;
                
                // Only consider accounts that are in the classes
                if (in_array($accountName, $classes)) {
                    foreach ($payeeWords as $word) {
                        if (!isset($classifier['datas'][$accountName]['freqs'][$word])) {
                            $classifier['datas'][$accountName]['freqs'][$word] = 0;
                        }
                        $classifier['datas'][$accountName]['freqs'][$word]++;
                        $classifier['datas'][$accountName]['total']++;
                    }
                    $classifier['learned']++;
                }
            }
        }
        
        return $classifier;
    }
    
    private function extractWords(string $text): array
    {
        // SAME AS GO: simply splits by spaces, keeps all words
        $text = strtolower(trim($text));
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $words ?: [];
    }
    
    private function getWordProb(array $classData, string $word): float
    {
        // SAME AS GO: P(W|C) = (count(W,C) + 1) / (total_words_in_C + vocabulary_size)
        $vocabSize = count($classData['freqs']);
        if ($classData['total'] == 0 || $vocabSize == 0) {
            return 1e-11; // defaultProb from Go
        }
        
        $value = $classData['freqs'][$word] ?? 0;
        return ($value + 1) / ($classData['total'] + $vocabSize);
    }
    
    private function getPriors(array $classifier): array
    {
        // SAME AS GO: P(C_j) = (count_j + 1) / (total + num_classes)
        $n = count($classifier['classes']);
        $priors = [];
        $sum = 0;
        
        foreach ($classifier['classes'] as $index => $class) {
            $total = $classifier['datas'][$class]['total'];
            $priors[$index] = $total;
            $sum += $total;
        }
        
        // Apply Laplace smoothing
        $floatN = (float)$n;
        $floatSum = (float)$sum;
        foreach ($priors as $index => $prior) {
            $priors[$index] = ($prior + 1) / ($floatSum + $floatN);
        }
        
        return $priors;
    }
    
    private function logScores(array $classifier, array $document): array
    {
        // SAME AS GO: classifier.LogScores()
        $n = count($classifier['classes']);
        $scores = array_fill(0, $n, 0.0);
        $priors = $this->getPriors($classifier);
        
        foreach ($classifier['classes'] as $index => $class) {
            $data = $classifier['datas'][$class];
            $score = log($priors[$index]);
            
            foreach ($document as $word) {
                $wordProb = $this->getWordProb($data, $word);
                $score += log($wordProb);
            }
            
            $scores[$index] = $score;
        }
        
        return $scores;
    }
    
    private function findMax(array $scores): array
    {
        // SAME AS GO: findMax function
        $inx = 0;
        $strict = true;
        
        for ($i = 1; $i < count($scores); $i++) {
            if ($scores[$inx] < $scores[$i]) {
                $inx = $i;
                $strict = true;
            } elseif ($scores[$inx] == $scores[$i]) {
                $strict = false;
            }
        }
        
        return ['inx' => $inx, 'strict' => $strict];
    }
    
    private function classifyPayee(array $classifier, string $payee): string
    {
        $words = $this->extractWords($payee);
        
        if (empty($words) || empty($classifier['classes'])) {
            return 'unknown:unknown';
        }
        
        $scores = $this->logScores($classifier, $words);
        $result = $this->findMax($scores);
        
        return $classifier['classes'][$result['inx']];
    }
    
    private function parseArguments(array $argv): void
    {
        $options = [];
        $positionalArgs = [];
        
        $i = 1;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            
            if ($arg === '--help') {
                $this->showUsage();
                exit(0);
            }
            
            if (strpos($arg, '--') === 0) {
                if (strpos($arg, '=') !== false) {
                    $parts = explode('=', substr($arg, 2), 2);
                    $key = $parts[0];
                    $value = $parts[1];
                    $options[$key] = $value;
                } else {
                    $key = substr($arg, 2);
                    if ($i + 1 < count($argv) && strpos($argv[$i + 1], '-') !== 0) {
                        $options[$key] = $argv[$i + 1];
                        $i++;
                    } else {
                        $options[$key] = true;
                    }
                }
            } elseif (strpos($arg, '-') === 0 && strlen($arg) == 2) {
                $key = $arg[1];
                if ($i + 1 < count($argv) && strpos($argv[$i + 1], '-') !== 0) {
                    $options[$key] = $argv[$i + 1];
                    $i++;
                } else {
                    $options[$key] = true;
                }
            } else {
                $positionalArgs[] = $arg;
            }
            
            $i++;
        }
        
        $this->options = array_merge($this->options, $options);
        
        if (count($positionalArgs) >= 2) {
            $this->account = $positionalArgs[0];
            $this->csvFileName = $positionalArgs[1];
        } else {
            echo "Error: Insufficient arguments.\n";
            $this->showUsage();
            exit(1);
        }
    }
    
    private function processCSV(array $generalLedger, array $classifier): void
    {
        if (!file_exists($this->csvFileName)) {
            throw new \RuntimeException("CSV file not found: " . $this->csvFileName);
        }
        
        $csvContent = file_get_contents($this->csvFileName);
        if ($csvContent === false) {
            throw new \RuntimeException("Could not read CSV file");
        }
        
        $lines = explode("\n", trim($csvContent));
        $header = str_getcsv(array_shift($lines), $this->options['delimiter'], '"', '\\');
        
        $dateColumn = $payeeColumn = $amountColumn = $commentColumn = $uuidColumn = $buyerColumn = -1;
        
        foreach ($header as $index => $fieldName) {
            $fieldName = strtolower(trim($fieldName));
            if (strpos($fieldName, 'date') !== false) {
                $dateColumn = $index;
            } elseif (strpos($fieldName, 'description') !== false || strpos($fieldName, 'payee') !== false) {
                $payeeColumn = $index;
            } elseif (strpos($fieldName, 'amount') !== false || strpos($fieldName, 'expense') !== false) {
                $amountColumn = $index;
            } elseif (strpos($fieldName, 'note') !== false) {
                $commentColumn = $index;
            } elseif (strpos($fieldName, 'uuid') !== false) {
                $uuidColumn = $index;
            } elseif (strpos($fieldName, 'buyer') !== false) {
                $buyerColumn = $index;
            }
        }
        
        if ($dateColumn < 0 || $payeeColumn < 0 || $amountColumn < 0) {
            throw new \RuntimeException("Unable to find columns required from header field names.");
        }
        
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            
            $record = str_getcsv($line, $this->options['delimiter'], '"', '\\');
            $record = array_pad($record, max($dateColumn, $payeeColumn, $amountColumn, $commentColumn, $uuidColumn, $buyerColumn) + 1, '');
            
            $dateStr = trim($record[$dateColumn]);
            $date = $this->parseDate($dateStr);
            
            if ($date === false) {
                continue;
            }
            
            $payee = trim($record[$payeeColumn]);
            $payeeWords = explode(' ', $payee);
            
            if (!$this->options['allow-matching'] && $this->existingTransaction($generalLedger, $date, $payeeWords[0] ?? '')) {
                continue;
            }
            
            // Classify using EXACTLY the same algorithm as Go
            $classifiedAccount = $this->classifyPayee($classifier, $payee);
            
            // Parse value
            $amountStr = trim($record[$amountColumn]);
            $amount = $this->parseAmount($amountStr);
            
            if ($amount === null) {
                continue;
            }
            
            $amount *= (float)$this->options['scale'];
            
            // CORREÇÃO: Se --neg estiver ativo, inverte o sinal do amount original
            if ($this->options['neg']) {
                $amount = -$amount;
            }
            
            // Logic from Go
            $csvAmount = -$amount;
            $expenseAmount = -$csvAmount;
            
            // Comments - REMOVE extra space after semicolon
            $comments = [];
            if ($commentColumn >= 0 && trim($record[$commentColumn]) !== '') {
                $comments[] = ';' . trim($record[$commentColumn]); // NO space
            }
            if ($uuidColumn >= 0 && trim($record[$uuidColumn]) !== '') {
                $comments[] = '; UUID: ' . trim($record[$uuidColumn]);
            }
            if ($buyerColumn >= 0 && trim($record[$buyerColumn]) !== '') {
                $comments[] = '; Buyer: ' . trim($record[$buyerColumn]);
            }
            
            // Print transaction
            $this->printTransaction($date, $payee, $this->account, $csvAmount, $classifiedAccount, $expenseAmount, $comments);
        }
    }
    
    private function parseDate(string $dateStr)
    {
        // Remove espaços extras
        $dateStr = trim($dateStr);
        
        // Se temos um formato específico configurado
        if (!empty($this->options['date-format'])) {
            $format = $this->options['date-format'];
            
            // Tentar o formato configurado
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) {
                return $date;
            }
            
            // Tentar variantes com diferentes separadores
            $variants = [
                str_replace('/', '-', $format),
                str_replace('-', '/', $format),
                str_replace('/', '.', $format),
                str_replace('.', '/', $format),
            ];
            
            foreach ($variants as $variant) {
                if ($variant !== $format) {
                    $date = \DateTime::createFromFormat($variant, $dateStr);
                    if ($date !== false) {
                        return $date;
                    }
                }
            }
        }
        
        // Lista mais abrangente de formatos (em ordem de prioridade para Brasil)
        $formats = [
            // Formatos brasileiros primeiro
            'd/m/Y',      // 31/12/2023
            'd-m-Y',      // 31-12-2023  
            'd.m.Y',      // 31.12.2023
            
            // Formatos ISO/internacionais
            'Y-m-d',      // 2023-12-31
            'Y/m/d',      // 2023/12/31
            'Y.m.d',      // 2023.12.31
            
            // Formatos americanos
            'm/d/Y',      // 12/31/2023
            'm-d-Y',      // 12-31-2023
            'm.d.Y',      // 12.31.2023
            
            // Com barras invertidas
            'd\\m\\Y',    // 31\12\2023
            'Y\\m\\d',    // 2023\12\31
            
            // Com hora (ignora a hora)
            'Y-m-d H:i:s',    // 2023-12-31 14:30:00
            'd/m/Y H:i:s',    // 31/12/2023 14:30:00
            'm/d/Y H:i:s',    // 12/31/2023 14:30:00
            'Y-m-d\TH:i:s',   // 2023-12-31T14:30:00 (ISO com T)
            
            // Formatos com dia/mês com 1 dígito
            'j/n/Y',      // 31/12/2023 (sem zero à esquerda)
            'j-n-Y',      // 31-12-2023
            'n/j/Y',      // 12/31/2023 (EUA sem zero)
            
            // Datas apenas numéricas (auto-detectar)
            'Ymd',        // 20231231
            'dmY',        // 31122023
            'mdy',        // 12312023
        ];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) {
                // Verificar se a data é válida (não é falsa como 31/02/2023)
                $errors = \DateTime::getLastErrors();
                // getLastErrors() pode retornar false se não houver erros
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $date;
                }
            }
        }
        
        // Se não funcionou com os formatos acima, tentar detecção inteligente
        return $this->parseDateIntelligent($dateStr);
    }
    
    private function parseAmount(string $amountStr): ?float
    {
        $amountStr = trim($amountStr);
        
        if (preg_match('/^\((.+)\)$/', $amountStr, $matches)) {
            $amountStr = '-' . $matches[1];
        }
        
        $amountStr = preg_replace('/[^\d\.,\-]/', '', $amountStr);
        
        if ($amountStr === '') {
            return 0.0;
        }
        
        $negative = (substr_count($amountStr, '-') % 2) == 1;
        $amountStr = str_replace('-', '', $amountStr);
        
        $amountStr = str_replace(',', '.', $amountStr);
        
        if (!is_numeric($amountStr)) {
            return null;
        }
        
        $amount = (float)$amountStr;
        if ($negative) {
            $amount = -$amount;
        }
        
        return $amount;
    }
    
    private function existingTransaction(array $generalLedger, \DateTime $transDate, string $payee): bool
    {
        foreach ($generalLedger as $trans) {
            if ($trans->date->format('Y-m-d') === $transDate->format('Y-m-d')) {
                if (strpos($trans->payee, $payee) === 0) {
                    return true;
                }
            }
        }
        return false;
    }
    
    private function printTransaction(\DateTime $date, string $payee, string $csvAccount, 
                                     float $csvAmount, string $expenseAccount, 
                                     float $expenseAmount, array $comments): void
    {
        foreach ($comments as $comment) {
            echo $comment . "\n";
        }
        
        echo $date->format(TRANSACTION_DATE_FORMAT) . " " . $payee . "\n";
        
        $this->printAccountLine($csvAccount, $csvAmount);
        $this->printAccountLine($expenseAccount, $expenseAmount);
        
        echo "\n";
    }
    
    private function printAccountLine(string $accountName, float $amount): void
    {
        $formattedValue = number_format($amount, DISPLAY_PRECISION, '.', '');
        
        $indent = str_repeat(' ', 4); // Always 4 spaces indent
        $nameLength = strlen($accountName);
        
        // Calculate available width for name and value
        $availableWidth = $this->options['columns'] - 4; // 4 spaces indent
        $valueWidth = 12; // Width for values (10 digits + 2 spaces)
        
        // If not enough space, truncate account name
        if ($nameLength > $availableWidth - $valueWidth - 2) {
            $maxDisplayLength = $availableWidth - $valueWidth - 5; // Leave space for "..."
            if ($maxDisplayLength > 10) {
                $accountName = substr($accountName, 0, $maxDisplayLength) . '...';
                $nameLength = strlen($accountName);
            }
        }
        
        // Calculate spaces to right align value
        $totalSpaces = $availableWidth - $nameLength - strlen($formattedValue);
        if ($totalSpaces < 2) {
            $totalSpaces = 2;
        }
        
        echo $indent . $accountName . str_repeat(' ', $totalSpaces) . $formattedValue . "\n";
    }
    
    private function showUsage(): void
    {
        echo "Limport - CSV Importer for Ledger (results only)\n";
        echo "=================================================\n\n";
        echo "Usage:\n";
        echo "  php limport.php -f <ledger-file> [options] <account> <csv-file>\n\n";
        echo "Options:\n";
        echo "  -f FILE          Ledger file (*required)\n";
        echo "  --neg            Negate amount column value\n";
        echo "  --allow-matching Include imported transactions that match existing ledger transactions\n";
        echo "  --scale=FACTOR   Scaling factor to multiply each imported value by (default: 1.0)\n";
        echo "  --set-search=STR Search string used to find account set for classification\n";
        echo "  --date-format=STR Date format (default: m/d/Y)\n";
        echo "  --delimiter=STR  Field delimiter (default: ,)\n";
        echo "  --columns=N      Column width (default: 79)\n";
        echo "  --wide           Wide mode (132 columns)\n";
        echo "  --help           Show this help\n\n";
        echo "Examples:\n";
        echo "  php limport.php -f Journal.txt Paypal paypal.csv\n";
        echo "  php limport.php -f Journal.txt --set-search Expenses Paypal paypal.csv\n";
        echo "  php limport.php -f Journal.txt --set-search Utilities Paypal paypal.csv\n";
        echo "  php limport.php -f Journal.txt --columns=132 Paypal paypal.csv\n";
        echo "  php limport.php -f Journal.txt --wide Paypal paypal.csv\n\n";
        echo "This script prints only imported transactions, without debug information.\n";
    }
}

if (PHP_SAPI === 'cli') {
    $cli = new LimportCLI();
    $cli->run($argv);
}
