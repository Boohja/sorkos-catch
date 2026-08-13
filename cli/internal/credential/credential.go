package credential

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"

	"github.com/zalando/go-keyring"
)

const service = "catch-cli"

var ErrNotFound = errors.New("Catch CLI credential not found")

func account(baseURL string) string {
	digest := sha256.Sum256([]byte(baseURL))
	return hex.EncodeToString(digest[:])
}

func Get(baseURL string) (string, error) {
	token, err := keyring.Get(service, account(baseURL))
	if errors.Is(err, keyring.ErrNotFound) {
		return "", ErrNotFound
	}
	return token, err
}

func Set(baseURL, token string) error {
	return keyring.Set(service, account(baseURL), token)
}

func Delete(baseURL string) error {
	err := keyring.Delete(service, account(baseURL))
	if errors.Is(err, keyring.ErrNotFound) {
		return nil
	}
	return err
}
