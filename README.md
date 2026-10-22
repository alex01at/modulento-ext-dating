# Dating

A swipe-matching "app" extension for [Modulento](https://github.com/alex01at/modulento):
a profile per account, a swipe queue, mutual-like matches, a chat per
match, and peer-to-peer block/report built in from the start.

## What it adds

- A dating profile (`/account/dating`), separate from the marketplace
  provider profile: display name, date of birth (18+), gender, who you are
  looking for, a bio, a location (for the radius search) and up to 6
  photos.
- A swipe queue (`/dating/swipe`) that excludes anyone already swiped,
  blocked in either direction, outside the chosen radius, or not matching
  gender/preference in either direction.
- A match and its own chat the moment both sides have liked each other
  (`/dating/matches`).
- Blocking and reporting, from the first release, not added later.
- Premium features sold through a subscription plan
  (`modulento-ext-abos`, or any other `SubscriptionProvider`): unlimited
  likes (free accounts get a daily limit), seeing who already liked you,
  undoing the last pass, and a profile boost.

## Installation

Drop this repository (or a release ZIP from its GitHub releases page) into
the core's `extensions/dating` folder and activate it under
Administration → Extensions. As an "app"-type extension, activating it
switches off whichever other app (freelancer, shop, auction, card, blog)
was active - its data is kept, only switched off.

## Tests

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```

Runs entirely against an in-memory SQLite fixture; no real network
request is made (geocoding is stubbed in the test file).
