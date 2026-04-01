#!/usr/bin/env python3

import csv
import re
import sys
import math
from datetime import datetime
from typing import List, Dict, Optional, Any, Tuple

TRANSACTION_DATE_FORMAT = "%Y/%m/%d"
DISPLAY_PRECISION = 2


class SimpleRational:
    """Simple rational number class for accounting calculations"""

    def __init__(self, value: float):
        self.value = value

    @classmethod
    def zero(cls):
        return cls(0.0)

    def plus(self, other):
        return SimpleRational(self.value + other.value)

    def is_zero(self):
        return abs(self.value) < 0.000001

    def to_f(self, precision=2):
        return f"{self.value:.{precision}f}"


class AccountChange:
    def __init__(self, name: str, balance: SimpleRational = None):
        self.name = name
        self.balance = balance


class Transaction:
    def __init__(self, payee: str, date: datetime):
        self.payee = payee
        self.date = date
        self.accountChanges = []
        self.comments = []


class Parser:
    @classmethod
    def parse_ledger(cls, content: str) -> List[Transaction]:
        transactions = []
        lines = content.split('\n')
        current = None
        comments = []

        for line in lines:
            line = line.rstrip()
            if not line.strip():
                if current:
                    current.comments = comments
                    transactions.append(current)
                    current = None
                    comments = []
                continue

            if line.strip().startswith(';'):
                comments.append(line)
                continue

            # Match date at beginning: YYYY/MM/DD
            match = re.match(r'^(\d{4}/\d{2}/\d{2})\s+(.+)$', line)
            if match:
                if current:
                    current.comments = comments
                    transactions.append(current)
                    comments = []
                date_str = match.group(1)
                payee = match.group(2)
                try:
                    date = datetime.strptime(date_str, "%Y/%m/%d")
                    current = Transaction(payee, date)
                except:
                    continue
            elif current and (line.startswith('    ') or line.startswith('\t')):
                line = line.strip()
                match2 = re.match(r'^(.+?)\s{2,}(.+)$', line)
                if match2:
                    name = match2.group(1).strip()
                    value_str = match2.group(2).strip().replace(',', '.')
                    try:
                        value = float(value_str)
                        current.accountChanges.append(AccountChange(name, SimpleRational(value)))
                    except:
                        pass
                else:
                    current.accountChanges.append(AccountChange(line))

        if current:
            current.comments = comments
            transactions.append(current)

        transactions.sort(key=lambda t: t.date)
        return transactions


def extract_words(text: str) -> List[str]:
    """Extract words from text, same as Go"""
    return text.lower().strip().split()


def get_word_prob(freqs: Dict[str, int], total: int, word: str, vocab_size: int) -> float:
    """Calculate P(W|C) = (count(W,C) + 1) / (total_words_in_C + vocabulary_size)"""
    if total == 0 or vocab_size == 0:
        return 1e-11
    value = freqs.get(word, 0)
    return (value + 1) / (total + vocab_size)


def get_priors(classes: List[str], datas: Dict[str, Dict[str, Any]]) -> List[float]:
    """Calculate priors P(C_j) = (count_j + 1) / (total + num_classes)"""
    n = len(classes)
    priors = []
    total_sum = 0
    for klass in classes:
        total = datas[klass]['total']
        priors.append(float(total))
        total_sum += total

    float_n = float(n)
    float_sum = float(total_sum)
    return [(p + 1) / (float_sum + float_n) for p in priors]


def log_scores(classes: List[str], datas: Dict[str, Dict[str, Any]], document: List[str]) -> List[float]:
    """Calculate log scores for each class"""
    n = len(classes)
    scores = [0.0] * n
    priors = get_priors(classes, datas)

    for idx, klass in enumerate(classes):
        data = datas[klass]
        freqs = data['freqs']
        total = data['total']
        vocab_size = len(freqs)

        if priors[idx] > 0:
            score = math.log(priors[idx])
        else:
            score = -float('inf')

        for word in document:
            word_prob = get_word_prob(freqs, total, word, vocab_size)
            score += math.log(word_prob)
        scores[idx] = score

    return scores


def find_max(scores: List[float]) -> Tuple[int, bool]:
    """Find index of maximum score - same as PHP/Go"""
    inx = 0
    strict = True
    
    for i in range(1, len(scores)):
        if scores[inx] < scores[i]:
            inx = i
            strict = True
        elif scores[inx] == scores[i]:
            strict = False
    
    return inx, strict


def classify_payee(payee: str, classes: List[str], datas: Dict[str, Dict[str, Any]]) -> str:
    """Classify payee using Bayesian classifier"""
    words = extract_words(payee)
    if not words or not classes:
        return "unknown:unknown"

    scores = log_scores(classes, datas, words)
    inx, _ = find_max(scores)
    return classes[inx]


def print_account_line(name: str, amount: float, columns: int) -> str:
    """Print account line with right-aligned amount at 79 columns (PHP style)"""
    amount_str = f"{amount:.{DISPLAY_PRECISION}f}"
    # Calculate spaces needed to right-align at column 79
    # PHP uses: spaces = columns - 4 - name_length - amount_length
    spaces = columns - 4 - len(name) - len(amount_str)
    if spaces < 2:
        spaces = 2
    return f"    {name}{' ' * spaces}{amount_str}"


def main():
    import argparse
    import math

    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('-f', dest='file', default='')
    parser.add_argument('--set-search', dest='set_search', default='Expenses')
    parser.add_argument('--neg', dest='neg', action='store_true', default=False)
    parser.add_argument('--allow-matching', dest='allow_matching', action='store_true', default=False)
    parser.add_argument('--scale', dest='scale', type=float, default=1.0)
    parser.add_argument('--date-format', dest='date_format', default='m/d/Y')
    parser.add_argument('--delimiter', dest='delimiter', default=',')
    parser.add_argument('--columns', dest='columns', type=int, default=79)
    parser.add_argument('--wide', dest='wide', action='store_true', default=False)
    parser.add_argument('--help', dest='help', action='store_true', default=False)
    parser.add_argument('account', nargs='?', default='')
    parser.add_argument('csv_file', nargs='?', default='')

    args = parser.parse_args()

    if args.help:
        print("Limport - CSV Importer for Ledger")
        print("=================================")
        print()
        print("Usage: python limport.py -f <ledger-file> [options] <account> <csv-file>")
        print("Options:")
        print("  --set-search=STR   Search string for classification (default: Expenses)")
        print("  --neg              Negate amount column value")
        print("  --allow-matching   Include transactions that match existing ones")
        print("  --scale=FACTOR     Scaling factor (default: 1.0)")
        print("  --date-format=STR  Date format (default: m/d/Y)")
        print("  --delimiter=STR    Field delimiter (default: ,)")
        print("  --columns=N        Column width (default: 79)")
        print("  --wide             Wide mode (132 columns)")
        return

    if not args.file:
        print("Error: Ledger file required (-f)")
        return

    if not args.account or not args.csv_file:
        print("Error: Account and CSV file required")
        print("Usage: python limport.py -f <ledger-file> <account> <csv-file>")
        return

    if args.wide:
        args.columns = 132

    try:
        # Read ledger
        with open(args.file, 'r', encoding='utf-8') as f:
            content = f.read()
        transactions = Parser.parse_ledger(content)

        # Find destination account
        all_accounts = set()
        for t in transactions:
            for ac in t.accountChanges:
                all_accounts.add(ac.name)

        matching = [name for name in all_accounts if args.account.lower() in name.lower()]
        if not matching:
            print(f"Error: Account '{args.account}' not found")
            return
        dest_account = matching[-1]

        # Build Bayesian classifier - preserve order as they appear in ledger
        classes = []
        for t in transactions:
            for ac in t.accountChanges:
                if args.set_search.lower() in ac.name.lower() and ac.name not in classes:
                    classes.append(ac.name)

        if not classes:
            print(f"Warning: No classes found containing '{args.set_search}'")
            classes = [args.set_search]

        # Initialize classifier data
        datas = {}
        for klass in classes:
            datas[klass] = {'freqs': {}, 'total': 0}

        # Train the classifier - same as Go
        learned = 0
        for t in transactions:
            payee_words = extract_words(t.payee)
            for ac in t.accountChanges:
                if ac.name in classes:
                    data = datas[ac.name]
                    freqs = data['freqs']
                    for word in payee_words:
                        freqs[word] = freqs.get(word, 0) + 1
                    datas[ac.name] = {'freqs': freqs, 'total': data['total'] + 1}
                    learned += 1

        # Read CSV
        with open(args.csv_file, 'r', encoding='utf-8') as f:
            csv_content = f.read()

        lines = [l.strip() for l in csv_content.split('\n') if l.strip()]
        header = lines[0].split(args.delimiter)

        # Find column indices
        date_col = -1
        payee_col = -1
        amount_col = -1
        note_col = -1

        for idx, h in enumerate(header):
            h_lower = h.lower()
            if 'date' in h_lower:
                date_col = idx
            elif 'description' in h_lower or 'payee' in h_lower:
                payee_col = idx
            elif 'amount' in h_lower or 'expense' in h_lower:
                amount_col = idx
            elif 'note' in h_lower or 'comment' in h_lower:
                note_col = idx

        if date_col < 0 or payee_col < 0 or amount_col < 0:
            print("Error: Required columns not found")
            return

        # Process CSV lines
        for line in lines[1:]:
            record = line.split(args.delimiter)
            if len(record) <= max(date_col, payee_col, amount_col):
                continue

            date_str = record[date_col].strip()
            payee = record[payee_col].strip()
            amount_str = record[amount_col].strip()
            note = record[note_col].strip() if note_col >= 0 and len(record) > note_col else ""

            if not date_str or not payee or not amount_str:
                continue

            # Parse date
            date = None
            formats = [args.date_format, "%d/%m/%Y", "%Y/%m/%d", "%m/%d/%Y", "%d-%m-%Y", "%Y-%m-%d", "%m-%d-%Y"]
            for fmt in formats:
                try:
                    date = datetime.strptime(date_str, fmt)
                    break
                except:
                    continue

            if date is None:
                continue

            # Parse amount
            amount_str_clean = re.sub(r'[^\d\.,\-]', '', amount_str).replace(',', '.')
            try:
                amount = float(amount_str_clean)
            except ValueError:
                continue

            amount *= args.scale
            if args.neg:
                amount = -amount

            csv_amount = -amount
            expense_amount = -csv_amount

            # Classify payee
            classified_account = classify_payee(payee, classes, datas)

            # Print transaction - exactly as PHP
            if note:
                print(f";{note}")  # PHP doesn't add space after semicolon
            print(f"{date.strftime(TRANSACTION_DATE_FORMAT)} {payee}")

            # Print account lines with exact PHP formatting
            print(print_account_line(dest_account, csv_amount, args.columns))
            print(print_account_line(classified_account, expense_amount, args.columns))
            print()

    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
