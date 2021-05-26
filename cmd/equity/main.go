//go:generate goversioninfo -manifest=testdata/resource/goversioninfo.exe.manifest
package main

import (
	"math/big"
	"sort"
	"time"
	"flag"
	"fmt"
	"io"
	"os"
	"strings"
	"github.com/howeyc/ledger"
)

const (
	transactionDateFormat = "2006/01/02"
	displayPrecision      = 2
)

func main() {
	var startDate, endDate time.Time
	startDate = time.Date(1970, 1, 1, 0, 0, 0, 0, time.Local)
	endDate = time.Now().Add(time.Hour * 24)
	var startString, endString string
	var columnWidth int
	var ledgerFileName string
	var payeeFilter string

	flag.StringVar(&ledgerFileName, "f", "", "Ledger file name (*Required).")
	flag.IntVar(&columnWidth, "columns", 79, "Set a column width for output.")
	flag.StringVar(&payeeFilter, "payee", "", "Filter output to payees that contain this string.")
	flag.StringVar(&startString, "b", startDate.Format(transactionDateFormat), "Begin date of transaction processing.")
	flag.StringVar(&endString, "e", endDate.Format(transactionDateFormat), "End date of transaction processing.")
	flag.Parse()

	parsedStartDate, tstartErr := time.Parse(transactionDateFormat, startString)
	parsedEndDate, tendErr := time.Parse(transactionDateFormat, endString)

	if tstartErr != nil || tendErr != nil {
		fmt.Println("Unable to parse start or end date string argument.")
		fmt.Println("Expected format: YYYY/MM/dd")
		return
	}

	if len(ledgerFileName) == 0 {
		flag.Usage()
		return
	}

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

	timeStartIndex, timeEndIndex := 0, 0
	for idx := 0; idx < len(generalLedger); idx++ {
		if generalLedger[idx].Date.After(parsedStartDate) {
			timeStartIndex = idx
			break
		}
	}
	for idx := len(generalLedger) - 1; idx >= 0; idx-- {
		if generalLedger[idx].Date.Before(parsedEndDate) {
			timeEndIndex = idx
			break
		}
	}
	generalLedger = generalLedger[timeStartIndex : timeEndIndex+1]

	origLedger := generalLedger
	generalLedger = make([]*ledger.Transaction, 0)
	for _, trans := range origLedger {
		if strings.Contains(trans.Payee, payeeFilter) {
			generalLedger = append(generalLedger, trans)
		}
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