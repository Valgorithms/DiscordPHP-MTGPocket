# Stun counters on entry

Tonberry and Sleep-Cursed Faerie should enter tapped with one and three stun counters, respectively. The existing named-entry parser did not include `stun`, so both instead entered untapped without counters. The existing entry-counter path now recognizes stun; no new serialized field is introduced.

On main `fc77ab3f79572b3a68ebc4ab58e1204f5061b7e9`, eight paid cases reached 40 assertions and all failed: both cards entered untapped for both seats, and their later untap expectations failed. The corrected 12 cases pass 92 assertions. They cover paid entry, JSON restore, ordinary untap steps, Faerie's paid untap ability, counter exhaustion before untapping and atomic rejection of an unpaid activation.

The normalized complete fixture SHA-256 is `bec03f1b43174c16835a6978c4337c641a8108c3701bf1dab5c1d3a88d8ecf17`. FIN Tonberry UUID `2dfac2a6-15a3-5f36-9adc-eb005e756cf1` comes from pool SHA-256 `96cffae6a671efe455003dcffbfc087061e576fd8126a548385457405ed16cfa`; WOE Sleep-Cursed Faerie UUID `b7cf0005-fc24-5821-8cec-e57e57519f84` comes from pool SHA-256 `52d5f5c3e8a975b4e3c8bebc38bd56b927700ce35e1e6b31ed3306306a061562`.

Tonberry's Chef's Knife combat clause remains unread. Faerie's Ward timing remains the existing upfront-payment behavior; the review-held Ward work is not enabled or tested here. General entry replacements, mass untap and newest-printing behavior are outside this slice. Generic counter-map restoration is reused, but a rollback restores the old parser behavior for future entries; old-reader gameplay compatibility has not been certified. No aggregate recognition update, whole-card certificate or corpus import is claimed.
