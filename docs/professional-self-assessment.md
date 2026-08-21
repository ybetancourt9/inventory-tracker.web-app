Professional Self-Assessment

Yaumel Betancourt

CS 499 Computer Science Capstone

Southern New Hampshire University

## Introduction

I came into the Computer Science program already working as a software engineer, and that shaped what I took from it. I did not need an introduction to writing code. What I needed was the reasoning that separates code that works from code that holds up, and exposure to the parts of the field my daily work never forced me to confront. Most of my professional experience is in backend development, so my weakest areas were the ones furthest from it, meaning frontend development, cloud infrastructure, and the discipline of proving a design behaves the way I claimed rather than the way I assumed.

The capstone is where I put that to the test. The artifact is an inventory application I originally built in CS 360 as an Android program that kept its data in a local file on a single phone. I rebuilt it as an Angular client, a PHP REST API with 11 endpoints, and a MySQL database running on AWS, then enhanced it separately for software design, for algorithms and data structures, and for databases. Working through one system three times rather than building three unrelated samples meant each enhancement had to live with the decisions made by the one before it, which is much closer to how professional work actually goes.

What follows is what the process taught me. The most valuable parts were not techniques I could have read about. They were the times a measurement contradicted something I was confident in.

## Measuring instead of assuming

The clearest example is the search feature. My original implementation examined 14,834 database rows to return 25 results. It reads naturally and would have passed a code review, because nothing about reading the code suggests a problem. It only became visible when I ran it against a realistic amount of data and looked at the query plan, which showed that a single condition spanning two columns prevents the database from narrowing its scan.

The more useful lesson came from the fix. My first correction made the system slower rather than faster, moving from 6.89 milliseconds to 10.8. The restructured query was right in principle, and one half of it still could not use an index efficiently. The approach only paid off after a second change, a composite index with the equality column placed ahead of the range column, and the final version examines 2,403 rows in 2.95 milliseconds.

Two correct ideas were not enough on their own. If I had stopped after the first, I would have shipped something worse while believing I had improved it, and nothing about the code would have told me. That has changed how I treat performance work generally. I no longer trust a change because it should be faster, and I have stopped describing improvements I have not measured.

A related lesson came from a data structure. My plan called for a hash map before I knew where one belonged, and the place it turned out to be necessary was not where I expected. Reordering results by identifier after loading them would have been quadratic, and a map keyed by identifier made the same work linear. The structure earned its place because the algorithm needed it, not because the plan mentioned one.

## What a passing test suite does not tell you

The single most instructive bug in this project was one that a full suite of passing tests failed to catch. My search used a bound parameter in a position the query language does not permit, which meant every search request would have failed against the real database. Fifty five unit tests passed anyway, because they ran against an in-memory substitute that happily accepted a query the real engine rejects.

I built a separate integration suite that runs against real MySQL, and then checked that it was worth having by deliberately reintroducing the bug. The unit tests passed 118 out of 118 while the integration tests failed immediately. The system now carries 163 tests across the two suites, and I think about them differently. Tests against a substitute verify my logic. Only tests against the real engine verify my queries. I had understood that distinction abstractly for years without ever having been burned by it, and being burned by it is what made it real.

## Moving rules into the database

Working on the database category changed where I think rules belong. In the original application the rule that stock cannot go negative existed only as a check in application code, which means it held exactly as long as every code path remembered to call it. The rebuilt version enforces it with a database constraint that refuses the value regardless of what sends the statement. A rule the application enforces is a convention, and a rule the database enforces is a guarantee.

Concurrency taught me something less obvious. My quantity adjustment read the current value, changed it in code, and wrote it back. Sending 100 simultaneous decrements at a product holding 100 units lost 41 of them with no error reported to anyone. What surprised me is that the naive version could never produce a negative number, because each request writes an absolute value it computed, so its only failure mode was losing updates. Making the update atomic fixes that and reintroduces the possibility of going below zero, which means both properties have to be handled in the same statement. Fixing one without the other would have traded one bug for another. After the change the same test loses nothing, and 20 simultaneous decrements against a stock of 5 produce exactly 5 successes and 15 refusals.

I also learned to think about infrastructure in cost terms. Choosing between two managed database services, the honest comparison was not about which was more capable. One charges for reserved capacity and the other for every read and write, and the more powerful option would have roughly quadrupled the bill. When I measured actual usage it was about 1 percent of the capacity already provisioned, which made the cheaper service the correct engineering answer rather than a compromise. I had never before had to justify an architectural decision in dollars, and I expect to do it for the rest of my career.

## Security in the configuration you actually run

I already treated security as something to design in rather than add on. What this project taught me is how much of it depends on the environment you actually deploy rather than the one you develop in.

The application uses Argon2id with a unique salt per password, pins the token signing algorithm at verification rather than trusting a token to declare its own, sends every value to the database as a bound parameter, restricts sortable columns with an enumeration because a column name cannot be parameterized, and connects using an account that holds three privileges and is refused everything else. I verified those rather than assuming them, testing the endpoints with injection payloads and attempting each forbidden database operation to watch it fail.

The lesson arrived when I ran the application in production configuration for the first time and found three defects that existed only there. One returned the internal class name of my authentication filter on a rejected request, quietly disclosing how the system is built. My own mitigation was correct and the framework leaked around it. I now assume that a control is only as good as its behavior in the configuration being deployed, and I test that configuration rather than trusting it to resemble development.

Deriving permissions taught me something similar. My instinct was to grant the application the familiar set including delete. Before doing it I checked whether the code issues a delete anywhere, and it does not, because retiring a product sets a flag instead. Granting it would have handed the running application the ability to destroy data it has no path to destroy. Deriving permissions from what the code does, rather than from what applications usually need, produced something tighter than habit would have.

## Writing it down changed what I built

I expected documentation to be a task at the end. It turned out to affect the work itself.

The system now carries 8 architecture diagrams, a reference for all 11 endpoints, and 20 decision records that state what each significant choice cost and why the alternative was rejected. Producing the decision records was the part that changed my thinking, because knowing I would have to justify a choice in writing led me to reject a few designs I would otherwise have shipped and defended informally. It is easy to be satisfied with a decision you never have to explain.

It also taught me to state limitations plainly. My search matches a prefix rather than a substring, which is a deliberate trade that keeps the query able to use an index and genuinely means a user cannot find a word in the middle of a product name. Writing that down as a cost rather than omitting it made the document more useful and, I think, more credible. I applied the same approach to weaknesses I accepted rather than removed, including where the browser stores the authentication token, recording the condition under which each must be revisited.

## Working so others can follow

The capstone is built alone, so my experience of team environments comes mostly from professional work. What this program added is the practice of making my work legible to someone who is not me.

The schema is defined by 5 versioned migrations, so a schema change arrives as a reviewable diff instead of a surprise. The integration suite means a contributor who changes a query finds out immediately whether they broke it. The dependency wiring lives in a single file that can be read in one sitting. None of those choices help a solo developer much, and all of them matter to whoever inherits the project.

## Where this leaves me

I want to move into a systems architect role, and this program changed my understanding of what that work is. I used to think architecture was the design you produce before the code, meaning the decisions and diagrams that come first. I now think that is half of it. The other half is confirming that the design behaves the way you said it would, and this project gave me several reminders that a decision can be defensible on paper and wrong in practice.

The specific gap I set out to close was cloud infrastructure, which is why I built and deployed the infrastructure myself rather than staying in the backend code where I was already comfortable. The application runs at a public address on infrastructure I provisioned, which taught me more about managed services, cost, and operational security than reading about them would have. I plan to continue in that direction with an AWS certification after I finish the degree.

What I take from the program overall is a stricter standard for what counts as knowing something. Before this I would have said I understood indexing, concurrency, and least privilege, and I would have been describing familiarity rather than knowledge. Having measured a query plan, watched 41 updates disappear, and been refused by a database I had deliberately restricted, I understand them differently. The artifacts that follow are the record of that difference.
