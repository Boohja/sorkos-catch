package api

import (
	"net/url"
	"testing"
)

func TestCapturesPath(t *testing.T) {
	path := CapturesPath("archived", 25, "meeting notes")
	parsed, err := url.Parse(path)
	if err != nil {
		t.Fatal(err)
	}
	if parsed.Path != "/api/v1/captures" || parsed.Query().Get("status") != "archived" || parsed.Query().Get("limit") != "25" || parsed.Query().Get("query") != "meeting notes" {
		t.Fatalf("unexpected captures path: %s", path)
	}
}
