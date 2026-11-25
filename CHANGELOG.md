# Changelog

## Unreleased

- Relay redirects stay same-origin and are read the way Guzzle reads `allow_redirects`: `['max' => 0]`, `['max' => null]`, any non-positive `max`, and values Guzzle would reject now turn redirects off on the call and on the injected client, and a smaller `max` is kept rather than raised to five.
- New `followRedirects: false` option on both transports (and `RelayEndpoint`) turns relay redirects off for any injected client, including decorators whose own setting cannot be read.

## 0.6.0

- Preserve HTTP status-based failure categories across both relay transports.
- Complete and validate the native concurrent POST before returning a correlated reply, with POST failures taking precedence over GET status.
- Classify bracketed IPv6 literals without DNS and apply the shared explicit address range policy, including embedded IPv4 classification.
- Recheck the relay before each native HTTP leg. DNS preflight complements the required trusted egress proxy and does not pin a later hostname connection.
