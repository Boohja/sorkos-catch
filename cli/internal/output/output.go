package output

import (
	"encoding/json"
	"fmt"
	"io"
	"strings"
)

func JSON(writer io.Writer, value any) error {
	encoder := json.NewEncoder(writer)
	encoder.SetEscapeHTML(false)
	return encoder.Encode(value)
}

func Capture(writer io.Writer, item map[string]any) {
	number := integer(item["catch_number"])
	title := first(item["title"], item["text"], item["url"], "(untitled)")
	fmt.Fprintf(writer, "Catch #%d  %s\n", number, oneLine(title))
	fmt.Fprintf(writer, "Status: %v  Type: %v  Created: %v\n", item["status"], item["type"], item["created_at"])
	if text, ok := item["text"].(string); ok && strings.TrimSpace(text) != "" && text != title {
		fmt.Fprintln(writer)
		fmt.Fprintln(writer, text)
	}
	if value, ok := item["url"].(string); ok && value != "" {
		fmt.Fprintln(writer, value)
	}
}

func Captures(writer io.Writer, items []map[string]any) {
	if len(items) == 0 {
		fmt.Fprintln(writer, "No captures found.")
		return
	}
	for _, item := range items {
		fmt.Fprintf(writer, "#%-6d %-10v %s\n", integer(item["catch_number"]), item["status"], oneLine(first(item["title"], item["text"], item["url"], "(untitled)")))
	}
}

func first(values ...any) string {
	for _, value := range values {
		if text, ok := value.(string); ok && strings.TrimSpace(text) != "" {
			return text
		}
	}
	return ""
}

func oneLine(value string) string {
	value = strings.Join(strings.Fields(value), " ")
	if len([]rune(value)) > 100 {
		return string([]rune(value)[:97]) + "..."
	}
	return value
}

func integer(value any) int {
	switch number := value.(type) {
	case float64:
		return int(number)
	case int:
		return number
	default:
		return 0
	}
}
