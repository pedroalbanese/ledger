# Ledger in Go
[![ISC License](http://img.shields.io/badge/license-ISC-blue.svg)](https://github.com/pedroalbanese/ledger/blob/master/LICENSE.md) 
[![GoDoc](https://godoc.org/github.com/pedroalbanese/ledger?status.png)](http://godoc.org/github.com/pedroalbanese/ledger)
[![Go Report Card](https://goreportcard.com/badge/github.com/pedroalbanese/ledger)](https://goreportcard.com/report/github.com/pedroalbanese/ledger)
[![GitHub go.mod Go version](https://img.shields.io/github/go-mod/go-version/pedroalbanese/ledger)](https://golang.org)
[![GitHub release (latest by date)](https://img.shields.io/github/v/release/pedroalbanese/ledger)](https://github.com/pedroalbanese/ledger/releases)

This is a project to parse and import transactions in a ledger file similar
to the [Ledger](http://ledger-cli.org) command line tool written in C++.

## Simple Ledger file support

The ledger file this will parse is much simpler than the C++ tool.

Transaction Format:

    <YYYY/MM/dd> <Payee description>
        <Account Name 1>    <Amount 1>
        .
        .
        .
        <Account Name N>    <Amount N>
 
The transaction must balance (the positive amounts must equal negative amounts).
One of the account lines is allowed to have no amount. The amount necessary
to balance the transaction will be added to that account for the transaction.
Amounts must be decimal numbers with a negative(-) sign in front if necessary.

Example transaction:

    2013/01/02 McDonald's #24233 HOUSTON TX
        Expenses:Dining Out:Fast Food        5.60
        Wallet:Cash

A ledger file is a list of transactions separated by a blank line.

A ledger file may include other ledger files using `include <filepath>`. The
`filepath` is relative to the including file.


## ledger

This will parse a ledger file into an array of Transaction structs.
There is also a function get balances for all accounts in the ledger file.

[GoDoc](https://pkg.go.dev/github.com/pedroalbanese/ledger)

## cmd/ledger

A very simplistic version of Ledger.
Supports "balance", "register", "print" and "stats" commands.

Example usage:
```sh
    ledger -f ledger.dat bal
    ledger -f ledger.dat bal Cash
    ledger -f ledger.dat reg
    ledger -f ledger.dat reg Food
    ledger -f ledger.dat print
    ledger -f ledger.dat stats
```

## cmd/limport

Using an existing ledger as input to a bayesian classifier, it will attempt to
classify an imported csv of transactions based on payee names and print them in
a ledger file format. 

Attempts to get payee, date, and amount based on headers in the csv file.

Example usage:
```sh
    limport -f ledger.dat discover discover-recent-transactions.csv
```

In the above example "discover" is the account search string to use to find
the account that all transactions in the csv file should be applied too. The
second account to use for each transaction will be picked based on the
bayesian classification of the payee.

## Incompatibilities

- C++ Ledger permits having amounts prefixed with $; Ledger in Go does not

- C++ Ledger permits an empty *Payee Description*; Ledger in Go does not
