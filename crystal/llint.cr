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

  def negated
    SimpleRational.new(-@value)
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
          finalize_transaction(current)
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
        end
      end
    end

    if current
      finalize_transaction(current)
      transactions << current
    end

    transactions.sort_by! { |t| t.date }
    transactions
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

def usage(name : String)
  puts "Usage: #{name} <ledger-file>"
  exit(1)
end

def main
  if ARGV.size != 1
    usage(ARGV[0]? || "ledger-validate")
  end

  ledger_file_name = ARGV[0]

  begin
    content = File.read(ledger_file_name)
    transactions = Parser.parse_ledger(content)
    
    error_count = 0
    
    transactions.each do |transaction|
      total = SimpleRational.zero
      transaction.accountChanges.each do |change|
        if balance = change.balance
          total = total.plus(balance)
        end
      end
      
      unless total.zero?
        puts "Ledger: Transaction not balanced: #{transaction.payee} (diff: #{total.to_f})"
        error_count += 1
      end
    end
    
    if error_count > 0
      puts "Found #{error_count} error(s) in ledger file."
      exit(error_count)
    else
      puts "Ledger file is valid."
      exit(0)
    end
    
  rescue e
    puts "Ledger: #{e.message}"
    exit(1)
  end
end

main
