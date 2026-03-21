#!/usr/bin/env crystal

TRANSACTION_DATE_FORMAT = "%Y/%m/%d"
DISPLAY_PRECISION = 2

class SimpleRational
  property value : Float64

  def initialize(value : Float64)
    @value = value
  end

  def self.zero
    SimpleRational.new(0.0)
  end

  def plus(other : SimpleRational)
    SimpleRational.new(@value + other.value)
  end

  def zero?
    @value.abs < 0.000001
  end

  def to_f(precision = 2)
    sprintf("%.#{precision}f", @value)
  end
end

class AccountChange
  property name : String
  property balance : SimpleRational?

  def initialize(@name : String, @balance = nil)
  end
end

class Transaction
  property payee : String
  property date : Time
  property accountChanges : Array(AccountChange)

  def initialize(@payee : String, @date : Time)
    @accountChanges = [] of AccountChange
  end
end

class Parser
  def self.parse_ledger(content : String)
    transactions = [] of Transaction
    lines = content.lines
    current = nil

    lines.each do |line|
      line = line.rstrip
      next if line.strip.empty?
      next if line.strip.starts_with?(';')

      if line =~ /^(\d{4}\/\d{2}\/\d{2})\s+(.+)$/
        if current
          transactions << current
        end
        date = Time.parse_utc($1, "%Y/%m/%d")
        current = Transaction.new($2, date)
      elsif current && (line.starts_with?("    ") || line.starts_with?("\t"))
        line = line.strip
        if line =~ /^(.+?)\s{2,}(.+)$/
          name = $1.strip
          value_str = $2.strip.gsub(',', '.').to_f
          current.accountChanges << AccountChange.new(name, SimpleRational.new(value_str))
        else
          current.accountChanges << AccountChange.new(line)
        end
      end
    end

    if current
      transactions << current
    end

    transactions.sort_by! { |t| t.date }
    transactions
  end
end

# Simple argument parser
file = nil
start_date = "1970/01/01"
end_date = nil
payee_filter = ""
columns = 79

ARGV.each_with_index do |arg, idx|
  if arg == "-f"
    file = ARGV[idx + 1] if idx + 1 < ARGV.size
  elsif arg == "-b"
    start_date = ARGV[idx + 1] if idx + 1 < ARGV.size
  elsif arg == "-e"
    end_date = ARGV[idx + 1] if idx + 1 < ARGV.size
  elsif arg == "--payee"
    payee_filter = ARGV[idx + 1] if idx + 1 < ARGV.size
  elsif arg == "--columns"
    columns = ARGV[idx + 1].to_i if idx + 1 < ARGV.size
  elsif arg == "--help" || arg == "-h"
    puts "Equity - Opening Balance Transaction Generator"
    puts "============================================="
    puts
    puts "Usage: crystal equity.cr [OPTIONS]"
    puts
    puts "Options:"
    puts "  -f FILE      Ledger file (*required) or '-' for stdin"
    puts "  -b DATE      Start date (default: 1970/01/01)"
    puts "  -e DATE      End date (default: today)"
    puts "  --payee=STR  Filter by payee"
    puts "  --columns=N  Column width (default: 79)"
    puts "  --help       Show this help"
    puts
    puts "Description:"
    puts "  Generates an 'Opening Balances' transaction with accumulated balances"
    puts "  from all transactions in the specified period."
    exit
  end
end

if file.nil?
  puts "Equity - Opening Balance Transaction Generator"
  puts "=============================================="
  puts
  puts "Usage: crystal equity.cr [OPTIONS]"
  puts
  puts "Options:"
  puts "  -f FILE      Ledger file (*required) or '-' for stdin"
  puts "  -b DATE      Start date (default: 1970/01/01)"
  puts "  -e DATE      End date (default: today)"
  puts "  --payee=STR  Filter by payee"
  puts "  --columns=N  Column width (default: 79)"
  puts "  --help       Show this help"
  puts
  puts "Description:"
  puts "  Generates an 'Opening Balances' transaction with accumulated balances"
  puts "  from all transactions in the specified period."
  exit
end

if end_date.nil?
  end_date = Time.utc.to_s(TRANSACTION_DATE_FORMAT)
end

begin
  content = File.read(file)
rescue e
  puts "Error: #{e.message}"
  exit
end

transactions = Parser.parse_ledger(content)

start = Time.parse_utc(start_date, "%Y/%m/%d")
fin = Time.parse_utc(end_date, "%Y/%m/%d")

filtered = transactions.select { |t| t.date >= start && t.date <= fin }

if payee_filter != ""
  filtered = filtered.select { |t| t.payee.downcase.includes?(payee_filter.downcase) }
end

if filtered.empty?
  puts "No transactions in specified period."
  exit
end

balances = {} of String => SimpleRational

filtered.each do |t|
  t.accountChanges.each do |ac|
    next if ac.balance.nil?
    name = ac.name
    balances[name] = SimpleRational.zero unless balances.has_key?(name)
    balances[name] = balances[name].plus(ac.balance.not_nil!)
  end
end

non_zero = balances.select { |_, v| !v.zero? }

if non_zero.empty?
  puts "All balances are zero in specified period."
  exit
end

last_date = filtered.last.date
puts "#{last_date.to_s(TRANSACTION_DATE_FORMAT)} Opening Balances"

non_zero.keys.sort.each do |name|
  balance = non_zero[name]
  balance_str = balance.to_f(DISPLAY_PRECISION)
  spaces = columns - 4 - name.size - balance_str.size
  spaces = 2 if spaces < 2
  puts "    #{name}#{" " * spaces}#{balance_str}"
end
puts
