#!/usr/bin/env python3

import sys
from datetime import datetime, timedelta, timezone

TRANSACTION_DATE_FORMAT = "%Y/%m/%d"
DISPLAY_PRECISION = 2


class SimpleRational:
    """Simple rational number class for accounting calculations"""

    def __init__(self, value: float):
        self.value = value

    @classmethod
    def zero(cls):
        """Create a zero value"""
        return cls(0.0)

    def plus(self, other):
        return SimpleRational(self.value + other.value)

    def is_zero(self):
        """Check if value is zero"""
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
    def parse_ledger(cls, content: str):
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
            import re
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


def show_usage():
    """Show usage information"""
    print("Equity - Opening Balance Transaction Generator")
    print("==============================================")
    print()
    print("Usage: python equity.py [OPTIONS]")
    print()
    print("Options:")
    print("  -f FILE      Ledger file (*required) or '-' for stdin")
    print("  -b DATE      Start date (default: 1970/01/01)")
    print("  -e DATE      End date (default: today)")
    print("  --payee=STR  Filter by payee")
    print("  --columns=N  Column width (default: 79)")
    print("  --help       Show this help")
    print()
    print("Description:")
    print("  Generates an 'Opening Balances' transaction with accumulated balances")
    print("  from all transactions in the specified period. Useful for archiving")
    print("  old transactions and starting with correct balances.")
    print()
    print("Examples:")
    print("  python equity.py -f my_ledger.txt -b 2023/01/01 -e 2023/12/31")
    print("  python equity.py -f my_ledger.txt --payee='Salary' -b 2023/01/01")
    print("  cat my_ledger.txt | python equity.py -f -")


def main():
    """Main entry point"""
    import argparse
    from datetime import datetime as dt

    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('-f', dest='file', default='')
    parser.add_argument('-b', dest='start_date', default='1970/01/01')
    parser.add_argument('-e', dest='end_date', default='')
    parser.add_argument('--payee', dest='payee', default='')
    parser.add_argument('--columns', dest='columns', type=int, default=79)
    parser.add_argument('--help', dest='help', action='store_true', default=False)

    args = parser.parse_args()

    if args.help:
        show_usage()
        return

    if not args.file:
        show_usage()
        return

    if not args.end_date:
        # Use timezone-aware UTC datetime to avoid deprecation warning
        args.end_date = dt.now(timezone.utc).strftime(TRANSACTION_DATE_FORMAT)

    try:
        if args.file == '-':
            content = sys.stdin.read()
        else:
            with open(args.file, 'r', encoding='utf-8') as f:
                content = f.read()

        transactions = Parser.parse_ledger(content)

        start = dt.strptime(args.start_date, TRANSACTION_DATE_FORMAT)
        fin = dt.strptime(args.end_date, TRANSACTION_DATE_FORMAT)

        filtered = [t for t in transactions if start <= t.date <= fin]

        if args.payee:
            filtered = [t for t in filtered if args.payee.lower() in t.payee.lower()]

        if not filtered:
            print("No transactions in specified period.")
            return

        balances = {}

        for t in filtered:
            for ac in t.accountChanges:
                if ac.balance is None:
                    continue
                name = ac.name
                if name not in balances:
                    balances[name] = SimpleRational.zero()
                balances[name] = balances[name].plus(ac.balance)

        non_zero = {k: v for k, v in balances.items() if not v.is_zero()}

        if not non_zero:
            print("All balances are zero in specified period.")
            return

        last_date = filtered[-1].date
        print(f"{last_date.strftime(TRANSACTION_DATE_FORMAT)} Opening Balances")

        for name in sorted(non_zero.keys()):
            balance = non_zero[name]
            balance_str = balance.to_f(DISPLAY_PRECISION)
            spaces = args.columns - 4 - len(name) - len(balance_str)
            spaces = 2 if spaces < 2 else spaces
            print(f"    {name}{' ' * spaces}{balance_str}")
        print()

    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
