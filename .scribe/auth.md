# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_SANCTUM_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Obtain a token via `POST /auth/otp/request` then `POST /auth/otp/verify` (Laravel Sanctum bearer token).
