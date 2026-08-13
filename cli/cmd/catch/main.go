package main

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"

	"catch.local/cli/internal/api"
	"catch.local/cli/internal/browser"
	"catch.local/cli/internal/config"
	"catch.local/cli/internal/credential"
	"catch.local/cli/internal/output"
)

type options struct {
	JSON       bool
	Status     string
	Limit      int
	Server     string
	Positional []string
}

func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()
	if err := run(ctx, os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "catch:", err)
		os.Exit(1)
	}
}

func run(ctx context.Context, args []string) error {
	if len(args) == 0 || args[0] == "help" || args[0] == "--help" || args[0] == "-h" {
		usage()
		return nil
	}
	command := args[0]
	opts, err := parseOptions(args[1:])
	if err != nil {
		return err
	}
	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("read config: %w", err)
	}
	if opts.Server != "" {
		cfg.BaseURL, err = normalizeServer(opts.Server)
		if err != nil {
			return err
		}
	}

	switch command {
	case "login":
		return login(ctx, cfg, opts.JSON)
	case "logout":
		return logout(ctx, cfg, opts.JSON)
	case "whoami":
		return whoami(ctx, cfg, opts.JSON)
	case "get":
		if len(opts.Positional) != 1 {
			return errors.New("usage: catch get <id> [--json]")
		}
		return get(ctx, cfg, opts.Positional[0], opts.JSON)
	case "search":
		if len(opts.Positional) == 0 {
			return errors.New("usage: catch search <query> [--status <status>] [--limit <n>] [--json]")
		}
		return list(ctx, cfg, strings.Join(opts.Positional, " "), opts)
	case "list":
		if len(opts.Positional) != 0 {
			return errors.New("usage: catch list [--status <status>] [--limit <n>] [--json]")
		}
		return list(ctx, cfg, "", opts)
	default:
		return fmt.Errorf("unknown command %q; run catch help", command)
	}
}

func parseOptions(args []string) (options, error) {
	opts := options{Status: "inbox", Limit: 100}
	for index := 0; index < len(args); index++ {
		switch args[index] {
		case "--json":
			opts.JSON = true
		case "--status", "--limit", "--server":
			if index+1 >= len(args) {
				return opts, fmt.Errorf("%s requires a value", args[index])
			}
			value := args[index+1]
			index++
			switch args[index-1] {
			case "--status":
				opts.Status = value
			case "--server":
				opts.Server = value
			case "--limit":
				limit, err := strconv.Atoi(value)
				if err != nil || limit < 1 || limit > 200 {
					return opts, errors.New("--limit must be between 1 and 200")
				}
				opts.Limit = limit
			}
		default:
			if strings.HasPrefix(args[index], "--") {
				return opts, fmt.Errorf("unknown option %s", args[index])
			}
			opts.Positional = append(opts.Positional, args[index])
		}
	}
	if !contains([]string{"inbox", "archived", "trash"}, opts.Status) {
		return opts, errors.New("--status must be inbox, archived, or trash")
	}
	return opts, nil
}

func login(ctx context.Context, cfg config.Config, jsonOutput bool) error {
	if _, err := credential.Get(cfg.BaseURL); err == nil {
		return errors.New("already logged in; run catch logout first")
	} else if !errors.Is(err, credential.ErrNotFound) {
		return fmt.Errorf("access OS credential store: %w", err)
	}
	verifierBytes := make([]byte, 32)
	if _, err := rand.Read(verifierBytes); err != nil {
		return err
	}
	verifier := base64.RawURLEncoding.EncodeToString(verifierBytes)
	digest := sha256.Sum256([]byte(verifier))
	challenge := base64.RawURLEncoding.EncodeToString(digest[:])
	hostname, err := os.Hostname()
	if err != nil || strings.TrimSpace(hostname) == "" {
		hostname = "Catch CLI"
	}
	client := api.New(cfg.BaseURL)
	var started struct {
		LoginID          string `json:"login_id"`
		AuthorizationURL string `json:"authorization_url"`
		Interval         int    `json:"interval"`
	}
	_, err = client.Do(ctx, http.MethodPost, "/api/cli/auth/start", map[string]string{"code_challenge": challenge, "device_name": hostname, "platform": runtime.GOOS}, &started)
	if err != nil {
		return fmt.Errorf("start login: %w", err)
	}
	if err := browser.Open(started.AuthorizationURL); err != nil {
		fmt.Fprintln(os.Stderr, "Could not open a browser automatically.")
		fmt.Fprintln(os.Stderr, "Open this URL:", started.AuthorizationURL)
	} else {
		fmt.Fprintln(os.Stderr, "Approve Catch CLI in your browser. Waiting for authorization...")
	}
	interval := time.Duration(max(started.Interval, 2)) * time.Second
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		var status struct {
			Status string `json:"status"`
			Token  string `json:"token"`
			Scope  string `json:"scope"`
			Device struct {
				Name string `json:"name"`
			} `json:"device"`
		}
		code, pollErr := client.Do(ctx, http.MethodPost, "/api/cli/auth/status/"+started.LoginID, map[string]string{"code_verifier": verifier}, &status)
		if pollErr == nil && status.Status == "connected" {
			if status.Token == "" {
				return errors.New("server returned an empty credential")
			}
			if err := credential.Set(cfg.BaseURL, status.Token); err != nil {
				return fmt.Errorf("save credential in OS keyring: %w", err)
			}
			if err := config.Save(cfg); err != nil {
				_ = credential.Delete(cfg.BaseURL)
				return fmt.Errorf("save config: %w", err)
			}
			if jsonOutput {
				return output.JSON(os.Stdout, map[string]any{"status": "logged_in", "device_name": status.Device.Name, "scope": status.Scope})
			}
			fmt.Fprintf(os.Stdout, "Logged in as %s.\n", status.Device.Name)
			return nil
		}
		if pollErr != nil && code != http.StatusAccepted {
			return fmt.Errorf("complete login: %w", pollErr)
		}
		select {
		case <-ctx.Done():
			return errors.New("login canceled")
		case <-ticker.C:
		}
	}
}

func logout(ctx context.Context, cfg config.Config, jsonOutput bool) error {
	token, err := credential.Get(cfg.BaseURL)
	if errors.Is(err, credential.ErrNotFound) {
		return errors.New("not logged in")
	}
	if err != nil {
		return fmt.Errorf("access OS credential store: %w", err)
	}
	client := api.New(cfg.BaseURL)
	client.Token = token
	_, serverErr := client.Do(ctx, http.MethodPost, "/api/cli/logout", nil, nil)
	if err := credential.Delete(cfg.BaseURL); err != nil {
		return fmt.Errorf("delete credential: %w", err)
	}
	if serverErr != nil {
		fmt.Fprintln(os.Stderr, "Warning: local credential removed, but server revocation could not be confirmed:", serverErr)
	}
	if jsonOutput {
		return output.JSON(os.Stdout, map[string]string{"status": "logged_out"})
	}
	fmt.Fprintln(os.Stdout, "Logged out.")
	return nil
}

func whoami(ctx context.Context, cfg config.Config, jsonOutput bool) error {
	client, err := authenticated(cfg)
	if err != nil {
		return err
	}
	var envelope struct {
		Data map[string]any `json:"data"`
	}
	if _, err := client.Do(ctx, http.MethodGet, "/api/cli/whoami", nil, &envelope); err != nil {
		return err
	}
	if jsonOutput {
		return output.JSON(os.Stdout, envelope.Data)
	}
	fmt.Fprintf(os.Stdout, "%v <%v>\n", envelope.Data["display_name"], envelope.Data["email"])
	if device, ok := envelope.Data["device"].(map[string]any); ok {
		fmt.Fprintf(os.Stdout, "Device: %v (%v)\n", device["name"], device["platform"])
	}
	return nil
}

func get(ctx context.Context, cfg config.Config, id string, jsonOutput bool) error {
	client, err := authenticated(cfg)
	if err != nil {
		return err
	}
	var envelope struct {
		Data map[string]any `json:"data"`
	}
	if _, err := client.Do(ctx, http.MethodGet, "/api/v1/captures/"+url.PathEscape(id), nil, &envelope); err != nil {
		return err
	}
	if jsonOutput {
		return output.JSON(os.Stdout, envelope.Data)
	}
	output.Capture(os.Stdout, envelope.Data)
	return nil
}

func list(ctx context.Context, cfg config.Config, query string, opts options) error {
	client, err := authenticated(cfg)
	if err != nil {
		return err
	}
	var envelope struct {
		Data []map[string]any `json:"data"`
	}
	if _, err := client.Do(ctx, http.MethodGet, api.CapturesPath(opts.Status, opts.Limit, query), nil, &envelope); err != nil {
		return err
	}
	if opts.JSON {
		return output.JSON(os.Stdout, envelope.Data)
	}
	output.Captures(os.Stdout, envelope.Data)
	return nil
}

func authenticated(cfg config.Config) (*api.Client, error) {
	token, err := credential.Get(cfg.BaseURL)
	if errors.Is(err, credential.ErrNotFound) {
		return nil, errors.New("not logged in; run catch login")
	}
	if err != nil {
		return nil, fmt.Errorf("access OS credential store: %w", err)
	}
	client := api.New(cfg.BaseURL)
	client.Token = token
	return client, nil
}

func normalizeServer(value string) (string, error) {
	parsed, err := url.Parse(strings.TrimSpace(value))
	if err != nil || parsed.Scheme == "" || parsed.Host == "" || (parsed.Scheme != "https" && parsed.Scheme != "http") {
		return "", errors.New("--server must be an absolute http or https URL")
	}
	if parsed.Path != "" && parsed.Path != "/" || parsed.RawQuery != "" || parsed.Fragment != "" {
		return "", errors.New("--server must not contain a path, query, or fragment")
	}
	return strings.TrimRight(parsed.String(), "/"), nil
}

func contains(values []string, wanted string) bool {
	for _, value := range values {
		if value == wanted {
			return true
		}
	}
	return false
}

func usage() {
	fmt.Fprint(os.Stdout, `Catch CLI — read and search Catch from a terminal

Usage:
  catch login [--server <url>] [--json]
  catch logout [--json]
  catch whoami [--json]
  catch get <id> [--json]
  catch search <query> [--status <status>] [--limit <n>] [--json]
  catch list [--status <status>] [--limit <n>] [--json]

Status values: inbox, archived, trash
`)
}
