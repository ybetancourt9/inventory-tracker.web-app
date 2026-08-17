# Architecture

How the Inventory Tracker is put together, and why it is put together that way.

## Contents

- [Layers](#layers)
- [Request lifecycle](#request-lifecycle)
- [Authentication](#authentication)
- [Database schema](#database-schema)
- [How a search is executed](#how-a-search-is-executed)
- [Concurrent stock changes](#concurrent-stock-changes)
- [Deployment](#deployment)

## Layers

The API follows a ports and adapters arrangement. The domain layer holds entities and the interfaces they need, and it depends on nothing. Infrastructure supplies the implementations. The consequence is that business rules can be tested without a database, and the database can be swapped without touching them.

```mermaid
flowchart TD
    subgraph api["HTTP layer"]
        auth["Auth"]
        products["Products"]
        health["Health"]
        filter["JwtAuthenticator"]
    end

    subgraph app["Application layer"]
        issuer["TokenIssuer"]
        verifier["TokenVerifier"]
        limiter["RateLimiter"]
    end

    subgraph domain["Domain layer"]
        entities["Product, User"]
        query["ProductQuery, ProductSort"]
        ports["Repository interfaces"]
    end

    subgraph infra["Infrastructure layer"]
        doctrine["Doctrine repositories"]
        apcu["APCu rate limiter"]
    end

    dbx[("MySQL")]

    products --> ports
    auth --> ports
    auth --> issuer
    auth --> limiter
    filter --> verifier
    products --> query
    ports --> entities
    doctrine -. implements .-> ports
    apcu -. implements .-> limiter
    doctrine --> dbx
```

The dotted lines are the inversion. Nothing in the domain layer knows Doctrine exists. The composition root in `api/public/index.php` is the single place where interfaces are bound to implementations, which means the wiring is readable in one file rather than scattered across the codebase.

## Request lifecycle

Every request enters through a single front controller. nginx rewrites all non-file requests to `index.php` and refuses to execute any other PHP file, so there is exactly one way in.

```mermaid
sequenceDiagram
    participant B as Browser
    participant C as Caddy
    participant N as nginx
    participant P as PHP-FPM
    participant D as MySQL

    B->>C: HTTPS GET /api/products?search=SSD
    C->>N: HTTP, adds X-Real-IP
    N->>P: FastCGI, /products?search=SSD
    P->>P: Route lookup
    P->>P: JwtAuthenticator verifies bearer token
    P->>P: ProductQuery validates and clamps input
    P->>D: SELECT with bound parameters
    D-->>P: Rows
    P-->>N: JSON
    N-->>C: JSON
    C-->>B: JSON over HTTPS
```

Two details in that chain matter more than they look.

nginx strips the `/api` prefix before handing the request to PHP, so the API has no knowledge of being mounted under a path. Caddy sets `X-Real-IP` and nginx is configured to trust it only from the container network, which is what lets rate limiting see the real caller rather than treating every request as coming from Caddy.

## Authentication

Authentication is stateless. There is no server-side session, so any instance can serve any request.

```mermaid
sequenceDiagram
    participant B as Browser
    participant A as API
    participant D as Database

    B->>A: POST /auth/login
    A->>D: Look up user by username
    D-->>A: User or nothing
    A->>A: Verify password with Argon2id
    Note over A: Failure paths spend the same time,<br/>so a missing account cannot be<br/>distinguished from a wrong password
    A-->>B: 200, signed token valid for one hour

    B->>A: GET /products with bearer token
    A->>A: Verify signature, algorithm pinned to HS256
    A->>A: Check expiry and issuer
    A-->>B: 200 with data, or 401
```

The verification step pins the algorithm rather than reading it from the token. A token that declares a different algorithm is rejected instead of being verified on its own terms, which closes the algorithm confusion attack where a caller supplies a token asking to be checked with a weaker scheme.

Rate limits apply before any work is done. Login allows twenty attempts per address and ten per username in a fifteen minute window, and registration allows five per address per hour. Exceeding either returns 429 with a `Retry-After` header.

## Database schema

Two tables, with no relationship between them.

```mermaid
erDiagram
    PRODUCTS {
        int id PK "auto increment"
        varchar sku UK "64, unique"
        varchar name "128, indexed with is_active"
        int quantity "default 0, CHECK >= 0"
        boolean is_active "default true, soft delete"
        datetime created_at
        datetime updated_at
    }

    USERS {
        int id PK "auto increment"
        varchar username UK "64, unique, lowercased"
        varchar password_hash "255, Argon2id"
        datetime created_at
        datetime updated_at
    }
```

**There is deliberately no foreign key between them.** The application has no concept of product ownership. Every signed-in user works with the same shared inventory, which is how a stockroom actually operates. Adding an owner column would mean either assigning arbitrary owners to existing products or leaving the column empty everywhere, and both encode a relationship the domain does not have.

### Indexes

| Index | Columns | Serves |
| --- | --- | --- |
| `uniq_products_sku` | `sku` | Uniqueness, and lookup by product code |
| `idx_products_active_name` | `is_active`, `name` | Default listing, and the name half of search |
| `idx_products_active_quantity` | `is_active`, `quantity` | The low stock filter |
| `uniq_users_username` | `username` | Login lookup and uniqueness |

The composite indexes put the equality column first and the range column second. An index narrows left to right and stops narrowing once it meets a range condition, so `is_active` first lets the database seek straight to the active rows and then scan a contiguous slice. Reversing the order would force it to choose between filtering on one column and ranging on the other.

### Constraints

`CHECK (quantity >= 0)` is enforced by the database, not only by the application. A rule the application enforces holds as long as every code path remembers to call it. A rule the database enforces holds regardless of what sends the statement.

## How a search is executed

Searching name and SKU in a single condition reads naturally and performs badly, because the database cannot narrow its scan on one column when a row might qualify on the other. Measured against twenty thousand rows it examined 14,834 rows to return 25.

The query is therefore split so each half can use its own index, and the results are combined.

```mermaid
flowchart TD
    input["search term"] --> escape["Escape % and _ then append wildcard"]
    escape --> branchA["Match on name<br/>uses idx_products_active_name"]
    escape --> branchB["Match on sku<br/>uses uniq_products_sku"]
    branchA --> union["UNION, then sort and page"]
    branchB --> union
    union --> ids["Ordered list of matching IDs"]
    ids --> hydrate["Load those products"]
    hydrate --> map["Reorder using a hash map keyed by ID"]
    map --> out["Page of results"]
```

The wildcard is only appended, never prepended. A pattern like `term%` can use an index, while `%term%` forces a full scan and would undo the point of indexing the column. The cost of that decision is that searching for a word in the middle of a name will not match it, which is a real limitation and a deliberate trade.

The hash map exists because the second query returns rows in whatever order the database finds convenient, so the sort has to be reapplied. Searching the result list for each ID would be linear per lookup and quadratic overall. A map keyed by ID makes each lookup constant time and the whole reorder linear.

## Concurrent stock changes

The original implementation read the current quantity, adjusted it in application code, and wrote the result back. One hundred simultaneous decrements against a stock of one hundred lost forty one of them, silently.

```mermaid
sequenceDiagram
    participant A as Request A
    participant B as Request B
    participant D as Database

    Note over A,D: Read then write, the broken version
    A->>D: SELECT quantity
    D-->>A: 100
    B->>D: SELECT quantity
    D-->>B: 100
    A->>D: UPDATE quantity = 99
    B->>D: UPDATE quantity = 99
    Note over D: Two decrements applied, one lost

    Note over A,D: Conditional update, the fix
    A->>D: UPDATE quantity = quantity - 1 WHERE quantity - 1 >= 0
    D-->>A: 1 row affected
    B->>D: UPDATE quantity = quantity - 1 WHERE quantity - 1 >= 0
    D-->>B: 1 row affected
    Note over D: 98, both applied
```

The arithmetic and the floor check happen inside a single statement, so the database decides the outcome rather than the application. When the condition fails no row is affected, and the API turns that into a 409 rather than reporting a success that did not happen.

## Deployment

The application runs as container images on a single virtual machine, talking to a managed database.

```mermaid
flowchart TB
    dev["Workstation"]
    ecr["Container registry"]

    subgraph cloud["AWS, single region"]
        subgraph vpc["Virtual private cloud"]
            subgraph ec2["Application host"]
                cad["Caddy"]
                ngx["nginx"]
                php["PHP-FPM"]
            end
            rds[("MySQL, no public address")]
            bastion["Bastion, no inbound ports"]
        end
        params["Parameter Store<br/>encrypted secrets"]
        dns["DNS"]
    end

    dev -- "build, tag with commit" --> ecr
    ecr -- "pull on deploy" --> ec2
    dev -- "deploy over SSM" --> ec2
    params -- "injected at startup" --> php
    php -- "TLS" --> rds
    bastion -- "tunnel for migrations" --> rds
    dns --> cad
```

Three decisions are worth calling out.

**Images are tagged with the commit they were built from.** A running host can always be traced back to an exact commit, and rolling back means deploying an earlier tag rather than reverting code.

**Secrets never touch disk.** Database credentials and the token signing key are read from Parameter Store at startup, written to a temporary filesystem that exists only in memory, consumed, and then destroyed. Nothing sensitive appears in the repository, in an image layer, or in a disk snapshot.

**There is no SSH.** Deployments and administration go through AWS Systems Manager, so the application host has no inbound port open other than the two the website needs, and no key pair exists to be stolen.

Database migrations are run manually through the bastion rather than as part of a deployment, because the account the application uses has no permission to change the schema. That is inconvenient by design.
