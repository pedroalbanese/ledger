package main

import (
	"bufio"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"
)

func main() {
	monthlyTotal := make(map[string]float64)

	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		line := scanner.Text()
		fields := strings.Fields(line)
		if len(fields) != 2 {
			continue
		}
		amount, err := strconv.ParseFloat(fields[1], 64)
		if err != nil {
			continue
		}
		dateFields := strings.Split(fields[0], "/")
		if len(dateFields) != 3 {
			continue
		}
		year := dateFields[0]
		month := dateFields[1]
		monthlyTotal[year+"/"+month] += amount
	}

	keys := make([]string, 0, len(monthlyTotal))
	for k := range monthlyTotal {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	for _, k := range keys {
		fmt.Printf("%s %.2f\n", k, monthlyTotal[k])
	}
}