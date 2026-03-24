#!/usr/bin/env crystal

require "csv"
require "time"

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

# Parse arguments
file = nil
account = nil
csv_file = nil
set_search = "Expenses"
neg = false
allow_matching = false
scale = 1.0
date_format = "m/d/Y"
delimiter = ","
columns = 79
wide = false

args = ARGV.dup
i = 0
while i < args.size
  arg = args[i]
  case arg
  when "-f"
    file = args[i + 1]
    i += 1
  when "--set-search"
    set_search = args[i + 1]
    i += 1
  when "--neg"
    neg = true
  when "--allow-matching"
    allow_matching = true
  when "--scale"
    scale = args[i + 1].to_f
    i += 1
  when "--date-format"
    date_format = args[i + 1]
    i += 1
  when "--delimiter"
    delimiter = args[i + 1]
    i += 1
  when "--columns"
    columns = args[i + 1].to_i
    i += 1
  when "--wide"
    wide = true
  when "--help"
    puts "Limport - CSV Importer for Ledger"
    puts "================================="
    puts ""
    puts "Usage: ./limport -f <ledger-file> [options] <account> <csv-file>"
    puts "Options:"
    puts "  --set-search=STR   Search string for classification (default: Expenses)"
    puts "  --neg              Negate amount column value"
    puts "  --allow-matching   Include transactions that match existing ones"
    puts "  --scale=FACTOR     Scaling factor (default: 1.0)"
    puts "  --date-format=STR  Date format (default: m/d/Y)"
    puts "  --delimiter=STR    Field delimiter (default: ,)"
    puts "  --columns=N        Column width (default: 79)"
    puts "  --wide             Wide mode (132 columns)"
    exit
  else
    if account.nil?
      account = arg
    elsif csv_file.nil?
      csv_file = arg
    end
  end
  i += 1
end

# Check required arguments
if file.nil? || account.nil? || csv_file.nil?
  puts "Error: Required arguments missing"
  puts "Usage: crystal limport.cr -f <ledger-file> <account> <csv-file>"
  exit 1
end

if wide
  columns = 132
end

# Read ledger
content = File.read(file)
transactions = Parser.parse_ledger(content)

# Find destination account
all_accounts = {} of String => Bool
transactions.each do |t|
  t.accountChanges.each do |ac|
    all_accounts[ac.name] = true
  end
end

matching = all_accounts.keys.select { |name| name.downcase.includes?(account.downcase) }

if matching.empty?
  puts "Error: Account '#{account}' not found"
  exit 1
end

dest_account = matching.last

# Build Bayesian classifier
classes = [] of String
transactions.each do |t|
  t.accountChanges.each do |ac|
    if ac.name.downcase.includes?(set_search.downcase)
      classes << ac.name unless classes.includes?(ac.name)
    end
  end
end

# Initialize classifier data
datas = {} of String => {freqs: Hash(String, Int32), total: Int32}
classes.each do |klass|
  datas[klass] = {freqs: {} of String => Int32, total: 0}
end

# Train the classifier
transactions.each do |t|
  payee_words = t.payee.downcase.strip.split(/\s+/)
  t.accountChanges.each do |ac|
    if classes.includes?(ac.name)
      data = datas[ac.name]
      freqs = data[:freqs]
      payee_words.each do |word|
        freqs[word] = (freqs[word]? || 0) + 1
      end
      datas[ac.name] = {freqs: freqs, total: data[:total] + payee_words.size}
    end
  end
end

# Function to classify payee
def classify_payee(payee : String, classes : Array(String), datas : Hash(String, {freqs: Hash(String, Int32), total: Int32})) : String
  words = payee.downcase.strip.split(/\s+/)
  return "unknown:unknown" if words.empty? || classes.empty?

  # Calculate priors
  n = classes.size
  priors = [] of Float64
  sum = 0
  classes.each do |klass|
    total = datas[klass][:total]
    priors << total.to_f
    sum += total
  end
  float_sum = sum.to_f
  float_n = n.to_f
  priors.map! { |p| (p + 1) / (float_sum + float_n) }

  # Calculate log scores
  scores = [] of Float64
  classes.each_with_index do |klass, idx|
    data = datas[klass]
    freqs = data[:freqs]
    total = data[:total]
    vocab_size = freqs.size
    score = Math.log(priors[idx])
    words.each do |word|
      value = freqs[word]? || 0
      word_prob = (value + 1).to_f / (total + vocab_size).to_f
      score += Math.log(word_prob)
    end
    scores << score
  end

  # Find max score
  max_idx = 0
  (1...scores.size).each do |i|
    max_idx = i if scores[max_idx] < scores[i]
  end
  classes[max_idx]
end

# Read CSV
csv_content = File.read(csv_file)
lines = csv_content.lines.map(&.chomp).reject(&.empty?)
header = lines.shift.split(delimiter)

date_col = header.index { |h| h.downcase.includes?("date") } || -1
payee_col = header.index { |h| h.downcase.includes?("description") || h.downcase.includes?("payee") } || -1
amount_col = header.index { |h| h.downcase.includes?("amount") || h.downcase.includes?("expense") } || -1
note_col = header.index { |h| h.downcase.includes?("note") || h.downcase.includes?("comment") } || -1

if date_col < 0 || payee_col < 0 || amount_col < 0
  puts "Error: Required columns not found"
  exit 1
end

# Process CSV
lines.each do |line|
  record = line.split(delimiter)
  next if record.size <= [date_col, payee_col, amount_col].max

  date_str = record[date_col].to_s.strip
  payee = record[payee_col].to_s.strip
  amount_str = record[amount_col].to_s.strip
  note = note_col >= 0 ? record[note_col].to_s.strip : ""

  next if date_str.empty? || payee.empty? || amount_str.empty?

  # Parse date
  date = nil
  [date_format, "%d/%m/%Y", "%Y/%m/%d", "%m/%d/%Y", "%d-%m-%Y", "%Y-%m-%d", "%m-%d-%Y"].each do |fmt|
    begin
      date = Time.parse_utc(date_str, fmt)
      break
    rescue
    end
  end
  next if date.nil?

  # Parse amount
  amount_str_clean = amount_str.gsub(/[^\d\.,\-]/, "").gsub(',', '.')
  amount = amount_str_clean.to_f?
  next if amount.nil?
  amount *= scale
  amount = -amount if neg

  csv_amount = -amount
  expense_amount = -csv_amount

  # Classify payee
  classified_account = classify_payee(payee, classes, datas)

  # Print transaction
  puts "; #{note}" if !note.empty?
  puts "#{date.to_s(TRANSACTION_DATE_FORMAT)} #{payee}"

  balance_str = sprintf("%.#{DISPLAY_PRECISION}f", csv_amount)
  spaces = columns - 4 - dest_account.size - balance_str.size
  spaces = 2 if spaces < 2
  puts "    #{dest_account}#{" " * spaces}#{balance_str}"

  balance_str = sprintf("%.#{DISPLAY_PRECISION}f", expense_amount)
  spaces = columns - 4 - classified_account.size - balance_str.size
  spaces = 2 if spaces < 2
  puts "    #{classified_account}#{" " * spaces}#{balance_str}"

  puts
end
