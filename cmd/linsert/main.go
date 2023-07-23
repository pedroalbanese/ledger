package main

import (
	"flag"
	"fmt"
	"time"
	"os"
)

func main() {
	// Parse command line arguments
	var sourceAccount, targetAccount, amount, payee, note string
	flag.StringVar(&sourceAccount, "sourceaccount", "", "Source account name")
	flag.StringVar(&targetAccount, "targetaccount", "", "Target account name")
	flag.StringVar(&amount, "amount", "", "Transaction amount")
	flag.StringVar(&payee, "payee", "", "Payee name")
	flag.StringVar(&note, "note", "", "Transaction note")
	flag.Parse()
	if len(os.Args) < 2 {
		flag.PrintDefaults()
		os.Exit(2)
	}

	// Format date
	date := time.Now().Format("2006/01/02")

	// Print transaction
	fmt.Printf("; %s\n", note)
	fmt.Printf("%s %s\n", date, payee)
	fmt.Printf("    %-65s%10s\n", sourceAccount, fmt.Sprintf("%10s", amount))
	fmt.Printf("    %-65s%10s\n", targetAccount, fmt.Sprintf("%10s", "-"+amount))
}
