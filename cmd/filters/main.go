package main

import (
	"bufio"
	"flag"
	"fmt"
	"os"
	"strings"
	"time"
)

var (
	today = time.Now()
	start = flag.String("start", "", "Start date")
	end   = flag.String("end", today.Format("2006/01/02"), "End date")
)

func inTimeSpan(start, end, check time.Time) bool {
	return check.After(start) && check.Before(end)
}

func main() {
	flag.Parse()
	start, _ := time.Parse("2006/01/02", *start)
	end, _ := time.Parse("2006/01/02", *end)

	data := os.Stdin

	scanner := bufio.NewScanner(data)
	scanner.Split(bufio.ScanLines)
	var txtlines []string

	for scanner.Scan() {
		txtlines = append(txtlines, scanner.Text())
	}

	for _, eachline := range txtlines {
		lines := strings.Split(string(eachline), " ")
		line, _ := time.Parse("2006/01/02", lines[0])
		if inTimeSpan(start, end, line) {
			fmt.Println(eachline)

		}
	}
}
