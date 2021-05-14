package ledger

import (
	"bufio"
	"fmt"
	"io"
	"math/big"
	"regexp"
	"sort"
	"strings"

	date "github.com/joyt/godate"
	"github.com/marcmak/calc/calc"
)

const (
	whitespace = " \t"
)

// ParseLedger parses a ledger file and returns a list of Transactions.
//
// Transactions are sorted by date.
func ParseLedger(ledgerReader io.Reader) (generalLedger []*Transaction, err error) {
	parseLedger(ledgerReader, func(t *Transaction, e error) (stop bool) {
		if e != nil {
			err = e
			stop = true
			return
		}

		generalLedger = append(generalLedger, t)
		return
	})

	if len(generalLedger) > 1 {
		sort.Slice(generalLedger, func(i, j int) bool {
			return generalLedger[i].Date.Before(generalLedger[j].Date)
		})
	}

	return
}

// ParseLedgerAsync parses a ledger file and returns a Transaction and error channels .
//
func ParseLedgerAsync(ledgerReader io.Reader) (c chan *Transaction, e chan error) {
	c = make(chan *Transaction)
	e = make(chan error)

	go func() {
		parseLedger(ledgerReader, func(t *Transaction, err error) (stop bool) {
			if err != nil {
				e <- err
			} else {
				c <- t
			}
			return
		})

		e <- nil
	}()
	return c, e
}

var accountToAmountSpace = regexp.MustCompile(" {2,}|\t+")

func parseLedger(ledgerReader io.Reader, callback func(t *Transaction, err error) (stop bool)) {
	var trans *Transaction
	scanner := bufio.NewScanner(ledgerReader)
	var line string
	var filename string
	var lineCount int
	var comments []string

	errorMsg := func(msg string) (stop bool) {
		return callback(nil, fmt.Errorf("%s:%d: %s", filename, lineCount, msg))
	}

	for scanner.Scan() {
		line = scanner.Text()

		// update filename/line if sentinel comment is found
		if strings.HasPrefix(line, markerPrefix) {
			filename, lineCount = parseMarker(line)
			continue
		}

		// remove heading and tailing space from the line
		trimmedLine := strings.Trim(line, whitespace)
		lineCount++

		// handle comments
		if commentIdx := strings.Index(trimmedLine, ";"); commentIdx >= 0 {
			comments = append(comments, trimmedLine[commentIdx:])
			trimmedLine = trimmedLine[:commentIdx]
			if len(trimmedLine) == 0 {
				continue
			}
		}

		if len(trimmedLine) == 0 {
			if trans != nil {
				transErr := balanceTransaction(trans)
				if transErr != nil {
					errorMsg("Unable to balance transaction, " + transErr.Error())
				}
				trans.Comments = comments
				callback(trans, nil)
				comments = nil
				trans = nil
			}
		} else if trans == nil {
			lineSplit := strings.SplitN(trimmedLine, " ", 2)
			if len(lineSplit) != 2 {
				if errorMsg("Unable to parse payee line: " + line) {
					return
				}
				continue
			}
			dateString := lineSplit[0]
			transDate, dateErr := date.Parse(dateString)
			if dateErr != nil {
				errorMsg("Unable to parse date: " + dateString)
			}
			payeeString := lineSplit[1]
			trans = &Transaction{Payee: payeeString, Date: transDate}
		} else {
			var accChange Account
			lineSplit := accountToAmountSpace.Split(trimmedLine, -1)
			var nonEmptyWords []string
			for _, word := range lineSplit {
				if len(word) > 0 {
					nonEmptyWords = append(nonEmptyWords, word)
				}
			}
			lastIndex := len(nonEmptyWords) - 1
			balErr, rationalNum := getBalance(strings.Trim(nonEmptyWords[lastIndex], whitespace))
			if !balErr {
				// Assuming no balance and whole line is account name
				accChange.Name = strings.Join(nonEmptyWords, " ")
			} else {
				accChange.Name = strings.Join(nonEmptyWords[:lastIndex], " ")
				accChange.Balance = rationalNum
			}
			trans.AccountChanges = append(trans.AccountChanges, accChange)
		}
	}
	// If the file does not end on empty line, we must attempt to balance last
	// transaction of the file.
	if trans != nil {
		transErr := balanceTransaction(trans)
		if transErr != nil {
			errorMsg("Unable to balance transaction, " + transErr.Error())
		}
		trans.Comments = comments
		callback(trans, nil)
	}
}

func getBalance(balance string) (bool, *big.Rat) {
	rationalNum := new(big.Rat)
	if strings.Contains(balance, "(") {
		rationalNum.SetFloat64(calc.Solve(balance))
		return true, rationalNum
	}
	_, isValid := rationalNum.SetString(balance)
	return isValid, rationalNum
}

// Takes a transaction and balances it. This is mainly to fill in the empty part
// with the remaining balance.
func balanceTransaction(input *Transaction) error {
	balance := new(big.Rat)
	var emptyFound bool
	var emptyAccIndex int
	for accIndex, accChange := range input.AccountChanges {
		if accChange.Balance == nil {
			if emptyFound {
				return fmt.Errorf("more than one account change empty")
			}
			emptyAccIndex = accIndex
			emptyFound = true
		} else {
			balance = balance.Add(balance, accChange.Balance)
		}
	}
	if balance.Sign() != 0 {
		if !emptyFound {
			return fmt.Errorf("no empty account change to place extra balance")
		}
	}
	if emptyFound {
		input.AccountChanges[emptyAccIndex].Balance = balance.Neg(balance)
	}
	return nil
}
