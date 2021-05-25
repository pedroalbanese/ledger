package main

import (
	"math/big"
	"sort"
	"time"
	"flag"
	"fmt"
	"io"
	"os"
	"github.com/howeyc/ledger"
)

const (
	transactionDateFormat = "2006/01/02"
	displayPrecision      = 2
)

func main() {

	var columnWidth int

	var ledgerFileName string

	flag.StringVar(&ledgerFileName, "f", "", "Ledger file name (*Required).")
	flag.IntVar(&columnWidth, "columns", 79, "Set a column width for output.")
	flag.Parse()

var lreader io.Reader

	if ledgerFileName == "-" {
		lreader = os.Stdin
	} else {
		ledgerFileReader, err := ledger.NewLedgerReader(ledgerFileName)
		if err != nil {
			fmt.Println(err)
			return
		}
		lreader = ledgerFileReader
	}

	generalLedger, parseError := ledger.ParseLedger(lreader)
	if parseError != nil {
		fmt.Printf("%s\n", parseError.Error())
		return
	}

	var trans ledger.Transaction
	trans.Payee = "Opening Balances"
	trans.Date = time.Now()
	if len(generalLedger) > 0 {
		trans.Date = generalLedger[len(generalLedger)-1].Date
	}

	balances := make(map[string]*big.Rat)
	for _, trans := range generalLedger {
		for _, accChange := range trans.AccountChanges {
			if ratNum, ok := balances[accChange.Name]; !ok {
				balances[accChange.Name] = new(big.Rat).Set(accChange.Balance)
			} else {
				ratNum.Add(ratNum, accChange.Balance)
			}
		}
	}

	ratZero := big.NewRat(0, 1)
	for name, bal := range balances {
		if bal.Cmp(ratZero) != 0 {
			trans.AccountChanges = append(trans.AccountChanges, ledger.Account{
				Name:    name,
				Balance: bal,
			})
		}
	}

	sort.Slice(trans.AccountChanges, func(i, j int) bool {
		return trans.AccountChanges[i].Name < trans.AccountChanges[j].Name
	})

	PrintTransaction(&trans, columnWidth)
}