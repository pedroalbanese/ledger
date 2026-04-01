#!/usr/bin/env python3

import re
import sys
from datetime import datetime, timedelta, timezone
from typing import List, Optional, Dict, Tuple, Any
from dataclasses import dataclass, field

TRANSACTION_DATE_FORMAT = "%Y/%m/%d"
DISPLAY_PRECISION = 2


class SimpleRational:
    """Simple rational number class for precise accounting calculations"""
    
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
    def of(cls, value):
        return cls(value)
    
    @classmethod
    def zero(cls):
        return cls(0)
    
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
    
    def sign(self):
        if self.value > 0.000001:
            return 1
        if self.value < -0.000001:
            return -1
        return 0
    
    def __eq__(self, other):
        return abs(self.value - other.value) < 0.000001


@dataclass
class Account:
    name: str
    balance: SimpleRational


@dataclass
class AccountChange:
    name: str
    balance: Optional[SimpleRational] = None
    
    def __str__(self):
        if self.balance:
            return f"{self.name}    {self.balance.to_float()}"
        return self.name


@dataclass
class Transaction:
    payee: str
    date: datetime
    accountChanges: List[AccountChange] = field(default_factory=list)
    comments: List[str] = field(default_factory=list)
    
    def __str__(self):
        output = f"{self.date.strftime('%Y/%m/%d')} {self.payee}\n"
        for change in self.accountChanges:
            output += f"    {change}\n"
        return output


class Parser:
    """Ledger file parser"""
    
    @classmethod
    def parse_ledger(cls, content: str) -> List[Transaction]:
        transactions = []
        lines = content.split('\n')
        
        current_transaction = None
        comments = []
        
        for line in lines:
            line = line.rstrip()
            
            if not line.strip():
                if current_transaction:
                    cls._finalize_transaction(current_transaction)
                    current_transaction.comments = comments
                    transactions.append(current_transaction)
                    current_transaction = None
                    comments = []
                continue
            
            if line.strip().startswith(';'):
                comments.append(line)
                continue
            
            # Match date at beginning: YYYY/MM/DD or YYYY-MM-DD or YYYY.MM.DD
            match = re.match(r'^(\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2})\s+(.+)$', line)
            if match:
                if current_transaction:
                    cls._finalize_transaction(current_transaction)
                    current_transaction.comments = comments
                    transactions.append(current_transaction)
                    comments = []
                
                date_str = match.group(1)
                payee = match.group(2)
                
                try:
                    date = cls._parse_date(date_str)
                    current_transaction = Transaction(payee, date)
                except Exception:
                    continue
            
            elif current_transaction and (line.startswith('    ') or line.startswith('\t')):
                account_change = cls._parse_account_line(line.strip())
                if account_change:
                    current_transaction.accountChanges.append(account_change)
        
        if current_transaction:
            cls._finalize_transaction(current_transaction)
            current_transaction.comments = comments
            transactions.append(current_transaction)
        
        transactions.sort(key=lambda t: t.date)
        return transactions
    
    @classmethod
    def _parse_date(cls, date_string: str) -> datetime:
        date_string = re.sub(r'[\.\-]', '/', date_string)
        return datetime.strptime(date_string, "%Y/%m/%d")
    
    @classmethod
    def _parse_account_line(cls, line: str) -> Optional[AccountChange]:
        line = line.strip()
        
        # Pattern: "Account Name    123.45" or "Account Name"
        match = re.match(r'^(.*?)\s{2,}(.+)$', line)
        if match:
            account_name = match.group(1).strip()
            value_str = match.group(2).strip()
            balance = cls._parse_balance(value_str)
            if balance:
                return AccountChange(account_name, balance)
        
        # Try to find value at end of line
        parts = line.split()
        if len(parts) >= 2:
            last_part = parts[-1]
            balance = cls._parse_balance(last_part)
            if balance:
                account_name = ' '.join(parts[:-1])
                return AccountChange(account_name, balance)
        
        return AccountChange(line)
    
    @classmethod
    def _parse_balance(cls, value_str: str) -> Optional[SimpleRational]:
        value_str = value_str.strip()
        if not value_str:
            return None
        
        test_str = value_str
        is_negative = False
        
        match = re.match(r'^\((.+)\)$', test_str)
        if match:
            test_str = match.group(1)
            is_negative = True
        
        test_str = re.sub(r'[\$\€\£\¥\s,]', '', test_str)
        test_str = test_str.replace(',', '.')
        
        if re.match(r'^-?\d+\.?\d*$', test_str):
            value = float(test_str)
            if is_negative:
                value = -value
            return SimpleRational(value)
        
        return None
    
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


class Ledger:
    """Main ledger logic"""
    
    PERIOD_DAILY = "Daily"
    PERIOD_WEEK = "Weekly"
    PERIOD_2WEEK = "BiWeekly"
    PERIOD_MONTH = "Monthly"
    PERIOD_2MONTH = "BiMonthly"
    PERIOD_QUARTER = "Quarterly"
    PERIOD_SEMIYEAR = "SemiYearly"
    PERIOD_YEAR = "Yearly"
    
    RANGE_PARTITION = "Partition"
    RANGE_SNAPSHOT = "Snapshot"
    
    @classmethod
    def get_balances(cls, transactions: List[Transaction], filters: List[str] = None) -> List[Account]:
        if filters is None:
            filters = []
        
        balances = {}
        
        for transaction in transactions:
            for account_change in transaction.accountChanges:
                balance = account_change.balance
                if balance is None:
                    continue
                
                include_account = not filters
                if not include_account:
                    for f in filters:
                        if f in account_change.name:
                            include_account = True
                            break
                
                if include_account:
                    account_name = account_change.name
                    if account_name not in balances:
                        balances[account_name] = SimpleRational.zero()
                    balances[account_name] = balances[account_name].plus(balance)
        
        result = [Account(name, balance) for name, balance in balances.items()]
        result.sort(key=lambda a: a.name)
        return result
    
    @classmethod
    def _get_period_start(cls, date: datetime, period: str, first_transaction_date: datetime = None) -> datetime:
        start_date = datetime(date.year, date.month, date.day, 0, 0, 0)
        
        if period == cls.PERIOD_DAILY:
            pass
        
        elif period == cls.PERIOD_WEEK:
            day_of_week = start_date.weekday()
            # Python: Monday=0, Sunday=6; we need Sunday=0
            sunday = 6
            if day_of_week != sunday:
                days_to_subtract = (day_of_week + 1) % 7
                start_date -= timedelta(days=days_to_subtract)
        
        elif period == cls.PERIOD_2WEEK:
            if first_transaction_date:
                start_date = datetime(
                    first_transaction_date.year,
                    first_transaction_date.month,
                    first_transaction_date.day,
                    0, 0, 0
                )
            day_of_week = start_date.weekday()
            sunday = 6
            if day_of_week != sunday:
                days_to_subtract = (day_of_week + 1) % 7
                start_date -= timedelta(days=days_to_subtract)
        
        elif period == cls.PERIOD_MONTH:
            start_date = datetime(start_date.year, start_date.month, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_2MONTH:
            month = start_date.month
            year = start_date.year
            start_month = month if month % 2 == 1 else month - 1
            start_date = datetime(year, start_month, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_QUARTER:
            month = start_date.month
            year = start_date.year
            if month <= 3:
                start_date = datetime(year, 1, 1, 0, 0, 0)
            elif month <= 6:
                start_date = datetime(year, 4, 1, 0, 0, 0)
            elif month <= 9:
                start_date = datetime(year, 7, 1, 0, 0, 0)
            else:
                start_date = datetime(year, 10, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_SEMIYEAR:
            month = start_date.month
            year = start_date.year
            if month <= 6:
                start_date = datetime(year, 1, 1, 0, 0, 0)
            else:
                start_date = datetime(year, 7, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_YEAR:
            start_date = datetime(start_date.year, 1, 1, 0, 0, 0)
        
        return start_date
    
    @classmethod
    def _get_period_end(cls, start_date: datetime, period: str) -> datetime:
        end_date = start_date
        
        if period == cls.PERIOD_DAILY:
            end_date += timedelta(days=1)
        
        elif period == cls.PERIOD_WEEK:
            end_date += timedelta(days=7)
        
        elif period == cls.PERIOD_2WEEK:
            end_date += timedelta(days=14)
        
        elif period == cls.PERIOD_MONTH:
            if start_date.month == 12:
                end_date = datetime(start_date.year + 1, 1, 1, 0, 0, 0)
            else:
                end_date = datetime(start_date.year, start_date.month + 1, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_2MONTH:
            if start_date.month >= 11:
                end_date = datetime(start_date.year + 1, (start_date.month + 2) % 12, 1, 0, 0, 0)
            else:
                end_date = datetime(start_date.year, start_date.month + 2, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_QUARTER:
            if start_date.month >= 10:
                end_date = datetime(start_date.year + 1, (start_date.month + 3) % 12, 1, 0, 0, 0)
            else:
                end_date = datetime(start_date.year, start_date.month + 3, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_SEMIYEAR:
            if start_date.month >= 7:
                end_date = datetime(start_date.year + 1, (start_date.month + 6) % 12, 1, 0, 0, 0)
            else:
                end_date = datetime(start_date.year, start_date.month + 6, 1, 0, 0, 0)
        
        elif period == cls.PERIOD_YEAR:
            end_date = datetime(start_date.year + 1, 1, 1, 0, 0, 0)
        
        return end_date
    
    @classmethod
    def _get_date_range(cls, transactions: List[Transaction]) -> Tuple[Optional[datetime], Optional[datetime]]:
        if not transactions:
            return None, None
        
        start_date = transactions[0].date
        end_date = transactions[0].date
        
        for t in transactions:
            if t.date > end_date:
                end_date = t.date
            if t.date < start_date:
                start_date = t.date
        
        return start_date, end_date
    
    @classmethod
    def _transactions_in_date_range(cls, transactions: List[Transaction], start_date: datetime, end_date: datetime) -> List[Transaction]:
        result = []
        start_inclusive = start_date - timedelta(seconds=1)
        
        for t in transactions:
            if start_inclusive < t.date < end_date:
                result.append(t)
        
        return result
    
    @classmethod
    def _generate_periods(cls, start_date: datetime, end_date: datetime, period: str, first_transaction_date: datetime = None) -> List[Dict[str, datetime]]:
        periods = []
        current_start = cls._get_period_start(start_date, period, first_transaction_date)
        
        while current_start <= end_date:
            current_end = cls._get_period_end(current_start, period)
            periods.append({'start': current_start, 'end': current_end})
            current_start = current_end
        
        return periods
    
    @classmethod
    def _format_period_key(cls, start_date: datetime, period: str) -> str:
        if period == cls.PERIOD_DAILY:
            return start_date.strftime("%Y/%m/%d")
        
        elif period in [cls.PERIOD_WEEK, cls.PERIOD_2WEEK]:
            return start_date.strftime("%Y/%m/%d")
        
        elif period == cls.PERIOD_MONTH:
            return start_date.strftime("%Y/%m")
        
        elif period == cls.PERIOD_2MONTH:
            month = start_date.month
            bi_month = (month + 1) // 2
            return f"{start_date.year}-BM{bi_month}"
        
        elif period == cls.PERIOD_QUARTER:
            quarter = (start_date.month - 1) // 3 + 1
            return f"{start_date.year}-Q{quarter}"
        
        elif period == cls.PERIOD_SEMIYEAR:
            semester = 1 if start_date.month <= 6 else 2
            return f"{start_date.year}-H{semester}"
        
        elif period == cls.PERIOD_YEAR:
            return str(start_date.year)
        
        else:
            return start_date.strftime("%Y/%m/%d")
    
    @classmethod
    def transactions_by_period(cls, transactions: List[Transaction], period: str) -> List[Dict[str, Any]]:
        if not transactions:
            return []
        
        start_date, end_date = cls._get_date_range(transactions)
        first_transaction_date = transactions[0].date if period == cls.PERIOD_2WEEK else None
        
        periods = cls._generate_periods(start_date, end_date, period, first_transaction_date)
        results = []
        
        for period_obj in periods:
            period_transactions = cls._transactions_in_date_range(transactions, period_obj['start'], period_obj['end'])
            end_day = period_obj['end'] - timedelta(days=1)
            
            results.append({
                'start': period_obj['start'],
                'end': end_day,
                'transactions': period_transactions,
                'key': cls._format_period_key(period_obj['start'], period)
            })
        
        return results
    
    @classmethod
    def balances_by_period(cls, transactions: List[Transaction], period: str, range_type: str) -> List[Dict[str, Any]]:
        if not transactions:
            return []
        
        start_date, end_date = cls._get_date_range(transactions)
        first_transaction_date = transactions[0].date if period == cls.PERIOD_2WEEK else None
        
        periods = cls._generate_periods(start_date, end_date, period, first_transaction_date)
        results = []
        running_balances = {}
        
        for period_obj in periods:
            period_transactions = cls._transactions_in_date_range(transactions, period_obj['start'], period_obj['end'])
            end_day = period_obj['end'] - timedelta(days=1)
            
            if range_type == cls.RANGE_SNAPSHOT:
                for t in period_transactions:
                    for change in t.accountChanges:
                        if change.balance is None:
                            continue
                        account_name = change.name
                        if account_name not in running_balances:
                            running_balances[account_name] = SimpleRational.zero()
                        running_balances[account_name] = running_balances[account_name].plus(change.balance)
                
                balances = []
                for name, balance in running_balances.items():
                    if not balance.is_zero():
                        balances.append(Account(name, balance))
                balances.sort(key=lambda a: a.name)
                
                results.append({
                    'start': period_obj['start'],
                    'end': end_day,
                    'balances': balances
                })
            else:
                results.append({
                    'start': period_obj['start'],
                    'end': end_day,
                    'balances': cls.get_balances(period_transactions)
                })
        
        return results


def format_duration(days: int) -> str:
    """Format duration as years, weeks and days (like Go)"""
    if days == 0:
        return "0 days"
    
    years = days // 365
    remaining_after_years = days % 365
    
    weeks = remaining_after_years // 7
    remaining_days = remaining_after_years % 7
    
    parts = []
    
    if years > 0:
        parts.append(f"{years} year{'s' if years > 1 else ''}")
    
    if weeks > 0:
        parts.append(f"{weeks} week{'s' if weeks > 1 else ''}")
    
    if remaining_days > 0:
        parts.append(f"{remaining_days} day{'s' if remaining_days > 1 else ''}")
    
    return " ".join(parts)


def print_balances(balances: List[Account], columns: int, empty: bool, depth: int):
    """Print account balances"""
    max_depth = depth if depth >= 0 else 2**31 - 1
    show_empty = empty
    
    sorted_balances = sorted(balances, key=lambda a: a.name)
    
    all_accounts = {}
    
    for account in sorted_balances:
        account_name = account.name
        parts = account_name.split(':')
        
        if account_name not in all_accounts:
            all_accounts[account_name] = SimpleRational.zero()
        all_accounts[account_name] = all_accounts[account_name].plus(account.balance)
        
        for i in range(1, len(parts)):
            parent_name = ':'.join(parts[:i])
            if parent_name not in all_accounts:
                all_accounts[parent_name] = SimpleRational.zero()
            all_accounts[parent_name] = all_accounts[parent_name].plus(account.balance)
    
    display_accounts = {}
    
    for account_name, balance in all_accounts.items():
        depth_count = account_name.count(':') + 1
        
        if depth_count <= max_depth:
            display_accounts[account_name] = balance
        else:
            parts = account_name.split(':')
            parent_name = ':'.join(parts[:max_depth])
            if parent_name not in display_accounts:
                display_accounts[parent_name] = SimpleRational.zero()
            display_accounts[parent_name] = display_accounts[parent_name].plus(balance)
    
    filtered = []
    
    for account_name, balance in display_accounts.items():
        if show_empty or balance.sign() != 0:
            parts = account_name.split(':')
            filtered.append({
                'name': account_name,
                'balance': balance,
                'parts': parts,
                'depth': len(parts)
            })
    
    filtered.sort(key=lambda x: (x['parts'], len(x['parts'])))
    
    overall_balance = SimpleRational.zero()
    for account in balances:
        overall_balance = overall_balance.plus(account.balance)
    
    for item in filtered:
        balance_str = item['balance'].to_float(DISPLAY_PRECISION)
        spaces = columns - len(item['name']) - len(balance_str)
        if spaces < 0:
            spaces = 0
        print(f"{item['name']}{' ' * spaces}{balance_str}")
    
    if filtered:
        print("-" * columns)
        balance_str = overall_balance.to_float(DISPLAY_PRECISION)
        spaces = columns - len(balance_str)
        print(f"{' ' * spaces}{balance_str}")


def print_transaction(transaction: Transaction, columns: int):
    """Print a single transaction"""
    for comment in transaction.comments:
        print(comment)
    
    print(f"{transaction.date.strftime(TRANSACTION_DATE_FORMAT)} {transaction.payee}")
    
    max_name_length = 0
    for change in transaction.accountChanges:
        name_length = len(change.name)
        if name_length > max_name_length:
            max_name_length = name_length
    
    available_width = columns - 4
    value_width = 12
    name_column = min(max_name_length + 4, available_width - value_width)
    name_column = min(name_column, 50)
    
    for change in transaction.accountChanges:
        if change.balance:
            balance_str = change.balance.to_float(DISPLAY_PRECISION)
            name = change.name
            name_length = len(name)
            
            if name_length > name_column - 4:
                max_display_length = name_column - 7
                if max_display_length > 10:
                    name = name[:max_display_length] + "..."
                    name_length = len(name)
            
            total_spaces = available_width - name_length - len(balance_str)
            if total_spaces < 2:
                total_spaces = 2
            
            print(f"    {name}{' ' * total_spaces}{balance_str}")
        else:
            print(f"    {change.name}")
    
    print()


def print_register(transactions: List[Transaction], filters: List[str], columns: int):
    """Print register output"""
    if not transactions:
        print("No transactions in the period.")
        return
    
    remaining_width = columns - (10 * 3) - 4
    col1width = remaining_width // 3
    col2width = remaining_width - col1width
    
    running_balance = SimpleRational.zero()
    
    for transaction in transactions:
        for change in transaction.accountChanges:
            if change.balance is None:
                continue
            
            in_filter = not filters
            if not in_filter:
                for f in filters:
                    if f in change.name:
                        in_filter = True
                        break
            
            if in_filter:
                running_balance = running_balance.plus(change.balance)
                balance_str = change.balance.to_float(DISPLAY_PRECISION)
                running_str = running_balance.to_float(DISPLAY_PRECISION)
                
                print(f"{transaction.date.strftime(TRANSACTION_DATE_FORMAT):<10} "
                      f"{transaction.payee[:col1width]:<{col1width}} "
                      f"{change.name[:col2width]:<{col2width}} "
                      f"{balance_str:>10} "
                      f"{running_str:>10}")


def show_usage():
    """Show usage information"""
    print("Ledger CLI in Python")
    print("====================")
    print()
    print("Usage: python ledger.py [OPTIONS] COMMAND [FILTERS]")
    print()
    print("Commands:")
    print("  bal, balance    Account balance summary")
    print("  print           Print formatted ledger")
    print("  reg, register   Filtered register")
    print("  stats           Ledger statistics")
    print("  accounts        List all accounts")
    print()
    print("Options:")
    print("  -f FILE         Ledger file (*required) or '-' for stdin")
    print("  -b DATE         Start date (default: 1970/01/01)")
    print("  -e DATE         End date (default: today)")
    print("  --period=PERIOD Period (Daily, Weekly, BiWeekly, Monthly, BiMonthly, Quarterly, SemiYearly, Yearly)")
    print("  --payee=STR     Filter by payee")
    print("  --empty         Show zero balance accounts")
    print("  --depth=N       Transaction depth")
    print("  --columns=N     Column width (default: 79)")
    print("  --wide          Wide mode (132 columns)")
    print("  --help          Show this help")
    print()
    print("Examples:")
    print("  python ledger.py -f Journal.txt bal")
    print("  python ledger.py -f Journal.txt bal Assets")
    print("  python ledger.py -f Journal.txt reg Expenses")
    print("  python ledger.py -f Journal.txt --period=Monthly reg")
    print("  python ledger.py -f Journal.txt stats")
    print("  cat Journal.txt | python ledger.py -f - bal")


def main():
    """Main entry point"""
    import argparse
    from datetime import datetime as dt
    from datetime import timezone as tz
    
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('-f', dest='file', default='')
    parser.add_argument('-b', dest='start_date', default='1970/01/01')
    parser.add_argument('-e', dest='end_date', default='')
    parser.add_argument('--period', dest='period', default='')
    parser.add_argument('--payee', dest='payee', default='')
    parser.add_argument('--empty', dest='empty', action='store_true', default=False)
    parser.add_argument('--depth', dest='depth', type=int, default=-1)
    parser.add_argument('--columns', dest='columns', type=int, default=79)
    parser.add_argument('--wide', dest='wide', action='store_true', default=False)
    parser.add_argument('--help', dest='help', action='store_true', default=False)
    parser.add_argument('command', nargs='?', default='')
    parser.add_argument('filters', nargs='*', default=[])
    
    args = parser.parse_args()
    
    if args.help:
        show_usage()
        return
    
    if args.wide:
        args.columns = 132
    
    if not args.file:
        show_usage()
        return
    
    if not args.command:
        show_usage()
        return
    
    if not args.end_date:
        # Use timezone-aware UTC datetime to avoid deprecation warning
        args.end_date = dt.now(tz.utc).strftime(TRANSACTION_DATE_FORMAT)
    
    try:
        if args.file == '-':
            content = sys.stdin.read()
        else:
            with open(args.file, 'r', encoding='utf-8') as f:
                content = f.read()
        
        transactions = Parser.parse_ledger(content)
        
        start_time = dt.strptime(args.start_date, TRANSACTION_DATE_FORMAT)
        end_time = dt.strptime(args.end_date, TRANSACTION_DATE_FORMAT)
        transactions = [t for t in transactions if start_time <= t.date <= end_time]
        
        if args.payee:
            transactions = [t for t in transactions if args.payee.lower() in t.payee.lower()]
        
        if args.command in ['balance', 'bal']:
            if not args.period:
                balances = Ledger.get_balances(transactions, args.filters)
                print_balances(balances, args.columns, args.empty, args.depth)
            else:
                ranges = Ledger.balances_by_period(transactions, args.period, Ledger.RANGE_PARTITION)
                for i, r in enumerate(ranges):
                    if i > 0:
                        print()
                        print("=" * args.columns)
                    print(f"{r['start'].strftime(TRANSACTION_DATE_FORMAT)} - {r['end'].strftime(TRANSACTION_DATE_FORMAT)}")
                    print("=" * args.columns)
                    print_balances(r['balances'], args.columns, args.empty, args.depth)
        
        elif args.command in ['print']:
            for transaction in transactions:
                in_filter = not args.filters
                if not in_filter:
                    for change in transaction.accountChanges:
                        for f in args.filters:
                            if f in change.name:
                                in_filter = True
                                break
                        if in_filter:
                            break
                if in_filter:
                    print_transaction(transaction, args.columns)
        
        elif args.command in ['register', 'reg']:
            if not args.period:
                print_register(transactions, args.filters, args.columns)
            else:
                ranges = Ledger.transactions_by_period(transactions, args.period)
                for i, r in enumerate(ranges):
                    if i > 0:
                        print("=" * args.columns)
                    print(f"{r['start'].strftime(TRANSACTION_DATE_FORMAT)} - {r['end'].strftime(TRANSACTION_DATE_FORMAT)}")
                    print("=" * args.columns)
                    print_register(r['transactions'], args.filters, args.columns)
        
        elif args.command in ['stats']:
            if not transactions:
                print("Empty ledger.")
            else:
                start_d = transactions[0].date
                end_d = transactions[-1].date
                days = (end_d - start_d).days
                
                period_string = format_duration(days)
                trans_per_day = len(transactions) / days if days > 0 else len(transactions)
                
                payees = {}
                accounts = {}
                postings = 0
                last_date = None
                
                for transaction in transactions:
                    payees[transaction.payee] = True
                    for change in transaction.accountChanges:
                        accounts[change.name] = True
                        postings += 1
                    last_date = transaction.date
                
                now = dt.now(tz.utc)
                last_midnight = dt(last_date.year, last_date.month, last_date.day, 0, 0, 0, tzinfo=tz.utc)
                seconds_since_last = (now - last_midnight).total_seconds()
                hours_since_last = int(seconds_since_last / 3600)
                if seconds_since_last % 3600 > 0:
                    hours_since_last += 1
                
                days_since_last = hours_since_last // 24
                time_since_last_post = format_duration(days_since_last)
                
                postings_per_day = postings / days if days > 0 else postings
                
                print(f"Time period               : {start_d.strftime('%Y-%m-%d')} to {end_d.strftime('%Y-%m-%d')} ({period_string})")
                print(f"Unique payees             : {len(payees)}")
                print(f"Unique accounts           : {len(accounts)}")
                print(f"Number of transactions    : {len(transactions)} ({trans_per_day:.1f} per day)")
                print(f"Number of postings        : {postings} ({postings_per_day:.1f} per day)")
                print(f"Time since last post      : {time_since_last_post}")
        
        elif args.command in ['accounts']:
            all_accounts = {}
            for transaction in transactions:
                for change in transaction.accountChanges:
                    all_accounts[change.name] = True
            print("Accounts in ledger:")
            print("-" * args.columns)
            for account in sorted(all_accounts.keys()):
                print(account)
            print("-" * args.columns)
            print(f"Total: {len(all_accounts)} accounts")
        
        else:
            print(f"Command '{args.command}' not implemented.")
            show_usage()
            sys.exit(1)
    
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
