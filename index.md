# Yaumel Betancourt

## CS 499 Computer Science Capstone ePortfolio

Southern New Hampshire University

This ePortfolio presents a single application enhanced across three categories of computer science practice. Every document and every folder of code referenced below lives in [this repository](https://github.com/ybetancourt9/inventory-tracker.web-app).

**The finished application is deployed and running at [inventtracker.com](https://inventtracker.com).**

---

## Contents

1. [Professional self-assessment](#professional-self-assessment)
2. [The artifact](#the-artifact)
3. [Enhancement one: software design and engineering](#enhancement-one-software-design-and-engineering)
4. [Enhancement two: algorithms and data structures](#enhancement-two-algorithms-and-data-structures)
5. [Enhancement three: databases](#enhancement-three-databases)
6. [Supporting documentation](#supporting-documentation)

---

## Professional self-assessment

The self-assessment introduces this portfolio and reflects on what the program and the capstone taught me, including the places where a measurement contradicted something I was confident in.

**[Read the professional self-assessment](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/professional-self-assessment)** or [download the Word document](https://github.com/ybetancourt9/inventory-tracker.web-app/blob/main/docs/CS%20499%20Professional%20Self-Assessment.docx)

---

## The artifact

Every enhancement in this portfolio applies to one application rather than to three unrelated samples, so each improvement had to live with the decisions made by the one before it.

The artifact began as an inventory tracker built in CS 360 Mobile Architecture and Programming. It was an Android application written in Java that stored its data in a local SQLite file on a single phone. Only the person holding that device could see or change anything, there was no way to search or reorder the list, and if the phone was lost the inventory went with it.

| | Original artifact | Enhanced artifact |
| --- | --- | --- |
| Platform | Android application | Web application |
| Language | Java | PHP, TypeScript |
| Data | SQLite file on one device | MySQL on AWS RDS |
| Access | One phone | Any browser, over HTTPS |
| Finding an item | Scroll the full list | Search, sort, filter, paginate |
| Passwords | Hash with no salt | Argon2id with a unique salt |
| Availability | The device it was installed on | [inventtracker.com](https://inventtracker.com) |

- [Original artifact repository](https://github.com/ybetancourt9/CS360-Inventory-Tracker)
- [Enhanced artifact repository](https://github.com/ybetancourt9/inventory-tracker.web-app)

---

## Enhancement one: software design and engineering

The monolithic Android application was separated into an Angular client, a PHP REST API, and a database, with the API acting as the contract between them. Authentication was rebuilt around signed tokens, and passwords were moved to Argon2id.

**[Read the narrative](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/milestone-two-narrative)** or [download the Word document](https://github.com/ybetancourt9/inventory-tracker.web-app/blob/main/docs/CS%20499%20Milestone%20Two%20Narrative.docx)

Code produced by this enhancement:

| Folder | Contents |
| --- | --- |
| [`web/src/app`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/web/src/app) | Angular client, including the login and inventory screens |
| [`api/src/Api`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Api) | HTTP endpoints and the authentication filter |
| [`api/src/Application/Auth`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Application/Auth) | Token issuing and verification |
| [`api/src/Domain`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Domain) | Entities and the repository interfaces they depend on |
| [`api/public/index.php`](https://github.com/ybetancourt9/inventory-tracker.web-app/blob/main/api/public/index.php) | The single entry point and composition root |

---

## Enhancement two: algorithms and data structures

Search, sorting, filtering, and pagination were pushed out of application memory and into indexed database queries. Query plans were measured against twenty thousand rows rather than assumed, and more than one design changed as a result. The final search examines 2,403 rows where the original examined 14,834 to return the same 25 results.

**[Read the narrative](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/milestone-three-narrative)** or [download the Word document](https://github.com/ybetancourt9/inventory-tracker.web-app/blob/main/docs/CS%20499%20Milestone%20Three%20Narrative.docx)

Code produced by this enhancement:

| Folder | Contents |
| --- | --- |
| [`api/src/Domain/Product`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Domain/Product) | Query criteria and the enumeration restricting sortable columns |
| [`api/src/Infrastructure/Doctrine/Repository`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Infrastructure/Doctrine/Repository) | The split search query and the hash map that reorders its results |
| [`api/src/Domain/Pagination`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Domain/Pagination) | The generic page structure returned to callers |
| [`api/tests`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/tests) | 163 tests across a unit suite and an integration suite |

---

## Enhancement three: databases

The data moved from a local SQLite file to MySQL on AWS RDS, with the schema defined by versioned migrations rather than by code that runs at first launch. A database constraint makes negative stock impossible, an atomic conditional update fixed the loss of 41 out of 100 simultaneous decrements, and the application connects using an account restricted to reading and writing rows.

**[Read the narrative](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/milestone-four-narrative)** or [download the Word document](https://github.com/ybetancourt9/inventory-tracker.web-app/blob/main/docs/CS%20499%20Milestone%20Four%20Narrative.docx)

Code produced by this enhancement:

| Folder | Contents |
| --- | --- |
| [`api/migrations`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/migrations) | Five versioned migrations, including the negative stock constraint |
| [`api/src/Domain/Entity`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Domain/Entity) | Schema defined as entity mappings, with indexes and constraints |
| [`api/src/Infrastructure/Doctrine`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/api/src/Infrastructure/Doctrine) | Data access, including the atomic quantity adjustment |
| [`deploy/aws`](https://github.com/ybetancourt9/inventory-tracker.web-app/tree/main/deploy/aws) | Deployment configuration and the least privilege policy |

---

## Supporting documentation

Written for a reader who did not build the system and may need to change it.

| Document | Contents |
| --- | --- |
| [Architecture](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/architecture) | Eight diagrams covering the request lifecycle, the schema, the search algorithm, the concurrency fix, and the deployment |
| [API reference](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/api) | All eleven endpoints with parameters, responses, and error codes |
| [Decision records](https://ybetancourt9.github.io/inventory-tracker.web-app/docs/decisions) | Twenty decisions, what each cost, and why the alternative was rejected |
| [Repository README](https://github.com/ybetancourt9/inventory-tracker.web-app#readme) | How to run the project locally and what the layout means |
