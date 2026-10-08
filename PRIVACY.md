# Kizami: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis, retention period, data subject rights and hosting details for
their own site.

## What happens on a request

- Page views and clicks on configured redirect links are counted **on the web
  server**. Optionally, a small script reports how many seconds a page was
  visible, and a gallery can report deliberate opening of selected photos.
- **No analytics cookies, no browser storage, no external service.**
- With **Do Not Track** or **Global Privacy Control** set to 1, nothing is
  recorded. Known bots are filtered out.

## What is stored

Page path, timestamp, referring domain, campaign tags from the link
(`utm_source`, `utm_medium`, `utm_campaign`), name, origin page and position of
selected redirect clicks, a rough device class (phone, tablet, desktop),
requests for missing pages, and a **day identifier**. If enabled, it also
stores image IDs and seconds on page.

The day identifier is a hash of the IP address and user agent the server
receives, the date, and a secret key that is **replaced every day**. The raw IP
address and user agent are not stored in the statistics database. Rotating the
key reduces linkability between days. It does not guarantee anonymity.

## Redirect-click filter (since 1.0.0)

A redirect click is stored only if the browser confirms a user action
(`Sec-Fetch-User`), or if the same day identifier has already viewed a page on
the same day. Other hits are still redirected, but only increase a **daily
total with no other data**.

## Retention

Single events are removed from the active database after five years. Before
that, full days are condensed into daily totals (page views, redirect clicks,
estimated devices). Backups need their own limited retention and must not
contain the daily key (`secret.txt`). The CLI snapshot and the `/k/snapshot`
route exclude it. The snapshot route returns all single events, so its
recipients and retention belong in the privacy policy.
