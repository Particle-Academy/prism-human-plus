# Changelog

## Unreleased

- Preserve HTTP status-based failure categories across both relay transports.
- Complete and validate the native concurrent POST before returning a correlated reply, with POST failures taking precedence over GET status.
- Classify bracketed IPv6 literals without DNS and apply the shared explicit address range policy, including embedded IPv4 classification.
- Recheck the relay before each native HTTP leg. DNS preflight complements the required trusted egress proxy and does not pin a later hostname connection.
