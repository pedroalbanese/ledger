#!/usr/bin/env crystal

# ==============================
# Main Classes
# ==============================

class SimpleRational
  property value : Float64

  def initialize(value : String | Int | Float)
    if value.is_a?(String)
      str = value.strip
      str = str.gsub(',', '.')
      if str =~ /^\(.+\)$/
        str = str.gsub(/[\(\)]/, "")
        str = "-#{str}"
      end
      str = str.gsub(/[\$\€\£\¥]/, "")
      @value = str.to_f
    else
      @value = value.to_f
    end
  end

  def self.of(value) : SimpleRational
    SimpleRational.new(value)
  end

  def self.zero : SimpleRational
    SimpleRational.new(0)
  end

  def plus(other : SimpleRational) : SimpleRational
    SimpleRational.new(@value + other.value)
  end

  def negated : SimpleRational
    SimpleRational.new(-@value)
  end

  def zero? : Bool
    @value.abs < 0.000001
  end

  def to_f(precision : Int32 = 2) : String
    sprintf("%.#{precision}f", @value)
  end

  def value : Float64
    @value
  end

  def to_s(io : IO) : Nil
    io << to_f
  end

  def sign : Int32
    return 1 if @value > 0.000001
    return -1 if @value < -0.000001
    0
  end

  def equals(other : SimpleRational) : Bool
    (@value - other.value).abs < 0.000001
  end
end

class Account
  property name : String
  property balance : SimpleRational

  def initialize(@name : String, @balance : SimpleRational)
  end
end

class AccountChange
  property name : String
  property balance : SimpleRational?

  def initialize(@name : String, @balance = nil)
  end

  def to_s(io : IO) : Nil
    if balance = @balance
      io << @name << "    " << balance.to_f
    else
      io << @name
    end
  end
end

class Transaction
  property payee : String
  property date : Time
  property accountChanges : Array(AccountChange)
  property comments : Array(String)

  def initialize(@payee : String, @date : Time)
    @accountChanges = [] of AccountChange
    @comments = [] of String
  end

  def to_s(io : IO) : Nil
    io << @date.to_s("%Y/%m/%d") << " " << @payee << "\n"
    @accountChanges.each do |change|
      io << "    " << change << "\n"
    end
  end
end

# ==============================
# Parser - Exatamente como o PHP
# ==============================

class Parser
  def self.parse_ledger(content : String) : Array(Transaction)
    transactions = [] of Transaction
    lines = content.lines

    current_transaction = nil
    comments = [] of String

    lines.each do |line|
      line = line.rstrip

      if line.strip.empty?
        if current_transaction
          finalize_transaction(current_transaction)
          current_transaction.comments = comments
          transactions << current_transaction
          current_transaction = nil
          comments = [] of String
        end
        next
      end

      if line.strip.starts_with?(';')
        comments << line
        next
      end

      if line =~ /^(\d{4}[\/\-\.]\d{1,2}[\/\-\.]\d{1,2})\s+(.+)$/
        if current_transaction
          finalize_transaction(current_transaction)
          current_transaction.comments = comments
          transactions << current_transaction
          comments = [] of String
        end

        date_string = $1
        payee = $2

        begin
          date = parse_date(date_string)
          current_transaction = Transaction.new(payee, date)
        rescue
          next
        end
      elsif current_transaction && (line.starts_with?("    ") || line.starts_with?("\t"))
        account_change = parse_account_line(line.strip)
        if account_change
          current_transaction.accountChanges << account_change
        end
      end
    end

    if current_transaction
      finalize_transaction(current_transaction)
      current_transaction.comments = comments
      transactions << current_transaction
    end

    transactions.sort_by! { |t| t.date }
    transactions
  end

  private def self.parse_date(date_string : String) : Time
    date_string = date_string.gsub(/[\.\-]/, "/")
    Time.parse_utc(date_string, "%Y/%m/%d")
  rescue
    raise "Invalid date: #{date_string}"
  end

  private def self.parse_account_line(line : String) : AccountChange?
    line = line.strip

    if line =~ /^(.*?)\s{2,}(.+)$/
      account_name = $1.strip
      value_str = $2.strip
      balance = parse_balance(value_str)
      return AccountChange.new(account_name, balance)
    end

    parts = line.split(/\s+/)
    if parts.size >= 2
      last_part = parts.last
      balance = parse_balance(last_part)
      if balance
        account_name = parts[0...-1].join(" ")
        return AccountChange.new(account_name, balance)
      end
    end

    AccountChange.new(line)
  end

  private def self.parse_balance(value_str : String) : SimpleRational?
    value_str = value_str.strip
    return nil if value_str.empty?

    test_str = value_str
    is_negative = false

    if test_str =~ /^\((.+)\)$/
      test_str = $1
      is_negative = true
    end

    test_str = test_str.gsub(/[\$\€\£\¥\s,]/, "")
    test_str = test_str.gsub(',', '.')

    if test_str =~ /^-?\d+\.?\d*$/
      value = test_str.to_f
      value = -value if is_negative
      return SimpleRational.of(value)
    end

    nil
  end

  private def self.finalize_transaction(transaction : Transaction)
    total = SimpleRational.zero
    empty_index = -1

    transaction.accountChanges.each_with_index do |change, index|
      if change.balance.nil?
        if empty_index != -1
          raise "Multiple empty accounts in transaction: #{transaction.payee}"
        end
        empty_index = index
      else
        total = total.plus(change.balance.not_nil!)
      end
    end

    if empty_index != -1
      transaction.accountChanges[empty_index].balance = total.negated
    end

    check_total = SimpleRational.zero
    transaction.accountChanges.each do |change|
      if balance = change.balance
        check_total = check_total.plus(balance)
      end
    end

    unless check_total.zero?
      if check_total.value.abs > 0.01
        raise "Transaction not balanced: #{transaction.payee} (diff: #{check_total})"
      end
    end
  end
end

# ==============================
# Ledger - Exatamente como o PHP
# ==============================

class Ledger
  PERIOD_DAILY     = "Daily"      # ADDED
  PERIOD_WEEK      = "Weekly"
  PERIOD_2WEEK     = "BiWeekly"
  PERIOD_MONTH     = "Monthly"
  PERIOD_2MONTH    = "BiMonthly"
  PERIOD_QUARTER   = "Quarterly"
  PERIOD_SEMIYEAR  = "SemiYearly"
  PERIOD_YEAR      = "Yearly"

  RANGE_PARTITION  = "Partition"
  RANGE_SNAPSHOT   = "Snapshot"

  def self.get_balances(transactions : Array(Transaction), filters : Array(String) = [] of String) : Array(Account)
    balances = {} of String => SimpleRational

    transactions.each do |transaction|
      transaction.accountChanges.each do |account_change|
        balance = account_change.balance
        next unless balance

        include_account = filters.empty?
        unless include_account
          filters.each do |filter|
            if account_change.name.includes?(filter)
              include_account = true
              break
            end
          end
        end

        if include_account
          account_name = account_change.name
          balances[account_name] = SimpleRational.zero unless balances.has_key?(account_name)
          balances[account_name] = balances[account_name].not_nil!.plus(balance)
        end
      end
    end

    result = balances.map do |name, balance|
      Account.new(name, balance)
    end

    result.sort_by! { |a| a.name }
    result
  end

  private def self.get_period_start(date : Time, period : String, first_transaction_date : Time? = nil) : Time
    start_date = date
    start_date = Time.utc(start_date.year, start_date.month, start_date.day, 0, 0, 0)

    case period
    when PERIOD_DAILY     # ADDED
      # Daily periods start at midnight of each day
      # No adjustment needed
      
    when PERIOD_WEEK
      day_of_week = start_date.day_of_week.value % 7
      if day_of_week > 0
        start_date = start_date - day_of_week.days
      end
    when PERIOD_2WEEK
      if first_transaction_date
        start_date = first_transaction_date
        start_date = Time.utc(start_date.year, start_date.month, start_date.day, 0, 0, 0)
      end
      day_of_week = start_date.day_of_week.value % 7
      if day_of_week > 0
        start_date = start_date - day_of_week.days
      end
    when PERIOD_MONTH
      start_date = Time.utc(start_date.year, start_date.month, 1, 0, 0, 0)
    when PERIOD_2MONTH
      month = start_date.month
      year = start_date.year
      start_month = month.odd? ? month : month - 1
      start_date = Time.utc(year, start_month, 1, 0, 0, 0)
    when PERIOD_QUARTER
      month = start_date.month
      year = start_date.year
      if month <= 3
        start_date = Time.utc(year, 1, 1, 0, 0, 0)
      elsif month <= 6
        start_date = Time.utc(year, 4, 1, 0, 0, 0)
      elsif month <= 9
        start_date = Time.utc(year, 7, 1, 0, 0, 0)
      else
        start_date = Time.utc(year, 10, 1, 0, 0, 0)
      end
    when PERIOD_SEMIYEAR
      month = start_date.month
      year = start_date.year
      if month <= 6
        start_date = Time.utc(year, 1, 1, 0, 0, 0)
      else
        start_date = Time.utc(year, 7, 1, 0, 0, 0)
      end
    when PERIOD_YEAR
      start_date = Time.utc(start_date.year, 1, 1, 0, 0, 0)
    end

    start_date
  end

  private def self.get_period_end(start_date : Time, period : String) : Time
    end_date = start_date

    case period
    when PERIOD_DAILY     # ADDED
      end_date = start_date + 1.day
      
    when PERIOD_WEEK
      end_date = start_date + 7.days
    when PERIOD_2WEEK
      end_date = start_date + 14.days
    when PERIOD_MONTH
      if start_date.month == 12
        end_date = Time.utc(start_date.year + 1, 1, 1, 0, 0, 0)
      else
        end_date = Time.utc(start_date.year, start_date.month + 1, 1, 0, 0, 0)
      end
    when PERIOD_2MONTH
      if start_date.month >= 11
        end_date = Time.utc(start_date.year + 1, (start_date.month + 2) % 12, 1, 0, 0, 0)
      else
        end_date = Time.utc(start_date.year, start_date.month + 2, 1, 0, 0, 0)
      end
    when PERIOD_QUARTER
      if start_date.month >= 10
        end_date = Time.utc(start_date.year + 1, (start_date.month + 3) % 12, 1, 0, 0, 0)
      else
        end_date = Time.utc(start_date.year, start_date.month + 3, 1, 0, 0, 0)
      end
    when PERIOD_SEMIYEAR
      if start_date.month >= 7
        end_date = Time.utc(start_date.year + 1, (start_date.month + 6) % 12, 1, 0, 0, 0)
      else
        end_date = Time.utc(start_date.year, start_date.month + 6, 1, 0, 0, 0)
      end
    when PERIOD_YEAR
      end_date = Time.utc(start_date.year + 1, 1, 1, 0, 0, 0)
    end

    end_date
  end

  private def self.get_date_range(transactions : Array(Transaction)) : {Time?, Time?}
    return {nil, nil} if transactions.empty?
    start_date = transactions[0].date
    end_date = transactions[0].date

    transactions.each do |transaction|
      end_date = transaction.date if transaction.date > end_date
      start_date = transaction.date if transaction.date < start_date
    end

    {start_date, end_date}
  end

  private def self.transactions_in_date_range(transactions : Array(Transaction), start_date : Time, end_date : Time) : Array(Transaction)
    result = [] of Transaction
    start_inclusive = start_date - 1.second

    transactions.each do |transaction|
      if transaction.date > start_inclusive && transaction.date < end_date
        result << transaction
      end
    end

    result
  end

  private def self.generate_periods(start_date : Time, end_date : Time, period : String, first_transaction_date : Time? = nil) : Array(NamedTuple(start: Time, end: Time))
    periods = [] of NamedTuple(start: Time, end: Time)
    current_start = get_period_start(start_date, period, first_transaction_date)

    while current_start <= end_date
      current_end = get_period_end(current_start, period)
      periods << {start: current_start, end: current_end}
      current_start = current_end
    end

    periods
  end

  private def self.format_period_key(start_date : Time, period : String) : String
    case period
    when PERIOD_DAILY     # ADDED
      start_date.to_s("%Y/%m/%d")
      
    when PERIOD_WEEK, PERIOD_2WEEK
      start_date.to_s("%Y/%m/%d")
    when PERIOD_MONTH
      start_date.to_s("%Y/%m")
    when PERIOD_2MONTH
      month = start_date.month
      bi_month = (month.to_f / 2).ceil.to_i
      "#{start_date.year}-BM#{bi_month}"
    when PERIOD_QUARTER
      quarter = ((start_date.month - 1) / 3).to_i + 1
      "#{start_date.year}-Q#{quarter}"
    when PERIOD_SEMIYEAR
      semester = start_date.month <= 6 ? 1 : 2
      "#{start_date.year}-H#{semester}"
    when PERIOD_YEAR
      start_date.year.to_s
    else
      start_date.to_s("%Y/%m/%d")
    end
  end

  def self.transactions_by_period(transactions : Array(Transaction), period : String) : Array(NamedTuple(start: Time, end: Time, transactions: Array(Transaction), key: String))
    return [] of NamedTuple(start: Time, end: Time, transactions: Array(Transaction), key: String) if transactions.empty?

    start_date, end_date = get_date_range(transactions)
    first_transaction_date = (period == PERIOD_2WEEK && !transactions.empty?) ? transactions[0].date : nil

    periods = generate_periods(start_date.not_nil!, end_date.not_nil!, period, first_transaction_date)
    results = [] of NamedTuple(start: Time, end: Time, transactions: Array(Transaction), key: String)

    periods.each do |period_obj|
      period_transactions = transactions_in_date_range(transactions, period_obj[:start], period_obj[:end])
      end_day = period_obj[:end] - 1.day

      results << {
        start:        period_obj[:start],
        end:          end_day,
        transactions: period_transactions,
        key:          format_period_key(period_obj[:start], period)
      }
    end

    results
  end

  def self.balances_by_period(transactions : Array(Transaction), period : String, range_type : String) : Array(NamedTuple(start: Time, end: Time, balances: Array(Account)))
    return [] of NamedTuple(start: Time, end: Time, balances: Array(Account)) if transactions.empty?

    start_date, end_date = get_date_range(transactions)
    first_transaction_date = (period == PERIOD_2WEEK && !transactions.empty?) ? transactions[0].date : nil

    periods = generate_periods(start_date.not_nil!, end_date.not_nil!, period, first_transaction_date)
    results = [] of NamedTuple(start: Time, end: Time, balances: Array(Account))
    running_balances = {} of String => SimpleRational

    periods.each do |period_obj|
      period_transactions = transactions_in_date_range(transactions, period_obj[:start], period_obj[:end])
      end_day = period_obj[:end] - 1.day

      if range_type == RANGE_SNAPSHOT
        period_transactions.each do |transaction|
          transaction.accountChanges.each do |change|
            balance = change.balance
            next unless balance

            account_name = change.name
            running_balances[account_name] = SimpleRational.zero unless running_balances.has_key?(account_name)
            running_balances[account_name] = running_balances[account_name].not_nil!.plus(balance)
          end
        end

        balances = [] of Account
        running_balances.each do |name, balance|
          unless balance.zero?
            balances << Account.new(name, balance)
          end
        end
        balances.sort_by! { |a| a.name }

        results << {start: period_obj[:start], end: end_day, balances: balances}
      else
        results << {start: period_obj[:start], end: end_day, balances: get_balances(period_transactions)}
      end
    end

    results
  end
end

# ==============================
# CLI - Main Application
# ==============================

TRANSACTION_DATE_FORMAT = "%Y/%m/%d"
DISPLAY_PRECISION = 2

# Função auxiliar para formatar duração como no Go (com anos, semanas e dias)
def format_duration(days : Int) : String
  return "0 days" if days == 0
  
  years = days // 365
  remaining_after_years = days % 365
  
  weeks = remaining_after_years // 7
  remaining_days = remaining_after_years % 7
  
  parts = [] of String
  
  if years > 0
    parts << "#{years} year#{years > 1 ? "s" : ""}"
  end
  
  if weeks > 0
    parts << "#{weeks} week#{weeks > 1 ? "s" : ""}"
  end
  
  if remaining_days > 0
    parts << "#{remaining_days} day#{remaining_days > 1 ? "s" : ""}"
  end
  
  parts.join(" ")
end

def main
  args = ARGV.dup
  
  file = ""
  command = ""
  filters = [] of String
  start_date = "1970/01/01"
  end_date = ""
  period = ""
  payee = ""
  empty = false
  depth = -1
  columns = 79
  wide = false

  i = 0
  while i < args.size
    arg = args[i]
    
    case arg
    when "-f"
      if i + 1 < args.size
        file = args[i + 1]
        i += 1
      end
    when "-b"
      if i + 1 < args.size
        start_date = args[i + 1]
        i += 1
      end
    when "-e"
      if i + 1 < args.size
        end_date = args[i + 1]
        i += 1
      end
    when "--period"
      if i + 1 < args.size
        period = args[i + 1]
        i += 1
      end
    when "--payee"
      if i + 1 < args.size
        payee = args[i + 1]
        i += 1
      end
    when "--empty"
      empty = true
    when "--depth"
      if i + 1 < args.size
        depth = args[i + 1].to_i
        i += 1
      end
    when "--columns"
      if i + 1 < args.size
        columns = args[i + 1].to_i
        i += 1
      end
    when "--wide"
      wide = true
    when "--help"
      show_usage
      return
    else
      if arg.starts_with?("-")
        STDERR.puts "Unknown option: #{arg}"
        show_usage
        exit(1)
      elsif command.empty?
        command = arg
      else
        filters << arg
      end
    end
    
    i += 1
  end

  if wide
    columns = 132
  end

  if file.empty?
    STDERR.puts "Error: Ledger file required (-f option)"
    show_usage
    exit(1)
  end

  if command.empty?
    STDERR.puts "Error: Command required"
    show_usage
    exit(1)
  end

  if end_date.empty?
    now = Time.utc
    end_date = now.to_s(TRANSACTION_DATE_FORMAT)
  end

  begin
    content = file == "-" ? STDIN.gets_to_end : File.read(file)
    transactions = Parser.parse_ledger(content)
    
    start_time = Time.parse_utc(start_date, TRANSACTION_DATE_FORMAT)
    end_time = Time.parse_utc(end_date, TRANSACTION_DATE_FORMAT)
    transactions = transactions.select { |t| t.date >= start_time && t.date <= end_time }
    
    unless payee.empty?
      transactions = transactions.select { |t| t.payee.downcase.includes?(payee.downcase) }
    end
    
    case command
    when "balance", "bal"
      if period.empty?
        balances = Ledger.get_balances(transactions, filters)
        print_balances(balances, columns, empty, depth)
      else
        ranges = Ledger.balances_by_period(transactions, period, Ledger::RANGE_PARTITION)
        ranges.each_with_index do |range, i|
          puts "\n" + "=" * columns if i > 0
          puts "#{range[:start].to_s(TRANSACTION_DATE_FORMAT)} - #{range[:end].to_s(TRANSACTION_DATE_FORMAT)}"
          puts "=" * columns
          print_balances(range[:balances], columns, empty, depth)
        end
      end
    when "print"
      transactions.each do |transaction|
        in_filter = filters.empty?
        unless in_filter
          transaction.accountChanges.each do |account_change|
            filters.each do |filter|
              if account_change.name.includes?(filter)
                in_filter = true
                break
              end
            end
            break if in_filter
          end
        end
        print_transaction(transaction, columns) if in_filter
      end
    when "register", "reg"
      if period.empty?
        print_register(transactions, filters, columns)
      else
        ranges = Ledger.transactions_by_period(transactions, period)
        ranges.each_with_index do |range, i|
          puts "=" * columns if i > 0
          puts "#{range[:start].to_s(TRANSACTION_DATE_FORMAT)} - #{range[:end].to_s(TRANSACTION_DATE_FORMAT)}"
          puts "=" * columns
          print_register(range[:transactions], filters, columns)
        end
      end
    when "stats"
      if transactions.empty?
        puts "Empty ledger."
      else
        start_d = transactions[0].date
        end_d = transactions.last.date
        days = (end_d - start_d).total_days.to_i
        
        # Formatar período como anos, semanas e dias (como no Go)
        period_string = format_duration(days)
        
        trans_per_day = days > 0 ? transactions.size / days : transactions.size

        payees = {} of String => Bool
        accounts = {} of String => Bool
        postings = 0
        last_date = nil

        transactions.each do |transaction|
          payees[transaction.payee] = true
          transaction.accountChanges.each do |account_change|
            accounts[account_change.name] = true
            postings += 1
          end
          last_date = transaction.date
        end

        now = Time.utc
        last_midnight = Time.utc(last_date.not_nil!.year, last_date.not_nil!.month, last_date.not_nil!.day, 0, 0, 0)
        seconds_since_last = (now - last_midnight).total_seconds.to_i
        hours_since_last = seconds_since_last / 3600
        hours_since_last += 1 if (seconds_since_last % 3600) > 0

        # Calcular dias desde último post como inteiro
        days_since_last = (hours_since_last / 24).to_i
        
        # Formatar tempo desde último post como anos, semanas e dias (como no Go)
        time_since_last_post = format_duration(days_since_last)

        postings_per_day = days > 0 ? postings / days : postings

        puts "Time period               : #{start_d.to_s("%Y-%m-%d")} to #{end_d.to_s("%Y-%m-%d")} (#{period_string})"
        puts "Unique payees             : #{payees.size}"
        puts "Unique accounts           : #{accounts.size}"
        puts "Number of transactions    : #{transactions.size} (#{"%.1f" % trans_per_day} per day)"
        puts "Number of postings        : #{postings} (#{"%.1f" % postings_per_day} per day)"
        puts "Time since last post      : #{time_since_last_post}"
      end
    when "accounts"
      all_accounts = {} of String => Bool
      transactions.each do |transaction|
        transaction.accountChanges.each do |account_change|
          all_accounts[account_change.name] = true
        end
      end
      puts "Accounts in ledger:"
      puts "-" * columns
      all_accounts.keys.sort.each do |account|
        puts account
      end
      puts "-" * columns
      printf("Total: %d accounts\n", all_accounts.size)
    else
      STDERR.puts "Command '#{command}' not implemented."
      show_usage
      exit(1)
    end
  rescue e
    STDERR.puts "Error: #{e.message}"
    exit(1)
  end
end

def print_balances(balances : Array(Account), columns : Int32, empty : Bool, depth : Int32)
  max_depth = depth < 0 ? Int32::MAX : depth
  show_empty = empty

  sorted = balances.sort_by { |a| a.name }

  all_accounts = {} of String => SimpleRational

  sorted.each do |account|
    account_name = account.name
    parts = account_name.split(':')

    all_accounts[account_name] = SimpleRational.zero unless all_accounts.has_key?(account_name)
    all_accounts[account_name] = all_accounts[account_name].not_nil!.plus(account.balance)

    (1...parts.size).each do |i|
      parent_name = parts[0...i].join(':')
      all_accounts[parent_name] = SimpleRational.zero unless all_accounts.has_key?(parent_name)
      all_accounts[parent_name] = all_accounts[parent_name].not_nil!.plus(account.balance)
    end
  end

  display_accounts = {} of String => SimpleRational

  all_accounts.each do |account_name, balance|
    depth_count = account_name.count(':') + 1

    if depth_count <= max_depth
      display_accounts[account_name] = balance
    else
      parts = account_name.split(':')
      parent_name = parts[0...max_depth].join(':')
      display_accounts[parent_name] = SimpleRational.zero unless display_accounts.has_key?(parent_name)
      display_accounts[parent_name] = display_accounts[parent_name].not_nil!.plus(balance)
    end
  end

  filtered = [] of NamedTuple(name: String, balance: SimpleRational, parts: Array(String), depth: Int32)

  display_accounts.each do |account_name, balance|
    if show_empty || balance.sign != 0
      parts = account_name.split(':')
      filtered << {
        name:    account_name,
        balance: balance,
        parts:   parts,
        depth:   parts.size
      }
    end
  end

  filtered.sort! do |a, b|
    cmp = 0
    (0...[a[:parts].size, b[:parts].size].min).each do |i|
      cmp = a[:parts][i] <=> b[:parts][i]
      break if cmp != 0
    end
    cmp = a[:parts].size <=> b[:parts].size if cmp == 0
    cmp
  end

  overall_balance = SimpleRational.zero
  balances.each do |account|
    overall_balance = overall_balance.plus(account.balance)
  end

  filtered.each do |item|
    balance_str = item[:balance].to_f(DISPLAY_PRECISION)
    spaces = columns - item[:name].size - balance_str.size
    spaces = 0 if spaces < 0
    puts "#{item[:name]}#{" " * spaces}#{balance_str}"
  end

  unless filtered.empty?
    puts "-" * columns
    balance_str = overall_balance.to_f(DISPLAY_PRECISION)
    spaces = columns - balance_str.size
    puts "#{" " * spaces}#{balance_str}"
  end
end

def print_transaction(transaction : Transaction, columns : Int32)
  transaction.comments.each do |comment|
    puts comment
  end

  puts "#{transaction.date.to_s(TRANSACTION_DATE_FORMAT)} #{transaction.payee}"

  max_name_length = 0
  transaction.accountChanges.each do |account_change|
    name_length = account_change.name.size
    max_name_length = name_length if name_length > max_name_length
  end

  available_width = columns - 4
  value_width = 12
  name_column = (max_name_length + 4).clamp(0, available_width - value_width)
  name_column = 50 if name_column > 50

  transaction.accountChanges.each do |account_change|
    if balance = account_change.balance
      balance_str = balance.to_f(DISPLAY_PRECISION)
      name = account_change.name
      name_length = name.size

      if name_length > name_column - 4
        max_display_length = name_column - 7
        if max_display_length > 10
          name = name[0...max_display_length] + "..."
          name_length = name.size
        end
      end

      total_spaces = available_width - name_length - balance_str.size
      total_spaces = 2 if total_spaces < 2

      puts "    #{name}#{" " * total_spaces}#{balance_str}"
    else
      puts "    #{account_change.name}"
    end
  end

  puts ""
end

def print_register(transactions : Array(Transaction), filters : Array(String), columns : Int32)
  if transactions.empty?
    puts "No transactions in the period."
    return
  end

  remaining_width = columns - (10 * 3) - 4
  col1width = (remaining_width / 3).to_i
  col2width = remaining_width - col1width

  format_str = "%-10.10s %-#{col1width}.#{col1width}s %-#{col2width}.#{col2width}s %10.10s %10.10s"

  running_balance = SimpleRational.zero

  transactions.each do |transaction|
    transaction.accountChanges.each do |account_change|
      balance = account_change.balance
      next unless balance

      in_filter = filters.empty?

      unless in_filter
        filters.each do |filter|
          if account_change.name.includes?(filter)
            in_filter = true
            break
          end
        end
      end

      if in_filter
        running_balance = running_balance.plus(balance)
        balance_str = balance.to_f(DISPLAY_PRECISION)
        running_str = running_balance.to_f(DISPLAY_PRECISION)

        printf(
          format_str,
          transaction.date.to_s(TRANSACTION_DATE_FORMAT),
          transaction.payee[0, col1width],
          account_change.name[0, col2width],
          balance_str,
          running_str
        )
        puts ""
      end
    end
  end
end

def show_usage
  puts "Ledger CLI in Crystal"
  puts "================="
  puts ""
  puts "Usage: ledger [OPTIONS] COMMAND [FILTERS]"
  puts ""
  puts "Commands:"
  puts "  bal, balance    Account balance summary"
  puts "  print           Print formatted ledger"
  puts "  reg, register   Filtered register"
  puts "  stats           Ledger statistics"
  puts "  accounts        List all accounts"
  puts ""
  puts "Options:"
  puts "  -f FILE         Ledger file (*required) or '-' for stdin"
  puts "  -b DATE         Start date (default: 1970/01/01)"
  puts "  -e DATE         End date (default: today)"
  puts "  --period=PERIOD Period (Daily, Weekly, BiWeekly, Monthly, BiMonthly, Quarterly, SemiYearly, Yearly)"
  puts "  --payee=STR     Filter by payee"
  puts "  --empty         Show zero balance accounts"
  puts "  --depth=N       Transaction depth"
  puts "  --columns=N     Column width (default: 79)"
  puts "  --wide          Wide mode (132 columns)"
  puts "  --help          Show this help"
  puts ""
  puts "Examples:"
  puts "  ./ledger -f Journal.txt bal"
  puts "  ./ledger -f Journal.txt bal Assets"
  puts "  ./ledger -f Journal.txt reg Expenses"
  puts "  ./ledger -f Journal.txt --period=Monthly reg"
  puts "  ./ledger -f Journal.txt stats"
  puts "  cat Journal.txt | ./ledger -f - bal"
end

# Main execution
main
