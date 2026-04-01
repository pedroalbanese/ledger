#!/usr/bin/env python3

import re
import sys
from datetime import datetime

TRANSACTION_DATE_FORMAT = "%Y/%m/%d"
DISPLAY_PRECISION = 2


class SimpleRational:
    """Simple rational number class for accounting calculations"""

    def __init__(self, value):
        if isinstance(value, str):
            s = value.strip()
            s = s.replace(',', '.')
            if re.match(r'^\(.+\)$', s):
                s = re.sub(r'[\(\)]', '', s)
                s = '-' + s
            s = re.sub(r'[\$\€\£\¥]', '', s)
            self.value = float(s)
        else:
            self.value = float(value)

    @classmethod
    def zero(cls):
        return cls(0.0)

    def plus(self, other):
        return SimpleRational(self.value + other.value)

    def negated(self):
        return SimpleRational(-self.value)

    def is_zero(self):
        return abs(self.value) < 0.000001

    def to_float(self, precision=2):
        return f"{self.value:.{precision}f}"

    def get_value(self):
        return self.value

    def __str__(self):
        return self.to_float()


class AccountChange:
    def __init__(self, name: str, balance: SimpleRational = None):
        self.name = name
        self.balance = balance


class Transaction:
    def __init__(self, payee: str, date: datetime):
        self.payee = payee
        self.date = date
        self.accountChanges = []


class Parser:
    @classmethod
    def parse_ledger(cls, content: str):
        transactions = []
        lines = content.split('\n')
        current = None

        for line in lines:
            line = line.rstrip()
            if not line.strip() or line.strip().startswith(';'):
                continue

            match = re.match(r'^(\d{4}/\d{2}/\d{2})\s+(.+)$', line)
            if match:
                if current:
                    cls._finalize_transaction(current)
                    transactions.append(current)
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
            cls._finalize_transaction(current)
            transactions.append(current)

        transactions.sort(key=lambda t: t.date)
        return transactions

    @classmethod
    def _finalize_transaction(cls, transaction: Transaction):
        total = SimpleRational.zero()
        empty_index = -1

        for index, change in enumerate(transaction.accountChanges):
            if change.balance is None:
                if empty_index != -1:
                    raise RuntimeError(f"Multiple empty accounts in transaction: {transaction.payee}")
                empty_index = index
            else:
                total = total.plus(change.balance)

        if empty_index != -1:
            transaction.accountChanges[empty_index].balance = total.negated()

        check_total = SimpleRational.zero()
        for change in transaction.accountChanges:
            if change.balance:
                check_total = check_total.plus(change.balance)

        if not check_total.is_zero():
            if abs(check_total.get_value()) > 0.01:
                raise RuntimeError(f"Transaction not balanced: {transaction.payee} (diff: {check_total})")


def usage(name: str):
    print(f"Usage: {name} <ledger-file>")
    sys.exit(1)


def main():
    if len(sys.argv) != 2:
        usage(sys.argv[0])

    ledger_file_name = sys.argv[1]

    try:
        with open(ledger_file_name, 'r', encoding='utf-8') as f:
            content = f.read()

        transactions = Parser.parse_ledger(content)
        error_count = 0

        for transaction in transactions:
            total = SimpleRational.zero()
            for change in transaction.accountChanges:
                if change.balance:
                    total = total.plus(change.balance)

            if not total.is_zero():
                print(f"Ledger: Transaction not balanced: {transaction.payee} (diff: {total.to_float()})")
                error_count += 1

        if error_count > 0:
            print(f"Found {error_count} error(s) in ledger file.")
            sys.exit(error_count)
        else:
            print("Ledger file is valid.")
            sys.exit(0)

    except FileNotFoundError:
        print(f"Ledger: File '{ledger_file_name}' not found")
        sys.exit(1)
    except Exception as e:
        print(f"Ledger: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
