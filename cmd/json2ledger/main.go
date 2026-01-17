package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"strings"
)

type Transaction struct {
	ID            string `json:"id"`
	Amount        string `json:"amount"`
	Date          string `json:"date"`
	Note          string `json:"note"`
	Payee         string `json:"payee"`
	SourceAccount string `json:"sourceaccount"`
	TargetAccount string `json:"targetaccount"`
}

func main() {
	note := flag.String("note", "", "Note for the transaction")
	sourceAccount := flag.String("sourceaccount", "", "Source account")
	targetAccount := flag.String("targetaccount", "", "Target account")
	payee := flag.String("payee", "", "Payee")
	amount := flag.String("amount", "", "Amount")
	date := flag.String("date", "", "Date")
	showUUID := flag.Bool("uuid", false, "Include UUID in ledger output")

	flag.Parse()

	var tx Transaction
	if err := json.NewDecoder(os.Stdin).Decode(&tx); err != nil {
		fmt.Fprintln(os.Stderr, "Error decoding JSON:", err)
		os.Exit(1)
	}

	// Flags sobrescrevem JSON
	if *note != "" {
		tx.Note = *note
	}
	if *sourceAccount != "" {
		tx.SourceAccount = *sourceAccount
	}
	if *targetAccount != "" {
		tx.TargetAccount = *targetAccount
	}
	if *payee != "" {
		tx.Payee = *payee
	}
	if *amount != "" {
		tx.Amount = *amount
	}
	if *date != "" {
		tx.Date = *date
	}

	// Validações
	if tx.Amount == "" {
		fmt.Fprintln(os.Stderr, "Error: amount is required")
		os.Exit(1)
	}
	if tx.Date == "" {
		fmt.Fprintln(os.Stderr, "Error: date is required")
		os.Exit(1)
	}

	amountValue := 0.0
	if _, err := fmt.Sscanf(tx.Amount, "%f", &amountValue); err != nil {
		fmt.Fprintln(os.Stderr, "Error: invalid amount format")
		os.Exit(1)
	}

	var sb strings.Builder

	// Comentários
	if tx.Note != "" {
		sb.WriteString("; ")
		sb.WriteString(tx.Note)
		sb.WriteString("\n")
	}

	if *showUUID && tx.ID != "" {
		sb.WriteString("; UUID: ")
		sb.WriteString(tx.ID)
		sb.WriteString("\n")
	}

	// Cabeçalho
	sb.WriteString(tx.Date)
	sb.WriteString(" ")
	sb.WriteString(tx.Payee)
	sb.WriteString("\n")

	// Lançamentos
	if tx.SourceAccount != "" {
		sb.WriteString("    ")
		sb.WriteString(tx.SourceAccount)
		sb.WriteString(strings.Repeat(" ", 65-len(tx.SourceAccount)))
		sb.WriteString(fmt.Sprintf("%10.2f\n", -amountValue))
	}

	if tx.TargetAccount != "" {
		sb.WriteString("    ")
		sb.WriteString(tx.TargetAccount)
		sb.WriteString(strings.Repeat(" ", 65-len(tx.TargetAccount)))
		sb.WriteString(fmt.Sprintf("%10.2f\n", amountValue))
	}

	fmt.Print(sb.String())
}
