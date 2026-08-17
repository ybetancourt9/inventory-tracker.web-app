# API reference

JSON REST API for the Inventory Tracker.

Base URL in production is `https://inventtracker.com/api`. Locally the API is served directly on `http://localhost:8080` with no prefix.

## Contents

- [Conventions](#conventions)
- [Authentication](#authentication)
- [Errors](#errors)
- [Rate limits](#rate-limits)
- [Health](#health)
- [Auth endpoints](#auth-endpoints)
- [Product endpoints](#product-endpoints)
- [Objects](#objects)

## Conventions

Requests and responses are JSON. Credentials and payloads travel in the request body rather than the query string, so they stay out of access logs and browser history.

Timestamps are ISO 8601 with an offset, for example `2026-08-16T14:22:05+00:00`.

Endpoints are either public or protected. Protected endpoints require a bearer token and answer `401` without one.

| Method | Path | Access |
| --- | --- | --- |
| GET | `/health` | Public |
| POST | `/auth/register` | Public |
| POST | `/auth/login` | Public |
| GET | `/auth/me` | Protected |
| GET | `/products` | Protected |
| POST | `/products` | Protected |
| GET | `/products/{id}` | Protected |
| PATCH | `/products/{id}` | Protected |
| PATCH | `/products/{id}/quantity` | Protected |
| DELETE | `/products/{id}` | Protected |
| POST | `/products/{id}/restore` | Protected |

## Authentication

`POST /auth/login` returns a signed JSON Web Token. Send it on every protected request.

```http
Authorization: Bearer <token>
```

Tokens are signed with HS256 and expire after one hour by default. The signature algorithm is pinned at verification, so a token declaring a different algorithm is rejected rather than verified on its own terms.

There is no refresh endpoint. When a token expires the client authenticates again.

## Errors

Every error uses the same envelope.

```json
{
  "error": {
    "code": 401,
    "message": "Invalid username or password."
  }
}
```

| Code | Meaning |
| --- | --- |
| 400 | The request was malformed, or a value failed validation |
| 401 | Missing, malformed, expired, or invalid token, or wrong credentials |
| 404 | No such route, or no such product |
| 409 | The request conflicts with current state, such as a duplicate SKU or insufficient stock |
| 429 | Too many attempts, see `Retry-After` |
| 500 | Unexpected server error |

Error messages are deliberately unspecific where being specific would help an attacker. A failed login returns one shared message whether the account exists or not, and the failure paths are timed to match so a missing account cannot be distinguished from a wrong password by how long the response takes.

## Rate limits

Applied to the authentication endpoints only. Exceeding a limit returns `429` with a `Retry-After` header giving the seconds until the window resets.

| Endpoint | Limit | Window |
| --- | --- | --- |
| `POST /auth/login` | 20 per client address | 15 minutes |
| `POST /auth/login` | 10 per username | 15 minutes |
| `POST /auth/register` | 5 per client address | 60 minutes |

Login is limited on two keys at once. The address limit stops one host working through a list of accounts, and the username limit stops a distributed attempt against a single account.

---

## Health

### `GET /health`

Reports service and dependency state. Intended for a load balancer, so it reflects the database rather than always returning 200.

```json
{
  "status": "ok",
  "service": "inventory-tracker-api",
  "php": "8.4.23",
  "database": "up",
  "checkedAt": "2026-08-16T14:22:05+00:00"
}
```

`status` is `ok` or `degraded`. `database` is `up` or `down`, determined by round-tripping a query rather than by checking that a connection is configured.

---

## Auth endpoints

### `POST /auth/register`

Creates an account. Returns the account rather than a token, so tokens are minted in exactly one place. Clients follow up with `/auth/login`.

**Body**

| Field | Type | Rules |
| --- | --- | --- |
| `username` | string | Required, up to 64 characters, stored lowercased |
| `password` | string | Required, at least 12 characters |

**Response, 201**

```json
{ "id": 7, "username": "warehouse01" }
```

**Errors:** `400` unacceptable username or password, `409` username already taken, `429` too many registrations.

### `POST /auth/login`

Exchanges credentials for a token.

**Body**

| Field | Type |
| --- | --- |
| `username` | string |
| `password` | string |

**Response, 200**

```json
{
  "tokenType": "Bearer",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expiresIn": 3600,
  "user": { "id": 7, "username": "warehouse01" }
}
```

**Errors:** `401` invalid credentials, `429` too many attempts.

### `GET /auth/me`

Returns the account belonging to the bearer token. A client calls this on start-up to decide whether a stored token is still usable.

**Response, 200**

```json
{ "id": 7, "username": "warehouse01" }
```

**Errors:** `401` invalid or missing token, `404` the account was deleted after the token was issued.

---

## Product endpoints

All product endpoints are protected.

### `GET /products`

Lists products with search, filtering, sorting, and paging.

**Query parameters**

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `search` | string | none | Prefix match on name or SKU. `%` and `_` are escaped and match literally |
| `sort` | string | `name` | One of `name`, `sku`, `quantity`, `updatedAt`. Anything else falls back to `name` |
| `direction` | string | `asc` | `asc` or `desc` |
| `lowStock` | boolean | `false` | Only items below the threshold |
| `threshold` | integer | `10` | Clamped to zero or above |
| `includeInactive` | boolean | `false` | Include retired products |
| `page` | integer | `1` | Clamped to 1 or above |
| `perPage` | integer | `25` | Capped at 100 |

Search matches a prefix, not a substring. `SSD` matches `SSD-1024`, and `1024` does not. A leading wildcard cannot use an index and would force a full table scan, so the trade is deliberate.

`sort` is validated against a fixed set rather than passed through. A sort column is an identifier and cannot be bound as a parameter, so the allow-list is the only thing standing between input and the query.

**Response, 200**

```json
{
  "items": [
    {
      "id": 42,
      "sku": "SSD-1024",
      "name": "NVMe SSD 1TB",
      "quantity": 37,
      "isActive": true,
      "updatedAt": "2026-08-16T14:22:05+00:00"
    }
  ],
  "page": 1,
  "perPage": 25,
  "total": 411,
  "pageCount": 17
}
```

### `POST /products`

Creates a product.

**Body**

| Field | Type | Rules |
| --- | --- | --- |
| `sku` | string | Required, up to 64 characters, unique |
| `name` | string | Required, up to 128 characters |
| `quantity` | integer | Optional, defaults to 0, cannot be negative |

**Response, 201.** A [product object](#product).

**Errors:** `400` invalid input, `409` SKU already exists.

### `GET /products/{id}`

**Response, 200.** A [product object](#product). Retired products are returned, since the row still exists.

**Errors:** `404` no such product.

### `PATCH /products/{id}`

Renames a product, sets its quantity to an absolute value, or both. Backs the quantity text field, where the user has typed a specific number.

**Body.** At least one of the following.

| Field | Type | Notes |
| --- | --- | --- |
| `name` | string | Up to 128 characters |
| `quantity` | integer | Absolute value, cannot be negative |

**Response, 200.** The updated [product object](#product).

**Errors:** `400` neither field supplied or a value is invalid, `404` no such product, `409` the product is retired and its stock cannot be changed.

### `PATCH /products/{id}/quantity`

Applies a relative change. Backs the increment and decrement controls.

A delta rather than an absolute value, because two clients each adding one to a quantity of five should end at seven. Sending a computed absolute value loses one of them.

**Body**

| Field | Type | Notes |
| --- | --- | --- |
| `delta` | integer | May be negative |

**Response, 200.** The updated [product object](#product).

**Errors:** `404` no such product, `409` the change would take stock below zero, or the product is retired.

The floor is enforced inside the same statement that applies the change, so simultaneous requests cannot drive stock negative between a check and a write.

### `DELETE /products/{id}`

Retires a product. This is a soft delete, so the row survives, the SKU stays reserved, and history is preserved. Retired products are hidden from listings unless `includeInactive` is set, and their stock cannot be changed until they are restored.

**Response, 200.** The [product object](#product), now with `isActive` false.

**Errors:** `404` no such product.

### `POST /products/{id}/restore`

Brings a retired product back into use, and is the counterpart to `DELETE`. Restoring a product that is already active is not an error, so a repeated request is harmless.

**Body.** None.

**Response, 200.** The [product object](#product), now with `isActive` true.

**Errors:** `404` no such product.

---

## Objects

### Product

```json
{
  "id": 42,
  "sku": "SSD-1024",
  "name": "NVMe SSD 1TB",
  "quantity": 37,
  "isActive": true,
  "updatedAt": "2026-08-16T14:22:05+00:00"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Assigned by the database |
| `sku` | string | Unique product code, up to 64 characters |
| `name` | string | Up to 128 characters |
| `quantity` | integer | Never negative, enforced by a database constraint |
| `isActive` | boolean | False once retired |
| `updatedAt` | string | ISO 8601 with offset |

### Page

| Field | Type | Notes |
| --- | --- | --- |
| `items` | array | The rows for this page |
| `page` | integer | Current page, starting at 1 |
| `perPage` | integer | Rows per page, at most 100 |
| `total` | integer | Rows matching the filters, across all pages |
| `pageCount` | integer | Total pages available |
