# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_AUTH_KEY}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Get a token from <code>POST /api/v1/auth/token</code> with your email, password and a device name. Access follows your account's permissions — see docs/roles-and-permissions.md.
