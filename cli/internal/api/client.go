package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type Client struct {
	BaseURL    string
	Token      string
	HTTPClient *http.Client
}

type Error struct {
	Status  int
	Code    string
	Message string
}

func (e *Error) Error() string {
	if e.Message != "" {
		return e.Message
	}
	return fmt.Sprintf("Catch API returned HTTP %d", e.Status)
}

func New(baseURL string) *Client {
	return &Client{BaseURL: strings.TrimRight(baseURL, "/"), HTTPClient: &http.Client{Timeout: 30 * time.Second}}
}

func (c *Client) Do(ctx context.Context, method, path string, input any, output any) (int, error) {
	var body io.Reader
	if input != nil {
		encoded, err := json.Marshal(input)
		if err != nil {
			return 0, err
		}
		body = bytes.NewReader(encoded)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.BaseURL+path, body)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "catch-cli/1")
	if input != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.Token != "" {
		req.Header.Set("Authorization", "Bearer "+c.Token)
	}
	response, err := c.HTTPClient.Do(req)
	if err != nil {
		return 0, err
	}
	defer response.Body.Close()
	data, err := io.ReadAll(io.LimitReader(response.Body, 16<<20))
	if err != nil {
		return response.StatusCode, err
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		var envelope struct {
			Error struct {
				Code    string `json:"code"`
				Message string `json:"message"`
			} `json:"error"`
		}
		_ = json.Unmarshal(data, &envelope)
		return response.StatusCode, &Error{Status: response.StatusCode, Code: envelope.Error.Code, Message: envelope.Error.Message}
	}
	if output != nil && len(data) > 0 {
		if err := json.Unmarshal(data, output); err != nil {
			return response.StatusCode, fmt.Errorf("decode Catch response: %w", err)
		}
	}
	return response.StatusCode, nil
}

func CapturesPath(status string, limit int, query string) string {
	values := url.Values{}
	values.Set("status", status)
	values.Set("limit", fmt.Sprintf("%d", limit))
	if query != "" {
		values.Set("query", query)
	}
	return "/api/v1/captures?" + values.Encode()
}
