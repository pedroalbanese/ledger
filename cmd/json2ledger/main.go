package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"strings"
)

func main() {
	// Flags
	note := flag.String("note", "", "Note for the transaction")
	sourceAccount := flag.String("sourceaccount", "", "Source account for the transaction")
	targetAccount := flag.String("targetaccount", "", "Target account for the transaction")
	payee := flag.String("payee", "", "Payee for the transaction")
	amount := flag.String("amount", "", "Amount for the transaction")
	date := flag.String("date", "", "Date for the transaction")

	flag.Parse()

	// Read JSON input from STDIN
	var transaction map[string]interface{}
	err := json.NewDecoder(os.Stdin).Decode(&transaction)
	if err != nil {
		fmt.Println("Error decoding JSON:", err)
		return
	}

	// Set transaction fields from JSON or flags
	if *note == "" {
		if val, ok := transaction["note"].(string); ok {
			*note = val
		}
	}
	if *sourceAccount == "" {
		if val, ok := transaction["sourceaccount"].(string); ok {
			*sourceAccount = val
		}
	}
	if *targetAccount == "" {
		if val, ok := transaction["targetaccount"].(string); ok {
			*targetAccount = val
		}
	}
	if *payee == "" {
		if val, ok := transaction["payee"].(string); ok {
			*payee = val
		}
	}
	if *amount == "" {
		if val, ok := transaction["amount"].(string); ok {
			*amount = val
		}
	}
	if *date == "" {
		if val, ok := transaction["date"].(string); ok {
			*date = val
		}
	}

	// Validate required fields
	if *amount == "" {
		fmt.Println("Error: amount is required")
		return
	}
	if *date == "" {
		fmt.Println("Error: date is required")
		return
	}

	// Convert amount string to float64
	amountValue := 0.0
	_, err = fmt.Sscanf(*amount, "%f", &amountValue)
	if err != nil {
		fmt.Println("Error: invalid amount format")
		return
	}

	// Build the ledger transaction string
	var sb strings.Builder
	if *note != "" {
		sb.WriteString("; ")
		sb.WriteString(*note)
		sb.WriteString("\n")
	}
	sb.WriteString(*date)
	sb.WriteString(" ")
	sb.WriteString(*payee)
	sb.WriteString("\n")
	if *sourceAccount != "" {
		sb.WriteString("    ")
		sb.WriteString(*sourceAccount)
		sb.WriteString(strings.Repeat(" ", 65-len(*sourceAccount)))
		sb.WriteString(fmt.Sprintf("%10.2f\n", -amountValue))
	}
	if *targetAccount != "" {
		sb.WriteString("    ")
		sb.WriteString(*targetAccount)
		sb.WriteString(strings.Repeat(" ", 65-len(*targetAccount)))
		sb.WriteString(fmt.Sprintf("%10.2f\n", amountValue))
	}

	fmt.Print(sb.String())
}
