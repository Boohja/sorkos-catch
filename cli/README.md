# Catch CLI

The Catch CLI is a small, read-only client for people and local agents. It supports Windows amd64 and Linux amd64 and stores its bearer token in Windows Credential Manager or a Secret Service-compatible Linux keyring. The token is never written to the config file.

## Build

Go 1.22 or newer is required.

```text
cd cli
go build -trimpath -ldflags="-s -w" -o catch ./cmd/catch
```

Cross-build release binaries from any Go host:

```text
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o dist/catch-linux-amd64 ./cmd/catch
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o dist/catch-windows-amd64.exe ./cmd/catch
```

In PowerShell, set `$env:GOOS`, `$env:GOARCH`, and `$env:CGO_ENABLED` before each build.

## Use

```text
catch login
catch whoami
catch get 23 --json
catch search "meeting notes" --status archived --limit 20 --json
catch list --limit 50
catch logout
```

The default server is `https://catch.sorkos.net`. For a development installation, use `catch login --server https://catch.test`; this non-secret server choice is saved under the platform config directory.

`--json` writes only the requested JSON value to stdout. Progress and errors go to stderr. There is deliberately no token flag, token-printing command, environment-variable credential input, or plaintext credential fallback.
