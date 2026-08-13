package main

import "testing"

func TestOptionsMayFollowPositionals(t *testing.T) {
	opts, err := parseOptions([]string{"23", "--json", "--limit", "10"})
	if err != nil {
		t.Fatal(err)
	}
	if !opts.JSON || opts.Limit != 10 || len(opts.Positional) != 1 || opts.Positional[0] != "23" {
		t.Fatalf("unexpected options: %#v", opts)
	}
}

func TestInvalidStatusIsRejected(t *testing.T) {
	if _, err := parseOptions([]string{"--status", "deleted"}); err == nil {
		t.Fatal("invalid status was accepted")
	}
}
