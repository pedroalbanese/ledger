<?php
// Configuration
$ledgerCliPath = __DIR__ . '/ledger-cli.php';
$limportPath = __DIR__ . '/limport.php'; // NEW: path to limport.php
$defaultFile = '.Journal.txt'; // HIDDEN FILE - CHANGED

// SET TIMEZONE
date_default_timezone_set('America/Sao_Paulo'); // Adjust to your timezone

// ==========================================================
// FILENAME SANITIZATION FUNCTION (NEW)
// ==========================================================

function sanitizeFileName($filename) {
    // Remove path traversal attempts
    $filename = basename($filename);
    // Remove null bytes
    $filename = str_replace(chr(0), '', $filename);
    // Remove dangerous characters, keeping dot for hidden files
    $filename = preg_replace('/[^\w\.\-]/', '', $filename);
    return $filename;
}

// ==========================================================
// LOGIN SYSTEM - ADDED
// ==========================================================

// Set UTF-8 encoding at script start
header('Content-Type: text/html; charset=UTF-8');

// SHA-256 hash of password to access system
// Leave empty to allow access without password
// To generate hash: echo hash('sha256', 'admin123');
$LOGIN_PASSWORD_HASH = '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9'; // Example: '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9'

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if already authenticated
$isAuthenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Function to verify login password
function verifyLoginPassword($inputPassword) {
    global $LOGIN_PASSWORD_HASH;
    
    // If hash is empty, allow access
    if (empty($LOGIN_PASSWORD_HASH)) {
        return true;
    }
    
    $hashedInput = hash('sha256', $inputPassword);
    return hash_equals($LOGIN_PASSWORD_HASH, $hashedInput);
}

// Process login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    $password = $_POST['login_password'] ?? '';
    
    if (verifyLoginPassword($password)) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        $isAuthenticated = true;
        
        // Redirect to avoid form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = "Incorrect password!";
    }
}

// Process logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Check if login screen needs to be shown
// If hash is empty or user already authenticated, allow access
$showLogin = !$isAuthenticated && !empty($LOGIN_PASSWORD_HASH);

// If login needs to be shown, display form and stop execution
if ($showLogin) {
    displayLoginForm();
    exit;
}

// ==========================================================
// DELETE PASSWORD PROTECTION SYSTEM
// ==========================================================

// Password for delete operations (SHA-256 hash)
// Default password: "admin123"
$DELETE_PASSWORD_HASH = '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9';

// Initialize session for security features
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to verify delete password
function verifyDeletePassword($inputPassword) {
    global $DELETE_PASSWORD_HASH;
    $hashedInput = hash('sha256', $inputPassword);
    return hash_equals($DELETE_PASSWORD_HASH, $hashedInput);
}

// Function to check if delete is locked due to failed attempts
function isDeleteLocked() {
    if (!isset($_SESSION['delete_locked_until'])) {
        return false;
    }
    return time() < $_SESSION['delete_locked_until'];
}

// Function to get remaining lock time
function getDeleteLockRemaining() {
    if (!isset($_SESSION['delete_locked_until'])) {
        return 0;
    }
    $remaining = $_SESSION['delete_locked_until'] - time();
    return $remaining > 0 ? $remaining : 0;
}

// Function to handle failed attempt
function recordFailedDeleteAttempt() {
    if (!isset($_SESSION['delete_attempts'])) {
        $_SESSION['delete_attempts'] = 0;
    }
    
    $_SESSION['delete_attempts']++;
    
    // Lock for 5 minutes after 3 failed attempts
    if ($_SESSION['delete_attempts'] >= 3) {
        $_SESSION['delete_locked_until'] = time() + 300; // 5 minutes
        $_SESSION['delete_attempts'] = 0;
        return true; // Locked
    }
    
    return false; // Not locked yet
}

// Function to reset delete attempts on success
function resetDeleteAttempts() {
    $_SESSION['delete_attempts'] = 0;
    unset($_SESSION['delete_locked_until']);
}

// ==========================================================
// UUID GENERATION SYSTEM (DCE 1.1 - Version 1 Time-based)
// ==========================================================

class UUIDGenerator {
    // MAC address for node identification (fake one for privacy)
    private static $node = null;
    
    // Clock sequence
    private static $clock_seq = null;
    
    // Last timestamp to ensure uniqueness
    private static $last_timestamp = 0;
    
    // Initialize MAC address and clock sequence
    private static function init() {
        if (self::$node === null) {
            // Generate a pseudo-random MAC address (48 bits)
            // Using a combination of server IP and random bytes for uniqueness
            $server_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
            $ip_parts = explode('.', $server_ip);
            $ip_long = 0;
            
            foreach ($ip_parts as $part) {
                $ip_long = ($ip_long << 8) | (int)$part;
            }
            
            // Generate 6 bytes for MAC address
            $mac_bytes = [];
            $mac_bytes[0] = 0x02; // Set multicast bit to 0, locally administered bit to 1
            $mac_bytes[1] = mt_rand(0, 255);
            $mac_bytes[2] = mt_rand(0, 255);
            $mac_bytes[3] = ($ip_long >> 16) & 0xFF;
            $mac_bytes[4] = ($ip_long >> 8) & 0xFF;
            $mac_bytes[5] = $ip_long & 0xFF;
            
            self::$node = '';
            foreach ($mac_bytes as $byte) {
                self::$node .= sprintf('%02x', $byte);
            }
        }
        
        if (self::$clock_seq === null) {
            // Initialize clock sequence with random 14-bit number
            self::$clock_seq = mt_rand(0, 16383); // 2^14 - 1
        }
    }
    
    /**
     * Generate a DCE 1.1 (version 1) UUID based on time
     * Format: time_low(8)-time_mid(4)-time_hi_and_version(4)-clock_seq_hi_and_res(2)clock_seq_low(2)-node(12)
     */
    public static function generateUUIDv1() {
        self::init();
        
        // Get current time in 100-nanosecond intervals since 1582-10-15 00:00:00
        $time = self::getTimestamp();
        
        // Ensure uniqueness
        if ($time <= self::$last_timestamp) {
            $time = self::$last_timestamp + 1;
        }
        self::$last_timestamp = $time;
        
        // Split timestamp into parts
        $time_low = sprintf('%08x', $time & 0xFFFFFFFF);
        $time_mid = sprintf('%04x', ($time >> 32) & 0xFFFF);
        $time_hi = sprintf('%04x', ($time >> 48) & 0x0FFF); // 12 bits
        $time_hi = '1' . substr($time_hi, 1); // Version 1 (0001 in binary)
        
        // Clock sequence (14 bits) + variant (2 bits = 10 in binary for DCE 1.1)
        $clock_seq = self::$clock_seq & 0x3FFF;
        $clock_seq_hi = ($clock_seq >> 8) & 0x3F;
        $clock_seq_lo = $clock_seq & 0xFF;
        
        // Set variant bits (10xxxxxx for DCE 1.1)
        $clock_seq_hi = (0x80 | $clock_seq_hi) & 0xBF; // 10xxxxxx
        
        // Format UUID
        $uuid = sprintf('%s-%s-%s-%02x%02x-%s',
            $time_low,
            $time_mid,
            $time_hi,
            $clock_seq_hi,
            $clock_seq_lo,
            self::$node
        );
        
        return strtoupper($uuid);
    }
    
    /**
     * Get timestamp in 100-nanosecond intervals since 1582-10-15
     */
    private static function getTimestamp() {
        // Gregorian epoch: 1582-10-15 00:00:00 UTC
        $gregorian_epoch = -12219292800;
        
        // Current Unix timestamp
        $unix_time = microtime(true);
        
        // Convert to 100-nanosecond intervals
        $uuid_time = ($unix_time - $gregorian_epoch) * 10000000;
        
        return (int)$uuid_time;
    }
    
    /**
     * Generate a shorter, more readable transaction ID
     * Based on timestamp and random component
     */
    public static function generateShortID() {
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        $hash = substr(hash('crc32', $timestamp . $random), 0, 6);
        
        return sprintf('TXN-%s-%s',
            date('Ymd-His', $timestamp),
            strtoupper($hash)
        );
    }
    
    /**
     * Validate if a string is a valid UUID
     */
    public static function isValidUUID($uuid) {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }
    
    /**
     * Extract UUID from transaction text
     */
    public static function extractUUID($transactionText) {
        $lines = explode("\n", $transactionText);
        foreach ($lines as $line) {
            if (strpos($line, 'UUID:') !== false) {
                $parts = explode('UUID:', $line);
                if (count($parts) > 1) {
                    $uuid = trim($parts[1]);
                    if (self::isValidUUID($uuid)) {
                        return $uuid;
                    }
                }
            }
        }
        return null;
    }
}

// Function to display login form
function displayLoginForm() {
    global $loginError;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ledger CLI - Login</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Courier New', monospace;
                background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%);
                height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                color: #ccc;
            }
            
            .login-container {
                background: rgba(20, 20, 30, 0.95);
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.7);
                border: 1px solid rgba(0, 255, 0, 0.2);
                width: 100%;
                max-width: 400px;
                text-align: center;
            }
            
            .login-header {
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 1px solid rgba(0, 255, 0, 0.3);
            }
            
            .login-header h1 {
                color: #0f0;
                font-size: 24px;
                margin-bottom: 10px;
                text-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
            }
            
            .login-header p {
                color: #8f8;
                font-size: 14px;
            }
            
            .login-form {
                display: flex;
                flex-direction: column;
                gap: 20px;
            }
            
            .form-group {
                text-align: left;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 8px;
                color: #0f0;
                font-size: 14px;
                font-weight: bold;
            }
            
            .form-group input {
                width: 100%;
                padding: 12px 15px;
                background: rgba(0, 0, 0, 0.5);
                border: 1px solid rgba(0, 255, 0, 0.3);
                border-radius: 5px;
                color: #0f0;
                font-family: 'Courier New', monospace;
                font-size: 14px;
                transition: all 0.3s;
            }
            
            .form-group input:focus {
                outline: none;
                border-color: #0f0;
                box-shadow: 0 0 15px rgba(0, 255, 0, 0.2);
            }
            
            .login-button {
                background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
                color: white;
                border: none;
                padding: 15px;
                border-radius: 5px;
                font-family: 'Courier New', monospace;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s;
                margin-top: 10px;
            }
            
            .login-button:hover {
                background: linear-gradient(135deg, #34ce57 0%, #28a745 100%);
                box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
                transform: translateY(-2px);
            }
            
            .login-button:active {
                transform: translateY(0);
            }
            
            .error-message {
                background: rgba(220, 53, 69, 0.2);
                color: #ff5555;
                padding: 12px;
                border-radius: 5px;
                border: 1px solid rgba(220, 53, 69, 0.5);
                font-size: 14px;
                display: <?php echo isset($loginError) ? 'block' : 'none'; ?>;
            }
            
            .security-note {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid rgba(0, 255, 0, 0.2);
                font-size: 12px;
                color: #8f8;
                line-height: 1.6;
            }
            
            .security-note strong {
                color: #0f0;
            }
            
            .login-footer {
                margin-top: 30px;
                font-size: 11px;
                color: #666;
            }
            
            @media (max-width: 480px) {
                .login-container {
                    padding: 30px 20px;
                    margin: 20px;
                }
                
                .login-header h1 {
                    font-size: 20px;
                }
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-header">
                <h1>LEDGER CLI TERMINAL</h1>
                <p>Restricted Access</p>
            </div>
            
            <?php if (isset($loginError)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="login_password">ACCESS PASSWORD:</label>
                    <input type="password" 
                           id="login_password" 
                           name="login_password" 
                           placeholder="Enter password"
                           required
                           autofocus>
                </div>
                
                <button type="submit" class="login-button">
                    ENTER SYSTEM
                </button>
            </form>
            
            <div class="security-note">
                <strong>SECURITY INFORMATION:</strong><br>
                * System protected by authentication<br>
                * Each transaction includes unique UUID<br>
                * Journal files are hidden (start with .)<br>
                * Delete function requires additional password<br>
                * CSV import available (second tab)
            </div>
            
            <div class="login-footer">
                Ledger CLI Terminal v1.1 | Financial System | CSV Import
            </div>
        </div>
        
        <script>
            // Auto-focus on password field
            document.getElementById('login_password').focus();
            
            // Allow submit with Enter
            document.getElementById('login_password').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.querySelector('.login-form').submit();
                }
            });
        </script>
    </body>
    </html>
    <?php
}

// ==========================================================

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

// Initial check: if default file doesn't exist, create it
if (!file_exists($defaultFile)) {
    file_put_contents($defaultFile, "; Ledger Journal File\n; Created: " . date('Y/m/d H:i:s') . "\n\n");
    // Try to set restrictive permissions (works on Unix/Linux)
    @chmod($defaultFile, 0600);
}

// NEW: Check if limport.php file exists
if (!file_exists($limportPath)) {
    // Create limport.php file automatically
    $limportContent = '#!/usr/bin/env php
<?php

require_once __DIR__ . \'/ledger.php\';

use LedgerPHP\Ledger;
use LedgerPHP\Parser;
use LedgerPHP\SimpleRational;

define(\'TRANSACTION_DATE_FORMAT\', \'Y/m/d\');
define(\'DISPLAY_PRECISION\', 2);

class LimportCLI
{
    private $options = [
        \'f\' => \'\',
        \'neg\' => false,
        \'allow-matching\' => false,
        \'scale\' => 1.0,
        \'set-search\' => \'Expenses\',
        \'date-format\' => \'m/d/Y\',
        \'delimiter\' => \',\',
        \'columns\' => 79,
        \'wide\' => false,
    ];
    
    private $account;
    private $csvFileName;

    public function run(array $argv): void
    {
        $this->parseArguments($argv);
        
        if (empty($this->options[\'f\']) || empty($this->account) || empty($this->csvFileName)) {
            $this->showUsage();
            exit(1);
        }
        
        if ($this->options[\'wide\']) {
            $this->options[\'columns\'] = 132;
        }
        
        try {
            // Load ledger
            $ledgerContent = file_get_contents($this->options[\'f\']);
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
                if (stripos($accountChange->name, $this->options[\'set-search\']) !== false) {
                    $classes[$accountChange->name] = true;
                }
            }
        }
        
        $classes = array_keys($classes);
        
        // Initialize classifier
        $classifier = [
            \'classes\' => $classes,
            \'datas\' => [],
            \'learned\' => 0
        ];
        
        foreach ($classes as $class) {
            $classifier[\'datas\'][$class] = [
                \'freqs\' => [],
                \'total\' => 0
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
                        if (!isset($classifier[\'datas\'][$accountName][\'freqs\'][$word])) {
                            $classifier[\'datas\'][$accountName][\'freqs\'][$word] = 0;
                        }
                        $classifier[\'datas\'][$accountName][\'freqs\'][$word]++;
                        $classifier[\'datas\'][$accountName][\'total\']++;
                    }
                    $classifier[\'learned\']++;
                }
            }
        }
        
        return $classifier;
    }
    
    private function extractWords(string $text): array
    {
        // SAME AS GO: simply splits by spaces, keeps all words
        $text = strtolower(trim($text));
        $words = preg_split(\'/\\s+/\', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $words ?: [];
    }
    
    private function getWordProb(array $classData, string $word): float
    {
        // SAME AS GO: P(W|C) = (count(W,C) + 1) / (total_words_in_C + vocabulary_size)
        $vocabSize = count($classData[\'freqs\']);
        if ($classData[\'total\'] == 0 || $vocabSize == 0) {
            return 1e-11; // defaultProb from Go
        }
        
        $value = $classData[\'freqs\'][$word] ?? 0;
        return ($value + 1) / ($classData[\'total\'] + $vocabSize);
    }
    
    private function getPriors(array $classifier): array
    {
        // SAME AS GO: P(C_j) = (count_j + 1) / (total + num_classes)
        $n = count($classifier[\'classes\']);
        $priors = [];
        $sum = 0;
        
        foreach ($classifier[\'classes\'] as $index => $class) {
            $total = $classifier[\'datas\'][$class][\'total\'];
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
        $n = count($classifier[\'classes\']);
        $scores = array_fill(0, $n, 0.0);
        $priors = $this->getPriors($classifier);
        
        foreach ($classifier[\'classes\'] as $index => $class) {
            $data = $classifier[\'datas\'][$class];
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
        
        return [\'inx\' => $inx, \'strict\' => $strict];
    }
    
    private function classifyPayee(array $classifier, string $payee): string
    {
        $words = $this->extractWords($payee);
        
        if (empty($words) || empty($classifier[\'classes\'])) {
            return \'unknown:unknown\';
        }
        
        $scores = $this->logScores($classifier, $words);
        $result = $this->findMax($scores);
        
        return $classifier[\'classes\'][$result[\'inx\']];
    }
    
    private function parseArguments(array $argv): void
    {
        $options = [];
        $positionalArgs = [];
        
        $i = 1;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            
            if ($arg === \'--help\') {
                $this->showUsage();
                exit(0);
            }
            
            if (strpos($arg, \'--\') === 0) {
                if (strpos($arg, \'=\') !== false) {
                    $parts = explode(\'=\', substr($arg, 2), 2);
                    $key = $parts[0];
                    $value = $parts[1];
                    $options[$key] = $value;
                } else {
                    $key = substr($arg, 2);
                    if ($i + 1 < count($argv) && strpos($argv[$i + 1], \'-\') !== 0) {
                        $options[$key] = $argv[$i + 1];
                        $i++;
                    } else {
                        $options[$key] = true;
                    }
                }
            } elseif (strpos($arg, \'-\') === 0 && strlen($arg) == 2) {
                $key = $arg[1];
                if ($i + 1 < count($argv) && strpos($argv[$i + 1], \'-\') !== 0) {
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
        $header = str_getcsv(array_shift($lines), $this->options[\'delimiter\']);
        
        $dateColumn = $payeeColumn = $amountColumn = $commentColumn = $uuidColumn = $buyerColumn = -1;
        
        foreach ($header as $index => $fieldName) {
            $fieldName = strtolower(trim($fieldName));
            if (strpos($fieldName, \'date\') !== false) {
                $dateColumn = $index;
            } elseif (strpos($fieldName, \'description\') !== false || strpos($fieldName, \'payee\') !== false) {
                $payeeColumn = $index;
            } elseif (strpos($fieldName, \'amount\') !== false || strpos($fieldName, \'expense\') !== false) {
                $amountColumn = $index;
            } elseif (strpos($fieldName, \'note\') !== false) {
                $commentColumn = $index;
            } elseif (strpos($fieldName, \'uuid\') !== false) {
                $uuidColumn = $index;
            } elseif (strpos($fieldName, \'buyer\') !== false) {
                $buyerColumn = $index;
            }
        }
        
        if ($dateColumn < 0 || $payeeColumn < 0 || $amountColumn < 0) {
            throw new \RuntimeException("Unable to find columns required from header field names.");
        }
        
        foreach ($lines as $line) {
            if (trim($line) === \'\') continue;
            
            $record = str_getcsv($line, $this->options[\'delimiter\']);
            $record = array_pad($record, max($dateColumn, $payeeColumn, $amountColumn, $commentColumn, $uuidColumn, $buyerColumn) + 1, \'\');
            
            $dateStr = trim($record[$dateColumn]);
            $date = $this->parseDate($dateStr);
            
            if ($date === false) {
                continue;
            }
            
            $payee = trim($record[$payeeColumn]);
            $payeeWords = explode(\' \', $payee);
            
            if (!$this->options[\'allow-matching\'] && $this->existingTransaction($generalLedger, $date, $payeeWords[0] ?? \'\')) {
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
            
            $amount *= (float)$this->options[\'scale\'];
            
            // Logic from Go
            $csvAmount = -$amount;
            
            if ($this->options[\'neg\']) {
                $csvAmount = -$csvAmount;
            }
            
            $expenseAmount = -$csvAmount;
            
            // Comments - REMOVE extra space after semicolon
            $comments = [];
            if ($commentColumn >= 0 && trim($record[$commentColumn]) !== \'\') {
                $comments[] = \';\' . trim($record[$commentColumn]); // NO space
            }
            if ($uuidColumn >= 0 && trim($record[$uuidColumn]) !== \'\') {
                $comments[] = \'; UUID: \' . trim($record[$uuidColumn]);
            }
            if ($buyerColumn >= 0 && trim($record[$buyerColumn]) !== \'\') {
                $comments[] = \'; Buyer: \' . trim($record[$buyerColumn]);
            }
            
            // Print transaction
            $this->printTransaction($date, $payee, $this->account, $csvAmount, $classifiedAccount, $expenseAmount, $comments);
        }
    }
    
    private function parseDate(string $dateStr)
    {
        if (!empty($this->options[\'date-format\'])) {
            $date = \DateTime::createFromFormat($this->options[\'date-format\'], $dateStr);
            if ($date !== false) return $date;
        }
        
        $formats = [\'Y-m-d\', \'d/m/Y\', \'d-m-Y\', \'Y/m/d\', \'m/d/Y\', \'m-d-Y\'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) return $date;
        }
        
        return false;
    }
    
    private function parseAmount(string $amountStr): ?float
    {
        $amountStr = trim($amountStr);
        
        if (preg_match(\'/^\\((.+)\\)$/\', $amountStr, $matches)) {
            $amountStr = \'-\' . $matches[1];
        }
        
        $amountStr = preg_replace(\'/[^\\d\\.,\\-]/\', \'\', $amountStr);
        
        if ($amountStr === \'\') {
            return 0.0;
        }
        
        $negative = (substr_count($amountStr, \'-\') % 2) == 1;
        $amountStr = str_replace(\'-\', \'\', $amountStr);
        
        $amountStr = str_replace(\',\', \'.\', $amountStr);
        
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
            if ($trans->date->format(\'Y-m-d\') === $transDate->format(\'Y-m-d\')) {
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
        $formattedValue = number_format($amount, DISPLAY_PRECISION, \'.\', \'\');
        
        $indent = str_repeat(\' \', 4); // Always 4 spaces indent
        $nameLength = strlen($accountName);
        
        // Calculate available width for name and value
        $availableWidth = $this->options[\'columns\'] - 4; // 4 spaces indent
        $valueWidth = 12; // Width for values (10 digits + 2 spaces)
        
        // If not enough space, truncate account name
        if ($nameLength > $availableWidth - $valueWidth - 2) {
            $maxDisplayLength = $availableWidth - $valueWidth - 5; // Leave space for "..."
            if ($maxDisplayLength > 10) {
                $accountName = substr($accountName, 0, $maxDisplayLength) . \'...\';
                $nameLength = strlen($accountName);
            }
        }
        
        // Calculate spaces to right align value
        $totalSpaces = $availableWidth - $nameLength - strlen($formattedValue);
        if ($totalSpaces < 2) {
            $totalSpaces = 2;
        }
        
        echo $indent . $accountName . str_repeat(\' \', $totalSpaces) . $formattedValue . "\n";
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

if (PHP_SAPI === \'cli\') {
    $cli = new LimportCLI();
    $cli->run($argv);
}';
    
    if (file_put_contents($limportPath, $limportContent) !== false) {
        @chmod($limportPath, 0755);
    }
}

// Check if it's an AJAX request
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    // Process AJAX commands
    $currentFile = sanitizeFileName($_POST['ledger_file'] ?? $defaultFile); // SANITIZED
    
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
        $requestedFile = $_POST['ajax_file'] ?? $defaultFile;
        $newFile = sanitizeFileName($requestedFile); // SANITIZED
        $currentFile = $newFile;
        
        // Check if file exists
        if (!file_exists($currentFile)) {
            echo json_encode(['success' => false, 'error' => 'File not found']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'file' => $currentFile,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    if (isset($_POST['ajax_add_transaction'])) {
        $requestedFile = $_POST['ajax_file'] ?? $defaultFile;
        $currentFile = sanitizeFileName($requestedFile); // SANITIZED
        
        // Check if file exists
        if (!file_exists($currentFile)) {
            echo json_encode(['success' => false, 'error' => 'File not found']);
            exit;
        }
        
        $transactionResult = addTransaction($currentFile);
        
        echo json_encode([
            'success' => strpos($transactionResult, 'Error') === false,
            'message' => $transactionResult,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    if (isset($_POST['ajax_delete_last'])) {
        $requestedFile = $_POST['ajax_file'] ?? $defaultFile;
        $currentFile = sanitizeFileName($requestedFile); // SANITIZED
        $password = $_POST['delete_password'] ?? '';
        
        // Check if file exists
        if (!file_exists($currentFile)) {
            echo json_encode(['success' => false, 'error' => 'File not found']);
            exit;
        }
        
        // Check if delete is locked
        if (isDeleteLocked()) {
            $remaining = getDeleteLockRemaining();
            echo json_encode([
                'success' => false,
                'message' => "Too many failed attempts. Try again in {$remaining} seconds.",
                'locked' => true,
                'remaining' => $remaining
            ]);
            exit;
        }
        
        // Check if password was provided
        if (empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Password required for delete operation.',
                'requires_password' => true
            ]);
            exit;
        }
        
        // Verify password
        if (!verifyDeletePassword($password)) {
            // Record failed attempt
            $locked = recordFailedDeleteAttempt();
            
            if ($locked) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Too many failed attempts. Account locked for 5 minutes.',
                    'locked' => true,
                    'remaining' => 300
                ]);
            } else {
                $attempts = $_SESSION['delete_attempts'] ?? 0;
                $remainingAttempts = 3 - $attempts;
                echo json_encode([
                    'success' => false,
                    'message' => "Invalid password. {$remainingAttempts} attempts remaining.",
                    'remaining_attempts' => $remainingAttempts
                ]);
            }
            exit;
        }
        
        // Password is correct, reset attempts and proceed
        resetDeleteAttempts();
        $deleteResult = deleteLastTransaction($currentFile);
        
        echo json_encode([
            'success' => strpos($deleteResult, 'Error') === false,
            'message' => $deleteResult,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    // NEW: Process CSV import via AJAX
    if (isset($_POST['ajax_import_csv'])) {
        $requestedFile = $_POST['ajax_file'] ?? $defaultFile;
        $currentFile = sanitizeFileName($requestedFile); // SANITIZED
        
        // Check if file exists
        if (!file_exists($currentFile)) {
            echo json_encode(['success' => false, 'error' => 'Ledger file not found']);
            exit;
        }
        
        // Check if limport.php exists
        if (!file_exists($limportPath)) {
            echo json_encode(['success' => false, 'error' => 'limport.php not found']);
            exit;
        }
        
        $importResult = importCSV($currentFile, false); // false = full import, not preview
        
        echo json_encode([
            'success' => strpos($importResult, 'Error') === false,
            'message' => $importResult,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    // NEW: Process CSV preview via AJAX
    if (isset($_POST['ajax_preview_csv'])) {
        $requestedFile = $_POST['ajax_file'] ?? $defaultFile;
        $currentFile = sanitizeFileName($requestedFile); // SANITIZED
        
        // Check if file exists
        if (!file_exists($currentFile)) {
            echo json_encode(['success' => false, 'error' => 'Ledger file not found']);
            exit;
        }
        
        // Check if limport.php exists
        if (!file_exists($limportPath)) {
            echo json_encode(['success' => false, 'error' => 'limport.php not found']);
            exit;
        }
        
        $previewResult = importCSV($currentFile, true); // true = preview mode
        
        echo json_encode([
            'success' => true,
            'is_preview' => true,
            'message' => $previewResult,
            'accounts' => getExistingAccounts($currentFile)
        ]);
        exit;
    }
    
    exit;
}

// Process file change (non-AJAX)
$currentFile = $defaultFile;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_file'])) {
    $requestedFile = $_POST['ledger_file'] ?? $defaultFile;
    $currentFile = sanitizeFileName($requestedFile); // SANITIZED
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

// NEW: Process CSV import (non-AJAX)
$importResult = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    $importResult = importCSV($currentFile, false);
}

// NEW: Process CSV preview (non-AJAX)
$previewResult = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_csv'])) {
    $previewResult = importCSV($currentFile, true);
}

// Process last transaction deletion (non-AJAX)
$deleteResult = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_last'])) {
    $password = $_POST['delete_password'] ?? '';
    
    // Check if delete is locked
    if (isDeleteLocked()) {
        $remaining = getDeleteLockRemaining();
        $deleteResult = "Error: Too many failed attempts. Try again in {$remaining} seconds.";
    } elseif (empty($password)) {
        $deleteResult = "Error: Password required for delete operation.";
    } elseif (!verifyDeletePassword($password)) {
        // Record failed attempt
        $locked = recordFailedDeleteAttempt();
        
        if ($locked) {
            $deleteResult = "Error: Too many failed attempts. Account locked for 5 minutes.";
        } else {
            $attempts = $_SESSION['delete_attempts'] ?? 0;
            $remainingAttempts = 3 - $attempts;
            $deleteResult = "Error: Invalid password. {$remainingAttempts} attempts remaining.";
        }
    } else {
        // Password is correct, reset attempts and proceed
        resetDeleteAttempts();
        $deleteResult = deleteLastTransaction($currentFile);
    }
}

// List available files (INCLUDING HIDDEN FILES) - MODIFIED
$availableFiles = [];
$txtFiles = glob(".*.txt");  // Hidden .txt files (NEW)
$txtFiles = array_merge($txtFiles, glob("*.txt"));  // Normal .txt files
$ledgerFiles = glob(".*.ledger");  // Hidden .ledger files (NEW)
$ledgerFiles = array_merge($ledgerFiles, glob("*.ledger"));  // Normal .ledger files
$availableFiles = array_merge($txtFiles, $ledgerFiles);

// Remove duplicates and sort
$availableFiles = array_unique($availableFiles);
sort($availableFiles);

// Load existing accounts
$existingAccounts = getExistingAccounts($currentFile);

function executeLedgerCommand($command, $ledgerFile, $ledgerCliPath) {
    // Sanitize filename
    $ledgerFile = sanitizeFileName($ledgerFile);
    
    if (!file_exists($ledgerFile)) {
        return "Error: File '$ledgerFile' not found on server.";
    }
    
    if (!file_exists($ledgerCliPath)) {
        return "Error: ledger-cli.php not found at: $ledgerCliPath";
    }

    // BLOCK DANGEROUS CHARACTERS < | > (NEW)
    if (strpos($command, '>') !== false || strpos($command, '<') !== false || strpos($command, '|') !== false) {
        return "Error: Dangerous characters (<, >, |) are not allowed in commands.";
    }
    
    // BLOCK -f FLAG TO PREVENT READING OTHER FILES (CORRECTED)
    // Detect -f at beginning or after space, followed by space or end of string
    if (preg_match('/(?:^|\s)-f\b/', $command)) {
        return "Error: The -f flag is not allowed. You can only use the current journal file.";
    }
    
    // BLOCK PATH TRAVERSAL ATTEMPTS
    if (preg_match('/\.\./', $command)) {
        return "Error: Path traversal (..) is not allowed in commands.";
    }
    
    // BLOCK COMMANDS WITH EXPLICIT FILES (CORRECTED)
    // Detect files as arguments (ex: bal /etc/passwd)
    if (preg_match('/\s+(?:[\'"]?)(?:\.{0,2}\/|[a-zA-Z]:\\\\|[\/\\\])/', $command)) {
        return "Error: File path references are not allowed in commands.";
    }
    
    // Check if it's a mobile device
    $isMobile = isMobileDevice();
    
    // Prepare base command - FILE IS ADDED AUTOMATICALLY
    $fullCommand = "php " . escapeshellarg($ledgerCliPath) . 
                  " -f " . escapeshellarg($ledgerFile);
    
    // CORRECTION HERE: Add --columns=58 for mobile if --columns is not in command
    if ($isMobile && strpos($command, '--columns') === false) {
        $fullCommand .= " --columns=58";
    }
    
    // Add user command (after sanitization)
    $fullCommand .= " " . $command . " 2>&1";
    
    // Execute command
    $output = [];
    $returnCode = 0;
    exec($fullCommand, $output, $returnCode);
    
    return implode("\n", $output);
}

function executeLimport($ledgerFile, $csvFile, $account, $setSearch, $options = [], $isPreview = false) {
    global $limportPath;
    
    if (!file_exists($limportPath)) {
        return "Error: limport.php not found at: $limportPath";
    }
    
    if (!file_exists($ledgerFile)) {
        return "Error: Ledger file '$ledgerFile' not found.";
    }
    
    if (!file_exists($csvFile)) {
        return "Error: CSV file '$csvFile' not found.";
    }
    
    // DEBUG: Log das opções
    error_log("Limport options received: " . print_r($options, true));
    
    // Construir comando na ORDEM CORRETA
    $command = 'php ' . escapeshellarg($limportPath);
    
    // 1. Primeiro: flag -f obrigatória
    $command .= ' -f ' . escapeshellarg($ledgerFile);
    
    // 2. Depois: --set-search obrigatório
    if (empty($setSearch)) {
        $setSearch = 'Expenses';
    }
    $command .= ' --set-search=' . escapeshellarg($setSearch);
    
    // 3. Flags booleanas (--neg, --allow-matching, --wide) - CORREÇÃO AQUI
    // Verificar se a opção existe e é verdadeira, EXATAMENTE como --wide
    if (isset($options['neg']) && $options['neg'] === true) {
        $command .= ' --neg';
        error_log("Adding --neg flag to command");
    }
    
    if (isset($options['allow_matching']) && $options['allow_matching'] === true) {
        $command .= ' --allow-matching';
        error_log("Adding --allow-matching flag to command");
    }
    
    if (isset($options['wide']) && $options['wide'] === true) {
        $command .= ' --wide';
        error_log("Adding --wide flag to command");
    }
    
    // 4. Opções com valores (apenas se não for padrão)
    if (isset($options['scale']) && (float)$options['scale'] != 1.0) {
        $command .= ' --scale=' . escapeshellarg((string)$options['scale']);
    }
    
    if (isset($options['date_format']) && !empty($options['date_format']) && $options['date_format'] != 'm/d/Y') {
        $command .= ' --date-format=' . escapeshellarg($options['date_format']);
    }
    
    if (isset($options['delimiter']) && !empty($options['delimiter']) && $options['delimiter'] != ',') {
        $command .= ' --delimiter=' . escapeshellarg($options['delimiter']);
    }
    
    // 5. Argumentos posicionais DEVEM ser os ÚLTIMOS: <account> <csv-file>
    $command .= ' ' . escapeshellarg($account);
    $command .= ' ' . escapeshellarg($csvFile);
    
    $command .= ' 2>&1';
    
    // DEBUG: Log do comando
    error_log("Limport command: " . $command);
    
    // Executar
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    $result = implode("\n", $output);
    
    // DEBUG: Log do resultado
    error_log("Limport output (first 500 chars): " . substr($result, 0, 500));
    
    // Se houver erro de "Insufficient arguments", tente abordagem alternativa
    if (strpos($result, 'Insufficient arguments') !== false || 
        strpos($result, 'Error:') === 0) {
        
        // Tentar abordagem alternativa: colocar --set-search como espaço separado
        $altCommand = 'php ' . escapeshellarg($limportPath);
        $altCommand .= ' -f ' . escapeshellarg($ledgerFile);
        
        // --neg se necessário - CORREÇÃO AQUI TAMBÉM
        if (isset($options['neg']) && $options['neg'] === true) {
            $altCommand .= ' --neg';
        }
        
        // --allow-matching se necessário
        if (isset($options['allow_matching']) && $options['allow_matching'] === true) {
            $altCommand .= ' --allow-matching';
        }
        
        // --wide se necessário
        if (isset($options['wide']) && $options['wide'] === true) {
            $altCommand .= ' --wide';
        }
        
        // --set-search com espaço (não com =)
        $altCommand .= ' --set-search ' . escapeshellarg($setSearch);
        
        // Opções com valores
        if (isset($options['scale']) && (float)$options['scale'] != 1.0) {
            $altCommand .= ' --scale=' . escapeshellarg((string)$options['scale']);
        }
        
        if (isset($options['date_format']) && !empty($options['date_format']) && $options['date_format'] != 'm/d/Y') {
            $altCommand .= ' --date-format=' . escapeshellarg($options['date_format']);
        }
        
        if (isset($options['delimiter']) && !empty($options['delimiter']) && $options['delimiter'] != ',') {
            $altCommand .= ' --delimiter=' . escapeshellarg($options['delimiter']);
        }
        
        // Argumentos posicionais (CONTA e ARQUIVO CSV) - ÚLTIMOS!
        $altCommand .= ' ' . escapeshellarg($account);
        $altCommand .= ' ' . escapeshellarg($csvFile);
        
        $altCommand .= ' 2>&1';
        
        error_log("Limport alt command: " . $altCommand);
        
        $altOutput = [];
        exec($altCommand, $altOutput, $altReturnCode);
        
        $result = implode("\n", $altOutput);
    }
    
    // Modo preview
    if ($isPreview) {
        $previewHeader = "=== CSV IMPORT PREVIEW ===\n";
        $previewHeader .= "Ledger file: " . basename($ledgerFile) . "\n";
        $previewHeader .= "CSV file: " . basename($csvFile) . "\n";
        $previewHeader .= "Destination account: " . $account . "\n";
        $previewHeader .= "Set search: " . $setSearch . "\n";
        $previewHeader .= "Negate amounts: " . (isset($options['neg']) && $options['neg'] ? 'YES' : 'NO') . "\n";
        $previewHeader .= "Allow matching: " . (isset($options['allow_matching']) && $options['allow_matching'] ? 'YES' : 'NO') . "\n";
        $previewHeader .= "Scale factor: " . ($options['scale'] ?? 1.0) . "\n";
        $previewHeader .= "Date format: " . ($options['date_format'] ?? 'm/d/Y') . "\n";
        $previewHeader .= "Delimiter: " . ($options['delimiter'] ?? ',') . "\n";
        $previewHeader .= "Wide mode: " . (isset($options['wide']) && $options['wide'] ? 'YES' : 'NO') . "\n";
        $previewHeader .= "========================================\n\n";
        
        $result = $previewHeader . $result;
        
        $result .= "\n\n=== PREVIEW MODE ===\n";
        $result .= "This is a preview. No changes have been made to your ledger file.\n";
        $result .= "Click 'Import CSV' to actually import these transactions.";
    }
    
    return $result;
}

// NEW: Function to import CSV (with preview option)
function importCSV($ledgerFile, $isPreview = false) {
    global $limportPath;
    
    // Sanitize filename
    $ledgerFile = sanitizeFileName($ledgerFile);
    
    if (!file_exists($ledgerFile)) {
        return "Error: Ledger file '$ledgerFile' not found.";
    }
    
    if (!file_exists($limportPath)) {
        return "Error: limport.php not found.";
    }
    
    // Check if a file was uploaded
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        return "Error: No CSV file uploaded or upload error.";
    }
    
    $uploadedFile = $_FILES['csv_file'];
    
    // Validate file type
    $allowedTypes = ['text/csv', 'text/plain', 'application/vnd.ms-excel'];
    $fileType = mime_content_type($uploadedFile['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes) && 
        pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'csv') {
        return "Error: Only CSV files are allowed.";
    }
    
    // Validate file size (max 10MB)
    if ($uploadedFile['size'] > 10 * 1024 * 1024) {
        return "Error: File size exceeds 10MB limit.";
    }
    
    // Move file to temporary directory
    $tempDir = sys_get_temp_dir();
    $tempFile = tempnam($tempDir, 'csv_upload_');
    
    if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFile)) {
        return "Error: Failed to process uploaded file.";
    }
    
    // Get form parameters
    $account = trim($_POST['csv_account'] ?? '');
    $setSearch = trim($_POST['csv_set_search'] ?? 'Expenses');
    
    if (empty($account)) {
        unlink($tempFile);
        return "Error: Account is required for CSV import.";
    }
    
    // Prepare options - CORREÇÃO CRÍTICA AQUI
    // Processar checkboxes da mesma forma que --wide
    $options = [
        'neg' => isset($_POST['csv_neg']) && ($_POST['csv_neg'] == '1' || $_POST['csv_neg'] == 'on'),
        'allow_matching' => isset($_POST['csv_allow_matching']) && ($_POST['csv_allow_matching'] == '1' || $_POST['csv_allow_matching'] == 'on'),
        'scale' => floatval($_POST['csv_scale'] ?? 1.0),
        'date_format' => $_POST['csv_date_format'] ?? 'm/d/Y',
        'delimiter' => $_POST['csv_delimiter'] ?? ',',
        'wide' => isset($_POST['csv_wide']) && ($_POST['csv_wide'] == '1' || $_POST['csv_wide'] == 'on')
    ];
    
    // Execute limport
    $output = executeLimport($ledgerFile, $tempFile, $account, $setSearch, $options, $isPreview);
    
    // Clean up temporary file
    unlink($tempFile);
    
    // Check if output contains errors
    if (strpos($output, 'Error:') === 0 || strpos($output, 'Erro:') === 0) {
        return $output;
    }
    
    // If preview mode, just return the output
    if ($isPreview) {
        return $output;
    }
    
    // If no error and not preview, check if transactions were imported
    if (strpos($output, '202') === false && strpos($output, '20') === false) {
        return "No transactions were imported. Possible reasons:\n" .
               "- CSV format may be incorrect\n" .
               "- No matching data found\n" .
               "- All transactions already exist in ledger (if --allow-matching not enabled)\n\n" .
               "CSV Output:\n" . $output;
    }
    
    // ==========================================================
    // CORREÇÃO COMPLETA DA FORMATAÇÃO DO ARQUIVO
    // ==========================================================
    
    // 1. Ler conteúdo atual do arquivo ledger
    $content = file_get_contents($ledgerFile);
    
    // 2. Remover TODAS as quebras de linha extras no final
    $content = rtrim($content);
    
    // 3. Extrair transações do output (remover cabeçalho de preview se existir)
    $transactionsOutput = $output;
    if (strpos($output, '=== CSV IMPORT PREVIEW ===') !== false) {
        $lines = explode("\n", $output);
        $start = 0;
        foreach ($lines as $i => $line) {
            if (strpos($line, '========================================') !== false) {
                $start = $i + 1;
                break;
            }
        }
        $transactionsOutput = implode("\n", array_slice($lines, $start));
        
        // Remover nota do modo preview no final
        $transactionsOutput = preg_replace('/\n=== PREVIEW MODE ===.*/s', '', $transactionsOutput);
    }
    
    // 4. Limpar o output das transações
    $transactionsOutput = trim($transactionsOutput);
    
    // Se não há transações para importar, retornar
    if (empty($transactionsOutput)) {
        return "No transactions were imported from CSV.";
    }
    
    // 5. PROCESSAMENTO CRÍTICO: Garantir formatação correta das transações
    // Cada transação deve terminar com \n\n (duas quebras de linha)
    // O arquivo final deve terminar com \n\n\n (três quebras de linha)
    
    // Dividir por linhas
    $lines = explode("\n", $transactionsOutput);
    $formattedLines = [];
    $inTransaction = false;
    $transactionLines = [];
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // Se é início de nova transação (linha com data YYYY/MM/DD)
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}\s/', $line)) {
            // Se já estávamos em uma transação, processar a transação anterior
            if ($inTransaction && !empty($transactionLines)) {
                // Adicionar transação formatada
                foreach ($transactionLines as $transLine) {
                    $formattedLines[] = $transLine;
                }
                // Adicionar linha em branco após a transação (\n\n)
                $formattedLines[] = '';
                $transactionLines = [];
            }
            
            // Iniciar nova transação
            $inTransaction = true;
            $transactionLines[] = $line;
        }
        // Se é linha dentro de uma transação (comentário ou conta)
        elseif ($inTransaction) {
            // Linha vazia dentro de transação - ignorar (não deve ter)
            if ($trimmedLine === '') {
                continue;
            }
            $transactionLines[] = $line;
        }
        // Linha fora de transação (não deveria acontecer)
        else {
            $formattedLines[] = $line;
        }
    }
    
    // Processar última transação
    if ($inTransaction && !empty($transactionLines)) {
        foreach ($transactionLines as $transLine) {
            $formattedLines[] = $transLine;
        }
        // Adicionar linha em branco após a última transação (\n\n)
        $formattedLines[] = '';
    }
    
    // Remover última linha em branco se for a única (será adicionada depois)
    if (end($formattedLines) === '' && count($formattedLines) > 1) {
        array_pop($formattedLines);
    }
    
    // Reconstruir transactionsOutput formatado
    $transactionsOutput = implode("\n", $formattedLines);
    
    // 6. PREPARAR CONTEÚDO FINAL DO ARQUIVO
    
    // Se o arquivo original tem conteúdo
    if (!empty(trim($content))) {
        // Remover quebras de linha extras no final
        $content = rtrim($content);
        
        // Verificar o final do conteúdo atual
        $contentLength = strlen($content);
        $lastChars = '';
        if ($contentLength >= 3) {
            $lastChars = substr($content, -3);
        }
        
        // Se o conteúdo já termina com \n\n\n, manter
        if ($lastChars === "\n\n\n") {
            // Já está correto, não fazer nada
        }
        // Se termina com \n\n, adicionar mais um \n
        elseif (substr($content, -2) === "\n\n") {
            $content .= "\n";
        }
        // Se termina com \n, adicionar mais dois \n
        elseif (substr($content, -1) === "\n") {
            $content .= "\n\n";
        }
        // Se não termina com \n, adicionar três \n
        else {
            $content .= "\n\n\n";
        }
    }
    
    // 7. ADICIONAR NOVAS TRANSAÇÕES
    
    // Se transactionsOutput não está vazio
    if (!empty(trim($transactionsOutput))) {
        // Se já há conteúdo e não termina com \n\n, ajustar
        if (!empty(trim($content)) && substr(rtrim($content), -2) !== "\n\n") {
            $content = rtrim($content);
            if (substr($content, -1) === "\n") {
                $content .= "\n";
            } else {
                $content .= "\n\n";
            }
        }
        
        // Adicionar transações
        $content .= $transactionsOutput;
    }
    
    // 8. GARANTIR FORMATAÇÃO FINAL DO ARQUIVO
    
    // Remover espaços em branco extras no final
    $content = rtrim($content);
    
    // Garantir que o arquivo termine com \n\n\n (três quebras de linha)
    // Isso significa 2 linhas em branco no final
    $content .= "\n\n\n";
    
    // 9. SALVAR ARQUIVO
    
    if (file_put_contents($ledgerFile, $content, LOCK_EX) !== false) {
        // Tentar definir permissões restritivas
        @chmod($ledgerFile, 0600);
        
        // Contar transações importadas
        $transactionCount = 0;
        $lines = explode("\n", $transactionsOutput);
        foreach ($lines as $line) {
            if (preg_match('/^\d{4}\/\d{2}\/\d{2}\s/', $line)) {
                $transactionCount++;
            }
        }
        
        return "Successfully imported {$transactionCount} transactions from CSV!\n" .
               "Format: Each transaction separated by one blank line (\n\n)\n" .
               "File ends with two blank lines (\n\n\n)";
    } else {
        return "Error: Could not save imported transactions to ledger file.\n\n" .
               "Generated transactions:\n" . $transactionsOutput;
    }
}

function getExistingAccounts($ledgerFile) {
    // Sanitize filename
    $ledgerFile = sanitizeFileName($ledgerFile);
    
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
    // Sanitize filename
    $ledgerFile = sanitizeFileName($ledgerFile);
    
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
    
    // Generate UUID for this transaction
    $uuid = UUIDGenerator::generateUUIDv1();
    $shortId = UUIDGenerator::generateShortID();
    
    // Build transaction with proper formatting
    $transaction = "";
    
    // Add UUID and note as comments
    if (!empty($comment)) {
        $transaction .= "; Note: " . $comment . "\n";
    }
    $transaction .= "; UUID: " . $uuid . "\n";
    $transaction .= "; Transaction ID: " . $shortId . "\n";
    
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
    if (file_put_contents($ledgerFile, $content, LOCK_EX) !== false) {
        // Try to set restrictive permissions after saving
        @chmod($ledgerFile, 0600);
        return "Transaction added successfully! (ID: $shortId)";
    } else {
        return "Error: Could not save transaction to file.";
    }
}

function deleteLastTransaction($ledgerFile) {
    // Sanitize filename
    $ledgerFile = sanitizeFileName($ledgerFile);
    
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
            
            // Check for comments before (UUID lines, notes, etc.)
            for ($j = $i - 1; $j >= 0; $j--) {
                $prevLine = trim($lines[$j]);
                if (strpos($prevLine, ';') === 0) {
                    // It's a comment (could be UUID, note, etc.)
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
    
    // Try to extract UUID before deleting for logging
    $transactionText = implode("\n", array_slice($lines, $lastTransactionStart, $lastTransactionEnd - $lastTransactionStart));
    $uuid = UUIDGenerator::extractUUID($transactionText);
    
    // Remove transaction
    $newLines = [];
    for ($i = 0; $i < $totalLines; $i++) {
        if ($i < $lastTransactionStart || $i >= $lastTransactionEnd) {
            $newLines[] = $lines[$i];
        }
    }
    
    // Rebuild content
    $newContent = implode("\n", $newLines);
    
    // **CRITICAL CORRECTION:**
    // 1. Remove all trailing whitespace
    $newContent = rtrim($newContent);
    
    // 2. If there's still content, add two line breaks
    if (!empty($newContent)) {
        $newContent .= "\n\n\n";
    }
    
    // Save back
    if (file_put_contents($ledgerFile, $newContent, LOCK_EX) !== false) {
        // Try to set restrictive permissions after saving
        @chmod($ledgerFile, 0600);
        $uuidInfo = $uuid ? " (UUID: " . substr($uuid, 0, 8) . "...)" : "";
        return "Last transaction removed successfully!$uuidInfo";
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
        /* Add logout button styles */
        .logout-button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            transition: background 0.2s;
            margin-left: 10px;
            text-decoration: none;
            display: inline-block;
        }
        
        .logout-button:hover {
            background: #5a6268;
        }
        
        .login-info {
            color: #28a745;
            font-size: 11px;
            margin-left: 10px;
        }
        
        .login-status {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: auto; /* This pushes content to the right */
        }
        
        /* Style for terminal header container */
        .terminal-header-content {
            display: flex;
            width: 100%;
            justify-content: space-between;
            align-items: center;
        }
        
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
        
        /* NEW: Styles for tabs */
        .tab-container {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .tab-buttons {
            display: flex;
            background: #222;
            border-bottom: 1px solid #333;
        }
        
        .tab-button {
            flex: 1;
            background: #333;
            color: #ccc;
            border: none;
            padding: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border-right: 1px solid #444;
        }
        
        .tab-button:last-child {
            border-right: none;
        }
        
        .tab-button:hover {
            background: #444;
        }
        
        .tab-button.active {
            background: #222;
            color: #0f0;
            font-weight: bold;
            border-bottom: 2px solid #0f0;
        }
        
        .tab-content {
            flex: 1;
            display: none;
            overflow-y: auto;
        }
        
        .tab-content.active {
            display: flex;
            flex-direction: column;
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
            white-space: nowrap;
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
        
        .btn-warning {
            background: #e67e22;
        }
        
        .btn-warning:hover {
            background: #d35400;
        }
        
        .btn-info {
            background: #17a2b8;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .current-file {
            color: #0f0;
            font-size: 12px;
            font-weight: bold;
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
            gap: 10px;
            flex-wrap: nowrap;
        }
        
        .prompt {
            color: #0f0;
            font-weight: bold;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        input[type="text"] {
            background: transparent;
            border: none;
            color: #0f0;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            outline: none;
            padding: 5px;
            flex: 1;
            min-width: 0;
        }
        
        .command-output {
            margin-bottom: 15px;
        }
        
        .command-line {
            color: #0f0;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        /* IMPORTANT: STYLE FOR TERMINAL OUTPUT WITH FIXED SIZE AND LINE BREAK */
        .command-result {
            color: #0f0;
            white-space: pre-wrap;
            word-wrap: break-word;
            word-break: break-all;
            overflow-wrap: break-word;
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
        .form-group textarea,
        .form-group input[type="number"],
        .form-group input[type="file"],
        .form-group select {
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
        .form-group textarea:focus,
        .form-group input[type="number"]:focus,
        .form-group input[type="file"]:focus,
        .form-group select:focus {
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
        .autocomplete-list::-webkit-scrollbar,
        .tab-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .terminal-body::-webkit-scrollbar-track,
        .transaction-body::-webkit-scrollbar-track,
        .entries-container::-webkit-scrollbar-track,
        .autocomplete-list::-webkit-scrollbar-track,
        .tab-content::-webkit-scrollbar-track {
            background: #1a1a1a;
        }
        
        .terminal-body::-webkit-scrollbar-thumb,
        .transaction-body::-webkit-scrollbar-thumb,
        .entries-container::-webkit-scrollbar-thumb,
        .autocomplete-list::-webkit-scrollbar-thumb,
        .tab-content::-webkit-scrollbar-thumb {
            background: #444;
            border-radius: 4px;
        }
        
        .terminal-body::-webkit-scrollbar-thumb:hover,
        .transaction-body::-webkit-scrollbar-thumb:hover,
        .entries-container::-webkit-scrollbar-thumb:hover,
        .autocomplete-list::-webkit-scrollbar-thumb:hover,
        .tab-content::-webkit-scrollbar-thumb:hover {
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
            display: none;
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
            
            .terminal-input {
                padding: 8px 10px;
                gap: 5px;
            }
            
            .terminal-input input[type="text"] {
                font-size: 11px;
                min-width: 0;
                flex: 1;
            }
            
            .btn {
                padding: 4px 8px;
                font-size: 11px;
                flex-shrink: 0;
            }
            
            .tab-button {
                padding: 8px 5px;
                font-size: 10px;
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
            
            .terminal-input {
                padding: 8px 10px;
                gap: 5px;
            }
            
            .terminal-input input[type="text"] {
                font-size: 11px;
                min-width: 0;
                flex: 1;
            }
            
            .btn {
                padding: 4px 8px;
                font-size: 11px;
                flex-shrink: 0;
            }
            
            .tab-button {
                padding: 8px;
                font-size: 11px;
            }
        }
        
        /* Allow scrolling on mobile devices */
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
        
        /* Container with fixed width for terminal */
        .terminal-content {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        /* For very long lines with continuous characters */
        .break-long-lines {
            overflow-wrap: anywhere !important;
            word-break: break-all !important;
        }
        
        /* Delete password modal styles for dark theme */
        #deletePasswordModal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 10000;
            display: none;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: #222;
            padding: 30px;
            border-radius: 8px;
            width: 300px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            border: 1px solid #444;
        }
        
        .modal-title {
            color: #ff5555;
            margin-bottom: 20px;
            text-align: center;
            font-size: 18px;
        }
        
        .modal-message {
            margin-bottom: 20px;
            color: #ccc;
            text-align: center;
            line-height: 1.5;
        }
        
        .modal-input {
            width: 100%;
            padding: 10px;
            background: #333;
            border: 1px solid #555;
            color: #ccc;
            border-radius: 4px;
            font-size: 14px;
            margin-bottom: 10px;
            font-family: 'Courier New', monospace;
        }
        
        .modal-input:focus {
            outline: none;
            border-color: #ff5555;
            box-shadow: 0 0 0 2px rgba(255, 85, 85, 0.1);
        }
        
        .modal-error {
            color: #ff5555;
            font-size: 12px;
            margin-bottom: 15px;
            text-align: center;
            display: none;
        }
        
        .modal-buttons {
            display: flex;
            gap: 10px;
        }
        
        .modal-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        .modal-btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .modal-btn-cancel:hover {
            background: #5a6268;
        }
        
        .modal-btn-confirm {
            background: #dc3545;
            color: white;
        }
        
        .modal-btn-confirm:hover {
            background: #c82333;
        }
        
        .modal-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .locked-message {
            background: rgba(255, 243, 205, 0.1);
            color: #ffd700;
            border: 1px solid rgba(255, 234, 167, 0.3);
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 12px;
            text-align: center;
        }
        
        .lock-timer {
            text-align: center;
            font-size: 24px;
            color: #ff5555;
            margin: 20px 0;
            font-family: monospace;
        }
        
        .security-note {
            color: #ff5555;
            font-size: 11px;
            font-weight: bold;
        }
        
        .uuid-info {
            color: #00aaff;
            font-size: 10px;
            font-style: italic;
            margin-top: 5px;
        }
        
        .hidden-file-note {
            color: #ffaa00;
            font-size: 10px;
            font-style: italic;
            margin-top: 3px;
        }
        
        /* NEW: Styles for CSV advanced options */
        .advanced-options {
            background: #222;
            border: 1px solid #444;
            border-radius: 3px;
            padding: 10px;
            margin-top: 10px;
        }
        
        .advanced-options h4 {
            color: #0f0;
            margin-bottom: 10px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .advanced-options h4:after {
            content: '▼';
            font-size: 10px;
            transition: transform 0.2s;
        }
        
        .advanced-options.collapsed h4:after {
            transform: rotate(-90deg);
        }
        
        .advanced-options.collapsed .options-content {
            display: none;
        }
        
        .options-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .option-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .option-group label {
            color: #8f8;
            font-size: 11px;
        }
        
        .option-group input[type="checkbox"] {
            margin-right: 5px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .checkbox-group label {
            color: #8f8;
            font-size: 11px;
        }
        
        /* NEW: Style for CSV example */
        .csv-example {
            background: #222;
            border: 1px solid #444;
            border-radius: 3px;
            padding: 10px;
            margin-top: 15px;
            font-size: 11px;
        }
        
        .csv-example h4 {
            color: #0f0;
            margin-bottom: 8px;
            font-size: 11px;
        }
        
        .csv-example pre {
            color: #8f8;
            background: #111;
            padding: 8px;
            border-radius: 3px;
            overflow-x: auto;
            font-size: 10px;
        }
        
        .csv-example code {
            font-family: 'Courier New', monospace;
        }
        
        .preview-indicator {
            background: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
            font-weight: bold;
        }
        
        /* CORREÇÃO PARA DROPDOWN LONGO */
        #csv_date_format, #csv_delimiter {
            width: 100%;
            max-width: 200px;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            /* Ajuste para dropdowns em dispositivos móveis */
            #csv_date_format, #csv_delimiter,
            #csv_scale {
                max-width: 100%;
                width: 100%;
            }
            
            /* Força uma coluna em mobile */
            .options-content {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            /* Garante que grupos ocupem toda largura */
            .option-group {
                width: 100%;
            }
            
            /* Ajusta tamanho da fonte para mobile */
            .option-group select,
            .option-group input[type="number"] {
                font-size: 12px;
                padding: 6px 8px;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile indicator (optional, for debug) -->
    <div class="mobile-indicator" id="mobileIndicator">
        <?php echo isMobileDevice() ? 'MOBILE' : 'DESKTOP'; ?>
    </div>
    
    <!-- Delete Password Modal -->
    <div id="deletePasswordModal">
        <div class="modal-content">
            <h3 class="modal-title">Confirm Delete</h3>
            <p class="modal-message">
                Enter password to delete last transaction<br>
                <small style="font-size: 11px; color: #999;">Default password: admin123</small>
            </p>
            <input type="password" 
                   id="deletePasswordInput" 
                   class="modal-input" 
                   placeholder="Enter delete password">
            <div id="deletePasswordError" class="modal-error"></div>
            <div class="modal-buttons">
                <button type="button" 
                        class="modal-btn modal-btn-cancel" 
                        id="cancelDeleteBtn">
                    Cancel
                </button>
                <button type="button" 
                        class="modal-btn modal-btn-confirm" 
                        id="confirmDeleteBtn">
                    Delete
                </button>
            </div>
        </div>
    </div>
    
    <div class="container">
        <!-- Terminal Section (60%) -->
        <div class="terminal-section">
            <div class="terminal">
                <div class="terminal-header">
                    <div class="terminal-header-content">
                        <div class="file-selector">
                            <select name="ledger_file" id="fileSelector">
                                <option value="">-- Select file --</option>
                                <?php foreach ($availableFiles as $file): ?>
                                    <option value="<?php echo e($file); ?>" <?php echo ($currentFile === $file) ? 'selected' : ''; ?>>
                                        <?php echo e($file); ?>
                                        <?php if (substr($file, 0, 1) === '.'): ?>
                                            (hidden)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isAuthenticated): ?>
                            <div class="login-status">
                                <a href="?logout=1" class="logout-button">Logout</a>
                            </div>
                        <?php endif; ?>
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
                    
                    <?php if (!empty($transactionResult)): ?>
                        <div class="command-output <?php echo strpos($transactionResult, 'Error') === false ? 'success' : 'error'; ?>">
                            <div class="command-result"><?php echo formatOutput($transactionResult); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($importResult)): ?>
                        <div class="command-output <?php echo strpos($importResult, 'Error') === false ? 'success' : 'error'; ?>">
                            <div class="command-result"><?php echo formatOutput($importResult); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($previewResult)): ?>
                        <div class="command-output <?php echo strpos($previewResult, 'Error') === false ? 'success' : 'error'; ?>">
                            <div class="command-line">CSV Import Preview</div>
                            <div class="command-result"><?php echo formatOutput($previewResult); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($deleteResult)): ?>
                        <div class="command-output <?php echo strpos($deleteResult, 'Error') === false ? 'success' : 'error'; ?>">
                            <div class="command-result"><?php echo formatOutput($deleteResult); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (empty($commandOutput) && empty($transactionResult) && empty($importResult) && empty($previewResult) && empty($deleteResult)): ?>
                        <div class="welcome-message">
                            Ledger CLI Terminal v1.1<br>
                            Current file: <?php echo e($currentFile); ?>
                            <?php if (substr($currentFile, 0, 1) === '.'): ?>
                                <span style="color: #ffaa00;"> (hidden file)</span>
                            <?php endif; ?>
                            <br>
                            <?php if (isMobileDevice()): ?>
                                <span style="color: #0a0;">Mobile mode active (--columns=58, smaller font)</span><br>
                            <?php endif; ?>
                            Type '--help' to see available commands<br>
                            <span style="color: #ff5555; font-size: 12px;">
                                Security: -f flag, file paths, pipes (<, >, |) and path traversal (..) are blocked
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="terminal-input">
                    <span class="prompt">$</span>
                    <input type="text" id="commandInput" 
                           placeholder="Type a command (ex: bal, print, stats, accounts...)" 
                           autocomplete="off" 
                           autofocus
                           value="<?php echo isset($_POST['command']) ? e($_POST['command']) : ''; ?>">
                    <!-- EXECUTE BUTTON SAME LINE ON MOBILE AND DESKTOP -->
                    <button type="button" id="executeButton" class="btn">Execute</button>
                </div>
            </div>
        </div>
        
        <!-- Transactions Section (40%) - NOW WITH TABS -->
        <div class="transaction-section">
            <div class="tab-container">
                <div class="tab-buttons">
                    <button class="tab-button active" data-tab="add-transaction">Add Transaction</button>
                    <button class="tab-button" data-tab="import-csv">Import CSV</button>
                </div>
                
                <!-- Tab 1: Add Transaction (original) -->
                <div class="tab-content active" id="add-transaction">    
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
                        <?php if (isDeleteLocked()): ?>
                            <div class="locked-message" id="deleteLockedMessage">
                                Delete function locked for <?php echo getDeleteLockRemaining(); ?> seconds
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Tab 2: Import CSV (NEW) -->
                <div class="tab-content" id="import-csv">
                    <div class="transaction-body">
                        <form class="transaction-form" id="csvImportForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="csv_file">CSV File:</label>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                                <small style="color: #8f8; font-size: 11px;">
                                    Select a CSV file to import (max 10MB)
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="csv_account">Account (destination):</label>
                                <input type="text" id="csv_account" 
                                       name="csv_account" 
                                       placeholder="e.g., Assets:Checking, Paypal"
                                       required>
                                <small style="color: #8f8; font-size: 11px;">
                                    This account will receive the CSV amounts
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label for="csv_set_search">Set Search:</label>
                                <input type="text" id="csv_set_search" 
                                       name="csv_set_search" 
                                       value="Expenses"
                                       placeholder="e.g., Expenses, Utilities">
                                <small style="color: #8f8; font-size: 11px;">
                                    Used for Bayesian classification of payees
                                </small>
                            </div>
                            
                            <!-- Advanced options -->
                            <div class="advanced-options" id="advancedOptions">
                                <h4 onclick="toggleAdvancedOptions()">Advanced Options</h4>
                                <div class="options-content">
                                    <div class="option-group">
                                        <label for="csv_scale">Scale Factor:</label>
                                        <input type="number" id="csv_scale" name="csv_scale" 
                                               step="0.01" value="1.00" min="0.01">
                                    </div>
                                    
                                    <div class="option-group">
                                        <label for="csv_date_format">Date Format:</label>
                                        <select id="csv_date_format" name="csv_date_format">
                                            <option value="m/d/Y">m/d/Y (US)</option>
                                            <option value="d/m/Y">d/m/Y (EU)</option>
                                            <option value="Y-m-d">Y-m-d (ISO)</option>
                                            <option value="Y/m/d">Y/m/d (Ledger)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="option-group">
                                        <label for="csv_delimiter">Delimiter:</label>
                                        <select id="csv_delimiter" name="csv_delimiter">
                                            <option value=",">Comma (,)</option>
                                            <option value=";">Semicolon (;)</option>
                                            <option value="\t">Tab</option>
                                            <option value="|">Pipe (|)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="csv_neg" name="csv_neg" value="1">
                                        <label for="csv_neg">Negate Amounts</label>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="csv_allow_matching" name="csv_allow_matching" value="1">
                                        <label for="csv_allow_matching">Allow Matching</label>
                                    </div>
                                    
                                    <div class="checkbox-group">
                                        <input type="checkbox" id="csv_wide" name="csv_wide" value="1">
                                        <label for="csv_wide">Wide Mode (132 cols)</label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- CSV Example -->
                            <div class="csv-example">
                                <h4>CSV Format Example:</h4>
                                <pre><code>Date,Description,Amount
2023-11-15,Supermarket purchase,-85.90
2023-11-16,Salary deposit,2500.00
2023-11-17,Uber ride,-32.50</code></pre>
                                <small style="color: #8f8;">
                                    Required columns: Date, Payee/Description, Amount<br>
                                    Optional columns: Note, UUID
                                </small>
                            </div>
                        </form>
                    </div>
                    
                    <div class="transaction-footer">
                        <button type="button" id="previewCsvButton" class="btn btn-info">
                            Preview CSV
                        </button>
                        <button type="button" id="importCsvButton" class="btn btn-warning">Import CSV</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Existing accounts for autocomplete
        let existingAccounts = <?php echo json_encode($existingAccounts); ?>;
        let currentFile = "<?php echo e($currentFile); ?>";
        let isMobile = <?php echo isMobileDevice() ? 'true' : 'false'; ?>;
        let isDeleteLocked = <?php echo isDeleteLocked() ? 'true' : 'false'; ?>;
        let deleteLockRemaining = <?php echo getDeleteLockRemaining(); ?>;
        
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
            document.getElementById('importCsvButton').addEventListener('click', importCSV);
            document.getElementById('previewCsvButton').addEventListener('click', previewCSV);
            
            // Delete modal events
            document.getElementById('cancelDeleteBtn').addEventListener('click', cancelDelete);
            document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);
            document.getElementById('deletePasswordInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') confirmDelete();
            });
            
            // Close modal when clicking outside
            document.getElementById('deletePasswordModal').addEventListener('click', function(e) {
                if (e.target === this) cancelDelete();
            });
            
            // Tab switching
            document.querySelectorAll('.tab-button').forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.dataset.tab;
                    switchTab(tabId);
                });
            });
            
            // Update delete lock timer if locked
            if (isDeleteLocked && deleteLockRemaining > 0) {
                updateDeleteLockTimer();
            }
            
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
                // Ctrl+T to switch tabs
                if (e.ctrlKey && e.key === 't') {
                    e.preventDefault();
                    const activeTab = document.querySelector('.tab-button.active').dataset.tab;
                    const newTab = activeTab === 'add-transaction' ? 'import-csv' : 'add-transaction';
                    switchTab(newTab);
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
        
        function switchTab(tabId) {
            // Update tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.toggle('active', button.dataset.tab === tabId);
            });
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.toggle('active', content.id === tabId);
            });
            
            // If switching to CSV tab, pre-fill account field with existing accounts if available
            if (tabId === 'import-csv' && existingAccounts.length > 0) {
                const csvAccountInput = document.getElementById('csv_account');
                if (csvAccountInput && !csvAccountInput.value) {
                    // Suggest common account patterns
                    const commonAccounts = existingAccounts.filter(acc => 
                        acc.includes('Assets') || acc.includes('Checking') || acc.includes('Paypal')
                    );
                    if (commonAccounts.length > 0) {
                        csvAccountInput.placeholder = 'e.g., ' + commonAccounts[0];
                    }
                }
            }
        }
        
        function toggleAdvancedOptions() {
            const advancedOptions = document.getElementById('advancedOptions');
            advancedOptions.classList.toggle('collapsed');
        }
        
        function previewCSV() {
            const csvFileInput = document.getElementById('csv_file');
            const csvAccount = document.getElementById('csv_account').value.trim();
            const csvSetSearch = document.getElementById('csv_set_search').value.trim();
            
            // Validations
            if (!csvFileInput.files || csvFileInput.files.length === 0) {
                showTransactionMessage('Please select a CSV file.', 'error');
                return;
            }
            
            if (!csvAccount) {
                showTransactionMessage('Account is required for CSV import.', 'error');
                return;
            }
            
            if (!csvSetSearch) {
                showTransactionMessage('Set Search is required for classification.', 'error');
                return;
            }
            
            // Create FormData - CORREÇÃO: Enviar '1' para checked e '' para não checked
            const formData = new FormData();
            formData.append('ajax_preview_csv', '1');
            formData.append('ajax_file', currentFile);
            formData.append('csv_file', csvFileInput.files[0]);
            formData.append('csv_account', csvAccount);
            formData.append('csv_set_search', csvSetSearch);
            
            // CORREÇÃO CRÍTICA: Enviar checkboxes como '1' ou 'on' quando marcados
            formData.append('csv_neg', document.getElementById('csv_neg').checked ? '1' : '');
            formData.append('csv_allow_matching', document.getElementById('csv_allow_matching').checked ? '1' : '');
            formData.append('csv_wide', document.getElementById('csv_wide').checked ? '1' : '');
            
            formData.append('csv_scale', document.getElementById('csv_scale').value);
            formData.append('csv_date_format', document.getElementById('csv_date_format').value);
            formData.append('csv_delimiter', document.getElementById('csv_delimiter').value);
            
            // Debug no console
            console.log('Preview CSV - csv_neg checked:', document.getElementById('csv_neg').checked);
            console.log('Preview CSV - csv_allow_matching checked:', document.getElementById('csv_allow_matching').checked);
            console.log('Preview CSV - csv_wide checked:', document.getElementById('csv_wide').checked);
            
            // Show loading
            const terminalOutput = document.getElementById('terminalOutput');
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'command-output';
            loadingDiv.innerHTML = '<div class="command-result">Generating CSV preview...</div>';
            terminalOutput.appendChild(loadingDiv);
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
            
            // Send via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                // Remove loading
                loadingDiv.remove();
                
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        // Show message in terminal
                        if (response.success) {
                            const previewDiv = document.createElement('div');
                            previewDiv.className = 'command-output';
                            
                            const commandLine = document.createElement('div');
                            commandLine.className = 'command-line';
                            commandLine.textContent = 'CSV Import Preview';
                            
                            const resultDiv = document.createElement('div');
                            resultDiv.className = 'command-result';
                            resultDiv.textContent = response.message;
                            
                            previewDiv.appendChild(commandLine);
                            previewDiv.appendChild(resultDiv);
                            terminalOutput.appendChild(previewDiv);
                        } else {
                            showTransactionMessageInTerminal(response.error || 'Preview failed', 'error');
                        }
                    } catch (e) {
                        showTransactionMessageInTerminal('Error processing response', 'error');
                    }
                } else {
                    showTransactionMessageInTerminal('Error communicating with server', 'error');
                }
                
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            };
            
            xhr.onerror = function() {
                loadingDiv.remove();
                showTransactionMessageInTerminal('Connection error', 'error');
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            };
            
            xhr.send(formData);
        }
        
        function executeCommand() {
            const commandInput = document.getElementById('commandInput');
            const command = commandInput.value.trim();
            
            if (!command) return;
            
            // Validate command for security
            if (command.includes('>') || command.includes('<') || command.includes('|')) {
                showError('Error: Dangerous characters (<, >, |) are not allowed in commands.');
                return;
            }
            
            if (command.match(/(?:^|\s)-f\b/)) {
                showError('Error: The -f flag is not allowed. You can only use the current journal file.');
                return;
            }
            
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
                            
                            // Update accounts for autocomplete
                            existingAccounts = response.accounts;
                            setupAutocomplete();
                            
                            // Update welcome message
                            const terminalOutput = document.getElementById('terminalOutput');
                            terminalOutput.innerHTML = '<div class="welcome-message">' +
                                'Ledger CLI Terminal v1.1<br>' +
                                'Current file: ' + currentFile + 
                                (currentFile.startsWith('.') ? ' <span style="color: #ffaa00;">(hidden file)</span>' : '') +
                                '<br>' +
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
            
            // CORRECTION: Send date in correct format (YYYY-MM-DD)
            // PHP will convert to YYYY/MM/DD
            const formattedDate = dateInput; // Keep YYYY-MM-DD format
            
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
        
        function importCSV() {
            const csvFileInput = document.getElementById('csv_file');
            const csvAccount = document.getElementById('csv_account').value.trim();
            const csvSetSearch = document.getElementById('csv_set_search').value.trim();
            
            // Validations
            if (!csvFileInput.files || csvFileInput.files.length === 0) {
                showTransactionMessage('Please select a CSV file.', 'error');
                return;
            }
            
            if (!csvAccount) {
                showTransactionMessage('Account is required for CSV import.', 'error');
                return;
            }
            
            if (!csvSetSearch) {
                showTransactionMessage('Set Search is required for classification.', 'error');
                return;
            }
            
            // Create FormData - CORREÇÃO: Enviar '1' para checked e '' para não checked
            const formData = new FormData();
            formData.append('ajax_import_csv', '1');
            formData.append('ajax_file', currentFile);
            formData.append('csv_file', csvFileInput.files[0]);
            formData.append('csv_account', csvAccount);
            formData.append('csv_set_search', csvSetSearch);
            
            // CORREÇÃO CRÍTICA: Enviar checkboxes como '1' ou 'on' quando marcados
            formData.append('csv_neg', document.getElementById('csv_neg').checked ? '1' : '');
            formData.append('csv_allow_matching', document.getElementById('csv_allow_matching').checked ? '1' : '');
            formData.append('csv_wide', document.getElementById('csv_wide').checked ? '1' : '');
            
            formData.append('csv_scale', document.getElementById('csv_scale').value);
            formData.append('csv_date_format', document.getElementById('csv_date_format').value);
            formData.append('csv_delimiter', document.getElementById('csv_delimiter').value);
            
            // Debug no console
            console.log('Import CSV - csv_neg checked:', document.getElementById('csv_neg').checked);
            console.log('Import CSV - csv_allow_matching checked:', document.getElementById('csv_allow_matching').checked);
            console.log('Import CSV - csv_wide checked:', document.getElementById('csv_wide').checked);
            
            // Show loading
            const terminalOutput = document.getElementById('terminalOutput');
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'command-output';
            loadingDiv.innerHTML = '<div class="command-result">Importing CSV file...</div>';
            terminalOutput.appendChild(loadingDiv);
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
            
            // Send via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                // Remove loading
                loadingDiv.remove();
                
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        // Show message in terminal
                        showTransactionMessageInTerminal(response.message, response.success ? 'success' : 'error');
                        
                        if (response.success) {
                            // Clear file input
                            csvFileInput.value = '';
                            document.getElementById('csv_account').value = '';
                            
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
                
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            };
            
            xhr.onerror = function() {
                loadingDiv.remove();
                showTransactionMessageInTerminal('Connection error', 'error');
                terminalOutput.scrollTop = terminalOutput.scrollHeight;
            };
            
            xhr.send(formData);
        }
        
        function deleteLastTransaction() {
            // Check if delete is locked
            if (isDeleteLocked) {
                const modal = document.getElementById('deletePasswordModal');
                const modalContent = modal.querySelector('.modal-content');
                
                // Modify modal for lock message
                modalContent.innerHTML = `
                    <h3 class="modal-title">Delete Locked</h3>
                    <p class="modal-message">
                        Delete function is temporarily locked due to too many failed attempts.
                    </p>
                    <div class="lock-timer">
                        ${deleteLockRemaining}s
                    </div>
                    <div class="modal-buttons">
                        <button type="button" 
                                class="modal-btn modal-btn-cancel" 
                                onclick="cancelDelete()" 
                                style="flex: 1;">
                            Close
                        </button>
                    </div>
                `;
                
                modal.style.display = 'flex';
                return;
            }
            
            // Show password modal
            const modal = document.getElementById('deletePasswordModal');
            const input = document.getElementById('deletePasswordInput');
            const error = document.getElementById('deletePasswordError');
            
            // Reset modal to default
            modal.querySelector('.modal-content').innerHTML = `
                <h3 class="modal-title">Confirm Delete</h3>
                <p class="modal-message">
                    Enter password to delete last transaction<br>
                </p>
                <input type="password" 
                       id="deletePasswordInput" 
                       class="modal-input" 
                       placeholder="Enter delete password">
                <div id="deletePasswordError" class="modal-error"></div>
                <div class="modal-buttons">
                    <button type="button" 
                            class="modal-btn modal-btn-cancel" 
                            id="cancelDeleteBtn">
                        Cancel
                    </button>
                    <button type="button" 
                            class="modal-btn modal-btn-confirm" 
                            id="confirmDeleteBtn">
                        Delete
                    </button>
                </div>
            `;
            
            // Re-attach events
            document.getElementById('cancelDeleteBtn').addEventListener('click', cancelDelete);
            document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);
            document.getElementById('deletePasswordInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') confirmDelete();
            });
            
            modal.style.display = 'flex';
            document.getElementById('deletePasswordInput').value = '';
            document.getElementById('deletePasswordError').style.display = 'none';
            document.getElementById('deletePasswordInput').focus();
        }
        
        function cancelDelete() {
            const modal = document.getElementById('deletePasswordModal');
            modal.style.display = 'none';
        }
        
        function confirmDelete() {
            const password = document.getElementById('deletePasswordInput').value.trim();
            const error = document.getElementById('deletePasswordError');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            const cancelBtn = document.getElementById('cancelDeleteBtn');
            
            if (!password) {
                error.textContent = 'Password is required';
                error.style.display = 'block';
                return;
            }
            
            // Disable buttons during request
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            confirmBtn.textContent = 'Deleting...';
            
            // Send via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                // Re-enable buttons
                confirmBtn.disabled = false;
                cancelBtn.disabled = false;
                confirmBtn.textContent = 'Delete';
                
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            // Close modal
                            const modal = document.getElementById('deletePasswordModal');
                            modal.style.display = 'none';
                            
                            // Show success message
                            showTransactionMessageInTerminal(response.message, 'success');
                            
                            // Update accounts for autocomplete
                            existingAccounts = response.accounts;
                            setupAutocomplete();
                            
                            // Reset lock status
                            isDeleteLocked = false;
                            deleteLockRemaining = 0;
                            
                        } else if (response.requires_password) {
                            error.textContent = response.message;
                            error.style.display = 'block';
                            
                        } else if (response.locked) {
                            // Update lock status
                            isDeleteLocked = true;
                            deleteLockRemaining = response.remaining;
                            
                            // Update modal for lock message
                            const modal = document.getElementById('deletePasswordModal');
                            const modalContent = modal.querySelector('.modal-content');
                            
                            modalContent.innerHTML = `
                                <h3 class="modal-title">Delete Locked</h3>
                                <p class="modal-message">
                                    Too many failed attempts. Delete function is temporarily locked.
                                </p>
                                <div class="lock-timer">
                                    ${deleteLockRemaining}s
                                </div>
                                <div class="modal-buttons">
                                    <button type="button" 
                                            class="modal-btn modal-btn-cancel" 
                                            onclick="cancelDelete()" 
                                            style="flex: 1;">
                                        Close
                                    </button>
                                </div>
                            `;
                            
                            // Start lock timer
                            updateDeleteLockTimer();
                            
                        } else {
                            error.textContent = response.message;
                            error.style.display = 'block';
                            
                            // Clear password field
                            document.getElementById('deletePasswordInput').value = '';
                            document.getElementById('deletePasswordInput').focus();
                        }
                    } catch (e) {
                        error.textContent = 'Error processing response';
                        error.style.display = 'block';
                    }
                } else {
                    error.textContent = 'Error communicating with server';
                    error.style.display = 'block';
                }
            };
            
            xhr.onerror = function() {
                confirmBtn.disabled = false;
                cancelBtn.disabled = false;
                confirmBtn.textContent = 'Delete';
                error.textContent = 'Connection error';
                error.style.display = 'block';
            };
            
            const params = new URLSearchParams();
            params.append('ajax_delete_last', '1');
            params.append('ajax_file', currentFile);
            params.append('delete_password', password);
            
            xhr.send(params.toString());
        }
        
        function updateDeleteLockTimer() {
            if (!isDeleteLocked || deleteLockRemaining <= 0) return;
            
            const timer = setInterval(function() {
                deleteLockRemaining--;
                
                // Update modal if open
                const modal = document.getElementById('deletePasswordModal');
                if (modal.style.display === 'flex') {
                    const timerElement = modal.querySelector('.lock-timer');
                    if (timerElement) {
                        timerElement.textContent = `${deleteLockRemaining}s`;
                    }
                }
                
                // Update locked message in transaction footer
                const lockedMessage = document.getElementById('deleteLockedMessage');
                if (lockedMessage) {
                    lockedMessage.textContent = `Delete function locked for ${deleteLockRemaining} seconds`;
                }
                
                if (deleteLockRemaining <= 0) {
                    clearInterval(timer);
                    isDeleteLocked = false;
                    
                    // Close modal if open
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                    
                    // Remove locked message
                    if (lockedMessage) {
                        lockedMessage.remove();
                    }
                }
            }, 1000);
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
        
        function showTransactionMessage(message, type) {
            // This is for inline messages in the transaction form
            alert(message);
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
        
        // Helper function for string contains
        function strpos(haystack, needle, offset) {
            var i = (haystack + '').indexOf(needle, (offset || 0));
            return i === -1 ? false : i;
        }
    </script>
</body>
</html>
