package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"time"

	uuid "github.com/pedroalbanese/uuid"
)

type Payload struct {
	ID            string `json:"id"`
	Amount        string `json:"amount"`
	Date          string `json:"date"`
	Note          string `json:"note"`
	Payee         string `json:"payee"`
	SourceAccount string `json:"sourceaccount"`
	TargetAccount string `json:"targetaccount"`
}

func main() {
	amount := flag.String("amount", "", "Amount")
	note := flag.String("note", "", "Note")
	payee := flag.String("payee", "", "Payee")
	sourceAccount := flag.String("sourceaccount", "", "Source account")
	targetAccount := flag.String("targetaccount", "", "Target account")
	prettify := flag.Bool("prettify", false, "Prettify output")

	flag.Parse()

	date := time.Now().Format("2006/01/02")
	u, _ := uuid.NewUUID()

	payload := Payload{
		ID:            u.String(),
		Amount:        *amount,
		Date:          date,
		Note:          *note,
		Payee:         *payee,
		SourceAccount: *sourceAccount,
		TargetAccount: *targetAccount,
	}

	var jsonPayload []byte
	var err error
	if *prettify {
		jsonPayload, err = json.Marshal(payload)
	} else {
		jsonPayload, err = json.MarshalIndent(payload, "", "  ")
	}

	if err != nil {
		fmt.Println("Error generating the payload:", err)
		return
	}

	fmt.Println(string(jsonPayload))
}
