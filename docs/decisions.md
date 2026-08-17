# Architecture decision records

A record of the significant decisions made while re-engineering the Inventory Tracker, why each was made, and what it cost.

These exist because the reasoning behind a decision disappears faster than the code that resulted from it. Six months from now the code will still say what was built. Only this file says why the alternative was rejected, and that is the part someone needs before changing it.

Each record is written to be readable on its own. A reader who disagrees with a decision should be able to find the constraint that produced it and judge whether that constraint still holds.

**Status meanings**

| Status | Meaning |
| --- | --- |
| Accepted | In effect |
| Accepted with known risk | In effect, with a documented weakness and the conditions under which it must be revisited |
| Superseded | Replaced by a later record, kept because the reasoning is still instructive |

## Index

| # | Decision | Status |
| --- | --- | --- |
| [1](#1-separate-the-client-from-the-api) | Separate the client from the API | Accepted |
| [2](#2-run-everything-in-containers-pinned-to-exact-versions) | Run everything in containers, pinned to exact versions | Accepted |
| [3](#3-stay-on-php-84) | Stay on PHP 8.4 | Accepted |
| [4](#4-put-data-access-behind-domain-interfaces) | Put data access behind domain interfaces | Accepted |
| [5](#5-keep-products-and-users-unrelated) | Keep products and users unrelated | Accepted |
| [6](#6-do-search-sorting-and-filtering-in-the-database) | Do search, sorting and filtering in the database | Accepted |
| [7](#7-match-prefixes-not-substrings) | Match prefixes, not substrings | Accepted with known risk |
| [8](#8-order-composite-indexes-equality-before-range) | Order composite indexes equality before range | Accepted |
| [9](#9-make-stock-changes-atomic-and-enforce-the-floor-in-the-database) | Make stock changes atomic and enforce the floor in the database | Accepted |
| [10](#10-retire-products-instead-of-deleting-them) | Retire products instead of deleting them | Accepted |
| [11](#11-hash-passwords-with-argon2id-and-no-pepper) | Hash passwords with Argon2id and no pepper | Accepted |
| [12](#12-authenticate-with-stateless-tokens-held-in-localstorage) | Authenticate with stateless tokens held in localStorage | Accepted with known risk |
| [13](#13-keep-self-service-registration-open) | Keep self-service registration open | Accepted with known risk |
| [14](#14-restrict-the-applications-database-account) | Restrict the application's database account | Accepted |
| [15](#15-require-tls-at-the-database-server) | Require TLS at the database server | Accepted |
| [16](#16-pin-the-database-to-mysql-8046) | Pin the database to MySQL 8.0.46 | Supersedes an earlier pin |
| [17](#17-choose-rds-over-aurora) | Choose RDS over Aurora | Accepted |
| [18](#18-deploy-to-one-ec2-host-with-compose-rather-than-fargate) | Deploy to one EC2 host with Compose rather than Fargate | Accepted |
| [19](#19-terminate-tls-with-caddy-on-the-instance-rather-than-cloudfront) | Terminate TLS with Caddy on the instance rather than CloudFront | Accepted |
| [20](#20-keep-rate-limit-counters-in-process-memory) | Keep rate limit counters in process memory | Accepted with known risk |

---

## 1. Separate the client from the API

**Status:** Accepted

**Context.** The original artifact was an Android application where the user interface, the business rules, and the SQLite calls lived in the same activity classes. Data existed on one device, so no second user could ever see it, and the storage layer could not be changed without touching the screens.

**Decision.** Split it into an Angular client, a JSON REST API, and a database. The API is the contract between them.

**Consequences.** Several people can now work from the same inventory, and either side can be rewritten independently as long as the contract holds. The cost is that a single process became three, which introduces network failure, authentication, and versioning problems that did not exist before. A local function call cannot fail halfway.

**Alternatives considered.** Keeping the mobile application and syncing to a server was rejected because synchronisation and conflict resolution are harder problems than the one being solved.

---

## 2. Run everything in containers, pinned to exact versions

**Status:** Accepted

**Context.** Development began with the option of a local PHP install. That path produces an environment nobody else can reproduce and that drifts from production.

**Decision.** Every service runs in Docker, and every image is pinned to an exact patch version rather than a floating tag such as `8` or `latest`.

**Consequences.** The environment is reproducible and the same image that was tested is the one that runs in production. Upgrades become deliberate edits rather than something that happens silently on a rebuild. The cost is that upgrades must be done by hand, so a security patch does not arrive on its own and someone has to watch for it.

---

## 3. Stay on PHP 8.4

**Status:** Accepted

**Context.** PHP 8.5 was available and newer is usually better.

**Decision.** Pin to PHP 8.4.

**Consequences.** The project runs one version behind the latest. This is recorded because the reason is invisible in the code. Restler pulls in `react/promise` v2.11 transitively, which uses syntax that 8.5 deprecates, and there is no upgrade path that removes the warning. This was established by testing the actual dependency tree rather than by assuming. The pin should be revisited when Restler updates that dependency, not before.

---

## 4. Put data access behind domain interfaces

**Status:** Accepted

**Context.** Controllers that use an ORM directly become impossible to test without a database and impossible to change without touching every caller.

**Decision.** The domain layer defines repository interfaces. Doctrine implementations live in the infrastructure layer and are bound in one composition root. Controllers receive interfaces and never an entity manager.

**Consequences.** Business rules are testable in memory and the persistence technology can be replaced without touching them. The cost is indirection, meaning more files and one more hop to follow when reading the code. It also created a trap that took a real bug to expose, recorded in decision 6.

---

## 5. Keep products and users unrelated

**Status:** Accepted

**Context.** The original plan for this project listed a foreign key associating each product with the user who owns it, while the detailed design in the same document described two independent tables with no relationship between them. The plan contradicted itself and a choice had to be made.

**Decision.** Two independent tables. No foreign key.

**Consequences.** The schema matches the domain, because every signed-in user works with the same shared inventory the way a stockroom actually operates. The cost is that this artifact does not demonstrate relational modelling with joins, which is a common expectation of a database project. That is stated plainly rather than papered over.

**Alternatives considered.** Adding an owner column would have required either assigning arbitrary owners to existing products or leaving the column empty on all of them. Both encode a relationship the domain does not have, which is worse than not having the column.

---

## 6. Do search, sorting and filtering in the database

**Status:** Accepted

**Context.** The original application loaded every product and filtered in memory, which is invisible at ten rows and fatal at twenty thousand.

**Decision.** Push search, sorting, filtering and paging into indexed SQL. Verify with query plans rather than assumption.

**Consequences.** The number of rows examined became proportional to the number of matches rather than to the size of the table. The cost is that the design is now tied to what an index can do, which directly produced decision 7.

This decision also exposed a gap in decision 4. Unit tests ran against an in-memory repository double that happily accepted a query the real database rejects, so fifty five tests passed while every search request would have returned a server error. A separate integration suite that runs against real MySQL now exists for exactly this reason. Tests against a double verify logic, and only tests against the real engine verify queries.

---

## 7. Match prefixes, not substrings

**Status:** Accepted with known risk

**Context.** Users expect to find a product by typing part of its name.

**Decision.** Search matches a prefix. The wildcard is appended and never prepended.

**Consequences.** The query can use an index and stays a range scan. The cost is real and user facing, because searching for a word in the middle of a product name will not find it. Someone typing `wireless` will not match `Anker Wireless Mouse`.

**Alternatives considered.** A leading wildcard cannot use an index and would force a full table scan, which would undo the point of decision 6. Full text or trigram indexing would give true substring matching and is the correct answer at a scale this project does not have. Revisit if users report the limitation, or if the catalogue grows enough that browsing is no longer a workable substitute.

---

## 8. Order composite indexes equality before range

**Status:** Accepted

**Context.** The low stock filter and the default listing both filter on an active flag and then range or sort on a second column.

**Decision.** Composite indexes place the equality column first and the range column second, giving `(is_active, quantity)` and `(is_active, name)`.

**Consequences.** The database seeks directly to active rows and then scans a contiguous slice, examining 357 rows out of 20,010 for the low stock filter. Reversing the order forces a choice between filtering on one column and ranging on the other, and measurement showed it choosing wrong and examining 19,613 rows.

The general cost of indexes applies. Each one speeds reads and slows every insert and update, which is why a redundant single column index on `name` was later dropped once the composite covered it.

---

## 9. Make stock changes atomic and enforce the floor in the database

**Status:** Accepted

**Context.** Adjusting stock by reading the value, changing it in code, and writing it back loses updates under concurrency. Measured with one hundred simultaneous decrements against a stock of one hundred, forty one vanished with no error reported.

**Decision.** Do the arithmetic and the floor check inside a single conditional `UPDATE`, and back it with a `CHECK (quantity >= 0)` constraint. Zero rows affected becomes a 409.

**Consequences.** Concurrent adjustments compose correctly, and stock cannot go negative regardless of which client sends what. Re-running the same test loses nothing, and twenty simultaneous decrements against a stock of five produce exactly five successes and fifteen refusals.

There is a subtlety worth recording. The naive version could never produce a negative number, because each request wrote an absolute value it had computed, so its only failure was losing updates. Making the update atomic fixes that and reintroduces the possibility of going negative, so both properties must be handled in the same statement. Fixing one without the other would have traded one bug for another.

---

## 10. Retire products instead of deleting them

**Status:** Accepted

**Context.** Deleting a product destroys history and frees its SKU for reuse, which makes past records ambiguous.

**Decision.** Deletion sets an `is_active` flag. Retired products are hidden unless explicitly requested, their stock cannot be changed, and a restore endpoint brings them back.

**Consequences.** History survives and SKUs stay reserved. The costs are that every query must filter on the flag and that retired rows accumulate with no cleanup process.

The restore path was not in the original design and was added after testing revealed the gap. Retirement without it was a one-way door, and quantities could still be changed on retired products, which meant writes were reaching the database for items no longer carried. A lifecycle with two states needs both transitions designed, not one.

---

## 11. Hash passwords with Argon2id and no pepper

**Status:** Accepted

**Context.** The original application stored password hashes with no salt. A separate question was whether to add an application-held pepper.

**Decision.** Argon2id, chosen explicitly rather than through `PASSWORD_DEFAULT`, which is still bcrypt in PHP 8.4. No salt column and no pepper. The application refuses to start rather than fall back to a weaker algorithm if Argon2id is unavailable.

**Consequences.** Every password gets a unique salt generated and stored inside the hash string, which is why no salt column exists and why the same password produces different hashes. A pepper was considered and declined, because it only helps when an attacker obtains the database but not the application configuration, and it introduces a key that must be rotated and cannot be rotated without every user resetting their password. The trade was judged not worth it at this scale.

---

## 12. Authenticate with stateless tokens held in localStorage

**Status:** Accepted with known risk

**Context.** The API needs to identify callers without server-side session state.

**Decision.** Signed JSON Web Tokens using HS256, verified with the algorithm pinned rather than read from the token. The client stores the token in `localStorage`.

**Consequences.** Any instance can serve any request, which keeps the API horizontally scalable. Pinning the algorithm closes the confusion attack where a caller supplies a token asking to be verified under a weaker scheme.

The known risk is storage. A token in `localStorage` is readable by any script that achieves cross-site scripting on the page. An `httpOnly` cookie would not be, at the cost of needing cross-site request forgery protection. The storage decision is deliberately confined to about three lines of the client so it can be changed in one place, and it should be changed before this holds data that matters to anyone but its author.

A second gap is that tokens cannot be revoked before they expire. A `jti` claim exists as the hook for a deny list, and nothing consumes it yet.

---

## 13. Keep self-service registration open

**Status:** Accepted with known risk

**Context.** Registration returns 409 when a username is taken, which confirms that an account exists.

**Decision.** Leave registration open and the 409 in place. Add rate limiting instead.

**Consequences.** The enumeration weakness is real and is accepted knowingly. It is recorded because the obvious fix does not work and someone will propose it. Returning a vague error is theatre, because an attacker registers a name with a password they choose, and whether they can subsequently log in tells them if the account existed regardless of what the message said. Meanwhile legitimate users lose the ability to learn they need a different name.

**Alternatives considered.** Closing registration in favour of invite-only provisioning removes the oracle entirely and arguably suits an internal stockroom tool better, but needs a bootstrap path. Moving to email plus verification with a neutral response is the only true fix and requires mail delivery, verification tokens, and expiry state. Revisit if this is ever exposed to a real user base.

Login does not leak the same information. It returns one shared 401 and equalises timing with a dummy verification so a missing account cannot be distinguished from a wrong password.

---

## 14. Restrict the application's database account

**Status:** Accepted

**Context.** Applications are commonly granted broad rights on their own schema, which means a successful injection or a code defect can drop tables.

**Decision.** The application account holds `SELECT`, `INSERT` and `UPDATE` on one schema and nothing else. Migrations run under a separate, more privileged account at deploy time.

**Consequences.** The credentials the running application holds cannot reshape or destroy the database. Verified by connecting as that account and attempting each forbidden operation, all of which were refused.

`DELETE` was withheld because the application never issues one, since retirement is a flag update under decision 10. That was checked against the code rather than assumed from what applications usually need. The cost is that a future feature requiring deletion needs a deliberate grant instead of working by default, which is the correct direction for that friction to run.

---

## 15. Require TLS at the database server

**Status:** Accepted

**Context.** The database is unreachable from the internet, but traffic between the application and the database was still plaintext, and RDS permits plaintext connections by default.

**Decision.** Enable encryption in the client and set `require_secure_transport = ON` on the server so unencrypted connections are refused.

**Consequences.** An unencrypted connection is rejected before it can authenticate. This is recorded because the client-side setting alone was not enough. A setting the client chooses is a preference, and only a setting the server enforces is a guarantee. Certificate verification is on for direct connections and is disabled only when connecting through a tunnel, where the client dials localhost and no certificate names that host. Traffic stays encrypted either way.

---

## 16. Pin the database to MySQL 8.0.46

**Status:** Accepted, supersedes an earlier pin to 8.0.40

**Context.** Local development ran MySQL 8.0.40 and the intent was to match it exactly in the cloud. RDS no longer offers that release, and its oldest available 8.0 version is 8.0.42.

**Decision.** Move both environments to 8.0.46 rather than letting them differ, and pin character set, collation and time zone through a parameter group.

**Consequences.** Local and cloud run the same optimizer, which matters because the performance measurements supporting decisions 6, 7 and 8 are only meaningful if the same engine produced them.

The collation half of this is the part that would have gone unnoticed. MySQL 8 defaults to a different collation than the local container was configured with, and nothing fails loudly when they differ. Text comparison and sorting simply behave differently in the two environments, which surfaces much later as a defect nobody can reproduce.

---

## 17. Choose RDS over Aurora

**Status:** Accepted

**Context.** The original plan specified Aurora MySQL. Aurora offers distributed storage across three availability zones, automatic storage scaling, and considerably higher write throughput.

**Decision.** Use RDS MySQL on a `db.t4g.micro` instance with gp3 storage.

**Consequences.** Aurora would have roughly quadrupled the database cost, because it has no micro tier and bills per input and output operation on top of the instance. RDS with gp3 includes disk operations in the storage price and has no per-operation meter.

The decision was made on measurement rather than preference. Peak usage during the heaviest write burst the system has handled was 32 write operations per second against a ceiling of 3,000, which is about one percent. Paying four times as much to remove a ceiling being used at one percent is not justifiable. Revisit if sustained write throughput approaches the ceiling, or if the storage volume grows past 400 GB, at which point higher provisioned throughput becomes available anyway.

---

## 18. Deploy to one EC2 host with Compose rather than Fargate

**Status:** Accepted

**Context.** The application is two container images that need somewhere to run.

**Decision.** A single `t3.micro` instance running Docker Compose, pulling images from a private registry.

**Consequences.** The load balancer that ECS Fargate effectively requires costs more per month than everything else in this deployment combined, for an application serving a handful of users. The cost of this choice is honest and worth stating, because a single instance is a single point of failure with no horizontal scaling and no rolling deployment. For a system with one user that is the correct trade, and it would be the wrong trade for a system with real availability requirements.

The instance is `t3.micro` rather than the cheaper ARM equivalent because images are built on an x86 workstation. Saving a dollar a month in exchange for cross-architecture builds was not worth the failure mode, which appears at run time as an exec format error rather than at build time.

---

## 19. Terminate TLS with Caddy on the instance rather than CloudFront

**Status:** Accepted

**Context.** The site needs HTTPS on its own domain. CloudFront with a certificate from ACM is the conventional answer and adds caching and absorption of denial of service traffic.

**Decision.** Run Caddy as the edge container on the instance, obtaining and renewing Let's Encrypt certificates automatically.

**Consequences.** HTTPS works with about ten lines of configuration and no certificate renewal process to maintain. The trade is that there is no CDN, so every request reaches the instance.

The deciding factor was rate limiting. Limits are keyed on the client address, and a CDN in front would make every request appear to originate from the CDN, so all callers would share one bucket and could lock each other out. Using CloudFront correctly requires configuring trusted proxy handling in the same change, and adding a second moving part to a single-user application was not justified. Caddy sets a real client address header that nginx trusts only from the container network, which keeps the limits meaningful. If a CDN is added later, the proxy handling must land in the same change and not after it.

---

## 20. Keep rate limit counters in process memory

**Status:** Accepted with known risk

**Context.** Login and registration needed throttling. A shared store such as Redis is the usual answer.

**Decision.** A fixed-window counter held in APCu shared memory, behind a `RateLimiter` interface with a null implementation for environments where APCu is unavailable.

**Consequences.** No extra infrastructure and no additional cost. Counters are shared across all worker processes on a host.

The known limits are that counters reset when the container restarts and are not shared between hosts, so running more than one instance would multiply the effective limit by the number of instances. That is acceptable for the single-host deployment in decision 18 and stops being acceptable the moment a second instance exists. The interface is what makes this replaceable without touching the endpoints.
