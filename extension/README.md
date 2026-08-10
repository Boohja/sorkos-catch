# Catch browser extension

One dependency-free Manifest V3 codebase builds the Chrome/Chromium and Firefox variants.

## Build

```text
cd extension
npm run build
```

The output folders are:

- `build/chrome`
- `build/firefox`

Load `build/chrome` with **Load unpacked** on `chrome://extensions`. Load `build/firefox/manifest.json` as a temporary add-on from `about:debugging`.

Run `npm run check` to rebuild, validate both manifests, and syntax-check every JavaScript file.

Firefox declares the data needed for the user-triggered capture flow through its built-in consent system. “Authentication information” refers only to the random Catch device token stored by the extension, never website passwords, cookies, or login fields. Website activity/content is transmitted only when the user explicitly catches a page, selection, link, image, or screenshot; the extension does not send browsing history or page content in the background.

The Firefox action defaults to the navigation toolbar. Zen also exposes it through its Settings Hub; right-click Catch there and choose **Pin to Toolbar** if an existing installation retained Firefox's previous placement.

## Pairing

The extension creates a short-lived pairing request and a local random verifier. Catch receives only the SHA-256 challenge until the verifier-protected exchange. The signed-in user confirms the browser on the normal Catch website. The long-lived `capture:write` device token is returned in the exchange response, never in a URL, and is stored with `storage.local`.

Captures attempted before pairing are queued locally and submitted after connection. Screenshot bytes are not captured before a valid token exists. If the original tab is no longer active when pairing finishes, the text and URL are preserved and the queued capture records why its requested screenshot was omitted.
